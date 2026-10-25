use super::*;
use crate::commands::recipe::{Failure, WorkDir};
use std::time::Duration;
use std::{os::unix::fs::symlink, time::Instant};
struct Fixture {
    _work: WorkDir,
    files: Files,
    host: FakeHost,
}
#[derive(Default)]
struct FakeHost {
    reloads: usize,
    fail_reload: bool,
    needs_reload: bool,
    observe: Option<Box<dyn Fn()>>,
}
impl Host for FakeHost {
    fn execute(&mut self, call: &Invocation) -> Result<recipe::Output, Failure> {
        match call.argv.get(1).map(String::as_str) {
            Some("daemon-reload") => {
                self.reloads += 1;
                if let Some(observe) = &self.observe {
                    observe();
                }
                if self.fail_reload {
                    self.fail_reload = false;
                    self.needs_reload = true;
                    return Ok(recipe::Output {
                        code: 1,
                        ..Default::default()
                    });
                }
                self.needs_reload = false;
                Ok(recipe::Output::default())
            }
            Some("show") => Ok(recipe::Output {
                stdout: if self.needs_reload { "yes\n" } else { "no\n" }.into(),
                ..Default::default()
            }),
            _ => panic!("unexpected call: {:?}", call.argv),
        }
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn hooks() -> Vec<HookEntry> {
    [
        ("test.first", "first-start", "orbit-first.conf"),
        ("test.second", "second-start", "orbit-second.conf"),
    ]
    .into_iter()
    .map(|(command, name, drop_in)| HookEntry {
        command,
        name,
        drop_in,
        description: "test startup gate",
        validate: |input| {
            if input.is_object() && input.get("invalid").is_none() {
                Ok(())
            } else {
                Err(Outcome::refused("test.invalid", "invalid", json!({})))
            }
        },
        run: |input, _| {
            if input.get("fail").is_some() {
                Outcome::refused("test.failed", "failed", json!({}))
            } else {
                if let Some(path) = input.get("marker").and_then(Value::as_str) {
                    fs::write(path, "ran").unwrap();
                }
                Outcome::Ok(json!({}))
            }
        },
    })
    .collect()
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::getuid().as_raw(),
            gid: rustix::process::getgid().as_raw(),
        };
        fs::create_dir_all(files.path("/etc/systemd/system")).unwrap();
        Self {
            _work: work,
            files,
            host: FakeHost::default(),
        }
    }
    fn run(&mut self, names: &[&str]) -> Result<Output, Error> {
        let input = Input {
            hooks: names
                .iter()
                .map(|name| HookInput {
                    command: format!("test.{name}"),
                    input: json!({}),
                })
                .collect(),
            run: false,
        };
        converge(&self.files, &mut self.host, &input, &hooks(), &context())
    }
}
fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(5))
}
#[test]
fn publishes_data_and_fixed_service_and_preserves_files_and_modes_on_repeat() {
    let mut f = Fixture::new();
    fs::create_dir(f.files.path(DIRECTORY)).unwrap();
    fs::set_permissions(f.files.path(DIRECTORY), fs::Permissions::from_mode(0o711)).unwrap();
    assert!(f.run(&["first"]).unwrap().changed);
    let config = f.files.path("/etc/orbit/first-start.json");
    let unit = f.files.path(&format!("{UNITS}/orbit-first.conf"));
    assert_eq!(fs::read_to_string(&config).unwrap(), "{}\n");
    assert_eq!(fs::metadata(&config).unwrap().mode() & 0o7777, 0o600);
    assert!(fs::read_to_string(&unit)
        .unwrap()
        .contains("ExecStartPre=+/usr/local/bin/orbit-agent service test.first\n"));
    let paths = [config, unit, f.files.path(MANIFEST)];
    let before: Vec<_> = paths
        .iter()
        .map(|p| {
            let m = fs::metadata(p).unwrap();
            (m.ino(), m.mtime(), m.mtime_nsec())
        })
        .collect();
    assert!(!f.run(&["first"]).unwrap().changed);
    assert_eq!(f.host.reloads, 1);
    let after: Vec<_> = paths
        .iter()
        .map(|p| {
            let m = fs::metadata(p).unwrap();
            (m.ino(), m.mtime(), m.mtime_nsec())
        })
        .collect();
    assert_eq!(before, after);
    assert_eq!(
        fs::metadata(f.files.path(DIRECTORY)).unwrap().mode() & 0o7777,
        0o711
    );
}
#[test]
fn retirement_reloads_before_removing_data_and_retries_after_failed_reload() {
    let mut f = Fixture::new();
    f.run(&["first", "second"]).unwrap();
    let config = f.files.path("/etc/orbit/first-start.json");
    let unit = f.files.path(&format!("{UNITS}/orbit-first.conf"));
    let observed = config.clone();
    f.host.observe = Some(Box::new(move || {
        assert!(observed.exists());
        assert!(!unit.exists());
    }));
    f.host.fail_reload = true;
    assert_eq!(f.run(&["second"]).unwrap_err(), failed("daemon-reload"));
    assert!(config.exists());
    assert!(fs::read_to_string(f.files.path(MANIFEST))
        .unwrap()
        .contains("first-start"));
    f.run(&["second"]).unwrap();
    assert!(!config.exists());
    assert_eq!(f.host.reloads, 3);
    f.host.observe = None;
    f.run(&[]).unwrap();
    assert_eq!(fs::read_to_string(f.files.path(MANIFEST)).unwrap(), "");
    assert!(!f.run(&[]).unwrap().changed);
}
#[test]
fn retires_previous_source_only_after_reload_and_preserves_unrecorded_files() {
    let mut f = Fixture::new();
    fs::create_dir(f.files.path(DIRECTORY)).unwrap();
    let old = f.files.path("/etc/orbit/first-start");
    fs::write(&old, "old source").unwrap();
    let other = f.files.path("/etc/orbit/unrelated-start");
    fs::write(&other, "keep").unwrap();
    let observed = old.clone();
    f.host.observe = Some(Box::new(move || {
        assert_eq!(fs::read_to_string(&observed).unwrap(), "old source")
    }));
    f.host.fail_reload = true;
    assert!(f.run(&["first"]).is_err());
    assert!(old.exists());
    f.run(&["first"]).unwrap();
    assert!(!old.exists());
    assert_eq!(fs::read_to_string(other).unwrap(), "keep");
}
#[test]
fn validates_all_contributions_before_creating_paths() {
    for input in [
        Input {
            hooks: vec![HookInput {
                command: "unknown.action".into(),
                input: json!({}),
            }],
            run: false,
        },
        Input {
            hooks: vec![
                HookInput {
                    command: "test.first".into(),
                    input: json!({}),
                },
                HookInput {
                    command: "test.second".into(),
                    input: json!({"invalid":true}),
                },
            ],
            run: false,
        },
        Input {
            hooks: vec![
                HookInput {
                    command: "test.first".into(),
                    input: json!({})
                };
                2
            ],
            run: false,
        },
    ] {
        let mut f = Fixture::new();
        assert!(matches!(
            converge(&f.files, &mut f.host, &input, &hooks(), &context()),
            Err(Error::Invalid { .. })
        ));
        assert!(!f.files.path(DIRECTORY).exists());
        assert_eq!(f.host.reloads, 0);
    }
    for (name, unit, description) in [
        ("../escape-start", "orbit-test.conf", "valid"),
        ("test-start", "orbit-vpn.conf", "valid"),
        ("test-start", "other.conf", "valid"),
        ("test-start", "orbit-test.conf", "a\n[Service]"),
    ] {
        let mut registry = hooks();
        registry[0].name = name;
        registry[0].drop_in = unit;
        registry[0].description = description;
        assert!(validate(
            &Input {
                hooks: vec![HookInput {
                    command: "test.first".into(),
                    input: json!({})
                }],
                run: false
            },
            &registry
        )
        .is_err());
    }
}
#[test]
fn refuses_unsafe_manifest_and_target_before_writing() {
    for content in [
        "../escape-start orbit-escape.conf\n",
        "first-start orbit-vpn.conf\n",
        "first-start orbit-first.conf extra\n",
        "first-start orbit-first.conf\nfirst-start orbit-first.conf\n",
    ] {
        let mut f = Fixture::new();
        fs::create_dir(f.files.path(DIRECTORY)).unwrap();
        fs::write(f.files.path(MANIFEST), content).unwrap();
        assert!(matches!(f.run(&[]), Err(Error::Unsafe { .. })));
        assert_eq!(fs::read_to_string(f.files.path(MANIFEST)).unwrap(), content);
        assert_eq!(f.host.reloads, 0);
    }
    for suffix in ["first-start", "first-start.json", "php-fpm-start-hooks"] {
        let mut f = Fixture::new();
        fs::create_dir(f.files.path(DIRECTORY)).unwrap();
        let target = f.files.path(&format!("{DIRECTORY}/{suffix}"));
        let outside = f.files.path("/keep");
        fs::write(&outside, "keep").unwrap();
        symlink(&outside, &target).unwrap();
        assert!(matches!(f.run(&["first"]), Err(Error::Unsafe { .. })));
        assert!(fs::symlink_metadata(target).unwrap().is_symlink());
        assert_eq!(fs::read_to_string(outside).unwrap(), "keep");
        assert_eq!(f.host.reloads, 0);
    }
}
#[test]
fn renamed_hook_metadata_retires_only_old_names() {
    let mut f = Fixture::new();
    f.run(&["first"]).unwrap();
    let mut registry = hooks();
    registry[0].name = "renamed-start";
    let input = Input {
        hooks: vec![HookInput {
            command: "test.first".into(),
            input: json!({}),
        }],
        run: false,
    };
    converge(&f.files, &mut f.host, &input, &registry, &context()).unwrap();
    assert!(!f.files.path("/etc/orbit/first-start.json").exists());
    registry[0].drop_in = "orbit-renamed.conf";
    converge(&f.files, &mut f.host, &input, &registry, &context()).unwrap();
    assert!(!f.files.path(&format!("{UNITS}/orbit-first.conf")).exists());
    assert!(f.files.path("/etc/orbit/renamed-start.json").exists());
}
#[test]
fn reloads_when_systemd_reports_changes_and_runs_only_when_requested() {
    let mut f = Fixture::new();
    let marker = f.files.path("/ran");
    let mut input = Input {
        hooks: vec![HookInput {
            command: "test.first".into(),
            input: json!({"marker":marker}),
        }],
        run: false,
    };
    converge(&f.files, &mut f.host, &input, &hooks(), &context()).unwrap();
    assert!(!marker.exists());
    f.host.needs_reload = true;
    input.run = true;
    converge(&f.files, &mut f.host, &input, &hooks(), &context()).unwrap();
    assert!(marker.exists());
    assert_eq!(f.host.reloads, 2);
    input.hooks[0].input = json!({"fail":true});
    assert_eq!(
        converge(&f.files, &mut f.host, &input, &hooks(), &context()).unwrap_err(),
        Error::HookFailed {
            hook: "test.first".into(),
            cause: "test.failed".into()
        }
    );
}
