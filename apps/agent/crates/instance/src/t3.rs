//! `instance.t3`: the copy's T3 server running, its two sessions issued and checked, and its workspace a T3 project.

use serde::Deserialize;
use serde_json::Value;

use crate::prepare::{Files, Folders};
use crate::{Failure, Incus, System};

/// The workspace's writer, who runs T3 inside the copy.
const AGENT: u32 = 2750;
const SYSTEMCTL: &str = "/usr/bin/systemctl";
const CURL: &str = "/usr/bin/curl";
/// The two sessions: the Gateway's and Swamp's, each with its label.
const SESSIONS: [(&str, &str); 2] = [("gateway", "Orbit Gateway"), ("swamp", "Orbit Swamp")];

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct T3Input {
    project: String,
    name: String,
    /// T3's unit in the copy.
    unit: String,
    /// The `t3` binary in the copy.
    t3: String,
    /// T3's own port inside the copy.
    port: u16,
    workspace: String,
    title: String,
    /// The file that records the workspace's T3 project, so it is added once.
    project_mark: String,
    /// The Gateway's X25519 key: the sessions are sealed to it, so only the Gateway reads them.
    seal_to: String,
}

/// Starts T3 when it is not running, issues the two sessions while T3 comes up, waits until T3 answers, adds the workspace
/// as a T3 project once, then checks each session against T3. Answers `{"environment_id", "at_boot", "sealed"}`, where
/// `sealed` holds `{"environment", "gateway", "swamp"}` sealed to the Gateway: each session's token, status and body.
///
/// # Errors
///
/// `instance.t3_input_invalid`, `instance.t3_unready`, `instance.create_agent_failed` or `instance.t3_session_failed`.
pub fn t3(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: T3Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.t3_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.t3_input_invalid", detail))?;
    let incus = Incus { system, code: "instance.create_agent_failed" };
    let exec = |agent: bool, argv: &[&str], stdin: Option<&[u8]>| -> Result<String, Failure> {
        let mut command: Vec<String> = ["--project", &input.project, "exec", &input.name].iter().map(|part| (*part).to_owned()).collect();
        if agent {
            command.extend(["--user", "2750", "--group", "2750", "--cwd", "/home/agent", "--env", "HOME=/home/agent"].iter().map(|part| (*part).to_owned()));
        }
        command.push("--".to_owned());
        command.extend(argv.iter().map(|part| (*part).to_owned()));
        incus.run(&command, stdin)
    };
    let active = exec(false, &[SYSTEMCTL, "is-active", "--quiet", &input.unit], None).is_ok();
    if !active {
        exec(false, &[SYSTEMCTL, "daemon-reload"], None)?;
        if exec(false, &[SYSTEMCTL, "is-failed", "--quiet", &input.unit], None).is_ok() {
            exec(false, &[SYSTEMCTL, "reset-failed", &input.unit], None)?;
        }
        exec(false, &[SYSTEMCTL, "start", &input.unit], None)?;
    }
    let url = format!("http://127.0.0.1:{}", input.port);
    let ready = exec(true, &[CURL, "--fail", "--silent", "--show-error", "--max-time", "2", "--retry", "30", "--retry-delay", "1", "--retry-connrefused",
        "--retry-max-time", "30", &format!("{url}/.well-known/t3/environment")], None)
        .map_err(|failure| Failure::new("instance.t3_unready", failure.detail))?;
    let environment: Value = serde_json::from_str(&ready).map_err(|error| Failure::new("instance.t3_unready", error.to_string()))?;
    let environment_id = environment.get("environmentId").and_then(Value::as_str).filter(|id| !id.is_empty())
        .ok_or_else(|| Failure::new("instance.t3_unready", "T3 named no environment"))?.to_owned();
    let mut issued = Vec::new();
    for (name, label) in SESSIONS {
        let token = exec(true, &[&input.t3, "auth", "session", "issue", "--label", label, "--token-only"], None)?.trim().to_owned();
        if token.is_empty() || issued.iter().any(|(_, held): &(&str, String)| *held == token) {
            return Err(Failure::new("instance.t3_session_failed", format!("{name}: no distinct token")));
        }
        issued.push((name, token));
    }
    let files = Files { incus: &incus, project: &input.project, name: &input.name };
    if files.pull(&input.project_mark)?.is_none() {
        let added = exec(true, &[&input.t3, "project", "add", &input.workspace, "--title", &input.title], None)?;
        files.push(&input.project_mark, AGENT, AGENT, "0600", format!("{}\n", added.trim()).as_bytes(), Folders::Present)?;
    }
    let mut sealed = serde_json::json!({"environment": environment.to_string()});
    for (name, token) in &issued {
        // The token goes to curl on standard input as a header, never in its arguments.
        let header = format!("Authorization: Bearer {token}\n");
        let body = exec(true, &[CURL, "--silent", "--show-error", "--max-time", "5", "--header", "@-", "--write-out", "\n%{http_code}", &format!("{url}/api/auth/session")],
            Some(header.as_bytes()))
            .map_err(|failure| Failure::new("instance.t3_session_failed", failure.detail))?;
        let (text, status) = body.rsplit_once('\n').unwrap_or((&body, ""));
        let status: u16 = status.trim().parse().unwrap_or(0);
        let answer: Value = serde_json::from_str(text).unwrap_or(Value::Null);
        let scopes: Vec<&str> = answer.get("scopes").and_then(Value::as_array).map(|scopes| scopes.iter().filter_map(Value::as_str).collect()).unwrap_or_default();
        if !(200..400).contains(&status) || answer.get("authenticated") != Some(&Value::Bool(true)) || !scopes.contains(&"access:read") || !scopes.contains(&"access:write") {
            return Err(Failure::new("instance.t3_session_failed", format!("{name}: status {status}")));
        }
        sealed[*name] = serde_json::json!({"token": token, "status": status, "body": answer.to_string()});
    }
    let sealed = orbit_executor_core::seal::seal(&input.seal_to, sealed.to_string().as_bytes())
        .map_err(|error| Failure::new("instance.t3_session_failed", format!("seal: {error}")))?;
    Ok(serde_json::json!({"environment_id": environment_id, "at_boot": active, "sealed": sealed}).to_string())
}

fn check(input: &T3Input) -> Result<(), String> {
    for (field, value) in [("project", &input.project), ("name", &input.name)] {
        if !crate::name(value) {
            return Err(format!("{field} is not a name"));
        }
    }
    let unit = &input.unit;
    if unit.starts_with('-') || !unit.ends_with(".service") || !unit.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"@._-".contains(&byte)) {
        return Err("unit is not a service".to_owned());
    }
    let path = |value: &str| value.starts_with('/') && !value.split('/').any(|part| part == "..") && !value.bytes().any(|b| b.is_ascii_control() || b == b' ');
    if !path(&input.t3) || !path(&input.workspace) || !path(&input.project_mark) {
        return Err("t3, workspace and project_mark must be absolute paths".to_owned());
    }
    if input.port == 0 || input.title.is_empty() || input.title.starts_with('-') || input.title.bytes().any(|b| b.is_ascii_control()) {
        return Err("port or title is not valid".to_owned());
    }
    Ok(())
}
