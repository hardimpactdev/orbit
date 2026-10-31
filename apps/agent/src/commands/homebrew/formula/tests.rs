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
