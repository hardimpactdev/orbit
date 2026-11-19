//! `tls.ensure_csr`: the leaf key of one certificate version, and a request for it.

use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::{strings, Failure, System};

/// `/etc/caddy/orbit-certificates/<scope>/versions/<16 hex>`: the version folder the Gateway admitted.
static DIRECTORY: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^/etc/caddy/orbit-certificates/[A-Za-z0-9][A-Za-z0-9._-]*/versions/[0-9a-f]{16}$").expect("a valid pattern")
});
static DOMAIN: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    directory: String,
    domain: String,
}

/// Makes the version folder (root:caddy 0750) and its RSA 2048 key (root:caddy 0640) once, then answers a request for
/// that key, with CN `Orbit` and the one subject name the Gateway signs. A repeat finds the key and answers a request for
/// the same key, so a re-sent order never replaces a key that a signed certificate may already match. The key never
/// leaves the Node.
///
/// # Errors
///
/// `route.input_invalid` for an input outside the admitted shape, and `app-dev.certificate_request_failed` when the folder,
/// key or request cannot be made.
pub fn ensure_csr(system: &impl System, input: &serde_json::Value) -> Result<String, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    if !DIRECTORY.is_match(&input.directory) || !DOMAIN.is_match(&input.domain) {
        return Err(Failure::new("route.input_invalid", "directory or domain"));
    }
    let failed = |detail: String| Failure::new("app-dev.certificate_request_failed", detail);
    let directory = &input.directory;
    system.run(30, &strings(&["/usr/bin/install", "-d", "-o", "root", "-g", "caddy", "-m", "0750", directory])).map_err(failed)?;
    let key = format!("{directory}/key.pem");
    let name = format!("subjectAltName=DNS:{}", input.domain);
    if !system.path(&key).exists() {
        new_key(system, directory, &key).map_err(failed)?;
    }
    let answer = if let Ok(answer) = request(system, &key, &name) {
        answer
    } else {
        // A key that openssl cannot read matches no signed certificate: it is replaced once.
        new_key(system, directory, &key).map_err(failed)?;
        request(system, &key, &name).map_err(failed)?
    };
    if !answer.contains("-----BEGIN CERTIFICATE REQUEST-----") {
        return Err(failed("openssl gave no request".to_owned()));
    }
    Ok(answer)
}

/// An RSA 2048 key, written beside its final name and moved in place, so a stopped run never leaves half a key.
fn new_key(system: &impl System, directory: &str, key: &str) -> Result<(), String> {
    let partial = format!("{directory}/key.pem.partial");
    let mut argv = strings(&["/usr/bin/openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out"]);
    argv.push(partial.clone());
    system.run(60, &argv)?;
    system.run(10, &strings(&["/usr/bin/chown", "root:caddy", &partial]))?;
    system.run(10, &strings(&["/usr/bin/chmod", "0640", &partial]))?;
    std::fs::rename(system.path(&partial), system.path(key)).map_err(|error| error.to_string())
}

/// A request for `key`: CN `Orbit` and the one subject name.
fn request(system: &impl System, key: &str, name: &str) -> Result<String, String> {
    let mut argv = strings(&["/usr/bin/openssl", "req", "-new", "-key", key, "-subj", "/CN=Orbit", "-addext"]);
    argv.push(name.to_owned());
    system.run(30, &argv)
}
