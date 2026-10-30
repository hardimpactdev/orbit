use super::*;
use std::{
    fs,
    os::unix::fs::{symlink, PermissionsExt},
    path::PathBuf,
};
fn material(script: &str, seconds: u64) -> Material {
    Material {
        operation: 44,
        step: "before_activation.install".into(),
        digest: digest(script),
        instance: 12,
        project: 9,
        repository: "https://example.test/project.git".into(),
        relative_root: "public".into(),
        release: "admitted".into(),
        timeout_seconds: seconds,
        script: script.into(),
        environment: BTreeMap::new(),
    }
}
fn input(material: &Material) -> Input {
    Input {
        operation: material.operation,
        step: material.step.clone(),
        digest: material.digest.clone(),
    }
}
struct Scratch(PathBuf);
impl Scratch {
    fn new() -> Self {
        let path =
            std::env::temp_dir().join(format!("orbit-local-step-{:016x}", rand::random::<u64>()));
        fs::create_dir(&path).unwrap();
        Self(path)
    }
}
impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
#[test]
fn caller_cannot_select_a_program_or_account() {
    let valid = serde_json::json!({"operation":44,"step":"setup.install","digest":"a".repeat(64)});
    for (key, value) in [
        ("user", "root"),
        ("script", "id"),
        ("directory", "/"),
        ("timeout_seconds", "1"),
        ("environment", "X=secret"),
    ] {
        let mut request = valid.clone();
        request[key] = value.into();
        assert!(serde_json::from_value::<Input>(request).is_err());
    }
    for name in ["setup../install", "teardown.install", "setup..", "setup."] {
        let mut request: Input = serde_json::from_value(valid.clone()).unwrap();
        request.step = name.into();
        assert!(request.validate().is_err());
    }
    assert!(serde_json::from_value::<Input>(valid)
        .unwrap()
        .validate()
        .is_ok());
}
#[test]
fn changed_material_or_an_unsupported_full_deadline_refuses() {
    let mut material = material("true", 300);
    let input = input(&material);
    assert!(material
        .validate(&input, Instant::now() + Duration::from_secs(301))
        .is_ok());
    assert_eq!(
        material.validate(&input, Instant::now() + Duration::from_secs(299)),
        Err("instance.step_deadline_unsupported")
    );
    material.timeout_seconds = 301;
    assert_eq!(
        material.validate(&input, Instant::now() + Duration::from_secs(305)),
        Err("instance.step_deadline_unsupported")
    );
    material.timeout_seconds = 30;
    material.script = "changed".into();
    assert_eq!(
        material.validate(&input, Instant::now() + Duration::from_secs(305)),
        Err("instance.step_not_admitted")
    );
    material.script = "true".into();
    material
        .environment
        .insert("INVALID=NAME".into(), "secret".into());
    assert!(material
        .validate(&input, Instant::now() + Duration::from_secs(305))
        .is_err());
}
#[test]
fn layout_is_project_bound_and_refuses_a_symlink_or_foreign_owner() {
    let scratch = Scratch::new();
    let uid = rustix::process::geteuid().as_raw();
    let gid = rustix::process::getegid().as_raw();
    let layout = Layout {
        home_root: scratch.0.join("home"),
        state_root: scratch.0.join("state"),
        root_uid: uid,
    };
    let material = material("true", 30);
    let home = material.home(&layout);
    assert_eq!(home.user, "orbit-app-9");
    fs::create_dir_all(home.home.join("releases/admitted")).unwrap();
    let state = layout.state_root.join("12");
    fs::create_dir_all(&state).unwrap();
    fs::set_permissions(&state, fs::Permissions::from_mode(0o700)).unwrap();
    let marker = state.join("release-layout");
    fs::write(
        &marker,
        format!(
            "{}\0orbit-app-9\0{}\0initial\0",
            material.repository,
            home.home.display()
        ),
    )
    .unwrap();
    fs::set_permissions(&marker, fs::Permissions::from_mode(0o600)).unwrap();
    assert!(release(&material, &layout, uid, gid).is_ok());
    assert!(release(&material, &layout, uid + 1, gid).is_err());
    fs::rename(
        home.home.join("releases/admitted"),
        home.home.join("releases/elsewhere"),
    )
    .unwrap();
    symlink("elsewhere", home.home.join("releases/admitted")).unwrap();
    assert!(release(&material, &layout, uid, gid).is_err());
    fs::write(marker, "different binding").unwrap();
    assert!(release(&material, &layout, uid, gid).is_err());
}
#[test]
fn capture_bounds_redacts_and_drops_an_oversized_line() {
    let env = BTreeMap::from([("APP_KEY".into(), "first-secret\nsecond-secret".into())]);
    let mut capture = Capture::new(&env);
    let mut lines = Lines::default();
    capture.bytes(&mut lines, b"first-secret and second-secret\n");
    assert!(!capture.output.contains("secret"));
    capture.bytes(&mut lines, &vec![b'x'; LINE_LIMIT + 100]);
    capture.bytes(&mut lines, b"secret-tail\n");
    assert!(!capture.output.contains("secret-tail"));
    assert!(capture.truncated);
    for _ in 0..1000 {
        capture.bytes(&mut lines, b"bounded output\n");
    }
    assert!(capture.output.len() <= OUTPUT_LIMIT);
    capture.bytes(&mut lines, &[b'x'; 8192]);
    assert!(lines.pending.len() <= LINE_LIMIT);
}
#[test]
fn a_capture_cutoff_never_flushes_a_partial_secret() {
    let env = BTreeMap::from([("APP_SECRET".into(), "private-long-secret".into())]);
    let mut capture = Capture::new(&env);
    capture.remaining = 7;
    let mut lines = Lines::default();
    capture.bytes(&mut lines, b"private-long-secret");
    capture.line(&mut lines);
    assert!(capture.output.is_empty());
    assert!(capture.truncated);
}

#[test]
fn environment_values_cannot_disable_structured_credential_redaction() {
    let env = BTreeMap::from([
        ("SCHEME".into(), "https".into()),
        ("MARKER".into(), "Bearer".into()),
    ]);
    let mut capture = Capture::new(&env);
    let mut lines = Lines::default();
    capture.bytes(
        &mut lines,
        b"https://user:private-password@example.test\nAuthorization: Bearer private-token\n",
    );
    assert!(!capture.output.contains("private-password"));
    assert!(!capture.output.contains("private-token"));
}

#[tokio::test]
async fn an_incomplete_tail_never_publishes_a_secret_prefix() {
    let scratch = Scratch::new();
    let mut material = material("printf 'private-prefix'", 1);
    material
        .environment
        .insert("APP_SECRET".into(), "private-prefix-unread-rest".into());
    let output = execute(
        &material,
        rustix::process::geteuid().as_raw(),
        rustix::process::getegid().as_raw(),
        File::open(&scratch.0).unwrap(),
        Instant::now() + Duration::from_secs(3),
        false,
    )
    .await
    .unwrap();
    assert_eq!(output.exit_code, 0);
    assert!(output.output.is_empty());
    assert!(output.truncated);
}

#[tokio::test]
async fn program_has_only_app_env_and_the_sealed_script_descriptor() {
    assert_ne!(
        rustix::process::geteuid().as_raw(),
        0,
        "run the Rust suite as a normal user"
    );
    let scratch = Scratch::new();
    let socket = File::open("/dev/null").unwrap();
    // Simulate a non-CLOEXEC descriptor which must not reach the admitted program.
    let extra = unsafe { libc::fcntl(socket.as_raw_fd(), libc::F_DUPFD, 10) };
    assert!(extra >= 10);
    let script = format!("test ! -e /proc/self/fd/{extra}\ntest -z \"${{ORBIT_AGENT_TEST_PRIVATE-}}\"\nprintf '%s %s %s\\n' \"$USER\" \"$HOME\" \"$APP_VALUE\"\nid -u\n");
    // Environment mutation is process-global; do not set one. A known parent-only variable still proves env_clear.
    let script = format!("test -z \"${{CARGO_MANIFEST_DIR-}}\"\n{script}");
    let mut material = material(&script, 1);
    material
        .environment
        .insert("APP_VALUE".into(), "admitted-value".into());
    let output = execute(
        &material,
        rustix::process::geteuid().as_raw(),
        rustix::process::getegid().as_raw(),
        File::open(&scratch.0).unwrap(),
        Instant::now() + Duration::from_secs(3),
        false,
    )
    .await
    .unwrap();
    unsafe {
        libc::close(extra);
    }
    assert_eq!(output.exit_code, 0);
    assert!(!output.timed_out);
    assert!(output
        .output
        .contains("orbit-app-9 /home/orbit-app-9 [REDACTED]"));
    assert!(output
        .output
        .contains(&rustix::process::geteuid().as_raw().to_string()));
}
#[tokio::test]
async fn timeout_kills_the_process_group_and_noisy_output_stays_bounded() {
    let scratch = Scratch::new();
    let material = material(
        "sleep 30 &\necho $! > child.pid\nwhile true; do printf 'noisy output\\n'; done",
        1,
    );
    let start = Instant::now();
    let output = execute(
        &material,
        rustix::process::geteuid().as_raw(),
        rustix::process::getegid().as_raw(),
        File::open(&scratch.0).unwrap(),
        start + Duration::from_secs(3),
        false,
    )
    .await
    .unwrap();
    assert!(output.timed_out);
    assert!(start.elapsed() < Duration::from_secs(3));
    assert!(output.truncated);
    assert!(output.output.len() <= OUTPUT_LIMIT);
    let pid = fs::read_to_string(scratch.0.join("child.pid")).unwrap();
    // A group signal is asynchronous for other members. pidfd signals exit even for an unreaped zombie.
    let pid: i32 = pid.trim().parse().unwrap();
    let fd = unsafe { libc::syscall(libc::SYS_pidfd_open, pid, 0) } as i32;
    if fd >= 0 {
        let mut event = libc::pollfd {
            fd,
            events: libc::POLLIN,
            revents: 0,
        };
        let ready = unsafe { libc::poll(&mut event, 1, 1000) };
        unsafe {
            libc::close(fd);
        }
        assert_eq!(ready, 1);
    } else {
        assert_eq!(
            std::io::Error::last_os_error().raw_os_error(),
            Some(libc::ESRCH)
        );
    }
}
#[tokio::test]
async fn unsupported_deadline_precedes_any_program_effect() {
    let scratch = Scratch::new();
    let material = material("touch should-not-exist", 301);
    assert!(execute(
        &material,
        rustix::process::geteuid().as_raw(),
        rustix::process::getegid().as_raw(),
        File::open(&scratch.0).unwrap(),
        Instant::now() + Duration::from_secs(305),
        false
    )
    .await
    .is_err());
    assert!(!scratch.0.join("should-not-exist").exists());
}
