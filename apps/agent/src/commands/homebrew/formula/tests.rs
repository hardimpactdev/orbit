use super::*;
use recipe::{Failure, Host, Invocation, Output};
use serde_json::json;
use std::collections::VecDeque;

#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    replies: VecDeque<Output>,
}
impl Fake {
    fn new(replies: Vec<Output>) -> Self {
        Self {
            replies: replies.into(),
            ..Default::default()
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, Failure> {
        self.calls.push(call.clone());
        Ok(self
            .replies
            .pop_front()
            .expect("unexpected Homebrew invocation"))
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), Failure> {
        panic!("No retry")
    }
}
fn scope() -> homebrew_scope::Output {
    homebrew_scope::Output {
        prefix: "/opt/homebrew".into(),
        home: "/Users/mini".into(),
    }
}
fn input() -> Input {
    Input {
        package: "ripgrep".into(),
        architecture: Some(Architecture::Arm64),
    }
}
fn text(value: &str) -> Output {
    Output {
        stdout: value.into(),
        ..Default::default()
    }
}
fn formula(tag: &str) -> Value {
    let sha = "a".repeat(64);
    json!({"name":"ripgrep", "full_name":"ripgrep", "tap":"homebrew/core", "disabled":false,
        "versions":{"stable":"14.1.1", "bottle":true},
        "bottle":{"stable":{"files":{tag:{"sha256":sha,"url":format!("https://ghcr.io/v2/homebrew/core/ripgrep/blobs/sha256:{sha}")}}}}})
}
fn metadata_reply(formula: Value) -> Output {
    text(&json!({"formulae":[formula],"casks":[]}).to_string())
}

#[test]
fn candidates_and_mutations_use_native_platform_tags_and_fixed_core_argv() {
    for (platform, probe, tag) in [
        (Platform::Linux, "x86_64", "x86_64_linux"),
        (Platform::Linux, "aarch64", "arm64_linux"),
        (Platform::Macos, "27.0.1", "arm64_golden_gate"),
    ] {
        for operation in [Operation::Install, Operation::Update, Operation::Remove] {
            let needs_bottle = !matches!(operation, Operation::Remove);
            let verb = match operation {
                Operation::Install => "install",
                Operation::Update => "upgrade",
                Operation::Remove => "uninstall",
            };
            let mut replies = vec![];
            for _ in 0..2 {
                if needs_bottle {
                    replies.extend([text(probe), metadata_reply(formula(tag))]);
                }
                replies.push(Output::default());
            }
            let mut host = Fake::new(replies);
            for _ in 0..2 {
                assert_eq!(
                    mutate(&mut host, &scope(), platform, &input(), operation),
                    Ok(Done {})
                );
            }
            let mut expected = vec!["/opt/homebrew/bin/brew", verb, "--formula"];
            if needs_bottle {
                expected.push("--force-bottle");
            }
            expected.push("homebrew/core/ripgrep");
            let mutations: Vec<_> = host
                .calls
                .iter()
                .filter(|c| c.argv.get(1).map(String::as_str) == Some(verb))
                .collect();
            assert_eq!(mutations.len(), 2);
            for call in mutations {
                assert_eq!(call.argv, expected);
                assert_eq!(call.timeout, Duration::from_secs(900));
                assert_eq!(
                    call.environment
                        .contains(&("HOMEBREW_FORCE_API_AUTO_UPDATE".into(), "1".into())),
                    needs_bottle && platform == Platform::Macos
                );
            }
            for call in host
                .calls
                .iter()
                .filter(|c| c.argv.get(1).map(String::as_str) == Some("info"))
            {
                assert_eq!(
                    call.argv,
                    [
                        "/opt/homebrew/bin/brew",
                        "info",
                        "--json=v2",
                        "--formula",
                        "homebrew/core/ripgrep"
                    ]
                );
            }
        }
    }
}

#[test]
fn candidate_returns_the_stable_version_and_accepts_all_only_when_tag_absent() {
    for tag in ["arm64_golden_gate", "all"] {
        let mut host = Fake::new(vec![text("27.1"), metadata_reply(formula(tag))]);
        assert_eq!(
            candidate(&mut host, &scope(), Platform::Macos, &input())
                .unwrap()
                .version
                .as_deref(),
            Some("14.1.1")
        );
    }
    let mut metadata = formula("all");
    metadata["bottle"]["stable"]["files"]["arm64_golden_gate"] = Value::Null;
    let mut host = Fake::new(vec![text("27.1"), metadata_reply(metadata)]);
    assert_eq!(
        mutate(
            &mut host,
            &scope(),
            Platform::Macos,
            &input(),
            Operation::Install
        ),
        Err(Error::Candidate {})
    );
    assert_eq!(host.calls.len(), 2);
}

#[test]
fn unverified_formulae_refuse_before_install_or_upgrade() {
    for (pointer, invalid) in [
        ("/name", json!("other")),
        ("/full_name", json!("other/tap/ripgrep")),
        ("/tap", json!("other/tap")),
        ("/disabled", json!(true)),
        ("/disabled", Value::Null),
        ("/versions/stable", Value::Null),
        ("/versions/stable", json!("bad version")),
        ("/versions/stable", json!("bad\u{0000}")),
        ("/versions/bottle", json!(false)),
        ("/bottle/stable/files/arm64_golden_gate/sha256", Value::Null),
        (
            "/bottle/stable/files/arm64_golden_gate/sha256",
            json!("A".repeat(64)),
        ),
        (
            "/bottle/stable/files/arm64_golden_gate/url",
            json!("https://example.com/bottle"),
        ),
        (
            "/bottle/stable/files/arm64_golden_gate/url",
            json!(format!(
                "https://ghcr.io/v2/homebrew/core/ripgrep/blobs/sha256:{}",
                "b".repeat(64)
            )),
        ),
    ] {
        for operation in [Operation::Install, Operation::Update] {
            let mut value = formula("arm64_golden_gate");
            *value.pointer_mut(pointer).unwrap() = invalid.clone();
            let mut host = Fake::new(vec![text("27.0.1"), metadata_reply(value)]);
            assert_eq!(
                mutate(&mut host, &scope(), Platform::Macos, &input(), operation),
                Err(Error::Candidate {}),
                "{pointer}"
            );
            assert_eq!(host.calls.len(), 2);
        }
    }
    let mut host = Fake::new(vec![
        text("27.0.1"),
        metadata_reply(formula("arm64_sequoia")),
    ]);
    assert_eq!(
        mutate(
            &mut host,
            &scope(),
            Platform::Macos,
            &input(),
            Operation::Install
        ),
        Err(Error::Candidate {})
    );
    assert_eq!(host.calls.len(), 2);
}

#[test]
fn invalid_or_protected_input_refuses_without_program_execution() {
    for name in [
        "",
        "--cask",
        "example/tap/tool",
        "https://example.com/tool.rb",
        "./tool.rb",
        "Tool",
        "bad name",
        "tool#ref",
        "wireguard-go",
        "wireguard-tools",
    ] {
        for operation in [Operation::Install, Operation::Update, Operation::Remove] {
            let mut host = Fake::default();
            let mut input = input();
            input.package = name.into();
            let expected = if name.starts_with("wireguard-") {
                Error::Protected {}
            } else {
                Error::Invalid {}
            };
            assert_eq!(
                mutate(&mut host, &scope(), Platform::Macos, &input, operation),
                Err(expected)
            );
            assert!(host.calls.is_empty());
        }
    }
    let mut host = Fake::default();
    let mut input = input();
    input.architecture = None;
    assert_eq!(
        candidate(&mut host, &scope(), Platform::Macos, &input),
        Err(Error::Invalid {})
    );
    assert!(host.calls.is_empty());
    for value in [
        json!({"package":"ripgrep", "options":["--zap"]}),
        json!({"package":"ripgrep", "architecture":"i386"}),
        json!({"package":"ripgrep", "prefix":"/tmp/brew"}),
    ] {
        assert!(serde_json::from_value::<Input>(value).is_err());
    }
}

#[test]
fn exact_missing_formula_is_absent_but_cannot_be_installed() {
    let missing = Output {
        code: 1,
        stderr: "Error: No available formula with the name \"homebrew/core/ripgrep\".\n".into(),
        ..Default::default()
    };
    let mut host = Fake::new(vec![text("x86_64"), missing.clone()]);
    assert_eq!(
        candidate(&mut host, &scope(), Platform::Linux, &input()),
        Ok(CandidateOutput { version: None })
    );
    let mut host = Fake::new(vec![text("x86_64"), missing]);
    assert_eq!(
        mutate(
            &mut host,
            &scope(),
            Platform::Linux,
            &input(),
            Operation::Install
        ),
        Err(Error::Candidate {})
    );
    assert_eq!(host.calls.len(), 2);
    for output in [
        Output {
            code: 1,
            stderr: "No available formula with the name \"homebrew/core/other\"".into(),
            ..Default::default()
        },
        Output {
            code: 8,
            stderr: "private metadata error".into(),
            ..Default::default()
        },
    ] {
        let mut host = Fake::new(vec![text("x86_64"), output]);
        assert_eq!(
            candidate(&mut host, &scope(), Platform::Linux, &input()),
            Err(Error::Failed {})
        );
    }
}

#[test]
fn malformed_or_truncated_metadata_never_reaches_mutation() {
    for output in [
        text("{"),
        text("[]"),
        text("{}"),
        text(&json!({"formulae":[],"casks":[]}).to_string()),
        text(&json!({"formulae":[formula("x86_64_linux")],"casks":[{}]}).to_string()),
        text(
            &json!({"formulae":[formula("x86_64_linux"),formula("x86_64_linux")],"casks":[]})
                .to_string(),
        ),
        Output {
            truncated: true,
            ..metadata_reply(formula("x86_64_linux"))
        },
        text(&"a".repeat(131_073)),
    ] {
        let mut host = Fake::new(vec![text("x86_64"), output]);
        assert_eq!(
            mutate(
                &mut host,
                &scope(),
                Platform::Linux,
                &input(),
                Operation::Install
            ),
            Err(Error::Output {})
        );
        assert_eq!(host.calls.len(), 2);
    }
}

#[test]
fn failed_mutation_has_one_attempt_and_no_raw_output() {
    let mut host = Fake::new(vec![Output {
        code: 1,
        stderr: "secret token".into(),
        ..Default::default()
    }]);
    let error = mutate(
        &mut host,
        &scope(),
        Platform::Macos,
        &input(),
        Operation::Remove,
    )
    .unwrap_err();
    assert_eq!(error, Error::Failed {});
    assert!(!format!("{error:?}").contains("secret"));
    assert_eq!(host.calls.len(), 1);
}

fn installed_formula() -> Value {
    let mut value = formula("arm64_golden_gate");
    value["installed"] = json!([{"version":"14.1.1", "installed_on_request":true}]);
    value
}
#[test]
fn adoption_reads_fresh_installed_versions_and_metadata_without_mutation() {
    for platform in [Platform::Linux, Platform::Macos] {
        let mut value = installed_formula();
        if platform == Platform::Linux {
            value["bottle"] = formula("x86_64_linux")["bottle"].clone();
        }
        let probe = if platform == Platform::Linux {
            "x86_64"
        } else {
            "27.0"
        };
        let mut replies = vec![];
        for _ in 0..2 {
            replies.extend([
                text("ripgrep 14.1.1\n"),
                text(probe),
                metadata_reply(value.clone()),
            ]);
        }
        let mut host = Fake::new(replies);
        for _ in 0..2 {
            assert_eq!(
                adoption(&mut host, &scope(), platform, &input()),
                Ok(AdoptionOutput {
                    installed_version: Some("14.1.1".into()),
                    block: None
                })
            );
        }
        assert_eq!(host.calls.len(), 6);
        for call in host.calls.iter().filter(|c| c.argv[0].ends_with("/brew")) {
            assert!(matches!(call.argv[1].as_str(), "info" | "list"));
            assert_eq!(
                call.environment
                    .contains(&("HOMEBREW_FORCE_API_AUTO_UPDATE".into(), "1".into())),
                platform == Platform::Macos && call.argv[1] == "info"
            );
        }
    }
    let mut host = Fake::new(vec![text("")]);
    assert_eq!(
        adoption(&mut host, &scope(), Platform::Macos, &input()),
        Ok(AdoptionOutput {
            installed_version: None,
            block: None
        })
    );
    assert_eq!(host.calls.len(), 1);
    for inventory in [
        "ripgrep 1.0 2.0\n",
        "ripgrep 1.0\nripgrep 1.0\n",
        "ripgrep\n",
    ] {
        let mut host = Fake::new(vec![text(inventory)]);
        assert_eq!(
            adoption(&mut host, &scope(), Platform::Macos, &input()),
            Err(Error::Output {})
        );
        assert_eq!(host.calls.len(), 1);
    }
}
#[test]
fn adoption_preserves_source_dependency_version_artifact_and_bottle_blocks() {
    for (pointer, value, block, version) in [
        (
            "/tap",
            json!("other/tap"),
            Block::UnsupportedSource,
            Some("14.1.1"),
        ),
        (
            "/full_name",
            json!("other/tap/ripgrep"),
            Block::UnsupportedSource,
            Some("14.1.1"),
        ),
        (
            "/installed/0/installed_on_request",
            json!(false),
            Block::Dependency,
            Some("14.1.1"),
        ),
        (
            "/installed/0/version",
            json!("latest"),
            Block::VersionUnreadable,
            None,
        ),
        (
            "/installed/0/version",
            Value::Null,
            Block::VersionUnreadable,
            None,
        ),
        (
            "/disabled",
            json!(true),
            Block::UnsupportedArtifact,
            Some("14.1.1"),
        ),
        (
            "/versions/bottle",
            json!(false),
            Block::BottleUnavailable,
            Some("14.1.1"),
        ),
    ] {
        let mut formula = installed_formula();
        *formula.pointer_mut(pointer).unwrap() = value;
        assert_eq!(
            adoption_fact(&formula, "ripgrep", "arm64_golden_gate", "14.1.1".into()),
            Ok(AdoptionOutput {
                installed_version: version.map(String::from),
                block: Some(block)
            })
        );
    }
    let mut formula = installed_formula();
    formula["name"] = json!("wireguard-go");
    formula["full_name"] = json!("wireguard-go");
    assert_eq!(
        adoption_fact(
            &formula,
            "wireguard-go",
            "arm64_golden_gate",
            "14.1.1".into()
        )
        .unwrap()
        .block,
        Some(Block::Protected)
    );
    let mut formula = installed_formula();
    formula["installed"] = json!([{"version":"14.1.1","installed_on_request":true},{"version":"14.0.0","installed_on_request":true}]);
    assert_eq!(
        adoption_fact(&formula, "ripgrep", "arm64_golden_gate", "14.1.1".into())
            .unwrap()
            .block,
        Some(Block::VersionUnreadable)
    );
}
#[test]
fn inconsistent_adoption_metadata_refuses_instead_of_claiming_absence() {
    for (pointer, value) in [
        ("/installed", json!([])),
        ("/installed", json!([null])),
        ("/installed/0/installed_on_request", json!("true")),
        ("/installed/0/version", json!("14.0.0")),
        ("/disabled", json!("false")),
    ] {
        let mut formula = installed_formula();
        *formula.pointer_mut(pointer).unwrap() = value;
        assert_eq!(
            adoption_fact(&formula, "ripgrep", "arm64_golden_gate", "14.1.1".into()),
            Err(Error::Output {})
        );
    }
}
