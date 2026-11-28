//! `git.fetch`: one ref of a source fetched into one ref of one repository.

use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    full_ref, invalid, parse, path, resolve, BlobRef, Failure, NodeSystem, REMOTE_SECONDS,
};

const CODE: &str = "git.fetch_failed";

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
    /// Makes a missing repository bare, in the source's object format, once the source is known to hold the ref.
    #[serde(default)]
    create: bool,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields, rename_all = "snake_case")]
enum Source {
    /// An https remote, with an optional `store` credential line (`https://<user>:<password>@<host>`) as a blob.
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
    /// The URL and, with a credential, the `Authorization` header and the password to mask.
    Url(&'a str, Option<(String, String)>),
    Bundle(String),
}

/// What a fetch does with a missing repository.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Missing {
    Create,
    Refuse,
}

/// What a fetch answers: the commit the ref now names, `null` when the source lacks the ref, and the changes made.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Fetched {
    /// The object `into` names after the fetch, or `None` when the source has no such ref.
    pub commit: Option<String>,
    /// 0 when `into` already named the source's object, else 1 (2 when the repository was made too).
    pub changes: u32,
}

/// Fetches the source's ref into `into`, forced, so `into` mirrors the source. The source's ref and object format are read
/// first: a source without the ref changes nothing and makes no repository, and a repository is made in the source's format.
///
/// # Errors
///
/// `git.input_invalid`, `git.credential_missing`, `git.repository_missing`, `git.repository_refused`, `git.ref_symbolic`
/// or `git.fetch_failed`. No detail holds the credential.
pub fn fetch(system: &NodeSystem, input: &Value) -> Result<Fetched, Failure> {
    let input: FetchInput = parse(input)?;
    let repository = path(&input.repository, "repository")?;
    let reference = full_ref(&input.reference)?;
    let into = full_ref(&input.into)?;
    // Every operand and the credential are checked before anything runs.
    let source = match &input.source {
        Source::Url { url, credential } => {
            let url = https(url)?;
            let header = credential
                .as_ref()
                .map(|credential| authorization(&credential.bytes(system)?, url))
                .transpose()?;
            Checked::Url(url, header)
        }
        Source::Bundle { path: bundle } => {
            let bundle = path(bundle, "bundle")?;
            let plain = std::fs::symlink_metadata(&bundle)
                .map_err(|error| Failure::new(CODE, format!("bundle: {error}")))?
                .is_file();
            if !plain {
                return Err(invalid("bundle is not a plain file"));
            }
            Checked::Bundle(bundle.display().to_string())
        }
    };
    let secret = match &source {
        Checked::Url(_, Some((_, password))) => vec![password.as_str()],
        _ => Vec::new(),
    };
    let result = converge(
        system,
        &repository,
        &source,
        reference,
        into,
        if input.create {
            Missing::Create
        } else {
            Missing::Refuse
        },
    );
    result.map_err(|failure| failure.without(&secret))
}

fn converge(
    system: &NodeSystem,
    repository: &Path,
    source: &Checked<'_>,
    reference: &str,
    into: &str,
    missing: Missing,
) -> Result<Fetched, Failure> {
    let exists = repository.exists();
    if !exists && missing == Missing::Refuse {
        return Err(Failure::new(
            "git.repository_missing",
            repository.display().to_string(),
        ));
    }
    let parent = repository
        .parent()
        .ok_or_else(|| invalid("repository has no parent"))?;
    if exists {
        crate::require_repository(system, CODE, repository, crate::Kind::Repository)?;
    }
    let before = if exists {
        resolve(system, CODE, repository, into)?
    } else {
        None
    };
    let at = if exists { repository } else { parent };
    let Some(object) = listed(system, at, source, reference)? else {
        return Ok(Fetched {
            commit: None,
            changes: 0,
        });
    };
    let format = if object.len() == 64 { "sha256" } else { "sha1" };
    let mut changes = 0;
    if exists {
        let held = system.text(CODE, repository, &["rev-parse", "--show-object-format"])?;
        if held.trim() != format {
            return Err(Failure::new(
                CODE,
                format!("the repository is {}, the source {format}", held.trim()),
            ));
        }
    } else {
        let name = repository.display().to_string();
        system.text(
            CODE,
            parent,
            &[
                "init",
                "--quiet",
                "--bare",
                &format!("--object-format={format}"),
                "--",
                &name,
            ],
        )?;
        changes += 1;
    }
    let refspec = format!("+{reference}:{into}");
    let fetched = run_fetch(system, repository, source, &refspec)
        .and_then(|()| resolve(system, CODE, repository, into));
    let after = match fetched {
        Ok(Some(after)) => after,
        failed => {
            // A repository this order made holds nothing worth keeping when its one fetch failed.
            if !exists {
                let _ = std::fs::remove_dir_all(repository);
            }
            return Err(failed
                .err()
                .unwrap_or_else(|| Failure::new(CODE, "the ref is missing after the fetch")));
        }
    };
    if before.as_deref() != Some(after.as_str()) {
        changes += 1;
    }
    Ok(Fetched {
        commit: Some(after),
        changes,
    })
}

/// The settings and secret environment one source's calls take.
fn transport(source: &Checked<'_>) -> (Vec<String>, Vec<(String, String)>) {
    match source {
        Checked::Url(url, header) => (
            vec!["protocol.https.allow=always".to_owned()],
            header
                .iter()
                .map(|(value, _)| (format!("http.{url}.extraHeader"), value.clone()))
                .collect(),
        ),
        // A bundle is read through Git's file transport, and the file was checked to be a plain file.
        Checked::Bundle(_) => (vec!["protocol.file.allow=always".to_owned()], Vec::new()),
    }
}

/// The object the source's ref names, or `None` when the source lacks it.
fn listed(
    system: &NodeSystem,
    at: &Path,
    source: &Checked<'_>,
    reference: &str,
) -> Result<Option<String>, Failure> {
    let (settings, secret) = transport(source);
    let (args, separator): (Vec<&str>, char) = match source {
        Checked::Url(url, _) => (vec!["ls-remote", "--refs", "--", url, reference], '\t'),
        Checked::Bundle(bundle) => (vec!["bundle", "list-heads", "--", bundle], ' '),
    };
    let ran = system.run(REMOTE_SECONDS, at, &settings, &secret, &args)?;
    if ran.code != Some(0) {
        return Err(Failure::new(
            CODE,
            format!("{}: {}", args[0], ran.stderr.trim()),
        ));
    }
    let text = String::from_utf8_lossy(&ran.stdout);
    let object = text.lines().find_map(|line| {
        line.split_once(separator)
            .filter(|(_, name)| *name == reference)
            .map(|(object, _)| object.to_owned())
    });
    object
        .map(|object| {
            crate::commit(&object)
                .map(str::to_owned)
                .map_err(|_| Failure::new(CODE, "the source answered no object id"))
        })
        .transpose()
}

fn run_fetch(
    system: &NodeSystem,
    repository: &Path,
    source: &Checked<'_>,
    refspec: &str,
) -> Result<(), Failure> {
    let (settings, secret) = transport(source);
    let from = match source {
        Checked::Url(url, _) => *url,
        Checked::Bundle(bundle) => bundle.as_str(),
    };
    let ran = system.run(
        REMOTE_SECONDS,
        repository,
        &settings,
        &secret,
        &[
            "fetch",
            "--quiet",
            "--no-tags",
            "--no-write-fetch-head",
            "--no-recurse-submodules",
            "--",
            from,
            refspec,
        ],
    )?;
    if ran.code == Some(0) {
        Ok(())
    } else {
        Err(Failure::new(CODE, format!("fetch: {}", ran.stderr.trim())))
    }
}

/// An https URL with a host and nothing that can carry a secret or another meaning: no user information, query, fragment,
/// space or control byte.
fn https(url: &str) -> Result<&str, Failure> {
    let host = url
        .strip_prefix("https://")
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("");
    if host.is_empty()
        || url.contains(['@', '?', '#', '%'])
        || url
            .bytes()
            .any(|byte| byte.is_ascii_whitespace() || byte.is_ascii_control())
    {
        return Err(invalid("url is not a plain https URL"));
    }
    Ok(url)
}

/// The `Authorization` header a `store` credential line gives for the URL, and the password to mask. The line must name
/// the URL's own host over https.
fn authorization(line: &[u8], url: &str) -> Result<(String, String), Failure> {
    let refused = || invalid("the credential is not a store line for the URL's host");
    let line = std::str::from_utf8(line)
        .map_err(|_| refused())?
        .trim_end_matches(['\n', '\r']);
    let rest = line.strip_prefix("https://").ok_or_else(refused)?;
    let (userinfo, host) = rest.rsplit_once('@').ok_or_else(refused)?;
    let wanted = url
        .strip_prefix("https://")
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("");
    if host.trim_end_matches('/') != wanted {
        return Err(refused());
    }
    let (user, password) = userinfo.split_once(':').ok_or_else(refused)?;
    let (user, password) = (
        decode(user).ok_or_else(refused)?,
        decode(password).ok_or_else(refused)?,
    );
    if password.is_empty() {
        return Err(refused());
    }
    Ok((
        format!(
            "Authorization: Basic {}",
            base64(format!("{user}:{password}").as_bytes())
        ),
        password,
    ))
}

/// Percent-decoding of a credential line's user or password.
fn decode(value: &str) -> Option<String> {
    let bytes = value.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut at = 0;
    while at < bytes.len() {
        if bytes[at] == b'%' {
            let hex = std::str::from_utf8(bytes.get(at + 1..at + 3)?).ok()?;
            out.push(u8::from_str_radix(hex, 16).ok()?);
            at += 3;
        } else {
            out.push(bytes[at]);
            at += 1;
        }
    }
    String::from_utf8(out).ok()
}

fn base64(bytes: &[u8]) -> String {
    const ALPHABET: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut out = String::new();
    for chunk in bytes.chunks(3) {
        let triple = chunk.iter().enumerate().fold(0u32, |acc, (index, byte)| {
            acc | u32::from(*byte) << (16 - 8 * index)
        });
        for index in 0..4 {
            if index <= chunk.len() {
                out.push(char::from(
                    ALPHABET[usize::try_from((triple >> (18 - 6 * index)) & 63).unwrap_or(0)],
                ));
            } else {
                out.push('=');
            }
        }
    }
    out
}

#[cfg(test)]
mod unit {
    #[test]
    fn base64_and_the_authorization_header() {
        assert_eq!(super::base64(b"user:pass"), "dXNlcjpwYXNz");
        assert_eq!(super::base64(b"ab"), "YWI=");
        let (header, password) = super::authorization(
            b"https://x-access-token:s%40cret@example.com\n",
            "https://example.com/r.git",
        )
        .expect("line");
        assert_eq!(
            (header.as_str(), password.as_str()),
            (
                "Authorization: Basic eC1hY2Nlc3MtdG9rZW46c0BjcmV0",
                "s@cret"
            )
        );
        assert!(
            super::authorization(b"https://u:p@other.example/", "https://example.com/r.git")
                .is_err(),
            "another host refuses"
        );
        assert!(
            super::authorization(b"http://u:p@example.com", "https://example.com/r.git").is_err()
        );
    }
}
