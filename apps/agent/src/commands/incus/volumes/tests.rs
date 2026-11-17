use super::super::Output;
use super::*;
use serde_json::json;

#[derive(Default)]
struct Fake {
    names: Vec<String>,
    invalid: bool,
}
impl Host for Fake {
    fn run(&mut self, args: &[&str], _input: Option<&str>) -> Output {
        assert_eq!(args[4], "list");
        Output {
            code: 0,
            stdout: if self.invalid {
                "[{}]".into()
            } else {
                json!(self
                    .names
                    .iter()
                    .map(|n| json!({"name":n,"type":"custom"}))
                    .chain(std::iter::once(
                        json!({"name":"unrelated","type":"virtual-machine"})
                    ))
                    .collect::<Vec<_>>())
                .to_string()
            },
            stderr: String::new(),
            truncated: false,
        }
    }
}
fn pool() -> Pool {
    Pool {
        project: "orbit-tasks".into(),
        pool: "tank".into(),
    }
}

#[test]
fn reads_only_custom_volumes_and_refuses_incomplete_inventory() {
    let mut host = Fake {
        names: vec!["data".into()],
        ..Fake::default()
    };
    assert_eq!(names(&mut host, &pool()).unwrap(), vec!["data"]);
    assert_eq!(names(&mut host, &pool()).unwrap(), vec!["data"]);
    host.invalid = true;
    assert!(matches!(
        names(&mut host, &pool()),
        Err(Error::Output { .. })
    ));
}
