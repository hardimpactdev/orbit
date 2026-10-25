use super::*;
use crate::commands::recipe::{Failure, Output, WorkDir};
use std::{fs, process::Command as Process, time::Instant};

fn git(path: &std::path::Path, args: &[&str]) -> String {
    let output = Process::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Orbit test",
            "-c",
            "user.email=orbit@example.test",
            "-C",
        ])
        .arg(path)
        .args(args)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().to_owned()
}

fn inspect(path: &std::path::Path) -> Result<Refs, Error> {
    ReadRefs::run(
        Input {
            checkout: path.to_owned(),
        },
        &Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60)),
    )
}

#[test]
fn reads_unborn_and_detached_checkouts_without_changing_them() {
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet", "-b", "main"]);
    let empty = inspect(&work.0).unwrap();
    assert_eq!(
        empty,
        Refs {
            haves: vec![],
            baseline: None,
            branch: Some("main".into()),
            head: None
        }
    );
    fs::write(work.0.join("file"), "content").unwrap();
    git(&work.0, &["add", "file"]);
    git(&work.0, &["commit", "--quiet", "-m", "first"]);
    let commit = git(&work.0, &["rev-parse", "HEAD"]);
    git(
        &work.0,
        &["update-ref", "refs/remotes/origin/main", &commit],
    );
    git(&work.0, &["checkout", "--quiet", "--detach"]);
    let config = fs::read(work.0.join(".git/config")).unwrap();
    let index = fs::read(work.0.join(".git/index")).unwrap();
    for _ in 0..2 {
        assert_eq!(
            inspect(&work.0).unwrap(),
            Refs {
                haves: vec![commit.clone()],
                baseline: None,
                branch: None,
                head: Some(commit.clone())
            }
        );
    }
    assert_eq!(fs::read(work.0.join(".git/config")).unwrap(), config);
    assert_eq!(fs::read(work.0.join(".git/index")).unwrap(), index);
    assert_eq!(fs::read_to_string(work.0.join("file")).unwrap(), "content");
}

#[test]
fn reads_each_worktrees_own_baseline_and_branch() {
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet", "-b", "main"]);
    git(
        &work.0,
        &["commit", "--allow-empty", "--quiet", "-m", "first"],
    );
    let first = git(&work.0, &["rev-parse", "HEAD"]);
    git(
        &work.0,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &first],
    );
    git(
        &work.0,
        &["commit", "--allow-empty", "--quiet", "-m", "second"],
    );
    let second = git(&work.0, &["rev-parse", "HEAD"]);
    let checkout = work.0.join("linked");
    git(
        &work.0,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "task-7",
            checkout.to_str().unwrap(),
        ],
    );
    git(
        &checkout,
        &["update-ref", "refs/worktree/orbit/ci-baseline", &second],
    );
    for _ in 0..2 {
        let primary = inspect(&work.0).unwrap();
        let linked = inspect(&checkout).unwrap();
        assert_eq!(primary.baseline.as_deref(), Some(first.as_str()));
        assert_eq!(primary.branch.as_deref(), Some("main"));
        assert_eq!(linked.baseline.as_deref(), Some(second.as_str()));
        assert_eq!(linked.branch.as_deref(), Some("task-7"));
        assert_eq!(linked.head.as_deref(), Some(second.as_str()));
    }
}

#[test]
fn rejects_non_repositories_and_invalid_input_without_changes() {
    let work = WorkDir::new().unwrap();
    assert!(matches!(inspect(&work.0), Err(Error::Unreadable {})));
    assert_eq!(fs::read_dir(&work.0).unwrap().count(), 0);
    assert!(matches!(
        inspect(std::path::Path::new("relative")),
        Err(Error::Invalid {})
    ));
    assert!(serde_json::from_value::<Input>(
        serde_json::json!({"checkout":"/tmp/repo", "program":"evil"})
    )
    .is_err());
}

struct Scripted {
    outputs: std::collections::VecDeque<Output>,
    calls: Vec<Invocation>,
}
impl Host for Scripted {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        Ok(self.outputs.pop_front().unwrap())
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no polling");
    }
}

#[test]
fn bounds_and_deduplicates_haves_and_accepts_sha256_refs() {
    let id = "b".repeat(64);
    let mut host = Scripted {
        calls: vec![],
        outputs: [
            Output {
                stdout: format!(
                    "{id}\n{id}\nmalformed\n{}",
                    (0..1100).map(|n| format!("{n:040x}\n")).collect::<String>()
                ),
                ..Output::default()
            },
            Output {
                stdout: id.clone(),
                ..Output::default()
            },
            Output {
                stdout: "main\n".into(),
                ..Output::default()
            },
            Output {
                stdout: id.clone(),
                ..Output::default()
            },
        ]
        .into(),
    };
    let result = read(
        &Input {
            checkout: "/tmp/repo".into(),
        },
        &mut host,
    )
    .unwrap();
    assert_eq!(result.haves.len(), MAX_HAVES);
    assert_eq!(result.haves[0], id);
    assert_eq!(result.baseline, Some(id.clone()));
    assert_eq!(result.head, Some(id));
    assert!(host.calls[0].argv.iter().any(|arg| arg == "--count=1000"));
    assert!(host.calls.iter().all(|call| call.argv[0] == "/usr/bin/git"));
}

#[test]
fn refuses_truncation_and_command_failure_instead_of_reporting_absence() {
    for output in [
        Output {
            truncated: true,
            ..Output::default()
        },
        Output {
            code: 128,
            ..Output::default()
        },
    ] {
        let mut host = Scripted {
            calls: vec![],
            outputs: [output].into(),
        };
        assert!(matches!(
            read(
                &Input {
                    checkout: "/tmp/repo".into()
                },
                &mut host
            ),
            Err(Error::Unreadable {})
        ));
        assert_eq!(host.calls.len(), 1);
    }
}
