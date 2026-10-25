//! Public Process Route activation checks reachability, not application health.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    net::{IpAddr, SocketAddr},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "routes.http_input_invalid", "The Route HTTP check needs an IP address, port, and domain.";
        Unreachable {} => "routes.http_unreachable", "The selected Process listener did not answer HTTP.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: IpAddr,
    port: u16,
    domain: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Verified {}

fn request(input: &Input) -> Result<Process, Error> {
    if input.port == 0 || !super::doctor_route::valid_domain(&input.domain) {
        return Err(Error::Invalid {});
    }
    let mut process = Process::new("/usr/bin/curl");
    process
        .args([
            "--head",
            "--noproxy",
            "*",
            "--silent",
            "--show-error",
            "--connect-timeout",
            "10",
            "--max-time",
            "10",
            "--output",
            "/dev/null",
            "--header",
            &format!("Host: {}", input.domain),
            &format!("http://{}/", SocketAddr::new(input.address, input.port)),
        ])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null());
    Ok(process)
}

fn verify(input: &Input) -> Result<Verified, Error> {
    if request(input)?
        .status()
        .map_err(|_| Error::Unreachable {})?
        .success()
    {
        Ok(Verified {})
    } else {
        Err(Error::Unreachable {})
    }
}

pub struct VerifyHttp;
impl Command for VerifyHttp {
    const NAME: &'static str = "routes.verify-http";
    const SUMMARY: &'static str =
        "Check HTTP reachability of a public Process Route from its Ingress.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = Input;
    type Output = Verified;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Verified, Error> {
        verify(&input)
    }
}

#[cfg(test)]
#[path = "http_check_tests.rs"]
mod tests;
