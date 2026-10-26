use super::*;
pub(super) struct Fixture(pub(super) PathBuf);
impl Fixture {
    pub(super) fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-seed-cache-{:016x}", rand::random::<u64>()));
        fs::create_dir(&root).unwrap();
        Self(root)
    }
    pub(super) fn shared(&self) -> PathBuf {
        self.0.join("shared")
    }
    pub(super) fn home(&self) -> PathBuf {
        self.0.join("home")
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        fs::remove_dir_all(&self.0).unwrap();
    }
}
fn archive(path: &str, link: Option<&str>) -> Vec<u8> {
    let encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
    let mut tar = tar::Builder::new(encoder);
    let mut header = tar::Header::new_gnu();
    header.set_mode(0o644);
    header.set_size(if link.is_some() { 0 } else { 2 });
    let name = path.as_bytes();
    header.as_mut_bytes()[..name.len()].copy_from_slice(name);
    if let Some(link) = link {
        header.set_entry_type(tar::EntryType::Symlink);
        header.set_link_name(link).unwrap();
    }
    header.set_cksum();
    tar.append(&header, if link.is_some() { &b""[..] } else { &b"{}"[..] })
        .unwrap();
    tar.into_inner().unwrap().finish().unwrap()
}
fn cached(f: &Fixture, bytes: &[u8], npm: bool) -> Artifact {
    let url = if npm {
        "https://registry.npmjs.org/demo/-/demo-1.0.0.tgz".into()
    } else {
        format!(
            "https://api.github.com/repos/acme/demo/zipball/{}",
            "a".repeat(40)
        )
    };
    let integrity = if npm {
        format!("sha512-{}", STANDARD.encode(sha2::Sha512::digest(bytes)))
    } else {
        format!("sha1-{}", hex(&sha1::Sha1::digest(bytes)))
    };
    let key = if npm {
        format!(
            "npm/{}.tgz",
            hex(&Sha256::digest(format!("{url} {integrity}")))
        )
    } else {
        format!("composer/acme/demo/{}.zip", hex(&sha1::Sha1::digest(&url)))
    };
    let a = Artifact {
        manager: if npm { "npm" } else { "composer" }.into(),
        package: if npm { "demo" } else { "acme/demo" }.into(),
        version: "1.0.0".into(),
        url,
        integrity: Some(integrity),
        key,
    };
    initialize(&f.shared()).unwrap();
    let target = f.shared().join(&a.key);
    fs::create_dir_all(target.parent().unwrap()).unwrap();
    fs::write(target, bytes).unwrap();
    atomic(
        &f.shared().join(".inventory.json"),
        &serde_json::to_vec(&BTreeMap::from([(
            a.key.clone(),
            hex(&Sha256::digest(bytes)),
        )]))
        .unwrap(),
        0o644,
    )
    .unwrap();
    a
}
#[test]
fn composer_copies_missing_archives_once_without_sharing_inodes_or_writes() {
    let f = Fixture::new();
    let a = cached(&f, b"archive", false);
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    let target = f
        .home()
        .join(".cache/composer/files")
        .join(a.key.strip_prefix("composer/").unwrap());
    assert_eq!(fs::read(&target).unwrap(), b"archive");
    assert_ne!(
        fs::metadata(&target).unwrap().ino(),
        fs::metadata(f.shared().join(&a.key)).unwrap().ino()
    );
    fs::write(&target, "private").unwrap();
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    assert_eq!(fs::read(&target).unwrap(), b"private");
    assert_eq!(fs::read(f.shared().join(&a.key)).unwrap(), b"archive");
}
#[test]
fn npm_content_and_named_root_bun_archives_seed_without_running_scripts() {
    for root in ["package", "demo"] {
        let f = Fixture::new();
        let bytes = archive(&format!("{root}/package.json"), None);
        let a = cached(&f, &bytes, true);
        seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
        seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
        let content = hex(&sha2::Sha512::digest(&bytes));
        let target = f
            .home()
            .join(".npm/_cacache/content-v2/sha512")
            .join(&content[..2])
            .join(&content[2..4])
            .join(&content[4..]);
        assert_eq!(fs::read(target).unwrap(), bytes);
        assert_eq!(
            fs::read(
                f.home()
                    .join(".bun/install/cache/demo@1.0.0@@@1/package.json")
            )
            .unwrap(),
            b"{}"
        );
    }
}
#[test]
fn unverified_or_unselected_archives_do_not_create_private_files() {
    let f = Fixture::new();
    let a = cached(&f, b"archive", false);
    seed(&f.shared(), &f.home(), &[]).unwrap();
    assert!(!f.home().exists());
    fs::write(f.shared().join(&a.key), "corrupt").unwrap();
    seed(&f.shared(), &f.home(), &[a]).unwrap();
    assert!(!f.home().exists());
}
#[test]
fn traversal_and_escaping_links_never_publish_a_bun_directory() {
    for (path, link) in [
        ("../escape", None),
        ("/absolute", None),
        ("package/link", Some("../../escape")),
        ("package/link", Some("/tmp/escape")),
        ("package", Some("../escape")),
    ] {
        let f = Fixture::new();
        let bytes = archive(path, link);
        let a = cached(&f, &bytes, true);
        assert!(seed(&f.shared(), &f.home(), &[a]).is_err(), "{path}");
        assert!(!f.home().join(".bun/install/cache/demo@1.0.0@@@1").exists());
        let dir = f.home().join(".bun/install/cache");
        assert_eq!(fs::read_dir(dir).unwrap().count(), 0);
        assert!(!f.0.join("escape").exists());
    }
}
#[test]
fn marker_wait_is_bounded_and_access_errors_are_immediate() {
    let f = Fixture::new();
    let mut waited = 0;
    assert!(await_mount(&f.shared(), || waited += 1).is_err());
    assert_eq!(waited, 100);
    initialize(&f.shared()).unwrap();
    await_mount(&f.shared(), || panic!("unexpected wait")).unwrap();
    fs::set_permissions(f.shared(), fs::Permissions::from_mode(0o000)).unwrap();
    let result = await_mount(&f.shared(), || panic!("access denial must not wait"));
    fs::set_permissions(f.shared(), fs::Permissions::from_mode(0o755)).unwrap();
    assert!(matches!(result,Err(Error::Failed{path,..}) if path.ends_with(".orbit-owned")));
    assert_eq!(SeedCache::privilege(Platform::Macos), None);
}

#[test]
fn registry_directory_modes_cannot_prevent_seeding_their_children() {
    for mode in [0o666, 0o444, 0o555, 0o000] {
        let f = Fixture::new();
        let encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
        let mut tar = tar::Builder::new(encoder);
        for path in ["package", "package/lib"] {
            let mut header = tar::Header::new_gnu();
            header.set_entry_type(tar::EntryType::Directory);
            header.set_mode(mode);
            header.set_size(0);
            header.set_cksum();
            tar.append_data(&mut header, path, io::empty()).unwrap();
        }
        for (path, mode) in [
            ("package/CHANGELOG.md", 0o644),
            ("package/lib/cli.js", 0o755),
        ] {
            let mut header = tar::Header::new_gnu();
            header.set_mode(mode);
            header.set_size(2);
            header.set_cksum();
            tar.append_data(&mut header, path, &b"{}"[..]).unwrap();
        }
        let bytes = tar.into_inner().unwrap().finish().unwrap();
        let a = cached(&f, &bytes, true);

        let result = seed(&f.shared(), &f.home(), std::slice::from_ref(&a));
        // Restore search permission if the negative control leaves an inaccessible directory.
        let cache = f.home().join(".bun/install/cache");
        for entry in fs::read_dir(&cache).unwrap() {
            let path = entry.unwrap().path();
            for dir in [path.join("package"), path.join("package/lib")] {
                let _ = fs::set_permissions(dir, fs::Permissions::from_mode(0o755));
            }
        }
        result.unwrap();
        let target = cache.join("demo@1.0.0@@@1");
        assert_eq!(fs::read(target.join("CHANGELOG.md")).unwrap(), b"{}");
        assert_eq!(
            fs::metadata(target.join("lib/cli.js")).unwrap().mode() & 0o777,
            0o755
        );
        assert_eq!(fs::metadata(&target).unwrap().mode() & 0o700, 0o700);
        fs::write(target.join("lib/private"), b"private").unwrap();
        assert_eq!(fs::read(f.shared().join(&a.key)).unwrap(), bytes);
    }
}

#[test]
fn extraction_failures_identify_the_package_archive_and_underlying_cause() {
    let f = Fixture::new();
    // A valid gzip stream containing a tar file whose declared data is cut short.
    let mut plain = Vec::new();
    flate2::read::GzDecoder::new(&archive("package/file", None)[..])
        .read_to_end(&mut plain)
        .unwrap();
    plain.truncate(513);
    let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
    std::io::Write::write_all(&mut encoder, &plain).unwrap();
    let a = cached(&f, &encoder.finish().unwrap(), true);

    let error = seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap_err();

    let Error::Failed {
        operation,
        path,
        reason,
    } = error
    else {
        panic!("wrong error");
    };
    assert_eq!(operation, "seed");
    assert_eq!(path, f.shared().join(&a.key).display().to_string());
    assert!(reason.contains("demo@1.0.0"), "{reason}");
    assert!(reason.contains("package/file"), "{reason}");
    assert!(reason.contains("failed to write entire file"), "{reason}");
    assert_eq!(
        fs::read_dir(f.home().join(".bun/install/cache"))
            .unwrap()
            .count(),
        0
    );
}
