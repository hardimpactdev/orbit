use super::*;
use std::{net::TcpListener, os::unix::fs::symlink};

struct Fixture {
    root: PathBuf,
    live: PathBuf,
    input: Input,
}
impl Fixture {
    fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-public-edge-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&root).unwrap();
        let site = "# orbit: ingress route-1-ingress\napp.test {\n    bind 0.0.0.0 10.44.0.2\n    tls force_automate\n    reverse_proxy https://10.44.0.3\n}\n";
        fs::write(
            root.join("version"),
            format!("{{\n    auto_https disable_certs\n}}\n{site}"),
        )
        .unwrap();
        let live = root.join("Caddyfile");
        symlink("version", &live).unwrap();
        let input = Input {
            domain: "app.test".into(),
            expected_site: site.into(),
            certificates: root.join("certificates/current"),
            forwarding: vec![],
        };
        Self { root, live, input }
    }
    fn edit(&self, from: &str, to: &str) {
        fs::write(
            &self.live,
            fs::read_to_string(&self.live).unwrap().replace(from, to),
        )
        .unwrap();
    }
    fn inspect(&self, runtime: &mut impl Runtime) -> Observed {
        inspect(&self.input, &self.live, runtime).unwrap()
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
struct Host {
    trusted: bool,
    reachable: bool,
    calls: usize,
}
impl Host {
    fn healthy() -> Self {
        Self {
            trusted: true,
            reachable: true,
            calls: 0,
        }
    }
}
impl Runtime for Host {
    fn certificate(&mut self, _: &str) -> bool {
        self.calls += 1;
        self.trusted
    }
    fn reachable(&mut self, _: SocketAddr) -> bool {
        self.calls += 1;
        self.reachable
    }
}

#[test]
fn reads_one_live_version_and_separates_configuration_forwarding_and_tls_drift() {
    let mut fixture = Fixture::new();
    let mut host = Host::healthy();
    fixture.input.forwarding = vec!["10.44.0.3:443".parse().unwrap()];
    let before = fs::read(&fixture.live).unwrap();
    for _ in 0..2 {
        assert_eq!(
            fixture.inspect(&mut host),
            Observed {
                ingress: true,
                forwarding: true,
                tls: true
            }
        );
    }
    assert_eq!(fs::read(&fixture.live).unwrap(), before);
    host.reachable = false;
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: true,
            forwarding: false,
            tls: true
        }
    );
    host.reachable = true;
    host.trusted = false;
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: true,
            forwarding: true,
            tls: false
        }
    );
    host.trusted = true;
    fixture.edit("https://10.44.0.3", "https://10.44.0.99");
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: false,
            forwarding: true,
            tls: true
        }
    );
    fs::write(&fixture.live, "import version\n").unwrap();
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: false,
            forwarding: true,
            tls: false
        }
    );
}

#[test]
fn tls_only_changes_do_not_hide_an_otherwise_correct_composed_site() {
    let fixture = Fixture::new();
    let mut host = Host::healthy();
    fixture.edit("    tls force_automate\n", "");
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: true,
            forwarding: true,
            tls: false
        }
    );
    fixture.edit("    auto_https disable_certs\n", "");
    assert!(fixture.inspect(&mut host).tls);
    fixture.edit(
        "app.test {",
        &format!(
            "app.test {{\n    tls {0}/cert.pem {0}/key.pem",
            fixture.input.certificates.display()
        ),
    );
    assert!(!fixture.inspect(&mut host).tls);
    assert!(fixture.inspect(&mut host).ingress);
}

#[test]
fn checks_local_workload_blocks_and_all_listener_addresses() {
    let mut fixture = Fixture::new();
    let mut host = Host::healthy();
    fixture.input.expected_site = fixture.input.expected_site.replace(
        "reverse_proxy https://10.44.0.3",
        "php_fastcgi unix//run/php/orbit-app-1.sock",
    );
    fixture.edit(
        "reverse_proxy https://10.44.0.3",
        "php_fastcgi unix//run/php/orbit-app-1.sock",
    );
    assert!(fixture.inspect(&mut host).ingress);
    fixture.edit("0.0.0.0 10.44.0.2", "0.0.0.0");
    assert!(!fixture.inspect(&mut host).ingress);
    fixture.edit("0.0.0.0", "0.0.0.0 10.44.0.2");
    fixture.edit("orbit-app-1.sock", "other.sock");
    assert!(!fixture.inspect(&mut host).ingress);
}

#[test]
fn refuses_invalid_input_before_observing_and_distinguishes_absent_from_unreadable() {
    let mut fixture = Fixture::new();
    let mut host = Host::healthy();
    fixture.input.domain = "app.test\nother".into();
    assert_eq!(
        inspect(&fixture.input, &fixture.live, &mut host),
        Err(Error::Invalid {})
    );
    assert_eq!(host.calls, 0);
    fixture.input.domain = "app.test".into();
    fs::remove_file(&fixture.live).unwrap();
    assert_eq!(
        fixture.inspect(&mut host),
        Observed {
            ingress: false,
            forwarding: true,
            tls: false
        }
    );
    symlink("Caddyfile", &fixture.live).unwrap();
    assert_eq!(
        inspect(&fixture.input, &fixture.live, &mut host),
        Err(Error::Unavailable {})
    );
}

#[test]
fn native_forwarding_probes_ipv4_and_ipv6_listeners() {
    // Other tests spawn programs. Run this close-and-refuse check alone so their forks cannot retain its socket.
    if std::env::var_os("ORBIT_PUBLIC_FORWARDING_TEST").is_none() {
        let output = Process::new(std::env::current_exe().unwrap())
            .args(["--exact", "commands::doctor_public_route::tests::native_forwarding_probes_ipv4_and_ipv6_listeners", "--nocapture"])
            .env("ORBIT_PUBLIC_FORWARDING_TEST", "1").output().unwrap();
        assert!(
            output.status.success(),
            "{}{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
        return;
    }
    for address in ["127.0.0.1:0", "[::1]:0"] {
        let listener = TcpListener::bind(address).unwrap();
        let address = listener.local_addr().unwrap();
        assert!(Native.reachable(address));
        drop(listener);
        assert!(!Native.reachable(address));
    }
}

#[test]
fn public_certificate_margin_is_one_sixth_of_its_actual_lifetime() {
    let dates = "notBefore=Oct  1 00:00:00 2026 GMT\nnotAfter=Oct  7 00:00:00 2026 GMT\n";
    let start = time::macros::datetime!(2026-10-01 0:00 UTC).unix_timestamp();
    assert!(!outside_renewal_margin(dates, start - 1));
    assert!(outside_renewal_margin(dates, start + 5 * 86400));
    assert!(!outside_renewal_margin(dates, start + 5 * 86400 + 1));
    assert!(!outside_renewal_margin("missing", start));
    assert!(!outside_renewal_margin(
        &dates.replace("Oct  7", "Oct  1"),
        start
    ));
}

#[test]
fn public_listener_verification_rejects_other_roots_and_wrong_hostnames() {
    let fixture = Fixture::new();
    let openssl = |args: &[&str]| {
        assert!(Process::new("/usr/bin/openssl")
            .args(args)
            .current_dir(&fixture.root)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .unwrap()
            .success())
    };
    for name in ["public", "local"] {
        fs::create_dir(fixture.root.join(name)).unwrap();
        openssl(&[
            "req",
            "-x509",
            "-newkey",
            "ec",
            "-pkeyopt",
            "ec_paramgen_curve:prime256v1",
            "-nodes",
            "-days",
            "1",
            "-subj",
            &format!("/CN={name}"),
            "-keyout",
            &format!("{name}.key"),
            "-out",
            &format!("{name}/root.crt"),
        ]);
    }
    openssl(&[
        "req",
        "-new",
        "-newkey",
        "ec",
        "-pkeyopt",
        "ec_paramgen_curve:prime256v1",
        "-nodes",
        "-subj",
        "/CN=app.test",
        "-keyout",
        "leaf.key",
        "-out",
        "leaf.csr",
    ]);
    fs::write(
        fixture.root.join("extensions"),
        "subjectAltName=DNS:app.test\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n",
    )
    .unwrap();
    openssl(&[
        "x509",
        "-req",
        "-in",
        "leaf.csr",
        "-CA",
        "public/root.crt",
        "-CAkey",
        "public.key",
        "-CAcreateserial",
        "-days",
        "1",
        "-extfile",
        "extensions",
        "-out",
        "leaf.pem",
    ]);
    let cert = fs::read(fixture.root.join("leaf.pem")).unwrap();
    let key = fs::read(fixture.root.join("leaf.key")).unwrap();
    let config = Arc::new(
        rustls::ServerConfig::builder()
            .with_no_client_auth()
            .with_single_cert(
                rustls_pemfile::certs(&mut cert.as_slice())
                    .collect::<Result<Vec<_>, _>>()
                    .unwrap(),
                rustls_pemfile::private_key(&mut key.as_slice())
                    .unwrap()
                    .unwrap(),
            )
            .unwrap(),
    );
    for (root, domain, expected) in [
        ("public", "app.test", true),
        ("local", "app.test", false),
        ("public", "other.test", false),
    ] {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let config = config.clone();
        let server = std::thread::spawn(move || {
            let (mut socket, _) = listener.accept().unwrap();
            socket
                .set_read_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            socket
                .set_write_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            let _ = rustls::ServerConnection::new(config)
                .unwrap()
                .complete_io(&mut socket);
        });
        assert_eq!(
            certificate(
                domain,
                address,
                &fixture.root.join(root),
                time::OffsetDateTime::now_utc().unix_timestamp()
            ),
            expected
        );
        server.join().unwrap();
    }
}
