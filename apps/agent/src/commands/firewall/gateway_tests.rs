use super::*;
use std::collections::VecDeque;

struct Fake {
    outputs: VecDeque<io::Result<(i32, String)>>,
    stored: VecDeque<io::Result<Vec<Row>>>,
    calls: Vec<Vec<String>>,
}
impl Fake {
    fn new(outputs: &[&str]) -> Self {
        Self {
            outputs: outputs.iter().map(|s| Ok((0, (*s).into()))).collect(),
            stored: VecDeque::new(),
            calls: vec![],
        }
    }
}
impl Host for Fake {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
        self.calls.push(args.to_vec());
        self.outputs.pop_front().expect("unexpected UFW call")
    }
    fn stored(&mut self) -> io::Result<Vec<Row>> {
        self.stored.pop_front().expect("unexpected stored read")
    }
}
const RECOVERY4: &str = "[ 4] 2222/tcp ALLOW IN Anywhere # orbit:public-ssh-recovery";
const RECOVERY6: &str = "[ 5] 2222/tcp (v6) ALLOW IN Anywhere (v6) # orbit:public-ssh-recovery";
const VPN4: &str = "[ 1] 51820/udp ALLOW IN Anywhere # orbit:vpn-wireguard";
const VPN6: &str = "[ 2] 51820/udp (v6) ALLOW IN Anywhere (v6) # orbit:vpn-wireguard";
fn active(lines: &[&str]) -> String {
    format!("Status: active\n{}\n", lines.join("\n"))
}
fn rows(lines: &[&str]) -> Vec<Row> {
    inventory::live(&active(lines)).unwrap().1
}
fn input() -> Input {
    let mut vpn = rows(&[VPN4]).remove(0).shape.unwrap();
    vpn.family = None;
    let mut recovery = rows(&[RECOVERY4]).remove(0).shape.unwrap();
    recovery.family = None;
    Input {
        desired: vec![vpn],
        recovery,
    }
}

#[test]
fn adds_and_verifies_vpn_before_deleting_recovery_then_repeats() {
    let recovery = active(&[RECOVERY4, RECOVERY6]);
    let both = active(&[VPN4, VPN6, RECOVERY4, RECOVERY6]);
    let vpn = active(&[VPN4, VPN6]);
    let mut host = Fake::new(&[
        &recovery, "added", &both, "deleted", "deleted", &vpn, &vpn, &vpn,
    ]);
    run(&input(), &mut host).unwrap();
    assert_eq!(
        host.calls[1],
        [
            "allow",
            "in",
            "proto",
            "udp",
            "from",
            "any",
            "to",
            "any",
            "port",
            "51820",
            "comment",
            "orbit:vpn-wireguard"
        ]
    );
    assert_eq!(host.calls[2], ["status", "numbered"]);
    assert_eq!(host.calls[3], ["--force", "delete", "5"]);
    assert_eq!(host.calls[4], ["--force", "delete", "4"]);
    run(&input(), &mut host).unwrap();
    assert_eq!(host.calls.len(), 8);
}

#[test]
fn inactive_bootstrap_proves_both_stored_recovery_families_before_enable() {
    let recovery = active(&[RECOVERY4, RECOVERY6]);
    let both = active(&[VPN4, VPN6, RECOVERY4, RECOVERY6]);
    let mut host = Fake::new(&[
        "Status: inactive",
        "added recovery",
        "enabled",
        &recovery,
        "added VPN",
        &both,
        "deleted",
        "deleted",
        &active(&[VPN4, VPN6]),
    ]);
    host.stored
        .extend([Ok(vec![]), Ok(rows(&[RECOVERY4, RECOVERY6]))]);
    assert_eq!(run(&input(), &mut host), Ok(Output { matches: true }));
    assert_eq!(host.calls[1].last().unwrap(), "orbit:public-ssh-recovery");
    assert_eq!(host.calls[2], ["--force", "enable"]);
    assert_eq!(host.calls[4].last().unwrap(), "orbit:vpn-wireguard");
}

#[test]
fn rejects_drift_in_any_stored_or_live_rule_before_mutation() {
    let broad = "[1] Anywhere ALLOW IN Anywhere # orbit:vpn-wireguard";
    let duplicate = rows(&[RECOVERY4, RECOVERY4, RECOVERY6]);
    for (observed, expected_comment) in [
        (rows(&[broad]), "orbit:vpn-wireguard"),
        (duplicate, "orbit:public-ssh-recovery"),
        (rows(&[RECOVERY4]), "orbit:public-ssh-recovery"),
    ] {
        let mut host = Fake::new(&["Status: inactive"]);
        host.stored.push_back(Ok(observed));
        assert_eq!(
            run(&input(), &mut host),
            Err(GatewayError::Probe {
                comment: expected_comment.into()
            })
        );
        assert_eq!(host.calls.len(), 1);
    }
    for line in [
        broad,
        "[1] OpenSSH ALLOW IN Anywhere # orbit:vpn-wireguard",
        "unreadable # orbit:vpn-wireguard",
    ] {
        let mut host = Fake::new(&[&active(&[line])]);
        assert!(matches!(
            run(&input(), &mut host),
            Err(GatewayError::Probe { .. })
        ));
        assert_eq!(host.calls.len(), 1);
    }
}

#[test]
fn never_enables_without_exact_stored_recovery() {
    for stored in [vec![], rows(&[RECOVERY4]), rows(&[RECOVERY6])] {
        let mut host = Fake::new(&["Status: inactive", "added"]);
        host.stored.extend([Ok(vec![]), Ok(stored)]);
        assert_eq!(run(&input(), &mut host), Err(GatewayError::Recovery {}));
        assert_eq!(host.calls.len(), 2);
    }
    let mut host = Fake::new(&["Status: inactive"]);
    host.stored.push_back(Err(io::Error::other("unreadable")));
    assert_eq!(run(&input(), &mut host), Err(GatewayError::Recovery {}));
    assert_eq!(host.calls.len(), 1);
}

#[test]
fn missing_or_similar_comments_do_not_prove_vpn_and_recovery_stays() {
    for lines in [
        vec![RECOVERY4, RECOVERY6],
        vec![VPN4, RECOVERY4, RECOVERY6],
        vec![
            "[1] 51820/udp ALLOW IN Anywhere # xorbit:vpn-wireguard",
            "[2] 51820/udp ALLOW IN Anywhere # orbit:vpn-wireguard-extra",
            RECOVERY4,
            RECOVERY6,
        ],
    ] {
        let mut host = Fake::new(&[&active(&[RECOVERY4, RECOVERY6]), "added", &active(&lines)]);
        assert_eq!(run(&input(), &mut host), Err(probe(&input().desired[0])));
        assert!(!host.calls.iter().flatten().any(|a| a == "delete"));
    }
}

#[test]
fn reports_status_enable_apply_and_remove_failures_at_their_boundary() {
    let mut host = Fake::new(&["bad status"]);
    assert_eq!(
        run(&input(), &mut host),
        Err(GatewayError::StatusInvalid {})
    );
    for kind in [io::ErrorKind::NotFound, io::ErrorKind::PermissionDenied] {
        let mut host = Fake::new(&[]);
        host.outputs.push_back(Err(io::Error::from(kind)));
        assert_eq!(run(&input(), &mut host), Err(GatewayError::Status {}));
    }
    let mut host = Fake::new(&["Status: inactive"]);
    host.stored.extend([
        Ok(rows(&[RECOVERY4, RECOVERY6])),
        Ok(rows(&[RECOVERY4, RECOVERY6])),
    ]);
    host.outputs.push_back(Ok((1, "enable failed".into())));
    assert_eq!(run(&input(), &mut host), Err(GatewayError::Enable {}));
    let mut host = Fake::new(&[&active(&[])]);
    host.outputs.push_back(Ok((1, "rule failed".into())));
    assert_eq!(run(&input(), &mut host), Err(GatewayError::Rule {}));
    let both = active(&[VPN4, VPN6, RECOVERY4, RECOVERY6]);
    let mut host = Fake::new(&[&both, &both]);
    host.outputs.push_back(Ok((1, "delete failed".into())));
    assert_eq!(run(&input(), &mut host), Err(GatewayError::Remove {}));
    let mut host = Fake::new(&[&both, &both, "deleted", "deleted", &both]);
    assert_eq!(run(&input(), &mut host), Err(probe(&input().recovery)));
}

#[test]
fn rejects_invalid_or_overlapping_intent_before_host_access() {
    let mut cases = vec![];
    let mut value = input();
    value.recovery.family = Some("v4".into());
    cases.push(value);
    let mut value = input();
    value.desired.push(value.recovery.clone());
    cases.push(value);
    let mut value = input();
    value.desired[0].comment = "operator-rule".into();
    cases.push(value);
    let mut value = input();
    value.desired.clear();
    cases.push(value);
    for value in cases {
        let mut host = Fake::new(&[]);
        assert_eq!(run(&value, &mut host), Err(GatewayError::Invalid {}));
        assert!(host.calls.is_empty());
    }
}

#[test]
fn a_firewall_that_stays_inactive_reports_the_enable_boundary() {
    let mut host = Fake::new(&["Status: inactive", "enabled", "Status: inactive"]);
    host.stored.extend([
        Ok(rows(&[RECOVERY4, RECOVERY6])),
        Ok(rows(&[RECOVERY4, RECOVERY6])),
    ]);
    assert_eq!(
        run(&input(), &mut host),
        Err(GatewayError::StatusInvalid {})
    );
    assert_eq!(host.calls.len(), 3);
}

#[test]
fn inactive_verification_fails_without_retiring_recovery() {
    let mut host = Fake::new(&[
        &active(&[VPN4, VPN6, RECOVERY4, RECOVERY6]),
        "Status: inactive",
    ]);
    assert_eq!(run(&input(), &mut host), Err(probe(&input().desired[0])));
    assert_eq!(host.calls.len(), 2);
}
