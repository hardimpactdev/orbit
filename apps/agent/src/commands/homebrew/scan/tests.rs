use super::*;
use recipe::{Failure, Host, Invocation, Output as Reply};
use serde_json::json;
use std::collections::VecDeque;
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    replies: VecDeque<Reply>,
}
impl Fake {
    fn new(replies: Vec<Reply>) -> Self {
        Self {
            replies: replies.into(),
            ..Default::default()
        }
    }
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Reply, Failure> {
        self.calls.push(call.clone());
        Ok(self.replies.pop_front().expect("unexpected command"))
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), Failure> {
        panic!("no retry")
    }
}
fn scope() -> homebrew_scope::Output {
    homebrew_scope::Output {
        prefix: "/opt/homebrew".into(),
        home: "/Users/mini".into(),
    }
}
fn text(value: &str) -> Reply {
    Reply {
        stdout: value.into(),
        ..Default::default()
    }
}
fn formula(name: &str) -> Value {
    let sha = "a".repeat(64);
    json!({"name":name,"full_name":name,"tap":"homebrew/core","disabled":false,"versions":{"stable":"9.9.9","bottle":true},"bottle":{"stable":{"files":{"arm64_golden_gate":{"sha256":sha,"url":format!("https://ghcr.io/v2/homebrew/core/{name}/blobs/sha256:{sha}")}}}},"installed":[{"version":"1.2","installed_on_request":true}]})
}
fn cask() -> Value {
    json!({"token":"font-hack","full_token":"font-hack","tap":"homebrew/cask","url":"https://example.com/font.zip","version":"2.0","installed":"1.0","disabled":false,"sha256":"a".repeat(64),"artifacts":[{"font":["Hack.ttf"]}]})
}
fn metadata(formulae: Vec<Value>, casks: Vec<Value>) -> Reply {
    text(&json!({"formulae":formulae,"casks":casks}).to_string())
}
#[test]
fn native_scan_preserves_blocks_versions_dependencies_and_names_omitted_by_info() {
    let mut dependency = formula("openssl@3");
    dependency["installed"][0]["installed_on_request"] = json!(false);
    let mut foreign = formula("hello");
    foreign["tap"] = json!("other/tap");
    let mut disabled = formula("btop");
    disabled["disabled"] = json!(true);
    let mut bottle = formula("wget");
    bottle["bottle"]["stable"]["files"] = json!({});
    let mut unreadable = formula("jq");
    unreadable["installed"][0]["version"] = json!("latest");
    let mut ambiguous = formula("multi");
    ambiguous["installed"]
        .as_array_mut()
        .unwrap()
        .push(json!({"version":"2.0"}));
    let formulae = vec![
        formula("ripgrep"),
        dependency,
        foreign,
        disabled,
        bottle,
        unreadable,
        ambiguous,
        formula("wireguard-go"),
    ];
    let mut replies = vec![];
    for _ in 0..2 {
        replies.extend([text("27.0"),metadata(formulae.clone(),vec![]),text("ripgrep\nopenssl@3\nhello\nbtop\nwget\njq\nmulti\nwireguard-go\nomitted\nwireguard-tools\n"),metadata(vec![],vec![cask()]),text("font-hack\nomitted-cask\n")]);
    }
    let mut host = Fake::new(replies);
    for _ in 0..2 {
        let output = scan(
            &mut host,
            &scope(),
            Platform::Macos,
            BottleInput {
                architecture: Some(Architecture::Arm64),
            },
        )
        .unwrap();
        assert_eq!(output.formulae.state, State::Complete);
        assert_eq!(output.casks.state, State::Complete);
        let observations: Vec<_> = output
            .formulae
            .packages
            .iter()
            .map(|p| {
                (
                    p.name.as_str(),
                    p.block,
                    p.dependency,
                    p.installed_version.as_deref(),
                )
            })
            .collect();
        assert_eq!(
            observations,
            vec![
                ("btop", Some(Block::UnsupportedArtifact), false, Some("1.2")),
                ("hello", Some(Block::UnsupportedSource), false, Some("1.2")),
                ("jq", Some(Block::VersionUnreadable), false, None),
                ("multi", Some(Block::VersionUnreadable), false, None),
                ("omitted", Some(Block::UnsupportedSource), false, None),
                ("openssl@3", Some(Block::Dependency), true, Some("1.2")),
                ("ripgrep", None, false, Some("1.2")),
                ("wget", Some(Block::BottleUnavailable), false, Some("1.2")),
                ("wireguard-go", Some(Block::Protected), false, Some("1.2")),
                ("wireguard-tools", Some(Block::Protected), false, None),
            ]
        );
        assert_eq!(
            output.casks.packages[1].block,
            Some(Block::UnsupportedSource)
        );
        assert!(!output.casks.packages[1].dependency);
    }
    assert_eq!(host.calls.len(), 10);
    for call in host.calls {
        assert!(!call
            .environment
            .iter()
            .any(|(key, _)| key == "HOMEBREW_FORCE_API_AUTO_UPDATE"));
        assert!(!call
            .argv
            .iter()
            .any(|v| ["install", "upgrade", "uninstall"].contains(&v.as_str())));
    }
}
#[test]
fn failed_formula_scan_keeps_successful_casks_and_linux_never_reads_casks() {
    let mut host = Fake::new(vec![
        text("27.0"),
        Reply {
            code: 1,
            ..Default::default()
        },
        metadata(vec![], vec![cask()]),
        text("font-hack\n"),
    ]);
    let output = scan(
        &mut host,
        &scope(),
        Platform::Macos,
        BottleInput {
            architecture: Some(Architecture::Arm64),
        },
    )
    .unwrap();
    assert_eq!(output.formulae.state, State::Incomplete);
    assert!(output.formulae.packages.is_empty());
    assert_eq!(output.casks.state, State::Complete);
    let mut host = Fake::new(vec![text("x86_64"), metadata(vec![], vec![]), text("")]);
    let output = scan(
        &mut host,
        &scope(),
        Platform::Linux,
        BottleInput { architecture: None },
    )
    .unwrap();
    assert_eq!(output.formulae.state, State::Complete);
    assert_eq!(output.casks.state, State::Unsupported);
    assert_eq!(host.calls.len(), 3);
    let mut host = Fake::new(vec![
        text("27.0"),
        metadata(vec![], vec![]),
        text(""),
        Reply {
            code: 1,
            ..Default::default()
        },
    ]);
    let output = scan(
        &mut host,
        &scope(),
        Platform::Macos,
        BottleInput {
            architecture: Some(Architecture::Arm64),
        },
    )
    .unwrap();
    assert_eq!(output.formulae.state, State::Complete);
    assert_eq!(output.casks.state, State::Incomplete);
}
#[test]
fn name_list_disagreement_duplicates_or_invalid_names_make_inventory_incomplete() {
    for names in [
        "",
        "jq\njq\n",
        "ripgrep\ninvalid/name\n",
        "ripgrep\n\njq",
        " ripgrep\n",
    ] {
        let mut host = Fake::new(vec![
            metadata(vec![formula("ripgrep")], vec![]),
            text(names),
        ]);
        assert_eq!(
            inventory(&mut host, &scope(), "arm64_golden_gate", true),
            Err(Error::Output {})
        );
    }
    assert_eq!(
        names("jq\r\nripgrep\r\n")
            .unwrap()
            .into_iter()
            .collect::<Vec<_>>(),
        ["jq", "ripgrep"]
    );
}
#[test]
fn malformed_and_deep_metadata_refuses_before_name_listing() {
    let mut bad_keg = formula("jq");
    bad_keg["installed"][0]["installed_on_request"] = json!("true");
    let mut no_kegs = formula("jq");
    no_kegs["installed"] = json!([]);
    let mut invalid_name = formula("../bad");
    invalid_name["disabled"] = json!(false);
    for reply in [
        text("{"),
        text("{} {}"),
        text("[]"),
        metadata(vec![formula("jq"), formula("jq")], vec![]),
        metadata(vec![bad_keg], vec![]),
        metadata(vec![no_kegs], vec![]),
        metadata(vec![invalid_name], vec![]),
        Reply {
            truncated: true,
            ..metadata(vec![], vec![])
        },
        text(&format!(
            "{{\"formulae\":[],\"casks\":[],\"ignored\":{}0{}}}",
            "[".repeat(64),
            "]".repeat(64)
        )),
    ] {
        let mut host = Fake::new(vec![reply]);
        assert!(inventory(&mut host, &scope(), "arm64_golden_gate", true).is_err());
        assert_eq!(host.calls.len(), 1);
    }
}
