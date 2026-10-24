//! A fixed GitHub credential, never caller-selected Git configuration or process environment.
use base64::Engine;
use serde::{Deserialize, Deserializer};
use std::{collections::BTreeMap, process::Command};

#[derive(Default, Clone)]
pub struct GitRead {
    github_token: Option<String>,
}
impl<'de> Deserialize<'de> for GitRead {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        #[derive(Deserialize)]
        #[serde(deny_unknown_fields)]
        struct Input {
            github_token: Option<String>,
        }
        let input = Input::deserialize(deserializer)?;
        if input.github_token.as_ref().is_some_and(|token| {
            token.is_empty()
                || token.len() > 4096
                || !token
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || b"_-".contains(&byte))
        }) {
            return Err(serde::de::Error::custom("invalid GitHub read credential"));
        }
        Ok(Self {
            github_token: input.github_token,
        })
    }
}
impl GitRead {
    pub fn authenticated(&self) -> bool {
        self.github_token.is_some()
    }

    pub fn configure(&self, command: &mut Command) {
        // Keep repository programs and inherited credential helpers out of every Orbit Git read.
        for setting in [
            "core.hooksPath=/dev/null",
            "core.fsmonitor=false",
            "core.alternateRefsCommand=true",
            "core.sshCommand=ssh",
            "core.askPass=",
            "core.gitProxy=",
            "credential.helper=",
            "uploadpack.packObjectsHook=",
            "protocol.ext.allow=never",
        ] {
            command.args(["-c", setting]);
        }
        command
            .env("GIT_OPTIONAL_LOCKS", "0")
            .env("GIT_TERMINAL_PROMPT", "0");
        if self.authenticated() {
            command.args(self.fetch_options(&[]));
        }
        command.envs(self.environment());
    }

    /// Pin the permitted transports; a credential always restricts the read to HTTPS.
    pub fn fetch_options(&self, protocols: &[String]) -> Vec<String> {
        let mut settings = vec![
            "protocol.allow=never".to_owned(),
            "http.followRedirects=false".to_owned(),
        ];
        for protocol in ["https", "http", "ssh", "git", "file", "ext", "ftp", "ftps"] {
            let allowed = if self.authenticated() {
                protocol == "https"
            } else {
                protocol != "ext" && protocols.iter().any(|allowed| allowed == protocol)
            };
            settings.push(format!(
                "protocol.{protocol}.allow={}",
                if allowed { "always" } else { "never" }
            ));
        }
        settings
            .into_iter()
            .flat_map(|setting| ["-c".into(), setting])
            .collect()
    }

    fn environment(&self) -> BTreeMap<String, String> {
        let Some(token) = &self.github_token else {
            return BTreeMap::new();
        };
        let entries = [
            (
                "http.https://github.com/.extraheader",
                format!(
                    "Authorization: Basic {}",
                    base64::engine::general_purpose::STANDARD
                        .encode(format!("x-access-token:{token}"))
                ),
            ),
            (
                "url.https://github.com/.insteadOf",
                "git@github.com:".into(),
            ),
            (
                "url.https://github.com/.insteadOf",
                "ssh://git@github.com/".into(),
            ),
        ];
        let mut environment =
            BTreeMap::from([("GIT_CONFIG_COUNT".into(), entries.len().to_string())]);
        for (index, (key, value)) in entries.into_iter().enumerate() {
            environment.insert(format!("GIT_CONFIG_KEY_{index}"), key.into());
            environment.insert(format!("GIT_CONFIG_VALUE_{index}"), value);
        }
        environment
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn rejects_environment_maps_extra_fields_and_invalid_tokens_without_echoing_them() {
        for input in [
            serde_json::json!({"GIT_SSH_COMMAND":"touch /tmp/pwned"}),
            serde_json::json!({"github_token":"ghs_test","GIT_CONFIG_COUNT":"0"}),
            serde_json::json!({"github_token":"secret\nprogram"}),
            serde_json::json!({"github_token":""}),
            serde_json::json!({"github_token":"x".repeat(4097)}),
        ] {
            let error = serde_json::from_value::<GitRead>(input)
                .err()
                .expect("unsafe input accepted");
            assert!(!error.to_string().contains("secret"));
        }
    }
    #[test]
    fn constructs_only_fixed_settings_and_keeps_token_out_of_arguments() {
        let read: GitRead =
            serde_json::from_value(serde_json::json!({"github_token":"ghs_sentinel"})).unwrap();
        let mut command = Command::new("git");
        read.configure(&mut command);
        let args: Vec<_> = command.get_args().map(|s| s.to_str().unwrap()).collect();
        assert!(!args.join(" ").contains("ghs_sentinel"));
        for setting in [
            "credential.helper=",
            "core.hooksPath=/dev/null",
            "protocol.file.allow=never",
            "protocol.https.allow=always",
            "http.followRedirects=false",
        ] {
            assert!(args.contains(&setting));
        }
        let environment = read.environment();
        assert_eq!(environment.len(), 7);
        assert_eq!(environment["GIT_CONFIG_COUNT"], "3");
        assert_eq!(
            environment["GIT_CONFIG_KEY_0"],
            "http.https://github.com/.extraheader"
        );
        assert_eq!(
            environment["GIT_CONFIG_VALUE_0"],
            "Authorization: Basic eC1hY2Nlc3MtdG9rZW46Z2hzX3NlbnRpbmVs"
        );
        assert_eq!(environment["GIT_CONFIG_VALUE_1"], "git@github.com:");
        assert_eq!(environment["GIT_CONFIG_VALUE_2"], "ssh://git@github.com/");
    }
    #[test]
    fn anonymous_read_has_no_credential_configuration() {
        for input in [
            serde_json::json!({}),
            serde_json::json!({"github_token":null}),
        ] {
            let read: GitRead = serde_json::from_value(input).unwrap();
            assert!(!read.authenticated());
            assert!(read.environment().is_empty());
        }
    }
    #[test]
    fn credential_cannot_follow_a_repository_rewrite_to_file_even_when_caller_allows_it() {
        let work = crate::commands::recipe::WorkDir::new().unwrap();
        let repo = git2::Repository::init(work.0.join("checkout")).unwrap();
        let remote = work.0.join("private.git");
        git2::Repository::init_bare(&remote).unwrap();
        repo.config()
            .unwrap()
            .set_str(
                &format!("url.{}.insteadOf", remote.display()),
                "https://github.com/acme/site.git",
            )
            .unwrap();
        let read: GitRead =
            serde_json::from_value(serde_json::json!({"github_token":"ghs_sentinel"})).unwrap();
        for _ in 0..2 {
            let mut command = Command::new("git");
            read.configure(&mut command);
            let output = command
                .args(read.fetch_options(&["file".into(), "ssh".into(), "ext".into()]))
                .args([
                    "-C",
                    repo.workdir().unwrap().to_str().unwrap(),
                    "ls-remote",
                    "--",
                    "https://github.com/acme/site.git",
                ])
                .env("GIT_CONFIG_GLOBAL", "/dev/null")
                .env("GIT_CONFIG_NOSYSTEM", "1")
                .output()
                .unwrap();
            assert!(!output.status.success());
            let error = String::from_utf8_lossy(&output.stderr);
            assert!(error.contains("transport 'file' not allowed"), "{error}");
            assert!(!error.contains("ghs_sentinel"));
        }
        assert!(!remote.join("refs/heads/main").exists());
    }

    #[test]
    fn repository_configuration_cannot_restore_programs_or_credentials() {
        let work = crate::commands::recipe::WorkDir::new().unwrap();
        let repo = git2::Repository::init(work.0.join("checkout")).unwrap();
        let settings = [
            ("core.hooksPath", "/dev/null"),
            ("core.fsmonitor", "false"),
            ("core.alternateRefsCommand", "true"),
            ("core.sshCommand", "ssh"),
            ("core.askPass", ""),
            ("core.gitProxy", ""),
            ("credential.helper", ""),
            ("uploadpack.packObjectsHook", ""),
        ];
        for (key, _) in settings {
            repo.config()
                .unwrap()
                .set_str(key, "untrusted-program")
                .unwrap();
        }
        for (key, expected) in settings {
            let mut command = Command::new("git");
            GitRead::default().configure(&mut command);
            let output = command
                .args([
                    "-C",
                    repo.workdir().unwrap().to_str().unwrap(),
                    "config",
                    "--get",
                    key,
                ])
                .output()
                .unwrap();
            assert!(output.status.success());
            assert_eq!(
                String::from_utf8(output.stdout).unwrap().trim(),
                expected,
                "{key}"
            );
        }
    }
}
