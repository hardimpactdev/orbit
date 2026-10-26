use super::*;
use serde_json::json;
use std::collections::VecDeque;
struct Fake {
    answers: VecDeque<incus::Output>,
    calls: Vec<Vec<String>>,
}
fn output(text: &str) -> incus::Output {
    incus::Output {
        code: 0,
        stdout: text.into(),
        stderr: String::new(),
        truncated: false,
    }
}
impl Fake {
    fn new(answers: Vec<incus::Output>) -> Self {
        Self {
            answers: answers.into(),
            calls: vec![],
        }
    }
}
impl Host for Fake {
    fn run(&mut self, args: &[&str]) -> Result<incus::Output> {
        self.calls
            .push(args.iter().map(|s| s.to_string()).collect());
        Ok(self
            .answers
            .pop_front()
            .expect("Unexpected Composer invocation"))
    }
}
fn input() -> CandidateInput {
    CandidateInput {
        package: "laravel/installer".into(),
        operation: CandidateOperation::Install,
    }
}
#[test]
fn refuses_invalid_coordinates_and_operations_before_execution() {
    for package in [
        "laravel/installer",
        "a/b",
        "vendor_name/package.name",
        "v/p-1",
    ] {
        assert!(valid_package(package));
    }
    for package in [
        "",
        "Vendor/pkg",
        "vendor/pkg@1",
        "vendor/pkg:*",
        "https://example/pkg",
        "../pkg",
        "-x/pkg",
        "vendor/*",
        "vendor/pkg\n",
        &format!("{}/{}", "v".repeat(128), "p".repeat(127)),
    ] {
        let mut host = Fake::new(vec![]);
        assert_eq!(
            candidate(
                &mut host,
                CandidateInput {
                    package: package.into(),
                    ..input()
                }
            ),
            Err(Error::Invalid {})
        );
        for operation in ["require", "update", "remove"] {
            assert_eq!(
                mutate(&mut host, package, operation),
                Err(Error::Invalid {})
            );
        }
        assert_eq!(plan(&mut host, package), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
    for value in [
        json!({"package":"v/p","operation":"remove"}),
        json!({"package":"v/p","operation":"install","scope":"/tmp/x"}),
    ] {
        assert!(serde_json::from_value::<CandidateInput>(value).is_err());
    }
}
#[test]
fn preserves_fixed_global_arguments_on_repeat() {
    let mut host = Fake::new((0..8).map(|_| output("")).collect());
    for _ in 0..2 {
        mutate(&mut host, "vendor/package", "require").unwrap();
        mutate(&mut host, "vendor/package", "update").unwrap();
        plan(&mut host, "vendor/package").unwrap();
        mutate(&mut host, "vendor/package", "remove").unwrap();
    }
    assert_eq!(
        host.calls[0],
        [
            "global",
            "require",
            "vendor/package:*",
            "--no-interaction",
            "--no-ansi",
            "--no-progress",
            "--no-audit",
            "--with-all-dependencies"
        ]
    );
    assert_eq!(host.calls[1][..3], ["global", "update", "vendor/package"]);
    assert_eq!(
        host.calls[2][..4],
        ["global", "remove", "vendor/package", "--dry-run"]
    );
    assert_eq!(host.calls[3][..3], ["global", "remove", "vendor/package"]);
    assert_eq!(host.calls[..4], host.calls[4..]);
}
#[test]
fn reads_manager_first_line_and_refuses_nonzero_truncated_or_oversized_output() {
    let mut host = Fake::new(vec![output("Composer version 2.9.0\nextra")]);
    assert_eq!(
        version(&mut host).unwrap().version,
        "Composer version 2.9.0"
    );
    assert_eq!(host.calls, [vec!["--version", "--no-ansi"]]);
    for text in ["", "Composer\0secret", &"a".repeat(256)] {
        assert_eq!(
            version(&mut Fake::new(vec![output(text)])),
            Err(Error::Output {})
        );
    }
    assert_eq!(
        version(&mut Fake::new(vec![incus::Output {
            code: 1,
            ..output("private")
        }])),
        Err(Error::Failed {})
    );
    for result in [
        incus::Output {
            truncated: true,
            ..output("[]")
        },
        output(&"x".repeat(MAX_STREAM + 1)),
        incus::Output {
            stderr: "x".repeat(MAX_STREAM + 1),
            ..output("")
        },
    ] {
        assert_eq!(
            mutate(&mut Fake::new(vec![result]), "v/p", "require"),
            Err(Error::Output {})
        );
    }
}
#[test]
fn inventory_preserves_versions_and_duplicates_and_isolates_unverifiable_entries() {
    let document = json!({"installed":[{"name":"laravel/installer","version":"v5.17.0"},{"name":"laravel/installer","version":"v5.18.0"},{"name":"v/bad","version":3},{"name":"v/control","version":"1.0\n"},{"name":"v/spaces","version":"dev main"},{"name":3},{"name":"Invalid/name","version":"1"},{}]});
    let mut host = Fake::new(vec![output(&document.to_string())]);
    let result = inventory(&mut host).unwrap();
    assert_eq!(result.packages.len(), 5);
    assert_eq!(result.packages[0].version.as_deref(), Some("v5.17.0"));
    assert_eq!(result.packages[1].version.as_deref(), Some("v5.18.0"));
    assert!(result.packages[2..].iter().all(|p| p.version.is_none()));
    assert_eq!(
        host.calls,
        [vec!["global", "show", "--format=json", "--no-ansi"]]
    );
    for empty in ["[]", r#"{"installed":[]}"#] {
        assert!(inventory(&mut Fake::new(vec![output(empty)]))
            .unwrap()
            .packages
            .is_empty());
    }
    for text in [
        "{}",
        "null",
        "[{}]",
        r#"{"installed":{}}"#,
        r#"{"installed":[null]}"#,
        r#"{"installed":[[]]}"#,
        r#"{"installed":["v/p"]}"#,
        r#"{"installed":[]}{}"#,
    ] {
        assert_eq!(
            inventory(&mut Fake::new(vec![output(text)])),
            Err(Error::Output {})
        );
    }
}
#[test]
fn candidate_uses_exact_target_destination_and_only_matching_lock_install_pairs() {
    for (text, expected) in [
        ("  - Locking laravel/installer (v5.17.0)", "v5.17.0"),
        ("  - Installing laravel/installer (v5.17.0)", "v5.17.0"),
        (
            "  - Upgrading laravel/installer (v5.16.0 => v5.17.0)",
            "v5.17.0",
        ),
        (
            "  - Downgrading laravel/installer (v5.17.0 => v5.16.0)",
            "v5.16.0",
        ),
        (
            "  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)",
            "v5.17.0",
        ),
    ] {
        let mut host = Fake::new(vec![incus::Output {
            stderr: text.into(),
            ..output("")
        }]);
        assert_eq!(
            candidate(&mut host, input()).unwrap().version.as_deref(),
            Some(expected)
        );
        assert_eq!(host.calls.len(), 1);
        assert_eq!(
            host.calls[0][..4],
            ["global", "require", "laravel/installer:*", "--dry-run"]
        );
    }
}
#[test]
fn no_op_requires_one_verified_exact_installed_package_in_the_same_call() {
    let no_op = "Nothing to install, update or remove";
    let installed = r#"{"installed":[{"name":"laravel/installer","version":"v5.17.0"}]}"#;
    let mut host = Fake::new(vec![output(no_op), output(installed)]);
    assert_eq!(
        candidate(
            &mut host,
            CandidateInput {
                operation: CandidateOperation::Update,
                ..input()
            }
        )
        .unwrap()
        .version
        .as_deref(),
        Some("v5.17.0")
    );
    assert_eq!(host.calls.len(), 2);
    assert_eq!(
        host.calls[0][..4],
        ["global", "update", "laravel/installer", "--dry-run"]
    );
    assert_eq!(
        host.calls[1],
        ["global", "show", "--format=json", "--no-ansi"]
    );
    for list in [
        json!([]),
        json!([{"name":"laravel/installer","version":""}]),
        json!([{"name":"laravel/installer","version":"v1\0private"}]),
        json!([{"name":"laravel/installer","version":"1".repeat(256)}]),
        json!([{"name":"other/installer","version":"v5.17.0"}]),
        json!([{"name":"laravel/installer","version":null}]),
        json!([{"name":"laravel/installer","version":"v1"},{"name":"laravel/installer","version":"v1"}]),
    ] {
        let mut host = Fake::new(vec![
            output(no_op),
            output(&json!({"installed":list}).to_string()),
        ]);
        assert_eq!(candidate(&mut host, input()), Err(Error::Output {}));
    }
}
#[test]
fn ambiguous_dry_runs_never_read_inventory_or_return_a_candidate() {
    for text in [
        "  - Installing other/installer (v5.17.0)",
        "  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)",
        "  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)\n  - Removing laravel/installer (v5.16.0)",
        "  - Locking laravel/installer (v5.17.0)\n  - Locking laravel/installer (v5.17.0)",
        "  - Installing laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.17.0)",
        "  - Locking laravel/installer (v5.17.0)\n  - Installing laravel/installer (v5.18.0)",
        "  - Installing laravel/installer (v5.17.0)\nNothing to install, update or remove",
        "  - Installing other/installer (v1)\nNothing to install, update or remove",
        "  - Removing other/installer (v1)\nNothing to install, update or remove",
        "  - Installing laravel/installer v5.17.0\nNothing to install, update or remove",
        "Nothing to install, update or remove\nNothing to install, update or remove",
        "Loading composer repositories with package information",
        "",
        "  - Installing laravel/installer (v5.17.0\0secret)",
        &format!("  - Installing laravel/installer ({})", "1".repeat(256)),
        &"x".repeat(MAX_CANDIDATE + 1),
    ] {
        let mut host = Fake::new(vec![output(text)]);
        assert_eq!(
            candidate(&mut host, input()),
            Err(Error::Output {}),
            "{text:.80}"
        );
        assert_eq!(host.calls.len(), 1);
    }
}
#[test]
fn only_the_bounded_exact_missing_package_diagnostic_reports_absence() {
    let message="Could not find a matching version of package laravel/installer. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (stable).";
    let mut host = Fake::new(vec![incus::Output {
        code: 2,
        stderr: message.into(),
        ..output("")
    }]);
    assert_eq!(candidate(&mut host, input()).unwrap().version, None);
    for text in [
        "secret",
        &message.replace("laravel/installer", "laravel/framework"),
        &format!("{}{message}", "x".repeat(MAX_CANDIDATE)),
    ] {
        assert!(!missing(text, "laravel/installer"));
    }
    assert_eq!(
        candidate(
            &mut Fake::new(vec![incus::Output {
                code: 2,
                ..output("private")
            }]),
            input()
        ),
        Err(Error::Failed {})
    );
}

#[test]
fn inventory_keeps_the_existing_json_depth_bound_and_skips_deep_metadata() {
    for (depth, valid) in [(508, true), (509, false)] {
        let text = format!(
            r#"{{"installed":[{{"name":"v/p","version":"1.0","metadata":{}0{}}}]}}"#,
            "[".repeat(depth),
            "]".repeat(depth)
        );
        assert_eq!(
            inventory(&mut Fake::new(vec![output(&text)])).is_ok(),
            valid
        );
    }
}
