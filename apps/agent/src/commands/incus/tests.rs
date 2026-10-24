use super::*;
use serde_json::json;
use std::collections::VecDeque;

#[derive(Default)]
pub(super) struct Fake {
    pub(super) answers: VecDeque<Output>,
    pub(super) calls: Vec<Vec<String>>,
}
impl Host for Fake {
    fn run(&mut self, args: &[&str], _input: Option<&str>) -> Output {
        self.calls.push(args.iter().map(|s| (*s).into()).collect());
        self.answers.pop_front().expect("unexpected Incus call")
    }
}
pub(super) fn output(text: &str) -> Output {
    Output {
        code: 0,
        stdout: text.into(),
        stderr: String::new(),
        truncated: false,
    }
}
pub(super) fn fake(texts: &[&str]) -> Fake {
    Fake {
        answers: texts.iter().map(|s| output(s)).collect(),
        ..Fake::default()
    }
}
fn project_input() -> Project {
    Project {
        project: "orbit-tasks".into(),
    }
}

#[test]
fn image_inventory_reads_exact_fingerprints_and_aliases_and_repeats() {
    let pin = "a".repeat(64);
    let text =
        json!([{"fingerprint":pin,"aliases":[{"name":"base"},{"name":"dated"}]}]).to_string();
    let mut host = fake(&[&text, &text]);
    let first = images(&mut host, &project_input()).unwrap();
    assert_eq!(
        first.images,
        BTreeMap::from([(pin, vec!["base".into(), "dated".into()])])
    );
    assert_eq!(images(&mut host, &project_input()).unwrap(), first);
    assert_eq!(
        host.calls,
        vec![
            vec![
                "--project",
                "orbit-tasks",
                "image",
                "list",
                "--format",
                "json"
            ];
            2
        ]
    );
    assert!(images(&mut fake(&["[]"]), &project_input())
        .unwrap()
        .images
        .is_empty());
}

#[test]
fn rejects_invalid_incus_input_before_any_host_call() {
    for name in ["", "--all-projects", "p/name", "name\n", &"a".repeat(64)] {
        let mut host = Fake::default();
        let input = Project {
            project: name.into(),
        };
        assert_eq!(images(&mut host, &input), Err(Error::Invalid {}));
        assert_eq!(instances(&mut host, &input), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
    assert!(serde_json::from_value::<Project>(json!({"project":"p","argv":[]})).is_err());
    assert!(serde_json::from_value::<Empty>(json!({"path":"/1.0"})).is_err());
    assert_eq!(ReadInventory::privilege(Platform::Macos), None);
}

#[test]
fn malformed_or_incomplete_images_refuse_instead_of_returning_an_empty_inventory() {
    for text in [
        "",
        "error",
        "{}",
        "[{}]",
        "[{\"fingerprint\":\"short\",\"aliases\":[]}]",
        "[{\"fingerprint\":42,\"aliases\":[]}]",
        &json!([{"fingerprint":"a".repeat(64),"aliases":{}}]).to_string(),
        &json!([{"fingerprint":"a".repeat(64),"aliases":[{"description":"no name"}]}]).to_string(),
    ] {
        assert!(matches!(
            images(&mut fake(&[text]), &project_input()),
            Err(Error::Output { .. })
        ));
    }
    let mut host = fake(&["[]"]);
    host.answers[0].truncated = true;
    assert!(matches!(
        images(&mut host, &project_input()),
        Err(Error::Output { .. })
    ));
}

#[test]
fn instance_inventory_requires_a_project_and_accepts_empty_and_repeated_reads() {
    let mut host = fake(&[
        "name: orbit-tasks",
        "tvm-1\ntvm-12\n",
        "name: orbit-tasks",
        "tvm-1\ntvm-12\n",
        "name: orbit-tasks",
        "",
    ]);
    let first = instances(&mut host, &project_input()).unwrap();
    assert_eq!(first.names, vec!["tvm-1", "tvm-12"]);
    assert_eq!(instances(&mut host, &project_input()).unwrap(), first);
    assert!(instances(&mut host, &project_input())
        .unwrap()
        .names
        .is_empty());
    assert_eq!(
        host.calls[0],
        vec![
            "--project",
            "orbit-tasks",
            "project",
            "show",
            "--",
            "orbit-tasks"
        ]
    );
    assert_eq!(
        host.calls[1],
        vec![
            "--project",
            "orbit-tasks",
            "list",
            "--format",
            "csv",
            "--columns",
            "n"
        ]
    );
    for text in ["tvm-1\n--flag\n", "a/b", "tvm-1,Running", "tvm-1\rtvm-2"] {
        assert!(matches!(
            instances(&mut fake(&["project", text]), &project_input()),
            Err(Error::Output { .. })
        ));
    }
}

#[test]
fn failure_keeps_bounded_diagnostics_and_does_not_continue_after_missing_project() {
    let mut host = fake(&[""]);
    host.answers[0].code = 1;
    host.answers[0].stderr = format!("ignored\n{}", "x".repeat(5000));
    let error = instances(&mut host, &project_input()).unwrap_err();
    assert_eq!(
        error,
        Error::Failed {
            operation: "project show".into(),
            exit_code: 1,
            stderr: "ignored\n".to_owned() + &"x".repeat(1992)
        }
    );
    assert_eq!(host.calls.len(), 1);
    let mut host = fake(&["project", ""]);
    host.answers[1].code = 7;
    host.answers[1].stderr = "a\nb\nc\nd\ne\nf\ng\n".into();
    assert_eq!(
        instances(&mut host, &project_input()),
        Err(Error::Failed {
            operation: "list".into(),
            exit_code: 7,
            stderr: "c\nd\ne\nf\ng".into()
        })
    );
}

#[test]
fn host_inventory_includes_default_and_foreign_projects_and_all_pools() {
    let texts = [
        "[\"/1.0/instances/local\",\"/1.0/instances/tvm-1?project=orbit-tasks\"]",
        "[\"/1.0/storage-pools/default\",\"/1.0/storage-pools/task%5Fpool\"]",
        "[\"/1.0/storage-pools/default/volumes/custom/data\"]",
        "[\"/1.0/storage-pools/task_pool/volumes/custom/tvm-1-topology-seed?project=orbit-tasks\"]",
    ];
    let mut host = fake(&[texts, texts].concat());
    let first = inventory(&mut host).unwrap();
    assert_eq!(
        serde_json::to_value(&first).unwrap(),
        json!({"instances":[{"project":"default","name":"local"},{"project":"orbit-tasks","name":"tvm-1"}], "volumes":[{"project":"default","pool":"default","name":"data"},{"project":"orbit-tasks","pool":"task_pool","name":"tvm-1-topology-seed"}]})
    );
    assert_eq!(inventory(&mut host).unwrap(), first);
    assert_eq!(
        host.calls[3],
        vec![
            "query",
            "--",
            "/1.0/storage-pools/task_pool/volumes/custom?all-projects=true"
        ]
    );
    assert_eq!(
        inventory(&mut fake(&["[]", "[]"])).unwrap(),
        Inventory {
            instances: vec![],
            volumes: vec![]
        }
    );
}

#[test]
fn malformed_host_urls_and_failed_queries_refuse_instead_of_hiding_resources() {
    for text in [
        "{}",
        "[42]",
        "[\"/1.0/instances/\"]",
        "[\"/other/tvm-1\"]",
        "[\"/1.0/instances/a%2Fb\"]",
        "[\"/1.0/instances/a?project=\"]",
        "[\"/1.0/instances/a?project\"]",
        "[\"/1.0/instances/a?project%5B%5D=p\"]",
        "[\"/1.0/instances/a%ZZ\"]",
    ] {
        assert!(matches!(
            inventory(&mut fake(&[text])),
            Err(Error::Output { .. })
        ));
    }
    let mut host = fake(&["[]"]);
    host.answers[0].code = 1;
    assert!(matches!(inventory(&mut host), Err(Error::Failed { .. })));
    assert_eq!(host.calls.len(), 1);
    let mut host = fake(&["[]", "[]"]);
    host.answers[1].truncated = true;
    assert!(matches!(inventory(&mut host), Err(Error::Output { .. })));
}

#[test]
fn native_capture_bounds_memory_rejects_invalid_utf8_and_stops_at_deadline() {
    let large =
        capture(Process::new("/usr/bin/head").args(["-c", "2097152", "/dev/zero"])).unwrap();
    assert!(large.truncated);
    assert_eq!(large.stdout.len(), MAX_OUTPUT + 1);
    let invalid = capture(Process::new("/usr/bin/printf").arg("\\377")).unwrap();
    assert!(invalid.truncated);
    let bounded = capture(Process::new("/usr/bin/timeout").args([
        "--kill-after=1",
        "0.01s",
        "/usr/bin/sleep",
        "60",
    ]))
    .unwrap();
    assert_eq!(bounded.code, 124);
    let context = Context::new(Platform::Linux, std::time::Instant::now());
    assert_eq!(Native(&context).run(&["query"], None).code, -1);
}
