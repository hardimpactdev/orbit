//! Operator-only issuer. Native Swamp owns tokens, vault custody and expiry.
use super::*;

const DIRECTORY: &str = "/etc/orbit/swamp-issuer";
const OPERATOR: &str = "/etc/orbit/swamp-issuer/operator.token";
const API_KEY: &str = "/etc/orbit/swamp-issuer/api-key";
const NODES: &str = "/etc/orbit/swamp-issuer/nodes.json";

#[derive(schemars::JsonSchema, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Pair {
    pub enrollment_token: String,
    pub server_token: String,
    pub expires_at: String,
}

fn native(host: &mut impl Host, args: &[&str]) -> Result<serde_json::Value, Error> {
    let mut argv = vec![
        "env",
        "-i",
        "PATH=/usr/local/bin:/usr/bin:/bin",
        "HOME=/var/lib/orbit-swamp-issuer",
        "SWAMP_HOME=/var/lib/orbit-swamp-issuer",
        "SWAMP_CONFIG_DIR=/var/lib/orbit-swamp-issuer/config",
        "SWAMP_API_KEY_FILE=/etc/orbit/swamp-issuer/api-key",
        "SWAMP_NO_UPDATE_CHECK=1",
        "SWAMP_NO_TELEMETRY=1",
        BINARY,
    ];
    argv.extend_from_slice(args);
    argv.extend([
        "--server",
        "wss://swamp.orbit",
        "--token-file",
        OPERATOR,
        "--ca-cert",
        CA,
        "--json",
    ]);
    let result = recipe::run(host, &Invocation::new(&argv), false).map_err(|_| failed())?;
    if result.stdout.len() + result.stderr.len() > 65536 {
        return Err(failed());
    }
    if result.code != 0 {
        if args.get(2) == Some(&"revoke") {
            let code = format!("{}_token_revoke_failed", args[0]);
            let kind = if args[0] == "worker" {
                "Enrollment"
            } else {
                "Server"
            };
            let message = format!("Server reported {code}: {kind} token '{}' not found. Use 'swamp {} token list' to see existing tokens.", args[3], args[0]);
            if result
                .stderr
                .char_indices()
                .filter(|(_, c)| *c == '{')
                .filter_map(|(i, _)| {
                    serde_json::from_str::<serde_json::Value>(&result.stderr[i..]).ok()
                })
                .any(|v| v["code"] == code && v["error"] == message)
            {
                return Ok(serde_json::Value::Null);
            }
        }
        return Err(failed());
    }
    let output = result.stdout;
    let last = serde_json::Deserializer::from_str(&output)
        .into_iter::<serde_json::Value>()
        .last()
        .ok_or_else(failed)?
        .map_err(|_| failed())?;
    if args[0] == "model" && last["status"] != "succeeded" {
        return Err(failed());
    }
    Ok(last)
}
fn issue(files: &Files, host: &mut impl Host, node: u64) -> Result<Pair, Error> {
    let directory = fs::symlink_metadata(files.path(DIRECTORY)).map_err(|_| invalid())?;
    if !directory.is_dir() || directory.uid() != files.uid || directory.mode() & 0o777 != 0o700 {
        return Err(invalid());
    }
    owned_file(files, OPERATOR, 0o600)?;
    owned_file(files, API_KEY, 0o600)?;
    owned_file(files, NODES, 0o600)?;
    let allowed = fs::read(files.path(NODES)).map_err(|_| invalid())?;
    if allowed.len() > 4096 {
        return Err(invalid());
    }
    let allowed: Vec<u64> = serde_json::from_slice(&allowed).map_err(|_| invalid())?;
    if node == 0 || !allowed.contains(&node) {
        return Err(invalid());
    }
    private_dir(
        files,
        "/var/lib/orbit-swamp-issuer",
        files.uid,
        files.gid,
        0o700,
    )?;
    install_binary(files, host)?;
    mint_pair(host, node)
}
fn mint_pair(host: &mut impl Host, node: u64) -> Result<Pair, Error> {
    let name = format!("orbit-node-{node}");
    let access = format!("{name}-access");
    // The Gateway drains the target before requesting a pair. Names cannot
    // be supplied by a workflow or task, and no grant is created here.
    native(host, &["worker", "token", "revoke", &name])?;
    native(host, &["access", "token", "revoke", &access])?;
    const VAULT: &str = "orbit-worker-enrollment";
    let enrollment = native(
        host,
        &[
            "worker",
            "token",
            "create",
            &name,
            "--duration",
            "24h",
            "--max-enrollments",
            "1",
            "--vault",
            VAULT,
        ],
    )?;
    // Native access-token mint has no vault flag. Its built-in typed method
    // accepts the audited vault; the default internal vault is not revealable remotely.
    let arguments = serde_json::json!({"principalId":format!("worker:{name}"), "principalEmail":format!("{name}@node.invalid"), "durationMs":86400000, "vaultName":VAULT}).to_string();
    native(
        host,
        &[
            "model",
            "method",
            "run",
            "@swamp/server-token",
            "mint",
            &access,
            "--literal-inputs",
            "--input",
            &arguments,
        ],
    )?;
    let revealed = native(
        host,
        &[
            "vault",
            "read-secret",
            VAULT,
            &format!("server-token-{access}"),
            "--yes",
        ],
    )?;
    let pair = Pair {
        enrollment_token: enrollment["token"].as_str().ok_or_else(failed)?.into(),
        server_token: format!(
            "{access}.{}",
            revealed["value"].as_str().ok_or_else(failed)?
        ),
        expires_at: enrollment["expiresAt"].as_str().ok_or_else(failed)?.into(),
    };
    Ok(pair)
}
pub struct Issue;
impl Command for Issue {
    const NAME: &'static str = "swamp.issue-node-worker";
    const SUMMARY: &'static str = "Issue one allowlisted Node's short-lived worker credentials using the operator credential.";
    const MUTATES: bool = true;
    // The only success reply contains credentials. Never write an effect receipt.
    const KEYED_EFFECT: bool = false;
    const RESOURCES: &'static [&'static str] = &["swamp-node-worker-issuer"];
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = Input;
    type Output = Pair;
    type Error = Error;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::super::linux_only(p, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Pair, Error> {
        issue(
            &Files::system(),
            &mut recipe::Native(context),
            input.node_id,
        )
    }
}
