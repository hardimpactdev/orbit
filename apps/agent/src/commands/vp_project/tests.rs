use super::*;
use std::os::unix::fs::{symlink, PermissionsExt};
struct Fixture(PathBuf);
impl Fixture {
    fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-vp-project-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("home")).unwrap();
        fs::write(root.join("package.json"), r#"{"devDependencies":{"vite-plus":"0.3.3"},"packageManager":"npm@11.12.0","scripts":{"preinstall":"touch hook-ran"}}"#).unwrap();
        Self(root)
    }
    fn read(&self) -> Result<Output> {
        read(&self.0, &self.0.join("home"))
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        fs::remove_dir_all(&self.0).unwrap();
    }
}
#[test]
fn reads_pins_locks_and_ordered_registry_settings_repeatedly_without_hooks() {
    let fixture = Fixture::new();
    for (name, data) in [
        (".node-version", "24.22.0\n"),
        (".nvmrc", "lts/*\n"),
        ("package-lock.json", "{}"),
        ("home/.npmrc", "registry=https://account.test/"),
        (".npmrc", "registry=https://project.test/"),
    ] {
        fs::write(fixture.0.join(name), data).unwrap();
    }
    let result = fixture.read().unwrap();
    assert_eq!(result, fixture.read().unwrap());
    assert_eq!(result.node_version.as_deref(), Some("24.22.0\n"));
    assert_eq!(result.nvmrc.as_deref(), Some("lts/*\n"));
    assert_eq!(result.package_manager_hint.as_deref(), Some("npm"));
    assert_eq!(result.locks["package-lock.json"], "{}");
    assert_eq!(
        result.npmrc,
        "registry=https://account.test/\nregistry=https://project.test/"
    );
    assert!(!fixture.0.join("hook-ran").exists());
}
#[test]
fn unrelated_projects_skip_runtime_files_and_missing_manifest_is_an_empty_object() {
    let fixture = Fixture::new();
    fs::create_dir(fixture.0.join(".node-version")).unwrap();
    fs::write(fixture.0.join("package.json"), r#"{"name":"unrelated"}"#).unwrap();
    assert_eq!(fixture.read().unwrap().node_version, None);
    fs::remove_file(fixture.0.join("package.json")).unwrap();
    let output = fixture.read().unwrap();
    assert!(output.package.is_empty());
    assert!(output.npmrc.is_empty());
    assert!(output.locks.is_empty());
}
#[test]
fn refuses_malformed_nonobject_nonutf8_and_oversized_files_without_hooks() {
    for bytes in [
        b"{".to_vec(),
        b"[]".to_vec(),
        b"null".to_vec(),
        vec![255],
        vec![b'x'; 1_048_577],
    ] {
        let fixture = Fixture::new();
        fs::write(fixture.0.join("package.json"), bytes).unwrap();
        assert_eq!(fixture.read(), Err(Error::Invalid {}));
        assert!(!fixture.0.join("hook-ran").exists());
    }
    let fixture = Fixture::new();
    fs::write(fixture.0.join(".node-version"), vec![b'x'; 1_048_577]).unwrap();
    assert_eq!(fixture.read(), Err(Error::Invalid {}));
    fs::remove_file(fixture.0.join(".node-version")).unwrap();
    let file = fs::File::create(fixture.0.join("pnpm-lock.yaml")).unwrap();
    file.set_len(16_777_217).unwrap();
    assert_eq!(fixture.read(), Err(Error::Invalid {}));
}
#[test]
fn refuses_file_symlinks_directories_and_fifos_without_blocking() {
    for name in [
        "package.json",
        ".node-version",
        ".nvmrc",
        ".npmrc",
        "home/.npmrc",
        "pnpm-lock.yaml",
    ] {
        let fixture = Fixture::new();
        let path = fixture.0.join(name);
        if path.exists() {
            fs::remove_file(&path).unwrap();
        }
        symlink("/etc/passwd", &path).unwrap();
        assert_eq!(fixture.read(), Err(Error::Invalid {}));
        fs::remove_file(&path).unwrap();
        fs::create_dir(&path).unwrap();
        assert_eq!(fixture.read(), Err(Error::Invalid {}));
        fs::remove_dir(&path).unwrap();
        let cpath = std::ffi::CString::new(path.to_str().unwrap()).unwrap();
        // SAFETY: a live NUL-terminated path and a valid mode.
        assert_eq!(unsafe { libc::mkfifo(cpath.as_ptr(), 0o600) }, 0);
        assert_eq!(fixture.read(), Err(Error::Invalid {}));
    }
}
#[test]
fn finds_aliases_and_workspace_versions_but_refuses_workspace_escape() {
    let fixture = Fixture::new();
    fs::write(
        fixture.0.join("package.json"),
        r#"{"dependencies":{"alias":"npm:vite-plus@0.3.3"}}"#,
    )
    .unwrap();
    fs::create_dir(fixture.0.join("runtime")).unwrap();
    fs::write(
        fixture.0.join("runtime/package.json"),
        r#"{"name":"vite-plus","version":"0.3.3"}"#,
    )
    .unwrap();
    fs::write(
        fixture.0.join("pnpm-lock.yaml"),
        "version: 'link:runtime'\n",
    )
    .unwrap();
    let result = fixture.read().unwrap();
    assert_eq!(result.workspaces["link:runtime"], "0.3.3");
    let outside = Fixture::new();
    symlink(&outside.0, fixture.0.join("escape")).unwrap();
    fs::write(fixture.0.join("pnpm-lock.yaml"), "version: link:escape\n").unwrap();
    assert_eq!(fixture.read(), Err(Error::Invalid {}));
    fs::write(fixture.0.join("pnpm-lock.yaml"), "version: link:../\n").unwrap();
    assert_eq!(fixture.read(), Err(Error::Invalid {}));
}
#[test]
fn manager_markers_preserve_selection_order() {
    let fixture = Fixture::new();
    for (name, expected) in [
        ("bun.lockb", "bun"),
        ("package-lock.json", "npm"),
        ("yarn.lock", "yarn"),
        ("pnpm-workspace.yaml", "pnpm"),
    ] {
        fs::write(fixture.0.join(name), "{}").unwrap();
        assert_eq!(
            fixture.read().unwrap().package_manager_hint.as_deref(),
            Some(expected)
        );
    }
}
#[test]
fn invalid_identity_refuses_before_starting_a_reader() {
    let fixture = Fixture::new();
    let context = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(2),
    );
    for user in [
        "root",
        "-root",
        "orbit;id",
        "orbit\nroot",
        "",
        "missing-orbit-test-account",
    ] {
        assert_eq!(
            execute(
                Input {
                    path: fixture.0.clone(),
                    user: user.into()
                },
                &context,
                Path::new("/not-an-executable")
            ),
            Err(Error::Invalid {})
        );
    }
}
#[test]
#[ignore = "Uses the source-built agent on SPIKE to prove the account read boundary"]
fn production_reader_cannot_read_owner_private_files() {
    let fixture = Fixture::new();
    fs::set_permissions(&fixture.0, fs::Permissions::from_mode(0o755)).unwrap();
    let secret = fixture.0.join("package.json");
    fs::set_permissions(&secret, fs::Permissions::from_mode(0o600)).unwrap();
    let binary = std::env::var("ORBIT_VP_PROJECT_TEST_BINARY").unwrap();
    let invoke = || {
        let digest = crate::command::run::file_sha256(Path::new(&binary)).unwrap();
        let mut child = Process::new("sudo")
            .args(["-n", &binary, "command", ReadProject::NAME])
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .spawn()
            .unwrap();
        use std::io::Write;
        child.stdin.take().unwrap().write_all(serde_json::to_string(&serde_json::json!({
            "agent_sha256": digest, "deadline_ms": 5000, "input": {"path": fixture.0, "user":"nobody"}
        })).unwrap().as_bytes()).unwrap();
        let output = child.wait_with_output().unwrap();
        assert!(output.status.success());
        serde_json::from_slice::<Value>(&output.stdout).unwrap()
    };
    assert_eq!(invoke()["error"]["code"], "tool.project_invalid");
    fs::set_permissions(secret, fs::Permissions::from_mode(0o644)).unwrap();
    // nobody's home does not exist; absence of account .npmrc is allowed.
    assert_eq!(invoke()["ok"], true);
    assert_eq!(invoke()["ok"], true);
    assert!(!fixture.0.join("hook-ran").exists());
}
