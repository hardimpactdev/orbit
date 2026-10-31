use super::*;
use serde_json::json;
use std::{fs, io::Write, time::Instant};

struct Fake {
    output: Vec<u8>,
    calls: Vec<Vec<String>>,
}
impl Fake {
    fn new(output: impl AsRef<[u8]>) -> Self {
        Self {
            output: output.as_ref().to_vec(),
            calls: vec![],
        }
    }
}
impl Host for Fake {
    fn run(&mut self, args: &[&str]) -> Result<Vec<u8>> {
        self.calls
            .push(args.iter().map(|s| s.to_string()).collect());
        Ok(self.output.clone())
    }
}
#[test]
fn manager_version_reads_one_safe_line_and_candidate_requires_a_json_string() {
    for newline in [
        "\n", "\r\n", "\u{000b}", "\u{000c}", "\u{0085}", "\u{2028}", "\u{2029}",
    ] {
        let mut host = Fake::new(format!("vp v0.2.6{newline}extra"));
        assert_eq!(version(&mut host).unwrap().version, "vp v0.2.6");
        assert_eq!(host.calls, [vec!["--version"]]);
    }
    for invalid in ["".as_bytes(), b"\xff", b"version\tsecret", &vec![b'a'; 256]] {
        assert_eq!(version(&mut Fake::new(invalid)), Err(Error::Output {}));
    }
    let mut host = Fake::new("\"0.150.0\"\n");
    assert_eq!(
        candidate(&mut host, "@openai/codex").unwrap().version,
        "0.150.0"
    );
    assert_eq!(
        host.calls,
        [vec!["info", "@openai/codex", "version", "--json"]]
    );
    for invalid in [
        "",
        "[]",
        "{}",
        "12",
        "null",
        "\"\"",
        "\"1.0\"\"2.0\"",
        "\"1.0\\nsecret\"",
        &format!("\"{}\"", "a".repeat(256)),
    ] {
        assert_eq!(
            candidate(&mut Fake::new(invalid), "typescript"),
            Err(Error::Output {})
        );
    }
}
#[test]
fn inventory_preserves_root_names_and_versions_without_dependencies() {
    let fixture = include_bytes!("../../../tests/fixtures/tools/vp-list-g.json");
    let mut host = Fake::new(fixture);
    let first = inventory(&mut host).unwrap();
    assert!(first.scan_complete);
    assert!(first.packages.contains(&Package {
        name: "@openai/codex".into(),
        version: Some("0.150.0".into())
    }));
    assert_eq!(inventory(&mut host).unwrap(), first);
    assert_eq!(
        host.calls,
        [vec!["list", "-g", "--json"], vec!["list", "-g", "--json"]]
    );
    assert_eq!(
        inventory(&mut Fake::new("[]")),
        Ok(InventoryOutput {
            packages: vec![],
            scan_complete: true
        })
    );
    let data = inventory(&mut Fake::new(
        r#"[{"name":"JSONStream","version":"1.3.5","dependencies":[{"name":"not-global"}]}]"#,
    ))
    .unwrap();
    assert!(data.scan_complete);
    assert_eq!(data.packages.len(), 1);
    assert_eq!(data.packages[0].name, "JSONStream");
}
#[test]
fn inventory_distinguishes_unverifiable_packages_from_a_failed_whole_scan() {
    for bad in [
        json!(null),
        json!(5),
        json!(""),
        json!("1.0\nsecret"),
        json!("a".repeat(256)),
        json!({}),
    ] {
        let input =
            json!([{"name":"bad", "version":bad}, {"name":"typescript", "version":"5.8.3"}]);
        let output = inventory(&mut Fake::new(input.to_string())).unwrap();
        assert!(!output.scan_complete);
        assert_eq!(output.packages[0].version, None);
        assert_eq!(output.packages[1].version.as_deref(), Some("5.8.3"));
    }
    for name in ["", "type\nscript", &"a".repeat(256)] {
        let output = inventory(&mut Fake::new(
            json!([{"name":name,"version":"1"}]).to_string(),
        ))
        .unwrap();
        assert!(!output.scan_complete);
    }
    for name in ["typescript", "JSONStream"] {
        let output = inventory(&mut Fake::new(
            json!([{"name":name,"version":"1"},{"name":name,"version":"2"}]).to_string(),
        ))
        .unwrap();
        assert!(!output.scan_complete);
        assert_eq!(output.packages.len(), 2);
    }
    for invalid in [
        "{}",
        "[][]",
        "[{}]",
        "[null]",
        "[[1]]",
        "[{\"name\":1}]",
        "[{\"name\":\"a\"}",
        &" ".repeat(MAX_OUTPUT as usize + 1),
    ] {
        assert_eq!(inventory(&mut Fake::new(invalid)), Err(Error::Output {}));
    }
}
#[test]
fn inventory_keeps_the_separate_scan_and_installed_depth_bounds() {
    for (nesting, complete) in [
        (3, true),
        (63, true),
        (64, false),
        (130, false),
        (511, false),
    ] {
        let input = format!(
            r#"[{{"name":"typescript","version":"1.0","ignored":{}0{}}}]"#,
            "[".repeat(nesting - 2),
            "]".repeat(nesting - 2)
        );
        let output = inventory(&mut Fake::new(input)).unwrap();
        assert_eq!(output.scan_complete, complete);
        assert_eq!(output.packages[0].version.as_deref(), Some("1.0"));
    }
    let input = format!(
        r#"[{{"name":"a","ignored":{}0{}}}]"#,
        "[".repeat(510),
        "]".repeat(510)
    );
    assert_eq!(inventory(&mut Fake::new(input)), Err(Error::Output {}));
    assert_eq!(depth(br#"[{"name":"escaped\\\"[{","version":"1"}]"#), 2);
}

fn native_helper(name: &str, home: &Path, timeout: Duration) -> Result<Vec<u8>> {
    let context = Context::new(Platform::current(), Instant::now() + timeout);
    Native {
        context: &context,
        binary: std::env::current_exe().unwrap(),
        home: home.into(),
    }
    .run(&[
        "--exact",
        &format!("commands::vp_global::tests::{name}"),
        "--ignored",
        "--nocapture",
    ])
}
#[test]
fn native_runner_sets_home_and_scope_and_returns_fixed_failures() {
    let home = std::env::temp_dir();
    let data = native_helper("environment_child", &home, Duration::from_secs(5)).unwrap();
    let text = String::from_utf8(data).unwrap();
    assert!(text.contains(&format!("HOME={}", home.display())));
    assert!(text.contains(&format!("CWD={}", home.display())));
    assert!(text.contains(&format!(
        "VP_HOME={}",
        std::env::current_exe()
            .unwrap()
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .display()
    )));
    assert_eq!(
        native_helper("failure_child", &home, Duration::from_secs(5)),
        Err(Error::Failed {})
    );
    for helper in ["output_child", "stderr_child"] {
        assert_eq!(
            native_helper(helper, &home, Duration::from_secs(5)),
            Err(Error::Output {})
        );
    }
}
#[test]
fn native_deadline_kills_the_process_group_with_inherited_pipes() {
    let home = std::env::temp_dir().join(format!("orbit-vp-global-{:016x}", rand::random::<u64>()));
    fs::create_dir(&home).unwrap();
    let start = Instant::now();
    assert_eq!(
        native_helper("sleep_child", &home, Duration::from_secs(2)),
        Err(Error::Failed {})
    );
    assert!(start.elapsed() < Duration::from_secs(5));
    let pid: i32 = fs::read_to_string(home.join("pid"))
        .unwrap()
        .parse()
        .unwrap();
    // A killed orphan can remain a zombie until init reaps it; it must not be running.
    let stopped = || {
        #[cfg(target_os = "linux")]
        {
            fs::read_to_string(format!("/proc/{pid}/stat"))
                .map_or(true, |stat| stat.contains(") Z "))
        }
        #[cfg(not(target_os = "linux"))]
        {
            unsafe { libc::kill(pid, 0) == -1 }
        }
    };
    let deadline = Instant::now() + Duration::from_secs(1);
    while !stopped() && Instant::now() < deadline {
        std::thread::sleep(Duration::from_millis(10));
    }
    assert!(stopped());
    fs::remove_dir_all(home).unwrap();
}
#[test]
#[ignore = "subprocess helper"]
fn environment_child() {
    println!("CWD={}", std::env::current_dir().unwrap().display());
    println!(
        "HOME={}\nVP_HOME={}",
        std::env::var("HOME").unwrap(),
        std::env::var("VP_HOME").unwrap()
    );
}
#[test]
#[ignore = "subprocess helper"]
fn failure_child() {
    eprintln!("private manager diagnostic");
    std::process::exit(7);
}
#[test]
#[ignore = "subprocess helper"]
fn output_child() {
    let data = vec![b'x'; MAX_OUTPUT as usize + 1];
    std::io::stdout().write_all(&data).unwrap();
    std::io::stderr().write_all(&data).unwrap();
}
#[test]
#[ignore = "subprocess helper"]
fn sleep_child() {
    // This isolated helper has no application threads or shared state in its child.
    let pid = unsafe { libc::fork() };
    assert!(pid >= 0);
    if pid == 0 {
        unsafe {
            libc::sleep(30);
            libc::_exit(0);
        }
    }
    fs::write(
        PathBuf::from(std::env::var_os("HOME").unwrap()).join("pid"),
        pid.to_string(),
    )
    .unwrap();
    // Exit with the descendant holding both pipes open. The deadline must still apply.
}

#[test]
fn expired_deadline_refuses_before_starting_the_executable() {
    let path =
        std::env::temp_dir().join(format!("orbit-vp-expired-{:016x}", rand::random::<u64>()));
    let context = Context::new(Platform::current(), Instant::now());
    let mut host = Native {
        context: &context,
        binary: "/usr/bin/touch".into(),
        home: std::env::temp_dir(),
    };
    assert_eq!(host.run(&[path.to_str().unwrap()]), Err(Error::Failed {}));
    assert!(!path.exists());
}

#[test]
#[ignore = "subprocess helper"]
fn stderr_child() {
    std::io::stderr()
        .write_all(&vec![b'x'; MAX_OUTPUT as usize + 1])
        .unwrap();
}
