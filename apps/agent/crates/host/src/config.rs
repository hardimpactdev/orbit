//! `config.apply`: one set of configuration files that share a lock (docs/reference/executor.md#primitives-that-span-more-than-one-file-or-call).
//!
//! Lifted from `route.withdraw_site` (its guard, restore and rollback) and `dns.publish_records` (its lock and restore). The
//! Gateway renders every whole file and names, for each, the digest of the live file it rendered from; the lock path, the
//! validators and the units come from the input too. Under the lock each live file must hold that digest or already the
//! desired bytes; anything else refuses before any write. The changed files are written, every validator runs, and every
//! unit reloads or restarts on every run, so a repeat after a crash still loads the files. A failed validation or load
//! puts every file back to its exact prior bytes, owner and mode, and a failed load loads the old files again.

use std::path::PathBuf;
use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::fs::{self, Previous};
use crate::{absolute, invalid, lock, parse, sha256, strings, BlobRef, Failure, Owner, System, DIGEST, NAME};

static VERSION: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9]\.[0-9]{1,2}$").expect("a valid pattern"));
static SUFFIX: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\.[A-Za-z0-9_-]{1,32}$").expect("a valid pattern"));
static UNIT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9@_.:-]{1,200}\.service$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    /// The lock file every writer of these files takes.
    lock: String,
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
    let input: Input = parse(input)?;
    validate(&input)?;
    let mut desired = Vec::new();
    for file in &input.files {
        desired.push((file, file.content.bytes(system)?, file.owner.ids(system, "config.write_failed")?));
    }
    // The same check through links: a folder reached by two names holds the same files. A kept copy's name counts too, so
    // scratch work never lands on a declared file or on the lock.
    let kept = |file: &File| file.from_sha256.as_ref().map(|from| fs::kept(&system.path(&file.path), from));
    let mut places: Vec<PathBuf> = input.files.iter().map(|file| system.path(&file.path)).chain(input.files.iter().filter_map(kept)).chain([system.path(&input.lock)]).map(|path| resolved(&path)).collect();
    let named = places.len();
    places.sort_unstable();
    places.dedup();
    if places.len() != named {
        return Err(invalid("two names of one file, a kept copy's name, or the lock"));
    }
    let _lock = lock::exclusive(system, &input.lock, lock::WAIT)?;

    // Every guard holds before the first write. Each file's restore point is the last usable state: what it held before this
    // run, or, when a stopped run already moved the unvalidated candidate in place, the rendered-from bytes kept beside it. A
    // file that existed is never restored to absence. Only a live file that holds neither digest refuses.
    let mut replaced: Vec<(PathBuf, Previous)> = Vec::new();
    let mut keep: Vec<Option<PathBuf>> = Vec::new();
    let mut changes = 0;
    for (file, content, _) in &desired {
        let dest = system.path(&file.path);
        let read = |path: &std::path::Path| fs::read(path).map_err(|error| Failure::new("config.write_failed", format!("{}: {error}", path.display())));
        let live = read(&dest)?;
        let current = live.as_ref().map(|(bytes, _)| bytes.as_slice());
        let copy = kept(file);
        // A kept copy is this render's only when it holds the rendered-from bytes; any other file under that name is not ours.
        let held = match (&copy, &file.from_sha256) {
            (Some(copy), Some(from)) => match read(copy)? {
                Some(bytes) if sha256(&bytes.0) == *from => Some(bytes),
                Some(_) => return Err(Failure::new("config.write_failed", format!("{} is taken", copy.display()))),
                None => None,
            },
            _ => None,
        };
        let previous = match (&file.from_sha256, current) {
            (None, None) => None,
            (None, Some(bytes)) if bytes == content.as_slice() => live.clone(),
            (Some(from), Some(bytes)) if sha256(bytes) == *from => live.clone(),
            (Some(_), Some(bytes)) if bytes == content.as_slice() => held.or_else(|| live.clone()),
            _ => return Err(Failure::new("owned_revision_conflict", format!("{} changed outside this render", file.path))),
        };
        replaced.push((dest, Previous { content: previous }));
        keep.push(copy);
    }
    let drop_kept = |keep: &[Option<PathBuf>]| {
        for copy in keep.iter().flatten() {
            let _ = std::fs::remove_file(copy);
        }
    };
    let undo = |code: &'static str, detail: String, replaced: &[(PathBuf, Previous)]| match fs::restore(replaced) {
        Ok(()) => {
            drop_kept(&keep);
            Failure::new(code, detail)
        }
        Err(restore) => Failure::new("config.restore_failed", format!("{detail}; restore: {restore}")),
    };
    for (((dest, previous), (file, content, ids)), copy) in replaced.iter().zip(&desired).zip(&keep) {
        // The rendered-from bytes are kept beside the file before it changes, so a replay after a stop still knows them.
        if let (Some((bytes, held)), Some(copy)) = (&previous.content, copy) {
            if bytes != content && fs::read(copy).ok().flatten().is_none() {
                if let Err(error) = fs::replace(copy, bytes, *held) {
                    return Err(undo("config.write_failed", format!("{}: {error}", file.path), &replaced));
                }
            }
        }
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
        if let Err(detail) = load(system, unit) {
            // The old files go back and load again in the units this run reached, so each runs what it ran before.
            let mut failure = undo("config.load_failed", detail, &replaced);
            for reached in &input.units[..=index] {
                if let Err(again) = load(system, reached) {
                    failure = Failure::new("config.restore_failed", format!("{}; reload {}: {again}", failure.detail, reached.unit));
                }
            }
            return Err(failure);
        }
    }
    // The set is live: the kept copies are no longer the last usable bytes.
    drop_kept(&keep);
    Ok(changes)
}

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
