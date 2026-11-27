//! `posix.acl.ensure`: one user entry of the access list of one path, or of every folder below it. Lifted from the
//! `setfacl` calls of `route.publish_site` (`access_argv`); the subject user, the path and the permissions come from input.

use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::{absolute, invalid, parse, strings, Failure, System, NAME};

static PERMS: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[r-][w-][xX-]$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    path: String,
    /// The user the entry is for.
    user: String,
    /// `rwx` form; `X` grants search on folders only.
    perms: String,
    /// The default entry that new folders inherit, instead of the access entry.
    #[serde(default)]
    default: bool,
    /// Every folder below the path too (and, for an access entry, every file).
    #[serde(default)]
    recursive: bool,
    /// The account that runs `setfacl`, so a path it cannot reach is never changed with root's rights.
    #[serde(default)]
    run_as: Option<String>,
    /// A missing path is left out and changes nothing, instead of failing.
    #[serde(default)]
    optional: bool,
}

/// Sets the entry and answers how many paths changed. The path's own entry is read first and set only when it differs; a
/// recursive entry is applied below on every run, since `setfacl` does not say what it changed, and counts as a change only
/// when the path's own entry differed.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `acl.ensure_failed` for a missing path or a failed
/// read or change.
pub fn acl_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    if !absolute(&input.path) || !NAME.is_match(&input.user) || !PERMS.is_match(&input.perms) || input.run_as.as_ref().is_some_and(|user| !NAME.is_match(user)) {
        return Err(invalid("acl"));
    }
    if input.optional && std::fs::symlink_metadata(system.path(&input.path)).is_err() {
        return Ok(0);
    }
    let failed = |detail: String| Failure::new("acl.ensure_failed", detail);
    let prefix = input.run_as.as_ref().map_or_else(Vec::new, |user| strings(&["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/env", "-C", "/"]));
    let tag = if input.default { "default:user" } else { "user" };
    let entry = format!("{}u:{}:{}", if input.default { "d:" } else { "" }, input.user, input.perms);
    let mut read = prefix.clone();
    read.extend(strings(&["/usr/bin/getfacl", "--physical", "--omit-header", "--absolute-names", "--", &input.path]));
    let listing = system.run(30, &read).map_err(failed)?;
    let wanted = input.perms.replace('X', "x");
    let holds = listing.lines().any(|line| {
        let line = line.split('#').next().unwrap_or_default().trim();
        line.strip_prefix(&format!("{tag}:{}:", input.user)).is_some_and(|perms| perms == wanted || perms.replace('x', "-") == input.perms.replace('X', "-"))
    });
    if holds && !input.recursive {
        return Ok(0);
    }
    let mut argv = prefix;
    if input.recursive && input.default {
        argv.extend(strings(&["/usr/bin/find", "-P", &input.path, "-type", "d", "-exec", "/usr/bin/setfacl", "-m", &entry, "--", "{}", "+"]));
    } else if input.recursive {
        argv.extend(strings(&["/usr/bin/setfacl", "-P", "-R", "-n", "-m", &entry, "--", &input.path]));
    } else {
        argv.extend(strings(&["/usr/bin/setfacl", "-m", &entry, "--", &input.path]));
    }
    system.run(120, &argv).map_err(failed)?;
    Ok(u32::from(!holds))
}
