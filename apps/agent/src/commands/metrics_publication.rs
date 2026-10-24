//! Grafana's UFW boundary: Gateway allow, other-peer deny, then broad WireGuard trust.
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    io,
    net::Ipv4Addr,
    process::{Command as Process, Stdio},
    time::Duration,
};
const ALLOW: &str = "orbit:metrics-grafana-upstream";
const DENY: &str = "orbit:metrics-grafana-isolation";
const TRUST: &str = "orbit:wireguard-members";
command_errors! {
    pub enum Error {
        Inspection {} => "metrics.publication_firewall_inspection_failed", "The Metrics Grafana firewall state could not be inspected.";
        Inactive {} => "metrics.publication_firewall_inactive", "UFW must be active for Metrics Grafana publication.";
        Ownership {} => "metrics.publication_firewall_ownership_drift", "Metrics Grafana firewall ownership cannot be proved.";
        Apply {} => "metrics.publication_firewall_apply_failed", "The Metrics Grafana firewall rule could not be applied.";
        Verify {} => "metrics.publication_firewall_verify_failed", "The ordered Metrics Grafana firewall boundary could not be verified.";
        Remove {} => "metrics.publication_firewall_remove_failed", "The Metrics Grafana firewall rule could not be removed.";
        RemoveVerify {} => "metrics.publication_firewall_remove_verify_failed", "A Metrics Grafana firewall rule remained after removal.";
        Timeout {} => "metrics.remote_command_timed_out", "A Metrics firewall command timed out.";
    }
}
type Result<T> = std::result::Result<T, Error>;
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: Ipv4Addr,
    action: Action,
}
#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
enum Action {
    Converge { gateway: Ipv4Addr },
    Remove { gateway: Ipv4Addr },
    Abandon {},
}
#[derive(Serialize)]
pub struct Output {
    changed: bool,
}
struct Row {
    number: u32,
    comment: String,
    body: String,
}
fn rows(status: &str) -> Result<Vec<Row>> {
    if !status
        .lines()
        .any(|line| line.trim().eq_ignore_ascii_case("Status: active"))
    {
        return Err(Error::Inactive {});
    }
    let mut rows = vec![];
    for line in status.lines() {
        let Some((body, comment)) = line.rsplit_once('#') else {
            continue;
        };
        if ![ALLOW, DENY, TRUST].contains(&comment.trim()) {
            continue;
        }
        let (number, body) = body
            .trim()
            .strip_prefix('[')
            .and_then(|body| body.split_once(']'))
            .ok_or(Error::Ownership {})?;
        let number = number
            .trim()
            .parse::<u32>()
            .ok()
            .filter(|number| *number > 0)
            .ok_or(Error::Ownership {})?;
        rows.push(Row {
            number,
            comment: comment.trim().into(),
            body: body.trim().into(),
        });
    }
    Ok(rows)
}
fn owned(rows: &[Row], comment: &str, address: Ipv4Addr, gateway: Ipv4Addr) -> Result<Option<u32>> {
    let matching: Vec<_> = rows.iter().filter(|row| row.comment == comment).collect();
    if matching.len() > 1 {
        return Err(Error::Ownership {});
    }
    let Some(row) = matching.first() else {
        return Ok(None);
    };
    let mut words: Vec<_> = row.body.split_whitespace().collect();
    let mut interface = false;
    while let Some(index) = words.iter().position(|word| *word == "on") {
        if words.get(index + 1) != Some(&"orbit") {
            return Err(Error::Ownership {});
        }
        interface = true;
        words.drain(index..index + 2);
    }
    let host = |word: &str| {
        word.strip_suffix("/32")
            .unwrap_or(word)
            .parse::<Ipv4Addr>()
            .ok()
    };
    let expected = if comment == ALLOW { "ALLOW" } else { "DENY" };
    match words.as_slice() {
        [destination, "3000/tcp", action, "IN", source]
            if interface
                && host(destination) == Some(address)
                && *action == expected
                && (if comment == ALLOW {
                    host(source) == Some(gateway)
                } else {
                    matches!(*source, "Anywhere" | "0.0.0.0/0")
                }) =>
        {
            Ok(Some(row.number))
        }
        _ => Err(Error::Ownership {}),
    }
}
fn before_trust(rows: &[Row], deny: u32) -> bool {
    rows.iter()
        .filter(|row| row.comment == TRUST)
        .all(|row| deny < row.number)
}
trait Host {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)>;
}
struct NativeHost;
impl Host for NativeHost {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
        let output = Process::new("/usr/bin/timeout")
            .args(["--kill-after=5s", "120s", "/usr/sbin/ufw"])
            .args(args)
            .stdin(Stdio::null())
            .stderr(Stdio::null())
            .output()?;
        Ok((
            output.status.code().unwrap_or(-1),
            String::from_utf8_lossy(&output.stdout).into_owned(),
        ))
    }
}
fn call(host: &mut impl Host, args: Vec<String>, error: Error) -> Result<String> {
    let (code, output) = host.ufw(&args).map_err(|_| Error::Inspection {})?;
    match code {
        0 => Ok(output),
        124 | 137 => Err(Error::Timeout {}),
        _ => Err(error),
    }
}
fn status(host: &mut impl Host) -> Result<Vec<Row>> {
    rows(&call(
        host,
        vec!["status".into(), "numbered".into()],
        Error::Inspection {},
    )?)
}
fn delete(host: &mut impl Host, number: u32) -> Result<()> {
    call(
        host,
        vec!["--force".into(), "delete".into(), number.to_string()],
        Error::Remove {},
    )?;
    Ok(())
}
fn insert(host: &mut impl Host, address: Ipv4Addr, gateway: Ipv4Addr, allow: bool) -> Result<()> {
    let address = address.to_string();
    let source = if allow {
        gateway.to_string()
    } else {
        "any".into()
    };
    call(
        host,
        [
            "insert",
            "1",
            if allow { "allow" } else { "deny" },
            "in",
            "on",
            "orbit",
            "proto",
            "tcp",
            "from",
            &source,
            "to",
            &address,
            "port",
            "3000",
            "comment",
            if allow { ALLOW } else { DENY },
        ]
        .map(str::to_owned)
        .into(),
        Error::Apply {},
    )?;
    Ok(())
}
fn execute(host: &mut impl Host, input: Input) -> Result<Output> {
    let mut rules = status(host)?;
    let gateway = match input.action {
        Action::Converge { gateway } | Action::Remove { gateway } => Some(gateway),
        Action::Abandon {} => None,
    };
    let (mut allow, mut deny) = if let Some(gateway) = gateway {
        (
            owned(&rules, ALLOW, input.address, gateway)?,
            owned(&rules, DENY, input.address, gateway)?,
        )
    } else {
        (None, None)
    };
    if !matches!(input.action, Action::Converge { .. }) {
        let mut numbers: Vec<_> = rules
            .iter()
            .filter(|row| [ALLOW, DENY].contains(&row.comment.as_str()))
            .map(|row| row.number)
            .collect();
        if numbers.is_empty() {
            return Ok(Output { changed: false });
        }
        numbers.sort_unstable_by(|a, b| b.cmp(a));
        for number in numbers {
            delete(host, number)?;
        }
        if status(host)?
            .iter()
            .any(|row| [ALLOW, DENY].contains(&row.comment.as_str()))
        {
            return Err(Error::RemoveVerify {});
        }
        return Ok(Output { changed: true });
    }
    let gateway = gateway.unwrap();
    if let (Some(allow), Some(deny)) = (allow, deny) {
        if allow < deny && before_trust(&rules, deny) {
            return Ok(Output { changed: false });
        }
    }
    if let Some(number) = deny {
        if !before_trust(&rules, number) {
            delete(host, number)?;
            rules = status(host)?;
            deny = None;
        }
    }
    if deny.is_none() {
        insert(host, input.address, gateway, false)?;
        rules = status(host)?;
        deny = owned(&rules, DENY, input.address, gateway)?;
        if !deny.is_some_and(|number| before_trust(&rules, number)) {
            return Err(Error::Verify {});
        }
    }
    allow = owned(&rules, ALLOW, input.address, gateway)?;
    if let (Some(allow), Some(deny)) = (allow, deny) {
        if allow > deny {
            delete(host, allow)?;
        }
    }
    rules = status(host)?;
    if owned(&rules, ALLOW, input.address, gateway)?.is_none() {
        insert(host, input.address, gateway, true)?;
    }
    rules = status(host)?;
    let allow = owned(&rules, ALLOW, input.address, gateway)?;
    let deny = owned(&rules, DENY, input.address, gateway)?;
    if !matches!((allow,deny),(Some(a),Some(d)) if a<d&&before_trust(&rules,d)) {
        return Err(Error::Verify {});
    }
    Ok(Output { changed: true })
}
pub struct Publication;
impl Command for Publication {
    const NAME: &'static str = "metrics.publication-firewall";
    const SUMMARY: &'static str =
        "Converge or remove Grafana's ordered Gateway-only firewall boundary.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["firewall"];
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some(Self::NAME.into())
    }
    fn run(input: Input, _: &Context) -> Result<Output> {
        execute(&mut NativeHost, input)
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    struct Fake {
        rules: Vec<String>,
        calls: Vec<Vec<String>>,
        fail_allow: bool,
        keep_deleted: bool,
        active: bool,
    }
    impl Default for Fake {
        fn default() -> Self {
            Self {
                rules: vec![
                    "10.44.0.3 on orbit ALLOW IN Anywhere on orbit # orbit:wireguard-members"
                        .into(),
                ],
                calls: vec![],
                fail_allow: false,
                keep_deleted: false,
                active: true,
            }
        }
    }
    impl Host for Fake {
        fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
            self.calls.push(args.to_vec());
            if args[0] == "status" {
                return Ok((
                    0,
                    format!(
                        "Status: {}\n{}",
                        if self.active { "active" } else { "inactive" },
                        self.rules
                            .iter()
                            .enumerate()
                            .map(|(i, row)| format!("[ {}] {row}", i + 1))
                            .collect::<Vec<_>>()
                            .join("\n")
                    ),
                ));
            }
            if args[0] == "--force" {
                if !self.keep_deleted {
                    self.rules.remove(args[2].parse::<usize>().unwrap() - 1);
                }
                return Ok((0, String::new()));
            }
            if self.fail_allow && args[2] == "allow" {
                return Ok((1, String::new()));
            }
            assert_eq!(&args[..2], ["insert", "1"]);
            assert_eq!(&args[3..9], ["in", "on", "orbit", "proto", "tcp", "from"]);
            self.rules.insert(
                0,
                format!(
                    "{} 3000/tcp on orbit {} IN {} # {}",
                    args[11],
                    args[2].to_uppercase(),
                    if args[9] == "any" {
                        "Anywhere"
                    } else {
                        &args[9]
                    },
                    args[15]
                ),
            );
            Ok((0, String::new()))
        }
    }
    fn input(action: Action) -> Input {
        Input {
            address: "10.44.0.3".parse().unwrap(),
            action,
        }
    }
    fn converge() -> Input {
        input(Action::Converge {
            gateway: "10.44.0.1".parse().unwrap(),
        })
    }
    #[test]
    fn converges_in_order_and_repeats_without_mutation() {
        let mut host = Fake::default();
        assert!(execute(&mut host, converge()).unwrap().changed);
        assert!(host.rules[0].ends_with(ALLOW));
        assert!(host.rules[1].ends_with(DENY));
        assert!(host.rules[2].ends_with(TRUST));
        host.calls.clear();
        assert!(!execute(&mut host, converge()).unwrap().changed);
        assert_eq!(host.calls.len(), 1);
    }
    #[test]
    fn leaves_isolation_when_allow_fails_and_recovers_on_repeat() {
        let mut host = Fake {
            fail_allow: true,
            ..Fake::default()
        };
        assert!(matches!(
            execute(&mut host, converge()),
            Err(Error::Apply {})
        ));
        assert!(host.rules[0].ends_with(DENY));
        assert!(!host.calls.iter().any(|args| args[0] == "--force"));
        host.fail_allow = false;
        execute(&mut host, converge()).unwrap();
        assert!(host.rules[0].ends_with(ALLOW));
    }
    #[test]
    fn repairs_rules_after_trust_or_with_allow_after_deny() {
        for order in [[2, 0, 1], [1, 2, 0]] {
            let mut host = Fake::default();
            execute(&mut host, converge()).unwrap();
            let rules = host.rules.clone();
            host.rules = order.map(|i| rules[i].clone()).into();
            execute(&mut host, converge()).unwrap();
            assert!(host.rules[0].ends_with(ALLOW));
            assert!(host.rules[1].ends_with(DENY));
            assert!(host.rules[2].ends_with(TRUST));
        }
    }
    #[test]
    fn refuses_wrong_source_port_interface_action_or_duplicate_before_change() {
        for replacement in ["source", "port", "interface", "action", "duplicate"] {
            let mut host = Fake::default();
            execute(&mut host, converge()).unwrap();
            match replacement {
                "source" => host.rules[0] = host.rules[0].replace("10.44.0.1", "10.44.0.9"),
                "port" => host.rules[0] = host.rules[0].replace("3000/tcp", "3001/tcp"),
                "interface" => host.rules[0] = host.rules[0].replace("on orbit", "on eth0"),
                "action" => host.rules[0] = host.rules[0].replace("ALLOW IN", "ALLOW OUT"),
                _ => host.rules.push(host.rules[0].clone()),
            };
            host.calls.clear();
            assert!(matches!(
                execute(&mut host, converge()),
                Err(Error::Ownership {})
            ));
            assert_eq!(host.calls.len(), 1);
            assert!(matches!(
                execute(
                    &mut host,
                    input(Action::Remove {
                        gateway: "10.44.0.1".parse().unwrap()
                    })
                ),
                Err(Error::Ownership {})
            ));
        }
    }
    #[test]
    fn removal_is_owned_verified_and_repeatable() {
        let mut host = Fake::default();
        execute(&mut host, converge()).unwrap();
        assert!(
            execute(
                &mut host,
                input(Action::Remove {
                    gateway: "10.44.0.1".parse().unwrap()
                })
            )
            .unwrap()
            .changed
        );
        assert_eq!(host.rules.len(), 1);
        assert!(
            !execute(
                &mut host,
                input(Action::Remove {
                    gateway: "10.44.0.1".parse().unwrap()
                })
            )
            .unwrap()
            .changed
        );
    }
    #[test]
    fn abandon_matches_exact_comments_without_a_gateway_and_verifies_absence() {
        let mut host = Fake::default();
        execute(&mut host, converge()).unwrap();
        host.rules.push(format!(
            "10.44.0.3 3000/tcp on orbit ALLOW IN Anywhere # {ALLOW}-v2"
        ));
        host.keep_deleted = true;
        assert!(matches!(
            execute(&mut host, input(Action::Abandon {})),
            Err(Error::RemoveVerify {})
        ));
        host.keep_deleted = false;
        execute(&mut host, input(Action::Abandon {})).unwrap();
        assert_eq!(host.rules.len(), 2);
        assert!(host.rules[1].ends_with("-v2"));
        assert!(
            !execute(&mut host, input(Action::Abandon {}))
                .unwrap()
                .changed
        );
    }
    #[test]
    fn inactive_or_invalid_input_refuses_without_changes() {
        let mut host = Fake {
            active: false,
            ..Fake::default()
        };
        assert!(matches!(
            execute(&mut host, converge()),
            Err(Error::Inactive {})
        ));
        assert_eq!(host.calls.len(), 1);
        assert!(serde_json::from_value::<Input>(
            serde_json::json!({"address":"10.44.0.3","action":{"operation":"abandon","script":"x"}})
        )
        .is_err());
    }
}
