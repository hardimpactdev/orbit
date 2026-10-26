use super::*;
use std::{collections::VecDeque, time::Instant};

struct Fake {
    outputs: VecDeque<Output>,
    calls: Vec<(String, Vec<String>)>,
}
impl Programs for Fake {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output {
        self.calls.push((
            program.into(),
            arguments.iter().map(|s| (*s).into()).collect(),
        ));
        self.outputs
            .pop_front()
            .expect("an unexpected host observation")
    }
}
struct Fixture {
    root: PathBuf,
    input: Input,
    programs: Fake,
    waits: Vec<Duration>,
}
impl Fixture {
    fn new(runtime: Runtime, outputs: Vec<Output>) -> Self {
        let root = std::env::temp_dir().join(format!(
            "orbit-process-doctor-{:016x}",
            rand::random::<u64>()
        ));
        fs::create_dir(&root).unwrap();
        let name = if runtime == Runtime::Systemd {
            "orbit-process-7-web.service"
        } else {
            "orbit-process-7-web"
        };
        fs::write(
            root.join(name),
            "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/false\n",
        )
        .unwrap();
        Self {
            root,
            input: Input {
                process_id: 7,
                runtime,
                name: name.into(),
                desired: Desired::Running,
            },
            programs: Fake {
                outputs: outputs.into(),
                calls: vec![],
            },
            waits: vec![],
        }
    }
    fn inspect(&mut self, seconds: u64) -> Result<Observed, Error> {
        inspect(
            &self.input,
            &self.root,
            &mut self.programs,
            &Context::new(
                Platform::Linux,
                Instant::now() + Duration::from_secs(seconds),
            ),
            &mut |duration| self.waits.push(duration),
        )
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
fn output(text: &str) -> Output {
    Output {
        stdout: text.into(),
        ..Output::default()
    }
}
fn sample(count: &str) -> Output {
    output(&format!(
        "ActiveState=active\nSubState=running\nNRestarts={count}\n"
    ))
}

#[test]
fn gathers_restart_evidence_without_mutation_in_one_bounded_inspection() {
    for (before, after) in [
        ("0", "0"),
        ("26000", "26000"),
        ("3", "4"),
        ("26000", "0"),
        ("", ""),
    ] {
        let mut fixture = Fixture::new(Runtime::Systemd, vec![sample(before), sample(after)]);
        let unchanged = fs::read(fixture.root.join(&fixture.input.name)).unwrap();
        let result = fixture.inspect(10).unwrap();
        assert!(result.present);
        assert_eq!(result.status, Some(Status::Active));
        assert_eq!(result.systemd_observations[0].restarts, before.parse().ok());
        assert_eq!(result.systemd_observations[1].restarts, after.parse().ok());
        assert_eq!(fixture.waits, [Duration::from_secs(2)]);
        assert_eq!(fixture.programs.calls.len(), 2);
        assert!(fixture.programs.calls.iter().all(|(program, args)| program
            == "/usr/bin/systemctl"
            && args
                == &[
                    "show",
                    "--property=ActiveState,SubState,NRestarts",
                    "--",
                    "orbit-process-7-web.service"
                ]));
        assert_eq!(
            fs::read(fixture.root.join(&fixture.input.name)).unwrap(),
            unchanged
        );
        fixture.programs.outputs = vec![sample(before), sample(after)].into();
        assert_eq!(fixture.inspect(10).unwrap(), result);
    }
}

#[test]
fn an_auto_restart_or_stopped_intent_needs_no_second_sample() {
    let mut fixture = Fixture::new(
        Runtime::Systemd,
        vec![output(
            "ActiveState=activating\nSubState=auto-restart\nNRestarts=3\n",
        )],
    );
    let result = fixture.inspect(10).unwrap();
    assert_eq!(result.status, Some(Status::Other));
    assert!(result.systemd_observations[0].auto_restart());
    assert!(fixture.waits.is_empty());
    fixture.input.desired = Desired::Stopped;
    fixture
        .programs
        .outputs
        .push_back(output("SubState=dead\nActiveState=inactive\n"));
    let result = fixture.inspect(10).unwrap();
    assert_eq!(result.status, Some(Status::Inactive));
    assert_eq!(result.systemd_observations[0].restarts, None);
    assert!(fixture.waits.is_empty());
}

#[test]
fn missing_runtime_differs_from_unreadable_or_foreign_runtime() {
    let mut fixture = Fixture::new(Runtime::Systemd, vec![]);
    let path = fixture.root.join(&fixture.input.name);
    fs::remove_file(&path).unwrap();
    assert_eq!(fixture.inspect(10), Ok(Observed::absent()));
    fs::write(&path, "X-Orbit-Process-ID=8\n").unwrap();
    assert_eq!(fixture.inspect(10), Err(Error::Unavailable {}));
    fs::remove_file(&path).unwrap();
    std::os::unix::fs::symlink(&fixture.input.name, &path).unwrap();
    assert_eq!(fixture.inspect(10), Err(Error::Unavailable {}));
    assert!(fixture.programs.calls.is_empty());
    fixture.input.name = "orbit-process-8-web.service".into();
    assert_eq!(fixture.inspect(10), Err(Error::Invalid {}));
}

#[test]
fn insufficient_time_refuses_before_waiting_and_a_failed_second_sample_is_unavailable() {
    let mut fixture = Fixture::new(Runtime::Systemd, vec![sample("0")]);
    assert_eq!(fixture.inspect(1), Err(Error::Unavailable {}));
    assert!(fixture.waits.is_empty());
    fixture.programs.outputs = vec![
        sample("0"),
        Output {
            exit_code: 1,
            stderr: "private failure".into(),
            ..Output::default()
        },
    ]
    .into();
    assert_eq!(fixture.inspect(10), Err(Error::Unavailable {}));
    assert_eq!(fixture.waits, [Duration::from_secs(2)]);
}

#[test]
fn refuses_malformed_or_unbounded_systemd_evidence() {
    for text in [
        "ActiveState=secret\nSubState=running\nNRestarts=0\n",
        "ActiveState=active\nSubState=secret\nNRestarts=0\n",
        "ActiveState=active\nSubState=running\nNRestarts=-1\n",
        "ActiveState=active\nSubState=running\nNRestarts=4294967296\n",
        "ActiveState=active\nSubState=running\nNRestarts=secret\n",
        "ActiveState=active\nSubState=running\nNRestarts=01\n",
        "ActiveState=active\nSubState=running\nNRestarts=+1\n",
        "ActiveState=active\nActiveState=active\nSubState=running\n",
        "ActiveState=active\nNRestarts=0\n",
        "ActiveState=active\nSubState=running\nNRestarts=0",
        "ActiveState=active\nSubState=running\nExtra=private\n",
    ] {
        assert_eq!(
            observation(output(text)),
            Err(Error::Unavailable {}),
            "{text}"
        );
    }
    let mut result = sample("0");
    result.stderr = "private output".into();
    assert_eq!(observation(result), Err(Error::Unavailable {}));
    assert!(observation(output("NRestarts=\nSubState=running\nActiveState=active\n")).is_ok());
}

#[test]
fn docker_checks_ownership_and_bounds_status_in_one_read() {
    for (state, expected) in [
        ("running", Status::Running),
        ("created", Status::Created),
        ("exited", Status::Exited),
        ("paused", Status::Other),
        ("restarting", Status::Other),
        ("removing", Status::Other),
        ("dead", Status::Other),
    ] {
        let mut fixture = Fixture::new(
            Runtime::Docker,
            vec![output(&format!("true\nprocess\n7\n{state}\n"))],
        );
        assert_eq!(
            fixture.inspect(10).unwrap(),
            Observed {
                present: true,
                status: Some(expected),
                systemd_observations: vec![]
            }
        );
        assert_eq!(fixture.programs.calls.len(), 1);
        assert!(fixture.waits.is_empty());
    }
    for text in [
        "false\nprocess\n7\nrunning\n",
        "true\nother\n7\nrunning\n",
        "true\nprocess\n8\nrunning\n",
        "true\nprocess\n7\nsecret\n",
        "true\nprocess\n7\nrunning",
        "true\nprocess\n7\nrunning\nextra\n",
    ] {
        let mut fixture = Fixture::new(Runtime::Docker, vec![output(text)]);
        assert_eq!(fixture.inspect(10), Err(Error::Unavailable {}));
    }
    for error in [
        "No such object",
        "No such container",
        "Cannot connect to daemon",
    ] {
        let mut fixture = Fixture::new(
            Runtime::Docker,
            vec![Output {
                exit_code: 1,
                stdout: String::new(),
                stderr: error.into(),
            }],
        );
        if error.starts_with("No such") {
            assert_eq!(fixture.inspect(10), Ok(Observed::absent()));
        } else {
            assert_eq!(fixture.inspect(10), Err(Error::Unavailable {}));
        }
    }
}
