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
        self.stored
            .pop_front()
            .expect("unexpected stored inventory read")
    }
}
fn input() -> Input {
    Input::Converge {
        route_id: 7,
        router: "192.0.2.1".parse().unwrap(),
        workload: "192.0.2.2".parse().unwrap(),
    }
}
fn active(lines: &[&str]) -> String {
    format!("Status: active\n{}\n", lines.join("\n"))
}
const OWNED: &str = "[ 2] 192.0.2.2 443/tcp ALLOW IN 192.0.2.1 # orbit:route-7-lan";
const OTHER: &str = "[ 1] 443/tcp ALLOW IN Anywhere # orbit:route-70-lan";

#[test]
fn adds_only_the_route_allowance_and_repeats_without_mutation() {
    let existing = active(&[OTHER]);
    let complete = active(&[OTHER, OWNED]);
    let mut host = Fake::new(&[&existing, "added", &complete, &complete]);
    assert_eq!(route(&input(), &mut host), Ok(Output { matches: true }));
    assert_eq!(
        host.calls[1],
        [
            "allow",
            "in",
            "proto",
            "tcp",
            "from",
            "192.0.2.1",
            "to",
            "192.0.2.2",
            "port",
            "443",
            "comment",
            "orbit:route-7-lan"
        ]
    );
    route(&input(), &mut host).unwrap();
    assert_eq!(host.calls.len(), 4);
}

#[test]
fn removes_every_exact_comment_in_descending_order_and_repeats() {
    let old = "[ 9] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:route-7-lan";
    let unparsed_shape = "[ 5] OpenSSH ALLOW IN Anywhere # orbit:route-7-lan";
    let initial = active(&[OTHER, OWNED, unparsed_shape, old]);
    let clean = active(&[OTHER]);
    let mut host = Fake::new(&[
        &initial, "deleted", "deleted", "deleted", &clean, &clean, &clean,
    ]);
    let input = Input::Remove { route_id: 7 };
    route(&input, &mut host).unwrap();
    assert_eq!(
        &host.calls[1..4],
        &[
            vec!["--force".to_owned(), "delete".into(), "9".into()],
            vec!["--force".to_owned(), "delete".into(), "5".into()],
            vec!["--force".to_owned(), "delete".into(), "2".into()],
        ]
    );
    route(&input, &mut host).unwrap();
    assert_eq!(host.calls.len(), 7);
}

#[test]
fn inactive_firewall_stays_inactive_and_convergence_checks_stored_rules() {
    let expected = inventory::live(&active(&[OWNED])).unwrap().1;
    let mut host = Fake::new(&[
        "Status: inactive",
        "added",
        "Status: inactive",
        "Status: inactive",
        "Status: inactive",
        "Status: inactive",
    ]);
    host.stored
        .extend([Ok(vec![]), Ok(expected.clone()), Ok(expected)]);
    route(&input(), &mut host).unwrap();
    route(&input(), &mut host).unwrap();
    route(&Input::Remove { route_id: 7 }, &mut host).unwrap();
    assert_eq!(host.calls.iter().filter(|a| a[0] != "status").count(), 1);
    assert!(!host.calls.iter().flatten().any(|a| a == "enable"));
}

#[test]
fn invalid_intent_or_unreadable_inventory_refuses_before_mutation() {
    let mut host = Fake::new(&[]);
    assert_eq!(
        route(&Input::Remove { route_id: 0 }, &mut host),
        Err(Error::Invalid {})
    );
    assert!(host.calls.is_empty());
    for value in [
        r#"{"operation":"remove","route_id":7,"router":"192.0.2.1"}"#,
        r#"{"operation":"converge","route_id":7,"router":"192.0.2.1/24","workload":"192.0.2.2"}"#,
        r#"{"operation":"converge","route_id":7,"router":"::1","workload":"192.0.2.2"}"#,
    ] {
        assert!(serde_json::from_str::<Input>(value).is_err());
    }
    let mut host = Fake::new(&["unexpected"]);
    assert_eq!(
        route(&input(), &mut host),
        Err(Error::StatusUnrecognized {})
    );
    assert_eq!(host.calls.len(), 1);
    let mut host = Fake::new(&[&active(&["unreadable # orbit:route-7-lan"])]);
    assert_eq!(
        route(&Input::Remove { route_id: 7 }, &mut host),
        Err(Error::Collision {})
    );
    assert_eq!(host.calls.len(), 1);
    for error in [io::ErrorKind::NotFound, io::ErrorKind::PermissionDenied] {
        let mut host = Fake::new(&[]);
        host.outputs.push_back(Err(io::Error::from(error)));
        assert_eq!(route(&input(), &mut host), Err(Error::Status {}));
        assert_eq!(host.calls.len(), 1);
    }
    let mut host = Fake::new(&["Status: inactive"]);
    host.stored
        .push_back(Err(io::Error::from(io::ErrorKind::PermissionDenied)));
    assert_eq!(route(&input(), &mut host), Err(Error::Status {}));
    assert_eq!(host.calls.len(), 1);
}

#[test]
fn mutation_and_verification_failures_remain_distinct() {
    for (input, initial, error) in [
        (input(), active(&[]), Error::Apply {}),
        (
            Input::Remove { route_id: 7 },
            active(&[OWNED]),
            Error::Remove {},
        ),
    ] {
        let mut host = Fake::new(&[&initial]);
        host.outputs.push_back(Ok((1, "failed".into())));
        assert_eq!(route(&input, &mut host), Err(error));
        assert_eq!(host.calls.len(), 2);
    }
    let mut host = Fake::new(&[&active(&[]), "added", &active(&[])]);
    assert_eq!(route(&input(), &mut host), Err(Error::Verify {}));
    let complete = active(&[OWNED]);
    let mut host = Fake::new(&[&complete, "deleted", &complete]);
    assert_eq!(
        route(&Input::Remove { route_id: 7 }, &mut host),
        Err(Error::RemoveVerify {})
    );
}
