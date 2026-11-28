//! `git.fetch`: one ref of a source fetched into one ref of one repository.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    full_ref, invalid, parse, path, resolve, BlobRef, Failure, NodeSystem, REMOTE_SECONDS,
};

const CODE: &str = "git.fetch_failed";
/// The fixed askpass program: it prints the environment entry named by the prompt Git passes it as its one argument.
const ASKPASS: &str = "/usr/bin/printenv";

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

/// A credential for one host: what askpass answers when, and only when, the server asks.
#[derive(Debug)]
pub(crate) struct Credential {
    /// The environment entries askpass reads: `GIT_ASKPASS` and one answer per prompt Git asks for the host.
    env: Vec<(String, String)>,
    /// Every form of the secret, decoded and as the line encodes it, to mask in any detail.
    masks: Vec<String>,
}

/// A source whose operands are checked and whose credential is read.
enum Checked<'a> {
    Url(&'a str, Option<Credential>),
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
/// Before the repository exists, the read and the init run in a private empty folder, never inside another repository.
///
/// # Errors
///
/// `git.input_invalid`, `git.credential_missing`, `git.repository_missing`, `git.repository_refused`, `git.ref_symbolic`
/// or `git.fetch_failed`. No detail holds the credential, and a credentialed call keeps none of Git's own messages.
pub fn fetch(system: &NodeSystem, input: &Value) -> Result<Fetched, Failure> {
    let input: FetchInput = parse(input)?;
    let repository = path(&input.repository, "repository")?;
    let reference = full_ref(&input.reference)?;
    let into = full_ref(&input.into)?;
    // Every operand and the credential are checked before anything runs.
    let source = match &input.source {
        Source::Url { url, credential } => {
            let url = https(url)?;
            let credential = credential
                .as_ref()
                .map(|credential| askpass(&credential.bytes(system)?, url))
                .transpose()?;
            Checked::Url(url, credential)
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
    let masks: Vec<&str> = match &source {
        Checked::Url(_, Some(credential)) => credential.masks.iter().map(String::as_str).collect(),
        _ => Vec::new(),
    };
    let missing = if input.create {
        Missing::Create
    } else {
        Missing::Refuse
    };
    converge(system, &repository, &source, reference, into, missing)
        .map_err(|failure| failure.without(&masks))
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
    if exists {
        crate::require_repository(system, CODE, repository, crate::Kind::Repository)?;
        let before = resolve(system, CODE, repository, into)?;
        let Some(object) = listed(system, repository, source, reference)? else {
            return Ok(Fetched {
                commit: None,
                changes: 0,
            });
        };
        let held = system.text(CODE, repository, &["rev-parse", "--show-object-format"])?;
        if held.trim() != format(&object) {
            return Err(Failure::new(
                CODE,
                format!(
                    "the repository is {}, the source {}",
                    held.trim(),
                    format(&object)
                ),
            ));
        }
        let after = fetched(system, repository, source, reference, into)?;
        return Ok(Fetched {
            changes: u32::from(before.as_deref() != Some(after.as_str())),
            commit: Some(after),
        });
    }
    let scratch = Scratch::new()?;
    let Some(object) = listed(system, &scratch.0, source, reference)? else {
        return Ok(Fetched {
            commit: None,
            changes: 0,
        });
    };
    let name = repository.display().to_string();
    system.text(
        CODE,
        &scratch.0,
        &[
            "init",
            "--quiet",
            "--bare",
            &format!("--object-format={}", format(&object)),
            "--",
            &name,
        ],
    )?;
    match fetched(system, repository, source, reference, into) {
        Ok(after) => Ok(Fetched {
            commit: Some(after),
            changes: 2,
        }),
        Err(failure) => {
            // A repository this order made holds nothing worth keeping when its one fetch failed.
            let _ = std::fs::remove_dir_all(repository);
            Err(failure)
        }
    }
}

fn format(object: &str) -> &'static str {
    if object.len() == 64 {
        "sha256"
    } else {
        "sha1"
    }
}

/// A private empty folder for the calls that run before the repository exists; removed when dropped.
struct Scratch(PathBuf);

impl Scratch {
    fn new() -> Result<Self, Failure> {
        static NEXT: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map_or(0, |at| at.as_nanos());
        let folder = std::env::temp_dir().join(format!(
            "git-fetch-{}-{nanos}-{}",
            std::process::id(),
            NEXT.fetch_add(1, std::sync::atomic::Ordering::Relaxed)
        ));
        std::fs::DirBuilder::new()
            .mode(0o700)
            .create(&folder)
            .map_err(|error| Failure::new(CODE, format!("scratch: {error}")))?;
        Ok(Self(folder))
    }
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir(&self.0);
    }
}

use std::os::unix::fs::DirBuilderExt as _;

/// The settings and environment one source's calls take.
fn transport<'a>(source: &'a Checked<'_>) -> (Vec<String>, &'a [(String, String)]) {
    match source {
        Checked::Url(_, credential) => (
            vec!["protocol.https.allow=always".to_owned()],
            credential
                .as_ref()
                .map_or(&[][..], |credential| &credential.env),
        ),
        // A bundle is read through Git's file transport, and the file was checked to be a plain file.
        Checked::Bundle(_) => (vec!["protocol.file.allow=always".to_owned()], &[]),
    }
}

/// A failure of one transport call: Git's own message only when no credential took part.
fn transport_failure(source: &Checked<'_>, what: &str, ran: &crate::Ran) -> Failure {
    match source {
        Checked::Url(_, Some(_)) => Failure::new(
            CODE,
            format!(
                "{what} failed with exit {:?}; a credentialed call keeps no message",
                ran.code
            ),
        ),
        _ => Failure::new(CODE, format!("{what}: {}", ran.stderr.trim())),
    }
}

/// The object the source's ref names, or `None` when the source lacks it.
fn listed(
    system: &NodeSystem,
    at: &Path,
    source: &Checked<'_>,
    reference: &str,
) -> Result<Option<String>, Failure> {
    let (settings, env) = transport(source);
    let (args, separator): (Vec<&str>, char) = match source {
        Checked::Url(url, _) => (vec!["ls-remote", "--refs", "--", url, reference], '\t'),
        Checked::Bundle(bundle) => (vec!["bundle", "list-heads", "--", bundle], ' '),
    };
    let ran = system.run(REMOTE_SECONDS, at, &settings, env, &args)?;
    if ran.code != Some(0) {
        return Err(transport_failure(source, args[0], &ran));
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

/// Fetches `+<ref>:<into>` and answers what `into` names afterwards.
fn fetched(
    system: &NodeSystem,
    repository: &Path,
    source: &Checked<'_>,
    reference: &str,
    into: &str,
) -> Result<String, Failure> {
    let (settings, env) = transport(source);
    let from = match source {
        Checked::Url(url, _) => *url,
        Checked::Bundle(bundle) => bundle.as_str(),
    };
    let refspec = format!("+{reference}:{into}");
    let args = [
        "fetch",
        "--quiet",
        "--no-tags",
        "--no-write-fetch-head",
        "--no-recurse-submodules",
        "--",
        from,
        &refspec,
    ];
    let ran = system.run(REMOTE_SECONDS, repository, &settings, env, &args)?;
    if ran.code != Some(0) {
        return Err(transport_failure(source, "fetch", &ran));
    }
    resolve(system, CODE, repository, into)?
        .ok_or_else(|| Failure::new(CODE, "the ref is missing after the fetch"))
}

/// An https URL with a host and nothing that can carry a secret or another meaning: no user information, query, fragment,
/// percent sign, space or control byte.
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

/// The askpass answers a `store` credential line gives for the URL's host. Git asks `Username for 'https://<host>': ` and
/// `Password for 'https://<user>@<host>': ` only when the server asks for authentication, and the fixed askpass prints the
/// environment entry of that exact name; nothing is sent before. The line must name the URL's own host over https.
pub(crate) fn askpass(line: &[u8], url: &str) -> Result<Credential, Failure> {
    let refused = || invalid("the credential is not a store line for the URL's host");
    let line = std::str::from_utf8(line)
        .map_err(|_| refused())?
        .trim_end_matches(['\n', '\r']);
    let rest = line.strip_prefix("https://").ok_or_else(refused)?;
    let (userinfo, host) = rest.rsplit_once('@').ok_or_else(refused)?;
    let host = host.trim_end_matches('/');
    let wanted = url
        .strip_prefix("https://")
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("");
    if host != wanted {
        return Err(refused());
    }
    let (encoded_user, encoded_password) = userinfo.split_once(':').ok_or_else(refused)?;
    let (user, password) = (
        decode(encoded_user).ok_or_else(refused)?,
        decode(encoded_password).ok_or_else(refused)?,
    );
    if user.is_empty()
        || password.is_empty()
        || user.contains(['\0', '\n'])
        || password.contains(['\0', '\n'])
    {
        return Err(refused());
    }
    let env = vec![
        ("GIT_ASKPASS".to_owned(), ASKPASS.to_owned()),
        (format!("Username for 'https://{host}': "), user.clone()),
        (
            format!("Password for 'https://{user}@{host}': "),
            password.clone(),
        ),
    ];
    Ok(Credential {
        env,
        masks: vec![password, encoded_password.to_owned(), userinfo.to_owned()],
    })
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

#[cfg(test)]
mod unit {
    use super::*;

    /// Git's own credential prompt, through the fixed askpass and the environment the fetch would set: the answers arrive
    /// only because Git asked, and no header or configuration carries them.
    #[test]
    fn git_asks_the_fixed_askpass_and_reads_the_answers_only_when_it_prompts() {
        let credential = askpass(
            b"https://x-access-token:s%40cret@example.com:8443\n",
            "https://example.com:8443/r.git",
        )
        .expect("line");
        assert!(credential
            .env
            .iter()
            .all(|(key, _)| !key.to_ascii_lowercase().contains("header")
                && !key.starts_with("GIT_CONFIG")));
        let mut fill = std::process::Command::new("/usr/bin/git")
            .env_clear()
            .env("PATH", "/usr/bin:/bin")
            .env("HOME", "/nonexistent")
            .env("GIT_CONFIG_NOSYSTEM", "1")
            .env("GIT_CONFIG_GLOBAL", "/dev/null")
            .env("GIT_TERMINAL_PROMPT", "0")
            .envs(
                credential
                    .env
                    .iter()
                    .map(|(key, value)| (key.as_str(), value.as_str())),
            )
            .args(["-c", "credential.helper=", "credential", "fill"])
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .spawn()
            .expect("git");
        std::io::Write::write_all(
            fill.stdin.as_mut().expect("stdin"),
            b"protocol=https\nhost=example.com:8443\n\n",
        )
        .expect("ask");
        let out = String::from_utf8(fill.wait_with_output().expect("answer").stdout).expect("text");
        assert!(
            out.contains("username=x-access-token\n") && out.contains("password=s@cret\n"),
            "{out}"
        );
        assert_eq!(
            credential.masks,
            ["s@cret", "s%40cret", "x-access-token:s%40cret"]
        );
        assert!(
            askpass(b"https://u:p@other.example/", "https://example.com/r.git").is_err(),
            "another host refuses"
        );
        assert!(askpass(b"http://u:p@example.com", "https://example.com/r.git").is_err());
    }
}
