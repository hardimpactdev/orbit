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
