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
        Ok(self.replies.pop_front().expect("unexpected command"))
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
        package: "font-hack".into(),
        architecture: Architecture::Arm64,
    }
}
fn text(value: &str) -> Output {
    Output {
        stdout: value.into(),
        ..Default::default()
    }
}
fn cask() -> Value {
    json!({"token":"font-hack","full_token":"font-hack","tap":"homebrew/cask","url":"https://example.com/font-hack.zip","version":"3.003","installed":"3.002","sha256":"a".repeat(64),"disabled":false,"variations":{},"artifacts":[{"font":["Hack.ttf"],"target":"/$HOME/Library/Fonts/Hack.ttf"}]})
}
fn reply(casks: Vec<Value>) -> Output {
    text(&json!({"formulae":[],"casks":casks}).to_string())
}
#[test]
fn artifact_paths_use_native_home_prefix_and_component_boundaries() {
    for target in [
        "/Users/mini/Library/Fonts/Hack.ttf",
        "/$HOME/Library/Fonts/Hack.ttf",
        "~/Library/Fonts/Hack.ttf",
        "$HOME/Library/Fonts/Hack.ttf",
        "$HOMEBREW_PREFIX/bin/hack",
        "/opt/homebrew/bin/hack",
        "Hack.ttf",
    ] {
        let mut value = cask();
        value["artifacts"][0]["target"] = json!(target);
        assert!(assess(&value, "font-hack", &scope()).is_ok(), "{target}");
    }
    for target in [
        "/Users/other/Library/Fonts/Hack.ttf",
        "/Users/mini-other/Library/Fonts/Hack.ttf",
        "/Library/Fonts/Hack.ttf",
        "/Applications/Hack.app",
        "$APPDIR/Hack.app",
    ] {
        let mut value = cask();
        value["artifacts"][0]["target"] = json!(target);
        assert_eq!(
            assess(&value, "font-hack", &scope()),
            Err(Error::CaskAuthorization {}),
            "{target}"
        );
    }
    for target in [
        "../Hack.ttf",
        "relative/path",
        "/Users/mini/../other/Hack.ttf",
        "bad\u{0000}target",
    ] {
        let mut value = cask();
        value["artifacts"][0]["target"] = json!(target);
        assert_eq!(
            assess(&value, "font-hack", &scope()),
            Err(Error::CaskArtifact {}),
            "{target}"
        );
    }
    for (trash, allowed) in [
        (json!("/Users/mini/Library/Fonts/Hack.ttf"), true),
        (json!(["~/Library/Fonts/Hack.ttf"]), true),
        (json!("/Library/Fonts/Hack.ttf"), false),
        (json!([false]), false),
    ] {
        let mut value = cask();
        value["artifacts"]
            .as_array_mut()
            .unwrap()
            .push(json!({"uninstall":[{"trash":trash}]}));
        assert_eq!(assess(&value, "font-hack", &scope()).is_ok(), allowed);
    }
}
#[test]
fn inventory_preserves_duplicate_and_unreadable_versions_but_skips_foreign_taps() {
    let mut unknown = cask();
    unknown["token"] = json!("bad");
    unknown["installed"] = json!("latest");
    let mut foreign = cask();
    foreign["tap"] = json!("other/cask");
    let mut invalid = cask();
    invalid["token"] = json!("../bad");
    let mut host = Fake::new(vec![reply(vec![cask(), cask(), unknown, foreign, invalid])]);
    assert_eq!(
        serde_json::to_value(inventory(&mut host, &scope()).unwrap()).unwrap(),
        json!({"packages":[
            {"name":"bad","versions":[],"unverifiable":true},
            {"name":"font-hack","versions":["3.002","3.002"],"unverifiable":false}
        ]})
    );
    assert_eq!(host.calls.len(), 1);
    for output in [
        reply(vec![Value::Null]),
        text("{}"),
        Output {
            code: 1,
            ..Default::default()
        },
        Output {
            truncated: true,
            ..reply(vec![cask()])
        },
    ] {
        assert!(inventory(&mut Fake::new(vec![output]), &scope()).is_err());
    }
}
#[test]
fn adoption_uses_installed_version_and_maps_assessment_blocks_without_refresh() {
    for (key, value, expected) in [
        ("disabled", json!(false), None),
        ("disabled", json!(true), Some(Block::UnsupportedArtifact)),
        (
            "artifacts",
            json!([{"app":["Hack.app"]}]),
            Some(Block::AuthorizationRequired),
        ),
        ("tap", json!("other/cask"), Some(Block::UnsupportedSource)),
        ("version", json!("latest"), Some(Block::VersionUnreadable)),
    ] {
        let mut metadata = cask();
        metadata[key] = value;
        let mut host = Fake::new(vec![
            reply(vec![cask()]),
            text("27.0"),
            reply(vec![metadata]),
        ]);
        assert_eq!(
            adoption(&mut host, &scope(), &input()),
            Ok(AdoptionOutput {
                installed_version: Some("3.002".into()),
                block: expected
            })
        );
        for call in host.calls {
            assert!(!call
                .environment
                .iter()
                .any(|(key, _)| key == "HOMEBREW_FORCE_API_AUTO_UPDATE"));
        }
    }
    let mut host = Fake::new(vec![reply(vec![])]);
    assert_eq!(
        adoption(&mut host, &scope(), &input()),
        Ok(AdoptionOutput {
            installed_version: None,
            block: None
        })
    );
    assert_eq!(host.calls.len(), 1);
}
#[test]
fn discoveries_preserve_unsupported_casks_and_refuse_duplicate_metadata() {
    let mut app = cask();
    app["token"] = json!("docker");
    app["full_token"] = json!("docker");
    app["artifacts"] = json!([{"app":["Docker.app"]}]);
    let mut invalid = cask();
    invalid["token"] = json!("../bad");
    let mut unreadable = cask();
    unreadable["token"] = json!("unknown");
    unreadable["full_token"] = json!("unknown");
    unreadable["installed"] = Value::Null;
    let data = json!({"formulae":[],"casks":[cask(),app,invalid,unreadable]});
    let packages = discoveries(&data, &scope(), "arm64_golden_gate").unwrap();
    assert_eq!(packages.len(), 3);
    assert_eq!(packages[0].block, None);
    assert_eq!(packages[1].block, Some(Block::AuthorizationRequired));
    assert_eq!(packages[2].block, Some(Block::VersionUnreadable));
    assert_eq!(
        discoveries(
            &json!({"formulae":[],"casks":[cask(),cask()]}),
            &scope(),
            "arm64_golden_gate"
        ),
        Err(Error::Output {})
    );
}
