use super::*;

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

const V4: &str = "[ 1] 443/tcp ALLOW IN Anywhere # orbit:node:7:firewall:web";
const V6: &str = "[ 2] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:node:7:firewall:web";

#[test]
fn ownership_distinguishes_exact_drift_and_missing_rules_and_family_comments_are_exact() {
    let rows = inventory::live(&format!(
        "Status: active\n{V4}\n{V6}\n[3] 80/tcp ALLOW IN Anywhere\n[4] OpenSSH ALLOW IN Anywhere # orbit:malformed\n"
    ))
    .unwrap()
    .1;
    let mut expected = rows[0].shape.clone().unwrap();
    expected.family = None;
    let mut missing = expected.clone();
    missing.comment = "orbit:missing".into();
    let mut malformed = expected.clone();
    malformed.comment = "orbit:malformed".into();
    assert_eq!(
        [&expected, &missing, &malformed].map(|shape| inventory::ownership(&rows, shape)),
        [
            inventory::Ownership::Exact,
            inventory::Ownership::Missing,
            inventory::Ownership::Drift
        ]
    );
    assert!(inventory::family_comment(
        "orbit:router-lan-https:5",
        "orbit:router-lan-https"
    ));
    assert!(!inventory::family_comment(
        "orbit:router-lan-https-operator",
        "orbit:router-lan-https"
    ));
}

#[test]
fn stored_recovery_checks_both_families_and_refuses_restricted_or_unreadable_rows() {
    let live = inventory::live(&format!("Status: active\n{V4}\n{V6}\n"))
        .unwrap()
        .1;
    let mut expected = live[0].shape.clone().unwrap();
    expected.family = None;
    let hex = expected
        .comment
        .bytes()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    let mut rows = inventory::stored(
        &format!("### tuple ### allow tcp 443 0.0.0.0/0 any 0.0.0.0/0 in comment={hex}"),
        "v4",
    );
    assert_eq!(
        inventory::ownership(&rows, &expected),
        inventory::Ownership::Drift
    );
    rows.extend(inventory::stored(
        &format!("### tuple ### allow tcp 443 ::/0 any ::/0 in comment={hex}"),
        "v6",
    ));
    assert_eq!(
        inventory::ownership(&rows, &expected),
        inventory::Ownership::Exact
    );
    for tail in ["in_orbit", "in!out_orbit", "invalid"] {
        let rows = inventory::stored(
            &format!("### tuple ### allow tcp 443 10.44.0.2 any 0.0.0.0/0 {tail} comment={hex}"),
            "v4",
        );
        assert_eq!(
            inventory::ownership(&rows, &expected),
            inventory::Ownership::Drift
        );
    }
    rows.extend(inventory::stored(
        &format!("### tuple ### malformed comment={hex}"),
        "v4",
    ));
    assert_eq!(
        inventory::ownership(&rows, &expected),
        inventory::Ownership::Drift
    );
}
