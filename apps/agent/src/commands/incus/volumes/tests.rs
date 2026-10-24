use super::super::Output;
use super::*;
use serde_json::json;

#[derive(Default)]
struct Fake {
    names: Vec<String>,
    calls: Vec<Vec<String>>,
    fail: bool,
    changed_despite_failure: bool,
    invalid: bool,
}
impl Host for Fake {
    fn run(&mut self, args: &[&str]) -> Output {
        self.calls.push(args.iter().map(|s| (*s).into()).collect());
        if args[4] == "list" {
            return Output {
                code: 0,
                stdout: if self.invalid {
                    "[{}]".into()
                } else {
                    json!(self
                        .names
                        .iter()
                        .map(|n| json!({"name":n,"type":"custom"}))
                        .chain(std::iter::once(
                            json!({"name":"unrelated","type":"virtual-machine"})
                        ))
                        .collect::<Vec<_>>())
                    .to_string()
                },
                stderr: String::new(),
                truncated: false,
            };
        }
        if !self.fail || self.changed_despite_failure {
            match args[4] {
                "copy" => self.names.push(args[7].split_once('/').unwrap().1.into()),
                "rename" => {
                    self.names.retain(|n| n != args[7]);
                    self.names.push(args[8].into());
                }
                "delete" => self.names.retain(|n| n != args[7]),
                _ => panic!("unexpected operation {args:?}"),
            }
        }
        Output {
            code: if self.fail { 1 } else { 0 },
            stdout: String::new(),
            stderr: "busy".into(),
            truncated: false,
        }
    }
}
fn pool() -> Pool {
    Pool {
        project: "orbit-tasks".into(),
        pool: "tank".into(),
    }
}
fn request(operation: Operation) -> Input {
    Input {
        location: pool(),
        operation,
    }
}
fn clone_request() -> Input {
    request(Operation::CloneSeed {
        vm: "tvm-7".into(),
        fingerprint: Some("a".repeat(64)),
    })
}

#[test]
fn reads_only_custom_volumes_and_refuses_incomplete_inventory() {
    let mut host = Fake {
        names: vec!["data".into()],
        ..Fake::default()
    };
    assert_eq!(names(&mut host, &pool()).unwrap(), vec!["data"]);
    assert_eq!(names(&mut host, &pool()).unwrap(), vec!["data"]);
    host.invalid = true;
    assert!(matches!(
        names(&mut host, &pool()),
        Err(Error::Output { .. })
    ));
}
#[test]
fn clones_once_requires_a_source_and_keeps_a_preexisting_clone() {
    let mut host = Fake {
        names: vec!["orbit-topology-seed-aaaaaaaaaaaa".into()],
        ..Fake::default()
    };
    assert_eq!(
        change(&mut host, &clone_request()).unwrap().name,
        "tvm-7-topology-seed"
    );
    assert_eq!(
        host.calls[1],
        [
            "--project",
            "orbit-tasks",
            "storage",
            "volume",
            "copy",
            "--",
            "tank/orbit-topology-seed-aaaaaaaaaaaa",
            "tank/tvm-7-topology-seed"
        ]
    );
    change(&mut host, &clone_request()).unwrap();
    assert_eq!(host.calls.len(), 3);
    let mut host = Fake::default();
    assert_eq!(
        change(&mut host, &clone_request()),
        Err(Error::SeedMissing {})
    );
    assert_eq!(host.calls.len(), 1);
    let request = request(Operation::CloneSeed {
        vm: "tvm-7".into(),
        fingerprint: None,
    });
    assert_eq!(change(&mut host, &request), Err(Error::SeedMissing {}));
    host.names.push("tvm-7-topology-seed".into());
    assert!(change(&mut host, &request).is_ok());
}
#[test]
fn finishes_a_seed_and_removes_exactly_the_selected_volume_with_repeated_calls() {
    let mut host = Fake {
        names: vec![
            "orbit-topology-seed-aaaaaaaaaaaa-new".into(),
            "operator-data".into(),
        ],
        ..Fake::default()
    };
    let finish = request(Operation::FinishSeed {
        fingerprint: "a".repeat(64),
    });
    assert_eq!(
        change(&mut host, &finish).unwrap().name,
        "orbit-topology-seed-aaaaaaaaaaaa"
    );
    change(&mut host, &finish).unwrap();
    let remove = request(Operation::Remove {
        name: "orbit-topology-seed-aaaaaaaaaaaa".into(),
    });
    change(&mut host, &remove).unwrap();
    change(&mut host, &remove).unwrap();
    assert_eq!(host.names, vec!["operator-data"]);
    assert_eq!(host.calls.iter().filter(|a| a[4] == "delete").count(), 1);
    assert_eq!(host.calls.iter().filter(|a| a[4] == "rename").count(), 1);
}
#[test]
fn checks_observed_state_after_failed_copy_rename_and_remove() {
    for (input, existing) in [
        (clone_request(), "orbit-topology-seed-aaaaaaaaaaaa"),
        (
            request(Operation::FinishSeed {
                fingerprint: "a".repeat(64),
            }),
            "orbit-topology-seed-aaaaaaaaaaaa-new",
        ),
        (
            request(Operation::Remove {
                name: "tvm-7-topology-seed".into(),
            }),
            "tvm-7-topology-seed",
        ),
    ] {
        for changed_despite_failure in [false, true] {
            let mut host = Fake {
                names: vec![existing.into()],
                fail: true,
                changed_despite_failure,
                ..Fake::default()
            };
            let result = change(&mut host, &input);
            if changed_despite_failure {
                assert!(result.is_ok());
            } else {
                assert!(matches!(result, Err(Error::Failed { .. })));
            }
            assert_eq!(host.calls.len(), 3);
        }
    }
}
#[test]
fn invalid_volume_intent_refuses_before_observation_or_mutation() {
    for mut input in [
        clone_request(),
        request(Operation::FinishSeed {
            fingerprint: "x".into(),
        }),
        request(Operation::Remove {
            name: "../data".into(),
        }),
    ] {
        if matches!(input.operation, Operation::CloneSeed { .. }) {
            input.location.project = "--project".into();
        }
        let mut host = Fake::default();
        assert_eq!(change(&mut host, &input), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
    assert!(serde_json::from_value::<Input>(json!({"location":{"project":"p","pool":"p"},"operation":{"kind":"remove","name":"x","force":true}})).is_err());
}
