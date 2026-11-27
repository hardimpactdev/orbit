//! `tls.ensure_csr`: the leaf key of one certificate version, and a request for it.

use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::{strings, Failure, System};

static NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[a-z_][a-z0-9_-]{0,31}$").expect("a valid pattern"));
static MODE: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^0?[0-7]{3}$").expect("a valid pattern"));
/// A certificate subject in `openssl -subj` form: `/CN=…` with plain characters only.
static SUBJECT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^(/[A-Za-z]{1,16}=[A-Za-z0-9 ._-]{1,64}){1,8}$").expect("a valid pattern"));
static DOMAIN: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    /// The version folder, made once with its owner and mode.
    directory: String,
    user: String,
    group: String,
    directory_mode: String,
    key_mode: String,
    /// The request's subject, such as `/CN=Orbit`.
    subject: String,
    domain: String,
}

/// Makes the version folder and its RSA 2048 key once, each with the owner and mode the Gateway names, then answers a
/// request for that key, with the Gateway's subject and the one subject name the Gateway signs. A repeat finds the key and answers a request for
/// the same key, so a re-sent order never replaces a key that a signed certificate may already match. The key never
/// leaves the Node.
///
/// # Errors
///
/// `route.input_invalid` for an input outside the admitted shape, and `app-dev.certificate_request_failed` when the folder,
/// key or request cannot be made.
pub fn ensure_csr(system: &impl System, input: &serde_json::Value) -> Result<String, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    let directory = &input.directory;
    let absolute = directory.starts_with('/') && !directory.split('/').any(|part| part == "..") && !directory.contains(['\n', '\0']);
    let shape = NAME.is_match(&input.user)
        && NAME.is_match(&input.group)
        && MODE.is_match(&input.directory_mode)
        && MODE.is_match(&input.key_mode)
        && SUBJECT.is_match(&input.subject);
    if !absolute || !shape || !DOMAIN.is_match(&input.domain) {
        return Err(Failure::new("route.input_invalid", "directory, owner, mode, subject or domain"));
    }
    let failed = |detail: String| Failure::new("app-dev.certificate_request_failed", detail);
    system
        .run(30, &strings(&["/usr/bin/install", "-d", "-o", &input.user, "-g", &input.group, "-m", &input.directory_mode, directory]))
        .map_err(failed)?;
    let key = format!("{directory}/key.pem");
    let name = format!("subjectAltName=DNS:{}", input.domain);
    if !system.path(&key).exists() {
        new_key(system, &input, &key).map_err(failed)?;
    }
    let answer = if let Ok(answer) = request(system, &key, &input.subject, &name) {
        answer
    } else {
        // A key that openssl cannot read matches no signed certificate: it is replaced once.
        new_key(system, &input, &key).map_err(failed)?;
        request(system, &key, &input.subject, &name).map_err(failed)?
    };
    if !answer.contains("-----BEGIN CERTIFICATE REQUEST-----") {
        return Err(failed("openssl gave no request".to_owned()));
    }
    Ok(answer)
}

/// An RSA 2048 key, written beside its final name and moved in place, so a stopped run never leaves half a key.
fn new_key(system: &impl System, input: &Input, key: &str) -> Result<(), String> {
    let partial = format!("{}/key.pem.partial", input.directory);
    let mut argv = strings(&["/usr/bin/openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out"]);
    argv.push(partial.clone());
    system.run(60, &argv)?;
    system.run(10, &strings(&["/usr/bin/chown", &format!("{}:{}", input.user, input.group), &partial]))?;
    system.run(10, &strings(&["/usr/bin/chmod", &input.key_mode, &partial]))?;
    std::fs::rename(system.path(&partial), system.path(key)).map_err(|error| error.to_string())
}

/// A request for `key`: the subject and the one subject name.
fn request(system: &impl System, key: &str, subject: &str, name: &str) -> Result<String, String> {
    let mut argv = strings(&["/usr/bin/openssl", "req", "-new", "-key", key, "-subj", subject, "-addext"]);
    argv.push(name.to_owned());
    system.run(30, &argv)
}
