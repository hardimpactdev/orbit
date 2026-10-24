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
fn missing() -> Output {
    Output{code:1,stderr:"Cask 'homebrew/cask/font-hack' is unavailable. This command requires the tap homebrew/cask.".into(),..Default::default()}
}
#[test]
fn complete_lifecycle_uses_fixed_official_arguments_and_no_zap() {
    for operation in [Operation::Install, Operation::Update, Operation::Remove] {
        let mut replies = vec![];
        for _ in 0..2 {
            replies.extend([text("27.0.1"), reply(vec![cask()]), Output::default()]);
        }
        let mut host = Fake::new(replies);
        for _ in 0..2 {
            assert_eq!(
                mutate(&mut host, &scope(), &input(), operation),
                Ok(Done {})
            );
        }
        assert_eq!(host.calls.len(), 6);
        let verb = match operation {
            Operation::Install => "install",
            Operation::Update => "upgrade",
            Operation::Remove => "uninstall",
        };
        for call in [&host.calls[2], &host.calls[5]] {
            assert_eq!(
                call.argv,
                [
                    "/opt/homebrew/bin/brew",
                    verb,
                    "--cask",
                    "homebrew/cask/font-hack"
                ]
            );
            assert_eq!(
                call.environment
                    .contains(&("HOMEBREW_FORCE_API_AUTO_UPDATE".into(), "1".into())),
                !matches!(operation, Operation::Remove)
            );
        }
    }
}
#[test]
fn unsupported_metadata_refuses_before_mutation() {
    for (key, value, error) in [
        (
            "artifacts",
            json!([{"preflight":null},{"font":["Hack.ttf"]}]),
            Error::CaskArtifact {},
        ),
        ("disabled", json!(true), Error::CaskArtifact {}),
        (
            "artifacts",
            json!([{"app":["Hack.app"],"target":"/Applications/Hack.app"}]),
            Error::CaskAuthorization {},
        ),
        (
            "artifacts",
            json!([{"pkg":["Hack.pkg"]}]),
            Error::CaskAuthorization {},
        ),
        (
            "artifacts",
            json!([{"font":["Hack.ttf"]},{"uninstall":[{"pkgutil":"example.font"}]}]),
            Error::CaskAuthorization {},
        ),
        (
            "artifacts",
            json!([{"font":["Hack.ttf"]},{"uninstall":[{"script":"secret code"}]}]),
            Error::CaskArtifact {},
        ),
        ("artifacts", json!([]), Error::CaskArtifact {}),
        ("artifacts", json!([null]), Error::CaskArtifact {}),
        ("sha256", json!("no_check"), Error::CaskChecksum {}),
        ("sha256", json!("A".repeat(64)), Error::CaskChecksum {}),
        ("url", json!("file:///tmp/font.zip"), Error::CaskSource {}),
        ("tap", json!("other/cask"), Error::CaskSource {}),
        (
            "full_token",
            json!("homebrew/cask/font-hack"),
            Error::CaskSource {},
        ),
        ("version", json!("latest"), Error::CaskVersion {}),
        ("version", json!("3.003 secret"), Error::CaskVersion {}),
    ] {
        for operation in [Operation::Install, Operation::Update] {
            let mut value_cask = cask();
            value_cask[key] = value.clone();
            let mut host = Fake::new(vec![text("27.0.1"), reply(vec![value_cask])]);
            assert_eq!(
                mutate(&mut host, &scope(), &input(), operation).unwrap_err(),
                error,
                "{key}"
            );
            assert_eq!(host.calls.len(), 2);
        }
    }
}
#[test]
fn variation_uses_only_current_os_and_cpu_and_never_runs_zap() {
    let mut value = cask();
    value["sha256"] = json!("no_check");
    value["artifacts"]
        .as_array_mut()
        .unwrap()
        .push(json!({"zap":[{"pkgutil":"ignored","delete":"/Library/Privileged"}]}));
    value["variations"] = json!({"golden_gate":{"sha256":"b".repeat(64),"version":"9.1.0"}});
    let mut host = Fake::new(vec![text("27.0"), reply(vec![value.clone()])]);
    assert_eq!(
        candidate(&mut host, &scope(), &input()),
        Err(Error::CaskChecksum {})
    );
    let intel = Input {
        architecture: Architecture::X86_64,
        ..input()
    };
    let mut host = Fake::new(vec![text("27.0"), reply(vec![value.clone()])]);
    assert_eq!(
        candidate(&mut host, &scope(), &intel)
            .unwrap()
            .version
            .as_deref(),
        Some("9.1.0")
    );
    value["variations"]["golden_gate"]["artifacts"] =
        json!([{"font":["Hack.ttf"]},{"uninstall":[{"pkgutil":"example.font"}]}]);
    let mut host = Fake::new(vec![text("27.0"), reply(vec![value])]);
    assert_eq!(
        mutate(&mut host, &scope(), &intel, Operation::Remove),
        Err(Error::CaskAuthorization {})
    );
    assert_eq!(host.calls.len(), 2);
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
fn removal_ignores_install_gates_but_checks_delisted_origin_and_uninstall_safety() {
    let mut value = cask();
    value["disabled"] = json!(true);
    value["sha256"] = json!("no_check");
    value["version"] = json!("latest");
    for delisted in [false, true] {
        let mut replies = vec![text("27.0")];
        if delisted {
            replies.push(missing());
        }
        replies.extend([reply(vec![value.clone()]), Output::default()]);
        let mut host = Fake::new(replies);
        assert_eq!(
            mutate(&mut host, &scope(), &input(), Operation::Remove),
            Ok(Done {})
        );
        assert_eq!(
            host.calls.last().unwrap().argv.last().unwrap(),
            if delisted {
                "font-hack"
            } else {
                "homebrew/cask/font-hack"
            }
        );
    }
    value["tap"] = json!("other/cask");
    let mut host = Fake::new(vec![text("27.0"), missing(), reply(vec![value])]);
    assert_eq!(
        mutate(&mut host, &scope(), &input(), Operation::Remove),
        Err(Error::CaskSource {})
    );
    assert_eq!(host.calls.len(), 3);
    for artifacts in [
        json!([{"uninstall":[{"script":"arbitrary"}]}]),
        json!([null]),
        Value::Null,
    ] {
        let mut value = cask();
        value["artifacts"] = artifacts;
        let mut host = Fake::new(vec![text("27.0"), reply(vec![value])]);
        assert!(mutate(&mut host, &scope(), &input(), Operation::Remove).is_err());
        assert_eq!(host.calls.len(), 2);
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
#[test]
fn unknown_fields_bad_names_and_linux_are_rejected_without_execution() {
    for package in [
        "",
        "homebrew/cask/font-hack",
        "--zap",
        "./font.rb",
        "https://example.com/cask",
    ] {
        let mut host = Fake::default();
        let input = Input {
            package: package.into(),
            ..input()
        };
        assert_eq!(
            mutate(&mut host, &scope(), &input, Operation::Install),
            Err(Error::Invalid {})
        );
        assert!(host.calls.is_empty());
    }
    for value in [
        json!({"package":"font-hack","architecture":"i386"}),
        json!({"package":"font-hack","architecture":"arm64","options":["--zap"]}),
        json!({"package":"font-hack"}),
    ] {
        assert!(serde_json::from_value::<Input>(value).is_err());
    }
    assert_eq!(Install::privilege(Platform::Linux), None);
    assert_eq!(
        Install::privilege(Platform::Macos),
        Some(Privilege::Account)
    );
    assert_eq!(Install::target(&input()).as_deref(), Some("tools:homebrew"));
}
#[test]
fn missing_is_distinct_from_failed_malformed_and_truncated_metadata() {
    for output in [
        missing(),
        Output {
            code: 1,
            stderr: "Cask 'font-hack' is unavailable: No Cask with this name exists".into(),
            ..Default::default()
        },
    ] {
        let mut host = Fake::new(vec![text("27.0"), output]);
        assert_eq!(
            candidate(&mut host, &scope(), &input()),
            Ok(CandidateOutput { version: None })
        );
    }
    for output in [
        text("{"),
        text("[]"),
        reply(vec![]),
        reply(vec![cask(), cask()]),
        Output {
            truncated: true,
            ..reply(vec![cask()])
        },
        Output {
            code: 1,
            stderr: "secret error".into(),
            ..Default::default()
        },
    ] {
        let mut host = Fake::new(vec![text("27.0"), output]);
        let error = mutate(&mut host, &scope(), &input(), Operation::Install).unwrap_err();
        assert!(!format!("{error:?}").contains("secret"));
        assert_eq!(host.calls.len(), 2);
    }
}
