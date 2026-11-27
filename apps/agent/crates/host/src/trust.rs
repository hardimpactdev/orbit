//! `ca.trust.ensure`: one certificate in the host's trust store. Lifted from the root trust step of `route.publish_site`;
//! the file's name comes from input, and only the store's own folder is fixed here.

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

/// Places or removes the certificate and rebuilds the trust bundle when the store or the effective trust differs: a
/// certificate is trusted only when the bundle's link for it reaches the placed file. Answers `1` on a change and `0` on
/// a repeat.
///
/// # Errors
///
/// `host.input_invalid` for a name outside `<name>.crt` or an owner outside its shape, `host.content_missing` for a blob
/// the executor did not read, and `trust.ensure_failed` for content openssl does not read as one certificate, a failed
/// write or rebuild, or trust that does not match after the rebuild.
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
    let trusted = || std::fs::canonicalize(&link).is_ok_and(|target| std::fs::canonicalize(&dest).is_ok_and(|placed| placed == target));
    let present = matches!(input, Input::Present { .. });
    let mut changed = match &input {
        Input::Present { certificate, owner, .. } => {
            let bytes = certificate.bytes(system)?;
            let ids = owner.ids(system, "trust.ensure_failed")?;
            // openssl reads the candidate before it goes in place.
            let candidate = dest.with_file_name(format!(".{name}.candidate"));
            fs::replace(&candidate, &bytes, ids).map_err(|error| failed(error.to_string()))?;
            let checked = system.run(30, &strings(&["/usr/bin/openssl", "x509", "-noout", "-in", &candidate.to_string_lossy()]));
            let placed = checked.map_err(failed).and_then(|_| fs::ensure(&dest, &bytes, ids).map_err(|error| failed(error.to_string())));
            let _ = std::fs::remove_file(&candidate);
            placed?
        }
        Input::Absent { .. } => match std::fs::remove_file(&dest) {
            Ok(()) => true,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => false,
            Err(error) => return Err(failed(error.to_string())),
        },
    };
    let linked = std::fs::symlink_metadata(&link).is_ok();
    if changed || (present && !trusted()) || (!present && linked) {
        let mut argv = strings(&["/usr/sbin/update-ca-certificates"]);
        if !present {
            argv.push("--fresh".to_owned());
        }
        system.run(60, &argv).map_err(failed)?;
        changed = true;
    }
    let held = if present { trusted() } else { std::fs::symlink_metadata(&link).is_err() };
    if !held {
        return Err(failed(format!("{name} trust read-back")));
    }
    Ok(u32::from(changed))
}
