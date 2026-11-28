//! `conn.session.issue`, `conn.project.ensure` and `conn.pairing.issue`: one session, one project and one pairing link of a
//! Conn Server, through the server's own local control plane.
//!
//! Each runs the server binary the order names with a fixed argv, as the account the order names (`setpriv`, no extra
//! groups) and with an empty environment: `auth session issue`, `auth session list`, `auth session revoke`, `auth pairing
//! list` and `project add`, each with `--base-dir <home>`. The server's CLI takes no `--`, so every value it receives is
//! checked here and none starts with `-`. A pairing link is minted over the server's local API on loopback with a
//! five-minute session that the same order issues, holds in memory only and revokes before it answers.
//!
//! A token or pairing code the server returns is sealed to the Gateway's key here and travels only inside the receipt's
//! output: the executor journals and logs nothing else of it, and a failure's detail never carries a server answer's body.

use std::net::SocketAddr;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{absolute, invalid, parse, strings, Failure, System, NAME};

const SETPRIV: &str = "/usr/bin/setpriv";
const CURL: &str = "/usr/bin/curl";
/// The time limit of one call to the server's CLI or local API.
const SECONDS: u32 = 15;
/// The scopes and lifetime of the session that mints one pairing link and is revoked right after.
const MINT_SCOPES: [&str; 2] = ["access:read", "access:write"];
const MINT_TTL: &str = "5m";

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
    /// The server's local API: a loopback address and port.
    address: String,
    /// The link's label: one order's own, so a second issue for it is refused.
    label: String,
    scopes: Vec<String>,
    /// How long the session a device redeems with the link lasts, such as `until-revoked`.
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
/// `host.input_invalid`; `conn.session_issued` when the server already holds a session with this subject, so a retried
/// order never makes a second one; `conn.session_failed` when the server cannot be run, refuses, answers no token, or the
/// token cannot be sealed.
pub fn session_issue(system: &impl System, input: &Value) -> Result<IssuedSession, Failure> {
    let input: SessionInput = parse(input)?;
    checked_server(&input.server)?;
    if !label(&input.label) || !value(&input.subject) || !scopes(&input.scopes) || !ttl(&input.ttl) || input.seal_to.is_empty() {
        return Err(invalid("label, subject, scopes, ttl or seal_to"));
    }
    let code = "conn.session_failed";
    let ids = ids(system, &input.server, code)?;
    if subject_held(system, &input.server, ids, &input.subject, code)? {
        return Err(Failure::new("conn.session_issued", format!("a session with subject {} exists", input.subject)));
    }
    let (session_id, token, granted) = issue(system, &input.server, ids, &input.label, &input.subject, &input.scopes, &input.ttl, code)?;
    let sealed = seal(&input.seal_to, &serde_json::json!({"token": token}), code)?;
    Ok(IssuedSession { session_id, scopes: granted, sealed })
}

/// Makes one folder a project of the server: reads the server's project list, runs `project add` only when no project with
/// this folder and title is listed, and reads the list back. Answers the number of changes: 0 or 1.
///
/// # Errors
///
/// `host.input_invalid`, or `conn.project_failed` when the list cannot be read, `project add` fails, or the read-back lacks
/// the project.
pub fn project_ensure(system: &impl System, input: &Value) -> Result<u32, Failure> {
    let input: ProjectInput = parse(input)?;
    checked_server(&input.server)?;
    if !absolute(&input.workspace) || !label(&input.title) {
        return Err(invalid("workspace or title"));
    }
    let code = "conn.project_failed";
    let ids = ids(system, &input.server, code)?;
    if listed(system, &input.server, &input.workspace, &input.title)? {
        return Ok(0);
    }
    let argv = cli(&input.server, ids, &["project", "add", &input.workspace, "--title", &input.title]);
    let exit = system.exec(SECONDS, &argv, None).map_err(|detail| Failure::new(code, detail))?;
    if exit.code != Some(0) {
        return Err(Failure::new(code, format!("project add exited {:?}: {}", exit.code, exit.stderr_text())));
    }
    if !listed(system, &input.server, &input.workspace, &input.title)? {
        return Err(Failure::new(code, "the project is not listed after project add"));
    }
    Ok(1)
}

/// Issues one pairing link: `auth pairing list` first, then a five-minute session, `POST /api/auth/pairing-token` on the
/// server's loopback address with that session as the bearer, and `auth session revoke` for it.
///
/// # Errors
///
/// `host.input_invalid`; `conn.pairing_issued` when the server already holds a link with this label; `conn.pairing_failed`
/// when the server cannot be run or reached in time, refuses, or answers no code.
pub fn pairing_issue(system: &impl System, input: &Value) -> Result<IssuedPairing, Failure> {
    let input: PairingInput = parse(input)?;
    checked_server(&input.server)?;
    let address: SocketAddr = input.address.parse().map_err(|_| invalid("address"))?;
    if !address.ip().is_loopback() || address.port() == 0 {
        return Err(invalid("address is not a loopback address and port"));
    }
    if !label(&input.label) || !scopes(&input.scopes) || !ttl(&input.session_ttl) || input.seal_to.is_empty() {
        return Err(invalid("label, scopes, session_ttl or seal_to"));
    }
    let code = "conn.pairing_failed";
    let ids = ids(system, &input.server, code)?;
    let list = run_json(system, &cli(&input.server, ids, &["auth", "pairing", "list", "--json"]), code)?;
    if entries(&list, code)?.iter().any(|link| link.get("label").and_then(Value::as_str) == Some(input.label.as_str())) {
        return Err(Failure::new("conn.pairing_issued", format!("a pairing link labelled {} exists", input.label)));
    }
    let mint_scopes: Vec<String> = MINT_SCOPES.iter().map(|scope| (*scope).to_owned()).collect();
    let (session_id, token, _) = issue(system, &input.server, ids, &input.label, &input.label, &mint_scopes, MINT_TTL, code)?;
    let minted = mint(system, &address, &token, &input);
    let revoked = system.exec(SECONDS, &cli(&input.server, ids, &["auth", "session", "revoke", &session_id]), None);
    let answer = minted?;
    match revoked {
        Ok(exit) if exit.code == Some(0) => {}
        Ok(exit) => return Err(Failure::new(code, format!("auth session revoke exited {:?}", exit.code))),
        Err(detail) => return Err(Failure::new(code, detail)),
    }
    let text = |field: &str| answer.get(field).and_then(Value::as_str).filter(|text| !text.is_empty()).map(str::to_owned);
    let (Some(pairing_id), Some(credential), Some(expires_at)) = (text("id"), text("credential"), text("expiresAt")) else {
        return Err(Failure::new(code, "the server answered no pairing link"));
    };
    let sealed = seal(&input.seal_to, &serde_json::json!({"credential": credential}), code)?;
    Ok(IssuedPairing { pairing_id, expires_at, sealed })
}

/// `POST /api/auth/pairing-token` with the bearer on curl's standard input, never in its arguments.
fn mint(system: &impl System, address: &SocketAddr, token: &str, input: &PairingInput) -> Result<Value, Failure> {
    let code = "conn.pairing_failed";
    let body = serde_json::json!({"label": input.label, "scopes": input.scopes, "sessionTtl": input.session_ttl}).to_string();
    let url = format!("http://{address}/api/auth/pairing-token");
    let argv = strings(&[CURL, "--globoff", "--silent", "--max-time", "10", "--header", "@-", "--header", "Content-Type: application/json",
        "--data-binary", &body, "--write-out", "\n%{http_code}", "--", &url]);
    let header = format!("Authorization: Bearer {token}\n");
    let exit = system.exec(SECONDS, &argv, Some(header.as_bytes())).map_err(|detail| Failure::new(code, detail))?;
    if exit.code != Some(0) {
        // curl exits 28 when its time ran out, 7 when nothing listens: the server was not reached.
        return Err(Failure::new(code, format!("the local API was not reached: curl exited {:?}", exit.code)));
    }
    let out = String::from_utf8(exit.stdout).map_err(|_| Failure::new(code, "the local API answered no text"))?;
    let (text, status) = out.rsplit_once('\n').unwrap_or(("", &out));
    if status.trim() != "200" {
        return Err(Failure::new(code, format!("the local API answered status {}", status.trim())));
    }
    serde_json::from_str(text).map_err(|_| Failure::new(code, "the local API answered no JSON"))
}

/// `auth session issue --json`: the session's id, token and granted scopes.
#[expect(clippy::too_many_arguments, reason = "one fixed argv, each value checked by its caller")]
fn issue(
    system: &impl System,
    server: &Server,
    ids: (u32, u32),
    label: &str,
    subject: &str,
    scopes: &[String],
    ttl: &str,
    code: &'static str,
) -> Result<(String, String, Vec<String>), Failure> {
    let mut args = vec!["auth", "session", "issue", "--label", label, "--subject", subject, "--ttl", ttl];
    for scope in scopes {
        args.extend(["--scope", scope.as_str()]);
    }
    args.push("--json");
    let answer = run_json(system, &cli(server, ids, &args), code)?;
    let text = |field: &str| answer.get(field).and_then(Value::as_str).filter(|text| !text.is_empty()).map(str::to_owned);
    let (Some(session_id), Some(token)) = (text("sessionId"), text("token")) else {
        return Err(Failure::new(code, "the server answered no session"));
    };
    let granted = answer.get("scopes").and_then(Value::as_array).map(|list| list.iter().filter_map(Value::as_str).map(str::to_owned).collect()).unwrap_or_default();
    Ok((session_id, token, granted))
}

fn subject_held(system: &impl System, server: &Server, ids: (u32, u32), subject: &str, code: &'static str) -> Result<bool, Failure> {
    let list = run_json(system, &cli(server, ids, &["auth", "session", "list", "--json"]), code)?;
    Ok(entries(&list, code)?.iter().any(|session| session.get("subject").and_then(Value::as_str) == Some(subject)))
}

/// Whether the server's project list holds this folder under this title. The list is the server's `userdata/projects.json`
/// in its home, which `project add` writes; a missing list holds nothing.
fn listed(system: &impl System, server: &Server, workspace: &str, title: &str) -> Result<bool, Failure> {
    let code = "conn.project_failed";
    let path = system.path(&format!("{}/userdata/projects.json", server.home));
    let bytes = match std::fs::read(&path) {
        Ok(bytes) => bytes,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(false),
        Err(error) => return Err(Failure::new(code, format!("{}: {error}", path.display()))),
    };
    let list: Value = serde_json::from_slice(&bytes).map_err(|error| Failure::new(code, format!("{}: {error}", path.display())))?;
    Ok(entries(&list, code)?.iter().any(|project| {
        project.get("workspaceRoot").and_then(Value::as_str) == Some(workspace) && project.get("title").and_then(Value::as_str) == Some(title)
    }))
}

fn entries<'a>(list: &'a Value, code: &'static str) -> Result<&'a Vec<Value>, Failure> {
    list.as_array().ok_or_else(|| Failure::new(code, "the server answered no list"))
}

fn run_json(system: &impl System, argv: &[String], code: &'static str) -> Result<Value, Failure> {
    let exit = system.exec(SECONDS, argv, None).map_err(|detail| Failure::new(code, detail))?;
    if exit.code != Some(0) {
        // Standard error is the server's message about the call; a token is only ever on standard output.
        return Err(Failure::new(code, format!("{} exited {:?}: {}", argv[CLI_COMMAND..].iter().take(3).cloned().collect::<Vec<_>>().join(" "), exit.code,
            exit.stderr_text())));
    }
    serde_json::from_slice(&exit.stdout).map_err(|_| Failure::new(code, "the server answered no JSON"))
}

/// Where the server's own arguments start in [`cli`]'s argv.
const CLI_COMMAND: usize = 8;

/// `setpriv --reuid <uid> --regid <gid> --clear-groups -- <binary> <args…> --base-dir <home>`.
fn cli(server: &Server, (uid, gid): (u32, u32), words: &[&str]) -> Vec<String> {
    let (uid, gid) = (uid.to_string(), gid.to_string());
    let mut argv = strings(&[SETPRIV, "--reuid", &uid, "--regid", &gid, "--clear-groups", "--", &server.binary]);
    argv.extend(words.iter().map(|word| (*word).to_owned()));
    argv.extend(strings(&["--base-dir", &server.home]));
    argv
}

fn ids(system: &impl System, server: &Server, code: &'static str) -> Result<(u32, u32), Failure> {
    Ok((
        system.user(&server.user).map_err(|detail| Failure::new(code, detail))?,
        system.group(&server.group).map_err(|detail| Failure::new(code, detail))?,
    ))
}

fn seal(recipient: &str, plaintext: &Value, code: &'static str) -> Result<String, Failure> {
    orbit_executor_core::seal::seal(recipient, plaintext.to_string().as_bytes()).map_err(|error| Failure::new(code, format!("seal: {error}")))
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
    !list.is_empty() && list.len() <= 64 && list.iter().all(|scope| !scope.is_empty() && !scope.starts_with('-') && scope.bytes().all(|b| b.is_ascii_lowercase() || b == b':' || b == b'-'))
}

/// `until-revoked`, or a count of minutes, hours or days such as `3650d`.
fn ttl(text: &str) -> bool {
    text == "until-revoked"
        || text.split_at_checked(text.len().saturating_sub(1)).is_some_and(|(count, unit)| {
            ["m", "h", "d"].contains(&unit) && !count.is_empty() && count.len() <= 6 && !count.starts_with('0') && count.bytes().all(|b| b.is_ascii_digit())
        })
}
