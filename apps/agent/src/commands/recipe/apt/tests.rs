use super::*;
use crate::commands::recipe::diagnostic;
use std::collections::VecDeque;

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
