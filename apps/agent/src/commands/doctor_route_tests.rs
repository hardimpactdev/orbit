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
