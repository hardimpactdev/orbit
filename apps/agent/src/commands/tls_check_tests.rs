use super::*;
use std::{fs, net::TcpListener, sync::Arc};

fn input(port: u16) -> CertificateInput {
    CertificateInput {
        address: "127.0.0.1".parse().unwrap(),
        port,
        domain: "route.example.test".into(),
    }
}

#[test]
fn rejects_invalid_fields_and_builds_fixed_ipv6_connection() {
    for (port, domain) in [
        (0, "route.test"),
        (443, "bad\nname"),
        (443, "-option"),
        (443, ""),
    ] {
        let mut input = input(port);
        input.domain = domain.into();
        assert_eq!(verify(&input), Err(CertificateError::Input {}));
    }
    assert!(serde_json::from_value::<CertificateInput>(
        serde_json::json!({"address":"host:443", "port":443, "domain":"route.test"})
    )
    .is_err());
    let mut input = input(443);
    input.address = "::1".parse().unwrap();
    let command = certificate_process(&input).unwrap();
    assert_eq!(command.get_program(), "/usr/bin/timeout");
    assert_eq!(
        command.get_args().collect::<Vec<_>>(),
        [
            "10",
            "/usr/bin/openssl",
            "s_client",
            "-connect",
            "[::1]:443",
            "-servername",
            "route.example.test",
            "-verify_return_error"
        ]
    );
}

#[test]
fn verifies_trust_sends_sni_repeats_and_preserves_chain_only_policy() {
    let directory = std::env::temp_dir().join(format!("orbit-route-tls-{}", std::process::id()));
    fs::create_dir(&directory).unwrap();
    let output = Process::new("/usr/bin/openssl")
        .current_dir(&directory)
        .args([
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
            "/CN=different.example.test",
            "-keyout",
            "key.pem",
            "-out",
            "cert.pem",
        ])
        .output()
        .unwrap();
    assert!(output.status.success());
    let cert = fs::read(directory.join("cert.pem")).unwrap();
    let key = fs::read(directory.join("key.pem")).unwrap();
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
    for trusted in [true, true, false] {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        let config = config.clone();
        let server = std::thread::spawn(move || {
            let (mut socket, _) = listener.accept().unwrap();
            socket
                .set_read_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            socket
                .set_write_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            let mut tls = rustls::ServerConnection::new(config).unwrap();
            let _ = tls.complete_io(&mut socket);
            tls.server_name().map(str::to_owned)
        });
        let mut command = certificate_process(&input(port)).unwrap();
        if trusted {
            command.env("SSL_CERT_FILE", directory.join("cert.pem"));
        }
        let output = command.output().unwrap();
        assert_eq!(
            output.status.success(),
            trusted,
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        assert_eq!(
            server.join().unwrap().as_deref(),
            Some("route.example.test")
        );
    }
    fs::remove_dir_all(directory).unwrap();
}

#[test]
fn unreachable_listener_is_a_bounded_typed_refusal() {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    drop(listener);
    let Err(CertificateError::Invalid { stderr }) = verify(&input(port)) else {
        panic!("connection must fail")
    };
    assert!(!stderr.is_empty());
    assert!(stderr.chars().count() <= 1000);
}
