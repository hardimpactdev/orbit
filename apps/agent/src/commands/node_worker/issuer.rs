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
impl Pair {
    pub fn expires(&self) -> Result<i64, Error> {
        time::OffsetDateTime::parse(
            &self.expires_at,
            &time::format_description::well_known::Rfc3339,
        )
        .map(|d| d.unix_timestamp())
        .map_err(|_| invalid())
    }
    pub fn validate(&self, now: i64) -> Result<(), Error> {
        let name = self.enrollment_token.split_once('.').ok_or_else(invalid)?.0;
        if name
            .strip_prefix("orbit-node-")
            .and_then(|v| v.parse::<u64>().ok())
            .is_none_or(|v| v == 0)
        {
            return Err(invalid());
        }
        let token = |value: &str, prefix: &str| {
            value.strip_prefix(&format!("{prefix}.")).is_some_and(|s| {
                s.len() >= 16
                    && s.len() <= 512
                    && s.bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b"_-".contains(&b))
            })
        };
        let remaining = self.expires()? - now;
        if !(72000..=86400).contains(&remaining)
            || !token(&self.enrollment_token, name)
            || !token(&self.server_token, &format!("{name}-access"))
        {
            return Err(invalid());
        }
        Ok(())
    }
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
    let output = call(host, &argv)?;
    if output.len() > 65536 {
        return Err(failed());
    }
    let mut documents = serde_json::Deserializer::from_str(&output)
        .into_iter::<serde_json::Value>()
        .collect::<Result<Vec<_>, _>>()
        .map_err(|_| failed())?;
    let last = documents.pop().ok_or_else(failed)?;
    if args.starts_with(&["model", "method", "run"]) {
        if last["status"] != "succeeded"
            || documents
                .iter()
                .any(|event| event["event"] != "definition_auto_created")
        {
            return Err(failed());
        }
    } else if !documents.is_empty() {
        return Err(failed());
    }
    Ok(last)
}
fn revoke_existing(host: &mut impl Host, group: &[&str], name: &str) -> Result<(), Error> {
    let mut list = group.to_vec();
    list.push("list");
    let values = native(host, &list)?;
    let values = values.as_array().ok_or_else(failed)?;
    if values.iter().any(|t| t["name"].as_str() == Some(name)) {
        let mut revoke = group.to_vec();
        revoke.extend(["revoke", name]);
        native(host, &revoke)?;
    }
    Ok(())
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
    revoke_existing(host, &["worker", "token"], &name)?;
    revoke_existing(host, &["access", "token"], &access)?;
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
    let expires = |v: &serde_json::Value| -> Result<i64, Error> {
        time::OffsetDateTime::parse(
            v["expiresAt"].as_str().ok_or_else(failed)?,
            &time::format_description::well_known::Rfc3339,
        )
        .map(|d| d.unix_timestamp())
        .map_err(|_| failed())
    };
    // Enrollment is minted first, so its deadline bounds the later server token.
    let deadline = expires(&enrollment)?;
    let pair = Pair {
        enrollment_token: enrollment["token"].as_str().ok_or_else(failed)?.into(),
        server_token: format!(
            "{access}.{}",
            revealed["value"].as_str().ok_or_else(failed)?
        ),
        expires_at: time::OffsetDateTime::from_unix_timestamp(deadline)
            .map_err(|_| failed())?
            .format(&time::format_description::well_known::Rfc3339)
            .map_err(|_| failed())?,
    };
    pair.validate(time::OffsetDateTime::now_utc().unix_timestamp())?;
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

#[cfg(test)]
mod tests {
    use super::*;
    use recipe::{Failure, Output as HostOutput};

    struct Fake {
        calls: Vec<Vec<String>>,
        expires: String,
    }
    impl Host for Fake {
        fn execute(&mut self, call: &Invocation) -> Result<HostOutput, Failure> {
            self.calls.push(call.argv.clone());
            let first = call.argv.iter().position(|v| v == BINARY).unwrap() + 1;
            let args = &call.argv[first..];
            let value = match args[0].as_str() {
                "worker" if args[2] == "list" => serde_json::json!([{"name":"orbit-node-71"}]),
                "access" if args[2] == "list" => {
                    serde_json::json!([{"name":"orbit-node-71-access"}])
                }
                "worker" if args[2] == "create" => {
                    serde_json::json!({"expiresAt":self.expires,"token":"orbit-node-71.enrollmentSentinel"})
                }
                "vault" => serde_json::json!({"value":"serverSentinel1234"}),
                _ => serde_json::json!({}),
            };
            Ok(HostOutput {
                stdout: if args[0] == "model" {
                    format!(
                        "{}\n{}",
                        serde_json::json!({"event":"definition_auto_created","definitionName":"orbit-node-71-access"}),
                        serde_json::json!({"status":"succeeded"})
                    )
                } else {
                    value.to_string()
                },
                ..HostOutput::default()
            })
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("no waits");
        }
    }
    #[test]
    fn native_issuer_uses_one_machine_worker_principal_and_audited_vault_without_secret_argv() {
        let expires = (time::OffsetDateTime::now_utc() + time::Duration::hours(24))
            .format(&time::format_description::well_known::Rfc3339)
            .unwrap();
        let mut host = Fake {
            calls: vec![],
            expires,
        };
        let pair = mint_pair(&mut host, 71).unwrap();
        assert!(pair.enrollment_token.starts_with("orbit-node-71."));
        let text = serde_json::to_string(&host.calls).unwrap();
        assert!(!text.contains("enrollmentSentinel"));
        assert!(!text.contains("serverSentinel"));
        assert!(text.contains("--max-enrollments"));
        let create = host
            .calls
            .iter()
            .find(|c| c.iter().any(|a| a == "create"))
            .unwrap();
        let duration = create.iter().position(|a| a == "--duration").unwrap();
        assert_eq!(create[duration + 1], "24h");
        let method = host
            .calls
            .iter()
            .find(|c| c.iter().any(|a| a == "@swamp/server-token"))
            .unwrap();
        let input = method.iter().position(|a| a == "--input").unwrap() + 1;
        let value: serde_json::Value = serde_json::from_str(&method[input]).unwrap();
        assert_eq!(value["principalId"], "worker:orbit-node-71");
        assert_eq!(value["vaultName"], "orbit-worker-enrollment");
        assert_eq!(value["durationMs"], 86400000);
        let entry = crate::command::registry::find("swamp.issue-node-worker").unwrap();
        let result = (entry.run)(
            serde_json::json!({"node_id":71,"effect_key":"op-71.issue"}),
            &Context::new(
                Platform::Linux,
                std::time::Instant::now() + Duration::from_secs(1),
            ),
        );
        assert_eq!(result.to_json()["error"]["code"], "agent.invalid_input");
    }
    #[test]
    fn missing_operator_custody_refuses_before_any_native_command() {
        let sandbox = super::super::tests::Sandbox::new();
        let mut host = Fake {
            calls: vec![],
            expires: String::new(),
        };
        assert!(issue(&sandbox.0, &mut host, 71).is_err());
        assert!(host.calls.is_empty());
    }
}
