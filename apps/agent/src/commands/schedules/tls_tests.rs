use super::{runtime, tests::Fixture, *};
use std::{
    io::{Read, Write},
    net::TcpListener,
    sync::Arc,
};

fn openssl(directory: &Path, args: &[&str]) {
    assert!(Process::new("openssl")
        .args(args)
        .current_dir(directory)
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .unwrap()
        .success());
}

/// A real TLS server checks trust, the completion payload, and redirect refusal.
#[test]
fn completion_uses_only_the_pinned_ca_and_never_redirects_or_sends_credentials() {
    let fixture = Fixture::new();
    let root = &fixture.host.root;
    for name in ["orbit", "other"] {
        openssl(
            root,
            &[
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
                &format!("{name}.pem"),
            ],
        );
    }
    openssl(
        root,
        &[
            "req",
            "-new",
            "-newkey",
            "ec",
            "-pkeyopt",
            "ec_paramgen_curve:prime256v1",
            "-nodes",
            "-subj",
            "/CN=localhost",
            "-keyout",
            "leaf.key",
            "-out",
            "leaf.csr",
        ],
    );
    fs::write(
        root.join("extensions"),
        "subjectAltName=IP:127.0.0.1\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n",
    )
    .unwrap();
    openssl(
        root,
        &[
            "x509",
            "-req",
            "-in",
            "leaf.csr",
            "-CA",
            "orbit.pem",
            "-CAkey",
            "orbit.key",
            "-CAcreateserial",
            "-days",
            "1",
            "-extfile",
            "extensions",
            "-out",
            "leaf.pem",
        ],
    );
    let leaf = fs::read(root.join("leaf.pem")).unwrap();
    let key = fs::read(root.join("leaf.key")).unwrap();
    let certs = rustls_pemfile::certs(&mut leaf.as_slice())
        .collect::<Result<Vec<_>, _>>()
        .unwrap();
    let key = rustls_pemfile::private_key(&mut key.as_slice())
        .unwrap()
        .unwrap();
    let config = Arc::new(
        rustls::ServerConfig::builder()
            .with_no_client_auth()
            .with_single_cert(certs, key)
            .unwrap(),
    );
    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .unwrap();
    for (ca, success, status) in [
        ("orbit", true, 204),
        ("orbit", false, 204),
        ("other", true, 204),
        ("orbit", true, 302),
    ] {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let config = config.clone();
        let server = std::thread::spawn(move || {
            let (socket, _) = listener.accept().unwrap();
            socket
                .set_read_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            socket
                .set_write_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            let mut tls =
                rustls::StreamOwned::new(rustls::ServerConnection::new(config).unwrap(), socket);
            let mut received = Vec::new();
            let mut byte = [0];
            while !received.ends_with(b"\r\n\r\n") {
                if tls.read_exact(&mut byte).is_err() {
                    return None;
                }
                received.push(byte[0]);
            }
            let headers = String::from_utf8(received).unwrap();
            let length = headers
                .lines()
                .find_map(|line| {
                    line.to_ascii_lowercase()
                        .strip_prefix("content-length:")
                        .map(|v| v.trim().parse::<usize>().unwrap())
                })
                .unwrap();
            let mut body = vec![0; length];
            tls.read_exact(&mut body).unwrap();
            write!(tls, "HTTP/1.1 {status} Result\r\nLocation: https://127.0.0.1:1/forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").unwrap();
            tls.flush().unwrap();
            Some((headers, body))
        });
        let result = runtime.block_on(runtime::report(
            &format!(
                "https://{address}/api/v1/schedules/{}/complete",
                super::tests::ID
            ),
            &fs::read(root.join(format!("{ca}.pem"))).unwrap(),
            success,
        ));
        let received = server.join().unwrap();
        if ca == "other" {
            assert!(result.is_err());
            assert!(received.is_none());
        } else {
            assert!(result.is_ok(), "{result:?}");
            let (headers, body) = received.unwrap();
            assert!(headers.starts_with("POST /api/v1/schedules/"));
            assert!(!headers.to_ascii_lowercase().contains("authorization"));
            assert_eq!(
                serde_json::from_slice::<serde_json::Value>(&body).unwrap(),
                serde_json::json!({"status": if success { "success" } else { "error" }})
            );
        }
    }
}
