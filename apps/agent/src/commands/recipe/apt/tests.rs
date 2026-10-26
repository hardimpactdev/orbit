use super::*;
use crate::command::{Context, Platform};
use crate::commands::recipe::{diagnostic, Native};
use std::{
    collections::VecDeque,
    io::{Read, Write},
    net::TcpListener,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    time::Instant,
};

#[derive(Default)]
struct Fake {
    replies: VecDeque<Output>,
    calls: Vec<Invocation>,
    waits: Vec<Duration>,
}
impl Host for Fake {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        self.calls.push(invocation.clone());
        Ok(self.replies.pop_front().expect("unexpected tool call"))
    }
    fn wait(&mut self, duration: Duration) -> Result<(), Failure> {
        self.waits.push(duration);
        Ok(())
    }
}
fn stdout(text: &str) -> Output {
    Output {
        stdout: text.into(),
        ..Output::default()
    }
}
fn failed(text: &str) -> Output {
    Output {
        code: 100,
        stderr: text.into(),
        ..Output::default()
    }
}
const INDEX: &str = "https://security.ubuntu.com/ubuntu resolute-security/main amd64 Packages";

#[test]
fn origin_checks_the_exact_installed_version_and_every_listing() {
    let global = format!(" 500 {INDEX}\n     release o=Ubuntu,a=resolute-security,n=resolute\n");
    let package = format!("curl:\n     9.0 500\n        500 {INDEX}\n *** 8.0 100\n        100 /var/lib/dpkg/status\n");
    assert_eq!(
        parse_origin(&global, &package, "8.0", &BTreeSet::new()),
        Origin {
            trusted: false,
            untrusted: false,
            installed: true
        }
    );
    let package =
        format!("curl:\n *** 8.0 500\n        500 {INDEX}\n        100 /var/lib/dpkg/status\n");
    assert_eq!(
        parse_origin(&global, &package, "8.0", &BTreeSet::new()),
        Origin {
            trusted: true,
            untrusted: false,
            installed: true
        }
    );
    let package =
        format!("{package}        500 https://ppa.example/ubuntu resolute/main amd64 Packages\n");
    assert_eq!(
        parse_origin(&global, &package, "8.0", &BTreeSet::new()),
        Origin {
            trusted: true,
            untrusted: true,
            installed: true
        }
    );
    for invalid in [
        global.replace("o=Ubuntu", "o=Other"),
        global.replace("a=resolute-security", "a=resolute-backports"),
        global.replace("n=resolute", "n=noble"),
    ] {
        assert!(!parse_origin(&invalid, &package, "8.0", &BTreeSet::new()).trusted);
    }
    assert!(!ubuntu_index(
        "https://archive.ubuntu.com.evil/ubuntu resolute/main amd64 Packages",
        &BTreeSet::new()
    ));
    assert!(!ubuntu_index(
        "file:///ubuntu resolute/main amd64 Packages",
        &BTreeSet::new()
    ));
    let mirror = "https://mirror.test/ubuntu resolute/main amd64 Packages";
    let signed = [("https://mirror.test/ubuntu".into(), "resolute/main".into())].into();
    assert!(ubuntu_index(mirror, &signed));
    assert!(!ubuntu_index(
        &mirror.replace("/main", "/universe"),
        &signed
    ));
}

#[test]
fn a_trusted_snapshot_cannot_hide_a_foreign_host_listing() {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    let global = format!(" 500 {INDEX}\n     release o=Ubuntu,a=resolute-security,n=resolute\n");
    let trusted = format!("curl:\n *** 8.0 500\n        500 {INDEX}\n");
    let foreign =
        "curl:\n *** 8.0 500\n        500 https://ppa.test/ubuntu resolute/main amd64 Packages\n";
    let mut host = Fake {
        replies: [
            stdout(&global),
            stdout("amd64\n"),
            stdout(foreign),
            stdout(&global),
            stdout("amd64\n"),
            stdout(&trusted),
        ]
        .into(),
        ..Fake::default()
    };
    assert!(!ubuntu_origin(
        &mut host,
        &files,
        "curl",
        "8.0",
        Some(&[]),
        &mut Snapshots::default()
    )
    .unwrap());
    assert_eq!(host.calls.len(), 6);
    assert!(!host.calls.iter().any(|v| v.argv[0] == "apt-get"));
}

#[test]
fn retries_only_explicit_transport_failures_and_keeps_the_attempt_bound() {
    for (reason, attempts) in [
        ("503 Service Unavailable", 4),
        ("501 Not Implemented", 4),
        ("505 Server Error", 4),
        ("Connection reset by peer", 4),
        ("Connection failed", 4),
        ("404 Not Found", 1),
        ("408 Request Timeout", 1),
        ("429 Too Many Requests", 1),
        ("503 Service Unavailable\nE: Hash Sum mismatch", 1),
        (
            "503 Service Unavailable\nE: Signature verification failed",
            1,
        ),
        ("503 Service Unavailable\nE: NO_PUBKEY 1234", 1),
        ("503 Service Unavailable\nE: Unexpected size", 1),
        (
            "503 Service Unavailable\nE: Failed to fetch https://other.test/pkg 403 Forbidden",
            1,
        ),
    ] {
        let message = format!("E: Failed to fetch https://repo.test/pkg {reason}\n");
        let mut host = Fake {
            replies: vec![failed(&message); attempts].into(),
            ..Fake::default()
        };
        let error = fetch(&mut host, &Invocation::new(&["apt-get", "update"])).unwrap_err();
        assert_eq!(host.calls.len(), attempts, "{reason}");
        assert_eq!(
            host.waits,
            [10, 20, 30][..attempts - 1]
                .iter()
                .map(|s| Duration::from_secs(*s))
                .collect::<Vec<_>>()
        );
        assert_eq!(error.diagnostic.unwrap().exit_code, 100);
    }
    let mut host = Fake {
        replies: [
            failed("E: Failed to fetch https://repo.test/pkg 503 Error\n"),
            stdout("updated"),
        ]
        .into(),
        ..Fake::default()
    };
    assert_eq!(
        fetch(&mut host, &Invocation::new(&["apt-get", "update"]))
            .unwrap()
            .stdout,
        "updated"
    );
    assert_eq!(host.calls.len(), 2);
}

#[test]
fn a_failed_release_fetch_explains_only_its_own_unsigned_error() {
    let error = "E: Failed to fetch https://repo.test/ubuntu/dists/resolute/InRelease 503 Service Unavailable\nE: The repository 'https://repo.test/ubuntu resolute InRelease' is not signed.\n";
    assert!(retryable("", error));
    assert!(!retryable("", &format!("{error}E: The repository 'https://other.test/ubuntu resolute InRelease' is not signed.\n")));
    assert!(!retryable(
        "",
        "E: The repository 'https://repo.test/ubuntu resolute InRelease' is not signed.\n"
    ));
    assert!(!retryable(
        "",
        &error.replace("503 Service Unavailable", "404 Not Found")
    ));
}

#[test]
fn historical_snapshots_cache_success_failure_and_the_three_timestamp_limit() {
    let mut host = Fake {
        replies: [stdout("ok"), failed("no signature"), stdout("ok")].into(),
        ..Fake::default()
    };
    let mut snapshots = Snapshots::default();
    let first = snapshots
        .options(&mut host, "20261001T000000Z")
        .unwrap()
        .to_vec();
    assert_eq!(
        snapshots.options(&mut host, "20261001T000000Z").unwrap(),
        first
    );
    for _ in 0..2 {
        assert_eq!(
            snapshots
                .options(&mut host, "20261002T000000Z")
                .unwrap_err()
                .stage,
            "snapshot-unavailable"
        );
    }
    snapshots.options(&mut host, "20261003T000000Z").unwrap();
    assert_eq!(
        snapshots
            .options(&mut host, "20261004T000000Z")
            .unwrap_err()
            .stage,
        "snapshot-lookup-limit"
    );
    assert_eq!(host.calls.len(), 3);
    let config = PathBuf::from(&first[1]);
    assert!(config.exists());
    drop(snapshots);
    assert!(!config.exists());
}

#[test]
fn dpkg_logs_prefer_install_actions_over_rewritten_status_and_include_rotated_gzip() {
    let work = WorkDir::new().unwrap();
    let log = work.0.join("dpkg.log");
    fs::write(&log, "2026-10-22 12:00:00 status installed curl:amd64 8.0\n2026-10-01 12:00:00 status installed curl:amd64 8.0\n2026-09-01 12:00:00 install curl:arm64 <none> 8.0\n").unwrap();
    let mut gzip = flate2::write::GzEncoder::new(
        fs::File::create(work.0.join("dpkg.log.1.gz")).unwrap(),
        flate2::Compression::default(),
    );
    gzip.write_all(b"2026-10-10 12:00:00 upgrade curl:amd64 7.0 8.0\n2026-10-12 12:00:00 upgrade curl:amd64 7.0 8.0\n").unwrap();
    gzip.finish().unwrap();
    assert_eq!(
        install_snapshot(&log, "curl", "8.0", "amd64"),
        log_timestamp("2026-10-10", "12:00:00")
    );
    fs::remove_file(work.0.join("dpkg.log.1.gz")).unwrap();
    assert_eq!(
        install_snapshot(&log, "curl", "8.0", "amd64"),
        log_timestamp("2026-10-01", "12:00:00")
    );
    assert_eq!(install_snapshot(&log, "curl", "9.0", "amd64"), None);
    fs::write(work.0.join("dpkg.log.1.gz"), "broken gzip").unwrap();
    assert_eq!(install_snapshot(&log, "curl", "8.0", "amd64"), None);
}

#[test]
fn exact_artifacts_can_downgrade_but_ubuntu_and_unlisted_dependencies_cannot() {
    let debs = [PathBuf::from("/work/caddy.deb")];
    let retained = [("curl".into(), "8.0".into())].into();
    let mut host = Fake {
        replies: [
            stdout("Inst caddy [3.0] (2.0 local-deb [amd64])\n"),
            stdout(""),
        ]
        .into(),
        ..Fake::default()
    };
    install_exact_debs(&mut host, &debs, &retained, &retained, &[], &[]).unwrap();
    assert_eq!(host.calls.len(), 2);
    assert!(host.calls[0].argv.contains(&"--simulate".into()));
    assert!(!host.calls[1].argv.contains(&"--simulate".into()));
    assert!(host
        .calls
        .iter()
        .all(|v| v.argv.contains(&"curl=8.0".into())
            && v.argv.contains(&"--allow-downgrades".into())));
    for (package, comparison) in [("curl", 0), ("unlisted", 0), ("unlisted", 2)] {
        let mut host = Fake {
            replies: [
                stdout(&format!("Inst {package} [8.0] (7.0 Ubuntu [amd64])\n")),
                Output {
                    code: comparison,
                    ..Output::default()
                },
            ]
            .into(),
            ..Fake::default()
        };
        let error =
            install_exact_debs(&mut host, &debs, &retained, &retained, &[], &[]).unwrap_err();
        assert_eq!(error.packages, [package]);
        assert_eq!(host.calls.len(), 2);
        assert!(host.calls[0].argv.contains(&"--simulate".into()));
        assert_eq!(host.calls[1].argv[0], "dpkg");
    }
    let mut host = Fake {
        replies: [stdout("Inst caddy without version\n")].into(),
        ..Fake::default()
    };
    assert!(install_exact_debs(&mut host, &debs, &retained, &retained, &[], &[]).is_err());
}

#[test]
fn dpkg_audit_output_is_failure_even_with_a_success_exit_code() {
    for (out, err) in [
        ("half configured package\n", ""),
        ("", "dpkg problem\n"),
        ("", ""),
    ] {
        let mut host = Fake {
            replies: [Output {
                stdout: out.into(),
                stderr: err.into(),
                ..Output::default()
            }]
            .into(),
            ..Fake::default()
        };
        assert_eq!(
            audit(&mut host, &[]).is_ok(),
            out.is_empty() && err.is_empty()
        );
    }
}

#[test]
fn diagnostics_redact_before_bounding_and_keep_utf8_and_command_identity() {
    let text = format!("{}\nhttps://user:password@example.test/pkg\nAuthorization: Bearer private-token\nSECRET=private-secret\n", "☃\n\t\u{1b}".repeat(15000));
    let clean = diagnostic(&text);
    assert!(clean.len() <= 8000);
    for secret in ["user:password", "private-token", "private-secret", "\u{1b}"] {
        assert!(!clean.contains(secret));
    }
    assert!(clean.contains("[REDACTED]"));
    let mut host = Fake {
        replies: [failed("API_TOKEN=private-token")].into(),
        ..Fake::default()
    };
    let error = package_command(
        &mut host,
        &Invocation::new(&["apt-get", "install", "--", "curl=8.0"]),
        &["curl=8.0".into()],
    )
    .unwrap_err();
    assert_eq!(error.packages, ["curl=8.0"]);
    let output = error.diagnostic.unwrap();
    assert_eq!(output.command, ["apt-get", "install", "--", "curl=8.0"]);
    assert!(!output.stderr.contains("private-token"));
}

struct Server {
    url: String,
    stopped: Arc<AtomicBool>,
    thread: Option<std::thread::JoinHandle<()>>,
}
impl Server {
    fn new(mut reply: impl FnMut(&str) -> (u16, Vec<u8>) + Send + 'static) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        listener.set_nonblocking(true).unwrap();
        let stopped = Arc::new(AtomicBool::new(false));
        let stop = stopped.clone();
        let thread = std::thread::spawn(move || {
            while !stop.load(Ordering::Relaxed) {
                let (mut stream, _) = match listener.accept() {
                    Ok(v) => v,
                    Err(e) if e.kind() == io::ErrorKind::WouldBlock => {
                        std::thread::sleep(Duration::from_millis(5));
                        continue;
                    }
                    Err(e) => panic!("{e}"),
                };
                stream
                    .set_read_timeout(Some(Duration::from_secs(5)))
                    .unwrap();
                let mut request = Vec::new();
                let mut byte = [0];
                while request.len() < 16384 && stream.read(&mut byte).unwrap_or_default() == 1 {
                    request.push(byte[0]);
                    if request.ends_with(b"\r\n\r\n") {
                        break;
                    }
                }
                let request = String::from_utf8_lossy(&request);
                let path = request.split_whitespace().nth(1).unwrap_or_default();
                let (code, body) = reply(path);
                let _ = write!(
                    stream,
                    "HTTP/1.1 {code} Test\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                    body.len()
                );
                let _ = stream.write_all(&body);
            }
        });
        Self {
            url,
            stopped,
            thread: Some(thread),
        }
    }
}
impl Drop for Server {
    fn drop(&mut self) {
        self.stopped.store(true, Ordering::Relaxed);
        self.thread.take().unwrap().join().unwrap();
    }
}
struct NoWait<'a> {
    native: Native<'a>,
    calls: usize,
    waits: Vec<Duration>,
}

#[test]
#[cfg(target_os = "linux")]
fn real_signed_index_refresh_recovers_from_transport_failure_and_refuses_untrusted_metadata() {
    let fixture = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/recipe");
    for (mode, attempts) in [
        ("recover", 2),
        ("unavailable", 4),
        ("unsigned", 1),
        ("signature", 1),
        ("mixed", 1),
    ] {
        let release = fs::read(fixture.join("apt-snapshot/InRelease")).unwrap();
        let packages = fs::read(fixture.join("Packages.gz")).unwrap();
        let mut requests = 0;
        let server = Server::new(move |path| {
            requests += 1;
            if path.ends_with("/InRelease") {
                if mode == "unsigned" {
                    return (200, b"unsigned release".to_vec());
                }
                if mode == "signature" || path.starts_with("/bad/") {
                    return (
                        200,
                        String::from_utf8(release.clone())
                            .unwrap()
                            .replace("Origin: Ubuntu", "Origin: Tampered")
                            .into_bytes(),
                    );
                }
                if mode == "unavailable" || mode == "mixed" || (mode == "recover" && requests == 1)
                {
                    return (503, vec![]);
                }
                (200, release.clone())
            } else if path.ends_with("/Packages.gz") {
                (200, packages.clone())
            } else {
                (404, vec![])
            }
        });
        let mut source = format!("Types: deb\nURIs: {}/good/\nSuites: resolute\nComponents: main\nSigned-By: {}\nCheck-Valid-Until: no\n", server.url, fixture.join("apt-snapshot/keyring.gpg").display());
        if mode == "mixed" {
            source = format!("{source}\n{}", source.replace("/good/", "/bad/"));
        }
        let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30));
        let mut host = NoWait {
            native: Native(&context),
            calls: 0,
            waits: vec![],
        };
        let result = Indexes::temporary(&mut host, &source);
        match result {
            Ok(indexes) => {
                assert_eq!(mode, "recover");
                assert!(fs::read_dir(indexes.work.0.join("lists"))
                    .unwrap()
                    .any(|entry| entry
                        .unwrap()
                        .file_name()
                        .to_string_lossy()
                        .contains("Packages")));
            }
            Err(error) => {
                assert_ne!(mode, "recover", "{error:?}");
                assert_eq!(error.stage, "apt-source");
                if mode == "unavailable" {
                    assert!(error.diagnostic.unwrap().stderr.contains("503"));
                }
            }
        }
        assert_eq!(host.calls, attempts, "{mode}");
        assert_eq!(
            host.waits,
            [10, 20, 30][..attempts - 1]
                .iter()
                .map(|seconds| Duration::from_secs(*seconds))
                .collect::<Vec<_>>(),
            "{mode}"
        );
    }
}
impl Host for NoWait<'_> {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        self.calls += 1;
        self.native.execute(invocation)
    }
    fn wait(&mut self, delay: Duration) -> Result<(), Failure> {
        self.waits.push(delay);
        Ok(())
    }
}

#[test]
#[cfg(target_os = "linux")]
fn real_apt_fetch_recovers_from_5xx_and_refuses_4xx_and_corruption() {
    use sha2::{Digest, Sha256};
    let digest = format!("{:x}", Sha256::digest(b"index"));
    for (statuses, body, attempts, success) in [
        (vec![503, 200], b"index".to_vec(), 2, true),
        (vec![501, 200], b"index".to_vec(), 2, true),
        (vec![505], b"index".to_vec(), 4, false),
        (vec![503], vec![], 4, false),
        (vec![404], vec![], 1, false),
        (vec![408], vec![], 1, false),
        (vec![429], vec![], 1, false),
        (vec![200], b"tampered".to_vec(), 1, false),
    ] {
        let mut request = 0;
        let server = Server::new(move |_| {
            let status = statuses[request.min(statuses.len() - 1)];
            request += 1;
            (status, body.clone())
        });
        let work = WorkDir::new().unwrap();
        let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30));
        let mut host = NoWait {
            native: Native(&context),
            calls: 0,
            waits: vec![],
        };
        let mut invocation = Invocation::new(&["/usr/lib/apt/apt-helper"]);
        invocation.argv.extend(fetch_options());
        invocation.argv.extend([
            "download-file".into(),
            format!("{}/Packages.gz", server.url),
            work.0.join("Packages.gz").display().to_string(),
            format!("SHA256:{digest}"),
        ]);
        invocation.timeout = Duration::from_secs(10);
        let result = fetch(&mut host, &invocation);
        assert_eq!(result.is_ok(), success, "{result:?}");
        assert_eq!(host.calls, attempts);
        assert_eq!(host.waits.len(), attempts - 1);
    }
}
