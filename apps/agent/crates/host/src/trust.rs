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
    /// No certificate under `name`.
    Absent { name: String },
}

/// The bundle `update-ca-certificates` builds from every trusted certificate: the effective trust, part of the host.
const BUNDLE: &str = "/etc/ssl/certs/ca-certificates.crt";

/// Places or removes the certificate and rebuilds the trust store when the store or the effective trust differs. A placed
/// certificate is trusted only when the bundle holds it and both its name link and its subject-hash link reach the placed
/// file; a removed one only when the bundle, rebuilt from the remaining sources, no longer holds it. Answers `1` on a
/// change and `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for a name outside `<name>.crt` or an owner outside its shape, `host.content_missing` for a blob
/// the executor did not read, and `trust.ensure_failed` for content that is not exactly one PEM certificate openssl reads,
/// a failed write or rebuild, or trust that does not match after the rebuild.
pub fn trust_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let name = match &input {
        Input::Present { name, .. } | Input::Absent { name } => name,
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
    let link = system.path(&format!("{TRUSTED}{stem}.pem"));
    let bundle = system.path(BUNDLE);
    let reaches = |path: &std::path::Path| std::fs::canonicalize(path).is_ok_and(|target| std::fs::canonicalize(&dest).is_ok_and(|placed| placed == target));
    let bundled = |body: &str| std::fs::read_to_string(&bundle).is_ok_and(|text| text.split_whitespace().collect::<String>().contains(body));
    let rebuild = || system.run(60, &strings(&["/usr/sbin/update-ca-certificates", "--fresh"])).map_err(failed);
    match &input {
        Input::Present { certificate, owner, .. } => {
            let bytes = certificate.bytes(system)?;
            let body = one_certificate(&bytes).ok_or_else(|| failed(format!("{name} is not exactly one PEM certificate")))?;
            let ids = owner.ids(system, "trust.ensure_failed")?;
            // openssl reads a candidate this run creates, before anything goes in place, and names the subject's hash link.
            let (candidate, mut stream) = fs::scratch(&dest, "candidate", |path| {
                std::fs::File::options().write(true).create_new(true).custom_flags(fs::NOFOLLOW).mode(0o600).open(path)
            })
            .map_err(|error| failed(error.to_string()))?;
            let written = stream.write_all(&bytes).and_then(|()| stream.sync_all());
            let hash = written
                .map_err(|error| failed(error.to_string()))
                .and_then(|()| system.run(30, &strings(&["/usr/bin/openssl", "x509", "-noout", "-subject_hash", "-in", &candidate.to_string_lossy()])).map_err(failed));
            let _ = std::fs::remove_file(&candidate);
            let hash = hash?.trim().to_owned();
            if hash.len() != 8 || !hash.chars().all(|c| c.is_ascii_hexdigit()) {
                return Err(failed(format!("{name}: openssl gave no subject hash")));
            }
            let hashed = || (0..10).any(|index| reaches(&system.path(&format!("{TRUSTED}{hash}.{index}"))));
            let trusted = || reaches(&link) && hashed() && bundled(&body);
            let mut changed = fs::ensure(&dest, &bytes, ids).map_err(|error| failed(error.to_string()))?;
            if changed || !trusted() {
                rebuild()?;
                changed = true;
            }
            if !trusted() {
                return Err(failed(format!("{name} trust read-back")));
            }
            Ok(u32::from(changed))
        }
        Input::Absent { .. } => {
            // The store file's certificate, while it is there, is what the bundle must no longer hold.
            let held = fs::read(&dest).map_err(|error| failed(error.to_string()))?;
            let body = held.as_ref().and_then(|(bytes, _)| one_certificate(bytes));
            let removed = match std::fs::remove_file(&dest) {
                Ok(()) => true,
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => false,
                Err(error) => return Err(failed(error.to_string())),
            };
            // A removal that stopped before its rebuild left the bundle stale with no file to show it: the bundle is always
            // rebuilt from the remaining sources, and a change is a removed file or a bundle that differs.
            let before = std::fs::read(&bundle).ok();
            rebuild()?;
            let changed = removed || std::fs::read(&bundle).ok() != before;
            if std::fs::symlink_metadata(&link).is_ok() || body.is_some_and(|body| bundled(&body)) {
                return Err(failed(format!("{name} trust read-back")));
            }
            Ok(u32::from(changed))
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
