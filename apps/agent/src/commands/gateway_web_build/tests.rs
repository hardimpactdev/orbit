use super::*;
use crate::{command::CommandError, commands::recipe::WorkDir};
use std::io::{Cursor, Write};
use zip::{write::SimpleFileOptions, CompressionMethod, ZipWriter};

fn archive(entries: &[(&str, &[u8])]) -> Vec<u8> {
    let mut zip = ZipWriter::new(Cursor::new(Vec::new()));
    for (name, bytes) in entries {
        let options = SimpleFileOptions::default()
            .compression_method(CompressionMethod::Stored)
            .unix_permissions(0o644);
        if name.ends_with('/') {
            zip.add_directory(*name, options).unwrap();
        } else {
            zip.start_file(*name, options).unwrap();
            zip.write_all(bytes).unwrap();
        }
    }
    zip.finish().unwrap().into_inner()
}
fn signature(bytes: &[u8], pattern: &[u8]) -> usize {
    bytes
        .windows(pattern.len())
        .position(|p| p == pattern)
        .unwrap()
}
fn set32(bytes: &mut [u8], offset: usize, value: u32) {
    bytes[offset..offset + 4].copy_from_slice(&value.to_le_bytes());
}
fn declared_size(bytes: &mut [u8], size: u32) {
    let local = signature(bytes, b"PK\x03\x04");
    let central = signature(bytes, b"PK\x01\x02");
    set32(bytes, local + 22, size);
    set32(bytes, central + 24, size);
}
struct Fixture {
    work: WorkDir,
    input: InstallInput,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let web_root = work.0.join("web");
        fs::create_dir_all(web_root.join("releases")).unwrap();
        let input = InstallInput {
            web_root,
            sha: "a".repeat(40),
            archive: work.0.join("artifact.zip"),
            sha256: String::new(),
        };
        let mut result = Self { work, input };
        result.bytes(&archive(&[
            ("index.html", b"<script src='assets/app.js'></script>"),
            ("assets/app.js", b"console.log(1)"),
        ]));
        result
    }
    fn bytes(&mut self, bytes: &[u8]) {
        fs::write(&self.input.archive, bytes).unwrap();
        self.input.sha256 = file_sha256(&self.input.archive).unwrap();
    }
    fn install(&self) -> Result<Installed, Error> {
        install(
            &self.input,
            rustix::process::getegid().as_raw(),
            Instant::now() + Duration::from_secs(20),
        )
    }
    fn release(&self, id: &str) -> PathBuf {
        self.input.web_root.join("releases").join(id)
    }
    fn input(&self, id: &str) -> ReleaseInput {
        ReleaseInput {
            web_root: self.input.web_root.clone(),
            release: id.into(),
        }
    }
    fn built(&self, id: &str) {
        fs::create_dir_all(self.release(id)).unwrap();
        fs::write(self.release(id).join("index.html"), id).unwrap();
    }
    fn current(&self, target: &str) {
        symlink(target, self.input.web_root.join("current")).unwrap();
    }
    fn link(&self) -> PathBuf {
        fs::read_link(self.input.web_root.join("current")).unwrap()
    }
    fn refusal_preserves_target(&self, expected: &str) {
        let target = self.release("aaaaaaaaaaaa");
        fs::create_dir_all(&target).unwrap();
        fs::write(target.join("preserved"), "old incomplete build").unwrap();
        assert_eq!(self.install().unwrap_err().code(), expected);
        assert_eq!(
            fs::read_to_string(target.join("preserved")).unwrap(),
            "old incomplete build"
        );
        assert!(!self.release(".aaaaaaaaaaaa.partial").exists());
        assert!(!self.input.web_root.join("current").exists());
    }
}
#[test]
fn installs_with_caddy_modes_reuses_without_reading_archive_and_replaces_only_incomplete_staging() {
    let f = Fixture::new();
    assert!(!inspect(f.input("aaaaaaaaaaaa")).unwrap().installed);
    assert_eq!(
        inspect(f.input("aaaaaaaaaaaa")).unwrap().archive_limit,
        ARCHIVE_BYTES
    );
    fs::create_dir(f.release(".aaaaaaaaaaaa.partial")).unwrap();
    fs::write(f.release(".aaaaaaaaaaaa.partial/stale"), "stale").unwrap();
    assert!(!f.install().unwrap().reused);
    let target = f.release("aaaaaaaaaaaa");
    assert_eq!(
        fs::read(target.join("assets/app.js")).unwrap(),
        b"console.log(1)"
    );
    for (path, mode) in [
        (&target, 0o750),
        (&target.join("assets"), 0o750),
        (&target.join("index.html"), 0o640),
        (&target.join("assets/app.js"), 0o640),
    ] {
        let m = fs::metadata(path).unwrap();
        assert_eq!(m.mode() & 0o777, mode);
        assert_eq!(m.gid(), rustix::process::getegid().as_raw());
        assert_eq!(m.uid(), identity::effective_uid());
    }
    let inode = fs::metadata(&target).unwrap().ino();
    fs::remove_file(&f.input.archive).unwrap();
    assert!(f.install().unwrap().reused);
    assert!(inspect(f.input("aaaaaaaaaaaa")).unwrap().installed);
    assert_eq!(fs::metadata(&target).unwrap().ino(), inode);
    assert_eq!(fs::read_dir(target.parent().unwrap()).unwrap().count(), 1);
    assert!(!f.input.web_root.join("current").exists());
}
#[test]
fn invalid_archives_refuse_before_changing_a_build() {
    for name in [
        "../escape",
        "assets/../../escape",
        "/absolute",
        "assets\\escape",
        "assets/./file",
        "assets//file",
        "control\nfile",
    ] {
        let mut f = Fixture::new();
        f.bytes(&archive(&[("index.html", b"ok"), (name, b"bad")]));
        f.refusal_preserves_target("gateway.release_web_build_invalid");
    }
    for entries in [
        vec![("assets/app.js", b"x".as_slice())],
        vec![("dist/index.html", b"x".as_slice())],
        vec![
            ("index.html", b"ok".as_slice()),
            ("index.html/child", b"x".as_slice()),
        ],
        vec![
            ("index.html/child", b"x".as_slice()),
            ("index.html", b"ok".as_slice()),
        ],
    ] {
        let mut f = Fixture::new();
        f.bytes(&archive(&entries));
        f.refusal_preserves_target("gateway.release_web_build_invalid");
    }
    for fault in [
        "not-zip",
        "symlink",
        "special",
        "encrypted",
        "crc",
        "size",
        "expanded",
        "duplicate",
        "header",
    ] {
        let mut f = Fixture::new();
        let mut bytes = archive(&[("index.html", b"ok"), ("other.html", b"same-length-name")]);
        let central = signature(&bytes, b"PK\x01\x02");
        let local = signature(&bytes, b"PK\x03\x04");
        match fault {
            "not-zip" => bytes = b"not a zip".to_vec(),
            "symlink" | "special" => set32(
                &mut bytes,
                central + 38,
                (if fault == "symlink" {
                    0o120777
                } else {
                    0o010644
                }) << 16,
            ),
            "encrypted" => {
                bytes[central + 8] |= 1;
                bytes[local + 6] |= 1;
            }
            "crc" => {
                set32(&mut bytes, central + 16, 0);
                set32(&mut bytes, local + 14, 0);
            }
            "size" => declared_size(&mut bytes, 3),
            "expanded" => declared_size(&mut bytes, EXPANDED_BYTES as u32 + 1),
            "header" => bytes[local + 30] = b'X',
            "duplicate" => {
                let needle = b"other.html";
                let positions: Vec<_> = bytes
                    .windows(needle.len())
                    .enumerate()
                    .filter_map(|(i, b)| (b == needle).then_some(i))
                    .collect();
                for offset in positions {
                    bytes[offset..offset + needle.len()].copy_from_slice(b"index.html");
                }
            }
            _ => unreachable!(),
        }
        f.bytes(&bytes);
        f.refusal_preserves_target("gateway.release_web_build_invalid");
    }
}
#[test]
fn entry_and_archive_limits_refuse_without_allocating_expanded_files() {
    let mut f = Fixture::new();
    let mut writer = ZipWriter::new(Cursor::new(Vec::new()));
    for i in 0..=ENTRIES {
        writer
            .start_file(format!("file-{i}"), SimpleFileOptions::default())
            .unwrap();
    }
    f.bytes(&writer.finish().unwrap().into_inner());
    f.refusal_preserves_target("gateway.release_web_build_invalid");
    let f = Fixture::new();
    fs::OpenOptions::new()
        .write(true)
        .open(&f.input.archive)
        .unwrap()
        .set_len(ARCHIVE_BYTES + 1)
        .unwrap();
    f.refusal_preserves_target("gateway.release_web_build_invalid");
}
#[test]
fn invalid_identity_hash_and_paths_refuse_before_mutation() {
    for fault in [
        "sha",
        "digest",
        "digest-format",
        "source-link",
        "source-directory",
        "source-missing",
        "staging-link",
    ] {
        let mut f = Fixture::new();
        match fault {
            "sha" => f.input.sha = "main".into(),
            "digest" => f.input.sha256 = "0".repeat(64),
            "digest-format" => f.input.sha256 = "invalid".into(),
            "source-link" => {
                let link = f.work.0.join("alias.zip");
                symlink(&f.input.archive, &link).unwrap();
                f.input.archive = link;
            }
            "source-directory" => f.input.archive = f.work.0.clone(),
            "source-missing" => fs::remove_file(&f.input.archive).unwrap(),
            "staging-link" => symlink(&f.work.0, f.release(".aaaaaaaaaaaa.partial")).unwrap(),
            _ => unreachable!(),
        }
        if fault == "staging-link" {
            assert_eq!(
                f.install().unwrap_err().code(),
                "gateway.release_web_build_invalid"
            );
            assert_eq!(
                fs::read_link(f.release(".aaaaaaaaaaaa.partial")).unwrap(),
                f.work.0
            );
        } else {
            f.refusal_preserves_target("gateway.release_web_build_invalid");
        }
    }
    for fault in ["missing", "web-link", "releases-link"] {
        let f = Fixture::new();
        let path = if fault == "web-link" {
            &f.input.web_root
        } else {
            &f.input.web_root.join("releases")
        };
        fs::rename(path, f.work.0.join("kept")).unwrap();
        if fault != "missing" {
            symlink(f.work.0.join("kept"), path).unwrap();
        }
        assert_eq!(
            f.install().unwrap_err().code(),
            "gateway.release_web_directory_missing"
        );
    }
}
#[test]
fn permission_failure_and_expired_deadline_keep_existing_build_and_clean_new_staging() {
    let f = Fixture::new();
    let target = f.release("aaaaaaaaaaaa");
    fs::create_dir(&target).unwrap();
    fs::write(target.join("preserved"), "old").unwrap();
    assert_eq!(
        install(
            &f.input,
            u32::MAX - 1,
            Instant::now() + Duration::from_secs(20)
        )
        .unwrap_err()
        .code(),
        "gateway.release_web_install_failed"
    );
    assert_eq!(fs::read_to_string(target.join("preserved")).unwrap(), "old");
    assert!(!f.release(".aaaaaaaaaaaa.partial").exists());
    assert_eq!(
        install(
            &f.input,
            rustix::process::getegid().as_raw(),
            Instant::now()
        )
        .unwrap_err()
        .code(),
        "gateway.release_web_install_failed"
    );
    assert_eq!(fs::read_to_string(target.join("preserved")).unwrap(), "old");
}
#[test]
fn publish_repeats_and_restore_preserves_the_exact_previous_absolute_or_relative_target() {
    for absolute in [false, true] {
        let f = Fixture::new();
        for id in ["aaaaaaaaaaaa", "bbbbbbbbbbbb", "cccccccccccc"] {
            f.built(id);
        }
        let previous = if absolute {
            f.release("cccccccccccc").to_str().unwrap().to_owned()
        } else {
            "releases/cccccccccccc".into()
        };
        f.current(&previous);
        let result = publish(f.input("bbbbbbbbbbbb")).unwrap();
        assert_eq!(result.previous.as_deref(), Some(previous.as_str()));
        assert_eq!(f.link(), Path::new("releases/bbbbbbbbbbbb"));
        assert_eq!(
            publish(f.input("bbbbbbbbbbbb"))
                .unwrap()
                .previous
                .as_deref(),
            Some("releases/bbbbbbbbbbbb")
        );
        for expected in [true, false] {
            let result = restore(RestoreInput {
                web_root: f.input.web_root.clone(),
                release: "aaaaaaaaaaaa".into(),
                previous: Some(previous.clone()),
            })
            .unwrap();
            assert_eq!(result.changed, expected);
            assert_eq!(f.link(), Path::new(&previous));
        }
        assert_eq!(fs::read_dir(&f.input.web_root).unwrap().count(), 2);
    }
    let f = Fixture::new();
    assert!(
        !restore(RestoreInput {
            web_root: f.input.web_root.clone(),
            release: "aaaaaaaaaaaa".into(),
            previous: None
        })
        .unwrap()
        .changed
    );
    f.built("aaaaaaaaaaaa");
    assert!(
        restore(RestoreInput {
            web_root: f.input.web_root.clone(),
            release: "aaaaaaaaaaaa".into(),
            previous: None
        })
        .unwrap()
        .changed
    );
}
#[test]
fn missing_or_unsafe_publication_preserves_current_and_removal_never_follows_a_link() {
    let f = Fixture::new();
    f.built("aaaaaaaaaaaa");
    f.current("releases/aaaaaaaaaaaa");
    assert_eq!(
        publish(f.input("bbbbbbbbbbbb")).unwrap_err().code(),
        "gateway.release_web_build_missing"
    );
    for previous in [
        "../outside",
        "releases/../../outside",
        "/outside",
        "releases/main",
    ] {
        assert_eq!(
            restore(RestoreInput {
                web_root: f.input.web_root.clone(),
                release: "aaaaaaaaaaaa".into(),
                previous: Some(previous.into())
            })
            .unwrap_err()
            .code(),
            "gateway.release_web_publish_failed"
        );
        assert_eq!(f.link(), Path::new("releases/aaaaaaaaaaaa"));
    }
    assert!(!remove(f.input("aaaaaaaaaaaa")).unwrap().changed);
    f.built("bbbbbbbbbbbb");
    fs::write(f.work.0.join("outside"), "preserved").unwrap();
    symlink(f.work.0.join("outside"), f.release("bbbbbbbbbbbb/link")).unwrap();
    assert!(remove(f.input("bbbbbbbbbbbb")).unwrap().changed);
    assert!(!remove(f.input("bbbbbbbbbbbb")).unwrap().changed);
    assert_eq!(
        fs::read_to_string(f.work.0.join("outside")).unwrap(),
        "preserved"
    );
    fs::remove_file(f.input.web_root.join("current")).unwrap();
    f.current("../outside");
    assert_eq!(
        publish(f.input("aaaaaaaaaaaa")).unwrap_err().code(),
        "gateway.release_web_publish_failed"
    );
    assert_eq!(f.link(), Path::new("../outside"));
}
#[test]
fn prune_preserves_retained_current_and_partial_builds_and_validates_before_removal() {
    let f = Fixture::new();
    for id in [
        "aaaaaaaaaaaa",
        "bbbbbbbbbbbb",
        "cccccccccccc",
        "dddddddddddd",
        ".eeeeeeeeeeee.partial",
    ] {
        f.built(id);
    }
    f.current(f.release("aaaaaaaaaaaa").to_str().unwrap());
    for retained in [vec![], vec!["invalid".into()]] {
        let result = prune(PruneInput {
            web_root: f.input.web_root.clone(),
            retained,
        });
        assert!(result.is_err() || !result.unwrap().changed);
        assert!(f.release("cccccccccccc").exists());
    }
    for changed in [true, false] {
        assert_eq!(
            prune(PruneInput {
                web_root: f.input.web_root.clone(),
                retained: vec!["bbbbbbbbbbbb".into()]
            })
            .unwrap()
            .changed,
            changed
        );
        assert!(f.release("aaaaaaaaaaaa").exists());
        assert!(f.release("bbbbbbbbbbbb").exists());
        assert!(f.release(".eeeeeeeeeeee.partial").exists());
        assert!(!f.release("cccccccccccc").exists());
        assert!(!f.release("dddddddddddd").exists());
    }
    f.built("dddddddddddd");
    symlink(&f.work.0, f.release("cccccccccccc")).unwrap();
    assert!(prune(PruneInput {
        web_root: f.input.web_root.clone(),
        retained: vec!["bbbbbbbbbbbb".into()]
    })
    .is_err());
    assert!(f.release("dddddddddddd").exists());
}
#[test]
#[ignore = "invoked in an isolated process by restrictive_umask"]
fn restrictive_umask_child() {
    if std::env::var_os("ORBIT_WEB_UMASK_CHILD").is_none() {
        return;
    }
    let f = Fixture::new();
    // SAFETY: this test runs alone in a child process.
    unsafe {
        libc::umask(0o777);
    }
    f.install().unwrap();
    assert_eq!(
        fs::metadata(f.release("aaaaaaaaaaaa/assets"))
            .unwrap()
            .mode()
            & 0o777,
        0o750
    );
    assert_eq!(
        fs::metadata(f.release("aaaaaaaaaaaa/assets/app.js"))
            .unwrap()
            .mode()
            & 0o777,
        0o640
    );
}
#[test]
fn restrictive_umask() {
    let result = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--exact",
            "commands::gateway_web_build::tests::restrictive_umask_child",
            "--ignored",
        ])
        .env("ORBIT_WEB_UMASK_CHILD", "1")
        .output()
        .unwrap();
    assert!(
        result.status.success(),
        "{}",
        String::from_utf8_lossy(&result.stdout)
    );
}

#[test]
fn web_mutations_declare_root_and_share_the_directory_convergence_resource() {
    assert_eq!(
        Inspect::privilege(Platform::Linux),
        Some(Privilege::Account)
    );
    for privilege in [
        Install::privilege(Platform::Linux),
        Publish::privilege(Platform::Linux),
        Restore::privilege(Platform::Linux),
        Remove::privilege(Platform::Linux),
        Prune::privilege(Platform::Linux),
    ] {
        assert_eq!(privilege, Some(Privilege::Root));
    }
    assert_eq!(
        Install::RESOURCES,
        super::super::gateway_web::PrepareDirectory::RESOURCES
    );
}
