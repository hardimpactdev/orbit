//! `instance.fetch_branch`: the Instance's branch in the Node's mirror of its repository, when the origin has it.

use std::os::unix::fs::{OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, System};

const GIT: &str = "/usr/bin/git";
/// Where the mirrors live; a mirror path must be a `<id>.git` folder directly inside it.
const MIRRORS: &str = "/var/lib/orbit/instance-repositories";
/// A remote read's own bound, inside the step's budget.
const REMOTE_SECONDS: u32 = 80;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct FetchInput {
    mirror: String,
    repository: String,
    branch: String,
    /// The one protocol Git may use, `https`.
    protocol: String,
    /// The `store` credential line for the repository's host: a secret, so a blob.
    credential: crate::BlobRef,
}

/// Asks the origin for the branch; when it has it, fetches it into the mirror, made bare on first use. A branch the origin
/// lacks starts at the seed's commit, so nothing is fetched. Answers `{"commit": <the fetched commit, or null>}`.
///
/// The credential is written root-only beside the mirror for the two Git calls and removed after them, whatever they did.
///
/// # Errors
///
/// `instance.fetch_input_invalid`, `instance.fetch_credential_missing` or `instance.branch_fetch_failed`.
pub fn fetch_branch(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: FetchInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.fetch_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.fetch_input_invalid", detail))?;
    let line = system.blob(&input.credential.blob.sha256).map_err(|detail| Failure::new("instance.fetch_credential_missing", detail))?;
    let mirror = system.path(&input.mirror);
    let folder = mirror.parent().map_or_else(|| PathBuf::from(MIRRORS), Path::to_path_buf);
    std::fs::create_dir_all(&folder).and_then(|()| std::fs::set_permissions(&folder, std::fs::Permissions::from_mode(0o700))).map_err(failed)?;
    let credential = folder.join(format!(".credential-{}", std::process::id()));
    let _ = std::fs::remove_file(&credential);
    std::fs::File::options()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&credential)
        .and_then(|mut file| std::io::Write::write_all(&mut file, &line))
        .map_err(failed)?;
    let result = fetch(system, &input, &mirror, &credential);
    let _ = std::fs::remove_file(&credential);
    result.map(|commit| serde_json::json!({"commit": commit}).to_string())
}

fn failed(error: impl std::fmt::Display) -> Failure {
    Failure::new("instance.branch_fetch_failed", error.to_string())
}

fn check(input: &FetchInput) -> Result<(), String> {
    let id = input
        .mirror
        .strip_prefix(MIRRORS)
        .and_then(|rest| rest.strip_prefix('/'))
        .and_then(|rest| rest.strip_suffix(".git"))
        .ok_or("mirror is not an Orbit mirror")?;
    if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_digit()) {
        return Err("mirror is not an Orbit mirror".to_owned());
    }
    if input.protocol != "https" || !input.repository.starts_with("https://") || input.repository.bytes().any(|byte| byte.is_ascii_whitespace() || byte.is_ascii_control()) {
        return Err("repository is not an https URL".to_owned());
    }
    // A branch name Git accepts and that cannot read as an option or leave refs/heads.
    let branch = &input.branch;
    let valid = !branch.is_empty()
        && !branch.starts_with(['-', '/', '.'])
        && !branch.ends_with(['/', '.'])
        && !std::path::Path::new(branch.as_str()).extension().is_some_and(|extension| extension.eq_ignore_ascii_case("lock"))
        && !branch.contains("..")
        && !branch.contains("//")
        && !branch.contains("@{")
        && !branch.bytes().any(|byte| byte.is_ascii_control() || b" ~^:?*[\\".contains(&byte));
    if !valid {
        return Err("branch is not a branch name".to_owned());
    }
    Ok(())
}

fn fetch(system: &impl System, input: &FetchInput, mirror: &Path, credential: &Path) -> Result<Option<String>, Failure> {
    let reference = format!("refs/heads/{}", input.branch);
    let remote = |mirror: Option<&Path>, rest: &[&str]| -> Vec<String> {
        let mut argv: Vec<String> = ["/usr/bin/env", "GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL=/dev/null", "GIT_TERMINAL_PROMPT=0", GIT]
            .iter()
            .map(|part| (*part).to_owned())
            .collect();
        argv.extend(["-c".to_owned(), "core.hooksPath=/dev/null".to_owned(), "-c".to_owned(), "core.fsmonitor=false".to_owned()]);
        if let Some(mirror) = mirror {
            argv.extend(["-C".to_owned(), mirror.display().to_string()]);
        }
        for setting in [
            "credential.helper=".to_owned(),
            format!("credential.helper=store --file={}", credential.display()),
            "http.followRedirects=false".to_owned(),
            "protocol.allow=never".to_owned(),
            format!("protocol.{}.allow=always", input.protocol),
        ] {
            argv.extend(["-c".to_owned(), setting]);
        }
        argv.extend(rest.iter().map(|part| (*part).to_owned()));
        argv
    };
    let heads = system
        .run(REMOTE_SECONDS, &remote(None, &["ls-remote", "--heads", "--", &input.repository, &reference]), None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("ls-remote: {detail}")))?;
    if heads.trim().is_empty() {
        return Ok(None);
    }
    if !mirror.join("HEAD").exists() {
        system
            .run(30, &[GIT.to_owned(), "init".to_owned(), "--quiet".to_owned(), "--bare".to_owned(), mirror.display().to_string()], None)
            .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("init: {detail}")))?;
    }
    let refspec = format!("+{reference}:{reference}");
    system
        .run(REMOTE_SECONDS, &remote(Some(mirror), &["fetch", "--quiet", "--no-tags", "--", &input.repository, &refspec]), None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("fetch: {detail}")))?;
    let commit = system
        .run(30, &[GIT.to_owned(), "-C".to_owned(), mirror.display().to_string(), "rev-parse".to_owned(), "--verify".to_owned(), format!("{reference}^{{commit}}")], None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("rev-parse: {detail}")))?;
    Ok(Some(commit.trim().to_owned()))
}
