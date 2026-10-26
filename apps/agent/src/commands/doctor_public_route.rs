//! The public edge is checked at its live Caddyfile and listener, without loading fragments or local CA trust.
use super::doctor_route::{absolute, valid_domain};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::Write,
    net::{SocketAddr, TcpStream},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    sync::Arc,
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid {} => "doctor.public_route_invalid", "The public Route inspection input is invalid.";
        Unavailable {} => "doctor.public_route_unavailable", "The public Route configuration could not be read.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    domain: String,
    expected_site: String,
    certificates: PathBuf,
    #[schemars(extend("items" = {"type": "string", "format": "socket-address"}))]
    forwarding: Vec<SocketAddr>,
}

#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Observed {
    ingress: bool,
    forwarding: bool,
    tls: bool,
}

trait Runtime {
    fn certificate(&mut self, domain: &str) -> bool;
    fn reachable(&mut self, address: SocketAddr) -> bool;
}
struct Native;
impl Runtime for Native {
    fn certificate(&mut self, domain: &str) -> bool {
        certificate(
            domain,
            "127.0.0.1:443".parse().unwrap(),
            Path::new("/usr/share/ca-certificates/mozilla"),
            time::OffsetDateTime::now_utc().unix_timestamp(),
        )
    }
    fn reachable(&mut self, address: SocketAddr) -> bool {
        TcpStream::connect_timeout(&address, Duration::from_secs(1)).is_ok()
    }
}

fn without_tls(text: &str) -> String {
    text.lines()
        .filter(|line| !line.trim_start().starts_with("tls "))
        .collect::<Vec<_>>()
        .join("\n")
}

fn inspect(input: &Input, live: &Path, runtime: &mut impl Runtime) -> Result<Observed, Error> {
    if !valid_domain(&input.domain)
        || !absolute(&input.certificates)
        || input.expected_site.is_empty()
        || input.expected_site.len() > 1024 * 1024
        || input.forwarding.len() > 1024
        || input.forwarding.iter().any(|address| address.port() == 0)
    {
        return Err(Error::Invalid {});
    }
    let content = match fs::read_to_string(live) {
        Ok(text) => text,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => String::new(),
        Err(_) => return Err(Error::Unavailable {}),
    };
    // A build's first line is its source comment; it is not part of the site's effective configuration.
    let expected = input
        .expected_site
        .lines()
        .skip(1)
        .collect::<Vec<_>>()
        .join("\n");
    let expected = without_tls(&expected);
    if expected.is_empty() {
        return Err(Error::Invalid {});
    }
    let header = format!("{} {{", input.domain);
    let mut site = Vec::new();
    for line in content.lines().skip_while(|line| *line != header) {
        site.push(line);
        if line == "}" {
            break;
        }
    }
    let pinned = format!("tls {}/cert.pem", input.certificates.display());
    let automation_disabled = content
        .lines()
        .any(|line| matches!(line.trim(), "auto_https disable_certs" | "auto_https off"));
    let automation = !site.is_empty()
        && !content.contains(&pinned)
        && (site.iter().any(|line| line.trim() == "tls force_automate") || !automation_disabled);
    Ok(Observed {
        ingress: without_tls(&content).contains(&expected),
        forwarding: input
            .forwarding
            .iter()
            .all(|address| runtime.reachable(*address)),
        tls: automation && runtime.certificate(&input.domain),
    })
}

/// Verify the TLS handshake against packaged public roots and the Route hostname, then inspect the leaf's dates.
fn certificate(domain: &str, address: SocketAddr, roots: &Path, now: i64) -> bool {
    public_certificate_dates(domain, address, roots)
        .is_some_and(|dates| outside_renewal_margin(&dates, now))
}

fn public_certificate_dates(domain: &str, address: SocketAddr, roots: &Path) -> Option<String> {
    let mut trust = rustls::RootCertStore::empty();
    for entry in fs::read_dir(roots).ok()? {
        let entry = entry.ok()?;
        if entry
            .path()
            .extension()
            .is_none_or(|extension| extension != "crt")
            || !entry.file_type().ok()?.is_file()
        {
            continue;
        }
        let pem = fs::read(entry.path()).ok()?;
        for cert in rustls_pemfile::certs(&mut pem.as_slice()) {
            trust.add(cert.ok()?).ok()?;
        }
    }
    if trust.is_empty() {
        return None;
    }
    let config = rustls::ClientConfig::builder()
        .with_root_certificates(trust)
        .with_no_client_auth();
    let name = rustls::pki_types::ServerName::try_from(domain.to_owned()).ok()?;
    let mut client = rustls::ClientConnection::new(Arc::new(config), name).ok()?;
    let mut socket = TcpStream::connect_timeout(&address, Duration::from_secs(10)).ok()?;
    socket
        .set_read_timeout(Some(Duration::from_secs(10)))
        .ok()?;
    socket
        .set_write_timeout(Some(Duration::from_secs(10)))
        .ok()?;
    client.complete_io(&mut socket).ok()?;
    let leaf = client.peer_certificates()?.first()?;
    let mut command = Process::new("/usr/bin/openssl")
        .args(["x509", "-inform", "DER", "-noout", "-dates"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .ok()?;
    let written = command.stdin.take()?.write_all(leaf.as_ref()).is_ok();
    let output = command.wait_with_output().ok()?;
    (written && output.status.success())
        .then(|| String::from_utf8_lossy(&output.stdout).into_owned())
}

fn outside_renewal_margin(dates: &str, now: i64) -> bool {
    let Some((start, end)) = super::dev_certificates::validity_bounds(dates) else {
        return false;
    };
    end > start && now >= start && end - now >= (end - start) / 6
}

pub struct InspectPublicRoute;
impl Command for InspectPublicRoute {
    const NAME: &'static str = "doctor.inspect-public-route";
    const SUMMARY: &'static str =
        "Inspect a public Route's live site, forwarding and public certificate.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Observed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Observed, Error> {
        inspect(&input, Path::new("/etc/caddy/Caddyfile"), &mut Native)
    }
}

#[cfg(test)]
#[path = "doctor_public_route_tests.rs"]
mod tests;
