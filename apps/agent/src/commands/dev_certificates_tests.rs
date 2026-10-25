use super::*;
use crate::commands::recipe;

#[test]
fn every_directory_to_the_caddy_leaf_grants_group_access_on_creation_and_repair() {
    use std::os::unix::fs::MetadataExt;
    let root = std::env::temp_dir().join(format!(
        "orbit-leaf-permissions-{:016x}",
        rand::random::<u64>()
    ));
    let scope = root.join("app-instance-3");
    let versions = scope.join("versions");
    let candidate = versions.join("leaf.candidate");
    let directories = [
        root.as_path(),
        scope.as_path(),
        versions.as_path(),
        candidate.as_path(),
    ];
    let owner = rustix::process::getuid().as_raw();
    let group = rustix::process::getgid().as_raw();
    for repair in [false, true] {
        if repair {
            for path in directories {
                fs::set_permissions(path, fs::Permissions::from_mode(0o700)).unwrap();
            }
        }
        prepare_caddy_directories(&root, "app-instance-3", "leaf", owner, group).unwrap();
        for path in directories {
            let metadata = fs::metadata(path).unwrap();
            assert_eq!(
                (metadata.uid(), metadata.gid(), metadata.mode() & 0o777),
                (owner, group, 0o750),
                "{}",
                path.display()
            );
        }
    }
    fs::remove_dir_all(root).unwrap();
}

#[test]
fn validity_reads_openssl_dates_with_one_and_two_digit_days() {
    let dates = "notBefore=Oct  3 12:00:00 2026 GMT\nnotAfter=Nov  4 12:00:00 2027 GMT\n";
    assert_eq!(validity(dates), Some(397 * 86_400));
    let dates = "notBefore=Oct 23 12:00:00 2026 GMT\nnotAfter=Nov 24 12:00:00 2027 GMT\n";
    assert_eq!(validity(dates), Some(397 * 86_400));
    assert_eq!(validity("notBefore=soon\n"), None);
}

fn run(directory: &Path, arguments: &[&str]) {
    let status = Process::new("openssl")
        .args(arguments)
        .current_dir(directory)
        .stderr(Stdio::null())
        .status()
        .unwrap();
    assert!(status.success(), "openssl {arguments:?}");
}

/// A root, and a current leaf signed by it for `domain`, in the managed and Caddy trees under `root`.
fn leaf(
    root: &Path,
    domain: &str,
    key_usage: &str,
    algorithm: &[&str],
) -> (PathBuf, PathBuf, String) {
    let current = root.join("current");
    let caddy = root.join("caddy");
    fs::create_dir_all(&current).unwrap();
    fs::create_dir_all(&caddy).unwrap();
    run(
        root,
        &[
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            "ca.key",
            "-out",
            "ca.pem",
            "-days",
            "30",
            "-subj",
            "/CN=Orbit test root",
        ],
    );
    let mut key = vec!["genpkey"];
    key.extend_from_slice(algorithm);
    key.extend_from_slice(&["-out", "current/key.pem"]);
    run(root, &key);
    run(
        root,
        &[
            "req",
            "-new",
            "-key",
            "current/key.pem",
            "-out",
            "request.pem",
            "-subj",
            &format!("/CN={domain}"),
        ],
    );
    fs::write(
        root.join("leaf.ext"),
        format!("basicConstraints=critical,CA:FALSE\nkeyUsage=critical,{key_usage}\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:{domain}\n"),
    )
    .unwrap();
    run(
        root,
        &[
            "x509",
            "-req",
            "-in",
            "request.pem",
            "-CA",
            "ca.pem",
            "-CAkey",
            "ca.key",
            "-set_serial",
            "1",
            "-days",
            "397",
            "-extfile",
            "leaf.ext",
            "-out",
            "current/cert.pem",
        ],
    );
    fs::copy(root.join("ca.pem"), current.join("root.pem")).unwrap();
    fs::copy(current.join("key.pem"), caddy.join("key.pem")).unwrap();
    fs::copy(current.join("cert.pem"), caddy.join("cert.pem")).unwrap();
    let hash = sha256(&current.join("root.pem")).unwrap();
    (current, caddy, hash)
}

#[test]
fn only_a_current_rsa_leaf_with_the_exact_policy_is_reused() {
    let cases: [(&str, &[&str], bool); 3] = [
        (
            "digitalSignature,keyEncipherment",
            &["-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"],
            true,
        ),
        (
            "digitalSignature",
            &["-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"],
            false,
        ),
        (
            "digitalSignature,keyEncipherment",
            &["-algorithm", "EC", "-pkeyopt", "ec_paramgen_curve:P-256"],
            false,
        ),
    ];
    for (usage, algorithm, reused) in cases {
        let root = std::env::temp_dir().join(format!("orbit-leaf-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        let (current, caddy, hash) = leaf(&root, "acme.app-dev.orbit", usage, algorithm);
        assert_eq!(
            current_is_valid(&current, &caddy, "acme.app-dev.orbit", &hash),
            reused,
            "{usage} {algorithm:?}"
        );
        if reused {
            assert!(!current_is_valid(
                &current,
                &caddy,
                "other.app-dev.orbit",
                &hash
            ));
            assert!(!current_is_valid(
                &current,
                &caddy,
                "acme.app-dev.orbit",
                &"0".repeat(64)
            ));
            fs::write(caddy.join("cert.pem"), "stale").unwrap();
            assert!(!current_is_valid(
                &current,
                &caddy,
                "acme.app-dev.orbit",
                &hash
            ));
        }
        let _ = fs::remove_dir_all(&root);
    }
}

#[test]
fn scope_and_version_are_single_safe_names() {
    assert!(valid_name("app-instance-7-hostname-change"));
    assert!(!valid_name("../etc"));
    assert!(!valid_name(".current"));
    assert!(!valid_name(""));
}

/// A host whose account programs run as the test user, and whose trust store and Caddy live in the sandbox.
struct Fake {
    root: PathBuf,
    caddy_group: Option<u32>,
    /// The program or action that fails: an `as_user` program, `update-trust`, or `reload`.
    fail: Option<&'static str>,
    calls: Vec<String>,
}

impl Host for Fake {
    fn account(&self, _: &str) -> Option<(u32, u32)> {
        Some(me())
    }

    fn caddy_group(&self) -> Option<u32> {
        self.caddy_group
    }

    fn as_user(&mut self, user: &str, program: &str, arguments: &[&str]) -> Option<Vec<u8>> {
        assert_eq!(user, "orbit");
        self.calls
            .push(format!("{program} {}", arguments.join(" ")));
        if self.fail == Some(program) {
            return None;
        }
        let output = Process::new(program)
            .args(arguments)
            .current_dir("/")
            .stdin(Stdio::null())
            .stderr(Stdio::null())
            .output()
            .ok()?;
        output.status.success().then_some(output.stdout)
    }

    /// Writes the bundle from the anchor, as `update-ca-certificates` does.
    fn update_trust(&mut self) -> bool {
        self.calls.push("update-ca-certificates".into());
        if self.fail == Some("update-trust") {
            return false;
        }
        let anchor = self.root.join(TRUST_ANCHOR.trim_start_matches('/'));
        fs::copy(anchor, self.root.join(TRUST_BUNDLE.trim_start_matches('/'))).is_ok()
    }

    fn reload_caddy(&mut self) -> io::Result<()> {
        self.calls.push("reload caddy".into());
        if self.fail == Some("reload") {
            return Err(io::Error::other("caddy did not reload"));
        }
        Ok(())
    }
}

fn me() -> (u32, u32) {
    (
        rustix::process::getuid().as_raw(),
        rustix::process::getgid().as_raw(),
    )
}

const DOMAIN: &str = "acme.app-dev.orbit";
const SCOPE: &str = "app-instance-3";

/// The Node's files under a sandbox root, and a Gateway signer with its own root.
struct Sandbox {
    _work: recipe::WorkDir,
    files: Files,
    host: Fake,
    signer: PathBuf,
    root_certificate: String,
}

impl Sandbox {
    fn new() -> Self {
        let work = recipe::WorkDir::new().unwrap();
        let root = fs::canonicalize(&work.0).unwrap();
        for directory in [
            "etc/caddy",
            "etc/ssl/certs",
            "usr/local/share/ca-certificates",
            "home/orbit",
            "signer",
        ] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        let signer = root.join("signer");
        run(
            &signer,
            &[
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                "ca.key",
                "-out",
                "ca.pem",
                "-days",
                "30",
                "-subj",
                "/CN=Orbit test root",
            ],
        );
        let root_certificate = fs::read_to_string(signer.join("ca.pem")).unwrap();
        let (uid, gid) = me();
        Self {
            files: Files {
                root: root.clone(),
                uid,
                gid,
            },
            host: Fake {
                root,
                caddy_group: Some(gid),
                fail: None,
                calls: vec![],
            },
            signer,
            root_certificate,
            _work: work,
        }
    }

    fn path(&self, path: &str) -> PathBuf {
        self.files.path(path)
    }

    fn leaf_input(&self, version: &str) -> LeafInput {
        LeafInput {
            scope: SCOPE.into(),
            domain: DOMAIN.into(),
            version: version.into(),
            root_hash: format!("{:x}", Sha256::digest(&self.root_certificate)),
            user: "orbit".into(),
            home: "/home/orbit".into(),
        }
    }

    fn request(&mut self, version: &str) -> Result<Requested, LeafError> {
        let input = self.leaf_input(version);
        request(&self.files, &mut self.host, &input)
    }

    /// Signs a request as the Gateway does, for `domain`.
    fn sign(&self, request: &str, domain: &str) -> String {
        fs::write(self.signer.join("request.pem"), request).unwrap();
        fs::write(
            self.signer.join("leaf.ext"),
            format!("basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:{domain}\n"),
        )
        .unwrap();
        run(
            &self.signer,
            &[
                "x509",
                "-req",
                "-in",
                "request.pem",
                "-CA",
                "ca.pem",
                "-CAkey",
                "ca.key",
                "-set_serial",
                "7",
                "-days",
                "397",
                "-extfile",
                "leaf.ext",
                "-out",
                "leaf.pem",
            ],
        );
        fs::read_to_string(self.signer.join("leaf.pem")).unwrap()
    }

    fn publish_input(&self, version: &str, certificate: String) -> PublishInput {
        PublishInput {
            leaf: self.leaf_input(version),
            certificate,
            root_certificate: self.root_certificate.clone(),
        }
    }

    fn publish(&mut self, version: &str, certificate: String) -> Result<Published, LeafError> {
        let input = self.publish_input(version, certificate);
        publish(&self.files, &mut self.host, &input)
    }

    /// Requests a new key for `version`, even beside a valid current leaf, and returns its signing request.
    fn requested(&mut self, version: &str) -> String {
        let mut input = self.leaf_input(version);
        // Another root hash makes the current leaf stale; the request itself does not use the hash.
        input.root_hash = "0".repeat(64);
        match request(&self.files, &mut self.host, &input).unwrap() {
            Requested::Request { request } => request,
            Requested::Current => panic!("a fresh leaf was expected"),
        }
    }

    /// Requests, signs, and publishes one version.
    fn converge(&mut self, version: &str) {
        let request = self.requested(version);
        let certificate = self.sign(&request, DOMAIN);
        assert_eq!(self.publish(version, certificate), Ok(Published {}));
    }

    fn user_tree(&self) -> PathBuf {
        self.path(&format!("/home/orbit/.orbit/certificates/{SCOPE}"))
    }

    fn caddy_tree(&self) -> PathBuf {
        self.path(&format!("{CADDY_ROOT}/{SCOPE}"))
    }

    fn scope(&self) -> ScopeInput {
        ScopeInput {
            scope: SCOPE.into(),
            home: "/home/orbit".into(),
        }
    }
}

fn mode(path: &Path) -> u32 {
    use std::os::unix::fs::MetadataExt;
    fs::metadata(path).unwrap().mode() & 0o777
}

#[test]
fn a_fresh_leaf_is_requested_published_in_both_trees_trusted_and_reloaded() {
    let mut sandbox = Sandbox::new();
    let request = sandbox.requested("one");
    assert!(request.starts_with("-----BEGIN CERTIFICATE REQUEST-----"));
    let candidate = sandbox.user_tree().join("versions/one.candidate");
    assert_eq!(mode(&candidate), 0o700);
    assert_eq!(mode(&candidate.join("key.pem")), 0o600);
    let programs: Vec<String> = sandbox
        .host
        .calls
        .iter()
        .map(|call| call.split(' ').take(2).collect::<Vec<_>>().join(" "))
        .collect();
    assert_eq!(
        programs,
        [
            "install -d",
            "install -d",
            "openssl genrsa",
            "chmod 0600",
            "openssl req"
        ]
    );

    sandbox.host.calls.clear();
    let certificate = sandbox.sign(&request, DOMAIN);
    assert_eq!(
        sandbox.publish("one", certificate.clone()),
        Ok(Published {})
    );

    let user = sandbox.user_tree();
    assert_eq!(
        fs::read_link(user.join("current")).unwrap(),
        user.join("versions/one")
    );
    assert!(!candidate.exists());
    let published = user.join("versions/one");
    assert_eq!(
        fs::read_to_string(published.join("cert.pem")).unwrap(),
        certificate
    );
    assert_eq!(
        fs::read_to_string(published.join("root.pem")).unwrap(),
        sandbox.root_certificate
    );
    assert!(!published.join("request.pem").exists());
    assert_eq!(mode(&published.join("key.pem")), 0o600);
    assert_eq!(mode(&published.join("cert.pem")), 0o644);

    let caddy = sandbox.caddy_tree();
    assert_eq!(
        fs::read_link(caddy.join("current")).unwrap(),
        caddy.join("versions/one")
    );
    assert_eq!(
        fs::read(caddy.join("versions/one/key.pem")).unwrap(),
        fs::read(published.join("key.pem")).unwrap()
    );
    assert_eq!(mode(&caddy.join("versions/one/key.pem")), 0o640);
    assert_eq!(mode(&caddy.join("versions/one/cert.pem")), 0o644);
    for directory in [
        sandbox.path(CADDY_ROOT),
        caddy.clone(),
        caddy.join("versions"),
    ] {
        assert_eq!(mode(&directory), 0o750, "{}", directory.display());
    }
    let anchor = sandbox.path(TRUST_ANCHOR);
    assert_eq!(
        fs::read_to_string(&anchor).unwrap(),
        sandbox.root_certificate
    );
    assert_eq!(mode(&anchor), 0o644);
    assert_eq!(
        sandbox.host.calls,
        ["update-ca-certificates", "reload caddy"]
    );
    assert_eq!(
        inspect(&sandbox.files, &sandbox.scope()),
        Ok(Presence { present: true })
    );
}

#[test]
fn a_valid_current_leaf_is_reused_without_a_change() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    sandbox.host.calls.clear();
    let current = fs::read_link(sandbox.user_tree().join("current")).unwrap();

    assert_eq!(sandbox.request("two"), Ok(Requested::Current));
    assert!(sandbox.host.calls.is_empty(), "{:?}", sandbox.host.calls);
    assert!(!sandbox.user_tree().join("versions/two.candidate").exists());
    assert_eq!(
        fs::read_link(sandbox.user_tree().join("current")).unwrap(),
        current
    );

    // A changed trust anchor is installed again before the reuse is confirmed.
    fs::write(sandbox.path(TRUST_ANCHOR), "stale").unwrap();
    assert_eq!(sandbox.request("three"), Ok(Requested::Current));
    assert_eq!(sandbox.host.calls, ["update-ca-certificates"]);
    assert_eq!(
        fs::read_to_string(sandbox.path(TRUST_ANCHOR)).unwrap(),
        sandbox.root_certificate
    );
}

#[test]
fn a_leaf_for_another_root_or_with_a_stale_caddy_copy_is_replaced() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    let mut input = sandbox.leaf_input("two");
    input.root_hash = "0".repeat(64);
    assert!(matches!(
        request(&sandbox.files, &mut sandbox.host, &input),
        Ok(Requested::Request { .. })
    ));

    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    fs::write(sandbox.caddy_tree().join("versions/one/cert.pem"), "stale").unwrap();
    sandbox.converge("two");
    assert_eq!(
        fs::read_link(sandbox.caddy_tree().join("current")).unwrap(),
        sandbox.caddy_tree().join("versions/two")
    );
    assert_eq!(sandbox.request("three"), Ok(Requested::Current));
}

#[test]
fn a_request_removes_stale_candidates() {
    let mut sandbox = Sandbox::new();
    let stale = sandbox.user_tree().join("versions/old.candidate");
    fs::create_dir_all(&stale).unwrap();
    sandbox.request("one").unwrap();
    assert!(!stale.exists());
    assert!(sandbox.user_tree().join("versions/one.candidate").is_dir());
}

#[test]
fn a_failed_key_step_fails_the_request() {
    for program in ["install", "openssl", "chmod"] {
        let mut sandbox = Sandbox::new();
        sandbox.host.fail = Some(program);
        assert_eq!(
            sandbox.request("one"),
            Err(failed("certificate-request")),
            "{program}"
        );
    }
}

#[test]
fn unsafe_names_refuse_before_any_change() {
    let mut sandbox = Sandbox::new();
    for (scope, version) in [("../etc", "one"), (SCOPE, ".current"), ("", "one")] {
        let mut input = sandbox.leaf_input(version);
        input.scope = scope.into();
        assert_eq!(
            request(&sandbox.files, &mut sandbox.host, &input),
            Err(failed("certificate-input"))
        );
        let publish_input = PublishInput {
            leaf: input,
            certificate: String::new(),
            root_certificate: String::new(),
        };
        assert_eq!(
            publish(&sandbox.files, &mut sandbox.host, &publish_input),
            Err(failed("certificate-input"))
        );
        let scope = ScopeInput {
            scope: scope.into(),
            home: "/home/orbit".into(),
        };
        if !valid_name(&scope.scope) {
            assert_eq!(
                inspect(&sandbox.files, &scope),
                Err(failed("certificate-input"))
            );
            assert_eq!(
                remove(&sandbox.files, &scope),
                Err(failed("certificate-input"))
            );
        }
    }
    assert!(sandbox.host.calls.is_empty());
    assert!(!sandbox.path("/home/orbit/.orbit").exists());
}

/// Publishes input that fails a check and asserts that nothing the Node serves or trusts changed.
fn refused_publish(sandbox: &mut Sandbox, input: PublishInput, step: &str) {
    let user = fs::read_link(sandbox.user_tree().join("current")).unwrap();
    let caddy = fs::read_link(sandbox.caddy_tree().join("current")).unwrap();
    let anchor = fs::read(sandbox.path(TRUST_ANCHOR)).unwrap();
    sandbox.host.calls.clear();
    assert_eq!(
        publish(&sandbox.files, &mut sandbox.host, &input),
        Err(failed(step))
    );
    assert_eq!(
        fs::read_link(sandbox.user_tree().join("current")).unwrap(),
        user
    );
    assert_eq!(
        fs::read_link(sandbox.caddy_tree().join("current")).unwrap(),
        caddy
    );
    assert_eq!(fs::read(sandbox.path(TRUST_ANCHOR)).unwrap(), anchor);
    assert!(!sandbox.host.calls.contains(&"reload caddy".to_string()));
}

#[test]
fn a_certificate_that_fails_a_check_refuses_before_any_switch() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");

    // No candidate for the version.
    let certificate = fs::read_to_string(sandbox.user_tree().join("current/cert.pem")).unwrap();
    let input = sandbox.publish_input("missing", certificate);
    refused_publish(&mut sandbox, input, "certificate-publish");

    // A leaf for another domain.
    let request = sandbox.requested("two");
    let other = sandbox.sign(&request, "other.app-dev.orbit");
    let input = sandbox.publish_input("two", other);
    refused_publish(&mut sandbox, input, "certificate-publish");

    // A root whose hash is not the one the Gateway named.
    let request = sandbox.requested("three");
    let certificate = sandbox.sign(&request, DOMAIN);
    let mut input = sandbox.publish_input("three", certificate);
    input.leaf.root_hash = "0".repeat(64);
    refused_publish(&mut sandbox, input, "certificate-publish");

    // A leaf that the named root did not sign.
    let request = sandbox.requested("four");
    let certificate = sandbox.sign(&request, DOMAIN);
    let stranger = Sandbox::new();
    let mut input = sandbox.publish_input("four", certificate);
    input.root_certificate = stranger.root_certificate.clone();
    input.leaf.root_hash = format!("{:x}", Sha256::digest(&stranger.root_certificate));
    refused_publish(&mut sandbox, input, "certificate-publish");
}

#[test]
fn a_missing_caddy_group_refuses_before_the_managed_tree_switches() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    let request = sandbox.requested("two");
    let certificate = sandbox.sign(&request, DOMAIN);
    sandbox.host.caddy_group = None;
    let input = sandbox.publish_input("two", certificate);
    refused_publish(&mut sandbox, input, "certificate-publish");
    assert!(sandbox.user_tree().join("versions/two.candidate").is_dir());
}

#[test]
fn a_failed_trust_update_refuses_before_any_switch() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    let request = sandbox.requested("two");
    let certificate = sandbox.sign(&request, DOMAIN);
    fs::write(sandbox.path(TRUST_ANCHOR), "stale").unwrap();
    sandbox.host.fail = Some("update-trust");
    sandbox.host.calls.clear();
    let input = sandbox.publish_input("two", certificate);
    let user = fs::read_link(sandbox.user_tree().join("current")).unwrap();
    assert_eq!(
        publish(&sandbox.files, &mut sandbox.host, &input),
        Err(failed("certificate-trust"))
    );
    assert_eq!(
        fs::read_link(sandbox.user_tree().join("current")).unwrap(),
        user
    );
    assert!(!sandbox.host.calls.contains(&"reload caddy".to_string()));
}

#[test]
fn a_failed_reload_fails_the_publish_after_both_trees_switch() {
    let mut sandbox = Sandbox::new();
    let request = sandbox.requested("one");
    let certificate = sandbox.sign(&request, DOMAIN);
    sandbox.host.fail = Some("reload");
    assert_eq!(
        sandbox.publish("one", certificate),
        Err(failed("certificate-publish"))
    );
    assert_eq!(
        fs::read_link(sandbox.caddy_tree().join("current")).unwrap(),
        sandbox.caddy_tree().join("versions/one")
    );
}

#[test]
fn inspection_needs_the_leaf_in_both_trees() {
    let mut sandbox = Sandbox::new();
    let scope = sandbox.scope();
    assert_eq!(
        inspect(&sandbox.files, &scope),
        Ok(Presence { present: false })
    );
    sandbox.converge("one");
    assert_eq!(
        inspect(&sandbox.files, &scope),
        Ok(Presence { present: true })
    );
    fs::remove_file(sandbox.caddy_tree().join("current")).unwrap();
    assert_eq!(
        inspect(&sandbox.files, &scope),
        Ok(Presence { present: false })
    );
}

#[test]
fn removal_deletes_both_trees_and_repeats() {
    let mut sandbox = Sandbox::new();
    sandbox.converge("one");
    let scope = sandbox.scope();
    assert_eq!(remove(&sandbox.files, &scope), Ok(Published {}));
    assert!(!sandbox.user_tree().exists());
    assert!(!sandbox.caddy_tree().exists());
    assert!(sandbox.path(CADDY_ROOT).is_dir(), "other scopes stay");
    assert_eq!(remove(&sandbox.files, &scope), Ok(Published {}));
    assert_eq!(
        inspect(&sandbox.files, &scope),
        Ok(Presence { present: false })
    );
}

#[test]
fn removal_fails_when_a_tree_cannot_be_deleted() {
    let sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path(CADDY_ROOT)).unwrap();
    fs::write(sandbox.caddy_tree(), "not a directory").unwrap();
    assert_eq!(
        remove(&sandbox.files, &sandbox.scope()),
        Err(failed("certificate-remove"))
    );
}
