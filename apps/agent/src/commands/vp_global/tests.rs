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
fn accepts_only_package_coordinates_before_running_any_program() {
    for package in [
        "typescript",
        "@openai/codex",
        "@anthropic-ai/claude-code",
        "package_name~beta",
    ] {
        assert!(validate(package, true).is_ok());
    }
    for package in [
        "",
        "TypeScript",
        "type script",
        "typescript\nwhoami",
        "typescript@latest",
        "typescript^5.0.0",
        "https://registry.npmjs.org/typescript",
        "file:../typescript",
        "npm:typescript",
        "../package",
        "-g",
        "@openai/",
        "openai/codex",
        "@openai//codex",
        &"a".repeat(215),
    ] {
        let mut host = Fake::new("");
        assert_eq!(candidate(&mut host, package), Err(Error::Invalid {}));
        for op in [
            Operation::Install,
            Operation::Update,
            Operation::Remove,
            Operation::Plan,
        ] {
            assert_eq!(operation(&mut host, package, op), Err(Error::Invalid {}));
        }
        assert!(host.calls.is_empty());
    }
    let context = Context::new(Platform::Linux, Instant::now());
    assert_eq!(
        Install::run(
            PackageInput {
                package: "-g".into()
            },
            &context
        ),
        Err(Error::Invalid {})
    );
    assert!(serde_json::from_value::<PackageInput>(json!({"package":"a","argv":[]})).is_err());
    assert!(serde_json::from_value::<Empty>(json!({"binary":"/tmp/vp"})).is_err());
}
#[test]
fn protects_pnpm_before_scope_lookup_or_execution() {
    let mut host = Fake::new("\"12.0.0\"");
    for op in [
        Operation::Install,
        Operation::Update,
        Operation::Remove,
        Operation::Plan,
    ] {
        assert_eq!(operation(&mut host, "pnpm", op), Err(Error::Protected {}));
    }
    assert!(host.calls.is_empty());
    let context = Context::new(Platform::Linux, Instant::now());
    assert_eq!(
        Remove::run(
            PackageInput {
                package: "pnpm".into()
            },
            &context
        ),
        Err(Error::Protected {})
    );
    assert!(candidate(&mut host, "pnpm").is_ok());
}
#[test]
fn uses_fixed_arguments_on_repeated_global_operations() {
    let mut host = Fake::new("");
    for _ in 0..2 {
        for op in [
            Operation::Install,
            Operation::Update,
            Operation::Plan,
            Operation::Remove,
        ] {
            assert_eq!(operation(&mut host, "@openai/codex", op), Ok(Done {}));
        }
    }
    let expected = [
        vec!["install", "-g", "@openai/codex", "--node", "lts"],
        vec!["update", "-g", "@openai/codex", "--reinstall-node-mismatch"],
        vec!["remove", "-g", "--dry-run", "@openai/codex"],
        vec!["remove", "-g", "@openai/codex"],
    ];
    assert_eq!(host.calls, [expected.clone(), expected].concat());
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
#[ignore = "Downloads verified recipe and a global package into a temporary SPIKE scope"]
fn real_global_lifecycle_preserves_other_packages_and_repeats() {
    use crate::commands::recipe::{self, store::Account, types::Input, Files, WorkDir};
    let work = WorkDir::new().unwrap();
    let uid = identity::effective_uid();
    assert_ne!(
        uid, 0,
        "Run this acceptance as the unprivileged SPIKE account"
    );
    let gid = rustix::process::getegid().as_raw();
    let account = Account {
        name: identity::user_name(uid).unwrap(),
        uid,
        gid,
        home: work.0.join("home"),
    };
    fs::create_dir(&account.home).unwrap();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    fs::create_dir_all(files.path("/opt/orbit")).unwrap();
    fs::create_dir_all(files.path("/usr/local/bin")).unwrap();
    let recipe: serde_json::Value = serde_json::from_str(include_str!(
        "../../../../gateway/resources/machine-software/recipe.json"
    ))
    .unwrap();
    let pins: serde_json::Map<String, serde_json::Value> = ["node", "vp-cli", "vp", "pnpm"]
        .into_iter()
        .map(|name| (name.into(), recipe["tools"][name].clone()))
        .collect();
    let input: Input = serde_json::from_value(json!({"user":account.name, "snapshot":recipe["ubuntu"]["snapshot"], "architecture":if cfg!(target_arch="aarch64") {"arm64"} else {"amd64"}, "tools":pins, "artifact_mirror":recipe["artifact_mirror"], "vp_lock":include_str!("../../../../gateway/resources/machine-software/vite-plus-package-lock.json")})).unwrap();
    let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(900));
    recipe::javascript::converge(
        &mut recipe::Native(&context),
        &files,
        &input,
        &account,
        context.deadline(),
    )
    .unwrap();
    let scope = vp_scope::inspect(
        Platform::Linux,
        &account.home,
        uid,
        gid,
        &files.path("/opt/orbit"),
        uid,
        gid,
    )
    .unwrap();
    let mut host = Native {
        context: &context,
        binary: scope.binary,
        home: account.home,
    };
    let initial = inventory(&mut host).unwrap();
    assert!(initial.scan_complete);
    assert!(version(&mut host)
        .unwrap()
        .version
        .contains(&input.tools["vp-cli"].version));
    assert!(safe(&candidate(&mut host, "semver").unwrap().version));
    for _ in 0..2 {
        operation(&mut host, "semver", Operation::Install).unwrap();
        operation(&mut host, "semver", Operation::Update).unwrap();
        let installed = inventory(&mut host).unwrap();
        assert!(installed.scan_complete);
        assert_eq!(
            installed
                .packages
                .iter()
                .filter(|p| p.name == "semver" && p.version.is_some())
                .count(),
            1
        );
        operation(&mut host, "semver", Operation::Plan).unwrap();
        assert_eq!(
            inventory(&mut host).unwrap(),
            installed,
            "A removal plan must not remove a package"
        );
        operation(&mut host, "semver", Operation::Remove).unwrap();
        assert_eq!(
            inventory(&mut host).unwrap(),
            initial,
            "Other global packages must remain installed"
        );
    }
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
