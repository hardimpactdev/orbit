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

/// Places or removes the certificate, runs the plain `update-ca-certificates` after a change (never `--fresh`, which would
/// rebuild every consumer's links), and verifies the effect with `openssl verify -CAfile <bundle>`: trusted, with a
/// subject-hash link to a file that holds it, for `present`; not trusted for `absent`. Answers `1` on a change and `0` on
/// a repeat.
///
/// # Errors
///
/// `host.input_invalid` for a name outside `<name>.crt` or an owner outside its shape, `host.content_missing` for a blob
/// the executor did not read, `trust.source_mismatch` when `absent` finds other bytes under the name (nothing changes),
/// `trust.not_converged` when the update leaves the trust other than asked, and `trust.ensure_failed` for content that is
/// not exactly one PEM certificate openssl reads, or a failed write or update.
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
    // openssl reads a copy this run creates, before anything changes: it names the subject's hash and is what `verify` checks.
    let (candidate, mut stream) = fs::scratch(&dest, "candidate", |path| std::fs::File::options().write(true).create_new(true).custom_flags(fs::NOFOLLOW).mode(0o600).open(path))
        .map_err(|error| failed(error.to_string()))?;
    let result = stream.write_all(&bytes).and_then(|()| stream.sync_all()).map_err(|error| failed(error.to_string())).and_then(|()| converge(system, &input, &dest, &candidate, &bytes, &body));
    let _ = std::fs::remove_file(&candidate);
    result
}

fn converge(system: &impl System, input: &Input, dest: &std::path::Path, candidate: &std::path::Path, bytes: &[u8], body: &str) -> Result<u32, Failure> {
    let failed = |detail: String| Failure::new("trust.ensure_failed", detail);
    let unconverged = |detail: &str| Failure::new("trust.not_converged", detail.to_owned());
    let candidate = candidate.to_string_lossy();
    let hash = system.run(30, &strings(&["/usr/bin/openssl", "x509", "-noout", "-subject_hash", "-in", &candidate])).map_err(failed)?.trim().to_owned();
    if hash.len() != 8 || !hash.chars().all(|c| c.is_ascii_hexdigit()) {
        return Err(failed("openssl gave no subject hash".to_owned()));
    }
    let bundle = system.path(BUNDLE).to_string_lossy().into_owned();
    let trusted = || system.run(30, &strings(&["/usr/bin/openssl", "verify", "-CAfile", &bundle, &candidate])).is_ok_and(|out| out.trim_end().ends_with(": OK"));
    let hashed = || (0..10).any(|index| std::fs::read(system.path(&format!("{TRUSTED}{hash}.{index}"))).is_ok_and(|held| one_certificate(&held).as_deref() == Some(body)));
    let update = || system.run(60, &strings(&["/usr/sbin/update-ca-certificates"])).map_err(failed);
    match input {
        Input::Present { owner, .. } => {
            let ids = owner.ids(system, "trust.ensure_failed")?;
            let changed = fs::ensure(dest, bytes, ids).map_err(|error| failed(error.to_string()))?;
            let held = || trusted() && hashed();
            if !changed && held() {
                return Ok(0);
            }
            update()?;
            if !held() {
                return Err(unconverged("the update left the certificate untrusted or without its subject-hash link"));
            }
            Ok(1)
        }
        Input::Absent { .. } => {
            // Only the source that holds this certificate goes; other bytes under the name are someone else's.
            let removed = match fs::read(dest).map_err(|error| failed(error.to_string()))? {
                Some((held, _)) if held != bytes => return Err(Failure::new("trust.source_mismatch", format!("{} holds another certificate", dest.display()))),
                Some(_) => {
                    std::fs::remove_file(dest).map_err(|error| failed(error.to_string()))?;
                    true
                }
                None => false,
            };
            if !removed && !trusted() {
                return Ok(0);
            }
            update()?;
            if trusted() {
                return Err(unconverged("the update left the certificate trusted"));
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
