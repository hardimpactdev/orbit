//! The rule planner and check against a ufw double: a port of `firewall_test.ts` and `native_fixture.ts`. No host
//! firewall calls.

use std::cell::{Cell, RefCell};

use super::plan::{check, inventory, plan, validated, Input, Row};
use super::{ensure_rule, Failure, Ufw};

const PARTIAL_APPLICATION: &str = "Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 47865/tcp                  ALLOW IN    10.232.2.11               # orbit
[ 2] 47510/tcp                  ALLOW IN    10.232.2.11               # orbit
[ 3] 10.45.0.2 on orbit         ALLOW IN    Anywhere                  # orbit:wireguard-members
[ 4] 47865/tcp                  DENY IN     Anywhere                  # proof:deny
[ 5] 47510/tcp                  DENY IN     Anywhere                  # proof:deny
[ 6] 47372/tcp                  ALLOW IN    10.232.2.11               # proof:existing
[ 7] 47372/tcp                  DENY IN     Anywhere                  # proof:deny
[ 8] 47372/udp                  DENY IN     Anywhere                  # proof:deny
[ 9] Anywhere                   ALLOW IN    10.232.2.0/24             # proof-network
[10] 47865/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[11] 47510/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[12] 47372/tcp (v6)             DENY IN     Anywhere (v6)             # proof:deny
[13] 47372/udp (v6)             DENY IN     Anywhere (v6)             # proof:deny
";

fn row(to: &str, action: &str, source: &str, comment: &str) -> Row {
    Row { to: to.to_owned(), action: action.to_owned(), direction: "in".to_owned(), source: source.to_owned(), comment: comment.to_owned() }
}

fn trust() -> Row {
    row("Anywhere on orbit", "allow", "10.44.0.0/24", "orbit:wireguard-members")
}

fn trust6() -> Row {
    row("22/tcp (v6)", "allow", "Anywhere (v6)", "orbit:public-ssh-recovery")
}

/// A ufw double: numbered status, add, insert and delete. `from any` has IPv4 and IPv6 lines, and a line from one IPv6
/// address shows the address alone.
#[derive(Debug)]
struct Double {
    rows: RefCell<Vec<Row>>,
    active: Cell<bool>,
    fail: Cell<bool>,
    /// Ignore insert positions, as a racing writer would.
    misplace: Cell<bool>,
    /// How many times `status` was read.
    reads: Cell<u32>,
    applied: RefCell<Vec<Vec<String>>>,
}

impl Double {
    fn new(rows: Vec<Row>) -> Self {
        Self {
            rows: RefCell::new(rows),
            active: Cell::new(true),
            fail: Cell::new(false),
            misplace: Cell::new(false),
            reads: Cell::new(0),
            applied: RefCell::new(Vec::new()),
        }
    }

    fn standard() -> Self {
        Self::new(vec![trust(), trust6()])
    }

    fn rows(&self) -> Vec<Row> {
        self.rows.borrow().clone()
    }

    fn tuples(&self, port: Option<&str>) -> Vec<[String; 3]> {
        self.rows
            .borrow()
            .iter()
            .filter(|row| port.is_none_or(|port| row.to.split('/').next() == Some(port)))
            .map(|row| [row.to.clone(), row.action.clone(), row.source.clone()])
            .collect()
    }

    fn text(&self) -> String {
        let rows: Vec<String> = self
            .rows
            .borrow()
            .iter()
            .enumerate()
            .map(|(index, row)| {
                let comment = if row.comment.is_empty() { String::new() } else { format!(" # {}", row.comment) };
                format!("[{}] {} {} IN {}{comment}", index + 1, row.to, row.action.to_uppercase(), row.source)
            })
            .collect();
        format!(
            "Status: {}\n\n     To  Action  From\n{}",
            if self.active.get() { "active" } else { "inactive" },
            rows.join("\n")
        )
    }
}

impl Ufw for Double {
    fn status(&self) -> Result<String, Failure> {
        self.reads.set(self.reads.get() + 1);
        Ok(self.text())
    }

    fn apply(&self, args: &[String]) -> Result<(), Failure> {
        if self.fail.get() {
            return Err(Failure::from("firewall.apply_failed"));
        }
        self.applied.borrow_mut().push(args.to_vec());
        let word = |index: usize| args.get(index).map_or("", String::as_str);
        let remove = word(0) == "--force" && word(1) == "delete";
        let line: Option<usize> = if word(0) == "insert" && !self.misplace.get() { word(1).parse().ok() } else { None };
        let rule: &[String] = if remove || word(0) == "insert" { args.get(2..).unwrap_or_default() } else { args };
        let field = |index: usize| rule.get(index).map_or("", String::as_str);
        let (action, protocol, source, port) = (field(0), field(3), field(5), field(9));
        let comment = rule.iter().position(|word| word == "comment").and_then(|at| rule.get(at + 1)).cloned().unwrap_or_default();
        let families: Vec<bool> = match source {
            "any" => vec![false, true],
            "0.0.0.0/0" => vec![false],
            "::/0" => vec![true],
            other => vec![other.contains(':')],
        };
        let anywhere = matches!(source, "any" | "0.0.0.0/0" | "::/0");
        let mut rows = self.rows.borrow_mut();
        for six in families {
            let suffix = if six && anywhere { " (v6)" } else { "" };
            let shown = if anywhere { "Anywhere" } else { source };
            let new = row(&format!("{port}/{protocol}{suffix}"), action, &format!("{shown}{suffix}"), &comment);
            if remove {
                rows.retain(|old| !(old.to == new.to && old.source == new.source && old.action == action));
            } else if let Some(same) = rows.iter_mut().find(|old| old.to == new.to && old.source == new.source) {
                same.action = action.to_owned();
                same.comment.clone_from(&comment);
            } else {
                let first6 = rows.iter().position(|old| format!("{}{}", old.to, old.source).contains("(v6)") || old.source.contains(':'));
                let at = match (line, six, first6) {
                    (Some(line), _, _) => line.saturating_sub(1).min(rows.len()),
                    (None, true, _) | (None, false, None) => rows.len(),
                    (None, false, Some(first6)) => first6,
                };
                rows.insert(at, new);
            }
        }
        Ok(())
    }
}

fn input(ensure: &str, changes: &serde_json::Value) -> serde_json::Value {
    let mut value = serde_json::json!({
        "action": "allow", "port": "443", "protocol": "tcp", "source": "192.0.2.1", "comment": "orbit web", "ensure": ensure,
    });
    if let (Some(base), Some(extra)) = (value.as_object_mut(), changes.as_object()) {
        base.extend(extra.clone());
    }
    value
}

/// The primitive end to end against the double: the commands it ran, and its result.
fn converge(ufw: &Double, changes: &serde_json::Value, ensure: &str) -> (Vec<Vec<String>>, Result<u32, &'static str>) {
    ufw.applied.borrow_mut().clear();
    let result = ensure_rule(ufw, &input(ensure, changes)).map_err(|failure| failure.code);
    (ufw.applied.borrow().clone(), result)
}

fn present(ufw: &Double, changes: &serde_json::Value) -> (Vec<Vec<String>>, Result<u32, &'static str>) {
    converge(ufw, changes, "present")
}

fn rule(ensure: &str, changes: &serde_json::Value) -> super::plan::Rule {
    validated(serde_json::from_value::<Input>(input(ensure, changes)).expect("input shape")).expect("valid rule")
}

fn tuple(to: &str, action: &str, source: &str) -> [String; 3] {
    [to.to_owned(), action.to_owned(), source.to_owned()]
}

fn words(text: &str) -> Vec<String> {
    text.split(' ').map(str::to_owned).collect()
}

/// A planned argument list whose last word, the comment, may hold spaces.
fn command(text: &str, comment: &str) -> Vec<String> {
    let mut args = words(text);
    args.extend(["comment".to_owned(), comment.to_owned()]);
    args
}

const NONE: Vec<Vec<String>> = Vec::new();

#[test]
fn any_writes_one_commented_line_per_protocol_and_family_above_broad_managed_lines() {
    let ufw = Double::standard();
    let any = serde_json::json!({"protocol": "any", "source": "any"});
    assert_eq!(present(&ufw, &any).1, Ok(4));
    let before = ufw.rows();
    let (commands, result) = present(&ufw, &any);
    assert_eq!(commands, NONE);
    assert_eq!(result, Ok(0));
    assert_eq!(ufw.tuples(None), vec![
        tuple("443/tcp", "allow", "Anywhere"),
        tuple("443/udp", "allow", "Anywhere"),
        tuple("Anywhere on orbit", "allow", "10.44.0.0/24"),
        tuple("22/tcp (v6)", "allow", "Anywhere (v6)"),
        tuple("443/tcp (v6)", "allow", "Anywhere (v6)"),
        tuple("443/udp (v6)", "allow", "Anywhere (v6)"),
    ]);
    assert!(ufw.rows().iter().filter(|row| row.to.starts_with("443")).all(|row| row.comment == "orbit web"));
    assert_eq!(before, ufw.rows());
}

#[test]
fn an_allow_range_with_protocol_any_converges_narrow_first_and_repeats_without_commands() {
    for (source, ipv4, ipv6) in [
        ("any", Some("Anywhere"), Some("Anywhere (v6)")),
        ("192.0.2.0/24", Some("192.0.2.0/24"), None),
        ("2001:db8::/64", None, Some("2001:db8::/64")),
    ] {
        let ufw = Double::standard();
        present(&ufw, &serde_json::json!({"port": "8000:8100", "protocol": "any", "source": "192.0.2.1"}));
        present(&ufw, &serde_json::json!({"port": "8000:8100", "protocol": "any", "source": "2001:db8::1"}));
        let narrow = ufw.rows();
        let range = serde_json::json!({"port": "8000:8100", "protocol": "any", "source": source});
        let (commands, result) = present(&ufw, &range);
        let expected4: Vec<[String; 3]> = ipv4
            .map(|shown| vec![tuple("8000:8100/tcp", "allow", shown), tuple("8000:8100/udp", "allow", shown)])
            .unwrap_or_default();
        let suffix = if source == "any" { " (v6)" } else { "" };
        let expected6: Vec<[String; 3]> = ipv6
            .map(|shown| {
                vec![
                    tuple(&format!("8000:8100/tcp{suffix}"), "allow", shown),
                    tuple(&format!("8000:8100/udp{suffix}"), "allow", shown),
                ]
            })
            .unwrap_or_default();
        assert_eq!(commands.len(), expected4.len() + expected6.len(), "{source}");
        assert!(commands.iter().all(|args| args.iter().skip_while(|word| *word != "port").nth(1).map(String::as_str) == Some("8000:8100")));
        assert!(result.is_ok_and(|changes| changes > 0), "{source}");
        let mut wanted = vec![tuple("8000:8100/tcp", "allow", "192.0.2.1"), tuple("8000:8100/udp", "allow", "192.0.2.1")];
        wanted.extend(expected4);
        wanted.extend([
            tuple("Anywhere on orbit", "allow", "10.44.0.0/24"),
            tuple("22/tcp (v6)", "allow", "Anywhere (v6)"),
            tuple("8000:8100/tcp", "allow", "2001:db8::1"),
            tuple("8000:8100/udp", "allow", "2001:db8::1"),
        ]);
        wanted.extend(expected6);
        assert_eq!(ufw.tuples(None), wanted, "{source}");
        let kept: Vec<Row> =
            ufw.rows().into_iter().filter(|row| narrow.iter().any(|old| old.to == row.to && old.source == row.source)).collect();
        assert_eq!(kept, narrow, "{source}");
        let applied = ufw.rows();
        let (commands, result) = present(&ufw, &range);
        assert_eq!(commands, NONE, "{source}");
        assert_eq!(result, Ok(0), "{source}");
        assert_eq!(ufw.rows(), applied, "{source}");
    }
}

#[test]
fn a_deny_sits_above_wireguard_trust_and_an_allowed_source_above_the_deny() {
    let ufw = Double::standard();
    present(&ufw, &serde_json::json!({"action": "deny", "port": "5432", "source": "any"}));
    present(&ufw, &serde_json::json!({"port": "5432", "source": "10.44.0.6"}));
    assert_eq!(ufw.tuples(None).get(..3), Some(&[
        tuple("5432/tcp", "allow", "10.44.0.6"),
        tuple("5432/tcp", "deny", "Anywhere"),
        tuple("Anywhere on orbit", "allow", "10.44.0.0/24"),
    ][..]));
}

#[test]
fn narrower_rules_sit_above_broader_ones_whatever_their_action() {
    let ufw = Double::standard();
    present(&ufw, &serde_json::json!({"action": "deny", "port": "5432", "source": "any"}));
    present(&ufw, &serde_json::json!({"port": "5432", "source": "192.0.2.10"}));
    let first = ufw.tuples(Some("5432"));
    present(&ufw, &serde_json::json!({"port": "25", "source": "any"}));
    present(&ufw, &serde_json::json!({"action": "deny", "port": "25", "source": "192.0.2.10"}));
    present(&ufw, &serde_json::json!({"port": "25", "source": "192.0.2.0/24"}));
    let second: Vec<[String; 3]> = ufw.tuples(Some("25")).into_iter().take(3).collect();
    present(&ufw, &serde_json::json!({"action": "deny", "port": "20:30", "source": "192.0.0.0/16"}));
    let order = || -> Vec<[String; 3]> {
        ufw.tuples(None)
            .into_iter()
            .filter(|[to, _, _]| !to.ends_with("(v6)") && (to.starts_with("25") || to.starts_with("20")))
            .collect()
    };
    let third = order();
    converge(&ufw, &serde_json::json!({"port": "25", "source": "192.0.2.0/24"}), "absent");
    let narrow = tuple("25/tcp", "deny", "192.0.2.10");
    let network = tuple("25/tcp", "allow", "192.0.2.0/24");
    let broad = tuple("25/tcp", "allow", "Anywhere");
    let range = tuple("20:30/tcp", "deny", "192.0.0.0/16");
    assert_eq!(first, vec![
        tuple("5432/tcp", "allow", "192.0.2.10"),
        tuple("5432/tcp", "deny", "Anywhere"),
        tuple("5432/tcp (v6)", "deny", "Anywhere (v6)"),
    ]);
    assert_eq!(second, vec![narrow.clone(), network.clone(), broad.clone()]);
    assert_eq!(third, vec![narrow.clone(), network, range.clone(), broad.clone()]);
    assert_eq!(order(), vec![narrow, range, broad]);
}

#[test]
fn a_misplaced_line_moves_and_a_racing_writer_fails_the_check() {
    let ufw = Double::standard();
    ufw.rows.borrow_mut().splice(1..1, [row("80/tcp", "allow", "Anywhere", ""), row("80/tcp", "deny", "192.0.2.10", "orbit")]);
    let (commands, _) = present(&ufw, &serde_json::json!({"port": "80", "source": "192.0.2.10", "action": "deny", "comment": "orbit"}));
    assert_eq!(commands.iter().map(|args| args.first().cloned().unwrap_or_default()).collect::<Vec<_>>(), ["--force", "insert"]);
    assert_eq!(ufw.tuples(Some("80")), vec![tuple("80/tcp", "deny", "192.0.2.10"), tuple("80/tcp", "allow", "Anywhere")]);
    ufw.misplace.set(true);
    assert_eq!(present(&ufw, &serde_json::json!({"port": "80", "source": "192.0.2.11", "action": "deny"})).1, Err("firewall.verify_failed"));
}

#[test]
fn matching_ignores_the_comment_and_removal_keeps_other_tuples() {
    let ufw = Double::standard();
    ufw.rows
        .borrow_mut()
        .splice(0..0, [row("443/tcp", "allow", "192.0.2.1", "set by hand"), row("400:500/tcp", "allow", "192.0.2.1", "orbit range")]);
    present(&ufw, &serde_json::json!({}));
    assert_eq!(ufw.tuples(None).iter().filter(|t| **t == tuple("443/tcp", "allow", "192.0.2.1")).count(), 1);
    assert!(converge(&ufw, &serde_json::json!({}), "absent").1.is_ok());
    assert!(!ufw.tuples(None).iter().any(|[to, _, source]| to == "443/tcp" && source == "192.0.2.1"));
    assert!(ufw.tuples(None).contains(&tuple("400:500/tcp", "allow", "192.0.2.1")));
}

#[test]
fn a_retained_removal_leaves_the_managed_line() {
    let ufw = Double::standard();
    present(&ufw, &serde_json::json!({"action": "deny", "port": "25"}));
    let (commands, result) = converge(&ufw, &serde_json::json!({"action": "deny", "port": "25", "retain": true}), "absent");
    assert_eq!(commands, NONE);
    assert_eq!(result, Ok(0));
    assert!(ufw.tuples(None).contains(&tuple("25/tcp", "deny", "192.0.2.1")));
}

#[test]
fn an_inactive_firewall_a_lost_removal_and_invalid_input_refuse() {
    let ufw = Double::standard();
    ufw.active.set(false);
    assert_eq!(present(&ufw, &serde_json::json!({})).1, Err("firewall.backend_inactive"));
    ufw.active.set(true);
    present(&ufw, &serde_json::json!({}));
    let rows = inventory(&ufw.text()).expect("rows");
    assert_eq!(check(&rule("absent", &serde_json::json!({})), &rows), Err("firewall.remove_verify_failed"));
    for bad in [
        serde_json::json!({"port": "ssh"}),
        serde_json::json!({"protocol": "icmp"}),
        serde_json::json!({"comment": "orbit it's"}),
        serde_json::json!({"comment": "web"}),
        serde_json::json!({"port": "9:8"}),
        serde_json::json!({"action": "allowlist"}),
        serde_json::json!({"source": "10.0.0.300"}),
        serde_json::json!({"argv": ["sh"]}),
    ] {
        assert_eq!(present(&ufw, &bad).1, Err("firewall.input_invalid"), "{bad}");
    }
}

#[test]
fn a_line_from_one_ipv6_address_sits_above_the_ipv6_deny_and_is_found_again() {
    let ufw = Double::standard();
    present(&ufw, &serde_json::json!({"action": "deny", "port": "80", "source": "any"}));
    assert!(present(&ufw, &serde_json::json!({"port": "80", "source": "fd42::10"})).1.is_ok());
    let rows = ufw.tuples(Some("80"));
    let allow = rows.iter().position(|[_, action, from]| action == "allow" && from == "fd42::10");
    let deny = rows.iter().position(|[to, action, _]| action == "deny" && to.ends_with("(v6)"));
    assert!(allow < deny, "{rows:?}");
    let (commands, result) = present(&ufw, &serde_json::json!({"port": "80", "source": "fd42::10"}));
    assert_eq!(commands, NONE);
    assert_eq!(result, Ok(0));
}

#[test]
fn a_plan_without_changes_reads_ufw_once_and_changes_are_read_back_once() {
    let ufw = Double::standard();
    assert_eq!(present(&ufw, &serde_json::json!({})).1, Ok(1));
    assert_eq!(ufw.reads.get(), 2);
    ufw.reads.set(0);
    assert_eq!(present(&ufw, &serde_json::json!({})).1, Ok(0));
    assert_eq!(ufw.reads.get(), 1);
    ufw.reads.set(0);
    assert_eq!(converge(&ufw, &serde_json::json!({}), "absent").1, Ok(1));
    assert_eq!(ufw.reads.get(), 2);
    ufw.reads.set(0);
    assert_eq!(converge(&ufw, &serde_json::json!({}), "absent").1, Ok(0));
    assert_eq!(ufw.reads.get(), 1);
}

#[test]
fn numbered_inserts_count_unrelated_limit_reject_and_outgoing_rows() {
    let unrelated = "[ 1] 22/tcp LIMIT IN Anywhere # hand-owned\n[ 2] 25/tcp ALLOW OUT Anywhere\n[ 3] 80/tcp REJECT IN Anywhere\n\
        [ 4] 443/tcp ALLOW FWD 192.0.2.1\n[ 5] custom AUDIT IN Anywhere\n";
    let before = format!("Status: active\n{unrelated}[ 6] Anywhere on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members\n");
    let wanted = rule("present", &serde_json::json!({}));
    let commands = plan(&wanted, inventory(&before).expect("rows"));
    assert_eq!(commands.len(), 1);
    assert_eq!(commands.first().and_then(|args| args.get(..2)), Some(&words("insert 6")[..]));
    let after = format!(
        "Status: active\n{unrelated}[ 6] 443/tcp ALLOW IN 192.0.2.1 # orbit web\n[ 7] Anywhere on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members\n"
    );
    assert_eq!(plan(&wanted, inventory(&after).expect("rows")), NONE);
    assert_eq!(check(&wanted, &inventory(&after).expect("rows")), Ok(()));
}

#[test]
fn an_explicit_anywhere_of_one_family_affects_only_that_family_and_repeats_without_duplicates() {
    for source in ["0.0.0.0/0", "::/0"] {
        let ufw = Double::standard();
        let changes = serde_json::json!({"source": source, "protocol": "any"});
        assert_eq!(present(&ufw, &changes).0.len(), 2, "{source}");
        assert_eq!(ufw.rows().iter().filter(|row| row.to.starts_with("443")).count(), 2, "{source}");
        assert_eq!(present(&ufw, &changes).1, Ok(0), "{source}");
    }
}

#[test]
fn ipv4_embedded_ipv6_is_one_ipv6_rule_and_matches_the_equivalent_spelling() {
    let ufw = Double::standard();
    let changes = serde_json::json!({"source": "::ffff:192.0.2.1"});
    present(&ufw, &changes);
    assert_eq!(ufw.rows().iter().filter(|row| row.to.starts_with("443")).count(), 1);
    if let Some(found) = ufw.rows.borrow_mut().iter_mut().find(|row| row.to == "443/tcp") {
        "::ffff:c000:201".clone_into(&mut found.source);
    }
    assert_eq!(present(&ufw, &changes).1, Ok(0));
}

#[test]
fn an_interface_scoped_source_is_preserved_and_does_not_stand_in_for_an_unrestricted_allow() {
    let scoped = row("443/tcp", "allow", "192.0.2.1 on eth0", "hand-owned");
    let ufw = Double::new(vec![scoped.clone(), row("443/tcp", "deny", "Anywhere", "")]);
    let (commands, _) = present(&ufw, &serde_json::json!({}));
    assert_eq!(commands.len(), 1);
    assert_eq!(commands.first().and_then(|args| args.get(..2)), Some(&words("insert 2")[..]));
    assert_eq!(ufw.rows().first(), Some(&scoped));
    assert_eq!(ufw.rows().len(), 3);
    assert_eq!(present(&ufw, &serde_json::json!({})).0, NONE);
}

#[test]
fn a_both_protocol_allow_keeps_the_existing_tcp_line_below_unrelated_managed_ports_and_adds_only_udp() {
    let existing = row("47443/tcp", "allow", "192.0.2.1", "proof:existing");
    let mut rows = vec![
        row("22/tcp", "allow", "Anywhere", "orbit:public-ssh"),
        row("51820/udp", "allow", "Anywhere", "orbit:wireguard"),
        existing.clone(),
        row("47443/tcp", "deny", "Anywhere", "proof:deny"),
        row("47443/udp", "deny", "Anywhere", "proof:deny"),
    ];
    rows.extend([trust(), trust6()]);
    let ufw = Double::new(rows);
    let before = ufw.rows();
    let request = serde_json::json!({"port": "47443", "protocol": "any"});
    let (commands, result) = present(&ufw, &request);
    assert_eq!(commands, vec![command("insert 5 allow in proto udp from 192.0.2.1 to any port 47443", "orbit web")]);
    assert_eq!(result, Ok(1));
    let kept: Vec<Row> = ufw.rows().into_iter().filter(|row| row.to != "47443/udp" || row.action != "allow").collect();
    assert_eq!(kept, before);
    assert_eq!(ufw.rows().into_iter().filter(|row| row.comment == existing.comment).collect::<Vec<_>>(), vec![existing]);
    assert_eq!(present(&ufw, &request).0, NONE);
}

#[test]
fn an_equal_tcp_line_below_source_disjoint_managed_trust_keeps_its_comment_and_only_udp_is_added() {
    let existing = row("47443/tcp", "allow", "10.232.2.11", "proof:existing");
    let ufw = Double::new(vec![
        trust(),
        existing.clone(),
        row("47443/tcp", "deny", "Anywhere", "proof:deny"),
        row("47443/udp", "deny", "Anywhere", "proof:deny"),
    ]);
    let before = ufw.rows();
    let request = serde_json::json!({"port": "47443", "protocol": "any", "source": "10.232.2.11"});
    let (commands, result) = present(&ufw, &request);
    assert_eq!(commands, vec![command("insert 1 allow in proto udp from 10.232.2.11 to any port 47443", "orbit web")]);
    assert_eq!(result, Ok(1));
    let kept: Vec<Row> = ufw.rows().into_iter().filter(|row| row.to != "47443/udp" || row.action != "allow").collect();
    assert_eq!(kept, before);
    assert_eq!(ufw.rows().into_iter().filter(|row| row.comment == existing.comment).collect::<Vec<_>>(), vec![existing]);
    assert_eq!(present(&ufw, &request).0, NONE);
}

#[test]
fn a_partial_application_preserves_the_actual_tcp_row_below_scoped_trust_and_inserts_only_udp() {
    let request = serde_json::json!({"port": "47372", "protocol": "any", "source": "10.232.2.11", "comment": "orbit"});
    let before = inventory(PARTIAL_APPLICATION).expect("rows");
    assert_eq!(before.get(2), Some(&row("10.45.0.2 on orbit", "allow", "Anywhere", "orbit:wireguard-members")));
    assert_eq!(before.get(5).map(|row| row.comment.as_str()), Some("proof:existing"));
    let commands = plan(&rule("present", &request), before.clone());
    assert_eq!(commands, vec![command("insert 3 allow in proto udp from 10.232.2.11 to any port 47372", "orbit")]);
    let ufw = Double::new(before.clone());
    for args in &commands {
        ufw.apply(args).expect("apply");
    }
    assert_eq!(ufw.rows().get(6), before.get(5));
    let kept: Vec<Row> = ufw.rows().into_iter().enumerate().filter(|(index, _)| *index != 2).map(|(_, row)| row).collect();
    assert_eq!(kept, before);
    assert_eq!(check(&rule("present", &request), &inventory(&ufw.text()).expect("rows")), Ok(()));
    let after = ufw.rows();
    let (commands, result) = present(&ufw, &request);
    assert_eq!(commands, NONE);
    assert_eq!(result, Ok(0));
    assert_eq!(ufw.rows(), after);
}

#[test]
fn managed_trust_agreeing_with_an_allow_does_not_hide_an_intervening_operator_deny() {
    let mut rows = inventory(PARTIAL_APPLICATION).expect("rows");
    rows.swap(5, 6);
    let ufw = Double::new(rows);
    let request = serde_json::json!({"port": "47372", "source": "10.232.2.11"});
    assert_eq!(check(&rule("present", &request), &inventory(&ufw.text()).expect("rows")), Err("firewall.verify_failed"));
    let (commands, result) = present(&ufw, &request);
    assert_eq!(commands.iter().map(|args| args.first().cloned().unwrap_or_default()).collect::<Vec<_>>(), ["--force", "insert"]);
    assert!(result.is_ok());
    assert_eq!(ufw.tuples(Some("47372")).get(..2), Some(&[tuple("47372/tcp", "allow", "10.232.2.11"), tuple("47372/tcp", "deny", "Anywhere")][..]));
    assert_eq!(present(&ufw, &request).0, NONE);
}

#[test]
fn an_overlapping_managed_allow_leaves_the_unrestricted_existing_allow_intact() {
    for to in ["Anywhere", "Anywhere on orbit", "10.45.0.2 on orbit"] {
        let mut rows = inventory(PARTIAL_APPLICATION).expect("rows");
        if let Some(trust) = rows.get_mut(2) {
            to.clone_into(&mut trust.to);
        }
        let ufw = Double::new(rows.clone());
        let (commands, result) = present(&ufw, &serde_json::json!({"port": "47372", "source": "10.232.2.11"}));
        assert_eq!(commands, NONE, "{to}");
        assert_eq!(result, Ok(0), "{to}");
        assert_eq!(ufw.rows(), rows, "{to}");
    }
}

#[test]
fn an_overlapping_managed_deny_still_moves_the_unrestricted_existing_allow() {
    for to in ["Anywhere", "Anywhere on orbit", "10.45.0.2 on orbit"] {
        let ufw = Double::new(vec![
            row(to, "deny", "Anywhere", "orbit:restricted"),
            row("47372/tcp", "allow", "10.232.2.11", "proof:existing"),
        ]);
        let request = serde_json::json!({"port": "47372", "source": "10.232.2.11"});
        assert_eq!(check(&rule("present", &request), &inventory(&ufw.text()).expect("rows")), Err("firewall.verify_failed"), "{to}");
        let (commands, result) = present(&ufw, &request);
        assert_eq!(commands.iter().map(|args| args.first().cloned().unwrap_or_default()).collect::<Vec<_>>(), ["--force", "insert"], "{to}");
        assert!(result.is_ok(), "{to}");
        assert_eq!(ufw.rows().first().map(|row| row.to.as_str()), Some("47372/tcp"), "{to}");
        assert_eq!(ufw.rows().get(1).map(|row| row.to.as_str()), Some(to), "{to}");
        assert_eq!(present(&ufw, &request).0, NONE, "{to}");
    }
}

#[test]
fn a_failed_second_allow_keeps_tcp_and_a_retry_adds_only_udp_for_the_same_source() {
    let ufw = Double::standard();
    let commands = plan(&rule("present", &serde_json::json!({"protocol": "any"})), inventory(&ufw.text()).expect("rows"));
    assert_eq!(commands.len(), 2);
    ufw.apply(commands.first().expect("first command")).expect("apply");
    let tcp = ufw.rows().first().cloned();
    ufw.fail.set(true);
    assert_eq!(ufw.apply(commands.get(1).expect("second command")).map_err(|failure| failure.code), Err("firewall.apply_failed"));
    assert_eq!(ufw.tuples(Some("443")), vec![tuple("443/tcp", "allow", "192.0.2.1")]);
    ufw.fail.set(false);
    let (retry, result) = present(&ufw, &serde_json::json!({"protocol": "any"}));
    assert_eq!(retry.len(), 1);
    assert_eq!(retry.first().and_then(|args| args.iter().skip_while(|word| *word != "proto").nth(1)).map(String::as_str), Some("udp"));
    assert_eq!(ufw.rows().first().cloned(), tcp);
    assert_eq!(result, Ok(1));
    assert_eq!(present(&ufw, &serde_json::json!({"protocol": "any"})).0, NONE);
}

#[test]
fn a_partial_apply_is_not_rolled_back_and_a_repeat_repairs_only_the_missing_lines() {
    let ufw = Double::standard();
    let any = serde_json::json!({"source": "any", "protocol": "any"});
    let wanted = rule("present", &any);
    let commands = plan(&wanted, inventory(&ufw.text()).expect("rows"));
    ufw.apply(commands.first().expect("first command")).expect("apply");
    ufw.fail.set(true);
    assert!(ufw.apply(commands.get(1).expect("second command")).is_err());
    assert_eq!(check(&wanted, &inventory(&ufw.text()).expect("rows")), Err("firewall.verify_failed"));
    assert_eq!(ufw.tuples(Some("443")).len(), 1);
    ufw.fail.set(false);
    assert_eq!(present(&ufw, &any).0.len(), 3);
    assert_eq!(ufw.tuples(Some("443")).len(), 4);
    assert_eq!(present(&ufw, &any).0, NONE);
}

#[test]
fn an_interrupted_move_can_leave_the_line_absent_and_a_repeat_restores_it() {
    let ufw = Double::new(vec![row("443/tcp", "deny", "Anywhere", "proof:deny"), trust(), trust6()]);
    ufw.rows.borrow_mut().push(row("443/tcp", "allow", "10.44.0.9", "orbit web"));
    let wanted = rule("present", &serde_json::json!({"source": "10.44.0.9"}));
    let commands = plan(&wanted, inventory(&ufw.text()).expect("rows"));
    assert_eq!(commands.first().and_then(|args| args.get(..2)), Some(&words("--force delete")[..]));
    ufw.apply(commands.first().expect("first command")).expect("apply");
    assert_eq!(check(&wanted, &inventory(&ufw.text()).expect("rows")), Err("firewall.verify_failed"));
    assert_eq!(present(&ufw, &serde_json::json!({"source": "10.44.0.9"})).0.len(), 1);
    assert_eq!(present(&ufw, &serde_json::json!({"source": "10.44.0.9"})).0, NONE);
}

#[test]
fn invalid_ipv6_is_a_typed_refusal_rather_than_an_invented_address() {
    for source in ["2001:db8:1", "2001:db8::1::2", "1:2:3:4:5:6:7:8:9", "1:2:3:4:5:6:7::8"] {
        let ufw = Double::standard();
        assert_eq!(present(&ufw, &serde_json::json!({"source": source})).1, Err("firewall.input_invalid"), "{source}");
    }
}
