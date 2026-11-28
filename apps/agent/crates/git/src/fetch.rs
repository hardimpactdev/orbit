//! `git.fetch`: one ref of a source fetched into one ref of one repository.

use std::io::Write as _;
use std::os::unix::fs::OpenOptionsExt as _;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    full_ref, invalid, parse, path, resolve, BlobRef, Failure, NodeSystem, LOCAL_SECONDS,
    REMOTE_SECONDS,
};

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct FetchInput {
    repository: String,
    source: Source,
    /// The ref the source holds, such as `refs/heads/main`.
    #[serde(rename = "ref")]
    reference: String,
    /// The ref it is stored as in the repository.
    into: String,
    /// Makes a missing repository bare before the fetch; without it a missing repository fails.
    #[serde(default)]
    create: bool,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields, rename_all = "snake_case")]
enum Source {
    /// An https remote, with an optional `store` credential line as a blob.
    Url {
        url: String,
        #[serde(default)]
        credential: Option<BlobRef>,
    },
    /// A bundle file on this host: the bundle transfer between two repositories.
    Bundle { path: String },
}

/// A source whose operands are checked and whose credential is read.
enum Checked<'a> {
    Url(&'a str, Option<Vec<u8>>),
    Bundle(String),
}

/// What a fetch answers: the commit the ref now names, `null` when the source lacks the ref, and the changes made.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Fetched {
    /// The object `into` names after the fetch, or `None` when the source has no such ref.
    pub commit: Option<String>,
    /// 0 when `into` already named the source's object, else 1 (2 when the repository was made too).
    pub changes: u32,
}

/// Fetches the source's ref into `into`, forced, so `into` mirrors the source. A source without the ref changes nothing.
///
/// # Errors
///
/// `git.input_invalid`, `git.credential_missing`, `git.repository_missing` or `git.fetch_failed`.
pub fn fetch(system: &NodeSystem, input: &Value) -> Result<Fetched, Failure> {
    let input: FetchInput = parse(input)?;
    let repository = path(&input.repository, "repository")?;
    let reference = full_ref(&input.reference)?;
    let into = full_ref(&input.into)?;
    // Every operand and the credential are checked before anything changes.
    let source = match &input.source {
        Source::Url { url, credential } => Checked::Url(
            https(url)?,
            credential
                .as_ref()
                .map(|credential| credential.bytes(system))
                .transpose()?,
        ),
        Source::Bundle { path: bundle } => {
            let bundle = path(bundle, "bundle")?;
            let plain = std::fs::symlink_metadata(&bundle)
                .map_err(|error| Failure::new("git.fetch_failed", format!("bundle: {error}")))?
                .is_file();
            if !plain {
                return Err(invalid("bundle is not a plain file"));
            }
            Checked::Bundle(bundle.display().to_string())
        }
    };
    let mut changes = 0;
    if !repository.exists() {
        if !input.create {
            return Err(Failure::new(
                "git.repository_missing",
                repository.display().to_string(),
            ));
        }
        let parent = repository
            .parent()
            .ok_or_else(|| invalid("repository has no parent"))?;
        let name = repository.display().to_string();
        system.git_ok(
            "git.fetch_failed",
            LOCAL_SECONDS,
            parent,
            &[],
            &["init", "--quiet", "--bare", "--", &name],
        )?;
        changes += 1;
    }
    crate::require_repository(
        system,
        "git.fetch_failed",
        &repository,
        crate::Kind::Repository,
    )?;
    let before = resolve(system, "git.fetch_failed", &repository, into)?;
    let refspec = format!("+{reference}:{into}");
    let fetched = match &source {
        Checked::Url(url, line) => remote(
            system,
            &repository,
            url,
            line.as_deref(),
            reference,
            &refspec,
        )?,
        Checked::Bundle(bundle) => from_bundle(system, &repository, bundle, reference, &refspec)?,
    };
    if !fetched {
        return Ok(Fetched {
            commit: None,
            changes,
        });
    }
    let after = resolve(system, "git.fetch_failed", &repository, into)?;
    if after.is_none() {
        return Err(Failure::new(
            "git.fetch_failed",
            "the ref is missing after the fetch",
        ));
    }
    if after != before {
        changes += 1;
    }
    Ok(Fetched {
        commit: after,
        changes,
    })
}

/// An https URL with no space, control byte, option or other transport.
fn https(url: &str) -> Result<&str, Failure> {
    let host = url
        .strip_prefix("https://")
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("");
    if host.is_empty()
        || host.contains('@')
        || url
            .bytes()
            .any(|byte| byte.is_ascii_whitespace() || byte.is_ascii_control())
    {
        return Err(invalid("url is not an https URL without user information"));
    }
    Ok(url)
}

fn remote(
    system: &NodeSystem,
    repository: &Path,
    url: &str,
    line: Option<&[u8]>,
    reference: &str,
    refspec: &str,
) -> Result<bool, Failure> {
    let failed = |detail: String| Failure::new("git.fetch_failed", detail);
    // The credential lives root-only in the repository's own folder for the two calls, and goes after them whatever they did.
    let credential = line
        .map(|line| {
            let file = repository.join(format!("orbit-credential-{}", std::process::id()));
            std::fs::File::options()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&file)
                .and_then(|mut handle| handle.write_all(line))
                .map(|()| file)
        })
        .transpose()
        .map_err(|error| failed(format!("credential: {error}")))?;
    let mut settings = vec!["protocol.https.allow=always".to_owned()];
    if let Some(file) = &credential {
        settings.push(format!("credential.helper=store --file={}", file.display()));
    }
    let result = (|| {
        let heads = system.git_ok(
            "git.fetch_failed",
            REMOTE_SECONDS,
            repository,
            &settings,
            &["ls-remote", "--refs", "--", url, reference],
        )?;
        if !heads
            .lines()
            .any(|line| line.split('\t').nth(1) == Some(reference))
        {
            return Ok(false);
        }
        system.git_ok(
            "git.fetch_failed",
            REMOTE_SECONDS,
            repository,
            &settings,
            &[
                "fetch",
                "--quiet",
                "--no-tags",
                "--no-write-fetch-head",
                "--",
                url,
                refspec,
            ],
        )?;
        Ok(true)
    })();
    if let Some(file) = credential {
        let _ = std::fs::remove_file(file);
    }
    result
}

fn from_bundle(
    system: &NodeSystem,
    repository: &Path,
    bundle: &str,
    reference: &str,
    refspec: &str,
) -> Result<bool, Failure> {
    // A bundle is read through Git's file transport; nothing else is allowed, and the file was checked to be a plain file.
    let settings = ["protocol.file.allow=always".to_owned()];
    system.git_ok(
        "git.fetch_failed",
        LOCAL_SECONDS,
        repository,
        &settings,
        &["bundle", "verify", "--quiet", "--", bundle],
    )?;
    let heads = system.git_ok(
        "git.fetch_failed",
        LOCAL_SECONDS,
        repository,
        &settings,
        &["bundle", "list-heads", "--", bundle],
    )?;
    if !heads
        .lines()
        .any(|line| line.split(' ').nth(1) == Some(reference))
    {
        return Ok(false);
    }
    system.git_ok(
        "git.fetch_failed",
        REMOTE_SECONDS,
        repository,
        &settings,
        &[
            "fetch",
            "--quiet",
            "--no-tags",
            "--no-write-fetch-head",
            "--",
            bundle,
            refspec,
        ],
    )?;
    Ok(true)
}
