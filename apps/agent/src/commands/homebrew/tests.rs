use super::*;
use recipe::{Failure, Host, Invocation, Output};
use std::path::PathBuf;

#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    output: Output,
    fail: bool,
}
impl Fake {
    fn text(text: &str) -> Self {
        Self {
            output: Output {
                stdout: text.into(),
                ..Default::default()
            },
            ..Default::default()
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, Failure> {
        self.calls.push(call.clone());
        if self.fail {
            return Err(Failure::new("test", "unavailable"));
        }
        Ok(self.output.clone())
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), Failure> {
        panic!("No retries")
    }
}
fn scope() -> homebrew_scope::Output {
    homebrew_scope::Output {
        prefix: PathBuf::from("/opt/homebrew"),
        home: PathBuf::from("/Users/mini"),
    }
}

#[test]
fn brew_environment_is_fixed_and_does_not_use_gnu_env_or_a_shell() {
    let call = brew_invocation(
        Path::new("/usr/local"),
        Path::new("/Users/mini"),
        false,
        &["list", "--versions", "--formula"],
    )
    .unwrap();
    assert_eq!(
        call.argv,
        ["/usr/local/bin/brew", "list", "--versions", "--formula"]
    );
    assert_eq!(
        call.environment,
        vec![
            ("HOME".into(), "/Users/mini".into()),
            ("PATH".into(), "/usr/local/bin:/usr/bin:/bin".into()),
            ("HOMEBREW_NO_AUTO_UPDATE".into(), "1".into()),
            ("HOMEBREW_NO_ANALYTICS".into(), "1".into()),
            ("HOMEBREW_NO_ENV_HINTS".into(), "1".into()),
            ("HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK".into(), "1".into()),
            ("HOMEBREW_NO_INSTALL_CLEANUP".into(), "1".into()),
        ]
    );
}

#[test]
fn inventory_keeps_ambiguity_and_unreadable_packages_separate_from_absence() {
    let mut host = Fake::text("jq 1.7.1\ngh 2.88.1\nambiguous 1.0 2.0\nduplicate 1.0\nduplicate 1.0\nbad\nbad 1.0\nspaces  1.0\nother/tap/tool 2.0\n7 1.0\nlibsigc++ 3.0\n");
    for _ in 0..2 {
        let data = formula_inventory(&mut host, &scope()).unwrap();
        assert_eq!(
            serde_json::to_value(&data).unwrap(),
            serde_json::json!({"packages": [
                {"name":"7","versions":["1.0"],"unverifiable":false},
                {"name":"ambiguous","versions":["1.0","2.0"],"unverifiable":false},
                {"name":"bad","versions":["1.0"],"unverifiable":true},
                {"name":"duplicate","versions":["1.0","1.0"],"unverifiable":false},
                {"name":"gh","versions":["2.88.1"],"unverifiable":false},
                {"name":"jq","versions":["1.7.1"],"unverifiable":false},
                {"name":"libsigc++","versions":["3.0"],"unverifiable":false},
                {"name":"spaces","versions":[],"unverifiable":true}
            ]})
        );
    }
    for call in host.calls {
        assert_eq!(
            call.argv,
            ["/opt/homebrew/bin/brew", "list", "--versions", "--formula"]
        );
    }
    assert!(formula_inventory(&mut Fake::default(), &scope())
        .unwrap()
        .packages
        .is_empty());
    for invalid in ["a-+", "-option", "other/tap/tool", "Uppercase", "tool#ref"] {
        assert!(!valid_package(invalid), "{invalid}");
    }
}

#[test]
fn failed_truncated_or_oversized_reads_never_become_empty_inventory() {
    for (output, error) in [
        (
            Output {
                code: 1,
                ..Default::default()
            },
            Error::Failed {},
        ),
        (
            Output {
                truncated: true,
                ..Default::default()
            },
            Error::Output {},
        ),
        (
            Output {
                stdout: "a".repeat(1_048_577),
                ..Default::default()
            },
            Error::Output {},
        ),
        (
            Output {
                stderr: "a".repeat(1_048_577),
                ..Default::default()
            },
            Error::Output {},
        ),
    ] {
        assert_eq!(
            formula_inventory(
                &mut Fake {
                    output,
                    ..Default::default()
                },
                &scope()
            ),
            Err(error)
        );
    }
    assert_eq!(
        formula_inventory(
            &mut Fake {
                fail: true,
                ..Default::default()
            },
            &scope()
        ),
        Err(Error::Failed {})
    );
    assert!(serde_json::from_str::<Empty>(r#"{"arguments":["install","tool"]}"#).is_err());
}
