//! `php.*-production-*`: the dedicated PHP-FPM service of a production Instance. Its runtime directory
//! `/etc/orbit/php-fpm/<user>` holds the root-owned identity marker, the operator's `local.conf`, the generated
//! configuration, and two journals: the runtime generation receipts and the monitoring pool journal. Every command
//! runs as root and checks the marker first. These replace the Bash that `RemoteProductionPhpRuntimeManager` sent,
//! check for check, and keep its file formats, so a Node converged by the Bash converges here unchanged.

use super::production::valid_name;
use super::source::{
    exists, is_real_directory, is_regular_file, is_symlink, realpath, starts_inside,
};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    ffi::CString,
    fs,
    io::{Read, Write},
    os::unix::{
        fs::{FileTypeExt, MetadataExt, OpenOptionsExt, PermissionsExt},
        net::UnixStream,
    },
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

command_errors! {
    pub enum RuntimeError {
        Invalid { check: String } => "php.runtime_invalid", "The production PHP runtime does not pass its checks.";
        CacheSocketUnavailable {} => "php.cache_socket_unavailable", "The production PHP cache socket is unavailable.";
        CacheAssociationInvalid {} => "php.cache_association_invalid", "The production PHP cache service association is invalid.";
        CacheResetRejected {} => "php.cache_reset_rejected", "The production PHP cache reset was rejected.";
        CacheResetPending {} => "php.cache_reset_pending", "The production PHP cache reset did not complete before the deadline.";
        CacheProtocol {} => "php.cache_protocol_failed", "The production PHP cache answered outside the FastCGI protocol.";
    }
}

fn invalid(check: &str) -> RuntimeError {
    RuntimeError::Invalid {
        check: check.into(),
    }
}

fn check(condition: bool, what: &str) -> Result<(), RuntimeError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what))
    }
}

/// Where the commands find each system path, which program manages services, and who owns what. Tests point the
/// paths at a sandbox, `systemctl` and `sbin` at stubs, and the owners at the test account.
pub struct Host {
    pub homes: PathBuf,
    pub etc: PathBuf,
    pub units: PathBuf,
    pub sockets: PathBuf,
    pub proc_root: PathBuf,
    pub sbin: PathBuf,
    pub php_conf: PathBuf,
    pub tmp: PathBuf,
    pub systemctl: PathBuf,
    pub root_uid: u32,
    pub root_gid: u32,
    pub caddy_gid: u32,
}

impl Host {
    pub fn system() -> Result<Self, RuntimeError> {
        Ok(Self {
            homes: PathBuf::from("/home"),
            etc: PathBuf::from("/etc/orbit"),
            units: PathBuf::from("/etc/systemd/system"),
            sockets: PathBuf::from("/run/php"),
            proc_root: PathBuf::from("/proc"),
            sbin: PathBuf::from("/usr/sbin"),
            php_conf: PathBuf::from("/etc/php"),
            tmp: PathBuf::from("/tmp"),
            systemctl: PathBuf::from("/usr/bin/systemctl"),
            root_uid: 0,
            root_gid: 0,
            caddy_gid: group("caddy").ok_or_else(|| invalid("the caddy group exists"))?,
        })
    }

    fn systemctl(&self, arguments: &[&str]) -> bool {
        Process::new(&self.systemctl)
            .args(arguments)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    }

    fn quiet(&self, arguments: &[&str]) {
        let _ = self.systemctl(arguments);
    }

    fn active(&self, service: &str) -> bool {
        self.systemctl(&["is-active", "--quiet", service])
    }

    /// `systemctl show --property MainPID --value`, or `None` when it fails or prints no number.
    fn main_pid(&self, service: &str) -> Option<u32> {
        let output = Process::new(&self.systemctl)
            .args(["show", "--property", "MainPID", "--value", service])
            .stdin(Stdio::null())
            .stderr(Stdio::null())
            .output()
            .ok()?;
        let text = String::from_utf8(output.stdout).ok()?;
        let text = text.trim();
        (output.status.success() && !text.is_empty() && text.bytes().all(|b| b.is_ascii_digit()))
            .then(|| text.parse().ok())
            .flatten()
    }

    fn fpm(&self, version: &str) -> PathBuf {
        self.sbin.join(format!("php-fpm{version}"))
    }

    /// Whether the process runs the version's FPM binary, as `readlink -f /proc/<pid>/exe` reads it.
    fn runs_fpm(&self, pid: u32, version: &str) -> bool {
        pid > 1
            && realpath(&self.proc_root.join(pid.to_string()).join("exe"))
                .is_some_and(|exe| exe == self.fpm(version))
    }

    /// The start time of a process, field 22 of its `stat`, counted after the command name.
    fn start_time(&self, pid: u32) -> Option<u64> {
        let stat = fs::read_to_string(self.proc_root.join(pid.to_string()).join("stat")).ok()?;
        let after = &stat[stat.rfind(") ")? + 2..];
        let field = after.split_whitespace().nth(19)?;
        field
            .bytes()
            .all(|b| b.is_ascii_digit())
            .then(|| field.parse().ok())?
    }

    fn root_owned(&self, path: &Path, mode: u32) -> bool {
        owned(path, self.root_uid, self.root_gid, mode)
    }

    /// `test -S socket` and `user:caddy:660`.
    fn served_socket(&self, path: &Path, uid: u32) -> bool {
        fs::symlink_metadata(path).is_ok_and(|metadata| {
            metadata.file_type().is_socket()
                && metadata.uid() == uid
                && metadata.gid() == self.caddy_gid
                && metadata.mode() & 0o7777 == 0o660
        })
    }
}

fn group(name: &str) -> Option<u32> {
    let name = CString::new(name).ok()?;
    // SAFETY: getgrnam reads a NUL-terminated name and returns a static record or null.
    let record = unsafe { libc::getgrnam(name.as_ptr()) };
    // SAFETY: a non-null record is valid until the next getgr* call.
    (!record.is_null()).then(|| unsafe { (*record).gr_gid })
}

fn owned(path: &Path, uid: u32, gid: u32, mode: u32) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| {
        metadata.uid() == uid && metadata.gid() == gid && metadata.mode() & 0o7777 == mode
    })
}

fn sha256(content: &[u8]) -> String {
    Sha256::digest(content)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn file_hash(path: &Path) -> Option<String> {
    fs::read(path).ok().map(|content| sha256(&content))
}

fn sync(path: &Path) -> Result<(), RuntimeError> {
    fs::File::open(path)
        .and_then(|file| file.sync_all())
        .map_err(|_| invalid("the runtime files reach the disk"))
}

/// Writes `content` to a candidate in `directory` with `mode`, then renames it over `path`, as `install` and
/// `mv -fT` did.
fn publish(directory: &Path, path: &Path, content: &[u8], mode: u32) -> Result<(), RuntimeError> {
    let candidate = directory.join(format!(".orbit.{:016x}.candidate", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        file.write_all(content)?;
        file.sync_all()?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(mode))?;
        fs::rename(&candidate, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    result.map_err(|_| invalid("a runtime file is published"))
}

fn remove_file(path: &Path) -> Result<(), RuntimeError> {
    match fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => Err(invalid("a runtime file is removed")),
    }
}

fn remove_tree(path: &Path) -> Result<(), RuntimeError> {
    match fs::remove_dir_all(path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(_) => Err(invalid("a runtime directory is removed")),
    }
}

/// `cp -a` of a flat directory of regular files.
fn copy_flat(from: &Path, to: &Path) -> Result<(), RuntimeError> {
    let copied = (|| {
        fs::create_dir(to)?;
        fs::set_permissions(to, fs::metadata(from)?.permissions())?;
        for entry in fs::read_dir(from)? {
            let entry = entry?;
            fs::copy(entry.path(), to.join(entry.file_name()))?;
        }
        Ok::<_, std::io::Error>(())
    })();
    copied.map_err(|_| invalid("the runtime backup is copied"))
}

/// A private work directory that is removed with the value.
struct Work(PathBuf);

impl Work {
    fn new(host: &Host, prefix: &str) -> Result<Self, RuntimeError> {
        let path = host
            .tmp
            .join(format!("{prefix}.{:016x}", rand::random::<u64>()));
        super::source::private_directory(&path).map_err(|_| invalid("a work directory exists"))?;
        Ok(Self(path))
    }
}

impl Drop for Work {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

// ---- identity ----------------------------------------------------------------------------------------------------

/// The runtime that one production user owns. Every path follows from the user and the PHP version.
struct Runtime {
    user: String,
    uid: u32,
    home: PathBuf,
    version: String,
    service: String,
    socket: PathBuf,
    directory: PathBuf,
    generated: PathBuf,
    local: PathBuf,
    unit: PathBuf,
    marker: PathBuf,
    expected_marker: Vec<u8>,
}

fn valid_user(user: &str) -> bool {
    let bytes = user.as_bytes();
    (1..=32).contains(&bytes.len())
        && (bytes[0].is_ascii_lowercase() || bytes[0] == b'_')
        && bytes
            .iter()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b"_-".contains(b))
}

pub(super) fn valid_version(version: &str) -> bool {
    version.split_once('.').is_some_and(|(major, minor)| {
        [major, minor]
            .iter()
            .all(|part| !part.is_empty() && part.bytes().all(|b| b.is_ascii_digit()))
    })
}

impl Runtime {
    fn new(host: &Host, user: &str, version: &str, marker: &str) -> Result<Self, RuntimeError> {
        check(valid_user(user), "the production user name is valid")?;
        check(valid_version(version), "the PHP version is valid")?;
        let service = format!("orbit-{user}-php{version}-fpm.service");
        let directory = host.etc.join("php-fpm").join(user);
        Ok(Self {
            user: user.into(),
            uid: 0,
            home: host.homes.join(user),
            version: version.into(),
            socket: host.sockets.join(format!("{user}.sock")),
            generated: directory.join("generated"),
            local: directory.join("local.conf"),
            unit: host.units.join(&service),
            marker: directory.join("orbit.identity"),
            directory,
            service,
            expected_marker: marker.as_bytes().to_vec(),
        })
    }

    /// Checks that the account exists and owns its real home `/home/<user>`.
    fn with_home(mut self, home: &Path) -> Result<Self, RuntimeError> {
        let (uid, gid, _) =
            identity::account(&self.user).map_err(|_| invalid("the production user exists"))?;
        check(home == self.home, "the home is the production user's")?;
        check(is_real_directory(home), "the home is a real directory")?;
        check(
            fs::metadata(home).is_ok_and(|metadata| metadata.uid() == uid && metadata.gid() == gid),
            "the production user owns the home",
        )?;
        self.uid = uid;
        Ok(self)
    }

    /// Whether the marker is a root-owned 0644 file that holds the expected identity.
    fn marker_matches(&self, host: &Host) -> bool {
        is_regular_file(&self.marker)
            && host.root_owned(&self.marker, 0o644)
            && fs::read(&self.marker).is_ok_and(|content| content == self.expected_marker)
    }

    fn receipt(&self, name: &str) -> PathBuf {
        self.directory.join(name)
    }
}

// ---- runtime generation receipts -------------------------------------------------------------------------------

const PENDING: &str = ".runtime-generation.pending";
const APPLIED: &str = ".runtime-generation.applied";

/// The pool's working directories and the release it names, hashed: what a master must have loaded.
fn pool_generation(pool: &[u8]) -> String {
    let mut selected = Vec::new();
    for line in pool.split(|byte| *byte == b'\n') {
        if line.starts_with(b"chdir = ") || line.starts_with(b"; Orbit application release: ") {
            selected.extend_from_slice(line);
            selected.push(b'\n');
        }
    }
    sha256(&selected)
}

/// The boot, the master's pid, and its start time: one master in one boot.
fn master_token(host: &Host, service: &str) -> Option<(String, u32, u64)> {
    let pid = host.main_pid(service).filter(|pid| *pid > 1)?;
    let boot = fs::read_to_string(host.proc_root.join("sys/kernel/random/boot_id")).ok()?;
    let boot = boot.trim_end_matches('\n').to_string();
    (boot.len() == 36 && boot.bytes().all(|b| b.is_ascii_hexdigit() || b == b'-')).then_some(())?;
    let start = host.start_time(pid)?;
    (host.main_pid(service) == Some(pid)).then_some((boot, pid, start))
}

fn guard_receipt(host: &Host, path: &Path) -> bool {
    !exists(path) || (is_regular_file(path) && host.root_owned(path, 0o600))
}

/// The receipt names the master that confirmed the generation. With no pending receipt, a master that started
/// later, after a reboot or a restart in the same boot, loaded the same confirmed files, so a restart outside Orbit
/// does not make the generation unapplied.
fn generation_applied(host: &Host, runtime: &Runtime, pool: &[u8]) -> bool {
    let applied_path = runtime.receipt(APPLIED);
    if exists(&runtime.receipt(PENDING))
        || !guard_receipt(host, &applied_path)
        || !applied_path.is_file()
    {
        return false;
    }
    let Some((boot, pid, start)) = master_token(host, &runtime.service) else {
        return false;
    };
    let Ok(applied) = fs::read_to_string(&applied_path) else {
        return false;
    };
    let lines: Vec<&str> = applied.trim_end_matches('\n').split('\n').collect();
    if lines.len() != 4 || lines[0] != pool_generation(pool) {
        return false;
    }
    if lines[1] == boot && lines[2] == pid.to_string() && lines[3] == start.to_string() {
        return true;
    }
    let (recorded_boot, recorded_start) = (lines[1], lines[3]);
    if recorded_boot.len() != 36
        || !recorded_boot
            .bytes()
            .all(|b| b.is_ascii_hexdigit() || b == b'-')
    {
        return false;
    }
    let Ok(recorded_start) = recorded_start.parse::<u64>() else {
        return false;
    };
    recorded_boot != boot || start > recorded_start
}

fn write_receipt(
    host: &Host,
    runtime: &Runtime,
    name: &str,
    value: &str,
) -> Result<(), RuntimeError> {
    let path = runtime.receipt(name);
    check(
        guard_receipt(host, &path),
        "the runtime receipt is root-owned",
    )?;
    publish(
        &runtime.directory,
        &path,
        format!("{value}\n").as_bytes(),
        0o600,
    )?;
    sync(&runtime.directory)
}

fn begin_generation(host: &Host, runtime: &Runtime, pool: &[u8]) -> Result<(), RuntimeError> {
    check(
        guard_receipt(host, &runtime.receipt(APPLIED)),
        "the applied receipt is root-owned",
    )?;
    write_receipt(host, runtime, PENDING, &pool_generation(pool))
}

fn confirm_generation(host: &Host, runtime: &Runtime, pool: &[u8]) -> Result<(), RuntimeError> {
    let (boot, pid, start) =
        master_token(host, &runtime.service).ok_or_else(|| invalid("the PHP-FPM master runs"))?;
    let value = format!("{}\n{boot}\n{pid}\n{start}", pool_generation(pool));
    write_receipt(host, runtime, APPLIED, &value)?;
    remove_file(&runtime.receipt(PENDING))?;
    sync(&runtime.directory)?;
    check(
        generation_applied(host, runtime, pool),
        "the runtime generation is applied",
    )
}

// ---- monitoring pool journal -----------------------------------------------------------------------------------

const METRICS_PENDING: &str = ".metrics-pool.pending";
const METRICS_BACKUP: &str = ".metrics-pool.backup";

/// The pending journal: the master's state when the change began and the hash of the pool it publishes.
fn read_journal(path: &Path) -> Result<(bool, String), RuntimeError> {
    let text =
        fs::read_to_string(path).map_err(|_| invalid("the monitoring journal is readable"))?;
    let first = text.split('\n').next().unwrap_or_default();
    let mut fields = first.splitn(2, ' ');
    let state = fields.next().unwrap_or_default();
    let hash = fields.next().unwrap_or_default().trim_end();
    check(
        state == "active" || state == "stopped",
        "the monitoring journal names a state",
    )?;
    check(
        hash.len() == 64
            && hash
                .bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)),
        "the monitoring journal names a hash",
    )?;
    Ok((state == "active", hash.into()))
}

fn candidates(directory: &Path, prefix: &str) -> Vec<PathBuf> {
    fs::read_dir(directory)
        .map(|entries| {
            entries
                .flatten()
                .filter(|entry| {
                    let name = entry.file_name();
                    let name = name.to_string_lossy();
                    name.starts_with(prefix) && name.ends_with(".candidate")
                })
                .map(|entry| entry.path())
                .collect()
        })
        .unwrap_or_default()
}

/// Removes a pool candidate that an interrupted monitoring change left beside the pool, when it is one of the two
/// pools the journal names.
fn cleanup_monitoring_candidate(host: &Host, runtime: &Runtime) -> Result<(), RuntimeError> {
    let pending = runtime.receipt(METRICS_PENDING);
    let backup = runtime.receipt(METRICS_BACKUP);
    if !exists(&pending) {
        return Ok(());
    }
    check(
        is_regular_file(&pending) && host.root_owned(&pending, 0o600),
        "the monitoring journal is root-owned",
    )?;
    check(
        is_regular_file(&backup) && host.root_owned(&backup, 0o600),
        "the monitoring backup is root-owned",
    )?;
    let (_, pending_hash) = read_journal(&pending)?;
    for candidate in candidates(&runtime.generated, ".pool.conf.") {
        check(
            is_regular_file(&candidate) && host.root_owned(&candidate, 0o644),
            "the pool candidate is root-owned",
        )?;
        let hash = file_hash(&candidate);
        check(
            hash.as_deref() == Some(pending_hash.as_str()) || hash == file_hash(&backup),
            "the pool candidate is a journaled pool",
        )?;
        remove_file(&candidate)?;
    }
    sync(&runtime.generated)
}

#[derive(PartialEq, Clone, Copy)]
enum Journal {
    /// Finish or undo an interrupted change, and change nothing else.
    Resolve,
    /// Then publish the desired pool with a reload.
    Apply,
}

fn reload(host: &Host, service: &str) -> Result<(), RuntimeError> {
    check(
        host.systemctl(&["reload", service]) && host.active(service),
        "the PHP-FPM service reloads",
    )
}

/// Publishes a pool that differs only in monitoring with a reload instead of a restart. The journal makes an
/// interrupted change finish or undo on the next run.
fn converge_monitoring_pool(
    host: &Host,
    runtime: &Runtime,
    desired: &[u8],
    was_active: bool,
    mode: Journal,
) -> Result<(), RuntimeError> {
    let pool_file = runtime.generated.join("pool.conf");
    let pending = runtime.receipt(METRICS_PENDING);
    let backup = runtime.receipt(METRICS_BACKUP);
    for candidate in candidates(&runtime.directory, ".metrics-pool.")
        .into_iter()
        .chain(candidates(&runtime.generated, ".pool.conf."))
    {
        remove_file(&candidate)?;
    }
    check(is_regular_file(&pool_file), "the pool is a regular file")?;
    if exists(&backup) {
        check(
            is_regular_file(&backup) && host.root_owned(&backup, 0o600),
            "the monitoring backup is root-owned",
        )?;
    }
    if exists(&pending) {
        check(
            is_regular_file(&pending) && host.root_owned(&pending, 0o600),
            "the monitoring journal is root-owned",
        )?;
        check(is_regular_file(&backup), "the monitoring backup exists")?;
        let (was_running, pending_hash) = read_journal(&pending)?;
        let current = file_hash(&pool_file).unwrap_or_default();
        let backup_hash = file_hash(&backup).unwrap_or_default();
        check(
            current == pending_hash || current == backup_hash,
            "the pool did not change during an interrupted monitoring update",
        )?;
        if mode == Journal::Apply && sha256(desired) == backup_hash && current != backup_hash {
            let content =
                fs::read(&backup).map_err(|_| invalid("the monitoring backup is readable"))?;
            publish(&runtime.generated, &pool_file, &content, 0o644)?;
            sync(&runtime.generated)?;
        }
        if host.active(&runtime.service) {
            reload(host, &runtime.service)?;
        } else {
            check(
                !was_running,
                "the PHP-FPM master runs during monitoring recovery",
            )?;
        }
        remove_file(&pending)?;
        sync(&runtime.directory)?;
        remove_file(&backup)?;
    } else if exists(&backup) {
        remove_file(&backup)?;
    }

    if mode == Journal::Resolve {
        return Ok(());
    }
    check(is_regular_file(&pool_file), "the pool is a regular file")?;
    if fs::read(&pool_file).is_ok_and(|content| content == desired) {
        return Ok(());
    }

    let current = fs::read(&pool_file).map_err(|_| invalid("the pool is readable"))?;
    publish(&runtime.directory, &backup, &current, 0o600)?;
    let state = if was_active { "active" } else { "stopped" };
    publish(
        &runtime.directory,
        &pending,
        format!("{state} {}\n", sha256(desired)).as_bytes(),
        0o600,
    )?;
    sync(&runtime.directory)?;
    publish(&runtime.generated, &pool_file, desired, 0o644)?;
    sync(&runtime.generated)?;
    if was_active {
        reload(host, &runtime.service)?;
    }
    remove_file(&pending)?;
    sync(&runtime.directory)?;
    remove_file(&backup)
}

// ---- converge ----------------------------------------------------------------------------------------------------

#[derive(Deserialize, Clone, Copy, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum Mode {
    /// Publish the whole runtime, restarting the master when a file changed.
    Converge,
    /// Publish only a monitoring change of the pool, with a reload. A runtime that does not exist is left alone.
    Monitor,
}

/// The configuration the Gateway renders for one runtime.
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Configuration {
    pub main: String,
    pub pool: String,
    pub local_defaults: String,
    pub master_ini: String,
    pub unit: String,
    /// The pool for the first start, before `current` exists.
    pub initial_pool: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ConvergeInput {
    pub mode: Mode,
    pub user: String,
    pub home: PathBuf,
    pub version: String,
    pub marker: String,
    pub configuration: Configuration,
    /// The application directory under `current`, and the same under `releases/initial`.
    pub application: PathBuf,
    pub initial_application: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Converged {
    /// Whether the master restarted or started with new files.
    pub published: bool,
}

/// Binds the pool to the resolved release, so convergence replaces workers whose working directory still points at
/// the previous release although the configured `chdir` through `current` did not change. Before `current` exists,
/// the initial pool and directory apply.
fn select_application_pool(input: &ConvergeInput) -> Result<Vec<u8>, RuntimeError> {
    let fail = || invalid("the application directory is in the selected release");
    let current = input.home.join("current");
    let releases = input.home.join("releases");
    let (directory, pool, resolved) = if input.application != input.initial_application {
        if exists(&current) {
            check(is_symlink(&current), "current is a link")?;
            let selected = realpath(&current).ok_or_else(fail)?;
            check(starts_inside(&selected, &releases), "current is a release")?;
            let name = selected.strip_prefix(&releases).map_err(|_| fail())?;
            check(
                valid_name(&name.to_string_lossy()),
                "the release name is valid",
            )?;
            let resolved = realpath(&input.application).ok_or_else(fail)?;
            let below = input
                .application
                .strip_prefix(&current)
                .map_err(|_| fail())?;
            let expected = if below.as_os_str().is_empty() {
                selected.clone()
            } else {
                selected.join(below)
            };
            check(
                resolved == expected,
                "the application directory is in current",
            )?;
            (&input.application, &input.configuration.pool, resolved)
        } else {
            let resolved = realpath(&input.initial_application).ok_or_else(fail)?;
            check(
                resolved == input.initial_application,
                "the initial directory is canonical",
            )?;
            (
                &input.initial_application,
                &input.configuration.initial_pool,
                resolved,
            )
        }
    } else {
        let resolved = realpath(&input.application).ok_or_else(fail)?;
        check(
            resolved == input.application,
            "the application directory is canonical",
        )?;
        (&input.application, &input.configuration.pool, resolved)
    };
    check(directory.is_dir(), "the application directory exists")?;
    let mut selected = pool.as_bytes().to_vec();
    selected.extend_from_slice(
        format!("\n; Orbit application release: {}\n", resolved.display()).as_bytes(),
    );
    Ok(selected)
}

/// The values of `key = ` lines after the first, which belong to the pools that Routes with a web root add.
fn served_values<'a>(pool: &'a str, key: &str) -> Vec<&'a str> {
    let prefix = format!("{key} = ");
    pool.lines()
        .filter_map(|line| line.strip_prefix(prefix.as_str()))
        .skip(1)
        .collect()
}

/// The operator's `local.conf` may tune the pool but never name its identity, socket, directory, or includes.
fn local_tuning_allowed(local: &str, pool_name: &str, monitoring: bool) -> bool {
    let forbidden = [
        "pid",
        "user",
        "group",
        "listen",
        "listen.owner",
        "listen.group",
        "listen.mode",
        "chdir",
        "env[home]",
        "env[user]",
    ];
    let names = |line: &str, key: &str| {
        line.strip_prefix(key)
            .is_some_and(|rest| rest.trim_start_matches([' ', '\t']).starts_with('='))
    };
    for raw in local.lines() {
        let trimmed = raw.trim_start();
        if trimmed.is_empty() || trimmed.starts_with(';') || trimmed.starts_with('#') {
            continue;
        }
        if trimmed.starts_with('[') {
            if raw.trim() != format!("[{pool_name}]") {
                return false;
            }
            continue;
        }
        let line = trimmed.to_lowercase();
        if names(&line, "include")
            || (monitoring && (names(&line, "pm.status_path") || names(&line, "pm.status_listen")))
            || forbidden.iter().any(|key| names(&line, key))
        {
            return false;
        }
    }
    true
}

/// Checks the candidate configuration with the version's own FPM binary: it must parse, load the generated
/// `master.ini`, and apply each of its settings.
fn validate(host: &Host, runtime: &Runtime, work: &Path, main: &str) -> Result<(), RuntimeError> {
    let validation: String = main
        .split('\n')
        .map(|line| match line.strip_prefix("include = ") {
            Some(path) if path.ends_with("/generated/pool.conf") => {
                format!("include = {}", work.join("pool.conf").display())
            }
            Some(path) if path.ends_with("/local.conf") => {
                format!("include = {}", work.join("local.conf").display())
            }
            _ => line.to_string(),
        })
        .collect::<Vec<_>>()
        .join("\n");
    let validation_path = work.join("php-fpm.validate.conf");
    fs::write(&validation_path, validation)
        .map_err(|_| invalid("the validation file is written"))?;
    let scan = format!(
        "{}:{}",
        host.php_conf
            .join(&runtime.version)
            .join("fpm/conf.d")
            .display(),
        work.display()
    );
    let fpm = |arguments: &[&std::ffi::OsStr]| {
        Process::new(host.fpm(&runtime.version))
            .args(arguments)
            .env("PHP_INI_SCAN_DIR", &scan)
            .stdin(Stdio::null())
            .stderr(Stdio::null())
            .output()
            .ok()
            .filter(|output| output.status.success())
    };
    check(
        fpm(&["-y".as_ref(), validation_path.as_os_str(), "-t".as_ref()]).is_some(),
        "the PHP-FPM configuration is valid",
    )?;
    let information =
        fpm(&["-i".as_ref()]).ok_or_else(|| invalid("PHP-FPM prints its settings"))?;
    let information = String::from_utf8_lossy(&information.stdout).into_owned();
    let master = work.join("master.ini");
    check(
        information.contains(&master.display().to_string()),
        "PHP-FPM loads the generated master.ini",
    )?;
    let ini = fs::read_to_string(&master).map_err(|_| invalid("master.ini is readable"))?;
    for line in ini.lines() {
        if line.is_empty() || line.starts_with(';') {
            continue;
        }
        let (key, value) = line.split_once('=').unwrap_or((line, line));
        let key = key.trim_end_matches(' ');
        let mut value = value.trim_start_matches(' ').to_string();
        if key == "opcache.validate_timestamps" && value == "0" {
            value = "Off".into();
        }
        let expected = format!("{key} => {value} => {value}");
        if !information.lines().any(|line| line == expected) {
            return Err(invalid(&format!(
                "PHP {} dedicated fpm does not apply {key} = {value} from {}.",
                runtime.version,
                master.display()
            )));
        }
    }
    Ok(())
}

const GENERATED: [&str; 4] = ["php-fpm.conf", "pool.conf", "master.ini", "local.sha256"];

/// The state before a publication, to put back when it fails.
struct Before {
    generated: Option<PathBuf>,
    unit: Option<PathBuf>,
    active: bool,
    enabled: bool,
}

fn restore(host: &Host, runtime: &Runtime, before: &Before) {
    host.quiet(&["disable", "--now", &runtime.service]);
    let _ = fs::remove_dir_all(&runtime.generated);
    if let Some(backup) = &before.generated {
        let _ = copy_flat(backup, &runtime.generated);
    }
    let _ = fs::remove_file(&runtime.unit);
    if let Some(backup) = &before.unit {
        let _ = fs::copy(backup, &runtime.unit);
    }
    host.quiet(&["daemon-reload"]);
    if before.enabled {
        host.quiet(&["enable", &runtime.service]);
    }
    if before.active {
        host.quiet(&["start", &runtime.service]);
    }
}

pub fn converge(input: &ConvergeInput, host: &Host) -> Result<Converged, RuntimeError> {
    let runtime = Runtime::new(host, &input.user, &input.version, &input.marker)?;
    if input.mode == Mode::Monitor
        && (!is_regular_file(&runtime.marker) || !is_real_directory(&runtime.generated))
    {
        return Ok(Converged { published: false });
    }
    let runtime = runtime.with_home(&input.home)?;
    converge_shared_directory(host)?;

    let mut created = false;
    if !exists(&runtime.directory) {
        let parent = host.etc.join("php-fpm");
        if !exists(&parent) {
            fs::create_dir(&parent)
                .and_then(|()| fs::set_permissions(&parent, fs::Permissions::from_mode(0o755)))
                .map_err(|_| invalid("the runtime directory is created"))?;
        }
        fs::create_dir(&runtime.directory)
            .and_then(|()| {
                fs::set_permissions(&runtime.directory, fs::Permissions::from_mode(0o755))
            })
            .map_err(|_| invalid("the runtime directory is created"))?;
        created = true;
    }
    check(
        is_real_directory(&runtime.directory) && host.root_owned(&runtime.directory, 0o755),
        "the runtime directory is root-owned",
    )?;

    if exists(&runtime.marker) {
        check(
            runtime.marker_matches(host),
            "the runtime marker is this Instance's",
        )?;
    } else {
        if !created {
            check(
                !exists(&runtime.generated),
                "no generated files exist without a marker",
            )?;
            check(
                !exists(&runtime.local),
                "no local tuning exists without a marker",
            )?;
        }
        check(!exists(&runtime.unit), "no unit exists without a marker")?;
        check(
            !exists(&runtime.socket),
            "no socket exists without a marker",
        )?;
        publish(
            &runtime.directory,
            &runtime.marker,
            &runtime.expected_marker,
            0o644,
        )?;
    }

    let configuration = &input.configuration;
    if exists(&runtime.local) {
        check(
            is_regular_file(&runtime.local) && host.root_owned(&runtime.local, 0o644),
            "local.conf is root-owned",
        )?;
    } else {
        publish(
            &runtime.directory,
            &runtime.local,
            configuration.local_defaults.as_bytes(),
            0o644,
        )?;
    }
    let local = fs::read(&runtime.local).map_err(|_| invalid("local.conf is readable"))?;
    let local_before = sha256(&local);

    let pool = select_application_pool(input)?;
    let pool_text = String::from_utf8_lossy(&pool).into_owned();
    for directory in served_values(&pool_text, "chdir") {
        let resolved = realpath(Path::new(directory))
            .ok_or_else(|| invalid("a served application directory exists"))?;
        check(
            starts_inside(&resolved, &input.home.join("releases")) && resolved.is_dir(),
            "a served application directory is in a release",
        )?;
    }
    let monitoring = pool_text
        .lines()
        .any(|line| line == "pm.status_path = /orbit-fpm-status");
    check(
        local_tuning_allowed(
            &String::from_utf8_lossy(&local),
            &format!("orbit-{}", runtime.user),
            monitoring,
        ),
        "local.conf only tunes the pool",
    )?;

    let work = Work::new(host, ".orbit-php-runtime")?;
    let local_hash = format!("{local_before}\n");
    let desired: [(&str, &[u8]); 4] = [
        ("php-fpm.conf", configuration.main.as_bytes()),
        ("pool.conf", &pool),
        ("master.ini", configuration.master_ini.as_bytes()),
        ("local.sha256", local_hash.as_bytes()),
    ];
    for (name, content) in desired {
        fs::write(work.0.join(name), content).map_err(|_| invalid("a work file is written"))?;
    }
    fs::write(work.0.join("local.conf"), &local).map_err(|_| invalid("a work file is written"))?;
    validate(host, &runtime, &work.0, &configuration.main)?;

    let mut before = Before {
        generated: None,
        unit: None,
        active: false,
        enabled: false,
    };
    if exists(&runtime.generated) {
        check(
            is_real_directory(&runtime.generated) && host.root_owned(&runtime.generated, 0o755),
            "the generated directory is root-owned",
        )?;
        cleanup_monitoring_candidate(host, &runtime)?;
        let unexpected = fs::read_dir(&runtime.generated)
            .map_err(|_| invalid("the generated directory is readable"))?
            .flatten()
            .any(|entry| !GENERATED.contains(&entry.file_name().to_string_lossy().as_ref()));
        check(
            !unexpected,
            "the generated directory holds only generated files",
        )?;
        for name in ["php-fpm.conf", "pool.conf", "local.sha256"] {
            let path = runtime.generated.join(name);
            check(
                is_regular_file(&path) && host.root_owned(&path, 0o644),
                "a generated file is root-owned",
            )?;
        }
        let master = runtime.generated.join("master.ini");
        if exists(&master) {
            check(
                is_regular_file(&master) && host.root_owned(&master, 0o644),
                "master.ini is root-owned",
            )?;
        }
        let backup = work.0.join("generated.backup");
        copy_flat(&runtime.generated, &backup)?;
        before.generated = Some(backup);
    }
    if exists(&runtime.unit) {
        check(
            is_regular_file(&runtime.unit) && host.root_owned(&runtime.unit, 0o644),
            "the unit is root-owned",
        )?;
        let backup = work.0.join("unit.backup");
        fs::copy(&runtime.unit, &backup).map_err(|_| invalid("the unit backup is copied"))?;
        before.unit = Some(backup);
    }
    before.active = host.active(&runtime.service);
    before.enabled = host.systemctl(&["is-enabled", "--quiet", &runtime.service]);
    if input.mode == Mode::Converge && exists(&runtime.receipt(METRICS_PENDING)) {
        converge_monitoring_pool(host, &runtime, &pool, before.active, Journal::Resolve)?;
    }
    if exists(&runtime.socket) {
        check(
            host.served_socket(&runtime.socket, runtime.uid),
            "the socket belongs to the pool",
        )?;
    }

    let mut changed = desired.iter().any(|(name, content)| {
        fs::read(runtime.generated.join(name)).map_or(true, |current| current != *content)
    }) || fs::read(&runtime.unit)
        .map_or(true, |current| current != configuration.unit.as_bytes());
    check(
        guard_receipt(host, &runtime.receipt(PENDING))
            && guard_receipt(host, &runtime.receipt(APPLIED)),
        "the runtime receipts are root-owned",
    )?;
    if !generation_applied(host, &runtime, &pool) {
        changed = true;
    }

    if input.mode == Mode::Monitor {
        check(
            !exists(&runtime.receipt(PENDING)),
            "no runtime generation is pending",
        )?;
        if before.active {
            check(
                generation_applied(host, &runtime, &pool),
                "the running master loaded this generation",
            )?;
        }
        converge_monitoring_pool(host, &runtime, &pool, before.active, Journal::Apply)?;
        if before.active {
            confirm_generation(host, &runtime, &pool)?;
        }
        return Ok(Converged { published: false });
    }

    if before.active && !changed {
        check(
            host.systemctl(&["enable", &runtime.service]),
            "the service is enabled",
        )?;
        verify(host, &runtime, &pool_text, &local_before)?;
        check(
            generation_applied(host, &runtime, &pool),
            "the running master loaded this generation",
        )?;
        return Ok(Converged { published: false });
    }

    // From here a failure puts the files and the service back as they were.
    begin_generation(host, &runtime, &pool)?;
    let published = (|| {
        if !exists(&runtime.generated) {
            fs::create_dir(&runtime.generated)
                .and_then(|()| {
                    fs::set_permissions(&runtime.generated, fs::Permissions::from_mode(0o755))
                })
                .map_err(|_| invalid("the generated directory is created"))?;
        }
        for (name, content) in desired {
            publish(
                &runtime.generated,
                &runtime.generated.join(name),
                content,
                0o644,
            )?;
        }
        publish(
            &host.units,
            &runtime.unit,
            configuration.unit.as_bytes(),
            0o644,
        )?;
        sync(&runtime.generated)?;
        sync(&host.units)?;
        check(
            host.systemctl(&["daemon-reload"]),
            "systemd reloads its units",
        )?;
        if before.active {
            check(
                host.systemctl(&["enable", &runtime.service]),
                "the service is enabled",
            )?;
            check(
                host.systemctl(&["restart", &runtime.service]),
                "the service restarts",
            )?;
        } else {
            check(
                host.systemctl(&["enable", "--now", &runtime.service]),
                "the service starts",
            )?;
        }
        verify(host, &runtime, &pool_text, &local_before)?;
        confirm_generation(host, &runtime, &pool)
    })();
    if let Err(error) = published {
        restore(host, &runtime, &before);
        return Err(error);
    }
    Ok(Converged { published: true })
}

/// The master runs the version's binary, its sockets belong to the pool, and `local.conf` did not change.
fn verify(
    host: &Host,
    runtime: &Runtime,
    pool: &str,
    local_before: &str,
) -> Result<(), RuntimeError> {
    check(host.active(&runtime.service), "the service is active")?;
    let pid = host
        .main_pid(&runtime.service)
        .ok_or_else(|| invalid("the service has a master"))?;
    check(
        host.runs_fpm(pid, &runtime.version),
        "the master runs the version's PHP-FPM",
    )?;
    check(
        host.served_socket(&runtime.socket, runtime.uid),
        "the socket belongs to the pool",
    )?;
    for socket in served_values(pool, "listen") {
        check(
            host.served_socket(Path::new(socket), runtime.uid),
            "a served socket belongs to the pool",
        )?;
    }
    check(
        file_hash(&runtime.local).as_deref() == Some(local_before),
        "local.conf did not change",
    )?;
    check(
        host.main_pid(&runtime.service) == Some(pid),
        "the master did not change",
    )
}

/// `/etc/orbit` is a root-owned 0711 directory, so each runtime below it can be reached but not listed.
fn converge_shared_directory(host: &Host) -> Result<(), RuntimeError> {
    let directory = &host.etc;
    let fail = || invalid("/etc/orbit is a root-owned directory");
    if exists(directory) {
        check(
            is_real_directory(directory)
                && fs::metadata(directory).is_ok_and(|metadata| {
                    metadata.uid() == host.root_uid && metadata.gid() == host.root_gid
                }),
            "/etc/orbit is a root-owned directory",
        )?;
    } else {
        fs::create_dir(directory).map_err(|_| fail())?;
    }
    fs::set_permissions(directory, fs::Permissions::from_mode(0o711)).map_err(|_| fail())?;
    check(
        host.root_owned(directory, 0o711),
        "/etc/orbit is a root-owned directory",
    )
}

pub struct ConvergeRuntime;

impl Command for ConvergeRuntime {
    const NAME: &'static str = "php.converge-production-runtime";
    const SUMMARY: &'static str =
        "Publish a production Instance's dedicated PHP-FPM service, or only a monitoring change of its pool.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = ConvergeInput;
    type Output = Converged;
    type Error = RuntimeError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &ConvergeInput) -> Option<String> {
        Some(format!("production-php:{}", input.user))
    }

    fn run(input: ConvergeInput, _: &Context) -> Result<Converged, RuntimeError> {
        converge(&input, &Host::system()?)
    }
}

// ---- remove ------------------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveInput {
    pub user: String,
    pub version: String,
    pub marker: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Removed {
    /// Whether a runtime existed.
    pub removed: bool,
}

/// Stops and removes the service and its generated files, and keeps the operator's `local.conf`.
pub fn remove(input: &RemoveInput, host: &Host) -> Result<Removed, RuntimeError> {
    let runtime = Runtime::new(host, &input.user, &input.version, &input.marker)?;
    if !exists(&runtime.marker) {
        check(
            !exists(&runtime.generated),
            "no generated files exist without a marker",
        )?;
        check(!exists(&runtime.unit), "no unit exists without a marker")?;
        check(
            !exists(&runtime.socket),
            "no socket exists without a marker",
        )?;
        return Ok(Removed { removed: false });
    }
    check(
        runtime.marker_matches(host),
        "the runtime marker is this Instance's",
    )?;

    if !host.systemctl(&["disable", "--now", &runtime.service]) {
        check(!exists(&runtime.unit), "the service stops")?;
        host.quiet(&["stop", &runtime.service]);
    }
    check(!host.active(&runtime.service), "the service is stopped")?;
    check(
        host.main_pid(&runtime.service).unwrap_or(0) == 0,
        "the service has no master",
    )?;
    remove_file(&runtime.receipt(METRICS_PENDING))?;
    remove_file(&runtime.receipt(METRICS_BACKUP))?;
    for name in [PENDING, APPLIED] {
        let path = runtime.receipt(name);
        if exists(&path) {
            check(
                is_regular_file(&path) && host.root_owned(&path, 0o600),
                "the runtime receipt is root-owned",
            )?;
            remove_file(&path)?;
        }
    }
    remove_tree(&runtime.generated)?;
    remove_file(&runtime.unit)?;
    check(
        host.systemctl(&["daemon-reload"]),
        "systemd reloads its units",
    )?;
    host.quiet(&["reset-failed", &runtime.service]);
    if exists(&runtime.socket) {
        let (uid, _, _) =
            identity::account(&runtime.user).map_err(|_| invalid("the production user exists"))?;
        check(
            fs::symlink_metadata(&runtime.socket).is_ok_and(|metadata| {
                metadata.file_type().is_socket()
                    && metadata.uid() == uid
                    && metadata.gid() == host.caddy_gid
            }),
            "the socket belongs to the pool",
        )?;
        remove_file(&runtime.socket)?;
    }
    if exists(&runtime.local) {
        check(
            is_regular_file(&runtime.local),
            "local.conf is a regular file",
        )?;
    }
    remove_file(&runtime.marker)?;
    check(
        is_real_directory(&runtime.directory),
        "the runtime directory remains",
    )?;
    Ok(Removed { removed: true })
}

pub struct RemoveRuntime;

impl Command for RemoveRuntime {
    const NAME: &'static str = "php.remove-production-runtime";
    const SUMMARY: &'static str =
        "Stop and remove a production Instance's dedicated PHP-FPM service, keeping its local tuning.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = RemoveInput;
    type Output = Removed;
    type Error = RuntimeError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &RemoveInput) -> Option<String> {
        Some(format!("production-php:{}", input.user))
    }

    fn run(input: RemoveInput, _: &Context) -> Result<Removed, RuntimeError> {
        remove(&input, &Host::system()?)
    }
}

// ---- inspect the pool --------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectPoolInput {
    pub user: String,
    pub version: String,
    pub marker: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Pool {
    pub pool: String,
    /// The hash of the main configuration, the pool, and `local.conf`: it changes with any of them.
    pub fingerprint: String,
}

/// Reads the generated pool of a runtime whose marker is the expected one. Every path up to `/` is root-owned and
/// not writable by others.
pub fn inspect_pool(input: &InspectPoolInput, host: &Host) -> Result<Pool, RuntimeError> {
    let runtime = Runtime::new(host, &input.user, &input.version, &input.marker)?;
    let read = |path: &Path| {
        for parent in path.ancestors() {
            let trusted = fs::symlink_metadata(parent).is_ok_and(|metadata| {
                !metadata.file_type().is_symlink()
                    && metadata.uid() == host.root_uid
                    && metadata.mode() & 0o022 == 0
            });
            if !trusted {
                return Err(invalid("the runtime path is root-owned"));
            }
            if parent == host.etc.parent().unwrap_or(Path::new("/")) {
                break;
            }
        }
        fs::read_to_string(path).map_err(|_| invalid("a runtime file is readable"))
    };
    check(
        read(&runtime.marker)?.as_bytes() == runtime.expected_marker,
        "the runtime marker is this Instance's",
    )?;
    let main = read(&runtime.generated.join("php-fpm.conf"))?;
    let pool = read(&runtime.generated.join("pool.conf"))?;
    let local = read(&runtime.local)?;
    let fingerprint = sha256(format!("{main}{pool}{local}").as_bytes());
    Ok(Pool { pool, fingerprint })
}

pub struct InspectPool;

impl Command for InspectPool {
    const NAME: &'static str = "php.inspect-production-pool";
    const SUMMARY: &'static str =
        "Read a production Instance's generated PHP-FPM pool and its fingerprint.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectPoolInput;
    type Output = Pool;
    type Error = RuntimeError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: InspectPoolInput, _: &Context) -> Result<Pool, RuntimeError> {
        inspect_pool(&input, &Host::system()?)
    }
}

// ---- refresh the cache -------------------------------------------------------------------------------------------

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RefreshCacheInput {
    pub user: String,
    pub home: PathBuf,
    pub version: String,
    pub marker: String,
    /// How long the reset may take, from 1 to 30 seconds.
    pub deadline_seconds: u64,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Refreshed {
    pub complete: bool,
}

/// The probe runs in the pool's own worker and answers with its identity and OPcache state, or resets OPcache.
const PROBE: &str = r#"<?php

declare(strict_types=1);

header('Content-Type: application/json');

$status = opcache_get_status(false);
$statistics = is_array($status) ? ($status['opcache_statistics'] ?? null) : null;
$processStatus = file_get_contents('/proc/self/status');

if (! is_array($status) || ! is_array($statistics)) {
    echo json_encode(['error' => 'opcache-unavailable'], JSON_THROW_ON_ERROR);
    return;
}

if (! is_string($processStatus)) {
    echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);
    return;
}

if (preg_match('/^Uid:\s+([0-9]+)/m', $processStatus, $uidMatch) !== 1) {
    echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);
    return;
}

$identity = [
    'sapi' => PHP_SAPI,
    'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
    'uid' => (int) $uidMatch[1],
    'worker_pid' => getmypid(),
    'parent_pid' => posix_getppid(),
];
$observation = [
    'opcache_enabled' => $status['opcache_enabled'] ?? null,
    'restart_pending' => $status['restart_pending'] ?? null,
    'restart_in_progress' => $status['restart_in_progress'] ?? null,
    'manual_restarts' => $statistics['manual_restarts'] ?? null,
    'last_restart_time' => $statistics['last_restart_time'] ?? null,
];

if (($_SERVER['ORBIT_OPCACHE_ACTION'] ?? '') === 'reset') {
    echo json_encode([
        'identity' => $identity,
        'observation' => $observation,
        'accepted' => opcache_reset(),
    ], JSON_THROW_ON_ERROR);
    return;
}

echo json_encode([
    'identity' => $identity,
    'observation' => $observation,
], JSON_THROW_ON_ERROR);
"#;

const FCGI_BEGIN_REQUEST: u8 = 1;
const FCGI_END_REQUEST: u8 = 3;
const FCGI_PARAMS: u8 = 4;
const FCGI_STDIN: u8 = 5;
const FCGI_STDOUT: u8 = 6;
const FCGI_STDERR: u8 = 7;
const FCGI_RESPONDER: u16 = 1;

fn record(kind: u8, content: &[u8]) -> Vec<u8> {
    let padding = (8 - content.len() % 8) % 8;
    let length = u16::try_from(content.len()).unwrap_or(u16::MAX);
    let mut bytes = vec![1, kind, 0, 1];
    bytes.extend_from_slice(&length.to_be_bytes());
    bytes.extend_from_slice(&[u8::try_from(padding).unwrap_or(0), 0]);
    bytes.extend_from_slice(content);
    bytes.extend(std::iter::repeat_n(0, padding));
    bytes
}

fn encoded_length(length: usize) -> Vec<u8> {
    if length < 128 {
        vec![u8::try_from(length).unwrap_or(0)]
    } else {
        (u32::try_from(length).unwrap_or(0) | 0x8000_0000)
            .to_be_bytes()
            .to_vec()
    }
}

fn parameters(values: &[(&str, &str)]) -> Vec<u8> {
    let mut encoded = Vec::new();
    for (name, value) in values {
        encoded.extend(encoded_length(name.len()));
        encoded.extend(encoded_length(value.len()));
        encoded.extend_from_slice(name.as_bytes());
        encoded.extend_from_slice(value.as_bytes());
    }
    encoded
}

/// One FastCGI request to the pool's socket. A timeout after the reset was accepted means the reset is pending.
fn fastcgi(
    socket: &Path,
    probe: &Path,
    home: &Path,
    action: &str,
    deadline: Instant,
    reset_accepted: bool,
) -> Result<serde_json::Map<String, serde_json::Value>, RuntimeError> {
    let late = || {
        if reset_accepted {
            RuntimeError::CacheResetPending {}
        } else {
            RuntimeError::CacheProtocol {}
        }
    };
    let remaining = deadline
        .checked_duration_since(Instant::now())
        .filter(|left| !left.is_zero())
        .ok_or_else(late)?;
    let probe_path = probe.display().to_string();
    let home_path = home.display().to_string();
    let values = [
        ("GATEWAY_INTERFACE", "CGI/1.1"),
        ("REQUEST_METHOD", "GET"),
        ("SCRIPT_FILENAME", probe_path.as_str()),
        ("SCRIPT_NAME", "/.orbit-opcache-refresh.php"),
        ("QUERY_STRING", ""),
        ("REQUEST_URI", "/.orbit-opcache-refresh.php"),
        ("DOCUMENT_ROOT", home_path.as_str()),
        ("SERVER_PROTOCOL", "HTTP/1.1"),
        ("SERVER_NAME", "localhost"),
        ("SERVER_PORT", "80"),
        ("REMOTE_ADDR", "127.0.0.1"),
        ("REMOTE_PORT", "1"),
        ("SERVER_SOFTWARE", "Orbit"),
        ("REDIRECT_STATUS", "200"),
        ("ORBIT_OPCACHE_ACTION", action),
    ];
    let mut begin = FCGI_RESPONDER.to_be_bytes().to_vec();
    begin.extend_from_slice(&[0; 6]);
    let mut payload = record(FCGI_BEGIN_REQUEST, &begin);
    payload.extend(record(FCGI_PARAMS, &parameters(&values)));
    payload.extend(record(FCGI_PARAMS, b""));
    payload.extend(record(FCGI_STDIN, b""));

    let unavailable = |error: std::io::Error| {
        use std::io::ErrorKind::{ConnectionRefused, NotFound, PermissionDenied};
        if matches!(
            error.kind(),
            NotFound | ConnectionRefused | PermissionDenied
        ) || error.raw_os_error() == Some(libc::ENOTSOCK)
        {
            RuntimeError::CacheSocketUnavailable {}
        } else {
            RuntimeError::CacheProtocol {}
        }
    };
    let mut connection = UnixStream::connect(socket).map_err(unavailable)?;
    let timed = |error: std::io::Error| {
        if matches!(
            error.kind(),
            std::io::ErrorKind::WouldBlock | std::io::ErrorKind::TimedOut
        ) {
            late()
        } else {
            RuntimeError::CacheProtocol {}
        }
    };
    connection
        .set_write_timeout(Some(remaining.max(Duration::from_millis(50))))
        .and_then(|()| connection.write_all(&payload))
        .map_err(timed)?;

    let (mut output, mut errors, mut ended) = (Vec::new(), Vec::new(), false);
    while !ended {
        let remaining = deadline
            .checked_duration_since(Instant::now())
            .filter(|left| !left.is_zero())
            .ok_or_else(late)?;
        connection
            .set_read_timeout(Some(remaining.max(Duration::from_millis(50))))
            .map_err(timed)?;
        let mut header = [0u8; 8];
        connection.read_exact(&mut header).map_err(timed)?;
        if header[0] != 1 || u16::from_be_bytes([header[2], header[3]]) != 1 {
            return Err(RuntimeError::CacheProtocol {});
        }
        let length = usize::from(u16::from_be_bytes([header[4], header[5]]));
        let mut content = vec![0u8; length];
        connection.read_exact(&mut content).map_err(timed)?;
        let mut padding = vec![0u8; usize::from(header[6])];
        connection.read_exact(&mut padding).map_err(timed)?;
        match header[1] {
            FCGI_STDOUT => output.extend(content),
            FCGI_STDERR => errors.extend(content),
            FCGI_END_REQUEST => {
                if length != 8 || content[..5] != [0, 0, 0, 0, 0] {
                    return Err(RuntimeError::CacheProtocol {});
                }
                ended = true;
            }
            _ => {}
        }
        if output.len() + errors.len() > 65_536 {
            return Err(RuntimeError::CacheProtocol {});
        }
    }
    if !errors.is_empty() {
        return Err(RuntimeError::CacheProtocol {});
    }
    let split = output
        .windows(4)
        .position(|window| window == b"\r\n\r\n")
        .ok_or(RuntimeError::CacheProtocol {})?;
    let headers =
        std::str::from_utf8(&output[..split]).map_err(|_| RuntimeError::CacheProtocol {})?;
    let lines: Vec<&str> = headers.split("\r\n").collect();
    if !lines
        .iter()
        .any(|line| line.to_lowercase() == "content-type: application/json")
    {
        return Err(RuntimeError::CacheProtocol {});
    }
    let status: Vec<&&str> = lines
        .iter()
        .filter(|line| line.to_lowercase().starts_with("status:"))
        .collect();
    if !status.is_empty() && status != [&"Status: 200 OK"] {
        return Err(RuntimeError::CacheProtocol {});
    }
    match serde_json::from_slice(&output[split + 4..]) {
        Ok(serde_json::Value::Object(result)) => Ok(result),
        _ => Err(RuntimeError::CacheProtocol {}),
    }
}

#[derive(PartialEq, Clone, Copy, Debug)]
struct Observation {
    enabled: bool,
    restart_pending: bool,
    restart_in_progress: bool,
    manual_restarts: i64,
    last_restart_time: i64,
}

/// Reads one probe answer: it must come from a worker of the expected master, as the production user.
fn observation(
    result: &serde_json::Map<String, serde_json::Value>,
    version: &str,
    uid: u32,
    master: u32,
    reset_accepted: bool,
) -> Result<Observation, RuntimeError> {
    use serde_json::{json, Value};
    let error = result.get("error").and_then(Value::as_str);
    if result.len() == 1 && error == Some("opcache-unavailable") {
        return Err(RuntimeError::CacheResetRejected {});
    }
    if result.len() == 1 && error == Some("identity-unavailable") {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }
    let (Some(Value::Object(identity)), Some(Value::Object(observed))) =
        (result.get("identity"), result.get("observation"))
    else {
        return Err(RuntimeError::CacheProtocol {});
    };
    let worker = identity.get("worker_pid").cloned().unwrap_or(Value::Null);
    let expected = json!({"sapi": "fpm-fcgi", "version": version, "uid": uid, "worker_pid": worker, "parent_pid": master});
    if Value::Object(identity.clone()) != expected
        || !worker.as_i64().is_some_and(|pid| pid > 1)
        || !worker.is_i64()
    {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }
    let keys = [
        "opcache_enabled",
        "restart_pending",
        "restart_in_progress",
        "manual_restarts",
        "last_restart_time",
    ];
    if observed.len() != keys.len() || !keys.iter().all(|key| observed.contains_key(*key)) {
        return Err(RuntimeError::CacheProtocol {});
    }
    let flag = |key: &str| {
        observed[key]
            .as_bool()
            .ok_or(RuntimeError::CacheProtocol {})
    };
    let count = |key: &str| {
        observed[key]
            .as_i64()
            .filter(|_| observed[key].is_i64())
            .ok_or(RuntimeError::CacheProtocol {})
    };
    let state = Observation {
        enabled: flag("opcache_enabled")?,
        restart_pending: flag("restart_pending")?,
        restart_in_progress: flag("restart_in_progress")?,
        manual_restarts: count("manual_restarts")?,
        last_restart_time: count("last_restart_time")?,
    };
    if !state.enabled && !(reset_accepted && (state.restart_pending || state.restart_in_progress)) {
        return Err(RuntimeError::CacheResetRejected {});
    }
    Ok(state)
}

/// The master is the version's FPM binary, and the same process before and after the reset.
fn master_identity(host: &Host, runtime: &Runtime) -> Result<(u32, u64), RuntimeError> {
    let association = || RuntimeError::CacheAssociationInvalid {};
    if !host.active(&runtime.service) {
        return Err(association());
    }
    let pid = host.main_pid(&runtime.service).ok_or_else(association)?;
    if !host.runs_fpm(pid, &runtime.version) {
        return Err(association());
    }
    let start = host.start_time(pid).ok_or_else(association)?;
    Ok((pid, start))
}

/// Resets OPcache in the pool's own workers and waits until a worker reports the completed reset.
pub fn refresh_cache(input: &RefreshCacheInput, host: &Host) -> Result<Refreshed, RuntimeError> {
    let runtime =
        Runtime::new(host, &input.user, &input.version, &input.marker)?.with_home(&input.home)?;
    check(
        (1..=30).contains(&input.deadline_seconds),
        "the deadline is 1 to 30 seconds",
    )?;
    if !runtime.marker_matches(host) {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }
    if !fs::symlink_metadata(&runtime.socket).is_ok_and(|metadata| metadata.file_type().is_socket())
    {
        return Err(RuntimeError::CacheSocketUnavailable {});
    }
    if !host.served_socket(&runtime.socket, runtime.uid) {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }
    let (master, started) = master_identity(host, &runtime)?;
    // SAFETY: kill with signal 0 only checks that the process exists.
    if unsafe { libc::kill(i32::try_from(master).unwrap_or(0), 0) } != 0 {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }

    check(
        is_real_directory(&host.tmp) && host.root_owned(&host.tmp, 0o1777),
        "the probe directory's parent is /tmp",
    )?;
    let probe_directory = Work::new(host, &format!(".orbit-opcache-refresh-{}", runtime.user))?;
    fs::set_permissions(&probe_directory.0, fs::Permissions::from_mode(0o711))
        .map_err(|_| invalid("the probe directory is reachable"))?;
    let probe = probe_directory.0.join("probe.php");
    publish(&probe_directory.0, &probe, PROBE.as_bytes(), 0o444)?;

    let deadline = Instant::now() + Duration::from_secs(input.deadline_seconds);
    let ask = |action: &str, accepted: bool| {
        fastcgi(
            &runtime.socket,
            &probe,
            &runtime.home,
            action,
            deadline,
            accepted,
        )
    };
    let read = |result: &serde_json::Map<String, serde_json::Value>, accepted: bool| {
        observation(result, &runtime.version, runtime.uid, master, accepted)
    };
    let before = read(&ask("status", false)?, false)?;
    let reset = ask("reset", false)?;
    if read(&reset, false)? != before {
        return Err(RuntimeError::CacheProtocol {});
    }
    if reset.get("accepted") != Some(&serde_json::Value::Bool(true)) {
        return Err(RuntimeError::CacheResetRejected {});
    }
    loop {
        let after = read(&ask("status", true)?, true)?;
        let advanced = after.manual_restarts > before.manual_restarts
            || after.last_restart_time > before.last_restart_time;
        if advanced && !after.restart_pending && !after.restart_in_progress {
            break;
        }
        if Instant::now() >= deadline {
            return Err(RuntimeError::CacheResetPending {});
        }
        std::thread::sleep(
            Duration::from_millis(50).min(deadline.saturating_duration_since(Instant::now())),
        );
    }

    let (after, started_after) = master_identity(host, &runtime)?;
    if after != master
        || started_after != started
        || !host.served_socket(&runtime.socket, runtime.uid)
    {
        return Err(RuntimeError::CacheAssociationInvalid {});
    }
    Ok(Refreshed { complete: true })
}

pub struct RefreshCache;

impl Command for RefreshCache {
    const NAME: &'static str = "php.refresh-production-cache";
    const SUMMARY: &'static str =
        "Reset OPcache in a production Instance's own PHP-FPM workers and confirm it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = RefreshCacheInput;
    type Output = Refreshed;
    type Error = RuntimeError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn target(input: &RefreshCacheInput) -> Option<String> {
        Some(format!("production-php:{}", input.user))
    }

    fn run(input: RefreshCacheInput, _: &Context) -> Result<Refreshed, RuntimeError> {
        refresh_cache(&input, &Host::system()?)
    }
}

#[cfg(test)]
#[path = "php_runtime_tests.rs"]
mod tests;
