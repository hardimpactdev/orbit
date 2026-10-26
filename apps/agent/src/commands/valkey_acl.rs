//! `database.write-valkey-acl`: writes `/data/users.acl` into the Docker volume of a Valkey Database server, before
//! Valkey starts from it, or again to repair it (ADR 0221). A short-lived container without a network mounts the
//! volume and reads the file from standard input, so the file never reaches an argument list, and replaces the old
//! file with one rename. The first line is `default` with the SHA-256 hash of the admin password and every right, or
//! `nopass` during explicit adoption of an open server. Each other line is a managed user in Orbit's frame: one
//! password hash, key patterns, no channels, and the allowed commands between `-@all` and the closing denials.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    io::Write,
    process::{Command as Process, Stdio},
    time::Duration,
};

const WRITE: &str =
    "umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl";

command_errors! {
    pub enum ValkeyAclError {
        Invalid { check: String } => "database.valkey_acl_invalid", "The ACL request does not pass its checks.";
        Failed { exit_code: i32 } => "database.valkey_acl_failed", "The ACL container failed.";
    }
}

fn invalid(check: &str) -> ValkeyAclError {
    ValkeyAclError::Invalid {
        check: check.into(),
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct AclInput {
    pub volume: String,
    pub image: String,
    pub acl: String,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Written {}

fn volume(name: &str) -> bool {
    (2..=255).contains(&name.len())
        && name.as_bytes()[0].is_ascii_alphanumeric()
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'.' | b'-'))
}

fn image(name: &str) -> bool {
    name.strip_prefix("valkey/valkey:").is_some_and(|tag| {
        (1..=128).contains(&tag.len())
            && (tag.as_bytes()[0].is_ascii_alphanumeric() || tag.as_bytes()[0] == b'_')
            && tag
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'.' | b'-'))
    })
}

fn hash(token: &str) -> bool {
    token.strip_prefix('#').is_some_and(|hex| {
        hex.len() == 64
            && hex
                .bytes()
                .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
    })
}

fn user(name: &str) -> bool {
    !name.is_empty()
        && name.split('-').all(|part| {
            !part.is_empty()
                && part
                    .bytes()
                    .all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit())
        })
}

fn printable(token: &str) -> bool {
    !token.is_empty() && token.bytes().all(|byte| (b'!'..=b'~').contains(&byte))
}

/// `on ~<pattern>... resetchannels -@all +<command>... -@admin -@dangerous -function -script`.
fn managed_rules(rules: &[&str]) -> bool {
    let Some(rest) = rules.strip_prefix(&["on"]) else {
        return false;
    };
    let patterns = rest
        .iter()
        .take_while(|token| token.starts_with('~'))
        .count();
    if patterns == 0 || !rest[..patterns].iter().all(|token| printable(&token[1..])) {
        return false;
    }
    let Some(rest) = rest[patterns..].strip_prefix(&["resetchannels", "-@all"]) else {
        return false;
    };
    let Some(allowed) = rest.strip_suffix(&["-@admin", "-@dangerous", "-function", "-script"])
    else {
        return false;
    };
    allowed
        .iter()
        .all(|token| token.strip_prefix('+').is_some_and(printable))
}

/// The ACL file's lines: hashes and flags may come in either order; Orbit sets no payload flags.
pub fn valid_acl(acl: &str) -> bool {
    let Some(body) = acl.strip_suffix('\n') else {
        return false;
    };
    body.split('\n').enumerate().all(|(index, line)| {
        let tokens: Vec<&str> = line.split(' ').collect();
        if tokens.len() < 3 || tokens[0] != "user" || !user(tokens[1]) {
            return false;
        }
        let hashes = tokens[2..].iter().filter(|token| hash(token)).count();
        let rules: Vec<&str> = tokens[2..]
            .iter()
            .copied()
            .filter(|token| !hash(token) && *token != "sanitize-payload")
            .collect();
        if index == 0 {
            tokens[1] == "default"
                && ((hashes == 1 && rules == ["on", "~*", "&*", "+@all"])
                    || (hashes == 0 && rules == ["on", "nopass", "~*", "&*", "+@all"]))
        } else {
            tokens[1] != "default" && hashes == 1 && managed_rules(&rules)
        }
    })
}

pub fn write(input: &AclInput) -> Result<Written, ValkeyAclError> {
    if !volume(&input.volume) {
        return Err(invalid("the volume is a Docker volume name"));
    }
    if !image(&input.image) {
        return Err(invalid("the image is valkey/valkey:<tag>"));
    }
    if !valid_acl(&input.acl) {
        return Err(invalid(
            "the ACL file is the default line and managed user lines",
        ));
    }
    let mount = format!("type=volume,source={},target=/data", input.volume);
    let mut child = Process::new("docker")
        .args([
            "run",
            "--rm",
            "-i",
            "--network",
            "none",
            "--mount",
            &mount,
            "--entrypoint",
            "sh",
        ])
        .args([input.image.as_str(), "-c", WRITE])
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|_| ValkeyAclError::Failed { exit_code: 127 })?;
    if let Some(mut stdin) = child.stdin.take() {
        let _ = stdin.write_all(input.acl.as_bytes());
    }
    let status = child
        .wait()
        .map_err(|_| ValkeyAclError::Failed { exit_code: 1 })?;
    if status.success() {
        Ok(Written {})
    } else {
        Err(ValkeyAclError::Failed {
            exit_code: status.code().unwrap_or(1),
        })
    }
}

pub struct WriteValkeyAcl;

impl Command for WriteValkeyAcl {
    const NAME: &'static str = "database.write-valkey-acl";
    const SUMMARY: &'static str = "Write the ACL file into a Valkey Database server's volume.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = AclInput;
    type Output = Written;
    type Error = ValkeyAclError;

    /// Docker answers only root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    /// Each volume has one candidate file.
    fn target(input: &AclInput) -> Option<String> {
        Some(format!("valkey-acl:{}", input.volume))
    }

    fn run(input: AclInput, _: &Context) -> Result<Written, ValkeyAclError> {
        write(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn hash() -> String {
        format!("#{}", "a".repeat(64))
    }

    #[test]
    fn accepts_the_default_line_and_managed_users_in_either_order() {
        let h = hash();
        assert!(valid_acl(&format!("user default on {h} ~* &* +@all\n")));
        assert!(valid_acl("user default on nopass ~* &* +@all\n"));
        assert!(valid_acl(&format!(
            "user default {h} on ~* &* +@all sanitize-payload\nuser app-1 on {h} ~app:* resetchannels -@all +get +set -@admin -@dangerous -function -script\n"
        )));
    }

    #[test]
    fn refuses_anything_outside_orbits_frame() {
        let h = hash();
        for acl in [
            format!("user default on {h} ~* &* +@all"),
            format!("user app on {h} ~* &* +@all\n"),
            format!("user default on {h} ~* &* +@all\nuser default on {h} ~a resetchannels -@all -@admin -@dangerous -function -script\n"),
            format!("user default on {h} ~* &* +@all\nuser app on {h} ~a resetchannels -@all +get -@admin -@dangerous -function\n"),
            format!("user default on {h} ~* &* +@all\nuser app on ~a resetchannels -@all -@admin -@dangerous -function -script\n"),
            format!("user default on {h} ~* &* +@all\nuser App on {h} ~a resetchannels -@all -@admin -@dangerous -function -script\n"),
        ] {
            assert!(!valid_acl(&acl), "{acl}");
        }
    }

    #[test]
    fn refuses_a_bad_volume_or_image_before_docker() {
        let acl = "user default on nopass ~* &* +@all\n".to_string();
        let bad_volume = AclInput {
            volume: "-x".into(),
            image: "valkey/valkey:8.1".into(),
            acl: acl.clone(),
        };
        let bad_image = AclInput {
            volume: "orbit-data".into(),
            image: "redis:7".into(),
            acl,
        };
        assert!(matches!(
            write(&bad_volume),
            Err(ValkeyAclError::Invalid { .. })
        ));
        assert!(matches!(
            write(&bad_image),
            Err(ValkeyAclError::Invalid { .. })
        ));
    }
}
