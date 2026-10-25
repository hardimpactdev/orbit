//! Read-only private Route projection checks. No application code or HTTP request runs.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::Ipv4Addr,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum InspectionError {
        Invalid {} => "doctor.projection_invalid", "The private Route inspection input is invalid.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProjectionInput {
    domain: String,
    certificates: PathBuf,
    projection: Projection,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
enum Projection {
    Workload {
        dns_address: Option<Ipv4Addr>,
        laravel: Option<Laravel>,
    },
    Router {
        upstreams: Vec<String>,
    },
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case", deny_unknown_fields)]
enum Laravel {
    Skip {},
    Check { environment: PathBuf, url: String },
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct Observed {
    caddy: bool,
    tls: bool,
    firewall: bool,
    dns: Option<bool>,
    laravel: Option<bool>,
    pool: Option<bool>,
}
trait Runtime {
    fn dns(&mut self, domain: &str) -> Option<Ipv4Addr>;
    fn firewall(&mut self) -> bool;
}
struct Native;
impl Runtime for Native {
    fn dns(&mut self, domain: &str) -> Option<Ipv4Addr> {
        let result = Process::new("/usr/bin/getent")
            .args(["ahostsv4", domain])
            .stdin(Stdio::null())
            .output()
            .ok()?;
        if !result.status.success() {
            return None;
        }
        String::from_utf8_lossy(&result.stdout)
            .split_whitespace()
            .next()?
            .parse()
            .ok()
    }
    fn firewall(&mut self) -> bool {
        Process::new("/usr/sbin/ufw")
            .arg("status")
            .stdin(Stdio::null())
            .output()
            .is_ok_and(|result| {
                result.status.success()
                    && String::from_utf8_lossy(&result.stdout)
                        .lines()
                        .any(|line| line.starts_with("Status: active"))
            })
    }
}
pub(super) fn absolute(path: &Path) -> bool {
    path.is_absolute()
        && !path
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
}
pub(super) fn valid_domain(domain: &str) -> bool {
    !domain.is_empty()
        && domain.len() <= 253
        && domain.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && label.as_bytes()[0].is_ascii_alphanumeric()
                && label.as_bytes()[label.len() - 1].is_ascii_alphanumeric()
                && label
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || b == b'-')
        })
}
fn validate(input: &ProjectionInput) -> Result<(), InspectionError> {
    let valid = valid_domain(&input.domain)
        && absolute(&input.certificates)
        && match &input.projection {
            Projection::Workload {
                laravel: Some(Laravel::Check { environment, url }),
                ..
            } => absolute(environment) && !url.is_empty() && !url.contains(['\n', '\r', '\0']),
            Projection::Router { upstreams } => {
                upstreams.len() <= 1024
                    && upstreams.iter().all(|upstream| {
                        !upstream.is_empty()
                            && upstream.len() <= 512
                            && !upstream.contains(['\n', '\r', '\0'])
                    })
            }
            _ => true,
        };
    if valid {
        Ok(())
    } else {
        Err(InspectionError::Invalid {})
    }
}
fn url_matches(environment: &Path, url: &str) -> bool {
    if !fs::symlink_metadata(environment).is_ok_and(|metadata| metadata.is_file()) {
        return false;
    }
    let Ok(bytes) = fs::read(environment) else {
        return false;
    };
    let entries = super::laravel::entries(&bytes, "APP_URL");
    entries.len() == 1 && super::laravel::plain(&bytes, &entries, "APP_URL") == url.as_bytes()
}
fn inspect(
    input: &ProjectionInput,
    live: &Path,
    runtime: &mut impl Runtime,
) -> Result<Observed, InspectionError> {
    validate(input)?;
    let content = fs::read_to_string(live).unwrap_or_default();
    let domain = content.contains(&input.domain);
    let mut result = Observed {
        caddy: domain,
        tls: input.certificates.join("cert.pem").is_file()
            && input.certificates.join("key.pem").is_file(),
        firewall: runtime.firewall(),
        dns: None,
        laravel: None,
        pool: None,
    };
    match &input.projection {
        Projection::Workload {
            dns_address,
            laravel,
        } => {
            result.dns = dns_address.map(|expected| runtime.dns(&input.domain) == Some(expected));
            result.laravel = laravel.as_ref().map(|check| match check {
                Laravel::Skip {} => true,
                Laravel::Check { environment, url } => url_matches(environment, url),
            });
        }
        Projection::Router { upstreams } => {
            let matches = domain && upstreams.iter().all(|upstream| content.contains(upstream));
            result.caddy = matches;
            result.pool = Some(matches);
        }
    }
    Ok(result)
}
pub struct InspectPrivateRoute;
impl Command for InspectPrivateRoute {
    const NAME: &'static str = "doctor.inspect-private-route";
    const SUMMARY: &'static str =
        "Compare private Route projections without running application code or repairing state.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ProjectionInput;
    type Output = Observed;
    type Error = InspectionError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        inspect(&input, Path::new("/etc/caddy/Caddyfile"), &mut Native)
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ApplicationUrlsInput {
    checkout: PathBuf,
    applications: Vec<ApplicationUrl>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
struct ApplicationUrl {
    directory: String,
    url: String,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct ApplicationUrls {
    matches: Vec<bool>,
}
fn application_urls(input: &ApplicationUrlsInput) -> Result<ApplicationUrls, InspectionError> {
    if !absolute(&input.checkout)
        || input.applications.iter().any(|app| {
            let relative = app.directory.is_empty()
                || app.directory.split('/').all(|part| {
                    !part.is_empty() && part != "." && part != ".." && !part.contains(['\0', '\\'])
                });
            !relative || app.url.is_empty() || app.url.contains(['\n', '\r', '\0'])
        })
    {
        return Err(InspectionError::Invalid {});
    }
    Ok(ApplicationUrls {
        matches: input
            .applications
            .iter()
            .map(|app| {
                let root = input.checkout.join(&app.directory);
                !fs::symlink_metadata(root.join("artisan")).is_ok_and(|metadata| metadata.is_file())
                    || url_matches(&root.join(".env"), &app.url)
            })
            .collect(),
    })
}
pub struct InspectApplicationUrls;
impl Command for InspectApplicationUrls {
    const NAME: &'static str = "doctor.inspect-application-urls";
    const SUMMARY: &'static str =
        "Check Laravel URLs in the application directories served by an Instance's Routes.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ApplicationUrlsInput;
    type Output = ApplicationUrls;
    type Error = InspectionError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        application_urls(&input)
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProxyInput {
    domain: String,
    certificates: PathBuf,
    #[schemars(extend("format" = "socket-address"))]
    upstream: std::net::SocketAddr,
    dns_address: Option<Ipv4Addr>,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct ProxyObserved {
    caddy: bool,
    tls: bool,
    dns: Option<bool>,
    upstream: bool,
}
fn reachable(address: std::net::SocketAddr) -> bool {
    std::net::TcpStream::connect_timeout(&address, Duration::from_secs(1)).is_ok()
}
fn inspect_proxy(
    input: &ProxyInput,
    live: &Path,
    runtime: &mut impl Runtime,
) -> Result<ProxyObserved, InspectionError> {
    if !valid_domain(&input.domain)
        || !absolute(&input.certificates)
        || !input.upstream.ip().is_loopback()
        || input.upstream.port() == 0
    {
        return Err(InspectionError::Invalid {});
    }
    let content = fs::read_to_string(live).unwrap_or_default();
    Ok(ProxyObserved {
        caddy: content.contains(&input.domain) && content.contains(&input.upstream.to_string()),
        tls: input.certificates.join("cert.pem").is_file()
            && input.certificates.join("key.pem").is_file(),
        dns: input
            .dns_address
            .map(|expected| runtime.dns(&input.domain) == Some(expected)),
        upstream: reachable(input.upstream),
    })
}
pub struct InspectCustomProxy;
impl Command for InspectCustomProxy {
    const NAME: &'static str = "doctor.inspect-custom-proxy";
    const SUMMARY: &'static str =
        "Inspect a custom proxy's live site, certificate files, DNS and loopback reachability.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ProxyInput;
    type Output = ProxyObserved;
    type Error = InspectionError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        inspect_proxy(&input, Path::new("/etc/caddy/Caddyfile"), &mut Native)
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct DnsInput {
    domain: String,
    address: Ipv4Addr,
}
#[derive(schemars::JsonSchema, Debug, PartialEq, Serialize)]
pub struct DnsObserved {
    matches: bool,
}
fn resolve_dns(
    input: &DnsInput,
    runtime: &mut impl Runtime,
) -> Result<DnsObserved, InspectionError> {
    if !valid_domain(&input.domain) {
        return Err(InspectionError::Invalid {});
    }
    Ok(DnsObserved {
        matches: runtime.dns(&input.domain) == Some(input.address),
    })
}
pub struct ResolveRouteDns;
impl Command for ResolveRouteDns {
    const NAME: &'static str = "doctor.resolve-route-dns";
    const SUMMARY: &'static str =
        "Compare the first system IPv4 DNS answer for a Route with its expected address.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = DnsInput;
    type Output = DnsObserved;
    type Error = InspectionError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        resolve_dns(&input, &mut Native)
    }
}

#[cfg(test)]
#[path = "doctor_route_tests.rs"]
mod tests;
