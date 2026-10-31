//! Read-only Grafana authentication observation. Password changes execute in Swamp.
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{net::Ipv4Addr, time::Duration};

#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Error {
    Invalid,
    Address,
}
impl CommandError for Error {
    const CODES: &'static [&'static str] = &[
        "metrics.runtime_input_invalid",
        "metrics.wireguard_ip_missing",
    ];
    fn code(&self) -> &'static str {
        match self {
            Self::Invalid => Self::CODES[0],
            Self::Address => Self::CODES[1],
        }
    }
    fn message(&self) -> String {
        match self {
            Self::Invalid => "Metrics runtime input is invalid.",
            Self::Address => "Metrics requires a WireGuard IPv4 address.",
        }
        .into()
    }
    fn details(&self) -> Value {
        json!({"status":match self { Self::Invalid => 422, Self::Address => 409 }})
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: String,
    action: Action,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
enum Action {
    Verify { password: String },
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    verified: bool,
}
fn credential_request(address: &str, password: &str) -> bool {
    let Ok(runtime) = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
    else {
        return false;
    };
    runtime.block_on(async {
        let Ok(client) = reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .redirect(reqwest::redirect::Policy::none())
            .no_proxy()
            .build()
        else {
            return false;
        };
        client
            .get(format!("http://{address}/api/user"))
            .basic_auth("admin", Some(password))
            .send()
            .await
            .is_ok_and(|response| response.status().is_success())
    })
}
fn observe(input: Input, request: impl FnOnce(&str, &str) -> bool) -> Result<Output, Error> {
    let address: Ipv4Addr = input.address.parse().map_err(|_| Error::Address)?;
    let Action::Verify { password } = input.action;
    if password.is_empty() {
        return Err(Error::Invalid);
    }
    Ok(Output {
        verified: request(&format!("{address}:3000"), &password),
    })
}
pub struct MetricsCredentials;
impl Command for MetricsCredentials {
    const NAME: &'static str = "metrics.credentials";
    const SUMMARY: &'static str = "Observe whether Grafana accepts its administrator credential.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        observe(input, credential_request)
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{BufRead, BufReader, Write};

    #[test]
    fn credentials_allow_only_read_only_verification() {
        for action in [
            json!({"operation":"apply","active_password":"old","pending_password":"new"}),
            json!({"operation":"verify","password":"secret","pending_password":"new"}),
        ] {
            assert!(serde_json::from_value::<Input>(
                json!({"address":"10.44.0.3","action":action})
            )
            .is_err());
        }
        let input: Input = serde_json::from_value(
            json!({"address":"10.44.0.3","action":{"operation":"verify","password":"secret"}}),
        )
        .unwrap();
        let output = observe(input, |address, password| {
            assert_eq!(address, "10.44.0.3:3000");
            assert_eq!(password, "secret");
            true
        })
        .unwrap();
        assert_eq!(
            serde_json::to_value(output).unwrap(),
            json!({"verified":true})
        );
        for (address, password, error) in [
            ("hostname", "secret", Error::Address),
            ("10.44.0.3", "", Error::Invalid),
        ] {
            assert_eq!(
                observe(
                    Input {
                        address: address.into(),
                        action: Action::Verify {
                            password: password.into()
                        }
                    },
                    |_, _| panic!("invalid input must not perform HTTP")
                )
                .unwrap_err(),
                error
            );
        }
    }

    #[test]
    fn native_http_only_authenticates_get_and_refuses_unauthorized_or_redirects() {
        for status in [200, 401, 302] {
            let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
            let address = listener.local_addr().unwrap().to_string();
            let server = std::thread::spawn(move || {
                let (mut stream, _) = listener.accept().unwrap();
                stream
                    .set_read_timeout(Some(Duration::from_secs(2)))
                    .unwrap();
                let mut reader = BufReader::new(stream.try_clone().unwrap());
                let mut first = String::new();
                reader.read_line(&mut first).unwrap();
                assert_eq!(first, "GET /api/user HTTP/1.1\r\n");
                let mut headers = String::new();
                loop {
                    let mut line = String::new();
                    reader.read_line(&mut line).unwrap();
                    if line == "\r\n" {
                        break;
                    }
                    headers.push_str(&line);
                }
                assert!(headers
                    .to_lowercase()
                    .contains("authorization: basic ywrta"));
                assert!(!headers.to_lowercase().contains("content-length:"));
                write!(stream, "HTTP/1.1 {status} Test\r\nLocation: http://127.0.0.1:1/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").unwrap();
            });
            assert_eq!(
                credential_request(&address, "active-password"),
                status == 200
            );
            server.join().unwrap();
        }
    }
}
