use super::super::recipe::WorkDir;
use super::*;
use base64::{engine::general_purpose::STANDARD, Engine};
use sha2::{Digest, Sha256, Sha512};
use std::os::unix::fs::symlink;

fn fixture() -> (WorkDir, Account, Environment) {
    let work = WorkDir::new().unwrap();
    let account = account().unwrap();
    let env = Environment {
        store: work.0.clone(),
        node_version: "24.22.0".into(),
        package_manager: "npm".into(),
        package_manager_version: "11.12.0".into(),
        package_manager_hash: None,
    };
    (work, account, env)
}
fn deadline() -> Instant {
    Instant::now() + Duration::from_secs(5)
}
fn input(env: &Environment) -> InstallInput {
    InstallInput {
        environment: env.clone(),
        npmrc: "registry=https://private.example/\n//private.example/:_authToken=secret".into(),
    }
}
fn seed(env: &Environment) {
    let node = env
        .store
        .join("js_runtime/node")
        .join(&env.node_version)
        .join("bin/node");
    fs::create_dir_all(node.parent().unwrap()).unwrap();
    fs::write(&node, "node fixture").unwrap();
    fs::set_permissions(&node, fs::Permissions::from_mode(0o755)).unwrap();
    seed_manager(env);
}
fn seed_manager(env: &Environment) {
    let package = env
        .store
        .join("package_manager")
        .join(&env.package_manager)
        .join(&env.package_manager_version)
        .join(&env.package_manager);
    fs::create_dir_all(package.join("bin")).unwrap();
    fs::write(
        package.join("package.json"),
        serde_json::to_vec(
            &serde_json::json!({"name":env.package_manager,"version":env.package_manager_version}),
        )
        .unwrap(),
    )
    .unwrap();
    let launcher = package.join("bin").join(&env.package_manager);
    fs::write(&launcher, "manager fixture").unwrap();
    fs::set_permissions(launcher, fs::Permissions::from_mode(0o755)).unwrap();
}
fn archive(files: &[(&str, &[u8])]) -> Vec<u8> {
    let gzip = flate2::write::GzEncoder::new(vec![], flate2::Compression::fast());
    let mut tar = tar::Builder::new(gzip);
    for (name, bytes) in files {
        let mut header = tar::Header::new_gnu();
        header.set_size(bytes.len() as u64);
        header.set_mode(0o755);
        header.set_cksum();
        tar.append_data(&mut header, name, *bytes).unwrap();
    }
    tar.into_inner().unwrap().finish().unwrap()
}
struct Download {
    node: Vec<u8>,
    manager: Vec<u8>,
    source: String,
    env: Environment,
    calls: Vec<String>,
    failure: Option<&'static str>,
}
impl Download {
    fn new(env: &Environment) -> Self {
        let system = if cfg!(target_os = "macos") {
            "darwin"
        } else {
            "linux"
        };
        let arch = if cfg!(target_arch = "aarch64") {
            "arm64"
        } else {
            "x64"
        };
        let source = format!("node-v{}-{system}-{arch}", env.node_version);
        let manifest = serde_json::to_vec(&serde_json::json!({"name":env.package_manager,"version":env.package_manager_version,"bin":{&env.package_manager:"bin/cli.js"}})).unwrap();
        Self {
            node: archive(&[(&format!("{source}/bin/node"), b"node fixture")]),
            manager: archive(&[
                ("package/package.json", &manifest),
                (
                    "package/bin/cli.js",
                    b"#!/usr/bin/env node\nconsole.log('launcher works');\n",
                ),
            ]),
            source,
            env: env.clone(),
            calls: vec![],
            failure: None,
        }
    }
}
impl downloads::Download for Download {
    fn get(&mut self, url: &str, _: &BTreeMap<String, String>) -> Result<Vec<u8>> {
        self.calls.push(url.into());
        if url.ends_with("SHASUMS256.txt") {
            return Ok(
                format!("{:x}  {}.tar.gz\n", Sha256::digest(&self.node), self.source).into_bytes(),
            );
        }
        if url.ends_with(".tar.gz") {
            return Ok(if self.failure == Some("node") {
                b"corrupt".to_vec()
            } else {
                self.node.clone()
            });
        }
        if self.failure == Some("registry") {
            return Err(Error::Failed {});
        }
        if url.ends_with(".tgz") {
            return Ok(if self.failure == Some("integrity") {
                b"corrupt".to_vec()
            } else {
                self.manager.clone()
            });
        }
        Ok(serde_json::to_vec(&serde_json::json!({"name":self.env.package_manager,"version":self.env.package_manager_version,"dist":{"integrity":format!("sha512-{}", STANDARD.encode(Sha512::digest(&self.manager))),"tarball":"https://private.example/runtime.tgz"}})).unwrap())
    }
}

#[test]
fn exact_existing_content_is_read_only_even_with_the_real_store_lock_held() {
    let (_work, account, env) = fixture();
    seed(&env);
    let mut download = Download::new(&env);
    for _ in 0..2 {
        assert_eq!(
            inspect(
                &InspectInput {
                    environments: vec![env.clone()]
                },
                &account
            )
            .unwrap()
            .installed,
            [true]
        );
        assert!(install(&input(&env), &account, &mut download, deadline())
            .unwrap()
            .verified
            .is_empty());
    }
    assert!(!env.store.join(".orbit-store.lock").exists());
    let _lock = store::lock(&env.store, &account, deadline()).unwrap();
    assert!(
        install(&input(&env), &account, &mut download, Instant::now())
            .unwrap()
            .verified
            .is_empty()
    );
    assert!(download.calls.is_empty());
    assert!(!env.store.join(".orbit-runtimes.json").exists());
}
#[test]
fn missing_content_times_out_on_the_shared_recipe_lock_before_publication() {
    let (_work, account, env) = fixture();
    let _lock = store::lock(&env.store, &account, deadline()).unwrap();
    let mut download = Download::new(&env);
    assert!(matches!(
        install(&input(&env), &account, &mut download, Instant::now()),
        Err(Error::Busy {})
    ));
    assert!(download.calls.is_empty());
    assert!(!env.store.join("js_runtime").exists());
}
#[test]
fn waiting_installer_rechecks_content_after_the_other_publisher_finishes() {
    let (_work, account, env) = fixture();
    let lock = store::lock(&env.store, &account, deadline()).unwrap();
    let waiting = env.clone();
    let worker = std::thread::spawn(move || {
        let mut download = Download::new(&waiting);
        let result = install(
            &input(&waiting),
            &super::account().unwrap(),
            &mut download,
            deadline(),
        )
        .unwrap();
        assert!(download.calls.is_empty());
        assert!(result.verified.is_empty());
    });
    std::thread::sleep(Duration::from_millis(100));
    seed(&env);
    drop(lock);
    worker.join().unwrap();
}
#[test]
fn verified_archives_publish_side_by_side_with_executable_native_links_and_receipts() {
    for manager in ["npm", "pnpm"] {
        let (_work, account, mut env) = fixture();
        env.package_manager = manager.into();
        let old = Environment {
            node_version: "22.18.0".into(),
            package_manager_version: "10.9.0".into(),
            ..env.clone()
        };
        seed(&old);
        let mut download = Download::new(&env);
        let result = install(&input(&env), &account, &mut download, deadline()).unwrap();
        assert_eq!(result.verified.len(), 2);
        assert!(installed(&env, &account).unwrap());
        assert!(installed(&old, &account).unwrap());
        let lock = env.store.join(".orbit-store.lock");
        let inode = fs::metadata(&lock).unwrap().ino();
        assert_eq!(fs::metadata(&lock).unwrap().mode() & 0o777, 0o644);
        assert_eq!(
            fs::metadata(env.store.join(".orbit-runtimes.json"))
                .unwrap()
                .mode()
                & 0o777,
            0o644
        );
        let launcher = env
            .paths(&account)
            .unwrap()
            .1
            .join(manager)
            .join("bin")
            .join(manager);
        assert!(launcher.is_symlink());
        let output = std::process::Command::new(&launcher).output().unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert_eq!(output.stdout, b"launcher works\n");
        let calls = download.calls.clone();
        assert!(install(&input(&env), &account, &mut download, deadline())
            .unwrap()
            .verified
            .is_empty());
        assert_eq!(download.calls, calls);
        assert_eq!(fs::metadata(lock).unwrap().ino(), inode);
    }
}
#[test]
fn registry_and_integrity_failure_preserve_the_node_receipt_across_retry_and_collection() {
    for manager in ["npm", "pnpm"] {
        for failure in ["registry", "integrity"] {
            let (_work, account, mut env) = fixture();
            env.package_manager = manager.into();
            let mut download = Download::new(&env);
            download.failure = Some(failure);
            assert!(install(&input(&env), &account, &mut download, deadline()).is_err());
            let receipt = ledger(&env.store, &account).unwrap();
            assert_eq!(receipt.len(), 1);
            assert!(receipt.contains_key("js_runtime/node/24.22.0"));
            assert!(!installed(&env, &account).unwrap());
            download.failure = None;
            install(&input(&env), &account, &mut download, deadline()).unwrap();
            assert_eq!(
                download
                    .calls
                    .iter()
                    .filter(|url| url.starts_with("https://nodejs.org/"))
                    .count(),
                2
            );
            let collected = prune(
                &PruneInput {
                    store: env.store.clone(),
                    references: vec![],
                },
                &account,
                deadline(),
            )
            .unwrap();
            assert_eq!(collected.removed.len(), 2);
            assert!(ledger(&env.store, &account).unwrap().is_empty());
            assert!(prune(
                &PruneInput {
                    store: env.store.clone(),
                    references: vec![]
                },
                &account,
                deadline()
            )
            .unwrap()
            .removed
            .is_empty());
        }
    }
}
#[test]
fn checksum_failure_and_incomplete_content_refuse_without_repairing_it() {
    let (_work, account, env) = fixture();
    let mut download = Download::new(&env);
    download.failure = Some("node");
    assert!(install(&input(&env), &account, &mut download, deadline()).is_err());
    assert!(!env.store.join("js_runtime").exists());
    let node = env.paths(&account).unwrap().0;
    fs::create_dir_all(&node).unwrap();
    fs::write(node.join("keep"), "old").unwrap();
    let before = download.calls.len();
    assert!(install(&input(&env), &account, &mut download, deadline()).is_err());
    assert_eq!(download.calls.len(), before);
    assert_eq!(fs::read(node.join("keep")).unwrap(), b"old");
}

#[test]
fn existing_package_manager_without_a_manifest_refuses_before_download_or_replacement() {
    let (_work, account, env) = fixture();
    seed(&env);
    let manager = env.paths(&account).unwrap().1;
    fs::remove_file(manager.join("npm/package.json")).unwrap();
    let inode = fs::metadata(&manager).unwrap().ino();
    let mut download = Download::new(&env);
    assert!(matches!(
        install(&input(&env), &account, &mut download, deadline()),
        Err(Error::Invalid {})
    ));
    assert!(download.calls.is_empty());
    assert_eq!(fs::metadata(&manager).unwrap().ino(), inode);
    assert!(manager.join("npm/bin/npm").exists());
}

#[test]
fn native_pnpm_is_published_only_after_both_archives_match_without_running_install_hooks() {
    struct NativeDownload {
        base: Download,
        name: String,
        archive: Vec<u8>,
        corrupt: bool,
    }
    impl downloads::Download for NativeDownload {
        fn get(&mut self, url: &str, config: &BTreeMap<String, String>) -> Result<Vec<u8>> {
            if url.ends_with("native.tgz") {
                return Ok(if self.corrupt {
                    b"corrupt".to_vec()
                } else {
                    self.archive.clone()
                });
            }
            if url.contains("@pnpm/exe.") {
                return Ok(serde_json::to_vec(&serde_json::json!({"name":self.name,"version":self.base.env.package_manager_version,"dist":{"integrity":format!("sha512-{}", STANDARD.encode(Sha512::digest(&self.archive))),"tarball":"https://private.example/native.tgz"}})).unwrap());
            }
            self.base.get(url, config)
        }
    }
    for defect in [
        "none",
        "native-integrity",
        "project-integrity",
        "native-version",
    ] {
        let (_work, account, mut env) = fixture();
        env.package_manager = "pnpm".into();
        env.package_manager_version = "12.11.2".into();
        let system = if cfg!(target_os = "macos") {
            "darwin"
        } else {
            "linux"
        };
        let arch = if cfg!(target_arch = "aarch64") {
            "arm64"
        } else {
            "x64"
        };
        let name = format!("@pnpm/exe.{system}-{arch}");
        let binary = fs::read("/usr/bin/true").unwrap();
        let metadata = serde_json::to_vec(&serde_json::json!({"name":name,"version":if defect == "native-version" { "0.0.0" } else { &env.package_manager_version }})).unwrap();
        let native = archive(&[
            ("package/package.json", &metadata),
            ("package/pnpm", &binary),
        ]);
        let manifest = serde_json::to_vec(&serde_json::json!({"name":"pnpm","version":env.package_manager_version,"bin":{"pnpm":"pnpm","pnpx":"pnpx"},"optionalDependencies":{&name:env.package_manager_version},"scripts":{"install":"touch hook-ran"}})).unwrap();
        let mut download = NativeDownload {
            base: Download::new(&env),
            name,
            archive: native,
            corrupt: defect == "native-integrity",
        };
        download.base.manager = archive(&[
            ("package/package.json", &manifest),
            ("package/pnpm", b"unexecuted placeholder"),
            ("package/pnpx", b"unexecuted placeholder"),
        ]);
        env.package_manager_hash = Some(format!(
            "sha256.{}",
            if defect == "project-integrity" {
                "0".repeat(64)
            } else {
                format!("{:x}", Sha256::digest(&download.base.manager))
            }
        ));
        let result = install(&input(&env), &account, &mut download, deadline());
        let package = env.paths(&account).unwrap().1.join("pnpm");
        if defect == "none" {
            let result = result.unwrap();
            assert!(result.verified["package_manager/pnpm/12.11.2"].contains("@pnpm/exe."));
            assert_eq!(fs::read(package.join("pnpm")).unwrap(), binary);
            for command in ["pnpm", "pnpx"] {
                assert!(
                    std::process::Command::new(package.join("bin").join(command))
                        .status()
                        .unwrap()
                        .success()
                );
            }
            assert!(install(&input(&env), &account, &mut download, deadline())
                .unwrap()
                .verified
                .is_empty());
        } else {
            assert!(result.is_err());
            assert!(!package.parent().unwrap().exists());
            assert_eq!(ledger(&env.store, &account).unwrap().len(), 1);
        }
        assert!(!env.store.join("hook-ran").exists());
    }
}
#[test]
fn symlinked_locks_and_writable_or_symlinked_store_paths_refuse() {
    let (_work, account, env) = fixture();
    let outside = env.store.join("keep");
    fs::write(&outside, "unchanged").unwrap();
    symlink(&outside, env.store.join(".orbit-store.lock")).unwrap();
    assert!(install(&input(&env), &account, &mut Download::new(&env), deadline()).is_err());
    assert!(prune(
        &PruneInput {
            store: env.store.clone(),
            references: vec![]
        },
        &account,
        deadline()
    )
    .is_err());
    assert_eq!(fs::read(&outside).unwrap(), b"unchanged");
    fs::set_permissions(&env.store, fs::Permissions::from_mode(0o775)).unwrap();
    assert!(env.paths(&account).is_err());
    fs::set_permissions(&env.store, fs::Permissions::from_mode(0o755)).unwrap();
    symlink("/tmp", env.store.join("js_runtime")).unwrap();
    assert!(env.paths(&account).is_err());
}
#[test]
fn pruning_keeps_adopted_content_and_all_retained_references_and_refuses_foreign_paths_before_deletion(
) {
    let (_work, account, env) = fixture();
    let mut download = Download::new(&env);
    install(&input(&env), &account, &mut download, deadline()).unwrap();
    let adopted = Environment {
        node_version: "22.18.0".into(),
        ..env.clone()
    };
    seed(&adopted);
    let retained = PruneInput {
        store: env.store.clone(),
        references: vec![env.clone(), env.clone()],
    };
    assert!(prune(&retained, &account, deadline())
        .unwrap()
        .removed
        .is_empty());
    let mut receipts = ledger(&env.store, &account).unwrap();
    receipts.insert("js_runtime/node/26.0.0".into(), "old".into());
    symlink(
        &adopted.paths(&account).unwrap().0,
        env.store.join("js_runtime/node/26.0.0"),
    )
    .unwrap();
    write_ledger(&env.store, &receipts, &account).unwrap();
    let empty = PruneInput {
        store: env.store.clone(),
        references: vec![],
    };
    assert!(prune(&empty, &account, deadline()).is_err());
    assert!(installed(&env, &account).unwrap());
    fs::remove_file(env.store.join("js_runtime/node/26.0.0")).unwrap();
    assert_eq!(
        prune(&empty, &account, deadline()).unwrap().removed.len(),
        3
    );
    assert!(adopted.paths(&account).unwrap().0.exists());
}
#[test]
fn recipe_publication_is_deployable_and_protects_adopted_deploy_content_until_its_reference_moves()
{
    for layout in ["production", ".vite-plus", ".local/share/vite-plus"] {
        let (_work, account, mut env) = fixture();
        env.store = env.store.join(layout);
        fs::create_dir_all(&env.store).unwrap();
        let mut download = Download::new(&env);
        install(&input(&env), &account, &mut download, deadline()).unwrap();
        let receipt = ledger(&env.store, &account).unwrap();
        let node = env.paths(&account).unwrap().0;
        let inode = fs::metadata(&node).unwrap().ino();
        let archive = env.store.join("node.archive");
        fs::write(&archive, &download.node).unwrap();
        let digest = format!("{:x}", Sha256::digest(&download.node));
        for _ in 0..2 {
            store::publish_node(
                &Files::system(),
                &env.store,
                &account,
                &env.node_version,
                &archive,
                &digest,
                deadline(),
            )
            .unwrap();
            assert!(installed(&env, &account).unwrap());
            assert!(install(&input(&env), &account, &mut download, deadline())
                .unwrap()
                .verified
                .is_empty());
            assert_eq!(fs::metadata(&node).unwrap().ino(), inode);
            assert_eq!(ledger(&env.store, &account).unwrap(), receipt);
        }
        let empty = PruneInput {
            store: env.store.clone(),
            references: vec![],
        };
        assert_eq!(
            prune(&empty, &account, deadline()).unwrap().removed,
            [format!(
                "package_manager/npm/{}",
                env.package_manager_version
            )]
        );
        assert!(node.exists());
        fs::write(env.store.join(store::REFERENCE), r#"{"version":"26.0.0"}"#).unwrap();
        assert_eq!(
            prune(&empty, &account, deadline()).unwrap().removed,
            [format!("js_runtime/node/{}", env.node_version)]
        );
        assert!(!node.exists());
    }
}
#[test]
fn all_historical_recipe_links_migrate_and_older_adopted_versions_survive_collection() {
    let (_work, account, env) = fixture();
    let other = Environment {
        node_version: "22.18.0".into(),
        ..env.clone()
    };
    let mut latest_archive = None;
    for version in [&env, &other] {
        let download = Download::new(version);
        let digest = format!("{:x}", Sha256::digest(&download.node));
        let old = env.store.join("recipe").join(format!("node-{digest}"));
        fs::create_dir_all(&old).unwrap();
        let archive = old.join("node.archive");
        fs::write(&archive, &download.node).unwrap();
        let extracted = old.join("unpack");
        artifacts::extract(&archive, &extracted).unwrap();
        let target = old.join(&download.source);
        fs::rename(extracted.join(&download.source), &target).unwrap();
        let runtime = env
            .store
            .join("js_runtime/node")
            .join(&version.node_version);
        fs::create_dir_all(runtime.parent().unwrap()).unwrap();
        symlink(&target, &runtime).unwrap();
        if version.node_version == env.node_version {
            latest_archive = Some((archive, digest));
        }
    }
    let (archive, digest) = latest_archive.unwrap();
    store::publish_node(
        &Files::system(),
        &env.store,
        &account,
        &env.node_version,
        &archive,
        &digest,
        deadline(),
    )
    .unwrap();
    seed_manager(&env);
    assert!(installed(&env, &account).unwrap());
    let empty = PruneInput {
        store: env.store.clone(),
        references: vec![],
    };
    assert!(prune(&empty, &account, deadline())
        .unwrap()
        .removed
        .is_empty());
    for version in [&env, &other] {
        let node = version.paths(&account).unwrap().0;
        assert!(node.is_dir());
        assert!(!node.is_symlink());
    }
}
#[test]
fn unknown_fields_and_unsupported_versions_refuse_before_store_mutation() {
    let (_work, account, env) = fixture();
    let mut value = serde_json::to_value(&env).unwrap();
    value["script"] = Value::String("ignored?".into());
    assert!(serde_json::from_value::<Environment>(value).is_err());
    for value in [
        Environment {
            node_version: "../../outside".into(),
            ..env.clone()
        },
        Environment {
            package_manager: "yarn".into(),
            ..env.clone()
        },
        Environment {
            package_manager_hash: Some("sha1.bad".into()),
            ..env.clone()
        },
    ] {
        assert!(matches!(
            install(
                &input(&value),
                &account,
                &mut Download::new(&env),
                deadline()
            ),
            Err(Error::Invalid {})
        ));
    }
    assert!(!env.store.join(".orbit-store.lock").exists());
}

#[cfg(target_os = "linux")]
mod deployment {
    use super::*;
    use crate::commands::deploy_step::{run, DeployStepError, RunDeployStepInput};

    use crate::test_support::executable;

    fn deployment() -> (WorkDir, RunDeployStepInput) {
        let (work, account, mut env) = fixture();
        env.store = work.0.join("shared store");
        fs::create_dir(&env.store).unwrap();
        seed(&env);
        fs::create_dir(env.store.join("bin")).unwrap();
        executable(
            env.store.join("bin/vp"),
            r#"#!/bin/sh
set -eu
test "$1" = env && test "$2" = exec
test "$3" = --node && test "$4" = 24.22.0
test "$5" = --package-manager && test "$6" = npm@11.12.0
shift 6
export PATH="$VP_HOME/bin:$PATH"
exec "$@"
"#,
        );
        executable(
            env.store.join("bin/npm"),
            r#"#!/bin/sh
set -eu
printf '<%s>' "$@" >> build.log
printf '\n' >> build.log
if [ "$2" = build:ssr ]; then
    : > ssr.js
fi
"#,
        );
        let home = work.0.join(&account.name);
        fs::create_dir_all(home.join("releases/r1")).unwrap();
        let input = RunDeployStepInput {
            user: account.name,
            home,
            release: "r1".into(),
            script: String::new(),
            vp_environment: Some(env),
            operation: None,
            step: "npm-build".into(),
            timeout_seconds: 5,
            secrets: vec![],
        };
        (work, input)
    }

    #[test]
    fn runs_the_whole_project_script_with_quoted_flags_and_ssr_on_repeated_execution() {
        for base in ["/customer/", "/customer site/"] {
            let (work, mut input) = deployment();
            input.script = format!(
                "npm run build -- --base=\"{base}\" && npm run build:ssr\npwd\numask\nid -u\n"
            );
            let release = input.home.join("releases/r1");
            for attempt in 1..=2 {
                let output = run(&input, &work.0, None).unwrap();
                assert_eq!(output.exit_code, 0, "{:?}", output.output);
                assert_eq!(
                    fs::read_to_string(release.join("build.log")).unwrap(),
                    format!("<run><build><--><--base={base}>\n<run><build:ssr>\n").repeat(attempt)
                );
                assert!(release.join("ssr.js").is_file());
                let stdout: String = output
                    .output
                    .iter()
                    .filter(|chunk| chunk.stream == "stdout")
                    .map(|chunk| chunk.value.as_str())
                    .collect();
                assert_eq!(
                    stdout,
                    format!(
                        "{}\n0077\n{}\n",
                        release.display(),
                        rustix::process::geteuid().as_raw()
                    )
                );
            }
        }
    }

    #[test]
    #[ignore = "invoked as root by the private-store test"]
    fn private_store_as_root() {
        let path = PathBuf::from(std::env::var_os("ORBIT_TEST_VP_INPUT").unwrap());
        let input: RunDeployStepInput = serde_json::from_slice(&fs::read(&path).unwrap()).unwrap();
        let output = run(&input, path.parent().unwrap(), None).unwrap();
        assert_eq!(output.exit_code, 0, "{:?}", output.output);
        assert!(output.output.iter().any(|chunk| chunk.value == "nobody\n"));
    }

    #[test]
    fn production_gets_only_passage_through_a_private_store_ancestor() {
        use std::process::Command as Process;
        if !Process::new("sudo")
            .args(["-n", "true"])
            .status()
            .is_ok_and(|status| status.success())
        {
            eprintln!("skipped: passwordless sudo is needed for production identity");
            return;
        }
        let (work, mut input) = deployment();
        fs::set_permissions(&work.0, fs::Permissions::from_mode(0o755)).unwrap();
        let private = work.0.join("private");
        fs::create_dir(&private).unwrap();
        fs::set_permissions(&private, fs::Permissions::from_mode(0o700)).unwrap();
        let secret = private.join("private.txt");
        fs::write(&secret, "private").unwrap();
        fs::set_permissions(&secret, fs::Permissions::from_mode(0o600)).unwrap();
        let env = input.vp_environment.as_mut().unwrap();
        let store = private.join("runtime");
        fs::rename(&env.store, &store).unwrap();
        env.store = store.clone();
        input.user = "nobody".into();
        input.home = work.0.join("nobody");
        fs::create_dir_all(input.home.join("releases/r1")).unwrap();
        assert!(Process::new("sudo")
            .args(["-n", "chown", "-R", "nobody", "--"])
            .arg(&input.home)
            .status()
            .unwrap()
            .success());
        let path = work.0.join("input.json");
        fs::write(&path, serde_json::to_vec(&serde_json::json!({
            "user":input.user, "home":input.home, "release":input.release,
            "script":"id -un; test ! -w \"$VP_HOME\"; test ! -r \"$VP_HOME/..\"; test ! -r \"$VP_HOME/../private.txt\"",
            "vp_environment":input.vp_environment, "step":"build", "timeout_seconds":5,
        })).unwrap()).unwrap();
        let mut outputs = vec![];
        let mut snapshots = vec![];
        for _ in 0..2 {
            outputs.push(
                Process::new("sudo")
                    .args(["-n", "env"])
                    .arg(format!("ORBIT_TEST_VP_INPUT={}", path.display()))
                    .arg(std::env::current_exe().unwrap())
                    .args([
                        "--ignored",
                        "--exact",
                        "commands::vp_store::tests::deployment::private_store_as_root",
                        "--nocapture",
                    ])
                    .output()
                    .unwrap(),
            );
            snapshots.push(
                Process::new("getfacl")
                    .args(["-cpn", "--"])
                    .arg(&private)
                    .output()
                    .unwrap()
                    .stdout,
            );
        }
        // Restore only the disposable fixture so its normal-user owner can remove it.
        assert!(Process::new("sudo")
            .args(["-n", "chown", "-R"])
            .arg(rustix::process::geteuid().as_raw().to_string())
            .arg("--")
            .arg(&input.home)
            .status()
            .unwrap()
            .success());
        for output in outputs {
            assert!(
                output.status.success(),
                "{}{}",
                String::from_utf8_lossy(&output.stdout),
                String::from_utf8_lossy(&output.stderr)
            );
        }
        assert_eq!(snapshots[0], snapshots[1], "repeat preserves access");
        let acl = String::from_utf8_lossy(&snapshots[0]);
        assert!(acl.contains("user:65534:--x"), "{acl}");
        assert!(acl.contains("other::---"), "{acl}");
        assert_eq!(fs::metadata(secret).unwrap().mode() & 0o777, 0o600);
        assert_eq!(fs::metadata(store).unwrap().mode() & 0o777, 0o755);
    }

    #[test]
    fn development_steps_use_the_same_environment_and_refuse_before_writing() {
        use crate::commands::dev_deploy::{step, Checkout, DevDeployError, StepInput};
        let (_work, input) = deployment();
        let home = input.home.join("releases/r1");
        for args in [
            vec!["init", "--quiet"],
            vec![
                "remote",
                "add",
                "origin",
                "https://example.test/project.git",
            ],
        ] {
            assert!(std::process::Command::new("git")
                .args(args)
                .current_dir(&home)
                .status()
                .unwrap()
                .success());
        }
        let mut step_input = StepInput {
            checkout: Checkout {
                home: home.clone(),
                repository: "https://example.test/project.git".into(),
                instance: 1,
            },
            script: "npm run build -- --base=/dev/ && npm run build:ssr".into(),
            vp_environment: input.vp_environment,
            step: "build".into(),
            timeout_seconds: 5,
            operation: None,
            secrets: vec![],
        };
        assert_eq!(step(&step_input, None).unwrap().exit_code, 0);
        assert_eq!(
            fs::read_to_string(home.join("build.log")).unwrap(),
            "<run><build><--><--base=/dev/>\n<run><build:ssr>\n"
        );
        step_input.vp_environment.as_mut().unwrap().node_version = "latest".into();
        step_input.script = "touch unexpected".into();
        assert!(matches!(
            step(&step_input, None),
            Err(DevDeployError::Invalid { .. })
        ));
        assert!(!home.join("unexpected").exists());
        assert!(fs::read_dir(home.join(".git")).unwrap().all(|entry| !entry
            .unwrap()
            .file_name()
            .to_string_lossy()
            .starts_with("orbit-deploy-step.")));
    }

    #[test]
    fn refuses_bad_requirements_and_missing_runtimes_before_writing_or_running_a_step() {
        for invalid in [
            "node", "manager", "version", "hash", "path", "missing", "writable",
        ] {
            let (work, mut input) = deployment();
            input.script = "touch started".into();
            let env = input.vp_environment.as_mut().unwrap();
            match invalid {
                "node" => env.node_version = "../24.22.0".into(),
                "manager" => env.package_manager = "npm --help".into(),
                "version" => env.package_manager_version = "latest".into(),
                "hash" => env.package_manager_hash = Some("sha512.x;touch started".into()),
                "path" => env.store = env.store.join(".."),
                "missing" => fs::remove_dir_all(env.store.join("js_runtime")).unwrap(),
                "writable" => {
                    fs::set_permissions(&env.store, fs::Permissions::from_mode(0o777)).unwrap()
                }
                _ => unreachable!(),
            }
            assert!(
                matches!(
                    run(&input, &work.0, None),
                    Err(DeployStepError::Invalid { .. })
                ),
                "{invalid}"
            );
            assert!(!input.home.join("releases/r1/started").exists());
            assert_eq!(
                fs::read_dir(&input.home).unwrap().count(),
                1,
                "no private script file was written"
            );
        }
    }
}
