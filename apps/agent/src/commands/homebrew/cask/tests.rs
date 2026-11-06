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
