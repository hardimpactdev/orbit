//! `route.withdraw_site`: one Node's withdrawal of a Route (docs/cli/route/engine/destroy.mmd).
//!
//! The Gateway renders the Node's whole Caddyfile and PHP-FPM pools without the Route, and names the Caddyfile it rendered
//! from by its SHA-256. The live Caddyfile must be either that one or already the desired one; anything else is a change
//! outside this Route and refuses before any write. Then pools, Caddyfile and the Route's leaf folders converge to the
//! desired state, Caddy reloads on every run, also a repeat that finds nothing to change, and the Caddy admin API is read
//! back: the withdrawn names no longer appear in its live configuration.

use std::fs;
use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;
use sha2::{Digest as _, Sha256};

use crate::site::{pool_path, pool_test, pool_unit, Pool, Site, CADDYFILE, CERTIFICATES, VERSION};
use crate::{strings, BlobRef, Failure, System};

static NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[a-z_][a-z0-9_-]{0,31}$").expect("a valid pattern"));
static SCOPE: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9][A-Za-z0-9._-]*$").expect("a valid pattern"));
static DIGEST: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-f]{64}$").expect("a valid pattern"));
static DOMAIN: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$").expect("a valid pattern"));

/// The code of a pool that failed its write, test or reload: the Swamp chart's pool step.
const POOL_FAILED: &str = "route.pool_failed";

/// Caddy's admin endpoint on the Node, read back after the reload.
const ADMIN: &str = "http://127.0.0.1:2019/config/";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    account: String,
    /// The names the Route served on this Node; none may remain in Caddy's live configuration.
    domains: Vec<String>,
    /// The Route's leaf scopes on this Node, removed with their account copies.
    #[serde(default)]
    leaves: Vec<String>,
    #[serde(default)]
    php: Vec<GuardedPool>,
    caddy: Guarded,
}

/// A shared PHP-FPM pool's desired content and, when the Gateway rendered it from a live pool, that render: the live pool must
/// still hold it, with the account's group and home in place, or already the desired content.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct GuardedPool {
    version: String,
    user: Option<String>,
    content: BlobRef,
    #[serde(default)]
    from: Option<BlobRef>,
}

impl GuardedPool {
    fn pool(&self) -> Pool {
        Pool { version: self.version.clone(), user: self.user.clone(), content: self.content.clone() }
    }
}

/// The Node's publication lock that Swamp's withdrawal and publication take too (`orbit_route_withdraw` `caddy_publication_lock`).
const PUBLISH_LOCK: &str = "/etc/caddy/.orbit-publish.lock";

/// A file's desired content and the digest of the live content the Gateway rendered it from.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Guarded {
    from_sha256: String,
    content: BlobRef,
}

/// Withdraws the Route from this Node and returns how many things changed: `0` on a repeat that found it absent.
///
/// # Errors
///
/// `route.input_invalid` for an input outside the admitted shape, `route.content_missing` for a blob the executor did not
/// read, `owned_revision_conflict` when the live Caddyfile is neither the rendered-from one nor the desired one,
/// `route.pool_failed` when a pool fails its write, test or reload, `route.withdraw_failed` for another failed change, both
/// after the previous files are put back, `caddy.publish_busy` when the Node's publication lock stays held, and
/// `route.withdraw_unverified` when the read-back still finds the Route.
pub fn withdraw_site(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    validate(&input)?;
    let failed = |detail: String| Failure::new("route.withdraw_failed", detail);
    let pool_failed = |detail: String| Failure::new(POOL_FAILED, detail);
    let desired = input.caddy.content.text(system)?;
    let account = system.account(&input.account).map_err(failed)?;
    let home = |text: String| text.replace("/__ORBIT_NODE_HOME__", &account.home);
    // The account's primary group as FPM reads it: its gid, or its name, which an older publisher wrote in its place.
    let forms: Vec<String> = [Some(account.gid.to_string()), system.group_name(account.gid)].into_iter().flatten().collect();
    let rendered: Vec<(Pool, String, Option<String>)> = input
        .php
        .iter()
        .map(|guarded| Ok((guarded.pool(), home(guarded.content.text(system)?), guarded.from.as_ref().map(|from| from.text(system).map(home)).transpose()?)))
        .collect::<Result<_, Failure>>()?;
    // Under the Node's publication lock, as every Swamp writer of these files: nothing changes them between the guard and the write.
    let _lock = crate::lock::exclusive(system, PUBLISH_LOCK, "caddy.publish_busy")?;
    let live = fs::read(system.path(CADDYFILE)).map_err(|error| failed(error.to_string()))?;
    if live != desired.as_bytes() && hex(&live) != input.caddy.from_sha256 {
        return Err(Failure::new("owned_revision_conflict", "the live Caddyfile changed outside this Route"));
    }
    // The last usable generation of every file this withdrawal replaces, put back when a later check or reload fails.
    let mut previous: Vec<(String, Option<Vec<u8>>)> = vec![(CADDYFILE.to_owned(), Some(live))];
    let mut pools: Vec<(Pool, String)> = Vec::new();
    for (pool, content, from) in rendered {
        let path = pool_path(&pool);
        let current = fs::read(system.path(&path)).ok();
        // A guarded pool that is absent stays absent: the withdrawal never creates a pool.
        if from.is_some() && current.is_none() {
            continue;
        }
        let merged = match &from {
            // Only the scopes the withdrawal changes are replaced; every other scope keeps its live bytes.
            Some(from) => merge_pool(from, &content, &String::from_utf8_lossy(current.as_deref().unwrap_or_default()), &forms)
                .ok_or_else(|| Failure::new("owned_revision_conflict", "a shared PHP-FPM pool changed outside this Route"))?,
            None => resolve(&content, &forms[0]),
        };
        previous.push((path, current));
        pools.push((pool, merged));
    }

    let root = system.superuser();
    let mut site = Site { system, changes: 0 };
    for (pool, content) in &pools {
        if let Err(failure) = site.file(&pool_path(pool), content.as_bytes(), root, root, 0o644) {
            restore(system, &previous[1..]);
            return Err(pool_failed(failure.detail));
        }
    }
    for (pool, _) in &pools {
        if let Err(detail) = system.run(30, &pool_test(pool)) {
            restore(system, &previous[1..]);
            return Err(pool_failed(detail));
        }
    }
    let caddy = system.group("caddy").map_err(failed)?;
    if let Err(failure) = site.caddyfile(desired.as_bytes(), caddy) {
        restore(system, &previous[1..]);
        return Err(failed(failure.detail));
    }
    // A reload every run, as route.publish_site does: a repeat after a crash still loads the pool without the Route.
    for (pool, _) in &pools {
        if let Err(detail) = system.run(30, &strings(&["/usr/bin/systemctl", "reload", &pool_unit(pool)])) {
            rollback(system, &previous, &pools);
            return Err(pool_failed(detail));
        }
    }
    // Caddy reloads on every run: a repeat after a crash between the move and the reload still makes the withdrawal live.
    if let Err(detail) = system.run(60, &strings(&["/usr/bin/systemctl", "reload", "caddy"])) {
        rollback(system, &previous, &pools);
        return Err(failed(detail));
    }
    // The leaves go only once the Caddy that served them no longer names them.
    let mut changes = site.changes;
    for scope in &input.leaves {
        for folder in [format!("{CERTIFICATES}/{scope}"), format!("{}/.orbit/certificates/{scope}", account.home)] {
            let path = system.path(&folder);
            if fs::symlink_metadata(&path).is_ok() {
                fs::remove_dir_all(&path).map_err(|error| failed(error.to_string()))?;
                changes += 1;
            }
        }
    }
    verify(system, &input, desired.as_bytes(), &account.home)?;
    Ok(changes)
}

/// The pool text with the account's group in `form`.
fn resolve(text: &str, form: &str) -> String {
    text.replace("__ORBIT_NODE_GROUP__", form)
}

/// A pool file as its text before the first scope and each `[scope]` section by name.
fn sections(text: &str) -> (String, std::collections::BTreeMap<String, String>) {
    let mut prefix = String::new();
    let mut scopes = std::collections::BTreeMap::new();
    let mut current: Option<(String, String)> = None;
    for line in text.split_inclusive('\n') {
        let trimmed = line.trim();
        if trimmed.starts_with('[') && trimmed.ends_with(']') {
            if let Some((name, body)) = current.take() {
                scopes.insert(name, body);
            }
            current = Some((trimmed[1..trimmed.len() - 1].to_owned(), line.to_owned()));
        } else if let Some((_, body)) = current.as_mut() {
            body.push_str(line);
        } else {
            prefix.push_str(line);
        }
    }
    if let Some((name, body)) = current {
        scopes.insert(name, body);
    }
    (prefix, scopes)
}

/// The live pool with the withdrawal applied, as Swamp's merge does: each scope the render changes must still hold its
/// rendered-from or desired state, its group as the gid or as the group's name, else `None`; it then takes the desired state.
/// Every other scope, and the text before the first scope, keeps its live bytes.
fn merge_pool(from: &str, desired: &str, live: &str, forms: &[String]) -> Option<String> {
    let (_, original) = sections(from);
    let (_, wanted) = sections(desired);
    let (live_prefix, observed) = sections(live);
    let matches = |live: &str, text: Option<&String>| text.is_some_and(|text| forms.iter().any(|form| resolve(text, form) == live));
    let mut out = observed.clone();
    for name in original.keys().chain(wanted.keys()) {
        if original.get(name) == wanted.get(name) {
            continue;
        }
        if let Some(current) = observed.get(name) {
            if !matches(current, original.get(name)) && !matches(current, wanted.get(name)) {
                return None;
            }
            if matches(current, wanted.get(name)) {
                continue;
            }
        }
        match wanted.get(name) {
            Some(text) => out.insert(name.clone(), resolve(text, &forms[0])),
            None => out.remove(name),
        };
    }
    // A scope the render leaves unchanged keeps its live state, also its absence.
    let mut text = live_prefix;
    text.push_str(&out.into_values().collect::<String>());
    Some(text)
}

/// Puts back each file's previous generation; a file that did not exist is removed. Best effort: the caller's failure matters.
fn restore(system: &impl System, previous: &[(String, Option<Vec<u8>>)]) {
    for (path, bytes) in previous {
        let dest = system.path(path);
        let _ = match bytes {
            Some(bytes) => fs::write(&dest, bytes),
            None => fs::remove_file(&dest),
        };
    }
}

/// After a failed reload: the previous files again, and the pools and Caddy reloaded on them, so the running generation is usable.
fn rollback(system: &impl System, previous: &[(String, Option<Vec<u8>>)], pools: &[(Pool, String)]) {
    restore(system, previous);
    for (pool, _) in pools {
        let _ = system.run(30, &strings(&["/usr/bin/systemctl", "reload", &pool_unit(pool)]));
    }
    let _ = system.run(60, &strings(&["/usr/bin/systemctl", "reload", "caddy"]));
}

/// The read-back: the desired Caddyfile is live, no leaf folder remains, and Caddy's admin API serves none of the names.
fn verify(system: &impl System, input: &Input, desired: &[u8], home: &str) -> Result<(), Failure> {
    let unverified = |detail: &str| Failure::new("route.withdraw_unverified", detail.to_owned());
    if fs::read(system.path(CADDYFILE)).map_err(|_| unverified("Caddyfile unreadable"))? != desired {
        return Err(unverified("the live Caddyfile is not the desired one"));
    }
    for scope in &input.leaves {
        if fs::symlink_metadata(system.path(&format!("{CERTIFICATES}/{scope}"))).is_ok()
            || fs::symlink_metadata(system.path(&format!("{home}/.orbit/certificates/{scope}"))).is_ok()
        {
            return Err(unverified("a leaf folder remains"));
        }
    }
    let config = system
        .run(15, &strings(&["/usr/bin/curl", "--silent", "--show-error", "--fail", "--max-time", "10", ADMIN]))
        .map_err(|_| unverified("Caddy admin API"))?;
    if input.domains.iter().any(|domain| config.contains(&format!("\"{domain}\""))) {
        return Err(unverified("Caddy still serves a withdrawn name"));
    }
    Ok(())
}

fn validate(input: &Input) -> Result<(), Failure> {
    let invalid = |detail: &str| Err(Failure::new("route.input_invalid", detail.to_owned()));
    if !NAME.is_match(&input.account) {
        return invalid("account");
    }
    if input.domains.is_empty() || !input.domains.iter().all(|domain| DOMAIN.is_match(domain)) {
        return invalid("domains");
    }
    if !input.leaves.iter().all(|scope| SCOPE.is_match(scope) && !scope.contains("..")) {
        return invalid("leaf scope");
    }
    if !DIGEST.is_match(&input.caddy.from_sha256) {
        return invalid("caddy digest");
    }
    if !input.php.iter().all(|pool| {
        VERSION.is_match(&pool.version)
            && pool.user.as_ref().is_none_or(|user| NAME.is_match(user))
    }) {
        return invalid("pool");
    }
    Ok(())
}

fn hex(bytes: &[u8]) -> String {
    Sha256::digest(bytes).iter().fold(String::with_capacity(64), |mut hex, byte| {
        use std::fmt::Write as _;
        let _ = write!(hex, "{byte:02x}");
        hex
    })
}
