//! The dependency commands against sandbox sources that the test user owns.

use super::*;
use std::os::unix::fs::{symlink, PermissionsExt};

struct Sandbox(PathBuf);

impl Sandbox {
    fn new() -> Self {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-dependencies-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&path).unwrap();
        Self(path)
    }

    fn write(&self, name: &str, content: &str) -> &Self {
        let path = self.0.join(name);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, content).unwrap();
        self
    }

    fn path(&self) -> String {
        self.0.display().to_string()
    }

    fn probe(&self, ecosystem: Ecosystem) -> Value {
        probe(
            &ProbeInput {
                directory: self.path(),
                ecosystem,
            },
            &self.0.join("no-home"),
        )
    }
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn probes_composer_by_its_manifest_and_lock() {
    let source = Sandbox::new();
    assert_eq!(
        source.probe(Ecosystem::Composer),
        json!({"status": "absent"})
    );
    source.write("composer.json", "{}");
    assert_eq!(
        source.probe(Ecosystem::Composer),
        json!({"status": "incomplete"})
    );
    source.write("composer.lock", "{}");
    assert_eq!(
        source.probe(Ecosystem::Composer),
        json!({"status": "present"})
    );
}

#[test]
fn probes_npm_and_refuses_other_or_several_managers() {
    let source = Sandbox::new();
    source
        .write("package.json", "{}")
        .write("package-lock.json", "{}");
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"status": "present", "vp": null})
    );
    assert_eq!(source.probe(Ecosystem::Pnpm), json!({"status": "absent"}));
    assert_eq!(source.probe(Ecosystem::Bun), json!({"status": "absent"}));

    source.write("pnpm-lock.yaml", "");
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"error": "dependencies.ambiguous_manager"})
    );

    let source = Sandbox::new();
    source
        .write("package.json", "{}")
        .write("package-lock.json", "{}")
        .write("yarn.lock", "");
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"error": "dependencies.unsupported_format"})
    );

    let source = Sandbox::new();
    source.write("package.json", r#"{"workspaces": []}"#);
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"error": "dependencies.unsupported_layout"})
    );

    let source = Sandbox::new();
    source.write("package.json", r#"{"name": "a", "name": "b"}"#);
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"error": "dependencies.invalid_manifest"})
    );

    let source = Sandbox::new();
    source
        .write("package.json", r#"{"packageManager": "pnpm@9.1.0"}"#)
        .write("package-lock.json", "{}");
    assert_eq!(
        source.probe(Ecosystem::Npm),
        json!({"error": "dependencies.ambiguous_manager"})
    );
}

#[test]
fn probes_pnpm_and_bun_and_refuses_a_binary_bun_lock_alone() {
    let source = Sandbox::new();
    source.write("package.json", "{}");
    assert_eq!(
        source.probe(Ecosystem::Pnpm),
        json!({"status": "incomplete"})
    );
    source.write("pnpm-lock.yaml", "");
    assert_eq!(
        source.probe(Ecosystem::Pnpm),
        json!({"status": "present", "vp": null})
    );

    let source = Sandbox::new();
    source.write("package.json", "{}").write("bun.lockb", "");
    assert_eq!(
        source.probe(Ecosystem::Bun),
        json!({"error": "dependencies.unsupported_format"})
    );
    source.write("bun.lock", "");
    assert_eq!(
        source.probe(Ecosystem::Bun),
        json!({"status": "present", "vp": null})
    );
}

#[test]
fn probes_the_yarn_family() {
    let source = Sandbox::new();
    source.write("package.json", r#"{"packageManager": "yarn@1.22.19"}"#);
    assert_eq!(
        source.probe(Ecosystem::Yarn),
        json!({"status": "present", "family": "classic"})
    );
    source.write("yarn.lock", "__metadata:\n  version: 8\n");
    assert_eq!(
        source.probe(Ecosystem::Yarn),
        json!({"status": "present", "family": "modern"})
    );

    let source = Sandbox::new();
    source.write(
        "package.json",
        r#"{"devEngines": {"packageManager": {"name": "yarn", "version": "4.1.0"}}}"#,
    );
    assert_eq!(
        source.probe(Ecosystem::Yarn),
        json!({"status": "present", "family": "modern"})
    );
    assert_eq!(
        Sandbox::new().probe(Ecosystem::Yarn),
        json!({"status": "absent"})
    );
}

#[test]
fn refuses_a_link_on_the_way_or_in_place_of_a_file() {
    let source = Sandbox::new();
    source.write("real/composer.json", "{}");
    symlink(source.0.join("real"), source.0.join("linked")).unwrap();
    let linked = ProbeInput {
        directory: source.0.join("linked").display().to_string(),
        ecosystem: Ecosystem::Composer,
    };
    assert_eq!(
        probe(&linked, Path::new("/")),
        json!({"error": "dependencies.unsafe_source"})
    );

    symlink("/etc/hostname", source.0.join("real/composer.lock")).unwrap();
    let real = ProbeInput {
        directory: source.0.join("real").display().to_string(),
        ecosystem: Ecosystem::Composer,
    };
    assert_eq!(
        probe(&real, Path::new("/")),
        json!({"error": "dependencies.unsafe_source"})
    );
}

#[test]
fn reads_every_inventory_file_in_order_with_its_content_and_hash() {
    let source = Sandbox::new();
    source
        .write("apps/site/composer.json", "{}")
        .write("apps/site/composer.lock", "lock");
    fs::create_dir(source.0.join("apps/site/package.json")).unwrap();

    let receipt = read_files(
        "development",
        &FilesInput {
            path: source.path(),
            application: "/apps/site".into(),
            monorepo: false,
        },
    );

    assert_eq!(
        receipt["root"],
        json!(format!("{}/apps/site", source.path()))
    );
    assert_eq!(receipt["reference"], Value::Null);
    assert_eq!(receipt["identity"].as_str().unwrap().len(), 64);
    let files = receipt["files"].as_object().unwrap();
    assert_eq!(
        files.keys().collect::<Vec<_>>(),
        FILES.iter().collect::<Vec<_>>()
    );
    assert_eq!(files["composer.json"]["content"], json!("e30="));
    assert_eq!(
        files["composer.json"]
            .as_object()
            .unwrap()
            .keys()
            .collect::<Vec<_>>(),
        ["content", "hash", "error"]
    );
    assert_eq!(
        files["package.json"]["error"],
        json!("dependencies.unsafe_source")
    );
    assert_eq!(
        files["bun.lock"],
        json!({"content": null, "hash": null, "error": null})
    );
}

#[test]
fn reads_the_current_release_of_a_production_home_and_each_monorepo_sub_project() {
    let home = Sandbox::new();
    home.write("releases/r1/apps/web/composer.json", "{}")
        .write("releases/r1/README", "");
    symlink("releases/r1", home.0.join("current")).unwrap();

    let receipt = read_files(
        "production",
        &FilesInput {
            path: home.path(),
            application: String::new(),
            monorepo: true,
        },
    );

    assert_eq!(receipt["reference"], json!("r1"));
    assert_eq!(
        receipt["root"],
        json!(format!("{}/releases/r1", home.path()))
    );
    let project = &receipt["sub_projects"]["apps/web"];
    assert_eq!(
        project["root"],
        json!(format!("{}/releases/r1/apps/web", home.path()))
    );
    assert_eq!(project["reference"], json!("r1"));
    assert_eq!(project["identity"], receipt["identity"]);
    assert_eq!(project["files"]["composer.json"]["content"], json!("e30="));

    fs::remove_file(home.0.join("current")).unwrap();
    symlink("/tmp", home.0.join("current")).unwrap();
    assert_eq!(
        read_files(
            "production",
            &FilesInput {
                path: home.path(),
                application: String::new(),
                monorepo: false
            }
        ),
        json!({"error": "dependencies.unsafe_source"})
    );
}

#[test]
fn runs_the_update_in_the_directory_and_answers_124_at_its_deadline() {
    let source = Sandbox::new();
    let composer = source.0.join("composer");
    fs::write(&composer, "#!/bin/sh\nprintf '%s ' \"$@\" > \"$2/arguments\"\n[ -e \"$2/hang\" ] && sleep 30\nexit 0\n").unwrap();
    fs::set_permissions(&composer, fs::Permissions::from_mode(0o755)).unwrap();
    let input = UpdateInput {
        directory: source.path(),
        ecosystem: Ecosystem::Composer,
        vp: None,
    };

    assert_eq!(
        update(&input, &composer, Duration::from_secs(30)),
        Ok(Updated { exit_code: 0 })
    );
    assert_eq!(
        fs::read_to_string(source.0.join("arguments")).unwrap(),
        format!(
            "--working-dir {} update --no-interaction --no-ansi --no-progress --no-audit ",
            source.path()
        )
    );

    source.write("hang", "");
    let started = Instant::now();
    assert_eq!(
        update(&input, &composer, Duration::from_secs(1)),
        Ok(Updated { exit_code: 124 })
    );
    assert!(started.elapsed() < Duration::from_secs(5));
}

fn fake_vp(path: &Path, version: &str) {
    fs::create_dir_all(path.parent().unwrap()).unwrap();
    fs::write(path, format!("#!/bin/sh\necho 'vp v{version}'\n")).unwrap();
    fs::set_permissions(path, fs::Permissions::from_mode(0o755)).unwrap();
}

#[test]
fn finds_vite_plus_in_the_account_layouts_in_order() {
    let home = Sandbox::new();
    assert_eq!(vite_plus(&home.0), Value::Null);

    let shared = home.0.join(".local/share/vite-plus/bin/vp");
    fake_vp(&shared, "0.3.0");
    assert_eq!(
        vite_plus(&home.0),
        json!({"path": shared, "version": "0.3.0"})
    );

    let preferred = home.0.join(".vite-plus/bin/vp");
    fake_vp(&preferred, "0.4.1");
    assert_eq!(
        vite_plus(&home.0),
        json!({"path": preferred, "version": "0.4.1"})
    );

    fs::set_permissions(&preferred, fs::Permissions::from_mode(0o644)).unwrap();
    assert_eq!(
        vite_plus(&home.0)["path"],
        json!(shared),
        "an unexecutable install is skipped"
    );
}

#[test]
fn bounds_each_file_by_its_kind() {
    let source = Sandbox::new();
    source.write("composer.json", &"x".repeat(1024 * 1024 + 1));
    source.write("composer.lock", &"x".repeat(1024 * 1024 + 1));

    let receipt = read_files(
        "development",
        &FilesInput {
            path: source.path(),
            application: String::new(),
            monorepo: false,
        },
    );

    assert_eq!(
        receipt["files"]["composer.json"]["error"],
        json!("dependencies.source_too_large")
    );
    assert_eq!(
        receipt["files"]["composer.lock"]["error"],
        Value::Null,
        "a lockfile may hold 8 MiB"
    );
}
