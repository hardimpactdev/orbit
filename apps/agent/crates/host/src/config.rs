//! `config.apply`: one set of configuration files that share a lock (docs/reference/executor.md#primitives-that-span-more-than-one-file-or-call).
//!
//! Lifted from `route.withdraw_site` (its guard, restore and rollback) and `dns.publish_records` (its lock and restore). The
//! Gateway renders every whole file and names, for each, the digest of the live file it rendered from; the lock path, the
//! validators and the units come from the input too. Under the lock each live file must hold that digest or already the
//! desired bytes; anything else refuses before any write. The changed files are written, every validator runs, and every
//! unit reloads or restarts on every run, so a repeat after a crash still loads the files. A failed validation or load
//! puts every file back to its exact prior bytes, owner and mode, or removes it when it did not exist, and a failed load
//! loads the old files again. The prior state is recorded in the executor's recovery folder before the first write, so a run
//! that stopped is undone by the next run under the same lock.

use std::path::PathBuf;
use std::sync::LazyLock;

use regex::Regex;
use std::os::unix::fs::PermissionsExt as _;

use serde::{Deserialize, Serialize};

use crate::fs::{self, Previous};
use crate::{absolute, invalid, lock, parse, sha256, strings, BlobRef, Failure, Ids, Owner, System, DIGEST, NAME};

static VERSION: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9]\.[0-9]{1,2}$").expect("a valid pattern"));
static SUFFIX: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\.[A-Za-z0-9_-]{1,32}$").expect("a valid pattern"));
static UNIT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9@_.:-]{1,200}\.service$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    /// The lock file every writer of these files takes.
    lock: String,
    /// The folder the executor keeps this lock's recovery record in, outside every managed path.
    recovery_dir: String,
    files: Vec<File>,
    #[serde(default)]
    validators: Vec<Validator>,
    #[serde(default)]
    units: Vec<Unit>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct File {
    path: String,
    content: BlobRef,
    /// The digest of the live file the Gateway rendered from; absent when it rendered a new file.
    from_sha256: Option<String>,
    owner: Owner,
}

/// A validator from the fixed list. Its command is built here; no part of it is a free string from the order.
#[derive(Debug, Deserialize)]
#[serde(tag = "kind", rename_all = "kebab-case", deny_unknown_fields)]
enum Validator {
    /// `caddy validate` of one Caddyfile, as its service user.
    Caddy { config: String, user: String },
    /// `php-fpm<version> -t`, with an optional main configuration and ini scan folders.
    PhpFpm {
        version: String,
        #[serde(default)]
        fpm_config: Option<String>,
        #[serde(default)]
        ini_scan_dir: Vec<String>,
    },
    /// `dnsmasq --test` of the configuration the service loads: its main file and its configuration folder, with the
    /// suffixes the service skips there.
    Dnsmasq {
        conf_file: String,
        conf_dir: String,
        #[serde(default)]
        skip_suffixes: Vec<String>,
    },
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Unit {
    unit: String,
    load: Load,
}

#[derive(Debug, Clone, Copy, Deserialize)]
#[serde(rename_all = "snake_case")]
enum Load {
    Reload,
    Restart,
}

impl Validator {
    fn valid(&self) -> bool {
        match self {
            Self::Caddy { config, user } => absolute(config) && NAME.is_match(user),
            Self::PhpFpm { version, fpm_config, ini_scan_dir } => {
                VERSION.is_match(version) && fpm_config.as_deref().is_none_or(absolute) && ini_scan_dir.iter().all(|dir| absolute(dir) && !dir.contains(':'))
            }
            Self::Dnsmasq { conf_file, conf_dir, skip_suffixes } => {
                absolute(conf_file) && absolute(conf_dir) && !conf_dir.contains(',') && !conf_file.contains(',') && skip_suffixes.iter().all(|suffix| SUFFIX.is_match(suffix))
            }
        }
    }

    fn argv(&self) -> Vec<String> {
        match self {
            Self::Caddy { config, user } => {
                let mut argv = strings(&["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/caddy", "validate", "--config", config]);
                argv.extend(strings(&["--adapter", "caddyfile"]));
                argv
            }
            Self::PhpFpm { version, fpm_config, ini_scan_dir } => {
                let mut argv = Vec::new();
                if !ini_scan_dir.is_empty() {
                    argv.push("/usr/bin/env".to_owned());
                    argv.push(format!("PHP_INI_SCAN_DIR={}", ini_scan_dir.join(":")));
                }
                argv.push(format!("/usr/sbin/php-fpm{version}"));
                argv.push("-t".to_owned());
                if let Some(config) = fpm_config {
                    argv.extend(strings(&["--fpm-config", config]));
                }
                argv
            }
            Self::Dnsmasq { conf_file, conf_dir, skip_suffixes } => {
                let folder = std::iter::once(conf_dir.as_str()).chain(skip_suffixes.iter().map(String::as_str)).collect::<Vec<_>>().join(",");
                vec!["/usr/sbin/dnsmasq".to_owned(), "--test".to_owned(), format!("--conf-file={conf_file}"), format!("--conf-dir={folder}")]
            }
        }
    }
}

/// Applies the set and answers how many files changed: `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, including a validator outside the fixed list;
/// `host.content_missing` for a blob the executor did not read; `host.lock_busy`, `host.lock_invalid` or
/// `host.lock_failed` for the lock; `owned_revision_conflict` when a live file holds neither its rendered-from digest nor
/// the desired bytes; `config.write_failed`, `config.validate_failed` or `config.load_failed`, each after every file is put
/// back.
pub fn config_apply(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    // The order's identity: the same input is the same order, so a retry of a step finds its own record.
    let order = sha256(&serde_json::to_vec(input).map_err(|error| invalid(&error.to_string()))?);
    let input: Input = parse(input)?;
    validate(&input)?;
    let mut desired = Vec::new();
    for file in &input.files {
        desired.push((file, file.content.bytes(system)?, file.owner.ids(system, "config.write_failed")?));
    }
    // The same check through links: a folder reached by two names holds the same files.
    let mut places: Vec<PathBuf> = input.files.iter().map(|file| system.path(&file.path)).chain([system.path(&input.lock)]).map(|path| resolved(&path)).collect();
    let named = places.len();
    places.sort_unstable();
    places.dedup();
    if places.len() != named {
        return Err(invalid("two names of one file, or of the lock"));
    }
    let _lock = lock::exclusive(system, &input.lock, lock::WAIT)?;

    // A run under this lock that stopped before its end left its record. A replay of that same order restores the prior
    // state and loads it again in the units the record reached; any other order refuses and touches nothing.
    let record = Record { folder: system.path(&input.recovery_dir).join(sha256(input.lock.as_bytes())), order };
    if let Some(pending) = record.pending()? {
        if pending.order != record.order {
            return Err(Failure::new("config.recovery_pending", format!("{} holds the record of another order", input.lock)));
        }
        record.recover(system, &pending, &input.units)?;
    }

    // Every guard holds before the first write. Each file's restore point is what it held before this run: absent, or its
    // bytes with owner and mode. Only a live file that holds neither digest refuses.
    let mut replaced: Vec<(PathBuf, Previous)> = Vec::new();
    let mut changes = 0;
    for (file, content, _) in &desired {
        let dest = system.path(&file.path);
        let live = fs::read(&dest).map_err(|error| Failure::new("config.write_failed", format!("{}: {error}", file.path)))?;
        let current = live.as_ref().map(|(bytes, _)| bytes.as_slice());
        let guarded = match (&file.from_sha256, current) {
            (None, None) => true,
            (_, Some(bytes)) if bytes == content.as_slice() => true,
            (Some(from), Some(bytes)) => sha256(bytes) == *from,
            (Some(_) | None, _) => false,
        };
        if !guarded {
            return Err(Failure::new("owned_revision_conflict", format!("{} changed outside this render", file.path)));
        }
        replaced.push((dest, Previous { content: live }));
    }
    // The prior state is on record before the first write, so a replay after a stop restores it, absence and metadata too.
    let paths: Vec<&str> = input.files.iter().map(|file| file.path.as_str()).collect();
    record.write(&paths, &replaced).map_err(|error| Failure::new("config.write_failed", format!("recovery record: {error}")))?;
    let undo = |code: &'static str, detail: String, replaced: &[(PathBuf, Previous)]| match fs::restore(replaced) {
        Ok(()) => {
            record.drop();
            Failure::new(code, detail)
        }
        // The record stays, so the next run under this lock restores again.
        Err(restore) => Failure::new("config.restore_failed", format!("{detail}; restore: {restore}")),
    };
    for ((dest, _), (file, content, ids)) in replaced.iter().zip(&desired) {
        match fs::ensure(dest, content, *ids) {
            Ok(written) => changes += u32::from(written),
            Err(error) => return Err(undo("config.write_failed", format!("{}: {error}", file.path), &replaced)),
        }
    }
    // The installed set is read back before anything validates or loads it.
    for ((dest, _), (file, content, ids)) in replaced.iter().zip(&desired) {
        if !fs::read(dest).is_ok_and(|live| live.is_some_and(|(bytes, held)| bytes == *content && held == *ids)) {
            return Err(undo("config.write_failed", format!("{} read-back", file.path), &replaced));
        }
    }
    for validator in &input.validators {
        if let Err(detail) = system.run(60, &validator.argv()) {
            return Err(undo("config.validate_failed", detail, &replaced));
        }
    }
    for (index, unit) in input.units.iter().enumerate() {
        // The record names the units reached before each one loads, so a replay loads the prior state in them again.
        if let Err(error) = record.reach(index + 1) {
            return Err(undo("config.write_failed", format!("recovery record: {error}"), &replaced));
        }
        if let Err(detail) = load(system, unit) {
            // The old files go back and load again in the units this run reached, so each runs what it ran before. The
            // record goes only once both hold, so a replay finishes a recovery that failed here.
            let mut failure = match fs::restore(&replaced) {
                Ok(()) => Failure::new("config.load_failed", detail),
                Err(restore) => return Err(Failure::new("config.restore_failed", format!("{detail}; restore: {restore}"))),
            };
            for reached in &input.units[..=index] {
                if let Err(again) = load(system, reached) {
                    failure = Failure::new("config.restore_failed", format!("{}; reload {}: {again}", failure.detail, reached.unit));
                }
            }
            if failure.code == "config.load_failed" {
                record.drop();
            }
            return Err(failure);
        }
    }
    // The set is live: its prior state is no longer the one to restore.
    record.drop();
    Ok(changes)
}

/// The prior state of one run's files under one lock, kept in the order's recovery folder with the order's identity and the
/// units it reached. Every file in it was written by this executor; nothing beside a managed file is.
struct Record {
    folder: PathBuf,
    order: String,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Listing {
    order: String,
    priors: Vec<Prior>,
    /// How many of the order's units, in order, a load was started in.
    reached: usize,
}

#[derive(Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Prior {
    path: String,
    /// `None` when the file did not exist; else its bytes are in the record folder under `index`.
    held: Option<(u32, u32, u32)>,
    index: usize,
}

impl Record {
    /// The record a stopped run left, if any.
    fn pending(&self) -> Result<Option<Listing>, Failure> {
        let failed = |detail: String| Failure::new("config.restore_failed", detail);
        match std::fs::read(self.folder.join("record.json")) {
            Ok(listing) => serde_json::from_slice(&listing).map(Some).map_err(|error| failed(error.to_string())),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                self.drop();
                Ok(None)
            }
            Err(error) => Err(failed(error.to_string())),
        }
    }

    /// Restores every file of this order's record to its prior state, loads it again in the units the record reached, then
    /// drops the record. A failure keeps the record, so the next replay tries again.
    fn recover(&self, system: &impl System, listing: &Listing, units: &[Unit]) -> Result<(), Failure> {
        let failed = |detail: String| Failure::new("config.restore_failed", detail);
        let mut replaced = Vec::new();
        for prior in &listing.priors {
            let content = match prior.held {
                Some((uid, gid, mode)) => Some((std::fs::read(self.folder.join(prior.index.to_string())).map_err(|error| failed(error.to_string()))?, Ids { uid, gid, mode })),
                None => None,
            };
            replaced.push((system.path(&prior.path), Previous { content }));
        }
        fs::restore(&replaced).map_err(failed)?;
        for unit in units.iter().take(listing.reached) {
            load(system, unit).map_err(|detail| failed(format!("reload {}: {detail}", unit.unit)))?;
        }
        self.drop();
        Ok(())
    }

    /// Writes the prior state: each file's bytes first, then the listing that makes the record count.
    fn write(&self, paths: &[&str], replaced: &[(PathBuf, Previous)]) -> std::io::Result<()> {
        self.drop();
        std::fs::create_dir_all(&self.folder)?;
        std::fs::set_permissions(&self.folder, std::fs::Permissions::from_mode(0o700))?;
        let mut priors = Vec::new();
        for (index, (path, (_, previous))) in paths.iter().zip(replaced).enumerate() {
            let held = match &previous.content {
                Some((bytes, ids)) => {
                    fs::write_new(&self.folder.join(index.to_string()), bytes, OWN)?;
                    Some((ids.uid, ids.gid, ids.mode))
                }
                None => None,
            };
            priors.push(Prior { path: (*path).to_owned(), held, index });
        }
        self.list(&Listing { order: self.order.clone(), priors, reached: 0 })
    }

    /// Records that a load starts in the first `reached` units.
    fn reach(&self, reached: usize) -> std::io::Result<()> {
        let listing: Listing = serde_json::from_slice(&std::fs::read(self.folder.join("record.json"))?).map_err(std::io::Error::other)?;
        self.list(&Listing { reached, ..listing })
    }

    fn list(&self, listing: &Listing) -> std::io::Result<()> {
        fs::write_new(&self.folder.join("record.json"), &serde_json::to_vec(listing).map_err(std::io::Error::other)?, OWN)
    }

    fn drop(&self) {
        let _ = std::fs::remove_dir_all(&self.folder);
    }
}

/// The mode of the executor's own record files.
const OWN: Ids = Ids { uid: 0, gid: 0, mode: 0o600 };

/// A path with its folder's links resolved, where the folder exists.
fn resolved(path: &std::path::Path) -> PathBuf {
    match (path.parent().and_then(|parent| std::fs::canonicalize(parent).ok()), path.file_name()) {
        (Some(parent), Some(name)) => parent.join(name),
        _ => path.to_path_buf(),
    }
}

fn load(system: &impl System, unit: &Unit) -> Result<String, String> {
    let verb = match unit.load {
        Load::Reload => "reload",
        Load::Restart => "restart",
    };
    system.run(60, &strings(&["/usr/bin/systemctl", verb, &unit.unit]))
}

fn validate(input: &Input) -> Result<(), Failure> {
    if !absolute(&input.lock) {
        return Err(invalid("lock"));
    }
    // The record folder is the executor's: no managed file or lock lies in it.
    let inside = |path: &str| path == input.recovery_dir || path.starts_with(&format!("{}/", input.recovery_dir));
    if !absolute(&input.recovery_dir) || inside(&input.lock) || input.files.iter().any(|file| inside(&file.path)) {
        return Err(invalid("recovery_dir"));
    }
    if input.files.is_empty()
        || !input.files.iter().all(|file| {
            absolute(&file.path) && file.path != input.lock && file.owner.valid() && file.from_sha256.as_ref().is_none_or(|from| DIGEST.is_match(from))
        })
    {
        return Err(invalid("files"));
    }
    let mut paths: Vec<&str> = input.files.iter().map(|file| file.path.as_str()).chain([input.lock.as_str()]).collect();
    paths.sort_unstable();
    paths.dedup();
    if paths.len() != input.files.len() + 1 {
        return Err(invalid("a file named twice, or as the lock"));
    }
    if !input.validators.iter().all(Validator::valid) {
        return Err(invalid("validators"));
    }
    if !input.units.iter().all(|unit| UNIT.is_match(&unit.unit)) {
        return Err(invalid("units"));
    }
    Ok(())
}
