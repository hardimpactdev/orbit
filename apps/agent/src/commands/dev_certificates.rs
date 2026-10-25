//! The Orbit CA leaf certificates of development Routes. The managed account keeps each leaf's key under
//! `~/.orbit/certificates/<scope>`, and Caddy reads a copy under `/etc/caddy/orbit-certificates/<scope>`. A request
//! reuses a valid current leaf, or makes a new key and returns its signing request; the Gateway signs it, and a publish
//! installs the leaf in both trees, trusts the Orbit root, and reloads Caddy.

use super::caddy::{chown, group_id, public_key, reload_if_active};
use super::recipe::Files;
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    fs, io,
    os::unix::fs::{symlink, DirBuilderExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum LeafError {
        Failed { step: String } => "certificates.leaf_failed", "A certificate step failed on the Node.";
    }
}

fn failed(step: &str) -> LeafError {
    LeafError::Failed { step: step.into() }
}

const CADDY_ROOT: &str = "/etc/caddy/orbit-certificates";
const TRUST_ANCHOR: &str = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
const TRUST_BUNDLE: &str = "/etc/ssl/certs/ca-certificates.crt";
/// A leaf lives 397 or 398 days.
const VALIDITY: std::ops::RangeInclusive<i64> = 34_214_400..=34_387_200;

fn sha256(path: &Path) -> Option<String> {
    Some(format!("{:x}", Sha256::digest(fs::read(path).ok()?)))
}

fn openssl(arguments: &[&str]) -> Option<String> {
    let output = Process::new("openssl")
        .args(arguments)
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    output
        .status
        .success()
        .then(|| String::from_utf8_lossy(&output.stdout).into_owned())
}

/// The host boundary: accounts, the managed account's programs, the system trust store, and Caddy. Paths stay in
/// [`Files`]; `openssl` reads run directly.
trait Host {
    /// The managed account's user and group IDs.
    fn account(&self, user: &str) -> Option<(u32, u32)>;
    fn caddy_group(&self) -> Option<u32>;
    /// Runs a program as the managed account; its standard output on success.
    fn as_user(&mut self, user: &str, program: &str, arguments: &[&str]) -> Option<Vec<u8>>;
    /// Rebuilds the system bundle from the trust anchors.
    fn update_trust(&mut self) -> bool;
    fn reload_caddy(&mut self) -> io::Result<()>;
}

struct Native;

impl Host for Native {
    fn account(&self, user: &str) -> Option<(u32, u32)> {
        identity::account(user).ok().map(|(uid, gid, _)| (uid, gid))
    }

    fn caddy_group(&self) -> Option<u32> {
        group_id("caddy")
    }

    fn as_user(&mut self, user: &str, program: &str, arguments: &[&str]) -> Option<Vec<u8>> {
        let mut command = Process::new(program);
        command
            .args(arguments)
            .current_dir("/")
            .stdin(Stdio::null())
            .stderr(Stdio::null());
        identity::drop_to(&mut command, user).ok()?;
        let output = command.output().ok()?;
        output.status.success().then_some(output.stdout)
    }

    fn update_trust(&mut self) -> bool {
        Process::new("update-ca-certificates")
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }

    fn reload_caddy(&mut self) -> io::Result<()> {
        reload_if_active("caddy")
    }
}

/// Where a host path lives under the [`Files`] root.
fn at(files: &Files, path: &Path) -> PathBuf {
    files.root.join(path.strip_prefix("/").unwrap_or(path))
}

/// An extension of the certificate on one line, as the Gateway compares it.
fn extension(certificate: &str, name: &str) -> String {
    openssl(&["x509", "-in", certificate, "-noout", "-ext", name])
        .unwrap_or_default()
        .split_whitespace()
        .collect::<Vec<_>>()
        .join(" ")
}

/// The leaf's validity in seconds, from its `notBefore` and `notAfter`.
pub fn validity(dates: &str) -> Option<i64> {
    validity_bounds(dates).map(|(start, end)| end - start)
}

pub(super) fn validity_bounds(dates: &str) -> Option<(i64, i64)> {
    let format = time::macros::format_description!(
        "[month repr:short] [day padding:space] [hour]:[minute]:[second] [year] GMT"
    );
    let date = |key: &str| {
        let text = dates.lines().find_map(|line| line.strip_prefix(key))?;
        time::PrimitiveDateTime::parse(text.trim(), format).ok()
    };
    let start = date("notBefore=")?;
    let end = date("notAfter=")?;
    Some((
        start.assume_utc().unix_timestamp(),
        end.assume_utc().unix_timestamp(),
    ))
}

fn key_is_rsa_2048(key: &str) -> bool {
    openssl(&["pkey", "-in", key, "-text_pub", "-noout"]).is_some_and(|text| {
        text.lines()
            .any(|line| line == "Public-Key: (2048 bit)" || line == "RSA Public-Key: (2048 bit)")
    })
}

fn fingerprint(certificate: &str) -> Option<String> {
    openssl(&[
        "x509",
        "-in",
        certificate,
        "-fingerprint",
        "-sha256",
        "-noout",
    ])
}

/// Whether the current leaf is valid for the domain, signed by the expected root, and identical in Caddy's tree.
fn current_is_valid(current: &Path, caddy_current: &Path, domain: &str, root_hash: &str) -> bool {
    let file = |directory: &Path, name: &str| directory.join(name).to_string_lossy().into_owned();
    let (key, certificate, root) = (
        file(current, "key.pem"),
        file(current, "cert.pem"),
        file(current, "root.pem"),
    );
    let (caddy_key, caddy_certificate) = (
        file(caddy_current, "key.pem"),
        file(caddy_current, "cert.pem"),
    );
    let present = [&key, &certificate, &root, &caddy_key, &caddy_certificate]
        .iter()
        .all(|path| Path::new(path).is_file());
    present
        && sha256(Path::new(&root)).as_deref() == Some(root_hash)
        && openssl(&["verify", "-CAfile", &root, &certificate]).is_some()
        && openssl(&[
            "x509",
            "-in",
            &certificate,
            "-noout",
            "-checkend",
            "2592000",
        ])
        .is_some()
        && openssl(&["x509", "-in", &certificate, "-noout", "-dates"])
            .and_then(|dates| validity(&dates))
            .is_some_and(|seconds| VALIDITY.contains(&seconds))
        && openssl(&["x509", "-in", &certificate, "-noout", "-checkhost", domain])
            .is_some_and(|text| !text.contains("does NOT match"))
        && key_is_rsa_2048(&key)
        && extension(&certificate, "basicConstraints")
            == "X509v3 Basic Constraints: critical CA:FALSE"
        && extension(&certificate, "keyUsage")
            == "X509v3 Key Usage: critical Digital Signature, Key Encipherment"
        && extension(&certificate, "extendedKeyUsage")
            == "X509v3 Extended Key Usage: TLS Web Server Authentication"
        && extension(&certificate, "subjectAltName")
            == format!("X509v3 Subject Alternative Name: DNS:{domain}")
        && public_key("key", Path::new(&key)).is_some()
        && public_key("key", Path::new(&key)) == public_key("certificate", Path::new(&certificate))
        && fingerprint(&certificate).is_some()
        && fingerprint(&certificate) == fingerprint(&caddy_certificate)
        && public_key("key", Path::new(&caddy_key))
            == public_key("certificate", Path::new(&caddy_certificate))
}

/// Installs the root as the trust anchor when it differs, and checks the leaf against the system bundle.
fn trust(
    files: &Files,
    host: &mut impl Host,
    root: &Path,
    root_hash: &str,
    certificate: &Path,
) -> Result<(), LeafError> {
    let step = "certificate-trust";
    let anchor = files.path(TRUST_ANCHOR);
    if sha256(&anchor).as_deref() != Some(root_hash) {
        let staged = anchor.with_file_name(format!(
            "orbit-managed-root-ca.crt.orbit-{:016x}",
            rand::random::<u64>()
        ));
        let written = fs::copy(root, &staged)
            .and_then(|_| fs::set_permissions(&staged, fs::Permissions::from_mode(0o644)))
            .and_then(|()| chown(&staged, files.uid, files.gid))
            .and_then(|()| fs::rename(&staged, &anchor));
        if written.is_err() {
            let _ = fs::remove_file(&staged);
            return Err(failed(step));
        }
        if !host.update_trust() {
            return Err(failed(step));
        }
    }
    openssl(&[
        "verify",
        "-CAfile",
        &files.path(TRUST_BUNDLE).to_string_lossy(),
        &certificate.to_string_lossy(),
    ])
    .map(|_| ())
    .ok_or_else(|| failed(step))
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LeafInput {
    pub scope: String,
    pub domain: String,
    pub version: String,
    pub root_hash: String,
    pub user: String,
    pub home: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum Requested {
    Current {},
    Request { request: String },
}

fn valid_name(name: &str) -> bool {
    !name.is_empty()
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.'))
        && !name.starts_with('.')
}

fn roots(files: &Files, input: &LeafInput) -> Result<(PathBuf, PathBuf), LeafError> {
    if !valid_name(&input.version) {
        return Err(failed("certificate-input"));
    }
    scope_roots(
        files,
        &ScopeInput {
            scope: input.scope.clone(),
            home: input.home.clone(),
        },
    )
}

fn request(files: &Files, host: &mut impl Host, input: &LeafInput) -> Result<Requested, LeafError> {
    let step = "certificate-request";
    let (root, caddy_root) = roots(files, input)?;
    let current = root.join("current");
    if current_is_valid(
        &current,
        &caddy_root.join("current"),
        &input.domain,
        &input.root_hash,
    ) {
        trust(
            files,
            host,
            &current.join("root.pem"),
            &input.root_hash,
            &current.join("cert.pem"),
        )?;
        return Ok(Requested::Current {});
    }
    let versions = root.join("versions");
    let versions_text = versions.to_string_lossy().into_owned();
    host.as_user(
        &input.user,
        "install",
        &["-d", "-m", "0700", "--", &versions_text],
    )
    .ok_or_else(|| failed(step))?;
    if let Ok(entries) = fs::read_dir(&versions) {
        for entry in entries.flatten() {
            if entry.file_name().to_string_lossy().ends_with(".candidate") {
                let _ = fs::remove_dir_all(entry.path());
            }
        }
    }
    let candidate = versions.join(format!("{}.candidate", input.version));
    let candidate_text = candidate.to_string_lossy().into_owned();
    let key = format!("{candidate_text}/key.pem");
    let request = format!("{candidate_text}/request.pem");
    let subject = format!("/CN={}", input.domain);
    host.as_user(
        &input.user,
        "install",
        &["-d", "-m", "0700", "--", &candidate_text],
    )
    .ok_or_else(|| failed(step))?;
    host.as_user(&input.user, "openssl", &["genrsa", "-out", &key, "2048"])
        .ok_or_else(|| failed(step))?;
    host.as_user(&input.user, "chmod", &["0600", &key])
        .ok_or_else(|| failed(step))?;
    host.as_user(
        &input.user,
        "openssl",
        &[
            "req", "-new", "-key", &key, "-subj", &subject, "-out", &request,
        ],
    )
    .ok_or_else(|| failed(step))?;
    let request = fs::read_to_string(&request).map_err(|_| failed(step))?;
    Ok(Requested::Request { request })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PublishInput {
    #[serde(flatten)]
    pub leaf: LeafInput,
    pub certificate: String,
    pub root_certificate: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Published {}

/// Points `link` at `target` with one rename, so a reader sees the old or the new target.
fn swap_link(
    directory: &Path,
    version: &str,
    target: &Path,
    owner: Option<(u32, u32)>,
) -> io::Result<()> {
    let staged = directory.join(format!(".current-{version}"));
    let _ = fs::remove_file(&staged);
    symlink(target, &staged)?;
    if let Some((uid, gid)) = owner {
        rustix::fs::chownat(
            rustix::fs::CWD,
            &staged,
            Some(rustix::fs::Uid::from_raw(uid)),
            Some(rustix::fs::Gid::from_raw(gid)),
            rustix::fs::AtFlags::SYMLINK_NOFOLLOW,
        )?;
    }
    fs::rename(&staged, directory.join("current"))
}

fn install_file(source: &Path, target: &Path, uid: u32, gid: u32, mode: u32) -> io::Result<()> {
    fs::copy(source, target)?;
    chown(target, uid, gid)?;
    fs::set_permissions(target, fs::Permissions::from_mode(mode))
}

fn prepare_caddy_directories(
    root: &Path,
    scope: &str,
    version: &str,
    owner: u32,
    caddy: u32,
) -> io::Result<()> {
    let scope = root.join(scope);
    let versions = scope.join("versions");
    let candidate = versions.join(format!("{version}.candidate"));
    for directory in [root, &scope, &versions, &candidate] {
        if !directory.is_dir() {
            fs::DirBuilder::new().mode(0o750).create(directory)?;
        }
        chown(directory, owner, caddy)?;
        fs::set_permissions(directory, fs::Permissions::from_mode(0o750))?;
    }
    Ok(())
}

fn publish(
    files: &Files,
    host: &mut impl Host,
    input: &PublishInput,
) -> Result<Published, LeafError> {
    let leaf = &input.leaf;
    let step = "certificate-publish";
    let (root, caddy_root) = roots(files, leaf)?;
    let (uid, gid) = host.account(&leaf.user).ok_or_else(|| failed(step))?;
    let caddy = host.caddy_group().ok_or_else(|| failed(step))?;
    let candidate = root
        .join("versions")
        .join(format!("{}.candidate", leaf.version));
    if !candidate.is_dir() {
        return Err(failed(step));
    }
    let (key, certificate, root_pem) = (
        candidate.join("key.pem"),
        candidate.join("cert.pem"),
        candidate.join("root.pem"),
    );
    let io_failed = |_: io::Error| failed(step);
    for (path, content, mode) in [
        (&certificate, &input.certificate, 0o644),
        (&root_pem, &input.root_certificate, 0o644),
    ] {
        fs::write(path, content).map_err(io_failed)?;
        chown(path, uid, gid).map_err(io_failed)?;
        fs::set_permissions(path, fs::Permissions::from_mode(mode)).map_err(io_failed)?;
    }
    let _ = fs::remove_file(candidate.join("request.pem"));
    fs::set_permissions(&key, fs::Permissions::from_mode(0o600)).map_err(io_failed)?;
    let text = |path: &Path| path.to_string_lossy().into_owned();
    let checks = key_is_rsa_2048(&text(&key))
        && openssl(&["verify", "-CAfile", &text(&root_pem), &text(&certificate)]).is_some()
        && sha256(&root_pem).as_deref() == Some(leaf.root_hash.as_str())
        && openssl(&[
            "x509",
            "-in",
            &text(&certificate),
            "-noout",
            "-checkhost",
            &leaf.domain,
        ])
        .is_some_and(|text| !text.contains("does NOT match"))
        && public_key("key", &key).is_some()
        && public_key("key", &key) == public_key("certificate", &certificate);
    if !checks {
        return Err(failed(step));
    }
    trust(files, host, &root_pem, &leaf.root_hash, &certificate)?;
    let published = root.join("versions").join(&leaf.version);
    fs::rename(&candidate, &published).map_err(io_failed)?;
    swap_link(&root, &leaf.version, &published, Some((uid, gid))).map_err(io_failed)?;

    let caddy_versions = caddy_root.join("versions");
    let caddy_candidate = caddy_versions.join(format!("{}.candidate", leaf.version));
    let caddy_published = caddy_versions.join(&leaf.version);
    prepare_caddy_directories(
        &files.path(CADDY_ROOT),
        &leaf.scope,
        &leaf.version,
        files.uid,
        caddy,
    )
    .map_err(io_failed)?;
    install_file(
        &published.join("key.pem"),
        &caddy_candidate.join("key.pem"),
        files.uid,
        caddy,
        0o640,
    )
    .map_err(io_failed)?;
    install_file(
        &published.join("cert.pem"),
        &caddy_candidate.join("cert.pem"),
        files.uid,
        caddy,
        0o644,
    )
    .map_err(io_failed)?;
    let _ = fs::remove_dir_all(&caddy_published);
    fs::rename(&caddy_candidate, &caddy_published).map_err(io_failed)?;
    swap_link(&caddy_root, &leaf.version, &caddy_published, None).map_err(io_failed)?;
    // A live site may already name this certificate, and a build whose render did not change does not reload.
    host.reload_caddy().map_err(|_| failed(step))?;
    Ok(Published {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ScopeInput {
    pub scope: String,
    pub home: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Presence {
    pub present: bool,
}

fn scope_roots(files: &Files, input: &ScopeInput) -> Result<(PathBuf, PathBuf), LeafError> {
    if !valid_name(&input.scope) {
        return Err(failed("certificate-input"));
    }
    Ok((
        at(files, &input.home)
            .join(".orbit/certificates")
            .join(&input.scope),
        files.path(CADDY_ROOT).join(&input.scope),
    ))
}

fn inspect(files: &Files, input: &ScopeInput) -> Result<Presence, LeafError> {
    let (root, caddy_root) = scope_roots(files, input)?;
    Ok(Presence {
        present: root.join("current/cert.pem").is_file()
            && caddy_root.join("current/cert.pem").is_file(),
    })
}

fn remove(files: &Files, input: &ScopeInput) -> Result<Published, LeafError> {
    let (root, caddy_root) = scope_roots(files, input)?;
    for directory in [root, caddy_root] {
        match fs::remove_dir_all(&directory) {
            Err(error) if error.kind() != io::ErrorKind::NotFound => {
                return Err(failed("certificate-remove"))
            }
            _ => {}
        }
    }
    Ok(Published {})
}

pub struct RequestLeaf;

impl Command for RequestLeaf {
    const NAME: &'static str = "certificates.request-leaf";
    const SUMMARY: &'static str =
        "Reuse a valid development leaf certificate, or make a new key and its signing request.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = LeafInput;
    type Output = Requested;
    type Error = LeafError;

    /// The key belongs to the managed account; root reads Caddy's copy and installs the trust anchor.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &LeafInput) -> Option<String> {
        Some(format!("certificate:{}", input.scope))
    }

    fn run(input: LeafInput, _: &Context) -> Result<Requested, LeafError> {
        request(&Files::system(), &mut Native, &input)
    }
}

pub struct PublishLeaf;

impl Command for PublishLeaf {
    const NAME: &'static str = "certificates.publish-leaf";
    const SUMMARY: &'static str =
        "Install a signed development leaf for the managed account and Caddy, and reload Caddy.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = PublishInput;
    type Output = Published;
    type Error = LeafError;

    /// The leaf belongs to the managed account, its Caddy copy and the trust anchor to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &PublishInput) -> Option<String> {
        Some(format!("certificate:{}", input.leaf.scope))
    }

    fn run(input: PublishInput, _: &Context) -> Result<Published, LeafError> {
        publish(&Files::system(), &mut Native, &input)
    }
}

pub struct InspectLeaf;

impl Command for InspectLeaf {
    const NAME: &'static str = "certificates.inspect-leaf";
    const SUMMARY: &'static str =
        "Report whether a development leaf exists for the managed account and for Caddy.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = ScopeInput;
    type Output = Presence;
    type Error = LeafError;

    /// Caddy's copy is readable only to root and Caddy.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: ScopeInput, _: &Context) -> Result<Presence, LeafError> {
        inspect(&Files::system(), &input)
    }
}

pub struct RemoveLeaf;

impl Command for RemoveLeaf {
    const NAME: &'static str = "certificates.remove-leaf";
    const SUMMARY: &'static str =
        "Remove a development leaf for the managed account and for Caddy.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ScopeInput;
    type Output = Published;
    type Error = LeafError;

    /// The leaf belongs to the managed account, its Caddy copy to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &ScopeInput) -> Option<String> {
        Some(format!("certificate:{}", input.scope))
    }

    fn run(input: ScopeInput, _: &Context) -> Result<Published, LeafError> {
        remove(&Files::system(), &input)
    }
}

#[cfg(test)]
#[path = "dev_certificates_tests.rs"]
mod tests;
