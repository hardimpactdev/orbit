//! `ca.trust.ensure`: one certificate in the host's trust store. Lifted from the root trust step of `route.publish_site`;
//! the file's name comes from input, and only the store's own folder is fixed here.

use serde::Deserialize;

use crate::fs;
use crate::{invalid, parse, strings, BlobRef, Failure, Ids, System};

/// The folder `update-ca-certificates` reads local certificates from: part of the host, not of Orbit.
const STORE: &str = "/usr/local/share/ca-certificates/";

#[derive(Debug, Deserialize)]
#[serde(tag = "state", rename_all = "snake_case", deny_unknown_fields)]
enum Input {
    /// The certificate under `name` in the store.
    Present { name: String, certificate: BlobRef },
    /// No certificate under `name`.
    Absent { name: String },
}

/// Places or removes the certificate, root-owned and mode 0644, and rebuilds the trust bundle only when it changed.
/// Answers `1` on a change and `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for a name outside `<name>.crt`, `host.content_missing` for a blob the executor did not read, and
/// `trust.ensure_failed` for anything that is not a PEM certificate or a failed write or rebuild.
pub fn trust_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    let name = match &input {
        Input::Present { name, .. } | Input::Absent { name } => name,
    };
    let stem = name.strip_suffix(".crt").unwrap_or_default();
    if stem.is_empty() || !stem.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.')) || stem.starts_with('.') {
        return Err(invalid("name"));
    }
    let failed = |detail: String| Failure::new("trust.ensure_failed", detail);
    let dest = system.path(&format!("{STORE}{name}"));
    let changed = match &input {
        Input::Present { certificate, .. } => {
            let bytes = certificate.bytes(system)?;
            if !bytes.starts_with(b"-----BEGIN CERTIFICATE-----") {
                return Err(failed("not a PEM certificate".to_owned()));
            }
            fs::ensure(&dest, &bytes, Ids { uid: 0, gid: 0, mode: 0o644 }).map_err(|error| failed(error.to_string()))?
        }
        Input::Absent { .. } => match std::fs::remove_file(&dest) {
            Ok(()) => true,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => false,
            Err(error) => return Err(failed(error.to_string())),
        },
    };
    if changed {
        let mut argv = strings(&["/usr/sbin/update-ca-certificates"]);
        if matches!(input, Input::Absent { .. }) {
            argv.push("--fresh".to_owned());
        }
        system.run(60, &argv).map_err(failed)?;
    }
    Ok(u32::from(changed))
}
