use super::*;
use std::collections::VecDeque;

struct Fake {
    outputs: VecDeque<io::Result<(i32, String)>>,
    calls: Vec<Vec<String>>,
    stored: Vec<Row>,
}
impl Fake {
    fn new(outputs: &[&str]) -> Self {
        Self {
            outputs: outputs.iter().map(|s| Ok((0, (*s).into()))).collect(),
            calls: vec![],
            stored: vec![],
        }
    }
}
impl Host for Fake {
    fn ufw(&mut self, args: &[String]) -> io::Result<(i32, String)> {
        self.calls.push(args.to_vec());
        self.outputs
            .pop_front()
            .expect("unexpected firewall command")
    }
    fn stored(&mut self) -> io::Result<Vec<Row>> {
        Ok(self.stored.clone())
    }
}
fn input() -> RuleInput {
    RuleInput {
        node_id: 7,
        name: "web".into(),
        action: "allow".into(),
        source: "any".into(),
        port: "443".into(),
        protocol: "tcp".into(),
        operation: Operation::Converge,
    }
}
const V4: &str = "[ 1] 443/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web";
const V6: &str = "[ 2] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:node:7:firewall:web";
fn active(lines: &[&str]) -> String {
    format!("Status: active\n{}\n", lines.join("\n"))
}

#[test]
fn named_rules_preserve_inactive_state_and_converge_idempotently() {
    let input = input();
    let complete = active(&[V4, V6]);
    let mut host = Fake::new(&[
        "Status: inactive\n",
        &active(&[]),
        "Rule added",
        &complete,
        &complete,
    ]);
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Inactive
        })
    );
    assert_eq!(host.calls.len(), 1);
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Active
        })
    );
    assert_eq!(
        host.calls[2],
        [
            "prepend",
            "allow",
            "in",
            "from",
            "any",
            "to",
            "any",
            "port",
            "443",
            "proto",
            "tcp",
            "comment",
            "orbit:node:7:firewall:web"
        ]
    );
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Active
        })
    );
    assert_eq!(host.calls.len(), 5);
}

#[test]
fn named_rules_repair_one_missing_family_without_deleting_the_matching_family() {
    let mut host = Fake::new(&[&active(&[V4]), "Rule added (v6)", &active(&[V4, V6])]);
    named_rule(&input(), &mut host).unwrap();
    assert_eq!(host.calls.len(), 3);
    assert_eq!(host.calls[1][0], "prepend");
}

#[test]
fn named_rules_replace_only_one_owned_stale_shape() {
    let stale = "[1] 443/tcp ALLOW IN 192.0.2.0/24 # orbit:node:7:firewall:web";
    let unrelated = "[2] 443/tcp ALLOW IN Anywhere # operator-owned";
    let mut host = Fake::new(&[
        &active(&[stale, unrelated]),
        "deleted",
        "added",
        &active(&[unrelated, V4, V6]),
    ]);
    named_rule(&input(), &mut host).unwrap();
    assert_eq!(
        host.calls[1],
        [
            "delete",
            "allow",
            "in",
            "from",
            "192.0.2.0/24",
            "to",
            "any",
            "port",
            "443",
            "proto",
            "tcp",
            "comment",
            "orbit:node:7:firewall:web"
        ]
    );
    assert!(!host.calls.iter().flatten().any(|s| s == "operator-owned"));
}

#[test]
fn ownership_refusals_happen_before_any_mutation() {
    for rows in [
        vec![V4, V4, V6],
        vec!["[1] 443/tcp on eth0 ALLOW IN Anywhere # orbit:node:7:firewall:web"],
        vec!["[1] OpenSSH ALLOW IN Anywhere # orbit:node:7:firewall:web"],
        vec!["[1] 443/tcp ALLOW IN Anywhere ALLOW IN Anywhere # orbit:node:7:firewall:web"],
        vec![
            "[1] 80/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web",
            "[2] 80/tcp (v6) ALLOW IN Anywhere (v6) # orbit:node:7:firewall:web",
        ],
    ] {
        let mut host = Fake::new(&[&active(&rows)]);
        assert_eq!(named_rule(&input(), &mut host), Err(Error::Collision {}));
        assert_eq!(host.calls.len(), 1);
    }
    let mut invalid = input();
    invalid.name = "web;whoami".into();
    let mut host = Fake::new(&[]);
    assert_eq!(named_rule(&invalid, &mut host), Err(Error::Invalid {}));
    assert!(host.calls.is_empty());
}

#[test]
fn named_removal_is_exact_and_idempotent() {
    let mut input = input();
    input.operation = Operation::Remove;
    let mut host = Fake::new(&[
        &active(&[V4, V6]),
        "removed",
        &active(&[]),
        &active(&[]),
        "Status: inactive",
    ]);
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Absent
        })
    );
    assert_eq!(host.calls[1][0], "delete");
    assert_eq!(host.calls[1].last().unwrap(), "orbit:node:7:firewall:web");
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Absent
        })
    );
    assert_eq!(
        named_rule(&input, &mut host),
        Ok(RuleOutput {
            backend: Backend::Inactive
        })
    );
    assert_eq!(host.calls.len(), 5);
}

#[test]
fn mutation_and_verification_failures_remain_distinct() {
    for (operation, initial, expected) in [
        (Operation::Converge, active(&[]), Error::Apply {}),
        (
            Operation::Converge,
            active(&["[1] 80/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web"]),
            Error::Reconcile {},
        ),
        (Operation::Remove, active(&[V4, V6]), Error::Remove {}),
    ] {
        let mut input = input();
        input.operation = operation;
        let mut host = Fake::new(&[&initial]);
        host.outputs
            .push_back(Ok((1, "private manager error".into())));
        assert_eq!(named_rule(&input, &mut host), Err(expected));
    }
    let mut host = Fake::new(&[&active(&[]), "added", &active(&[V4])]);
    assert_eq!(named_rule(&input(), &mut host), Err(Error::Verify {}));
    let mut input = input();
    input.operation = Operation::Remove;
    let mut host = Fake::new(&[&active(&[V4, V6]), "removed", &active(&[V4])]);
    assert_eq!(named_rule(&input, &mut host), Err(Error::RemoveVerify {}));
    let mut host = Fake::new(&["private unrecognized status"]);
    assert_eq!(
        named_rule(&input, &mut host),
        Err(Error::StatusUnrecognized {})
    );
    let mut host = Fake::new(&[]);
    host.outputs.push_back(Ok((1, "private failure".into())));
    assert_eq!(named_rule(&input, &mut host), Err(Error::Status {}));
}

#[test]
fn inspection_distinguishes_owned_drift_missing_and_unmanaged_live_shapes() {
    let expected = rule_shape(&input()).unwrap();
    let mut missing = expected.clone();
    missing.comment = "orbit:missing".into();
    let mut host = Fake::new(&[&active(&[
        V4,
        V6,
        "[3] 80/tcp ALLOW IN Anywhere",
        "[4] OpenSSH ALLOW IN Anywhere # orbit:malformed",
    ])]);
    let mut malformed = expected.clone();
    malformed.comment = "orbit:malformed".into();
    let result = inspect(
        &InspectInput {
            expected: vec![expected.clone(), missing, malformed],
            exclusive_families: vec![],
            stored: false,
        },
        &mut host,
    )
    .unwrap();
    assert_eq!(
        result.ownerships,
        [Ownership::Exact, Ownership::Missing, Ownership::Drift]
    );
    assert!(!result.matches);
    assert_eq!(result.live.len(), 2);
    assert_eq!(result.live[1].comment, "");
    let mut host = Fake::new(&[&active(&[
        V4,
        V6,
        "[3] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https:5",
    ])]);
    assert!(
        !inspect(
            &InspectInput {
                expected: vec![expected],
                exclusive_families: vec!["orbit:router-lan-https".into()],
                stored: false
            },
            &mut host
        )
        .unwrap()
        .matches
    );
    assert!(!inventory::family_comment(
        "orbit:router-lan-https-operator",
        "orbit:router-lan-https"
    ));
}

#[test]
fn parser_preserves_interfaces_forwarding_ports_and_canonical_networks() {
    let row =
        inventory::live_row("[ 1] 10.44.0.3 443/tcp on orbit ALLOW IN Anywhere # orbit:https");
    let shape = row.shape.unwrap();
    assert_eq!(row.number, Some(1));
    assert_eq!(
        (
            shape.destination.as_str(),
            shape.port.as_str(),
            shape.in_interface.as_deref()
        ),
        ("10.44.0.3", "443", Some("orbit"))
    );
    let shape = inventory::live_row(
        "[1] 10.44.0.0/24 on orbit ALLOW FWD 10.44.0.129/24 on orbit # orbit:vpn",
    )
    .shape
    .unwrap();
    assert_eq!(
        (
            shape.source.as_str(),
            shape.destination.as_str(),
            shape.direction.as_str()
        ),
        ("10.44.0.0/24", "10.44.0.0/24", "fwd")
    );
    assert_eq!(shape.in_interface, shape.out_interface);
    for (target, destination, port, protocol) in [
        ("53", "any", "53", "any"),
        ("10.20.0.10 50:53", "10.20.0.10", "50:53", "any"),
        ("8000:8010/udp", "any", "8000:8010", "udp"),
        ("2001:db8::10 53 (v6)", "2001:db8::10", "53", "any"),
    ] {
        let shape = inventory::live_row(&format!("[1] {target} ALLOW IN Anywhere # orbit:test"))
            .shape
            .unwrap();
        assert_eq!(
            (
                shape.destination.as_str(),
                shape.port.as_str(),
                shape.protocol.as_str()
            ),
            (destination, port, protocol)
        );
    }
    for value in ["0", "65536", "10:1", "1:2:3", "-1"] {
        assert!(inventory::normalize_port(value).is_none());
    }
    assert_eq!(
        inventory::normalize_endpoint("2001:db8::f/64").as_deref(),
        Some("2001:db8::/64")
    );
    assert_eq!(
        inventory::normalize_endpoint("192.0.2.128/32").as_deref(),
        Some("192.0.2.128")
    );
    for value in ["192.0.2.0/033", "192.0.2.0/33", "::/129", "secret"] {
        assert!(inventory::normalize_endpoint(value).is_none());
    }
}

#[test]
fn stored_recovery_checks_both_families_and_refuses_restricted_or_unreadable_rows() {
    let expected = rule_shape(&input()).unwrap();
    let hex = expected
        .comment
        .bytes()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    let mut rows = inventory::stored(
        &format!("### tuple ### allow tcp 443 0.0.0.0/0 any 0.0.0.0/0 in comment={hex}"),
        "v4",
    );
    assert_eq!(inventory::ownership(&rows, &expected), Ownership::Drift);
    rows.extend(inventory::stored(
        &format!("### tuple ### allow tcp 443 ::/0 any ::/0 in comment={hex}"),
        "v6",
    ));
    assert_eq!(inventory::ownership(&rows, &expected), Ownership::Exact);
    let mut host = Fake::new(&["Status: inactive"]);
    host.stored = rows.clone();
    assert_eq!(
        inspect(
            &InspectInput {
                expected: vec![expected.clone()],
                exclusive_families: vec![],
                stored: true
            },
            &mut host
        )
        .unwrap()
        .ownerships,
        [Ownership::Exact]
    );
    for tail in ["in_orbit", "in!out_orbit", "invalid"] {
        let rows = inventory::stored(
            &format!("### tuple ### allow tcp 443 10.44.0.2 any 0.0.0.0/0 {tail} comment={hex}"),
            "v4",
        );
        assert_eq!(inventory::ownership(&rows, &expected), Ownership::Drift);
    }
    rows.extend(inventory::stored(
        &format!("### tuple ### malformed comment={hex}"),
        "v4",
    ));
    assert_eq!(inventory::ownership(&rows, &expected), Ownership::Drift);
}

#[test]
fn router_inspection_requires_exact_exclusive_families_even_without_desired_members() {
    let families = ["https", "dns-udp", "dns-tcp"];
    let expected: Vec<_> = families
        .iter()
        .zip([("tcp", "443"), ("udp", "53"), ("tcp", "53")])
        .map(|(family, (protocol, port))| Shape {
            comment: format!("orbit:router-lan-{family}:5"),
            action: "allow".into(),
            direction: "in".into(),
            source: "10.20.0.11".into(),
            destination: "10.20.0.10".into(),
            port: port.into(),
            protocol: protocol.into(),
            in_interface: None,
            out_interface: None,
            family: Some("v4".into()),
        })
        .collect();
    let exact = "Status: active\n[1] 10.20.0.10 443/tcp ALLOW IN 10.20.0.11 # orbit:router-lan-https:5\n[2] 10.20.0.10 53/udp ALLOW IN 10.20.0.11 # orbit:router-lan-dns-udp:5\n[3] 10.20.0.10 53/tcp ALLOW IN 10.20.0.11 # orbit:router-lan-dns-tcp:5\n[4] OpenSSH ALLOW IN Anywhere # orbit:router-lan-https-operator\n";
    let request = |empty| InspectInput {
        expected: if empty { vec![] } else { expected.clone() },
        exclusive_families: families
            .iter()
            .map(|f| format!("orbit:router-lan-{f}"))
            .collect(),
        stored: false,
    };
    assert!(
        inspect(&request(false), &mut Fake::new(&[exact]))
            .unwrap()
            .matches
    );
    assert!(
        inspect(&request(true), &mut Fake::new(&["Status: active"]))
            .unwrap()
            .matches
    );
    for empty in [false, true] {
        for family in families {
            for target in [
                "10.20.0.10 53/udp",
                "10.20.0.10 53",
                "53",
                "50:53",
                "OpenSSH",
                "53/udp (v6)",
            ] {
                let output = format!(
                    "{}\n[9] {target} ALLOW IN Anywhere # orbit:router-lan-{family}:999\n",
                    if empty { "Status: active" } else { exact }
                );
                assert!(
                    !inspect(&request(empty), &mut Fake::new(&[&output]))
                        .unwrap()
                        .matches,
                    "{output}"
                );
            }
        }
    }
}
