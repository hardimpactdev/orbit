use super::*;
use crate::commands::recipe::WorkDir;

#[derive(Default)]
struct FakeHost {
    calls: Vec<Vec<String>>,
    down: bool,
    fail: Option<&'static str>,
}
impl Host for FakeHost {
    fn run(&mut self, name: &str, args: &[&str]) -> bool {
        self.calls.push(
            std::iter::once(name)
                .chain(args.iter().copied())
                .map(str::to_string)
                .collect(),
        );
        !(self.down && name == "ip" || self.fail.is_some_and(|step| args.contains(&step)))
    }
}
fn fixture() -> (WorkDir, Files, FakeHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    (work, files, FakeHost::default())
}
fn input() -> Input {
    Input::Converge {
        address: "10.44.0.1".into(),
        domain: "orbit".into(),
    }
}
#[test]
fn routes_only_private_names_before_selecting_dns_and_repeats_without_rewriting_units() {
    let (_work, files, mut host) = fixture();
    assert_eq!(
        apply(&input(), &files, &mut host).unwrap(),
        Routed {
            configured: true,
            changed: true
        }
    );
    assert_eq!(
        host.calls,
        [
            vec!["systemctl", "daemon-reload"],
            vec!["ip", "link", "show", "dev", "orbit"],
            vec!["resolvectl", "domain", "orbit", "~orbit"],
            vec!["resolvectl", "default-route", "orbit", "false"],
            vec!["resolvectl", "dns", "orbit", "10.44.0.1"]
        ]
    );
    let path = files.path(DROP_IN);
    let inode = fs::metadata(&path).unwrap().ino();
    host.calls.clear();
    assert!(!apply(&input(), &files, &mut host).unwrap().changed);
    assert_eq!(fs::metadata(path).unwrap().ino(), inode);
    assert!(!host.calls.iter().any(|call| call[0] == "systemctl"));
}
#[test]
fn managed_peers_keep_their_dns_policy_and_inactive_tunnels_get_only_the_boot_drop_in() {
    for peer in [true, false] {
        let (_work, files, mut host) = fixture();
        if peer {
            directory(&files, files.path(PEER_STATE).parent().unwrap()).unwrap();
            fs::write(files.path(PEER_STATE), "peer-owned policy").unwrap();
        } else {
            host.down = true;
        }
        assert!(apply(&input(), &files, &mut host).unwrap().configured);
        assert!(files.path(DROP_IN).is_file());
        assert!(!host.calls.iter().any(|call| call[0] == "resolvectl"));
    }
}
#[test]
fn removal_retracts_only_the_owned_drop_in_and_reverts_an_active_nonpeer_link() {
    let (_work, files, mut host) = fixture();
    apply(&input(), &files, &mut host).unwrap();
    host.calls.clear();
    assert_eq!(
        apply(&Input::Remove {}, &files, &mut host).unwrap(),
        Routed {
            configured: false,
            changed: true
        }
    );
    assert!(!files.path(DROP_IN).exists());
    assert_eq!(
        host.calls.last().unwrap(),
        &["resolvectl", "revert", "orbit"]
    );
    host.calls.clear();
    assert!(!apply(&Input::Remove {}, &files, &mut host).unwrap().changed);
    assert!(host.calls.is_empty());
}
#[test]
fn stops_before_setting_dns_if_suffix_or_default_route_configuration_fails() {
    for failure in ["domain", "default-route", "daemon-reload"] {
        let (_work, files, mut host) = fixture();
        host.fail = Some(failure);
        assert!(apply(&input(), &files, &mut host).is_err());
        assert!(!host
            .calls
            .iter()
            .any(|call| call.get(1).map(String::as_str) == Some("dns")));
    }
}
#[test]
fn refuses_invalid_domains_addresses_and_foreign_units_before_commands() {
    for (address, domain) in [
        ("bad", "orbit"),
        ("10.44.0.1", "orbit;false"),
        ("10.44.0.1", "-orbit"),
        ("10.44.0.1", "a..b"),
    ] {
        let (_work, files, mut host) = fixture();
        assert!(apply(
            &Input::Converge {
                address: address.into(),
                domain: domain.into()
            },
            &files,
            &mut host
        )
        .is_err());
        assert!(host.calls.is_empty());
        assert!(!files.path(DROP_IN).exists());
    }
    let (_work, files, mut host) = fixture();
    directory(&files, files.path(DROP_IN).parent().unwrap()).unwrap();
    fs::write(files.path(DROP_IN), "operator-owned").unwrap();
    assert!(apply(&input(), &files, &mut host).is_err());
    assert!(host.calls.is_empty());
}
