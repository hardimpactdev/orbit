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
