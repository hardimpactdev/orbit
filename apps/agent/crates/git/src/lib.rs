//! The git family (ADR 0271 N3): primitives on one Git repository or work tree on this host.
//!
//! - [`fetch`] (`git.fetch`): one ref fetched into one repository, from an https URL or from a bundle file.
//! - [`checkout`] (`git.checkout`): the branch of one work tree, checked out, renamed, reset or cleaned.
//! - [`ref_ensure`] (`git.ref.ensure`): one ref, set only from an expected old value, or absent.
//! - [`worktree_read`] (`git.worktree.read`): the state of one work tree, read only.
//!
//! Every path, ref, URL and credential reference comes from the order; none is an Orbit name. Git runs as
//! `/usr/bin/git` with an argument list fixed here, in a cleared environment, and with `--` or `--end-of-options` before
//! each operand where Git takes it. Every operand is checked first, so none can read as an option.

mod checkout;
mod fetch;
mod read;
mod reference;
#[cfg(test)]
mod tests;

use std::path::{Path, PathBuf};
use std::process::Command;

use serde::Deserialize;

pub use checkout::checkout;
pub use fetch::fetch;
pub use read::worktree_read;
pub use reference::ref_ensure;

const GIT: &str = "/usr/bin/git";
const TIMEOUT: &str = "/usr/bin/timeout";
/// A local call's bound; a remote call has its own.
const LOCAL_SECONDS: u32 = 60;
const REMOTE_SECONDS: u32 = 120;

/// A typed failure: the code goes to the Gateway, the detail only to the log.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Failure {
    /// The stable failure code.
    pub code: &'static str,
    /// What went wrong, for the log only. It never holds a credential.
    pub detail: String,
}

impl Failure {
    pub(crate) fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: detail.into(),
        }
    }
}

pub(crate) fn invalid(detail: impl Into<String>) -> Failure {
    Failure::new("git.input_invalid", detail)
}

/// The host this family runs on: Git itself and the executor's blob cache.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    blobs: PathBuf,
}

impl NodeSystem {
    /// A system that reads blobs from this cache folder.
    #[must_use]
    pub fn new(blobs: impl Into<PathBuf>) -> Self {
        Self {
            blobs: blobs.into(),
        }
    }

    fn blob(&self, sha256: &str) -> Result<Vec<u8>, String> {
        std::fs::read(self.blobs.join(sha256)).map_err(|error| error.to_string())
    }

    /// Runs Git on one repository or work tree with the fixed settings, a cleared environment and a time limit.
    /// Answers the exit code (`None` for a signal), standard output and standard error.
    #[expect(
        clippy::unused_self,
        reason = "Git runs on the host this system stands for, beside its blob cache"
    )]
    pub(crate) fn git(
        &self,
        seconds: u32,
        at: &Path,
        settings: &[String],
        args: &[&str],
    ) -> Result<Ran, Failure> {
        // Discovery stops at the named folder, so a path that is no repository never resolves to one above it.
        let ceiling = at.parent().unwrap_or(at);
        let mut command = Command::new(TIMEOUT);
        command
            .env_clear()
            .env("PATH", "/usr/bin:/bin")
            .env("HOME", "/nonexistent")
            .env("LC_ALL", "C")
            .env("GIT_CONFIG_NOSYSTEM", "1")
            .env("GIT_CONFIG_GLOBAL", "/dev/null")
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("GIT_ASKPASS", "/bin/false")
            .env("GIT_CEILING_DIRECTORIES", ceiling)
            .arg(seconds.to_string())
            .arg(GIT)
            .arg("--no-optional-locks");
        for setting in FIXED
            .iter()
            .map(|setting| (*setting).to_owned())
            .chain(settings.iter().cloned())
        {
            command.arg("-c").arg(setting);
        }
        let output = command
            .arg("-C")
            .arg(at)
            .args(args)
            .output()
            .map_err(|error| Failure::new("git.run_failed", error.to_string()))?;
        Ok(Ran {
            code: output.status.code(),
            stdout: output.stdout,
            stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
        })
    }

    /// Runs Git and answers its standard output as text, failing with `code` on any exit but 0.
    pub(crate) fn git_ok(
        &self,
        code: &'static str,
        seconds: u32,
        at: &Path,
        settings: &[String],
        args: &[&str],
    ) -> Result<String, Failure> {
        let ran = self.git(seconds, at, settings, args)?;
        if ran.code != Some(0) {
            return Err(Failure::new(
                code,
                format!("git {}: {}", args.first().unwrap_or(&""), ran.stderr.trim()),
            ));
        }
        String::from_utf8(ran.stdout)
            .map_err(|_| Failure::new(code, "git answered bytes that are not UTF-8"))
    }
}

/// Settings every call takes: no hook, no filesystem monitor, no pager, no remote protocol unless a fetch allows one.
const FIXED: [&str; 7] = [
    "core.hooksPath=/dev/null",
    "core.fsmonitor=false",
    "core.pager=cat",
    "credential.helper=",
    "protocol.allow=never",
    "http.followRedirects=false",
    "submodule.recurse=false",
];

/// What one Git call answered.
#[derive(Debug)]
pub(crate) struct Ran {
    pub(crate) code: Option<i32>,
    pub(crate) stdout: Vec<u8>,
    pub(crate) stderr: String,
}

/// A blob reference in an order: `{"blob": {"sha256": "…"}}`. The executor fetched and hash-checked it before the run.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobRef {
    pub(crate) blob: BlobDigest,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct BlobDigest {
    pub(crate) sha256: String,
}

impl BlobRef {
    pub(crate) fn bytes(&self, system: &NodeSystem) -> Result<Vec<u8>, Failure> {
        use sha2::Digest as _;
        if self.blob.sha256.len() != 64
            || !self
                .blob
                .sha256
                .bytes()
                .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
        {
            return Err(invalid("blob reference"));
        }
        let bytes = system
            .blob(&self.blob.sha256)
            .map_err(|detail| Failure::new("git.credential_missing", detail))?;
        let digest: String = sha2::Sha256::digest(&bytes)
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        if digest != self.blob.sha256 {
            return Err(Failure::new(
                "git.credential_missing",
                "the blob does not hash to its reference",
            ));
        }
        Ok(bytes)
    }
}

/// Parses an order's input into its typed form; an unknown field refuses.
pub(crate) fn parse<T: serde::de::DeserializeOwned>(
    input: &serde_json::Value,
) -> Result<T, Failure> {
    serde_json::from_value(input.clone()).map_err(|error| invalid(error.to_string()))
}

/// An absolute, normal path: no `.` or `..` part, no empty part, no control byte, no trailing slash.
pub(crate) fn path(value: &str, what: &str) -> Result<PathBuf, Failure> {
    let normal = value.starts_with('/')
        && value.len() > 1
        && !value.ends_with('/')
        && !value.bytes().any(|byte| byte.is_ascii_control())
        && value[1..]
            .split('/')
            .all(|part| !part.is_empty() && part != "." && part != "..");
    if normal {
        Ok(PathBuf::from(value))
    } else {
        Err(invalid(format!("{what} is not an absolute normal path")))
    }
}

/// A full ref name Git accepts (`git check-ref-format` rules) under `refs/`, which cannot read as an option.
pub(crate) fn full_ref(value: &str) -> Result<&str, Failure> {
    if value.strip_prefix("refs/").is_some_and(branch_name)
        && value.split('/').all(|part| !part.starts_with('.'))
    {
        Ok(value)
    } else {
        Err(invalid("ref is not a full ref name under refs/"))
    }
}

/// A branch name Git accepts that cannot read as an option or leave `refs/heads`.
pub(crate) fn branch_name(branch: &str) -> bool {
    !branch.is_empty()
        && branch.len() <= 255
        && !branch.starts_with(['-', '/', '.'])
        && !branch.ends_with(['/', '.'])
        && !Path::new(branch)
            .extension()
            .is_some_and(|extension| extension.eq_ignore_ascii_case("lock"))
        && !branch.contains("..")
        && !branch.contains("//")
        && !branch.contains("/.")
        && !branch.contains(".lock/")
        && !branch.contains("@{")
        && branch != "@"
        && !branch
            .bytes()
            .any(|byte| byte.is_ascii_control() || b" ~^:?*[\\".contains(&byte))
}

/// A branch given by its short name.
pub(crate) fn branch(value: &str) -> Result<&str, Failure> {
    if branch_name(value) {
        Ok(value)
    } else {
        Err(invalid("branch is not a branch name"))
    }
}

/// A full object id: 40 (SHA-1) or 64 (SHA-256) lowercase hex digits.
pub(crate) fn commit(value: &str) -> Result<&str, Failure> {
    if matches!(value.len(), 40 | 64)
        && value
            .bytes()
            .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
    {
        Ok(value)
    } else {
        Err(invalid("commit is not a full object id"))
    }
}

/// The object a full ref names, or `None` when the repository has no such ref. Any other error fails with `code`.
pub(crate) fn resolve(
    system: &NodeSystem,
    code: &'static str,
    at: &Path,
    reference: &str,
) -> Result<Option<String>, Failure> {
    let ran = system.git(
        LOCAL_SECONDS,
        at,
        &[],
        &["show-ref", "--verify", "--hash", "--", reference],
    )?;
    match ran.code {
        Some(0) => Ok(Some(String::from_utf8_lossy(&ran.stdout).trim().to_owned())),
        Some(1 | 128) if ran.stderr.contains("not a valid ref") => Ok(None),
        _ => Err(Failure::new(
            code,
            format!("show-ref: {}", ran.stderr.trim()),
        )),
    }
}

/// What a named folder must be.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Kind {
    /// A work tree's top folder.
    WorkTree,
    /// A bare repository, or a work tree whose `.git` is the repository.
    Repository,
}

/// The repository's own top folder: the named path must be the work tree (or the bare repository) itself.
pub(crate) fn require_repository(
    system: &NodeSystem,
    code: &'static str,
    at: &Path,
    kind: Kind,
) -> Result<(), Failure> {
    let work_tree = kind == Kind::WorkTree;
    let flag = if work_tree {
        "--show-toplevel"
    } else {
        "--absolute-git-dir"
    };
    let top = system.git_ok(code, LOCAL_SECONDS, at, &[], &["rev-parse", flag])?;
    let top = PathBuf::from(top.trim());
    let named = std::fs::canonicalize(at).map_err(|error| Failure::new(code, error.to_string()))?;
    let bare = !work_tree
        && system
            .git_ok(
                code,
                LOCAL_SECONDS,
                at,
                &[],
                &["rev-parse", "--is-bare-repository"],
            )?
            .trim()
            == "true";
    let held = if work_tree || bare {
        top == named
    } else {
        top == named.join(".git")
    };
    if held {
        Ok(())
    } else {
        Err(Failure::new(
            code,
            format!("{} is not a repository's own folder", at.display()),
        ))
    }
}
