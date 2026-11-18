//! SPIKE: generic primitives, each on one kind of resource. Content never travels in an order: the order carries its
//! SHA-256; the bus has already read it from the NATS CONTENT bucket into `BLOBS`, and a primitive refuses any bytes
//! whose hash differs. Paths and units are
//! held to fixed allowlists; a real build would derive them from the Node's roles.

use std::fs;
use std::io::Write as _;
use std::os::unix::fs::{chown, symlink, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::process::Command;

use orbit_executor_core::journal::Outcome;
use serde::Deserialize;
use sha2::{Digest as _, Sha256};

pub(crate) const BLOBS: &str = "/var/lib/orbit/executor/blobs";
const TRUST: &str = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
const PATHS: [&str; 6] = ["/etc/caddy/", "/etc/dnsmasq.d/", "/var/lib/orbit/", "/data/caddy/", "/home/", "/srv/"];
const UNITS: [&str; 4] = ["dnsmasq", "orbit-private-dns.service", "caddy", "php8.5-fpm"];

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Blob {
    sha256: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Csr {
    directory: String,
    domain: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Put {
    path: String,
    blob: Blob,
    owner: String,
    group: String,
    mode: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Link {
    path: String,
    target: String,
    owner: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Install {
    sources: Vec<String>,
    directory: String,
    owner: String,
    group: String,
    mode: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Content {
    blob: Blob,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Units {
    units: Vec<String>,
}

fn parse<T: serde::de::DeserializeOwned>(input: &serde_json::Value) -> Result<T, Outcome> {
    serde_json::from_value(input.clone()).map_err(|_| failed("prim.input_invalid"))
}

/// Key and CSR in `directory`, exactly as the Swamp step makes them; the key never leaves the Node. Returns the CSR.
pub(crate) fn tls_csr(input: &serde_json::Value) -> Outcome {
    let csr: Csr = match parse(input) {
        Ok(csr) => csr,
        Err(outcome) => return outcome,
    };
    if !allowed(&csr.directory) || !csr.domain.bytes().all(|b| b.is_ascii_alphanumeric() || b".-".contains(&b)) {
        return failed("prim.input_invalid");
    }
    let script = "install -d -o root -g caddy -m 0750 -- \"$1\" && openssl req -quiet -new -newkey rsa:2048 -nodes \
        -keyout \"$1/key.pem\" -subj \"/CN=Orbit\" -addext \"subjectAltName=DNS:$2\" && chown root:caddy -- \"$1/key.pem\" \
        && chmod 0640 -- \"$1/key.pem\"";
    match Command::new("timeout").args(["30", "sh", "-eu", "-c", script, "sh", &csr.directory, &csr.domain]).output() {
        Ok(out) if out.status.success() => Outcome::Output {
            stdout: String::from_utf8_lossy(&out.stdout).into_owned(),
            encoding: "text".to_owned(),
        },
        _ => failed("tls.csr_failed"),
    }
}

/// Writes one file atomically with its owner and mode. `changes` is 0 when the file already matched.
pub(crate) fn file_put(input: &serde_json::Value) -> Outcome {
    let put: Put = match parse(input) {
        Ok(put) => put,
        Err(outcome) => return outcome,
    };
    let Some(mode) = mode(&put.mode) else { return failed("prim.input_invalid") };
    if !allowed(&put.path) {
        return failed("prim.path_refused");
    }
    let bytes = match fetch(&put.blob) {
        Ok(bytes) => bytes,
        Err(outcome) => return outcome,
    };
    match write(Path::new(&put.path), &bytes, &put.owner, &put.group, mode) {
        Ok(changed) => applied(changed),
        Err(code) => failed(code),
    }
}

/// Points a symlink at `target`, atomically, owned by `owner`.
pub(crate) fn file_link(input: &serde_json::Value) -> Outcome {
    let link: Link = match parse(input) {
        Ok(link) => link,
        Err(outcome) => return outcome,
    };
    let path = PathBuf::from(&link.path);
    if !allowed(&link.path) || (link.target.starts_with('/') && !allowed(&link.target)) || link.target.contains("..") {
        return failed("prim.path_refused");
    }
    let Some((uid, _)) = ids(&link.owner, None) else { return failed("prim.owner_unknown") };
    if fs::read_link(&path).is_ok_and(|current| current == Path::new(&link.target)) {
        return applied(false);
    }
    let temporary = path.with_file_name(format!(".orbit-link-{}", std::process::id()));
    let _ = fs::remove_file(&temporary);
    let linked = symlink(&link.target, &temporary)
        .and_then(|()| std::os::unix::fs::lchown(&temporary, Some(uid), None))
        .and_then(|()| fs::rename(&temporary, &path));
    match linked {
        Ok(()) => applied(true),
        Err(_) => {
            let _ = fs::remove_file(&temporary);
            failed("file.link_failed")
        }
    }
}

/// Copies files into a directory with owner and mode (`install -D -t`).
pub(crate) fn file_install(input: &serde_json::Value) -> Outcome {
    let install: Install = match parse(input) {
        Ok(install) => install,
        Err(outcome) => return outcome,
    };
    if mode(&install.mode).is_none() || !allowed(&install.directory) || !install.sources.iter().all(|s| allowed(s)) {
        return failed("prim.path_refused");
    }
    let status = Command::new("timeout")
        .args(["30", "install", "-D", "-o", &install.owner, "-g", &install.group, "-m", &install.mode, "-t", &install.directory])
        .args(&install.sources)
        .status();
    if status.is_ok_and(|s| s.success()) { applied(true) } else { failed("file.install_failed") }
}

/// Installs the Orbit root CA and refreshes the trust store only when it changed.
pub(crate) fn ca_trust(input: &serde_json::Value) -> Outcome {
    let content: Content = match parse(input) {
        Ok(content) => content,
        Err(outcome) => return outcome,
    };
    let bytes = match fetch(&content.blob) {
        Ok(bytes) => bytes,
        Err(outcome) => return outcome,
    };
    match write(Path::new(TRUST), &bytes, "root", "root", 0o644) {
        Ok(false) => applied(false),
        Ok(true) if run(&["timeout", "60", "update-ca-certificates"]) => applied(true),
        _ => failed("ca.trust_failed"),
    }
}

/// Desired state of the Node's Caddyfile: candidate, `caddy validate` as caddy, swap, reload. A config that does not
/// validate changes nothing.
pub(crate) fn caddy_config(input: &serde_json::Value) -> Outcome {
    let content: Content = match parse(input) {
        Ok(content) => content,
        Err(outcome) => return outcome,
    };
    let bytes = match fetch(&content.blob) {
        Ok(bytes) => bytes,
        Err(outcome) => return outcome,
    };
    let live = Path::new("/etc/caddy/Caddyfile");
    if fs::read(live).is_ok_and(|current| current == bytes) {
        return applied(false);
    }
    let candidate = PathBuf::from(format!("/etc/caddy/.orbit-candidate-{}", std::process::id()));
    if write(&candidate, &bytes, "root", "caddy", 0o640).is_err() {
        return failed("caddy.write_failed");
    }
    let path = candidate.to_string_lossy().into_owned();
    if !run(&["timeout", "60", "runuser", "-u", "caddy", "--", "caddy", "validate", "--config", &path, "--adapter", "caddyfile"]) {
        let _ = fs::remove_file(&candidate);
        return failed("caddy.validate_failed");
    }
    if fs::rename(&candidate, live).is_err() {
        let _ = fs::remove_file(&candidate);
        return failed("caddy.write_failed");
    }
    if run(&["timeout", "60", "systemctl", "reload", "caddy"]) { applied(true) } else { failed("caddy.reload_failed") }
}

/// Restarts allowlisted units, in order.
pub(crate) fn systemd_restart(input: &serde_json::Value) -> Outcome {
    let units: Units = match parse(input) {
        Ok(units) => units,
        Err(outcome) => return outcome,
    };
    if units.units.is_empty() || !units.units.iter().all(|u| UNITS.contains(&u.as_str())) {
        return failed("prim.unit_refused");
    }
    // Restarts of the same units are serialised and merged: a restart that started after this request arrived already
    // covers the change that came before the request, so a burst of Routes restarts DNS once or twice, not once per
    // Route (systemd refuses more than 5 starts in 10 s).
    static LOCK: std::sync::Mutex<()> = std::sync::Mutex::new(());
    static LAST: std::sync::LazyLock<std::sync::Mutex<std::collections::HashMap<String, std::time::Instant>>> =
        std::sync::LazyLock::new(Default::default);
    let requested = std::time::Instant::now();
    let key = units.units.join(",");
    let _serial = LOCK.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    if LAST.lock().unwrap_or_else(std::sync::PoisonError::into_inner).get(&key).is_some_and(|started| *started >= requested) {
        return applied(false);
    }
    let started = std::time::Instant::now();
    let mut reset = vec!["timeout", "30", "systemctl", "reset-failed"];
    reset.extend(units.units.iter().map(String::as_str));
    let _ = run(&reset);
    let mut argv = vec!["timeout", "60", "systemctl", "restart"];
    argv.extend(units.units.iter().map(String::as_str));
    if !run(&argv) {
        return failed("systemd.restart_failed");
    }
    LAST.lock().unwrap_or_else(std::sync::PoisonError::into_inner).insert(key, started);
    applied(true)
}

fn fetch(blob: &Blob) -> Result<Vec<u8>, Outcome> {
    if !valid_sha(&blob.sha256) {
        return Err(failed("prim.input_invalid"));
    }
    let bytes = fs::read(Path::new(BLOBS).join(&blob.sha256)).map_err(|_| failed("blob.missing"))?;
    if sha_hex(&bytes) != blob.sha256 {
        return Err(failed("blob.hash_mismatch"));
    }
    Ok(bytes)
}

pub(crate) fn valid_sha(sha: &str) -> bool {
    sha.len() == 64 && sha.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

pub(crate) fn sha_hex(bytes: &[u8]) -> String {
    Sha256::digest(bytes).iter().map(|b| format!("{b:02x}")).collect()
}

/// Atomic write through a temporary file beside the target; returns whether anything changed.
fn write(path: &Path, bytes: &[u8], owner: &str, group: &str, mode: u32) -> Result<bool, &'static str> {
    let (uid, gid) = ids(owner, Some(group)).ok_or("prim.owner_unknown")?;
    if let Ok(meta) = fs::symlink_metadata(path) {
        use std::os::unix::fs::MetadataExt as _;
        if meta.file_type().is_file() && fs::read(path).is_ok_and(|current| current == bytes)
            && (meta.uid(), meta.gid(), meta.mode() & 0o7777) == (uid, gid.unwrap_or(meta.gid()), mode)
        {
            return Ok(false);
        }
    }
    let parent = path.parent().ok_or("prim.path_refused")?;
    let temporary = parent.join(format!(".orbit-put-{}-{}", std::process::id(), path.file_name().map_or_else(String::new, |n| n.to_string_lossy().into_owned())));
    let written = (|| -> std::io::Result<()> {
        let mut file = fs::File::create(&temporary)?;
        file.write_all(bytes)?;
        file.sync_all()?;
        chown(&temporary, Some(uid), gid)?;
        fs::set_permissions(&temporary, fs::Permissions::from_mode(mode))?;
        fs::rename(&temporary, path)
    })();
    written.map(|()| true).map_err(|_| {
        let _ = fs::remove_file(&temporary);
        "file.write_failed"
    })
}

/// The uid of `owner` and, when given, the gid of `group`, from the Node's own account database.
fn ids(owner: &str, group: Option<&str>) -> Option<(u32, Option<u32>)> {
    let lookup = |database: &str, name: &str| -> Option<u32> {
        if !name.bytes().all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b)) {
            return None;
        }
        let out = Command::new("getent").args([database, name]).output().ok()?;
        String::from_utf8_lossy(&out.stdout).split(':').nth(2)?.trim().parse().ok()
    };
    let uid = lookup("passwd", owner)?;
    let gid = match group {
        Some(group) => Some(lookup("group", group)?),
        None => None,
    };
    Some((uid, gid))
}

fn mode(text: &str) -> Option<u32> {
    (text.len() == 4 && text.bytes().all(|b| (b'0'..=b'7').contains(&b))).then(|| u32::from_str_radix(text, 8).ok()).flatten()
}

/// A Route's certificate scope folder, gone: `/etc/caddy/orbit-certificates/<scope>` or `/home/<user>/.orbit/certificates/<scope>`.
pub(crate) fn remove_tree(input: &serde_json::Value) -> Outcome {
    let Some(path) = input.get("path").and_then(serde_json::Value::as_str) else { return failed("prim.input_invalid") };
    let scope = |name: &str| !name.is_empty() && name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-');
    let parts: Vec<&str> = path.split('/').collect();
    let shaped = match parts.as_slice() {
        ["", "etc", "caddy", "orbit-certificates", name] => scope(name),
        ["", "home", user, ".orbit", "certificates", name] => scope(user) && scope(name),
        _ => false,
    };
    if !shaped || path.contains("..") {
        return failed("prim.path_refused");
    }
    match fs::symlink_metadata(path) {
        Err(_) => applied(false),
        Ok(meta) if meta.is_dir() => match fs::remove_dir_all(path) {
            Ok(()) => applied(true),
            Err(_) => failed("prim.remove_failed"),
        },
        Ok(_) => failed("prim.path_refused"),
    }
}

fn allowed(path: &str) -> bool {
    !path.contains("..") && PATHS.iter().any(|prefix| path.starts_with(prefix))
}

fn run(argv: &[&str]) -> bool {
    argv.split_first().is_some_and(|(program, args)| Command::new(program).args(args).status().is_ok_and(|s| s.success()))
}

fn applied(changed: bool) -> Outcome {
    Outcome::Applied { changes: u32::from(changed) }
}

fn failed(code: &str) -> Outcome {
    Outcome::Failed { code: code.to_owned() }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn refuses_paths_units_and_bad_blobs() {
        assert!(!allowed("/etc/passwd"));
        assert!(!allowed("/etc/caddy/../passwd"));
        assert!(allowed("/etc/caddy/Caddyfile"));
        assert!(matches!(systemd_restart(&serde_json::json!({"units": ["ssh"]})), Outcome::Failed { .. }));
        assert!(matches!(
            file_put(&serde_json::json!({"path": "/etc/shadow", "blob": {"sha256": "0".repeat(64)},
                "owner": "root", "group": "root", "mode": "0644"})),
            Outcome::Failed { .. }
        ));
        assert!(matches!(ca_trust(&serde_json::json!({"blob": {"sha256": "abc"}})), Outcome::Failed { .. }));
        assert_eq!(mode("0640"), Some(0o640));
        assert_eq!(mode("640"), None);
    }
}
