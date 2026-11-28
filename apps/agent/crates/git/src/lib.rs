//! The git family (ADR 0271 N3): primitives on one Git repository or work tree on this host.
//!
//! - [`fetch`] (`git.fetch`): one ref fetched into one repository, from an https URL or from a bundle file.
//! - [`checkout`] (`git.checkout`): the branch of one work tree, checked out, renamed, reset or cleaned.
//! - [`ref_ensure`] (`git.ref.ensure`): one ref, set only from an expected old value, or absent.
//! - [`worktree_read`] (`git.worktree.read`): the state of one work tree, read only.
//!
//! Every path, ref, URL and credential reference comes from the order; none is an Orbit name. Git runs as
//! `/usr/bin/git` with an argument list fixed here, never through a shell, in a cleared environment, and with `--` or
//! `--end-of-options` before each operand where Git takes it. Every operand is checked first, so none can read as an option.
//! A repository whose own configuration could redirect a transport or run a program is refused before anything runs.

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
    /// What went wrong, for the log only. It never holds a credential: URL user information is masked.
    pub detail: String,
}

impl Failure {
    pub(crate) fn new(code: &'static str, detail: impl Into<String>) -> Self {
        Self {
            code,
            detail: redact(&detail.into(), &[]),
        }
    }

    /// The same failure with every given secret masked in its detail.
    pub(crate) fn without(mut self, secrets: &[&str]) -> Self {
        self.detail = redact(&self.detail, secrets);
        self
    }
}

/// Masks each secret and the user information of every URL in a text.
pub(crate) fn redact(text: &str, secrets: &[&str]) -> String {
    let mut text = text.to_owned();
    for secret in secrets.iter().filter(|secret| !secret.is_empty()) {
        text = text.replace(secret, "***");
    }
    let mut out = String::with_capacity(text.len());
    let mut rest = text.as_str();
    while let Some(at) = rest.find("://") {
        out.push_str(&rest[..at + 3]);
        rest = &rest[at + 3..];
        let end = rest
            .find(|c: char| c == '/' || c.is_whitespace() || c == '\'' || c == '"')
            .unwrap_or(rest.len());
        if let Some(user) = rest[..end].rfind('@') {
            out.push_str("***");
            rest = &rest[user..];
        }
    }
    out.push_str(rest);
    out
}

pub(crate) fn invalid(detail: impl Into<String>) -> Failure {
    Failure::new("git.input_invalid", detail)
}

/// The host this family runs on: Git itself and the executor's blob cache.
#[derive(Debug, Clone)]
pub struct NodeSystem {
    blobs: PathBuf,
}

/// Settings every call takes: no hook, no filesystem monitor, no program Git could start for a credential, diff, pager or
/// SSH, no untracked cache to write, and no remote protocol unless a fetch allows one.
const FIXED: [&str; 12] = [
    "core.hooksPath=/dev/null",
    "core.fsmonitor=false",
    "core.untrackedCache=false",
    "core.pager=cat",
    "core.sshCommand=/bin/false",
    "core.askPass=/bin/false",
    "diff.external=",
    "credential.helper=",
    "protocol.allow=never",
    "http.followRedirects=false",
    "submodule.recurse=false",
    "fetch.recurseSubmodules=false",
];

/// What one Git call answered.
#[derive(Debug)]
pub(crate) struct Ran {
    pub(crate) code: Option<i32>,
    pub(crate) stdout: Vec<u8>,
    pub(crate) stderr: String,
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

    /// Runs Git on one folder with the fixed settings, a cleared environment and a time limit. `secret` holds environment
    /// entries that carry a credential, never in the argument list that other users can read. Answers the exit
    /// code (`None` for a signal), standard output as bytes and standard error.
    #[expect(
        clippy::unused_self,
        reason = "Git runs on the host this system stands for, beside its blob cache"
    )]
    pub(crate) fn run(
        &self,
        seconds: u32,
        at: &Path,
        settings: &[String],
        secret: &[(String, String)],
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
            .env("GIT_CEILING_DIRECTORIES", ceiling);
        // A credentialed fetch replaces `GIT_ASKPASS` and adds the answers it reads; nothing else enters the environment.
        command.envs(
            secret
                .iter()
                .map(|(key, value)| (key.as_str(), value.as_str())),
        );
        command
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

    /// Runs Git with no extra settings.
    pub(crate) fn git(&self, seconds: u32, at: &Path, args: &[&str]) -> Result<Ran, Failure> {
        self.run(seconds, at, &[], &[], args)
    }

    /// Runs Git and answers its standard output as bytes, failing with `code` on any exit but 0.
    pub(crate) fn bytes(
        &self,
        code: &'static str,
        at: &Path,
        args: &[&str],
    ) -> Result<Vec<u8>, Failure> {
        let ran = self.git(LOCAL_SECONDS, at, args)?;
        if ran.code != Some(0) {
            return Err(Failure::new(
                code,
                format!("git {}: {}", args.first().unwrap_or(&""), ran.stderr.trim()),
            ));
        }
        Ok(ran.stdout)
    }

    /// Runs Git and answers its standard output as text, failing with `code` on any exit but 0 or on bytes that are not UTF-8.
    pub(crate) fn text(
        &self,
        code: &'static str,
        at: &Path,
        args: &[&str],
    ) -> Result<String, Failure> {
        String::from_utf8(self.bytes(code, at, args)?)
            .map_err(|_| Failure::new(code, "git answered bytes that are not UTF-8"))
    }
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
        let bytes = system.blob(&self.blob.sha256).map_err(|_| {
            Failure::new(
                "git.credential_missing",
                "the credential blob is not cached",
            )
        })?;
        let digest = sha2::Sha256::digest(&bytes)
            .iter()
            .fold(String::new(), |mut hex, byte| {
                let _ = std::fmt::Write::write_fmt(&mut hex, format_args!("{byte:02x}"));
                hex
            });
        if digest != self.blob.sha256 {
            return Err(Failure::new(
                "git.credential_missing",
                "the blob does not hash to its reference",
            ));
        }
        Ok(bytes)
    }
}

/// Parses an order's input into its typed form; an unknown field refuses. Serde's message is dropped: it can echo a value,
/// and a value may be a secret someone put in the wrong field.
pub(crate) fn parse<T: serde::de::DeserializeOwned>(
    input: &serde_json::Value,
) -> Result<T, Failure> {
    serde_json::from_value(input.clone())
        .map_err(|_| invalid("the input does not match the action's typed shape"))
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

/// A full object id: 40 (SHA-1) or 64 (SHA-256) lowercase hex digits, never the null id.
pub(crate) fn commit(value: &str) -> Result<&str, Failure> {
    if matches!(value.len(), 40 | 64)
        && value
            .bytes()
            .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
        && value.bytes().any(|byte| byte != b'0')
    {
        Ok(value)
    } else {
        Err(invalid("commit is not a full, non-null object id"))
    }
}

/// The object a full ref names, or `None` when the repository has no such ref. A symbolic ref refuses
/// (`git.ref_symbolic`): no primitive acts on, or answers for, the ref it points to. Any other error fails with `code`.
pub(crate) fn resolve(
    system: &NodeSystem,
    code: &'static str,
    at: &Path,
    reference: &str,
) -> Result<Option<String>, Failure> {
    let symbolic = system.git(
        LOCAL_SECONDS,
        at,
        &["symbolic-ref", "--quiet", "--", reference],
    )?;
    match symbolic.code {
        Some(0) => {
            return Err(Failure::new(
                "git.ref_symbolic",
                format!("{reference} is a symbolic ref"),
            ))
        }
        Some(1) => {}
        _ => {
            return Err(Failure::new(
                code,
                format!("symbolic-ref: {}", symbolic.stderr.trim()),
            ))
        }
    }
    let ran = system.git(
        LOCAL_SECONDS,
        at,
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

/// Configuration a repository may not hold, by lowercase key prefix and suffix: each can redirect a transport, start a
/// program, or make a read write. Git applies the repository's own value where no fixed `-c` covers every name (a
/// `protocol.<name>.allow`, a `url.<base>.insteadOf`, a filter or merge driver), so such a repository is refused instead.
const REFUSED: [(&str, &str); 21] = [
    ("url.", ".insteadof"),
    ("url.", ".pushinsteadof"),
    ("protocol.", ""),
    ("credential.", ""),
    ("http.", ""),
    ("filter.", ""),
    ("merge.", ".driver"),
    ("diff.", ".command"),
    ("diff.", ".textconv"),
    ("diff.external", ""),
    ("remote.", ".uploadpack"),
    ("remote.", ".receivepack"),
    ("remote.", ".proxy"),
    ("core.fsmonitor", ""),
    ("core.hookspath", ""),
    ("core.sshcommand", ""),
    ("core.askpass", ""),
    ("core.gitproxy", ""),
    ("core.worktree", ""),
    ("include", ""),
    ("uploadpack.", ""),
];

/// The repository's own top folder: the named path must be the work tree (or the bare repository) itself, and its own
/// configuration must hold no refused key.
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
    let top = PathBuf::from(system.text(code, at, &["rev-parse", flag])?.trim());
    let named = std::fs::canonicalize(at).map_err(|error| Failure::new(code, error.to_string()))?;
    let bare = !work_tree
        && system
            .text(code, at, &["rev-parse", "--is-bare-repository"])?
            .trim()
            == "true";
    let held = if work_tree || bare {
        top == named
    } else {
        top == named.join(".git")
    };
    if !held {
        return Err(Failure::new(
            code,
            format!("{} is not a repository's own folder", at.display()),
        ));
    }
    let listed = system.bytes(
        code,
        at,
        &["config", "--list", "--null", "--show-scope", "--includes"],
    )?;
    let mut fields = listed.split(|byte| *byte == 0);
    while let (Some(scope), Some(entry)) = (fields.next(), fields.next()) {
        if scope == b"command" {
            continue;
        }
        let key = entry
            .split(|byte| *byte == b'\n')
            .next()
            .unwrap_or_default()
            .to_ascii_lowercase();
        let key = String::from_utf8_lossy(&key);
        if REFUSED
            .iter()
            .any(|(prefix, suffix)| key.starts_with(prefix) && key.ends_with(suffix))
        {
            return Err(Failure::new(
                "git.repository_refused",
                format!("the repository's configuration sets {key}"),
            ));
        }
    }
    Ok(())
}

/// Paths a NUL-separated Git listing names, as raw bytes; a trailing `/` (a folder Git collapsed) is kept.
pub(crate) fn entries(listing: &[u8]) -> impl Iterator<Item = &[u8]> {
    listing
        .split(|byte| *byte == 0)
        .filter(|entry| !entry.is_empty())
}

/// The branch `HEAD` names itself, one level only: `HEAD` that names a symbolic branch refuses (`git.ref_symbolic`), so no
/// primitive acts on or certifies the branch an alias points to. `None` when `HEAD` is detached.
pub(crate) fn head_branch(
    system: &NodeSystem,
    code: &'static str,
    tree: &Path,
) -> Result<Option<String>, Failure> {
    let ran = system.git(
        LOCAL_SECONDS,
        tree,
        &["symbolic-ref", "--quiet", "--no-recurse", "HEAD"],
    )?;
    match ran.code {
        Some(0) => {
            let named = String::from_utf8(ran.stdout)
                .map_err(|_| Failure::new(code, "HEAD names a ref that is not UTF-8"))?;
            let named = named.trim_end_matches('\n');
            let branch = named
                .strip_prefix("refs/heads/")
                .ok_or_else(|| Failure::new(code, "HEAD names a ref outside refs/heads"))?;
            resolve(system, code, tree, named)?;
            Ok(Some(branch.to_owned()))
        }
        Some(1) => Ok(None),
        _ => Err(Failure::new(
            code,
            format!("symbolic-ref: {}", ran.stderr.trim()),
        )),
    }
}

/// Every folder above a relative path, nearest last.
pub(crate) fn ancestors(path: &[u8]) -> impl Iterator<Item = &[u8]> {
    path.iter()
        .enumerate()
        .filter(|(_, byte)| **byte == b'/')
        .map(move |(at, _)| &path[..at])
}

/// A relative path of a listing below a folder.
pub(crate) fn below(folder: &Path, relative: &[u8]) -> PathBuf {
    folder.join(<std::ffi::OsStr as std::os::unix::ffi::OsStrExt>::from_bytes(relative))
}
