//! `http.await-healthy`: waits from the Node until a local service answers its health URL with a 2xx status.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum HealthError {
        Invalid {} => "http.url_invalid", "The health URL is not an http:// or https:// URL.";
        Unhealthy {} => "http.unhealthy", "The service did not answer its health URL in time.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HealthInput {
    pub url: String,
    pub attempts: u32,
    pub interval_seconds: u64,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Healthy {}

/// The process and clock seam: production runs curl and sleeps, tests record the calls.
trait Host {
    /// Runs curl with `arguments` and answers whether it succeeded.
    fn curl(&mut self, arguments: &[&str]) -> bool;
    fn sleep(&mut self, delay: Duration);
}

struct Native;

impl Host for Native {
    fn curl(&mut self, arguments: &[&str]) -> bool {
        // `-q` must come first: it keeps the account's ~/.curlrc out of the check.
        Process::new("/usr/bin/curl")
            .arg("-q")
            .args(arguments)
            .stdin(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }

    fn sleep(&mut self, delay: Duration) {
        std::thread::sleep(delay);
    }
}

fn valid(url: &str) -> bool {
    url.strip_prefix("http://")
        .or_else(|| url.strip_prefix("https://"))
        .is_some_and(|rest| !rest.is_empty())
}

fn wait(host: &mut impl Host, input: &HealthInput) -> Result<Healthy, HealthError> {
    if !valid(&input.url) {
        return Err(HealthError::Invalid {});
    }
    let arguments = [
        "--fail",
        "--silent",
        "--show-error",
        "--max-time",
        "3",
        "--output",
        "/dev/null",
        "--",
        &input.url,
    ];
    for attempt in 1..=input.attempts {
        if host.curl(&arguments) {
            return Ok(Healthy {});
        }
        if attempt < input.attempts {
            host.sleep(Duration::from_secs(input.interval_seconds));
        }
    }
    Err(HealthError::Unhealthy {})
}

pub struct AwaitHealthy;

impl Command for AwaitHealthy {
    const NAME: &'static str = "http.await-healthy";
    const SUMMARY: &'static str = "Wait until a service answers its health URL.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = HealthInput;
    type Output = Healthy;
    type Error = HealthError;

    /// An HTTP request needs no identity.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: HealthInput, _: &Context) -> Result<Healthy, HealthError> {
        wait(&mut Native, &input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Answers each curl call from a list, records the calls, and records each sleep instead of sleeping.
    #[derive(Default)]
    struct Fake {
        answers: Vec<bool>,
        calls: Vec<Vec<String>>,
        sleeps: Vec<Duration>,
    }

    impl Host for Fake {
        fn curl(&mut self, arguments: &[&str]) -> bool {
            self.calls
                .push(arguments.iter().map(|s| s.to_string()).collect());
            self.answers.remove(0)
        }

        fn sleep(&mut self, delay: Duration) {
            self.sleeps.push(delay);
        }
    }

    fn input(url: &str, attempts: u32) -> HealthInput {
        HealthInput {
            url: url.into(),
            attempts,
            interval_seconds: 2,
        }
    }

    fn arguments(url: &str) -> Vec<String> {
        [
            "--fail",
            "--silent",
            "--show-error",
            "--max-time",
            "3",
            "--output",
            "/dev/null",
            "--",
            url,
        ]
        .map(String::from)
        .to_vec()
    }

    #[test]
    fn a_healthy_service_answers_on_the_first_request() {
        let mut host = Fake {
            answers: vec![true],
            ..Fake::default()
        };
        let url = "http://10.0.0.5:8000/api/health";
        assert_eq!(wait(&mut host, &input(url, 90)), Ok(Healthy {}));
        assert_eq!(host.calls, vec![arguments(url)]);
        assert!(host.sleeps.is_empty());
    }

    #[test]
    fn a_starting_service_is_asked_again_after_each_interval() {
        let mut host = Fake {
            answers: vec![false, false, true],
            ..Fake::default()
        };
        let url = "https://plausible.orbit/api/health";
        assert_eq!(wait(&mut host, &input(url, 5)), Ok(Healthy {}));
        assert_eq!(host.calls, vec![arguments(url); 3]);
        assert_eq!(host.sleeps, vec![Duration::from_secs(2); 2]);
    }

    #[test]
    fn a_service_that_never_answers_is_unhealthy_after_its_last_attempt() {
        let mut host = Fake {
            answers: vec![false; 3],
            ..Fake::default()
        };
        assert_eq!(
            wait(&mut host, &input("http://10.0.0.5:8000/", 3)),
            Err(HealthError::Unhealthy {})
        );
        assert_eq!(host.calls.len(), 3);
        // No sleep follows the last attempt.
        assert_eq!(host.sleeps, vec![Duration::from_secs(2); 2]);
    }

    #[test]
    fn a_url_without_an_http_scheme_is_refused_before_curl_runs() {
        for url in [
            "",
            "http://",
            "https://",
            "file:///etc/orbit/agent/secret",
            "ftp://10.0.0.5/",
            "--config=/etc/passwd",
            "-K/etc/passwd",
            "10.0.0.5:8000/api/health",
            "HTTP://10.0.0.5/",
        ] {
            let mut host = Fake::default();
            assert_eq!(
                wait(&mut host, &input(url, 3)),
                Err(HealthError::Invalid {}),
                "{url}"
            );
            assert!(host.calls.is_empty(), "{url}");
        }
    }
}
