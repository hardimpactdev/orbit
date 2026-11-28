//! `tls.ensure_csr`: the leaf key of one certificate version, and a request for it.

use std::fs::{self, File};
use std::io::{Read as _, Write as _};
use std::os::unix::fs::{fchown, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::Path;
use std::sync::LazyLock;

use regex::Regex;

/// `O_NOFOLLOW`: a link in the key's place is never followed.
const NOFOLLOW: i32 = 0o400_000;
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
    /// The key's path, kept across requests.
    key: String,
    user: String,
    group: String,
    directory_mode: String,
    key_mode: String,
    /// The request's subject, such as `/CN=Orbit`.
    subject: String,
    domain: String,
}

/// Makes the version folder and its RSA 2048 key once, each with the owner and mode the Gateway names, then answers a
/// request for that key, with the Gateway's subject and the one subject name the Gateway signs. A repeat finds the key,
/// puts its owner and mode right without touching its bytes, and answers a request for the same key, so a re-sent order
/// never replaces a key that a signed certificate may already match. Only a key that openssl cannot read is replaced; a
/// request that fails for a readable key fails the order and keeps the key. The key never leaves the Node.
///
/// # Errors
///
/// `route.input_invalid` for an input outside the admitted shape, `tls.key_unreadable` for a key file with a PEM block
/// openssl cannot read, and `app-dev.certificate_request_failed` when the folder,
/// key or request cannot be made.
pub fn ensure_csr(system: &impl System, input: &serde_json::Value) -> Result<String, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    let absolute = |path: &str| path.strip_prefix('/').is_some_and(|rest| !rest.is_empty() && rest.split('/').all(|part| !part.is_empty() && part != "." && part != "..")) && !path.contains(['\n', '\0']);
    let shape = NAME.is_match(&input.user)
        && NAME.is_match(&input.group)
        && MODE.is_match(&input.directory_mode)
        && MODE.is_match(&input.key_mode)
        && SUBJECT.is_match(&input.subject);
    if !absolute(&input.directory) || !absolute(&input.key) || !shape || !DOMAIN.is_match(&input.domain) {
        return Err(Failure::new("route.input_invalid", "directory, key, owner, mode, subject or domain"));
    }
    let failed = |detail: String| Failure::new("app-dev.certificate_request_failed", detail);
    let directory = &input.directory;
    system
        .run(30, &strings(&["/usr/bin/install", "-d", "-o", &input.user, "-g", &input.group, "-m", &input.directory_mode, directory]))
        .map_err(failed)?;
    let uid = system.account(&input.user).map_err(failed)?.uid;
    let gid = system.group(&input.group).map_err(failed)?;
    let mode = u32::from_str_radix(&input.key_mode, 8).map_err(|_| failed("key mode".to_owned()))?;
    let key = system.path(&input.key);
    // An existing file is replaced only when it is decisively not a key: empty, or holding no PEM block of any kind and
    // failing DER parsing. A file with any PEM block that openssl cannot read (an encrypted key included) fails with
    // `tls.key_unreadable` and stays. A timeout or a command that cannot start is inconclusive and fails too.
    let usable = match fs::symlink_metadata(&key) {
        Ok(meta) if meta.file_type().is_file() => {
            let held = read_key(&key).map_err(|error| failed(error.to_string()))?;
            let inspect = |form: &str| system.run(30, &strings(&["/usr/bin/openssl", "pkey", "-inform", form, "-noout", "-in", &input.key]));
            if held.is_empty() {
                false
            } else if held.windows(11).any(|window| window == b"-----BEGIN ") {
                inspect("PEM").map_err(|detail| Failure::new("tls.key_unreadable", format!("{} holds a PEM block openssl cannot read: {detail}", input.key)))?;
                true
            } else {
                match inspect("DER") {
                    Ok(_) => true,
                    Err(detail) if unparsed(&detail) => false,
                    Err(detail) => return Err(failed(format!("{} inspection: {detail}", input.key))),
                }
            }
        }
        Ok(_) => return Err(failed(format!("{} is not a plain file", input.key))),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => false,
        Err(error) => return Err(failed(error.to_string())),
    };
    if usable {
        own(&key, uid, gid, mode).map_err(|error| failed(error.to_string()))?;
    } else {
        new_key(system, &key, uid, gid, mode).map_err(failed)?;
    }
    let name = format!("subjectAltName=DNS:{}", input.domain);
    let answer = request(system, &input.key, &input.subject, &name).map_err(failed)?;
    if !answer.contains("-----BEGIN CERTIFICATE REQUEST-----") {
        return Err(failed("openssl gave no request".to_owned()));
    }
    Ok(answer)
}

/// Whether openssl's error says it read the file and found no key in it: its own decoding message, not a timeout (which
/// prints nothing) or a failure to start.
fn unparsed(detail: &str) -> bool {
    detail.contains("Could not read key") || detail.contains("unable to load key") || detail.contains("Could not find private key")
}

/// The key file's bytes, read through a descriptor that does not follow a link.
fn read_key(key: &Path) -> std::io::Result<Vec<u8>> {
    let mut held = Vec::new();
    File::options().read(true).custom_flags(NOFOLLOW).open(key)?.read_to_end(&mut held)?;
    Ok(held)
}

/// Puts the key's owner and mode right through a descriptor that does not follow a link, leaving its bytes alone.
fn own(key: &Path, uid: u32, gid: u32, mode: u32) -> std::io::Result<()> {
    let file = File::options().read(true).custom_flags(NOFOLLOW).open(key)?;
    let meta = file.metadata()?;
    if (meta.uid(), meta.gid()) != (uid, gid) {
        fchown(&file, Some(uid), Some(gid))?;
    }
    if meta.mode() & 0o7777 != mode {
        file.set_permissions(fs::Permissions::from_mode(mode))?;
    }
    Ok(())
}

/// An RSA 2048 key from openssl's output, written through a file this run creates beside the key (never an existing one,
/// never through a link), then moved in place. The key in place must be that same file, or the order fails.
fn new_key(system: &impl System, key: &Path, uid: u32, gid: u32, mode: u32) -> Result<(), String> {
    let pem = system.run(60, &strings(&["/usr/bin/openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"]))?;
    if !pem.contains("PRIVATE KEY-----") {
        return Err("openssl gave no key".to_owned());
    }
    let name = key.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned());
    let partial = key.with_file_name(format!(".{name}.partial-{}-{}", std::process::id(), std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |at| at.as_nanos())));
    let mut opened = false;
    let written = (|| {
        let mut file = File::options().write(true).create_new(true).custom_flags(NOFOLLOW).mode(0o600).open(&partial)?;
        opened = true;
        fchown(&file, Some(uid), Some(gid))?;
        file.set_permissions(fs::Permissions::from_mode(mode))?;
        file.write_all(pem.as_bytes())?;
        file.sync_all()?;
        let created = file.metadata()?;
        fs::rename(&partial, key)?;
        let placed = fs::symlink_metadata(key)?;
        if (placed.dev(), placed.ino()) == (created.dev(), created.ino()) {
            Ok(())
        } else {
            Err(std::io::Error::other("the key in place is not the file this run made"))
        }
    })();
    if written.is_err() && opened {
        let _ = fs::remove_file(&partial);
    }
    written.map_err(|error| error.to_string())
}
/// A request for `key`: the subject and the one subject name.
fn request(system: &impl System, key: &str, subject: &str, name: &str) -> Result<String, String> {
    let mut argv = strings(&["/usr/bin/openssl", "req", "-new", "-key", key, "-subj", subject, "-addext"]);
    argv.push(name.to_owned());
    system.run(30, &argv)
}
