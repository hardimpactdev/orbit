use super::*;
use crate::commands::recipe::Output;
use serde_json::json;
use sha2::{Digest, Sha256};

fn fixture() -> (WorkDir, Files, Account) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    let account = Account {
        name: "orbit".into(),
        uid,
        gid,
        home: files.path("/home/orbit"),
    };
    fs::create_dir_all(&account.home).unwrap();
    (work, files, account)
}
fn distribution(root: &Path, component: &str, manifest: &str, entries: &[(&str, &str)]) {
    let directory = root.join("distribution").join(component);
    fs::create_dir_all(&directory).unwrap();
    fs::write(root.join("distribution/components"), component).unwrap();
    fs::write(directory.join("manifest.in"), manifest).unwrap();
    for (name, content) in entries {
        let path = directory.join(name);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(&path, content).unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
    }
}
fn archive(root: &Path) -> Vec<u8> {
    let mut tar = tar::Builder::new(Vec::new());
    tar.append_dir_all("distribution", root.join("distribution"))
        .unwrap();
    tar.into_inner().unwrap()
}
struct Downloads {
    bytes: Vec<u8>,
    count: usize,
}
impl Host for Downloads {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        assert_eq!(call.argv[0], "curl");
        let position = call.argv.iter().position(|v| v == "--output").unwrap();
        fs::write(&call.argv[position + 1], &self.bytes).unwrap();
        self.count += 1;
        Ok(Output::default())
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No retries")
    }
}
fn input(bytes: &[u8]) -> Input {
    serde_json::from_value(json!({
        "identity": format!("sha256:{}", "a".repeat(64)),
        "agent_sha256": "b".repeat(64),
        "pi_sha256": null, "php": null,
        "recipe": {"user":"orbit", "snapshot":"20261022T000000Z", "architecture":"amd64", "tools":{}},
        "rust": {"rust":{"source":"artifact", "version":"1.91.0", "artifacts":{"amd64":{
            "url":"https://example.test/rust.tar", "sha256":format!("{:x}", Sha256::digest(bytes))
        }}}}
    })).unwrap()
}
#[test]
fn merges_component_directories_without_running_distribution_scripts() {
    let (_work, files, _) = fixture();
    let first = files.path("/first");
    let second = files.path("/second");
    distribution(
        &first,
        "rustc",
        "dir:lib\nfile:bin/rustc\n",
        &[("lib/a", "a"), ("bin/rustc", "rustc")],
    );
    distribution(&second, "rust-std", "dir:lib\n", &[("lib/b", "b")]);
    fs::write(first.join("distribution/install.sh"), "exit 99").unwrap();
    let prefix = files.path("/candidate");
    fs::create_dir(&prefix).unwrap();
    component(&first, &prefix).unwrap();
    component(&second, &prefix).unwrap();
    validate_tree(&prefix).unwrap();
    assert_eq!(fs::read(prefix.join("lib/a")).unwrap(), b"a");
    assert_eq!(fs::read(prefix.join("lib/b")).unwrap(), b"b");
    assert!(!prefix.join("install.sh").exists());
}
#[test]
fn publishes_complete_upgrade_removes_obsolete_files_and_repairs_links_without_downloads() {
    let (_work, files, account) = fixture();
    let source = files.path("/source");
    distribution(
        &source,
        "rustc",
        "dir:bin\ndir:lib\n",
        &[("bin/rustc", "new"), ("lib/current", "new")],
    );
    let bytes = archive(&source);
    let input = input(&bytes);
    input.validate().unwrap();
    let prefix = files.path("/opt/orbit/rust");
    fs::create_dir_all(prefix.join("bin")).unwrap();
    fs::write(prefix.join("obsolete"), "old").unwrap();
    fs::write(prefix.join("bin/retired"), "old").unwrap();
    let destinations = link_destinations(&files, &account).unwrap();
    for directory in &destinations {
        symlink(prefix.join("bin/retired"), directory.join("retired")).unwrap();
    }
    let mut host = Downloads { bytes, count: 0 };
    install(&mut host, &files, &input, &account).unwrap();
    assert_eq!(fs::read(prefix.join("bin/rustc")).unwrap(), b"new");
    assert!(!prefix.join("obsolete").exists());
    assert_eq!(
        recipe::store::json(&prefix.join(".recipe-artifacts.json")).unwrap(),
        pins(&input)
    );
    for directory in &destinations {
        assert!(fs::symlink_metadata(directory.join("retired")).is_err());
        fs::remove_file(directory.join("rustc")).unwrap();
    }
    install(&mut host, &files, &input, &account).unwrap();
    assert_eq!(host.count, 1);
    for directory in &destinations {
        assert_eq!(
            fs::read_link(directory.join("rustc")).unwrap(),
            prefix.join("bin/rustc")
        );
    }
    assert_eq!(paths(&files.path("/opt/orbit")).unwrap(), vec![prefix]);
}
#[test]
fn malformed_or_escaping_manifest_preserves_the_installed_toolchain() {
    for manifest in [
        "file:../outside",
        "file:/etc/passwd",
        "dir:bin/rustc",
        "bad",
        "link:bin/rustc",
    ] {
        let (_work, files, account) = fixture();
        let source = files.path("/source");
        distribution(&source, "rustc", manifest, &[("bin/rustc", "new")]);
        let bytes = archive(&source);
        let input = input(&bytes);
        let prefix = files.path("/opt/orbit/rust");
        fs::create_dir_all(prefix.join("bin")).unwrap();
        fs::write(prefix.join("bin/rustc"), "old").unwrap();
        let mut host = Downloads { bytes, count: 0 };
        assert!(
            install(&mut host, &files, &input, &account).is_err(),
            "{manifest}"
        );
        assert_eq!(fs::read(prefix.join("bin/rustc")).unwrap(), b"old");
        assert!(!prefix.join(".recipe-artifacts.json").exists());
        assert_eq!(paths(&files.path("/opt/orbit")).unwrap(), vec![prefix]);
    }
}
#[test]
fn refuses_component_conflicts_and_symlink_ancestors() {
    let (_work, files, _) = fixture();
    let first = files.path("/first");
    let second = files.path("/second");
    distribution(&first, "rustc", "dir:lib", &[("lib/a", "first")]);
    distribution(&second, "rust-std", "dir:lib", &[("lib/a", "second")]);
    let prefix = files.path("/candidate");
    fs::create_dir(&prefix).unwrap();
    component(&first, &prefix).unwrap();
    assert!(component(&second, &prefix)
        .unwrap_err()
        .reason
        .contains("conflicting"));
    assert_eq!(fs::read(prefix.join("lib/a")).unwrap(), b"first");
    fs::remove_dir_all(prefix.join("lib")).unwrap();
    symlink(
        files.path("/first/distribution/rustc/lib"),
        prefix.join("lib"),
    )
    .unwrap();
    assert!(component(&second, &prefix).is_err());
    assert_eq!(
        fs::read(files.path("/first/distribution/rustc/lib/a")).unwrap(),
        b"first"
    );
    fs::remove_dir_all(second.join("distribution/rust-std/lib")).unwrap();
    symlink(
        first.join("distribution/rustc/lib"),
        second.join("distribution/rust-std/lib"),
    )
    .unwrap();
    fs::write(
        second.join("distribution/rust-std/manifest.in"),
        "file:lib/a",
    )
    .unwrap();
    assert!(component(&second, &prefix)
        .unwrap_err()
        .reason
        .contains("non-directory"));
}
#[test]
fn publication_refuses_escaping_links_and_obstructed_binary_links_before_replacing_prefix() {
    for escape in [true, false] {
        let (_work, files, account) = fixture();
        let work = files.path("/work");
        let candidate = work.join("candidate");
        fs::create_dir_all(candidate.join("bin")).unwrap();
        fs::write(candidate.join("bin/rustc"), "new").unwrap();
        fs::set_permissions(
            candidate.join("bin/rustc"),
            fs::Permissions::from_mode(0o755),
        )
        .unwrap();
        let prefix = files.path("/opt/orbit/rust");
        fs::create_dir_all(&prefix).unwrap();
        fs::write(prefix.join("keep"), "old").unwrap();
        if escape {
            symlink(&prefix, candidate.join("outside")).unwrap();
        } else {
            let destinations = link_destinations(&files, &account).unwrap();
            fs::create_dir(destinations[1].join("rustc")).unwrap();
        }
        assert!(publish(&files, &candidate, &work, &account).is_err());
        assert_eq!(fs::read(prefix.join("keep")).unwrap(), b"old");
        assert!(candidate.exists());
        assert!(!prefix.join(".recipe-artifacts.json").exists());
    }
}
