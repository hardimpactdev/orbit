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
    php: Vec<Pool>,
    caddy: Guarded,
}

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
/// `route.withdraw_failed` for a failed change and `route.withdraw_unverified` when the read-back still finds the Route.
pub fn withdraw_site(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    validate(&input)?;
    let failed = |detail: String| Failure::new("route.withdraw_failed", detail);
    let desired = input.caddy.content.text(system)?;
    let live = fs::read(system.path(CADDYFILE)).map_err(|error| failed(error.to_string()))?;
    let live_digest = hex(&live);
    if live != desired.as_bytes() && live_digest != input.caddy.from_sha256 {
        return Err(Failure::new("owned_revision_conflict", "the live Caddyfile changed outside this Route"));
    }

    let account = system.account(&input.account).map_err(failed)?;
    let root = system.superuser();
    let mut site = Site { system, changes: 0 };
    let relabel = |failure: Failure| failed(failure.detail);
    for pool in &input.php {
        let content = pool.content.text(system)?.replace("__ORBIT_NODE_GROUP__", &account.gid.to_string()).replace("/__ORBIT_NODE_HOME__", &account.home);
        site.file(&pool_path(pool), content.as_bytes(), root, root, 0o644).map_err(relabel)?;
    }
    for pool in &input.php {
        system.run(30, &pool_test(pool)).map_err(failed)?;
    }
    // A reload every run, as route.publish_site does: a repeat after a crash still loads the pool without the Route.
    for pool in &input.php {
        system.run(30, &strings(&["/usr/bin/systemctl", "reload", &pool_unit(pool)])).map_err(failed)?;
    }
    let caddy = system.group("caddy").map_err(failed)?;
    site.caddyfile(desired.as_bytes(), caddy).map_err(relabel)?;
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
    // Caddy reloads on every run: a repeat after a crash between the move and the reload still makes the withdrawal live.
    system.run(60, &strings(&["/usr/bin/systemctl", "reload", "caddy"])).map_err(failed)?;
    verify(system, &input, desired.as_bytes(), &account.home)?;
    Ok(changes)
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
    if !input.php.iter().all(|pool| VERSION.is_match(&pool.version) && pool.user.as_ref().is_none_or(|user| NAME.is_match(user))) {
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
