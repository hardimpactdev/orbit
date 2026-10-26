use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::symlink;
fn fixture() -> (WorkDir, Files, Account, PathBuf) {
    let work = WorkDir::new().unwrap();
    let account = Account {
        name: crate::command::identity::user_name(rustix::process::getuid().as_raw()).unwrap(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
        home: work.0.join("home"),
    };
    let files = Files {
        root: work.0.clone(),
        uid: account.uid,
        gid: account.gid,
    };
    let home = account.home.join(".vite-plus");
    fs::create_dir_all(&home).unwrap();
    (work, files, account, home)
}
fn archive(root: &Path, version: &str) -> (PathBuf, String) {
    let archive = root.join(format!("node-{version}.tar.gz"));
    let gzip = flate2::write::GzEncoder::new(
        fs::File::create(&archive).unwrap(),
        flate2::Compression::fast(),
    );
    let mut tar = tar::Builder::new(gzip);
    let system = if cfg!(target_os = "macos") {
        "darwin"
    } else {
        "linux"
    };
    let architecture = if cfg!(target_arch = "aarch64") {
        "arm64"
    } else {
        "x64"
    };
    let prefix = format!("node-v{version}-{system}-{architecture}");
    let mut header = tar::Header::new_gnu();
    header.set_size(6);
    header.set_mode(0o755);
    header.set_cksum();
    tar.append_data(&mut header, format!("{prefix}/bin/node"), &b"binary"[..])
        .unwrap();
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(tar::EntryType::Symlink);
    header.set_size(0);
    header.set_mode(0o777);
    header.set_link_name("node").unwrap();
    header.set_cksum();
    tar.append_data(&mut header, format!("{prefix}/bin/npm"), io::empty())
        .unwrap();
    tar.into_inner().unwrap().finish().unwrap();
    let digest = crate::command::run::file_sha256(&archive).unwrap();
    (archive, digest)
}
fn publish(
    files: &Files,
    home: &Path,
    account: &Account,
    archive: &Path,
    digest: &str,
) -> Result<(), Failure> {
    publish_node(
        files,
        home,
        account,
        "24.1.0",
        archive,
        digest,
        Instant::now() + Duration::from_secs(5),
    )
}
#[test]
fn publishes_real_immutable_runtime_and_repeats_without_replacing_the_store_lock_or_ledger() {
    let (work, files, account, home) = fixture();
    let (archive, digest) = archive(&work.0, "24.1.0");
    let lock = home.join(".orbit-store.lock");
    fs::write(&lock, "").unwrap();
    let inode = fs::metadata(&lock).unwrap().ino();
    let ledger = home.join(".orbit-runtimes.json");
    fs::write(&ledger, "{\"js_runtime/node/24.1.0\":\"existing receipt\"}").unwrap();
    for _ in 0..2 {
        publish(&files, &home, &account, &archive, &digest).unwrap();
    }
    let node = home.join("js_runtime/node/24.1.0");
    assert!(node.is_dir());
    assert!(!node.is_symlink());
    assert_eq!(fs::read(node.join("bin/node")).unwrap(), b"binary");
    assert_eq!(fs::metadata(lock).unwrap().ino(), inode);
    assert!(fs::read_to_string(ledger)
        .unwrap()
        .contains("existing receipt"));
    assert_eq!(recipe_version(&home, &account), None);
    fs::write(
        home.join("config.json"),
        r#"{"defaultNodeVersion":"24.1.0"}"#,
    )
    .unwrap();
    assert_eq!(recipe_version(&home, &account), Some("24.1.0".into()));
    fs::write(home.join("config.json"), r#"{"defaultNodeVersion":"lts"}"#).unwrap();
    assert_eq!(recipe_version(&home, &account), None);
}
#[test]
fn refuses_mismatched_real_content_and_unrelated_links_without_deleting_them() {
    let (work, files, account, home) = fixture();
    let (archive, digest) = archive(&work.0, "24.1.0");
    let node = home.join("js_runtime/node/24.1.0");
    fs::create_dir_all(node.join("bin")).unwrap();
    fs::write(node.join("bin/node"), "different").unwrap();
    assert!(publish(&files, &home, &account, &archive, &digest).is_err());
    assert_eq!(
        fs::read_to_string(node.join("bin/node")).unwrap(),
        "different"
    );
    assert!(!home.join(REFERENCE).exists());
    fs::remove_dir_all(&node).unwrap();
    let other = home.join("unrelated");
    fs::create_dir(&other).unwrap();
    symlink(&other, &node).unwrap();
    assert!(publish(&files, &home, &account, &archive, &digest).is_err());
    assert!(node.is_symlink());
    assert!(other.is_dir());
}
#[test]
fn migrates_only_exact_historical_recipe_links_and_preserves_their_targets() {
    let (work, files, account, home) = fixture();
    let (archive, digest) = archive(&work.0, "24.1.0");
    let old = home.join("recipe").join(format!("node-{}", "a".repeat(64)));
    fs::create_dir_all(&old).unwrap();
    let extracted = old.join("unpack");
    artifacts::extract(&archive, &extracted).unwrap();
    let source = fs::read_dir(&extracted)
        .unwrap()
        .next()
        .unwrap()
        .unwrap()
        .path();
    let target = old.join(source.file_name().unwrap());
    fs::rename(source, &target).unwrap();
    let runtime = home.join("js_runtime/node/24.1.0");
    fs::create_dir_all(runtime.parent().unwrap()).unwrap();
    symlink(&target, &runtime).unwrap();
    assert_eq!(recipe_version(&home, &account), None);
    publish(&files, &home, &account, &archive, &digest).unwrap();
    assert!(!runtime.is_symlink());
    assert!(target.is_dir());
    assert!(matches(&target, &runtime, &account).unwrap());
}
#[test]
fn normalizes_owned_writable_components_but_never_adopts_another_owners_scope() {
    let (work, files, mut account, home) = fixture();
    let (archive, digest) = archive(&work.0, "24.1.0");
    fs::create_dir_all(home.join("js_runtime/node")).unwrap();
    for path in [
        &home,
        &home.join("js_runtime"),
        &home.join("js_runtime/node"),
    ] {
        fs::set_permissions(path, fs::Permissions::from_mode(0o775)).unwrap();
    }
    publish(&files, &home, &account, &archive, &digest).unwrap();
    for path in [
        &home,
        &home.join("js_runtime"),
        &home.join("js_runtime/node"),
    ] {
        assert_eq!(fs::metadata(path).unwrap().mode() & 0o022, 0);
    }
    account.uid += 1;
    assert!(publish(&files, &home, &account, &archive, &digest).is_err());
}
#[test]
fn corrupt_archive_and_a_held_store_lock_refuse_before_runtime_changes() {
    let (work, files, account, home) = fixture();
    let (archive, digest) = archive(&work.0, "24.1.0");
    assert!(publish(&files, &home, &account, &archive, &"f".repeat(64)).is_err());
    assert!(!home.join(".orbit-store.lock").exists());
    let _held = lock(&home, &account, Instant::now() + Duration::from_secs(1)).unwrap();
    assert!(publish_node(
        &files,
        &home,
        &account,
        "24.1.0",
        &archive,
        &digest,
        Instant::now()
    )
    .is_err());
    assert!(!home.join("js_runtime").exists());
}
