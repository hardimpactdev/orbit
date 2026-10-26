use super::*;
struct Fixture(std::path::PathBuf);
impl Fixture {
    fn new() -> Self {
        let p = std::env::temp_dir().join(format!("orbit-cache-{:016x}", rand::random::<u64>()));
        fs::create_dir(&p).unwrap();
        Self(p)
    }
    fn cache(&self) -> std::path::PathBuf {
        self.0.join("cache")
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        fs::remove_dir_all(&self.0).unwrap();
    }
}
struct Download {
    bytes: Vec<u8>,
    calls: Vec<String>,
    failure: Option<String>,
}
impl Download {
    fn new(bytes: &[u8]) -> Self {
        Self {
            bytes: bytes.into(),
            calls: vec![],
            failure: None,
        }
    }
}
impl Fetch for Download {
    fn download(&mut self, url: &str, path: &Path, limit: u64) -> Result<u64, String> {
        self.calls.push(url.into());
        if let Some(reason) = &self.failure {
            return Err(reason.clone());
        }
        if self.bytes.len() as u64 > limit {
            return Err("Cache full".into());
        }
        fs::write(path, &self.bytes).unwrap();
        Ok(self.bytes.len() as u64)
    }
}
pub(super) fn composer(bytes: &[u8]) -> Artifact {
    let url = format!(
        "https://api.github.com/repos/acme/demo/zipball/{}",
        "a".repeat(40)
    );
    Artifact {
        manager: "composer".into(),
        package: "acme/demo".into(),
        version: "1.0.0".into(),
        key: format!("composer/acme/demo/{}.zip", hex(&sha1::Sha1::digest(&url))),
        url,
        integrity: Some(format!("sha1-{}", hex(&sha1::Sha1::digest(bytes)))),
    }
}
fn npm(bytes: &[u8]) -> Artifact {
    let url = "https://registry.npmjs.org/demo/-/demo-1.0.0.tgz".to_string();
    let integrity = format!("sha512-{}", STANDARD.encode(sha2::Sha512::digest(bytes)));
    Artifact {
        manager: "npm".into(),
        package: "demo".into(),
        version: "1.0.0".into(),
        key: format!(
            "npm/{}.tgz",
            hex(&Sha256::digest(format!("{url} {integrity}")))
        ),
        url,
        integrity: Some(integrity),
    }
}
#[test]
fn initializes_repeats_and_refuses_unowned_or_unsafe_roots() {
    let f = Fixture::new();
    initialize(&f.cache()).unwrap();
    initialize(&f.cache()).unwrap();
    assert_eq!(
        fs::read_to_string(f.cache().join(".orbit-owned")).unwrap(),
        MARKER
    );
    fs::write(f.cache().join(".orbit-owned"), "foreign").unwrap();
    assert!(initialize(&f.cache()).is_err());
    let unowned = f.0.join("foreign");
    fs::create_dir(&unowned).unwrap();
    fs::write(unowned.join("file"), "kept").unwrap();
    assert!(initialize(&unowned).is_err());
    assert!(!unowned.join(".orbit-owned").exists());
    let link = f.0.join("link");
    std::os::unix::fs::symlink(f.cache(), &link).unwrap();
    assert!(initialize(&link).is_err());
    let unsafe_root = f.0.join("unsafe");
    fs::create_dir(&unsafe_root).unwrap();
    fs::set_permissions(&unsafe_root, fs::Permissions::from_mode(0o777)).unwrap();
    assert!(initialize(&unsafe_root).is_err());
}
#[test]
fn fills_additively_reuses_verified_hits_and_quarantines_corruption() {
    let f = Fixture::new();
    let a = composer(b"archive");
    let mut fetch = Download::new(b"archive");
    let first = fill(&f.cache(), std::slice::from_ref(&a), 10000, &mut fetch).unwrap();
    assert_eq!(
        first.hashes[&a.key],
        format!("sha256-{}", hex(&Sha256::digest(b"archive")))
    );
    let target = f.cache().join(&a.key);
    let stamp = fs::metadata(&target).unwrap().modified().unwrap();
    assert_eq!(
        fill(&f.cache(), std::slice::from_ref(&a), 10000, &mut fetch).unwrap(),
        first
    );
    assert_eq!(fetch.calls.len(), 1);
    assert_eq!(fs::metadata(&target).unwrap().modified().unwrap(), stamp);
    assert_eq!(
        fill(&f.cache(), &[], 10000, &mut fetch).unwrap(),
        Filled::default()
    );
    fs::set_permissions(&target, fs::Permissions::from_mode(0o644)).unwrap();
    fs::write(&target, "corrupt").unwrap();
    assert_eq!(fill(&f.cache(), &[a], 10000, &mut fetch).unwrap(), first);
    assert_eq!(fs::read(&target).unwrap(), b"archive");
    assert_eq!(
        fs::read(
            fs::read_dir(f.cache().join("quarantine"))
                .unwrap()
                .next()
                .unwrap()
                .unwrap()
                .path()
        )
        .unwrap(),
        b"corrupt"
    );
}
#[test]
fn invalid_limit_changes_nothing_and_invalid_artifacts_do_not_download() {
    let f = Fixture::new();
    let mut fetch = Download::new(b"archive");
    assert!(matches!(
        fill(&f.cache(), &[], 0, &mut fetch),
        Err(Error::Invalid {})
    ));
    assert!(!f.cache().exists());
    let mut artifacts = vec![];
    let mut bad = composer(b"archive");
    bad.key = "../outside".into();
    artifacts.push(bad);
    let mut bad = composer(b"archive");
    bad.url = "http://127.0.0.1/secret".into();
    artifacts.push(bad);
    let mut bad = npm(b"archive");
    bad.integrity = Some("md5-AAAA".into());
    artifacts.push(bad);
    let result = fill(&f.cache(), &artifacts, 10000, &mut fetch).unwrap();
    assert_eq!(result.failures.len(), 3);
    assert!(fetch.calls.is_empty());
    assert!(!f.0.join("outside").exists());
}
#[test]
fn a_failed_artifact_does_not_hide_later_success_and_no_temporary_file_survives() {
    let f = Fixture::new();
    let mut fetch = Download::new(b"archive");
    let bad = composer(b"wrong");
    let good = npm(b"archive");
    let result = fill(&f.cache(), &[bad.clone(), good.clone()], 10000, &mut fetch).unwrap();
    assert_eq!(result.failures[&bad.key], "Archive checksum mismatch");
    assert!(result.hashes.contains_key(&good.key));
    assert!(!f.cache().join(&bad.key).exists());
    assert!(!fs::read_dir(f.cache()).unwrap().any(|e| e
        .unwrap()
        .file_name()
        .to_string_lossy()
        .starts_with(".download-")));
    let f = Fixture::new();
    assert_eq!(
        fill(&f.cache(), &[good], 1, &mut fetch)
            .unwrap()
            .failures
            .values()
            .next()
            .unwrap(),
        "Cache full"
    );
}
#[test]
fn only_same_commit_github_redirects_and_registry_archives_are_allowed() {
    let pin = "a".repeat(40);
    let original = format!("https://api.github.com/repos/acme/demo/zipball/{pin}");
    let moved = format!("https://api.github.com/repositories/77/zipball/{pin}");
    let final_url = format!("https://codeload.github.com/moved/demo/legacy.zip/{pin}");
    assert!(allowed(&original, false));
    assert!(!allowed(&moved, false));
    assert!(redirect(&original, &moved));
    assert!(redirect(&moved, &final_url));
    for bad in [
        final_url.replace(&pin, &"b".repeat(40)),
        original.clone(),
        "https://registry.npmjs.org/demo/-/demo-1.0.0.tgz".into(),
        "https://evil.test/secret".into(),
    ] {
        assert!(!redirect(&original, &bad), "{bad}");
    }
    assert!(allowed(&npm(b"archive").url, false));
    assert!(!redirect(&npm(b"archive").url, &final_url));
}
#[test]
fn github_authentication_is_required_only_for_misses_and_never_exposed() {
    let f = Fixture::new();
    let a = composer(b"archive");
    let mut fetch = Download::new(b"archive");
    fill(&f.cache(), std::slice::from_ref(&a), 10000, &mut fetch).unwrap();
    let context = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(1),
    );
    let mut network = Network {
        token: None,
        context: &context,
    };
    assert!(
        fill(&f.cache(), std::slice::from_ref(&a), 10000, &mut network)
            .unwrap()
            .failures
            .is_empty()
    );
    let mut miss = a.clone();
    miss.url = miss.url.replace(&"a".repeat(40), &"b".repeat(40));
    miss.key = format!(
        "composer/acme/demo/{}.zip",
        hex(&sha1::Sha1::digest(&miss.url))
    );
    assert_eq!(
        fill(&f.cache(), &[miss.clone()], 10000, &mut network)
            .unwrap()
            .failures[&miss.key],
        "GitHub installation token unavailable"
    );
    assert_eq!(HostCache::privilege(Platform::Macos), None);
}

#[test]
fn archive_requests_authenticate_github_but_never_the_public_registry() {
    let client = reqwest::Client::new();
    for url in [
        composer(b"archive").url,
        format!(
            "https://api.github.com/repositories/77/zipball/{}",
            "a".repeat(40)
        ),
        format!(
            "https://codeload.github.com/acme/demo/legacy.zip/{}",
            "a".repeat(40)
        ),
    ] {
        let request = archive_request(&client, &url, Some("ghs_sentinel")).unwrap();
        assert_eq!(request.headers()["authorization"], "Bearer ghs_sentinel");
        assert!(archive_request(&client, &url, None).is_err());
    }
    let request = archive_request(&client, &npm(b"archive").url, Some("ghs_sentinel")).unwrap();
    assert!(!request.headers().contains_key("authorization"));
    assert!(!format!("{request:?}").contains("ghs_sentinel"));
}
