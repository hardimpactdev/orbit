//! `ca.trust.ensure`: one certificate in the host's trust store. Lifted from the root trust step of `route.publish_site`;
//! the file's name comes from input, and only the store's own folder is fixed here.

use std::io::Write as _;
use std::os::unix::fs::OpenOptionsExt as _;

use serde::Deserialize;

use crate::fs;
use crate::{invalid, parse, strings, BlobRef, Failure, Owner, System};

/// The folder `update-ca-certificates` reads local certificates from: part of the host, not of Orbit.
const STORE: &str = "/usr/local/share/ca-certificates/";

/// Where `update-ca-certificates` links each certificate it trusts, as `<name>.pem`: the effective trust, part of the host.
const TRUSTED: &str = "/etc/ssl/certs/";

#[derive(Debug, Deserialize)]
#[serde(tag = "state", rename_all = "snake_case", deny_unknown_fields)]
enum Input {
    /// The certificate under `name` in the store, with its owner and mode.
    Present { name: String, certificate: BlobRef, owner: Owner },
    /// No certificate under `name`, and `certificate` trusted by no other path either.
    Absent { name: String, certificate: BlobRef },
}

/// The bundle `update-ca-certificates` builds from every trusted certificate: the effective trust, part of the host.
const BUNDLE: &str = "/etc/ssl/certs/ca-certificates.crt";

/// Places or removes the certificate, and runs `update-ca-certificates` only when this certificate's state changed or its
/// trust does not match. Trust is the certificate's identity: the bundle holds it, and a subject-hash link reaches a file
/// that holds it, whatever that file's name. Answers `1` on a change and `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for a name outside `<name>.crt` or an owner outside its shape, `host.content_missing` for a blob
/// the executor did not read, and `trust.ensure_failed` for content that is not exactly one PEM certificate openssl reads,
/// a failed write or update, or trust that does not match after the update.
pub fn trust_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let (name, certificate) = match &input {
        Input::Present { name, certificate, .. } | Input::Absent { name, certificate } => (name, certificate),
    };
    let stem = name.strip_suffix(".crt").unwrap_or_default();
    if stem.is_empty() || !stem.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.')) || stem.starts_with('.') {
        return Err(invalid("name"));
    }
    if let Input::Present { owner, .. } = &input {
        if !owner.valid() {
            return Err(invalid("owner"));
        }
    }
    let failed = |detail: String| Failure::new("trust.ensure_failed", detail);
    let dest = system.path(&format!("{STORE}{name}"));
    let bytes = certificate.bytes(system)?;
    let body = one_certificate(&bytes).ok_or_else(|| failed(format!("{name} is not exactly one PEM certificate")))?;
    // openssl reads a candidate this run creates, before anything changes, and names the subject's hash.
    let (candidate, mut stream) = fs::scratch(&dest, "candidate", |path| std::fs::File::options().write(true).create_new(true).custom_flags(fs::NOFOLLOW).mode(0o600).open(path))
        .map_err(|error| failed(error.to_string()))?;
    let hash = stream
        .write_all(&bytes)
        .and_then(|()| stream.sync_all())
        .map_err(|error| failed(error.to_string()))
        .and_then(|()| system.run(30, &strings(&["/usr/bin/openssl", "x509", "-noout", "-subject_hash", "-in", &candidate.to_string_lossy()])).map_err(failed));
    let _ = std::fs::remove_file(&candidate);
    let hash = hash?.trim().to_owned();
    if hash.len() != 8 || !hash.chars().all(|c| c.is_ascii_hexdigit()) {
        return Err(failed(format!("{name}: openssl gave no subject hash")));
    }
    let bundled = || std::fs::read_to_string(system.path(BUNDLE)).is_ok_and(|text| text.split_whitespace().collect::<String>().contains(&body));
    let hashed = || (0..10).any(|index| std::fs::read(system.path(&format!("{TRUSTED}{hash}.{index}"))).is_ok_and(|held| one_certificate(&held).as_deref() == Some(body.as_str())));
    let update = || system.run(60, &strings(&["/usr/sbin/update-ca-certificates"])).map_err(failed);
    match &input {
        Input::Present { owner, .. } => {
            let ids = owner.ids(system, "trust.ensure_failed")?;
            let trusted = || bundled() && hashed();
            let mut changed = fs::ensure(&dest, &bytes, ids).map_err(|error| failed(error.to_string()))?;
            if changed || !trusted() {
                update()?;
                changed = true;
            }
            if !trusted() {
                return Err(failed(format!("{name} trust read-back")));
            }
            Ok(u32::from(changed))
        }
        Input::Absent { .. } => {
            let removed = match std::fs::remove_file(&dest) {
                Ok(()) => true,
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => false,
                Err(error) => return Err(failed(error.to_string())),
            };
            // Already gone and trusted nowhere: nothing runs.
            let untrusted = || !bundled() && !hashed();
            if !removed && untrusted() {
                return Ok(0);
            }
            update()?;
            if !untrusted() {
                return Err(failed(format!("{name} is still trusted")));
            }
            Ok(1)
        }
    }
}

/// The base64 body of `bytes` when they are exactly one PEM certificate block, with nothing but blank space around it.
fn one_certificate(bytes: &[u8]) -> Option<String> {
    let text = std::str::from_utf8(bytes).ok()?.trim();
    let body = text.strip_prefix("-----BEGIN CERTIFICATE-----")?.strip_suffix("-----END CERTIFICATE-----")?;
    let body: String = body.split_whitespace().collect();
    (!body.is_empty() && body.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '+' | '/' | '='))).then_some(body)
}
