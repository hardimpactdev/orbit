use super::*;
use crate::commands::recipe::{Output, WorkDir};
use std::{collections::VecDeque, time::Duration};
#[derive(Default)]
struct Fake {
    replies: VecDeque<Output>,
    calls: Vec<Invocation>,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        Ok(self.replies.pop_front().expect("unexpected command"))
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected retry")
    }
}
fn output(code: i32, text: &str) -> Output {
    Output {
        code,
        stdout: text.into(),
        ..Output::default()
    }
}
fn input(source: &str) -> Input {
    serde_json::from_value(serde_json::json!({"user":"orbit", "snapshot":"20261022T000000Z", "architecture":"amd64", "tools":{"curl":{"source":source,"version":"8.0","package":"curl","artifacts":{}}}})).unwrap()
}

#[test]
fn ubuntu_packages_have_floors_and_unproved_newer_packages_never_plan_a_downgrade() {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    let input = input("ubuntu");
    for (version, replies, status, changed, refusal) in [
        (None, vec![], Status::Missing, true, None),
        (Some("8.0"), vec![], Status::Current, false, None),
        (Some("7.0"), vec![output(1, "")], Status::OlderThanRecipe, true, None),
        (Some("9.0"), vec![output(0, ""), output(0,""), output(0,"amd64"), output(0,"curl:\n *** 9.0 100\n        100 /var/lib/dpkg/status\n"), output(0,"amd64")], Status::UnsupportedNewer, false, Some("origin:curl:dpkg-log-missing")),
        (Some("9.0"), vec![output(0,""), output(0,""), output(0,"amd64"), output(0,"curl:\n *** 9.0 500\n        500 https://foreign.test/ubuntu resolute/main amd64 Packages\n")], Status::UnsupportedNewer, false, Some("origin:curl"))] {
        let mut host = Fake { replies: replies.into(), ..Fake::default() };
        let versions = [("curl".into(), version.map(str::to_string))].into();
        let report = assess(&mut host, &files, &input, &versions, None, &mut Snapshots::default()).unwrap();
        let tool = &report["curl"]; assert_eq!(tool.status, status); assert_eq!(tool.changed, changed); assert_eq!(tool.refusal.as_deref(), refusal);
        assert_eq!(supported(&report).is_ok(), refusal.is_none()); assert!(host.replies.is_empty());
    }
}

#[test]
fn newer_official_ubuntu_package_stays_and_exact_artifacts_change() {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    let index = "http://archive.ubuntu.com/ubuntu resolute/main amd64 Packages";
    let mut host = Fake {
        replies: [
            output(0, ""),
            output(
                0,
                &format!(" 500 {index}\n     release o=Ubuntu,a=resolute,n=resolute\n"),
            ),
            output(0, "amd64"),
            output(0, &format!("curl:\n *** 9.0 500\n        500 {index}\n")),
        ]
        .into(),
        ..Fake::default()
    };
    let versions = [("curl".into(), Some("9.0".into()))].into();
    let report = assess(
        &mut host,
        &files,
        &input("ubuntu"),
        &versions,
        None,
        &mut Snapshots::default(),
    )
    .unwrap();
    assert_eq!(report["curl"].status, Status::NewerThanRecipe);
    assert!(!report["curl"].changed);
    assert_eq!(newer(&report)["curl"]["installed"], "9.0");
    let report = assess(
        &mut Fake::default(),
        &files,
        &input("artifact"),
        &versions,
        None,
        &mut Snapshots::default(),
    )
    .unwrap();
    assert_eq!(report["curl"].status, Status::Different);
    assert!(report["curl"].changed);
}

#[test]
fn an_inventory_comparison_failure_is_not_an_upgrade_plan() {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    let mut host = Fake {
        replies: [output(2, "")].into(),
        ..Fake::default()
    };
    let versions = [("curl".into(), Some("9.0".into()))].into();
    assert_eq!(
        assess(
            &mut host,
            &files,
            &input("ubuntu"),
            &versions,
            None,
            &mut Snapshots::default()
        )
        .unwrap_err()
        .stage,
        "inventory:curl"
    );
}

#[test]
fn platform_validation_refuses_untrusted_identity_wrong_architecture_and_docker_ce_before_changes()
{
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    std::fs::create_dir(files.path("/etc")).unwrap();
    for (release, stage) in [
        (
            "ID=ubuntu\nVERSION_CODENAME=resolute\nID=other\n",
            "platform:unknown:unknown",
        ),
        (
            "ID=$(touch /tmp/never)\nVERSION_CODENAME=resolute\n",
            "platform:unknown:unknown",
        ),
        ("ID=other\nVERSION_CODENAME=other\n", "platform:other:other"),
    ] {
        std::fs::write(files.path("/etc/os-release"), release).unwrap();
        let mut host = Fake::default();
        assert_eq!(
            platform(&mut host, &files, &input("ubuntu"))
                .unwrap_err()
                .stage,
            stage
        );
        assert!(host.calls.is_empty());
    }
    std::fs::write(
        files.path("/etc/os-release"),
        "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    )
    .unwrap();
    let mut host = Fake {
        replies: [output(0, "arm64")].into(),
        ..Fake::default()
    };
    assert_eq!(
        platform(&mut host, &files, &input("ubuntu"))
            .unwrap_err()
            .stage,
        "architecture"
    );
    assert_eq!(host.calls.len(), 1);
    let mut host = Fake {
        replies: [
            output(0, "amd64"),
            output(0, "amd64"),
            output(0, "docker-ce\tamd64\t1.0\tinstall ok installed\n"),
        ]
        .into(),
        ..Fake::default()
    };
    assert_eq!(
        platform(&mut host, &files, &input("ubuntu"))
            .unwrap_err()
            .stage,
        "docker-provider:docker-ce"
    );
    assert_eq!(host.calls.len(), 3);
}

#[test]
fn invalid_recipe_pins_refuse_before_any_host_observation() {
    let files = Files {
        root: "/nonexistent-recipe-test".into(),
        uid: 0,
        gid: 0,
    };
    for (field, value) in [
        ("user", "root"),
        ("user", "--help"),
        ("snapshot", "20261022;touch"),
        ("artifact_mirror", "http://mirror.test"),
    ] {
        let mut payload = serde_json::json!({"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}});
        payload[field] = value.into();
        let input: Input = serde_json::from_value(payload).unwrap();
        let mut host = Fake::default();
        assert_eq!(
            platform(&mut host, &files, &input).unwrap_err().stage,
            "input"
        );
        assert!(host.calls.is_empty());
    }
    let mut payload = input("ubuntu");
    payload.tools.get_mut("curl").unwrap().version = "8.0 --remove".into();
    let mut host = Fake::default();
    assert_eq!(
        platform(&mut host, &files, &payload).unwrap_err().stage,
        "input"
    );
    assert!(host.calls.is_empty());
}
