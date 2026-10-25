//! `routes.verify-certificate`: checks from this Node that a workload serves a certificate for a Route's domain that
//! this Node's trust store accepts, as Router Caddy will need before it forwards the Route.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    net::{IpAddr, SocketAddr},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum CertificateError {
        Input {} => "routes.certificate_input_invalid", "The Route certificate check needs an IP address, port, and domain.";
        Invalid { stderr: String } => "routes.certificate_invalid", "The workload does not serve a trusted certificate for the domain.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CertificateInput {
    pub address: IpAddr,
    pub port: u16,
    pub domain: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Verified {}

pub fn verify(input: &CertificateInput) -> Result<Verified, CertificateError> {
    let output =
        certificate_process(input)?
            .output()
            .map_err(|error| CertificateError::Invalid {
                stderr: error.to_string(),
            })?;
    if output.status.success() {
        return Ok(Verified {});
    }
    let stderr = String::from_utf8_lossy(&output.stderr);
    let tail: String = stderr
        .trim()
        .chars()
        .rev()
        .take(1000)
        .collect::<Vec<_>>()
        .into_iter()
        .rev()
        .collect();
    Err(CertificateError::Invalid { stderr: tail })
}

fn certificate_process(input: &CertificateInput) -> Result<Process, CertificateError> {
    if input.port == 0 || !super::doctor_route::valid_domain(&input.domain) {
        return Err(CertificateError::Input {});
    }
    let mut process = Process::new("/usr/bin/timeout");
    process
        .args([
            "10",
            "/usr/bin/openssl",
            "s_client",
            "-connect",
            &SocketAddr::new(input.address, input.port).to_string(),
            "-servername",
            &input.domain,
            "-verify_return_error",
        ])
        .stdin(Stdio::null());
    Ok(process)
}

pub struct VerifyCertificate;

impl Command for VerifyCertificate {
    const NAME: &'static str = "routes.verify-certificate";
    const SUMMARY: &'static str =
        "Check that a workload serves a trusted certificate for a Route's domain.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = CertificateInput;
    type Output = Verified;
    type Error = CertificateError;

    /// A TLS handshake needs no identity.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: CertificateInput, _: &Context) -> Result<Verified, CertificateError> {
        verify(&input)
    }
}

#[cfg(test)]
#[path = "tls_check_tests.rs"]
mod tests;
