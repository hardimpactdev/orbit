use super::*;
use std::os::unix::fs::symlink;

struct Fixture {
    root: PathBuf,
    input: ProjectionInput,
    live: PathBuf,
}
impl Fixture {
    fn new() -> Self {
        let root =
            std::env::temp_dir().join(format!("orbit-doctor-route-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("certificates/versions/one")).unwrap();
        let certificates = root.join("certificates/versions/one");
        fs::write(certificates.join("cert.pem"), "certificate").unwrap();
        fs::write(certificates.join("key.pem"), "private key").unwrap();
        symlink("versions/one", root.join("certificates/current")).unwrap();
        fs::write(
            root.join(".env"),
            "SECRET=hidden\nAPP_URL=\"https://app.test\"\n",
        )
        .unwrap();
        fs::write(
            root.join("build"),
            "app.test { reverse_proxy 10.44.0.2:443 }",
        )
        .unwrap();
        symlink("build", root.join("Caddyfile")).unwrap();
        let input = ProjectionInput {
            domain: "app.test".into(),
            certificates: root.join("certificates/current"),
            projection: Projection::Workload {
                dns_address: Some("10.44.0.2".parse().unwrap()),
                laravel: Some(Laravel::Check {
                    environment: root.join(".env"),
                    url: "https://app.test".into(),
                }),
            },
        };
        Self {
            live: root.join("Caddyfile"),
            root,
            input,
        }
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}
#[derive(Default)]
struct Fake {
    calls: usize,
    dns: Option<Ipv4Addr>,
    firewall: bool,
}
impl Fake {
    fn healthy() -> Self {
        Self {
            calls: 0,
            dns: Some("10.44.0.2".parse().unwrap()),
            firewall: true,
        }
    }
}
impl Runtime for Fake {
    fn dns(&mut self, _: &str) -> Option<Ipv4Addr> {
        self.calls += 1;
        self.dns
    }
    fn firewall(&mut self) -> bool {
        self.calls += 1;
        self.firewall
    }
}
#[test]
fn workload_reads_live_links_and_returns_only_repeatable_matches() {
    let fixture = Fixture::new();
    for _ in 0..2 {
        let result = inspect(&fixture.input, &fixture.live, &mut Fake::healthy()).unwrap();
        assert_eq!(
            result,
            Observed {
                caddy: true,
                tls: true,
                firewall: true,
                dns: Some(true),
                laravel: Some(true),
                pool: None
            }
        );
        let json = serde_json::to_string(&result).unwrap();
        assert!(!json.contains("hidden") && !json.contains("app.test"));
    }
    assert_eq!(
        fs::read(fixture.root.join(".env")).unwrap(),
        b"SECRET=hidden\nAPP_URL=\"https://app.test\"\n"
    );
}
#[test]
fn absent_or_different_projections_report_drift_without_repair() {
    let fixture = Fixture::new();
    fs::remove_file(fixture.root.join("build")).unwrap();
    fs::remove_file(fixture.root.join("certificates/versions/one/key.pem")).unwrap();
    fs::write(fixture.root.join(".env"), "APP_URL=https://other.test\n").unwrap();
    assert_eq!(
        inspect(&fixture.input, &fixture.live, &mut Fake::default()).unwrap(),
        Observed {
            caddy: false,
            tls: false,
            firewall: false,
            dns: Some(false),
            laravel: Some(false),
            pool: None,
        }
    );
    assert!(!fixture.root.join("build").exists());
}
#[test]
fn url_check_requires_one_literal_value_in_a_regular_environment_file() {
    let fixture = Fixture::new();
    let file = fixture.root.join(".env");
    for text in [
        "APP_URL=https://app.test\n",
        "APP_URL='https://app.test'\r\n",
        "APP_URL=\"https://app.test\"\n",
    ] {
        fs::write(&file, text).unwrap();
        assert!(url_matches(&file, "https://app.test"));
    }
    for text in [
        "",
        "APP_URL=https://app.test\nAPP_URL=https://app.test\n",
        "APP_URL=https://other.test\n",
        "APP_URL=${URL}\n",
    ] {
        fs::write(&file, text).unwrap();
        assert!(!url_matches(&file, "https://app.test"));
    }
    fs::rename(&file, fixture.root.join("secret")).unwrap();
    symlink("secret", &file).unwrap();
    assert!(!url_matches(&file, "https://app.test"));
    fs::remove_file(&file).unwrap();
    assert!(!url_matches(&file, "https://app.test"));
}
#[test]
fn unavailable_intent_stays_unknown_and_non_laravel_checks_are_skipped() {
    let mut fixture = Fixture::new();
    fixture.input.projection = Projection::Workload {
        dns_address: None,
        laravel: None,
    };
    let mut runtime = Fake::healthy();
    let result = inspect(&fixture.input, &fixture.live, &mut runtime).unwrap();
    assert_eq!((result.dns, result.laravel, runtime.calls), (None, None, 1));
    fixture.input.projection = Projection::Workload {
        dns_address: None,
        laravel: Some(Laravel::Skip),
    };
    assert_eq!(
        inspect(&fixture.input, &fixture.live, &mut runtime)
            .unwrap()
            .laravel,
        Some(true)
    );
}
#[test]
fn router_requires_the_domain_and_every_expected_upstream() {
    let mut fixture = Fixture::new();
    for (upstreams, expected) in [
        (vec!["10.44.0.2:443".into()], true),
        (vec!["10.44.0.2:443".into(), "10.44.0.3:443".into()], false),
    ] {
        fixture.input.projection = Projection::Router { upstreams };
        let result = inspect(&fixture.input, &fixture.live, &mut Fake::healthy()).unwrap();
        assert_eq!(
            (result.caddy, result.pool, result.dns, result.laravel),
            (expected, Some(expected), None, None)
        );
    }
    fixture.input.domain = "missing.test".into();
    fixture.input.projection = Projection::Router { upstreams: vec![] };
    assert_eq!(
        inspect(&fixture.input, &fixture.live, &mut Fake::healthy())
            .unwrap()
            .pool,
        Some(false)
    );
}
#[test]
fn invalid_input_refuses_before_observations_and_unknown_fields_are_rejected() {
    let mut fixture = Fixture::new();
    for domain in ["", "--help", "app.test\nother.test", "app.*"] {
        fixture.input.domain = domain.into();
        let mut runtime = Fake::healthy();
        assert_eq!(
            inspect(&fixture.input, &fixture.live, &mut runtime),
            Err(InspectionError::Invalid {})
        );
        assert_eq!(runtime.calls, 0);
    }
    assert!(serde_json::from_value::<ProjectionInput>(serde_json::json!({
        "domain": "app.test", "certificates": "/etc/caddy/certs", "projection": { "kind": "router", "upstreams": [], "script": "anything" }
    })).is_err());
}

#[test]
fn batch_urls_inspect_each_application_once_without_changing_files() {
    let fixture = Fixture::new();
    let cases = [
        (
            "docs",
            Some("APP_NAME=Docs\nAPP_URL=https://app.test\n"),
            true,
        ),
        ("quoted", Some("APP_URL=\"https://app.test\"\n"), true),
        ("stale", Some("APP_URL=https://old.test\n"), false),
        (
            "twice",
            Some("APP_URL=https://app.test\nAPP_URL=https://app.test\n"),
            false,
        ),
        ("missing", None, false),
    ];
    let mut applications = Vec::new();
    for (name, env, _) in &cases {
        let root = fixture.root.join("apps").join(name);
        fs::create_dir_all(&root).unwrap();
        fs::write(root.join("artisan"), "<?php").unwrap();
        if let Some(env) = env {
            fs::write(root.join(".env"), env).unwrap();
        }
        applications.push(ApplicationUrl {
            directory: format!("apps/{name}"),
            url: "https://app.test".into(),
        });
    }
    // No artisan means no Laravel URL to inspect, including an absent directory or a linked artisan.
    fs::create_dir_all(fixture.root.join("static")).unwrap();
    fs::write(fixture.root.join("static/.env"), "APP_URL=other").unwrap();
    symlink("../apps/docs/artisan", fixture.root.join("static/artisan")).unwrap();
    for name in ["static", "absent", ""] {
        applications.push(ApplicationUrl {
            directory: name.into(),
            url: "https://app.test".into(),
        });
    }
    let input = ApplicationUrlsInput {
        checkout: fixture.root.clone(),
        applications,
    };
    for _ in 0..2 {
        assert_eq!(
            application_urls(&input).unwrap().matches,
            vec![true, true, false, false, false, true, true, true]
        );
    }
    for (name, env, _) in cases {
        assert_eq!(
            fs::read_to_string(fixture.root.join("apps").join(name).join(".env"))
                .ok()
                .as_deref(),
            env
        );
    }
    fs::write(fixture.root.join("artisan"), "<?php").unwrap();
    fs::write(fixture.root.join(".env"), "APP_URL=wrong\n").unwrap();
    assert_eq!(
        application_urls(&input).unwrap().matches.last(),
        Some(&false)
    );
}

#[test]
fn batch_urls_refuse_invalid_directories_and_preserve_empty_checks() {
    let fixture = Fixture::new();
    for directory in [
        "/tmp",
        "../outside",
        "apps/../outside",
        "./apps",
        "apps//docs",
        "apps\\docs",
        "apps\0",
    ] {
        let input = ApplicationUrlsInput {
            checkout: fixture.root.clone(),
            applications: vec![ApplicationUrl {
                directory: directory.into(),
                url: "https://app.test".into(),
            }],
        };
        assert_eq!(application_urls(&input), Err(InspectionError::Invalid {}));
    }
    let input = ApplicationUrlsInput {
        checkout: fixture.root.clone(),
        applications: vec![],
    };
    assert!(application_urls(&input).unwrap().matches.is_empty());
}

#[test]
fn custom_proxy_reads_the_live_file_without_importing_fragments_and_checks_the_actual_listener() {
    let fixture = Fixture::new();
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let input = ProxyInput {
        domain: "app.test".into(),
        certificates: fixture.input.certificates.clone(),
        upstream: listener.local_addr().unwrap(),
        dns_address: Some("10.44.0.2".parse().unwrap()),
    };
    fs::write(
        fixture.root.join("build"),
        format!("https://app.test {{ reverse_proxy {} }}", input.upstream),
    )
    .unwrap();
    for _ in 0..2 {
        assert_eq!(
            inspect_proxy(&input, &fixture.live, &mut Fake::healthy()).unwrap(),
            ProxyObserved {
                caddy: true,
                tls: true,
                dns: Some(true),
                upstream: true
            }
        );
    }
    fs::rename(fixture.root.join("build"), fixture.root.join("fragment")).unwrap();
    fs::write(fixture.root.join("build"), "import fragment\n").unwrap();
    drop(listener);
    let result = inspect_proxy(&input, &fixture.live, &mut Fake::default()).unwrap();
    assert_eq!(
        result,
        ProxyObserved {
            caddy: false,
            tls: true,
            dns: Some(false),
            upstream: false
        }
    );
}

#[test]
fn custom_proxy_ipv6_uses_the_configured_address_and_refuses_non_loopback_targets() {
    let fixture = Fixture::new();
    let listener = std::net::TcpListener::bind("[::1]:0").unwrap();
    let mut input = ProxyInput {
        domain: "app.test".into(),
        certificates: fixture.input.certificates.clone(),
        upstream: listener.local_addr().unwrap(),
        dns_address: None,
    };
    assert!(
        inspect_proxy(&input, &fixture.live, &mut Fake::healthy())
            .unwrap()
            .upstream
    );
    for address in ["192.0.2.1:80", "127.0.0.1:0"] {
        input.upstream = address.parse().unwrap();
        let mut runtime = Fake::healthy();
        assert_eq!(
            inspect_proxy(&input, &fixture.live, &mut runtime),
            Err(InspectionError::Invalid {})
        );
        assert_eq!(runtime.calls, 0);
    }
}

#[test]
fn dns_reads_match_mismatch_and_invalid_input_without_exposing_answers() {
    let mut input = DnsInput {
        domain: "app.test".into(),
        address: "10.44.0.2".parse().unwrap(),
    };
    for _ in 0..2 {
        assert_eq!(
            resolve_dns(&input, &mut Fake::healthy()),
            Ok(DnsObserved { matches: true })
        );
    }
    assert_eq!(
        resolve_dns(&input, &mut Fake::default()),
        Ok(DnsObserved { matches: false })
    );
    input.domain = "--help".into();
    let mut runtime = Fake::healthy();
    assert_eq!(
        resolve_dns(&input, &mut runtime),
        Err(InspectionError::Invalid {})
    );
    assert_eq!(runtime.calls, 0);
    input.domain = "localhost".into();
    input.address = Ipv4Addr::LOCALHOST;
    assert_eq!(
        resolve_dns(&input, &mut Native),
        Ok(DnsObserved { matches: true })
    );
}
