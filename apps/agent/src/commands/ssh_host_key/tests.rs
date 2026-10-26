use super::*;
use std::collections::VecDeque;

#[derive(Default)]
struct Fake {
    results: VecDeque<Output>,
    calls: Vec<(String, Vec<String>, Option<String>)>,
}
impl Host for Fake {
    fn run(&mut self, program: &str, args: &[&str], input: Option<&str>) -> Output {
        self.calls.push((
            program.into(),
            args.iter().map(|s| (*s).into()).collect(),
            input.map(str::to_string),
        ));
        self.results.pop_front().expect("unexpected command")
    }
}
fn output(code: i32, stdout: &str) -> Output {
    Output {
        code,
        stdout: stdout.into(),
        stderr: "diagnostic".into(),
        truncated: false,
    }
}
fn input() -> Input {
    Input {
        host: "192.0.2.10".into(),
        port: 2222,
    }
}
fn fingerprint() -> String {
    format!("SHA256:{}", "a".repeat(43))
}

#[test]
fn prefers_ed25519_then_ecdsa_then_rsa_on_repeated_scans() {
    for (keys, wanted) in [
        (
            "host ssh-rsa RSA\nhost ecdsa-sha2-nistp256 EC\nhost ssh-ed25519 ED\n",
            ("ssh-ed25519", "ED"),
        ),
        (
            "host ssh-rsa RSA\nhost ecdsa-sha2-nistp256 EC\n",
            ("ecdsa-sha2-nistp256", "EC"),
        ),
        ("host ssh-rsa RSA\n", ("ssh-rsa", "RSA")),
    ] {
        for _ in 0..2 {
            let mut host = Fake {
                results: VecDeque::from([
                    output(0, keys),
                    output(0, &format!("256 {} target (KEY)\n", fingerprint())),
                ]),
                ..Default::default()
            };
            assert_eq!(
                scan(input(), &mut host),
                Ok(HostKey {
                    r#type: wanted.0.into(),
                    value: wanted.1.into(),
                    fingerprint: fingerprint(),
                })
            );
            assert_eq!(
                host.calls[0],
                (
                    "/usr/bin/ssh-keyscan".into(),
                    ["-T", "10", "-p", "2222", "--", "192.0.2.10"]
                        .map(str::to_string)
                        .to_vec(),
                    None
                )
            );
            assert_eq!(
                host.calls[1],
                (
                    "/usr/bin/ssh-keygen".into(),
                    ["-lf", "-", "-E", "sha256"].map(str::to_string).to_vec(),
                    Some(format!("host {} {}\n", wanted.0, wanted.1))
                )
            );
        }
    }
}

#[test]
fn rejects_invalid_input_before_starting_a_process() {
    for name in [
        "",
        "-f",
        "one,two",
        "one two",
        "host\nother",
        "/file",
        "bad..host",
        "host..",
        "bad;host",
        "host/-x",
    ] {
        let mut host = Fake::default();
        assert_eq!(
            scan(
                Input {
                    host: name.into(),
                    ..input()
                },
                &mut host
            ),
            Err(Error::Invalid {})
        );
        assert!(host.calls.is_empty());
    }
    let mut host = Fake::default();
    assert_eq!(
        scan(Input { port: 0, ..input() }, &mut host),
        Err(Error::Invalid {})
    );
    assert!(host.calls.is_empty());
    assert!(serde_json::from_str::<Input>(r#"{"host":"host","port":22,"argv":[]}"#).is_err());
    assert!(serde_json::from_str::<Input>(r#"{"host":"host","port":65536}"#).is_err());
}

#[test]
fn accepts_one_ip_or_hostname() {
    for name in [
        "localhost",
        "host.example",
        "host.example.",
        "2001:db8::1",
        "192.0.2.10",
    ] {
        let mut host = Fake {
            results: VecDeque::from([output(0, "")]),
            ..Default::default()
        };
        assert_eq!(
            scan(
                Input {
                    host: name.into(),
                    ..input()
                },
                &mut host
            ),
            Err(Error::Malformed {})
        );
        assert_eq!(host.calls[0].1.last().unwrap(), name);
    }
}

#[test]
fn refuses_failed_or_truncated_scan_and_fingerprint_with_bounded_diagnostics() {
    for stage in ["scan", "fingerprint"] {
        for truncated in [false, true] {
            let mut failure = output(if truncated { 0 } else { 255 }, "");
            failure.truncated = truncated;
            failure.stderr = "x".repeat(20000);
            let mut host = Fake::default();
            if stage == "fingerprint" {
                host.results.push_back(output(0, "host ssh-ed25519 ED\n"));
            }
            host.results.push_back(failure);
            let Err(Error::Failed {
                stage: observed,
                exit_code,
                stderr,
            }) = scan(input(), &mut host)
            else {
                panic!("expected typed command failure");
            };
            assert_eq!(observed, stage);
            assert_eq!(exit_code, if truncated { 0 } else { 255 });
            assert!(!stderr.is_empty() && stderr.len() <= 8000);
            assert_eq!(host.calls.len(), if stage == "scan" { 1 } else { 2 });
        }
    }
}

#[test]
fn rejects_missing_or_malformed_keys_without_fingerprinting() {
    for text in [
        "",
        "# comment ssh-ed25519 KEY",
        "host ssh-dss KEY",
        "host ssh-ed25519",
        "host ssh-ed25519 KEY extra",
    ] {
        let mut host = Fake {
            results: VecDeque::from([output(0, text)]),
            ..Default::default()
        };
        assert_eq!(scan(input(), &mut host), Err(Error::Malformed {}));
        assert_eq!(host.calls.len(), 1);
    }
}

#[test]
fn rejects_malformed_fingerprints() {
    for text in ["", "256 MD5:old key", "256 SHA256:short key", "invalid"] {
        let mut host = Fake {
            results: VecDeque::from([output(0, "host ssh-ed25519 ED\n"), output(0, text)]),
            ..Default::default()
        };
        assert_eq!(scan(input(), &mut host), Err(Error::Malformed {}));
    }
}

#[test]
fn scan_is_an_account_read_on_linux() {
    assert_eq!(Scan::privilege(Platform::Linux), Some(Privilege::Account));
    assert_eq!(Scan::privilege(Platform::Macos), None);
    const { assert!(!Scan::MUTATES) };
    assert_eq!(Scan::target(&input()), None);
}
