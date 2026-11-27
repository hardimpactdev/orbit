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
    /// `dnsmasq --test`.
    Dnsmasq,
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
            Self::Dnsmasq => true,
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
            Self::Dnsmasq => strings(&["/usr/sbin/dnsmasq", "--test"]),
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
    let _lock = lock::exclusive(system, &input.lock, lock::WAIT)?;

    // Every guard holds before the first write.
    let mut replaced: Vec<(PathBuf, Previous)> = Vec::new();
    let mut changes = 0;
    for (file, content, _) in &desired {
        let dest = system.path(&file.path);
        let live = fs::read(&dest).map_err(|error| Failure::new("config.write_failed", format!("{}: {error}", file.path)))?;
        let current = live.as_ref().map(|(bytes, _)| bytes.as_slice());
        let guarded = current == Some(content.as_slice()) || match (&file.from_sha256, current) {
            (Some(from), Some(bytes)) => sha256(bytes) == *from,
            (None, None) => true,
            _ => false,
        };
        if !guarded {
            return Err(Failure::new("owned_revision_conflict", format!("{} changed outside this render", file.path)));
        }
        replaced.push((dest, Previous { content: live }));
    }
    for ((dest, _), (file, content, ids)) in replaced.iter().zip(&desired) {
        match fs::ensure(dest, content, *ids) {
            Ok(written) => changes += u32::from(written),
            Err(error) => {
                fs::restore(&replaced);
                return Err(Failure::new("config.write_failed", format!("{}: {error}", file.path)));
            }
        }
    }
    for validator in &input.validators {
        if let Err(detail) = system.run(60, &validator.argv()) {
            fs::restore(&replaced);
            return Err(Failure::new("config.validate_failed", detail));
        }
    }
    for unit in &input.units {
        if let Err(detail) = load(system, unit) {
            // The old files go back and load again, so the service runs what it ran before.
            fs::restore(&replaced);
            for unit in &input.units {
                let _ = load(system, unit);
            }
            return Err(Failure::new("config.load_failed", detail));
        }
    }
    Ok(changes)
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
    let mut paths: Vec<&str> = input.files.iter().map(|file| file.path.as_str()).collect();
    paths.sort_unstable();
    paths.dedup();
    if paths.len() != input.files.len() {
        return Err(invalid("a file named twice"));
    }
    if !input.validators.iter().all(Validator::valid) {
        return Err(invalid("validators"));
    }
    if !input.units.iter().all(|unit| UNIT.is_match(&unit.unit)) {
        return Err(invalid("units"));
    }
    Ok(())
}
