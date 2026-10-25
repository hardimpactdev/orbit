use super::*;
use std::{
    io::{Read, Write},
    net::TcpListener,
};

fn input(port: u16) -> Input {
    Input {
        address: "127.0.0.1".parse().unwrap(),
        port,
        domain: "route.example.test".into(),
    }
}

#[test]
fn head_preserves_host_accepts_error_status_and_does_not_follow_redirects() {
    for status in [200, 301, 503, 503] {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let server = std::thread::spawn(move || {
            let (mut socket, _) = listener.accept().unwrap();
            socket
                .set_read_timeout(Some(Duration::from_secs(2)))
                .unwrap();
            let mut received = Vec::new();
            let mut byte = [0];
            while !received.ends_with(b"\r\n\r\n") {
                socket.read_exact(&mut byte).unwrap();
                received.push(byte[0]);
            }
            write!(socket, "HTTP/1.1 {status} Result\r\nLocation: http://127.0.0.1:1/forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").unwrap();
            String::from_utf8(received).unwrap()
        });
        let mut command = request(&input(address.port())).unwrap();
        command
            .env("http_proxy", "http://127.0.0.1:1")
            .env("ALL_PROXY", "http://127.0.0.1:1");
        assert!(command.status().unwrap().success());
        let received = server.join().unwrap();
        assert!(received.starts_with("HEAD / HTTP/1.1\r\n"));
        assert!(received.contains("Host: route.example.test\r\n"));
    }
}

#[test]
fn connection_failure_and_non_http_reply_are_typed_refusals() {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = std::thread::spawn(move || {
        let (mut socket, _) = listener.accept().unwrap();
        socket.write_all(b"not an HTTP response\r\n\r\n").unwrap();
    });
    assert_eq!(verify(&input(port)), Err(Error::Unreachable {}));
    server.join().unwrap();
    assert_eq!(verify(&input(port)), Err(Error::Unreachable {}));
}

#[test]
fn refuses_invalid_fields_before_starting_a_request() {
    for (port, domain) in [
        (0, "route.test"),
        (443, "bad\r\nHost: other"),
        (443, "-option"),
        (443, ""),
    ] {
        let mut input = input(port);
        input.domain = domain.into();
        assert_eq!(verify(&input), Err(Error::Invalid {}));
    }
    for value in [
        serde_json::json!({"address":"http://other", "port":80, "domain":"route.test"}),
        serde_json::json!({"address":"127.0.0.1", "port":80, "domain":"route.test", "url":"http://other"}),
    ] {
        assert!(serde_json::from_value::<Input>(value).is_err());
    }
    let mut input = input(8080);
    input.address = "::1".parse().unwrap();
    let command = request(&input).unwrap();
    assert_eq!(command.get_program(), "/usr/bin/curl");
    assert_eq!(command.get_args().last().unwrap(), "http://[::1]:8080/");
}
