use super::*;
use crate::commands::recipe::{Invocation, Output, WorkDir};
use std::time::Duration;

#[test]
fn convergence_installs_verified_bytes_repeats_without_downloads_and_refuses_bad_tools() {
    use std::io::Write;
    struct Runtime {
        files: Files,
        archive: Vec<u8>,
        calls: Vec<Invocation>,
        wrong_version: bool,
    }
    impl Host for Runtime {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            self.calls.push(call.clone());
            let mut out = Output::default();
            match call.argv[0].as_str() {
                "dpkg" if call.argv[1] == "--print-architecture" => out.stdout = "amd64".into(),
                "dpkg-query" => {}
                "env" if call.argv.last().unwrap() == "--version" => {
                    if self.files.path("/opt/orbit/bun/bin/bun").exists() {
                        out.stdout = if self.wrong_version { "1.3.1" } else { "1.3.2" }.into();
                    } else {
                        out.code = 127;
                    }
                }
                "curl" => {
                    let at = call.argv.iter().position(|v| v == "--output").unwrap() + 1;
                    fs::write(&call.argv[at], &self.archive).unwrap();
                }
                _ => panic!(
                    "Unexpected JavaScript installation command: {:?}",
                    call.argv
                ),
            }
            Ok(out)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    for defect in ["none", "digest", "version", "tool"] {
        let (_work, files, account, _scope, mut input) = fixture();
        let mut zip = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
        zip.start_file(
            "bun-linux-x64/bun",
            zip::write::SimpleFileOptions::default().unix_permissions(0o755),
        )
        .unwrap();
        zip.write_all(b"bun executable").unwrap();
        let archive = zip.finish().unwrap().into_inner();
        let digest = if defect == "digest" {
            "c".repeat(64)
        } else {
            format!("{:x}", Sha256::digest(&archive))
        };
        input.tools = serde_json::from_value(
            json!({"bun":{"source":"artifact", "version":"1.3.2", "artifacts":{
            "amd64":{"url":"https://example.test/bun.zip", "sha256":digest}}}}),
        )
        .unwrap();
        if defect == "tool" {
            input.tools.insert(
                "curl".into(),
                serde_json::from_value(
                    json!({"source":"ubuntu", "version":"1.0", "package":"curl"}),
                )
                .unwrap(),
            );
        }
        let mut host = Runtime {
            files: Files {
                root: files.root.clone(),
                uid: files.uid,
                gid: files.gid,
            },
            archive,
            calls: vec![],
            wrong_version: defect == "version",
        };
        let result = converge(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(5),
        );
        match defect {
            "none" => {
                assert!(result.unwrap());
                assert_eq!(
                    fs::read(files.path("/opt/orbit/bun/bin/bun")).unwrap(),
                    b"bun executable"
                );
                assert!(!converge(
                    &mut host,
                    &files,
                    &input,
                    &account,
                    Instant::now() + Duration::from_secs(5)
                )
                .unwrap());
                assert_eq!(host.calls.iter().filter(|c| c.argv[0] == "curl").count(), 1);
            }
            "digest" => {
                assert_eq!(result.unwrap_err().stage, "download");
                assert!(!files.path("/opt/orbit/bun").exists());
            }
            "version" => assert!(result.unwrap_err().reason.contains("do not match")),
            "tool" => {
                assert!(result.unwrap_err().reason.contains("only JavaScript"));
                assert!(host.calls.is_empty());
            }
            _ => unreachable!(),
        }
    }
}
fn fixture() -> (WorkDir, Files, Account, PathBuf, Input) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    let account = Account {
        name: crate::command::identity::user_name(uid).unwrap(),
        uid,
        gid,
        home: work.0.join("home"),
    };
    let scope = account.home.join(".vite-plus");
    for path in [
        scope.join("bin"),
        scope.join("recipe"),
        files.path("/opt/orbit"),
        files.path("/usr/local/bin"),
    ] {
        fs::create_dir_all(path).unwrap();
    }
    fs::write(scope.join("bin/vp"), "").unwrap();
    let lock = json!({"name":"orbit-vp","version":"1.2.0","packages":{"":{"dependencies":{"vite-plus":"1.2.0"}}}}).to_string();
    let digest = format!("{:x}", Sha256::digest(lock.as_bytes()));
    let input = serde_json::from_value(json!({"user":account.name,"snapshot":"20261022T000000Z","architecture":"amd64","vp_lock":lock,"tools":{"vp":{"source":"artifact","version":"1.2.0","lock_sha256":digest},"node":{"source":"artifact","version":"24.1.0"}}})).unwrap();
    (work, files, account, scope, input)
}
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.calls.push(call.clone());
        if call.argv.contains(&"ci".into()) {
            let candidate = PathBuf::from(call.argv.last().unwrap());
            let package = candidate.join("node_modules/vite-plus");
            fs::create_dir_all(&package).unwrap();
            fs::write(package.join("package.json"), r#"{"version":"1.2.0"}"#).unwrap();
        }
        let stdout = if call.argv == ["dpkg", "--print-architecture"] {
            "amd64"
        } else if call.argv.last().is_some_and(|v| v == "--version") {
            "v24.1.0"
        } else {
            ""
        };
        Ok(Output {
            stdout: stdout.into(),
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no retries")
    }
}
#[test]
fn replaces_only_the_locked_runtime_and_preserves_old_dependencies_and_global_tools() {
    let (_work, files, account, scope, input) = fixture();
    let runtime = scope.join("recipe");
    let old = scope.join("old/node_modules/vite-plus");
    fs::create_dir_all(&old).unwrap();
    fs::write(old.join("package.json"), r#"{"version":"1.0.0"}"#).unwrap();
    fs::create_dir(scope.join("node_modules")).unwrap();
    std::os::unix::fs::symlink(&old, scope.join("node_modules/vite-plus")).unwrap();
    fs::create_dir(scope.join("global")).unwrap();
    fs::write(scope.join("global/keep"), "managed tools").unwrap();
    let mut host = Fake::default();
    for _ in 0..2 {
        install_vite(&mut host, &files, &scope, &runtime, &input, &account).unwrap();
    }
    assert_eq!(host.calls.len(), 1);
    assert!(host.calls[0].argv.contains(&"--ignore-scripts".into()));
    assert_eq!(
        fs::read_to_string(old.join("package.json")).unwrap(),
        r#"{"version":"1.0.0"}"#
    );
    assert_eq!(
        fs::read_to_string(scope.join("global/keep")).unwrap(),
        "managed tools"
    );
    assert_eq!(
        fs::canonicalize(scope.join("node_modules/vite-plus")).unwrap(),
        fs::canonicalize(runtime.join("vp-current")).unwrap()
    );
}
#[test]
fn bad_lock_refuses_before_install_or_activation_and_old_lock_never_counts_as_current() {
    let (_work, files, account, scope, mut input) = fixture();
    let runtime = scope.join("recipe");
    let mut host = Fake::default();
    let correct = input.vp_lock.clone();
    input.vp_lock = Some("wrong".into());
    assert!(install_vite(&mut host, &files, &scope, &runtime, &input, &account).is_err());
    assert!(host.calls.is_empty());
    assert!(!scope.join("node_modules").exists());
    input.vp_lock = correct;
    install_vite(&mut host, &files, &scope, &runtime, &input, &account).unwrap();
    let tools = [("vp".into(), input.tools["vp"].clone())].into();
    assert_eq!(
        inspect(&mut host, &files, &tools, Some(&account)).unwrap()["vp"],
        Some("1.2.0".into())
    );
    let digest = input.tools["vp"].lock_sha256.as_ref().unwrap();
    fs::write(
        runtime.join(format!("vp-runtime-{digest}/package-lock.json")),
        "changed",
    )
    .unwrap();
    assert_eq!(
        inspect(&mut host, &files, &tools, Some(&account)).unwrap()["vp"],
        None
    );
}
#[test]
fn inventory_never_runs_a_moving_node_shim_or_uninitialized_vite_cli() {
    let (_work, files, account, scope, mut input) = fixture();
    input.tools.remove("vp");
    input
        .tools
        .insert("vp-cli".into(), input.tools["node"].clone());
    let mut host = Fake::default();
    let result = inspect(&mut host, &files, &input.tools, Some(&account)).unwrap();
    assert_eq!(result["node"], None);
    assert_eq!(result["vp-cli"], None);
    assert_eq!(host.calls.len(), 2);
    fs::write(scope.join("bin/.vp-setup-complete"), "").unwrap();
    let node = scope.join("js_runtime/node/24.1.0/bin/node");
    fs::create_dir_all(node.parent().unwrap()).unwrap();
    fs::write(&node, "").unwrap();
    fs::write(scope.join(store::REFERENCE), r#"{"version":"24.1.0"}"#).unwrap();
    fs::write(
        scope.join("config.json"),
        r#"{"defaultNodeVersion":"24.1.0"}"#,
    )
    .unwrap();
    host.calls.clear();
    let result = inspect(&mut host, &files, &input.tools, Some(&account)).unwrap();
    assert_eq!(result["node"], Some("24.1.0".into()));
    assert!(host
        .calls
        .iter()
        .any(|call| call.argv.contains(&node.display().to_string())));
    for call in host
        .calls
        .iter()
        .filter(|call| call.argv.last().is_some_and(|s| s == "--version"))
    {
        assert!(call.argv.contains(&format!("VP_HOME={}", scope.display())));
        assert!(!call
            .argv
            .contains(&scope.join("bin/node").display().to_string()));
    }
}
#[test]
fn root_runtime_commands_drop_to_the_enrolled_account_before_changing_directory_and_scope() {
    let account = Account {
        name: "orbit".into(),
        uid: rustix::process::geteuid().as_raw() + 1,
        gid: 1000,
        home: "/home/orbit".into(),
    };
    let call = account.invocation(
        Path::new("/home/orbit/.vite-plus"),
        &["/home/orbit/.vite-plus/bin/vp", "--version"],
    );
    assert_eq!(
        &call.argv[..8],
        &[
            "sudo",
            "-u",
            "orbit",
            "-H",
            "env",
            "-C",
            "/home/orbit",
            "VP_HOME=/home/orbit/.vite-plus"
        ]
    );
}

fn archive(path: &Path, entries: &[(&str, &[u8])]) {
    let gzip = flate2::write::GzEncoder::new(
        fs::File::create(path).unwrap(),
        flate2::Compression::default(),
    );
    let mut tar = tar::Builder::new(gzip);
    for (name, bytes) in entries {
        let mut header = tar::Header::new_gnu();
        header.set_size(bytes.len() as u64);
        header.set_mode(0o755);
        header.set_cksum();
        tar.append_data(&mut header, name, *bytes).unwrap();
    }
    tar.into_inner().unwrap().finish().unwrap();
}

#[test]
fn native_pnpm_and_pinned_dispatcher_publish_without_running_bootstrap_installers() {
    for name in ["pnpm", "vp-cli"] {
        let (work, files, account, scope, mut input) = fixture();
        let mut pin = input.tools["node"].clone();
        pin.version = "1.2.0".into();
        input.tools = [(name.into(), pin)].into();
        let path = work.0.join("runtime.archive");
        let binary = b"\x7fELF-pinned-executable";
        let entries: Vec<(&str, &[u8])> = if name == "pnpm" {
            vec![
                ("package/pnpm", binary),
                ("package/package.json", br#"{"version":"1.2.0"}"#),
            ]
        } else {
            vec![("vp", binary)]
        };
        archive(&path, &entries);
        let mut host = Fake::default();
        install(
            &mut host,
            &files,
            &input,
            &[(name.into(), path)].into(),
            &work.0,
            &account,
            Instant::now() + Duration::from_secs(10),
        )
        .unwrap();
        let executable = scope
            .join("bin")
            .join(if name == "vp-cli" { "vp" } else { "pnpm" });
        assert_eq!(fs::read(&executable).unwrap(), binary);
        assert_eq!(fs::metadata(executable).unwrap().mode() & 0o777, 0o755);
        assert!(host.calls.is_empty());
        if name == "vp-cli" {
            assert!(scope.join("bin/.vp-setup-complete").is_file());
        } else {
            assert!(scope.join("recipe/pnpm-current").is_symlink());
        }
    }
}

#[test]
fn invalid_pnpm_archive_does_not_activate_a_package_or_replace_the_existing_executable() {
    for metadata in [
        br#"{"version":"wrong"}"#.as_slice(),
        br#"{"version":"1.2.0"}"#,
    ] {
        let (work, files, account, scope, mut input) = fixture();
        let mut pin = input.tools["node"].clone();
        pin.version = "1.2.0".into();
        input.tools = [("pnpm".into(), pin)].into();
        fs::write(scope.join("bin/pnpm"), "existing executable").unwrap();
        let path = work.0.join("runtime.archive");
        archive(&path, &[("package/package.json", metadata)]);
        let mut host = Fake::default();
        assert!(install(
            &mut host,
            &files,
            &input,
            &[("pnpm".into(), path)].into(),
            &work.0,
            &account,
            Instant::now() + Duration::from_secs(10)
        )
        .is_err());
        assert!(!scope.join("recipe/pnpm-current").is_symlink());
        assert_eq!(
            fs::read_to_string(scope.join("bin/pnpm")).unwrap(),
            "existing executable"
        );
        assert!(host.calls.is_empty());
    }
}
#[test]
fn pnpm_metadata_is_current_only_when_the_account_launcher_executes_that_version() {
    struct HostWithRealLauncher<'a>(&'a crate::command::Context);
    impl Host for HostWithRealLauncher<'_> {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            if call.argv == ["dpkg", "--print-architecture"] {
                return Ok(Output {
                    stdout: "amd64".into(),
                    ..Default::default()
                });
            }
            if call.argv[0] == "dpkg-query" {
                return Ok(Output::default());
            }
            super::super::Native(self.0).execute(call)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No fetch during inspection")
        }
    }
    let (_work, files, account, scope, mut input) = fixture();
    input.tools = [("pnpm".into(), input.tools["node"].clone())].into();
    fs::create_dir_all(scope.join("recipe/pnpm-current")).unwrap();
    fs::write(
        scope.join("recipe/pnpm-current/package.json"),
        r#"{"version":"10.0.0"}"#,
    )
    .unwrap();
    let context =
        crate::command::Context::new(Platform::Linux, Instant::now() + Duration::from_secs(10));
    for (launcher, expected) in [
        ("#!/bin/sh\nexit 127\n", None),
        ("#!/bin/sh\nprintf '9.0.0\\n'\n", None),
        ("#!/bin/sh\nprintf '10.0.0\\n'\n", Some("10.0.0")),
    ] {
        crate::test_support::executable(scope.join("bin/pnpm"), launcher);
        let observed = inspect(
            &mut HostWithRealLauncher(&context),
            &files,
            &input.tools,
            Some(&account),
        )
        .unwrap();
        assert_eq!(observed["pnpm"].as_deref(), expected);
    }
}
