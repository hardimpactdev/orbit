use super::super::recipe::WorkDir;
use super::*;
use std::os::unix::fs::PermissionsExt;

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
                let output = run(&input, &work.0).unwrap();
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
        let output = run(&input, path.parent().unwrap()).unwrap();
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
                matches!(run(&input, &work.0), Err(DeployStepError::Invalid { .. })),
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
