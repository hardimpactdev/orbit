use super::*;

fn download(f: &mut Fixture) -> Result<Vec<PathBuf>> {
    let work = recipe::WorkDir::new().unwrap();
    packages::download(
        &mut f.host,
        &f.files,
        &f.input.versions["8.5"].packages,
        &f.input.mirror,
        &work.0,
        "amd64",
    )
}

#[test]
fn partial_mirror_failure_retries_only_failed_transfers() {
    let mut f = Fixture::new();
    let pin = &f.input.versions["8.5"].packages[0];
    f.host.artifacts.remove(&format!(
        "{}/{}_{}.deb",
        f.input.mirror, pin.name, pin.sha256
    ));
    let files = download(&mut f).unwrap();
    assert_eq!(files.len(), 2);
    let calls = f.calls("curl");
    assert_eq!(calls.len(), 2);
    assert_eq!(calls[0].argv.iter().filter(|a| *a == "--url").count(), 2);
    assert_eq!(calls[1].argv.iter().filter(|a| *a == "--url").count(), 1);
    assert!(calls[1]
        .argv
        .contains(&f.input.versions["8.5"].packages[0].url));
    assert!(calls.iter().all(|c| c.timeout == Duration::from_secs(300)));
}

#[test]
fn exhausted_sources_and_corrupt_mirror_never_install_or_try_valid_upstream() {
    let mut f = Fixture::new();
    f.host.artifacts.clear();
    assert_eq!(download(&mut f).unwrap_err().kind, Kind::DownloadFailed);
    assert_eq!(f.calls("curl").len(), 2);
    let mut f = Fixture::new();
    let pin = &f.input.versions["8.5"].packages[0];
    f.host.artifacts.insert(
        format!("{}/{}_{}.deb", f.input.mirror, pin.name, pin.sha256),
        b"corrupt".to_vec(),
    );
    assert_eq!(download(&mut f).unwrap_err().kind, Kind::DownloadFailed);
    assert_eq!(f.calls("curl").len(), 1);
    assert!(f.calls("apt-get").is_empty());
}

#[test]
fn verified_digest_with_wrong_metadata_still_refuses() {
    let mut f = Fixture::new();
    let pin = &mut f.input.versions.get_mut("8.5").unwrap().packages[0];
    let wrong = bytes(&pin.name, "9.0", "amd64");
    pin.sha256 = format!("{:x}", Sha256::digest(&wrong));
    f.host.artifacts.insert(
        format!("{}/{}_{}.deb", f.input.mirror, pin.name, pin.sha256),
        wrong,
    );
    assert_eq!(download(&mut f).unwrap_err().kind, Kind::DownloadFailed);
    assert_eq!(f.calls("curl").len(), 1);
}

#[test]
fn corrupt_cache_is_replaced_atomically_and_valid_cache_needs_no_network() {
    let mut f = Fixture::new();
    for pin in &f.input.versions["8.5"].packages {
        write(
            &f.files,
            &format!("{CACHE}/{}_{}.deb", pin.name, pin.sha256),
            "corrupt",
        );
    }
    let paths = download(&mut f).unwrap();
    for (path, pin) in paths.iter().zip(&f.input.versions["8.5"].packages) {
        assert_eq!(
            fs::read(path).unwrap(),
            bytes(&pin.name, &pin.version, "amd64")
        );
    }
    f.host.calls.clear();
    assert_eq!(download(&mut f).unwrap(), paths);
    assert!(f.calls("curl").is_empty());
    assert_eq!(super::super::paths(&f.files.path(CACHE)).unwrap().len(), 2);
}

#[test]
fn cache_publication_refuses_symlinks_without_overwriting_the_target() {
    let f = Fixture::new();
    let data = bytes("php8.5-cli", "2.0", "amd64");
    let source = write(&f.files, "/source.deb", &data);
    let target = write(&f.files, "/protected", "keep");
    let cache = f.files.path(CACHE);
    fs::create_dir_all(&cache).unwrap();
    let destination = cache.join(format!("php8.5-cli_{:x}.deb", Sha256::digest(data)));
    symlink(&target, &destination).unwrap();
    assert!(packages::keep(&f.files, &source, "php8.5-cli").is_err());
    assert_eq!(fs::read_to_string(target).unwrap(), "keep");
    assert_eq!(paths(&cache).unwrap(), [destination]);
}

#[test]
fn rollback_cache_requires_matching_digest_and_architecture() {
    for arch in ["amd64", "arm64"] {
        let mut f = Fixture::new();
        f.host.packages.insert("php8.5-cli".into(), "1.0".into());
        let data = bytes("php8.5-cli", "1.0", arch);
        let source = write(&f.files, "/old.deb", &data);
        let cached = packages::keep(&f.files, &source, "php8.5-cli").unwrap();
        if arch == "amd64" {
            fs::write(cached, "corruption").unwrap();
        }
        let work = recipe::WorkDir::new().unwrap();
        let mut installed = f.host.packages.clone();
        let prior = packages::previous(
            &mut f.host,
            &f.files,
            &["php8.5-cli".into()],
            &mut installed,
            &f.input.repack,
            &work.0,
            "amd64",
        )
        .unwrap();
        assert_eq!(
            fs::read(&prior["php8.5-cli"]).unwrap(),
            bytes("php8.5-cli", "1.0", "amd64")
        );
        assert_eq!(f.calls("env").len(), 1);
    }
}

#[cfg(target_os = "linux")]
#[test]
fn real_apt_archive_is_checked_against_installed_dpkg_checksums_before_rollback() {
    struct Archives(Fake);
    impl RecipeHost for Archives {
        fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
            if ["dpkg-deb", "md5sum"].contains(&call.argv[0].as_str()) {
                self.0.calls.push(call.clone());
                let result = std::process::Command::new(&call.argv[0])
                    .args(&call.argv[1..])
                    .env("LC_ALL", "C")
                    .output()
                    .unwrap();
                return Ok(Output {
                    code: result.status.code().unwrap_or(-1),
                    stdout: String::from_utf8_lossy(&result.stdout).into(),
                    stderr: String::from_utf8_lossy(&result.stderr).into(),
                    truncated: false,
                });
            }
            self.0.execute(call)
        }
        fn wait(&mut self, delay: Duration) -> std::result::Result<(), recipe::Failure> {
            self.0.wait(delay)
        }
    }
    for corrupt in [false, true] {
        let mut f = Fixture::new();
        let name = "php8.5-cli";
        f.host.packages.insert(name.into(), "1.0".into());
        let installed_file = write(&f.files, "/usr/bin/php8.5", "original runtime");
        let sum = std::process::Command::new("md5sum")
            .arg(&installed_file)
            .output()
            .unwrap();
        let digest = String::from_utf8(sum.stdout)
            .unwrap()
            .split_whitespace()
            .next()
            .unwrap()
            .to_owned();
        write(
            &f.files,
            "/var/lib/dpkg/info/php8.5-cli.md5sums",
            format!("{digest}  usr/bin/php8.5\n"),
        );
        let tree = f.files.path("/archive-tree");
        fs::create_dir_all(tree.join("DEBIAN")).unwrap();
        fs::create_dir_all(tree.join("usr/bin")).unwrap();
        fs::write(tree.join("DEBIAN/control"), "Package: php8.5-cli\nVersion: 1.0\nArchitecture: amd64\nMaintainer: Orbit <test@example.test>\nDescription: Rollback fixture\n").unwrap();
        fs::write(
            tree.join("usr/bin/php8.5"),
            if corrupt {
                "modified runtime"
            } else {
                "original runtime"
            },
        )
        .unwrap();
        let archive = f.files.path(ARCHIVES).join("php8.5-cli_1.0_amd64.deb");
        fs::create_dir_all(archive.parent().unwrap()).unwrap();
        assert!(std::process::Command::new("dpkg-deb")
            .args(["--build", "--root-owner-group"])
            .arg(&tree)
            .arg(&archive)
            .output()
            .unwrap()
            .status
            .success());
        // If the archive is rejected, prove that it reaches repacking rather than accepting different bytes.
        f.fail("apt-get", 1);
        let mut installed = f.host.packages.clone();
        let mut host = Archives(f.host);
        let work = recipe::WorkDir::new().unwrap();
        let result = packages::previous(
            &mut host,
            &f.files,
            &[name.into()],
            &mut installed,
            &f.input.repack,
            &work.0,
            "amd64",
        );
        if corrupt {
            assert_eq!(result.unwrap_err().kind, Kind::RollbackUnavailable);
        } else {
            let previous = result.unwrap();
            assert_eq!(
                fs::read(&previous[name]).unwrap(),
                fs::read(archive).unwrap()
            );
            assert!(!host.0.calls.iter().any(|c| c.argv[0] == "apt-get"));
        }
    }
}

#[test]
fn every_queued_download_batch_fits_in_the_source_pass_budget() {
    let mut f = Fixture::new();
    let pins: Vec<_> = (0..9)
        .map(|i| pin(&format!("php8.5-extra{i}"), "1.0"))
        .collect();
    f.input.versions.get_mut("8.5").unwrap().packages = pins;
    f.host.artifacts.clear();
    assert_eq!(download(&mut f).unwrap_err().kind, Kind::DownloadFailed);
    for call in f.calls("curl") {
        assert_eq!(call.timeout, Duration::from_secs(300));
        assert_eq!(call.argv.iter().filter(|a| *a == "--max-time").count(), 9);
        for pair in call.argv.windows(2).filter(|w| w[0] == "--max-time") {
            assert_eq!(pair[1], "100");
        }
    }
    assert_eq!(f.calls("curl").len(), 2);
}
