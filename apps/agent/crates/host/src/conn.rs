//! `conn.session.issue`, `conn.project.ensure` and `conn.pairing.issue`: one session, one project and one pairing link of a
//! Conn Server, through the server's own local control plane.
//!
//! Each runs the server binary the order names with a fixed argv, as the account the order names (`setpriv`, no extra
//! groups) and with an empty environment: `auth session list`, `auth session issue`, `auth pairing list`, `auth pairing
//! create` and `project add`, each with `--base-dir <home>`. The server's CLI takes no `--`, so every value it receives is
//! checked here and none starts with `-`.
//!
//! The binary must be a root-owned regular file named `conn-server` that no group or other user can write, below folders
//! that are root-owned and not group- or other-writable. It is opened once, without following a link, checked through that
//! descriptor, and run through it (`/proc/self/fd/<n>`), so the file checked is the file run.
//!
//! A token or pairing code the server returns is sealed to the Gateway's key here and travels only inside the receipt's
//! output. A failure's detail names only the fixed call and its exit status, never what the server wrote.

use std::os::fd::{AsRawFd as _, OwnedFd};
use std::path::{Component, Path};

use rustix::fs::{FileType, Mode, OFlags};
use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{absolute, invalid, parse, strings, Failure, System, NAME};

const SETPRIV: &str = "/usr/bin/setpriv";
/// The only file name a Conn Server binary may have.
const BINARY_NAME: &str = "conn-server";
/// The time limit of one call to the server's CLI.
const SECONDS: u32 = 15;
/// `timeout`'s exit code when the time limit ended the command.
const TIMED_OUT: i32 = 124;
/// The longest lifetime the server keeps: it stores a longer one, and `until-revoked`, as 3660 days.
const MAX_TTL_SECONDS: u64 = 3660 * 86_400;
/// The lifetime of the session a device redeems with a link from `auth pairing create`: the CLI takes no value for it.
const PAIRED_SESSION_TTL: &str = "30d";

/// The server binary, its state home and the account that owns that home.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Server {
    binary: String,
    home: String,
    user: String,
    group: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct SessionInput {
    server: Server,
    label: String,
    /// The session's subject: one order's own, so a second issue for it is refused.
    subject: String,
    scopes: Vec<String>,
    ttl: String,
    /// The Gateway's X25519 key, base64: the token is sealed to it.
    seal_to: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct ProjectInput {
    server: Server,
    workspace: String,
    title: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct PairingInput {
    server: Server,
    /// The link's label: one order's own, so a second issue for it is refused.
    label: String,
    scopes: Vec<String>,
    /// How long the link's code works.
    ttl: String,
    /// How long the session a device redeems with the link lasts: only the server's fixed `30d`.
    session_ttl: String,
    seal_to: String,
}

/// An issued session: its id, scopes and end in the clear, its token sealed to the Gateway.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct IssuedSession {
    /// The server's id of the session.
    pub session_id: String,
    /// The scopes the server granted.
    pub scopes: Vec<String>,
    /// `{"token"}`, sealed to the order's `seal_to`.
    pub sealed: String,
}

/// An issued pairing link: its id and end in the clear, its code sealed to the Gateway.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct IssuedPairing {
    /// The server's id of the link.
    pub pairing_id: String,
    /// When the code stops working.
    pub expires_at: String,
    /// `{"credential"}`, sealed to the order's `seal_to`.
    pub sealed: String,
}

/// Issues one session: `auth session list` first, then `auth session issue` with the order's label, subject, scopes and
/// lifetime.
///
/// # Errors
///
/// `host.input_invalid`, before anything runs; `conn.binary_untrusted`; `conn.session_issued` when the server already holds a
/// session with this subject, so a retried order never makes a second one; `conn.session_failed` when the server cannot be
/// run, times out, refuses or answers no token.
pub fn session_issue(system: &impl System, input: &Value) -> Result<IssuedSession, Failure> {
    let input: SessionInput = parse(input)?;
    checked_server(&input.server)?;
    if !label(&input.label) || !value(&input.subject) || !scopes(&input.scopes) || !ttl(&input.ttl) || !recipient(&input.seal_to) {
        return Err(invalid("label, subject, scopes, ttl or seal_to"));
    }
    let code = "conn.session_failed";
    let server = Run::open(system, &input.server, code)?;
    let list = server.json(&["auth", "session", "list", "--json"])?;
    if entries(&list, code)?.iter().any(|session| session.get("subject").and_then(Value::as_str) == Some(input.subject.as_str())) {
        return Err(Failure::new("conn.session_issued", "a session with this subject exists"));
    }
    let mut args = vec!["auth", "session", "issue", "--label", &input.label, "--subject", &input.subject, "--ttl", &input.ttl];
    for scope in &input.scopes {
        args.extend(["--scope", scope.as_str()]);
    }
    args.push("--json");
    let answer = server.json(&args)?;
    let (Some(session_id), Some(token)) = (text(&answer, "sessionId"), text(&answer, "token")) else {
        return Err(Failure::new(code, "auth session issue answered no session"));
    };
    let granted = answer.get("scopes").and_then(Value::as_array).map(|list| list.iter().filter_map(Value::as_str).map(str::to_owned).collect()).unwrap_or_default();
    let sealed = seal(&input.seal_to, &serde_json::json!({"token": token}), code)?;
    Ok(IssuedSession { session_id, scopes: granted, sealed })
}

/// Makes one folder a project of the server: reads the server's project list, runs `project add` only when no project with
/// this folder and title is listed, and reads the list back. Answers the number of changes: 0 or 1.
///
/// The server stores a title trimmed and a folder by its real path, so a padded title and a folder whose path is not its real
/// path are refused: every accepted input reads back as it was sent.
///
/// # Errors
///
/// `host.input_invalid`, before anything runs; `conn.binary_untrusted`; or `conn.project_failed` when the list cannot be read,
/// `project add` fails, or the read-back lacks the project.
pub fn project_ensure(system: &impl System, input: &Value) -> Result<u32, Failure> {
    let input: ProjectInput = parse(input)?;
    checked_server(&input.server)?;
    if !absolute(&input.workspace) || !label(&input.title) || input.title.trim() != input.title || input.title.contains('\u{feff}') {
        return Err(invalid("workspace or title"));
    }
    let real = std::fs::canonicalize(system.path(&input.workspace)).ok();
    let expected = std::fs::canonicalize(system.root()).ok().map(|root| root.join(input.workspace.trim_start_matches('/')));
    if real.is_none() || real != expected {
        return Err(invalid("workspace is not a folder at its real path"));
    }
    let code = "conn.project_failed";
    let server = Run::open(system, &input.server, code)?;
    if listed(system, &input.server, &input.workspace, &input.title)? {
        return Ok(0);
    }
    server.status(&["project", "add", &input.workspace, "--title", &input.title])?;
    if !listed(system, &input.server, &input.workspace, &input.title)? {
        return Err(Failure::new(code, "the project is not listed after project add"));
    }
    Ok(1)
}

/// Issues one pairing link: `auth pairing list` and `auth session list` first, then `auth pairing create` with the order's label, scopes and code
/// lifetime. It makes no session: a device's session comes from redeeming the link, and lasts the server's fixed 30 days.
///
/// # Errors
///
/// `host.input_invalid`, before anything runs; `conn.binary_untrusted`; `conn.pairing_issued` when the server already holds
/// a link with this label, or a session a device redeemed with it; `conn.pairing_failed` when the server cannot be run, times out, refuses or answers no code.
pub fn pairing_issue(system: &impl System, input: &Value) -> Result<IssuedPairing, Failure> {
    let input: PairingInput = parse(input)?;
    checked_server(&input.server)?;
    if !label(&input.label) || !scopes(&input.scopes) || !ttl(&input.ttl) || input.session_ttl != PAIRED_SESSION_TTL || !recipient(&input.seal_to) {
        return Err(invalid("label, scopes, ttl, session_ttl or seal_to"));
    }
    let code = "conn.pairing_failed";
    let server = Run::open(system, &input.server, code)?;
    let list = server.json(&["auth", "pairing", "list", "--json"])?;
    if entries(&list, code)?.iter().any(|link| link.get("label").and_then(Value::as_str) == Some(input.label.as_str())) {
        return Err(Failure::new("conn.pairing_issued", "a pairing link with this label exists"));
    }
    // A redeemed link leaves the pairing list; the session a device redeemed with it carries its label.
    let sessions = server.json(&["auth", "session", "list", "--json"])?;
    if entries(&sessions, code)?.iter().any(|session| session.pointer("/client/label").and_then(Value::as_str) == Some(input.label.as_str())) {
        return Err(Failure::new("conn.pairing_issued", "a session redeemed with this label exists"));
    }
    let scopes = input.scopes.join(",");
    let answer = server.json(&["auth", "pairing", "create", "--label", &input.label, "--scopes", &scopes, "--ttl", &input.ttl, "--json"])?;
    let (Some(pairing_id), Some(credential), Some(expires_at)) = (text(&answer, "id"), text(&answer, "credential"), text(&answer, "expiresAt")) else {
        return Err(Failure::new(code, "auth pairing create answered no link"));
    };
    let sealed = seal(&input.seal_to, &serde_json::json!({"credential": credential}), code)?;
    Ok(IssuedPairing { pairing_id, expires_at, sealed })
}

/// The server's CLI, run through the checked binary's descriptor as the home's account.
struct Run<'a, S> {
    system: &'a S,
    server: &'a Server,
    binary: OwnedFd,
    ids: (u32, u32),
    code: &'static str,
}

impl<'a, S: System> Run<'a, S> {
    fn open(system: &'a S, server: &'a Server, code: &'static str) -> Result<Self, Failure> {
        let binary = trusted_binary(system.root(), &server.binary, system.binary_owner())
            .map_err(|detail| Failure::new("conn.binary_untrusted", detail))?;
        let ids = (
            system.user(&server.user).map_err(|_| Failure::new(code, "the home's user is unknown"))?,
            system.group(&server.group).map_err(|_| Failure::new(code, "the home's group is unknown"))?,
        );
        Ok(Self { system, server, binary, ids, code })
    }

    /// Runs one fixed call and answers its standard output; a failure names only the call and its exit status.
    fn status(&self, words: &[&str]) -> Result<Vec<u8>, Failure> {
        let (uid, gid) = (self.ids.0.to_string(), self.ids.1.to_string());
        let path = format!("/proc/self/fd/{}", self.binary.as_raw_fd());
        let mut argv = strings(&[SETPRIV, "--reuid", &uid, "--regid", &gid, "--clear-groups", "--", &path]);
        argv.extend(words.iter().map(|word| (*word).to_owned()));
        argv.extend(strings(&["--base-dir", &self.server.home]));
        let call = words.iter().take_while(|word| !word.starts_with('-')).take(3).copied().collect::<Vec<_>>().join(" ");
        let exit = self.system.exec(SECONDS, &argv, None).map_err(|_| Failure::new(self.code, format!("{call} could not start")))?;
        match exit.code {
            Some(0) => Ok(exit.stdout),
            Some(TIMED_OUT) => Err(Failure::new(self.code, format!("{call} timed out"))),
            status => Err(Failure::new(self.code, format!("{call} exited {status:?}"))),
        }
    }

    fn json(&self, words: &[&str]) -> Result<Value, Failure> {
        let stdout = self.status(words)?;
        serde_json::from_slice(&stdout).map_err(|_| Failure::new(self.code, "the server answered no JSON"))
    }
}

/// Opens `path` below `root` without following any link and answers its descriptor, not close-on-exec so the command can
/// run it, only when every folder from `root` down is owned by `owner` and not group- or other-writable, and the file is a
/// regular file named `conn-server` with the same owner and modes.
pub(crate) fn trusted_binary(root: &Path, path: &str, owner: u32) -> Result<OwnedFd, String> {
    let trusted = |fd: &OwnedFd, kind: FileType| -> Result<(), String> {
        let stat = rustix::fs::fstat(fd).map_err(|_| "the binary's path cannot be read".to_owned())?;
        if FileType::from_raw_mode(stat.st_mode) != kind {
            return Err(if kind == FileType::Directory { "a folder of the binary's path is not a folder" } else { "the binary is not a regular file" }.to_owned());
        }
        if stat.st_uid != owner || stat.st_mode & 0o022 != 0 {
            return Err("the binary or a folder above it is not root-owned or is writable by others".to_owned());
        }
        Ok(())
    };
    let parts: Vec<&str> = Path::new(path).components().filter_map(|part| match part {
        Component::Normal(name) => name.to_str(),
        _ => None,
    }).collect();
    let Some((name, folders)) = parts.split_last() else {
        return Err("the binary has no name".to_owned());
    };
    if *name != BINARY_NAME {
        return Err("the binary is not named conn-server".to_owned());
    }
    let open = |at: &OwnedFd, part: &str, flags: OFlags| rustix::fs::openat(at, part, flags, Mode::empty()).map_err(|_| "the binary's path cannot be opened".to_owned());
    let mut at = rustix::fs::open(root, OFlags::PATH | OFlags::DIRECTORY | OFlags::CLOEXEC, Mode::empty()).map_err(|_| "the root cannot be opened".to_owned())?;
    trusted(&at, FileType::Directory)?;
    for folder in folders {
        at = open(&at, folder, OFlags::PATH | OFlags::DIRECTORY | OFlags::NOFOLLOW | OFlags::CLOEXEC)?;
        trusted(&at, FileType::Directory)?;
    }
    let binary = open(&at, name, OFlags::PATH | OFlags::NOFOLLOW)?;
    trusted(&binary, FileType::RegularFile)?;
    Ok(binary)
}

fn text(answer: &Value, field: &str) -> Option<String> {
    answer.get(field).and_then(Value::as_str).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// Whether the server's project list holds this folder under this title. The list is the server's `userdata/projects.json`
/// in its home, which `project add` writes; a missing list holds nothing.
fn listed(system: &impl System, server: &Server, workspace: &str, title: &str) -> Result<bool, Failure> {
    let code = "conn.project_failed";
    let path = system.path(&format!("{}/userdata/projects.json", server.home));
    let bytes = match std::fs::read(&path) {
        Ok(bytes) => bytes,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
        Err(_) => return Err(Failure::new(code, "the project list cannot be read")),
    };
    let list: Value = serde_json::from_slice(&bytes).map_err(|_| Failure::new(code, "the project list is not JSON"))?;
    Ok(entries(&list, code)?.iter().any(|project| {
        project.get("workspaceRoot").and_then(Value::as_str) == Some(workspace) && project.get("title").and_then(Value::as_str) == Some(title)
    }))
}

fn entries<'a>(list: &'a Value, code: &'static str) -> Result<&'a Vec<Value>, Failure> {
    list.as_array().ok_or_else(|| Failure::new(code, "the server answered no list"))
}

fn seal(recipient: &str, plaintext: &Value, code: &'static str) -> Result<String, Failure> {
    orbit_executor_core::seal::seal(recipient, plaintext.to_string().as_bytes()).map_err(|_| Failure::new(code, "the answer cannot be sealed"))
}

/// A recipient the answer can be sealed to: checked before the server makes anything.
fn recipient(key: &str) -> bool {
    orbit_executor_core::seal::seal(key, b"").is_ok()
}

fn checked_server(server: &Server) -> Result<(), Failure> {
    if !absolute(&server.binary) || !absolute(&server.home) || !NAME.is_match(&server.user) || !NAME.is_match(&server.group) {
        return Err(invalid("server binary, home, user or group"));
    }
    Ok(())
}

/// A label or title: 1 to 256 bytes, no control character, not starting with `-`.
fn label(text: &str) -> bool {
    !text.is_empty() && text.len() <= 256 && !text.starts_with('-') && !text.chars().any(char::is_control)
}

/// A subject: 1 to 128 of `A-Z a-z 0-9 : . _ -`, not starting with `-`.
fn value(text: &str) -> bool {
    !text.is_empty() && text.len() <= 128 && !text.starts_with('-') && text.bytes().all(|byte| byte.is_ascii_alphanumeric() || b":._-".contains(&byte))
}

/// 1 to 64 scopes, each lower-case letters, `:` and `-`, not starting with `-`.
fn scopes(list: &[String]) -> bool {
    !list.is_empty()
        && list.len() <= 64
        && list.iter().all(|scope| !scope.is_empty() && !scope.starts_with('-') && scope.bytes().all(|b| b.is_ascii_lowercase() || b == b':' || b == b'-'))
}

/// A lifetime the server keeps as sent: `until-revoked`, or a count without a leading zero and one of `s`, `m`, `h`, `d`
/// or `w`, from 1 second up to the server's 3660-day cap. A longer one the server would shorten without saying so.
fn ttl(text: &str) -> bool {
    if text == "until-revoked" {
        return true;
    }
    let Some((count, unit)) = text.split_at_checked(text.len().saturating_sub(1)) else {
        return false;
    };
    let seconds = match unit {
        "s" => 1,
        "m" => 60,
        "h" => 3_600,
        "d" => 86_400,
        "w" => 604_800,
        _ => return false,
    };
    !count.is_empty() && count.len() <= 9 && !count.starts_with('0') && count.bytes().all(|b| b.is_ascii_digit())
        && count.parse::<u64>().is_ok_and(|count| count * seconds <= MAX_TTL_SECONDS)
}
