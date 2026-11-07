use super::*;
use crate::test_support::executable;
use std::os::unix::fs::symlink;

pub(super) const ID: &str = "123e4567-e89b-42d3-a456-426614174000";

pub(super) struct Fixture {
    pub(super) host: Host,
    input: InstallInput,
}

impl Fixture {
    pub(super) fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-schedules-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("etc/systemd/system")).unwrap();
        fs::create_dir_all(root.join("bin")).unwrap();
        let uid = identity::effective_uid();
        assert_ne!(uid, 0, "Schedule tests run as a normal user");
        let user = identity::user_name(uid).unwrap();
        let account = identity::passwd(&user).unwrap();
        let host = Host {
            systemctl: root.join("bin/systemctl"),
            analyze: root.join("bin/systemd-analyze"),
            root,
            uid,
            gid: account.gid,
        };
        // External systemd is stateful, but all filesystem, rendering, rollback, and inspection logic is real.
        let state = host.root.to_str().unwrap();
        executable(
            &host.systemctl,
            format!(
                r#"#!/bin/sh
state='{state}'
case "$1" in
  is-enabled) cat "$state/enabled" 2>/dev/null || echo disabled ;;
  is-active) case "$2" in *.service) cat "$state/service-active" 2>/dev/null || echo inactive;; *) cat "$state/active" 2>/dev/null || echo inactive;; esac ;;
  enable) [ ! -f "$state/fail-enable" ] || exit 1; echo enabled > "$state/enabled"; [ "$2" != --now ] || echo active > "$state/active" ;;
  disable) [ ! -f "$state/fail-disable" ] || exit 1; echo disabled > "$state/enabled"; [ "$2" != --now ] || echo inactive > "$state/active" ;;
  start) echo active > "$state/active" ;;
  stop) echo inactive > "$state/active" ;;
  daemon-reload) [ ! -f "$state/fail-reload" ] ;;
  reset-failed) exit 0 ;;
  *) exit 1 ;;
esac
"#
            ),
        );
        executable(
            &host.analyze,
            format!(
                r#"#!/bin/sh
case "$1" in
  calendar) [ "$2" != invalid ] ;;
  verify) [ ! -f '{state}/fail-verify' ] ;;
  *) exit 1 ;;
esac
"#
            ),
        );
        let input = InstallInput {
            id: ID.into(),
            user,
            group: identity::group_name(account.gid).unwrap(),
            home: account.home,
            working_directory: host.root.clone(),
            shell: "/bin/bash".into(),
            login_shell: false,
            command: "printf '%s' \"$USER:$LOGNAME:$HOME\"".into(),
            desired: "enabled".into(),
            calendar: "daily".into(),
            timeout_seconds: 60,
        };
        Self { host, input }
    }

    fn paths(&self) -> Paths {
        self.host.paths(ID).unwrap()
    }
    fn install(&self) -> Result<Done, ScheduleError> {
        install_at(&self.host, &self.input)
    }
    fn files(&self) -> Vec<Vec<u8>> {
        let p = self.paths();
        [&p.script, &p.service, &p.timer]
            .map(|p| fs::read(p).unwrap())
            .to_vec()
    }
    fn fail(&self, step: &str) {
        fs::write(self.host.root.join(format!("fail-{step}")), "").unwrap();
    }
    fn target(&self) -> TargetInput {
        TargetInput {
            id: ID.into(),
            user: self.input.user.clone(),
            working_directory: self.input.working_directory.clone(),
        }
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.host.root);
    }
}

#[test]
fn rejects_invalid_intent_and_foreign_artifacts_before_mutation() {
    for change in [
        "id",
        "calendar",
        "timeout",
        "desired",
        "shell",
        "command",
        "root",
        "directory",
        "group",
    ] {
        let mut fixture = Fixture::new();
        match change {
            "id" => fixture.input.id = "abcd-1".into(),
            "calendar" => fixture.input.calendar = "invalid".into(),
            "timeout" => fixture.input.timeout_seconds = 0,
            "desired" => fixture.input.desired = "other".into(),
            "shell" => fixture.input.shell = "/bin/bash\nExecStart=/bad".into(),
            "command" => fixture.input.command = "echo first\necho second".into(),
            "root" => fixture.input.user = "root".into(),
            "directory" => fixture.input.working_directory = fixture.host.root.join("missing"),
            _ => fixture.input.group = "orbit-missing-group".into(),
        }
        assert!(fixture.install().is_err(), "{change}");
        assert!(!fixture.host.path("/etc/orbit").exists(), "{change}");
        assert!(!fixture.host.root.join("enabled").exists(), "{change}");
    }
    let fixture = Fixture::new();
    fs::create_dir_all(fixture.host.path("/etc/orbit/schedules")).unwrap();
    fs::set_permissions(
        fixture.host.path("/etc/orbit"),
        fs::Permissions::from_mode(0o700),
    )
    .unwrap();
    fs::write(fixture.paths().script, "foreign").unwrap();
    assert_eq!(fixture.install(), Err(ScheduleError::ArtifactConflict {}));
    assert_eq!(
        fs::read_to_string(fixture.paths().script).unwrap(),
        "foreign"
    );
    assert_eq!(
        fs::metadata(fixture.host.path("/etc/orbit"))
            .unwrap()
            .mode()
            & 0o777,
        0o700
    );
}

#[test]
fn installs_repeatedly_without_changing_files() {
    let fixture = Fixture::new();
    fixture.install().unwrap();
    let before = fixture.files();
    fixture.install().unwrap();
    assert_eq!(fixture.files(), before);
}

#[test]
fn failed_install_restores_files_and_enabled_active_state() {
    let mut fixture = Fixture::new();
    fixture.install().unwrap();
    let before = fixture.files();
    fixture.input.command = "echo replacement".into();
    fixture.fail("verify");
    assert_eq!(fixture.install(), Err(failed("verify")));
    assert_eq!(fixture.files(), before);
    assert_eq!(
        unit_state(&fixture.host, "is-enabled", &fixture.paths().timer_unit),
        "enabled"
    );
    assert_eq!(
        unit_state(&fixture.host, "is-active", &fixture.paths().timer_unit),
        "active"
    );
}

#[test]
fn first_install_failure_removes_its_files_and_reports_failed_rollback() {
    let fixture = Fixture::new();
    fixture.fail("verify");
    assert_eq!(fixture.install(), Err(failed("verify")));
    let paths = fixture.paths();
    assert!([paths.script, paths.service, paths.timer]
        .into_iter()
        .all(|p| !p.exists()));
    fixture.fail("reload");
    assert_eq!(fixture.install(), Err(ScheduleError::RollbackFailed {}));
}

#[test]
fn removal_waits_for_active_runs_but_cascade_does_not_and_refuses_foreign_artifacts() {
    let fixture = Fixture::new();
    fixture.install().unwrap();
    let mut input = RemoveInput {
        id: ID.into(),
        group: fixture.input.group.clone(),
        cascade: false,
    };
    fs::write(fixture.host.root.join("service-active"), "active").unwrap();
    assert_eq!(
        remove_at(&fixture.host, &input),
        Err(ScheduleError::Busy {})
    );
    assert!(fixture.paths().script.exists());
    assert_eq!(
        unit_state(&fixture.host, "is-active", &fixture.paths().timer_unit),
        "inactive"
    );
    input.cascade = true;
    remove_at(&fixture.host, &input).unwrap();
    remove_at(&fixture.host, &input).unwrap();
    fixture.install().unwrap();
    fs::write(fixture.paths().script, "foreign").unwrap();
    assert_eq!(
        remove_at(&fixture.host, &input),
        Err(ScheduleError::ArtifactConflict {})
    );
    assert_eq!(
        unit_state(&fixture.host, "is-active", &fixture.paths().timer_unit),
        "active"
    );
}

#[test]
fn removal_keeps_artifacts_when_disabling_fails_and_reports_reload_failure() {
    let fixture = Fixture::new();
    fixture.install().unwrap();
    let input = RemoveInput {
        id: ID.into(),
        group: fixture.input.group.clone(),
        cascade: false,
    };
    let before = fixture.files();
    fixture.fail("disable");
    assert_eq!(remove_at(&fixture.host, &input), Err(failed("remove")));
    assert_eq!(fixture.files(), before);
    fs::remove_file(fixture.host.root.join("fail-disable")).unwrap();
    fixture.fail("reload");
    assert_eq!(remove_at(&fixture.host, &input), Err(failed("remove")));
    fs::remove_file(fixture.host.root.join("fail-reload")).unwrap();
    remove_at(&fixture.host, &input).unwrap();
}

#[test]
fn login_context_reaches_the_shell_and_manual_runs_keep_the_disabled_timer() {
    let mut fixture = Fixture::new();
    fixture.input.command = "shopt -q login_shell".into();
    fixture.input.desired = "disabled".into();
    for login in [false, true] {
        fixture.input.login_shell = login;
        fixture.install().unwrap();
        assert_eq!(
            runtime::project_command(&fixture.host, ID)
                .unwrap()
                .status()
                .unwrap()
                .success(),
            login
        );
    }
    run_at(&fixture.host, &fixture.target()).unwrap();
    assert_eq!(
        unit_state(&fixture.host, "is-enabled", &fixture.paths().timer_unit),
        "disabled"
    );
    let mut target = fixture.target();
    target.working_directory = fixture.host.root.join("missing");
    assert_eq!(
        run_at(&fixture.host, &target),
        Err(ScheduleError::TargetUnavailable {})
    );
}

#[test]
fn runs_project_command_as_its_account_with_context_and_follows_current_release() {
    let mut fixture = Fixture::new();
    for release in ["one", "two"] {
        fs::create_dir(fixture.host.root.join(release)).unwrap();
    }
    let current = fixture.host.root.join("current");
    symlink("one", &current).unwrap();
    fixture.input.working_directory = current.clone();
    fixture.input.command = "printf '%s|%s|%s|%s' \"$USER\" \"$HOME\" \"$PWD\" \"$(id -u)\"".into();
    fixture.install().unwrap();
    for release in ["one", "two"] {
        fs::remove_file(&current).unwrap();
        symlink(release, &current).unwrap();
        let result = runtime::project_command(&fixture.host, ID)
            .unwrap()
            .output()
            .unwrap();
        assert!(result.status.success());
        assert_eq!(
            String::from_utf8(result.stdout).unwrap(),
            format!(
                "{}|{}|{}|{}",
                fixture.input.user,
                fixture.input.home,
                fixture.host.root.join(release).display(),
                fixture.host.uid
            )
        );
    }
    fixture.input.command = "exit 23".into();
    fixture.install().unwrap();
    assert_eq!(
        runtime::project_command(&fixture.host, ID)
            .unwrap()
            .status()
            .unwrap()
            .code(),
        Some(23)
    );
    fixture.input.user = "root".into();
    let artifacts = spec::render(&fixture.input).unwrap();
    fs::write(fixture.paths().script, artifacts.script).unwrap();
    assert!(runtime::project_command(&fixture.host, ID).is_err());
}

#[test]
fn renders_only_project_code_in_the_protected_file_and_quotes_systemd_values() {
    let mut fixture = Fixture::new();
    fixture.input.home = "/home/a b%h\\\"".into();
    fixture.input.command = "printf '%s' 'secret; $HOME'".into();
    let rendered = spec::render(&fixture.input).unwrap();
    assert!(!rendered.service.contains("secret") && !rendered.timer.contains("secret"));
    assert!(rendered
        .service
        .contains("Environment=\"HOME=/home/a b%%h\\\\\\\"\""));
    let (context, command) = spec::execution(&rendered.script, ID).unwrap();
    assert_eq!(context.home, fixture.input.home);
    assert_eq!(command, fixture.input.command);
    assert!(!context.login_shell);
    fixture.input.login_shell = true;
    assert!(
        spec::execution(&spec::render(&fixture.input).unwrap().script, ID)
            .unwrap()
            .0
            .login_shell
    );
    for id in [
        "",
        "abcd-1",
        "../id",
        "123e4567-e89b-02d3-a456-426614174000",
        "123e4567-e89b-42d3-f456-426614174000",
    ] {
        assert!(paths(id).is_err());
    }
}

#[test]
fn systemd_accepts_the_rendered_service_and_timer() {
    let mut fixture = Fixture::new();
    fixture.input.working_directory = fixture.host.root.join("with spaces");
    fs::create_dir(&fixture.input.working_directory).unwrap();
    let rendered = spec::render(&fixture.input).unwrap();
    let paths = fixture.paths();
    // The test host need not install the agent globally. Systemd still parses every generated directive.
    fs::write(
        &paths.service,
        rendered.service.replace(spec::AGENT, "/usr/bin/true"),
    )
    .unwrap();
    fs::write(&paths.timer, rendered.timer).unwrap();
    let output = Process::new("/usr/bin/systemd-analyze")
        .arg("verify")
        .args([&paths.service, &paths.timer])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
fn ownership_read_errors_are_not_treated_as_absence_and_symlinks_are_refused() {
    let fixture = Fixture::new();
    let path = fixture.host.root.join("loop");
    symlink("loop", &path).unwrap();
    assert_eq!(
        owned(
            &fixture.host,
            &path.join("file"),
            fixture.host.gid,
            0o750,
            "marker"
        ),
        Err(failed("inspect-artifacts"))
    );
    assert_eq!(
        owned(&fixture.host, &path, fixture.host.gid, 0o750, "marker"),
        Err(ScheduleError::ArtifactConflict {})
    );
}

#[test]
#[ignore = "invoked by the root test with root privileges"]
fn traversal_as_root_helper() {
    let root = PathBuf::from(std::env::var("ORBIT_SCHEDULE_FIXTURE").unwrap());
    let input: InstallInput =
        serde_json::from_slice(&fs::read(root.join("input.json")).unwrap()).unwrap();
    let host = Host {
        systemctl: root.join("bin/systemctl"),
        analyze: root.join("bin/systemd-analyze"),
        root,
        uid: 0,
        gid: 0,
    };
    install_at(&host, &input).unwrap();
    let paths = host.paths(ID).unwrap();
    let parent = host.path("/etc/orbit");
    assert!(
        runtime::project_command(&host, ID).is_err(),
        "root never executes project code"
    );
    // Return fixture ownership to the parent for normal-user cleanup.
    let uid = identity::passwd(&input.user).unwrap().uid;
    let gid = identity::group_id(&input.group).unwrap();
    for path in [
        &paths.script,
        &paths.service,
        &paths.timer,
        &parent,
        &host.path("/etc/orbit/schedules"),
        &host.path("/etc/orbit/schedule-candidates"),
    ] {
        chown(path, uid, gid).unwrap();
    }
}

#[test]
fn root_never_executes_project_code() {
    let fixture = Fixture::new();
    let input = &fixture.input;
    fs::write(fixture.host.root.join("input.json"), serde_json::to_vec(&serde_json::json!({
        "id": input.id, "user": input.user, "group": input.group, "home": input.home,
        "working_directory": input.working_directory, "shell": input.shell, "login_shell": input.login_shell,
        "command": input.command, "desired": input.desired, "calendar": input.calendar, "timeout_seconds": input.timeout_seconds
    })).unwrap()).unwrap();
    let output = Process::new("sudo")
        .args(["-n", "env"])
        .arg(format!(
            "ORBIT_SCHEDULE_FIXTURE={}",
            fixture.host.root.display()
        ))
        .arg(std::env::current_exe().unwrap())
        .args([
            "--ignored",
            "--exact",
            "commands::schedules::tests::traversal_as_root_helper",
            "--nocapture",
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
fn snapshot_uses_the_live_journal_format_in_chronological_order() {
    let fixture = Fixture::new();
    let program = fixture.host.root.join("bin/journalctl");
    let args = fixture.host.root.join("journal-args");
    executable(
        &program,
        format!(
            "#!/bin/sh\nprintf '%s\\n' \"$@\" > '{}'\nprintf '%s\\n' '2026-09-25T10:15:03+00:00 host job[1]: two' '2026-09-25T10:15:02+00:00 host job[1]: one'\n",
            args.display()
        ),
    );

    let logs = logs_with(
        &program,
        &LogsInput {
            id: ID.into(),
            lines: 2,
            max_bytes: 4096,
        },
    )
    .unwrap();

    assert_eq!(
        logs.output,
        "2026-09-25T10:15:02+00:00 host job[1]: one\n2026-09-25T10:15:03+00:00 host job[1]: two\n"
    );
    assert!(!logs.truncated);
    assert_eq!(fs::read_to_string(args).unwrap(), format!("--unit\norbit-schedule-{ID}.service\n--lines\n2\n--no-pager\n--quiet\n--reverse\n--output=short-iso\n--utc\n"));
}
