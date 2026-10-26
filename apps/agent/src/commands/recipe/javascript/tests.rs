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
        fs::write(scope.join("bin/pnpm"), launcher).unwrap();
        fs::set_permissions(scope.join("bin/pnpm"), fs::Permissions::from_mode(0o755)).unwrap();
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

#[test]
#[ignore = "Downloads the pinned JavaScript recipe; run on the disposable SPIKE topology"]
fn real_pinned_dispatcher_runs_projects_and_preserves_existing_global_content() {
    let (work, files, account, scope, mut input) = fixture();
    let recipe: Value = serde_json::from_str(include_str!(
        "../../../../../gateway/resources/machine-software/recipe.json"
    ))
    .unwrap();
    input.snapshot = recipe["ubuntu"]["snapshot"].as_str().unwrap().into();
    input.artifact_mirror = recipe["artifact_mirror"]
        .as_str()
        .unwrap_or_default()
        .into();
    input.tools = ["node", "vp-cli", "vp", "pnpm"]
        .into_iter()
        .map(|name| {
            (
                name.into(),
                serde_json::from_value(recipe["tools"][name].clone()).unwrap(),
            )
        })
        .collect();
    input.vp_lock = Some(
        include_str!(
            "../../../../../gateway/resources/machine-software/vite-plus-package-lock.json"
        )
        .into(),
    );
    input.architecture = if cfg!(target_arch = "aarch64") {
        super::super::types::Architecture::Arm64
    } else {
        super::super::types::Architecture::Amd64
    };
    let old = scope.join("old/node_modules/vite-plus");
    fs::create_dir_all(&old).unwrap();
    fs::write(
        old.join("package.json"),
        r#"{"version":"1.0.0","dependencies":{"missing":"1.0.0"}}"#,
    )
    .unwrap();
    fs::create_dir(scope.join("node_modules")).unwrap();
    std::os::unix::fs::symlink(&old, scope.join("node_modules/vite-plus")).unwrap();
    fs::create_dir(scope.join("global")).unwrap();
    fs::write(scope.join("global/keep"), "managed globals").unwrap();
    let context = crate::command::Context::new(
        crate::command::Platform::Linux,
        Instant::now() + Duration::from_secs(900),
    );
    let mut host = super::super::Native(&context);
    let mut downloads = BTreeMap::new();
    for (name, pin) in input.tools.iter().filter(|(name, _)| *name != "vp") {
        let path = work.0.join(format!("{name}.archive"));
        artifacts::download(&mut host, name, pin, &input, &path).unwrap();
        downloads.insert(name.clone(), path);
    }
    install(
        &mut host,
        &files,
        &input,
        &downloads,
        &work.0,
        &account,
        context.deadline(),
    )
    .unwrap();
    // Publish deploy prerequisites before Vite+ uses the store, as the Gateway does.
    use crate::command::Command;
    use crate::commands::vp_store;
    let npm = vp_store::Environment {
        store: scope.clone(),
        node_version: input.tools["node"].version.clone(),
        package_manager: "npm".into(),
        package_manager_version: "11.12.0".into(),
        package_manager_hash: None,
    };
    let pnpm = vp_store::Environment {
        package_manager: "pnpm".into(),
        package_manager_version: input.tools["pnpm"].version.clone(),
        ..npm.clone()
    };
    for environment in [&npm, &pnpm] {
        let result = vp_store::Install::run(
            vp_store::InstallInput {
                environment: environment.clone(),
                npmrc: String::new(),
            },
            &context,
        );
        result.unwrap();
    }
    let project = work.0.join("project");
    fs::create_dir(&project).unwrap();
    fs::write(project.join("package.json"), serde_json::to_vec(&json!({"name":"recipe-dispatch-test","version":"1.0.0","packageManager":format!("pnpm@{}", input.tools["pnpm"].version),"scripts":{"proof":"node -e 'console.log(42)'"}})).unwrap()).unwrap();
    let global = work.0.join("global-package");
    fs::create_dir(&global).unwrap();
    fs::write(global.join("package.json"), r#"{"name":"recipe-global-proof","version":"1.0.0","bin":{"recipe-global-proof":"index.js"}}"#).unwrap();
    fs::write(
        global.join("index.js"),
        "#!/usr/bin/env node\nconsole.log(84)\n",
    )
    .unwrap();
    fs::set_permissions(global.join("index.js"), fs::Permissions::from_mode(0o755)).unwrap();
    let global = global.to_str().unwrap();
    for (argv, expected) in [
        (
            vec!["vp", "--version"],
            input.tools["vp-cli"].version.as_str(),
        ),
        (
            vec!["node", "--version"],
            input.tools["node"].version.as_str(),
        ),
        (
            vec!["pnpm", "--version"],
            input.tools["pnpm"].version.as_str(),
        ),
        (vec!["vp", "install"], ""),
        (vec!["vp", "run", "proof"], "42"),
        (vec!["vp", "install", "-g", global], ""),
        (vec!["recipe-global-proof"], "84"),
    ] {
        let mut invocation = Invocation::new(&["env", "-C", project.to_str().unwrap()]);
        invocation.argv.extend(argv.iter().map(|v| (*v).into()));
        invocation = invocation
            .env("HOME", account.home.to_str().unwrap())
            .env("VP_HOME", scope.to_str().unwrap())
            .env(
                "PATH",
                &format!("{}/bin:/usr/local/bin:/usr/bin:/bin", scope.display()),
            );
        let output = run(&mut host, &invocation, true).unwrap();
        assert!(
            output.stdout.contains(expected),
            "{argv:?}: {}",
            output.stdout
        );
    }
    assert_eq!(
        fs::read_to_string(scope.join("global/keep")).unwrap(),
        "managed globals"
    );
    assert!(fs::read_to_string(old.join("package.json"))
        .unwrap()
        .contains("1.0.0"));

    // A production app reads exact deployment runtimes as another account. Neither Vite+
    // dispatch nor an adoption fast path may change the enrolled account's store.
    fs::set_permissions(&work.0, fs::Permissions::from_mode(0o755)).unwrap();
    fn snapshot(scope: &Path) -> BTreeMap<PathBuf, (i64, i64, String)> {
        artifacts::contents(scope)
            .unwrap()
            .into_iter()
            .map(|relative| {
                let path = scope.join(&relative);
                let meta = fs::symlink_metadata(&path).unwrap();
                let content = if meta.is_symlink() {
                    fs::read_link(&path).unwrap().display().to_string()
                } else if meta.is_file() {
                    crate::command::run::file_sha256(&path).unwrap()
                } else {
                    String::new()
                };
                (relative, (meta.mtime(), meta.mtime_nsec(), content))
            })
            .collect()
    }
    let before = snapshot(&scope);
    let held = store::lock(&scope, &account, context.deadline()).unwrap();
    assert!(vp_store::Install::run(
        vp_store::InstallInput {
            environment: pnpm.clone(),
            npmrc: String::new()
        },
        &context
    )
    .unwrap()
    .verified
    .is_empty());
    drop(held);
    let app = work.0.join("app");
    fs::create_dir(&app).unwrap();
    fs::write(app.join("package.json"), r#"{"name":"vp-readonly-contract","version":"1.0.0","packageManager":"npm@11.12.0","scripts":{"build":"node -e 'console.log(42)'"}}"#).unwrap();
    fs::write(app.join("package-lock.json"), r#"{"name":"vp-readonly-contract","version":"1.0.0","lockfileVersion":3,"packages":{"":{"name":"vp-readonly-contract","version":"1.0.0"}}}"#).unwrap();
    let pnpm_app = work.0.join("pnpm-app");
    fs::create_dir(&pnpm_app).unwrap();
    fs::write(
        pnpm_app.join("package.json"),
        serde_json::to_vec(&json!({
            "name": "pnpm-readonly-contract",
            "version": "1.0.0",
            "packageManager": format!("pnpm@{}", pnpm.package_manager_version)
        }))
        .unwrap(),
    )
    .unwrap();
    let path = format!("PATH={}/bin:/usr/local/bin:/usr/bin:/bin", scope.display());
    let vp_home = format!("VP_HOME={}", scope.display());
    run(
        &mut host,
        &Invocation::new(&[
            "sudo",
            "-n",
            "chown",
            "-R",
            "nobody",
            app.to_str().unwrap(),
            pnpm_app.to_str().unwrap(),
        ]),
        true,
    )
    .unwrap();
    let execute = |host: &mut super::super::Native<'_>,
                   directory: &Path,
                   selector: &str,
                   command: &[&str]| {
        let home = format!("HOME={}", directory.display());
        let mut invocation = Invocation::new(&[
            "sudo",
            "-n",
            "-u",
            "nobody",
            "env",
            "-C",
            directory.to_str().unwrap(),
            &home,
            &vp_home,
            &path,
            scope.join("bin/vp").to_str().unwrap(),
            "env",
            "exec",
            "--node",
            &npm.node_version,
            "--package-manager",
            selector,
        ]);
        invocation.argv.extend(command.iter().map(|v| (*v).into()));
        run(host, &invocation, true)
    };
    let built = execute(&mut host, &app, "npm@11.12.0", &["bash", "-eu", "-c", "node --version; npm --version; test ! -w \"$VP_HOME\"; vp install --frozen-lockfile; vp run build"]);
    let dispatched = execute(
        &mut host,
        &pnpm_app,
        &format!("pnpm@{}", pnpm.package_manager_version),
        &["pnpm", "--version"],
    );
    run(
        &mut host,
        &Invocation::new(&[
            "sudo",
            "-n",
            "chown",
            "-R",
            &format!("{}:{}", account.uid, account.gid),
            app.to_str().unwrap(),
            pnpm_app.to_str().unwrap(),
        ]),
        true,
    )
    .unwrap();
    let built = built.unwrap();
    assert!(built.stdout.contains("42"));
    assert_eq!(
        dispatched.unwrap().stdout.trim(),
        pnpm.package_manager_version
    );
    assert_eq!(
        snapshot(&scope),
        before,
        "Application execution or adoption changed the shared store"
    );
}
