use super::*;
use std::os::unix::fs::symlink;
fn tree() -> PathBuf {
    let path = std::env::temp_dir().join(format!(
        "package-runtime-test-{:016x}",
        rand::random::<u64>()
    ));
    fs::create_dir(&path).unwrap();
    path
}
#[test]
fn manifests_cannot_name_host_paths_scripts_or_runtime_custody() {
    for name in [
        "/home/orbit/package.json",
        "../package.json",
        "x/../package.json",
        "x/./package.json",
        ".git/package.json",
        "node_modules/package.json",
        "entry.sh",
        "package.json\n",
        "x\\package.json",
    ] {
        assert!(!manifest(name), "{name}");
    }
    for name in [
        "package.json",
        "apps/web/package-lock.json",
        "composer.lock",
        "bun.lock",
        "pnpm-workspace.yaml",
    ] {
        assert!(manifest(name));
    }
    assert!(!relative("/", true));
    assert!(!relative("..", true));
    assert!(relative(".", true));
}
#[test]
fn privileged_exports_refuse_final_and_parent_symlinks_and_oversized_files() {
    let root = tree();
    fs::create_dir(root.join("work")).unwrap();
    let names = vec!["package.json".into()];
    symlink("/etc/passwd", root.join("work/package.json")).unwrap();
    assert!(export(&root, &names).is_err());
    fs::remove_file(root.join("work/package.json")).unwrap();
    symlink("/etc", root.join("work/apps")).unwrap();
    assert!(export(&root, &["apps/package.json".into()]).is_err());
    fs::write(root.join("work/package.json"), "x".repeat(MAX_BYTES + 1)).unwrap();
    assert!(export(&root, &names).is_err());
    fs::write(root.join("work/package.json"), "{\"name\":\"fixture\"}").unwrap();
    assert_eq!(
        export(&root, &names).unwrap()["package.json"],
        "{\"name\":\"fixture\"}"
    );
    fs::remove_dir_all(root).unwrap();
}
#[test]
fn job_tokens_and_closed_tool_set_cannot_select_a_host_program_or_root() {
    let root = tree();
    for id in ["/", "../x", "x", "A0000000000000000000000000000000"] {
        assert!(job_path(&root, id).is_err());
    }
    for value in [
        r#"{"action":"run","job":"x","tool":"shell","cwd":".","args":[]}"#,
        r#"{"action":"open","files":{},"mount":"/"}"#,
    ] {
        assert!(serde_json::from_str::<Input>(value).is_err());
    }
    fs::remove_dir_all(root).unwrap();
}
#[test]
fn package_units_bind_to_their_supervisor_and_confine_entire_cgroups() {
    let a = Account {
        uid: 998,
        gid: 998,
        home: "/nonexistent".into(),
        shell: "/usr/sbin/nologin".into(),
        group: USER.into(),
        groups: vec![998],
    };
    let args = arguments(
        Path::new("/private/job/root"),
        &a,
        "orbit-package-fixture.service",
        "orbit-command-package-session-fixture.service",
        30,
        "apps/web",
        &["80.47.233.206".into(), "2001:db8::209".into()],
    );
    for required in [
        "--uid=998",
        "--gid=998",
        "--property=KillMode=control-group",
        "--property=BindsTo=orbit-command-package-session-fixture.service",
        "--property=NoNewPrivileges=yes",
        "--property=SocketBindDeny=any",
        "--property=PrivatePIDs=yes",
        "--property=ProtectControlGroups=yes",
        "--property=CapabilityBoundingSet=",
        "--property=RootDirectory=/private/job/root",
        "--property=ReadWritePaths=+/work +/home +/tmp +/cache",
    ] {
        assert!(args.iter().any(|v| v == required), "{required}");
    }
    assert!(args
        .iter()
        .all(|v| !v.contains("SWAMP_") && !v.contains("GITHUB_TOKEN") && !v.contains("GH_TOKEN")));
    assert!(args
        .iter()
        .filter(|v| v.starts_with("--property=BindReadOnlyPaths="))
        .all(|v| !v.contains("/home") && !v.contains("/var") && !v.contains("/run")));
}

#[test]
fn descriptor_transfer_rejects_hard_links_and_concurrent_parent_swaps() {
    use std::sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    };
    let root = tree();
    fs::create_dir(root.join("work")).unwrap();
    fs::write(root.join("outside"), "HOST_SENTINEL").unwrap();
    fs::hard_link(root.join("outside"), root.join("work/package.json")).unwrap();
    assert!(export(&root, &["package.json".into()]).is_err());
    fs::remove_file(root.join("work/package.json")).unwrap();
    let outside = tree();
    fs::write(outside.join("package.json"), "HOST_SENTINEL").unwrap();
    fs::create_dir(root.join("work/app")).unwrap();
    fs::write(root.join("work/app/package.json"), "inside").unwrap();
    let stop = Arc::new(AtomicBool::new(false));
    let flag = stop.clone();
    let work = root.join("work");
    let target = outside.clone();
    let racer = std::thread::spawn(move || {
        while !flag.load(Ordering::Relaxed) {
            if fs::rename(work.join("app"), work.join("held")).is_ok() {
                let _ = symlink(&target, work.join("app"));
                let _ = fs::remove_file(work.join("app"));
                let _ = fs::rename(work.join("held"), work.join("app"));
            }
        }
    });
    for _ in 0..1000 {
        if let Ok(files) = export(&root, &["app/package.json".into()]) {
            assert!(files.values().all(|v| v != "HOST_SENTINEL"));
        }
    }
    stop.store(true, Ordering::Relaxed);
    racer.join().unwrap();
    fs::remove_dir_all(root).unwrap();
    fs::remove_dir_all(outside).unwrap();
}

#[test]
fn launch_excludes_host_public_addresses_cgnat_and_php_pool_configuration() {
    let account = Account {
        uid: 998,
        gid: 998,
        home: "/nonexistent".into(),
        shell: "/usr/sbin/nologin".into(),
        group: USER.into(),
        groups: vec![998],
    };
    let args = arguments(
        Path::new("/job/root"),
        &account,
        "fixture.service",
        "fixture-parent.service",
        30,
        ".",
        &["80.47.233.206".into(), "2001:db8::209".into()],
    );
    let denies = args
        .iter()
        .find(|s| s.starts_with("--property=IPAddressDeny="))
        .unwrap();
    for address in ["80.47.233.206", "2001:db8::209", "100.64.0.0/10"] {
        assert!(denies.contains(address));
    }
    let mounts = args
        .iter()
        .find(|s| s.starts_with("--property=BindReadOnlyPaths="))
        .unwrap();
    assert!(!mounts.contains("/etc/php"));
    assert!(!mounts.contains(" /etc/php "));
    assert!(!mounts.contains("fpm"));
}
