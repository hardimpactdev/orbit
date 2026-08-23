use crate::paths::{is_safe_handoff_path, is_safe_staged_path};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::fs;
use std::path::Path;

pub const SCHEMA_VERSION: u32 = 1;
pub const INSTALL_MODE_AUTOMATIC: &str = "automatic";
pub const INSTALL_MODE_RESTART_READY: &str = "restart-ready";

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PendingUpdateError {
    Missing,
    Incomplete,
    Malformed,
    UnsafePath,
    Stale,
    InvalidIdentity(&'static str),
}

impl std::fmt::Display for PendingUpdateError {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Missing => write!(formatter, "Pending desktop update handoff is missing."),
            Self::Incomplete => write!(formatter, "Pending desktop update handoff is incomplete."),
            Self::Malformed => write!(formatter, "Pending desktop update handoff is malformed."),
            Self::UnsafePath => write!(formatter, "Pending desktop update path is unsafe."),
            Self::Stale => write!(formatter, "Pending desktop update identity is stale."),
            Self::InvalidIdentity(field) => {
                write!(formatter, "Pending desktop update {field} is invalid.")
            }
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ArtifactIdentity {
    pub sha256: String,
    #[serde(default)]
    pub bin_path: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct DesktopIdentity {
    pub sha256: String,
    pub signature: String,
    pub staged_path: String,
    pub version: String,
    pub platform: String,
    pub architecture: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct PendingDesktopUpdate {
    pub schema_version: u32,
    pub operation_id: String,
    pub version: String,
    pub build_id: String,
    pub install_mode: String,
    pub desktop: DesktopIdentity,
    pub agent: ArtifactIdentity,
    pub cli: ArtifactIdentity,
}

#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct ExpectedUpdateIdentity {
    pub operation_id: Option<String>,
    pub version: Option<String>,
    pub build_id: Option<String>,
}

pub fn parse_pending_update(json: &str) -> Result<PendingDesktopUpdate, PendingUpdateError> {
    let update: PendingDesktopUpdate =
        serde_json::from_str(json).map_err(|_| PendingUpdateError::Malformed)?;
    validate_pending_update(&update)?;
    Ok(update)
}

pub fn read_pending_update(
    path: &str,
    expected: &ExpectedUpdateIdentity,
) -> Result<PendingDesktopUpdate, PendingUpdateError> {
    if !is_safe_handoff_path(path) {
        return Err(PendingUpdateError::UnsafePath);
    }

    let contents = fs::read_to_string(path).map_err(|_| PendingUpdateError::Missing)?;

    if contents.trim().is_empty() {
        return Err(PendingUpdateError::Incomplete);
    }

    let update = parse_pending_update(&contents)?;
    assert_expected_identity(&update, expected)?;
    Ok(update)
}

pub fn validate_pending_update(update: &PendingDesktopUpdate) -> Result<(), PendingUpdateError> {
    if update.schema_version != SCHEMA_VERSION {
        return Err(PendingUpdateError::InvalidIdentity("schema_version"));
    }

    require_present(&update.operation_id, "operation_id")?;
    require_present(&update.version, "version")?;
    require_present(&update.build_id, "build_id")?;

    if update.install_mode != INSTALL_MODE_AUTOMATIC
        && update.install_mode != INSTALL_MODE_RESTART_READY
    {
        return Err(PendingUpdateError::InvalidIdentity("install_mode"));
    }

    validate_sha256(&update.desktop.sha256, "desktop")?;
    validate_sha256(&update.agent.sha256, "agent")?;
    validate_sha256(&update.cli.sha256, "cli")?;
    require_present(&update.desktop.signature, "signature")?;
    require_present(&update.desktop.version, "desktop version")?;

    if update.desktop.platform != "darwin" {
        return Err(PendingUpdateError::InvalidIdentity("platform"));
    }

    if update.desktop.architecture != "arm64" {
        return Err(PendingUpdateError::InvalidIdentity("architecture"));
    }

    if update.desktop.version != update.version {
        return Err(PendingUpdateError::Stale);
    }

    if !is_safe_staged_path(&update.desktop.staged_path) {
        return Err(PendingUpdateError::UnsafePath);
    }

    if let Some(path) = &update.agent.bin_path {
        if crate::paths::is_protected_system_launcher(path) || !path.contains("/.local/bin/") {
            return Err(PendingUpdateError::UnsafePath);
        }
    }

    if let Some(path) = &update.cli.bin_path {
        if crate::paths::is_protected_system_launcher(path) || !path.contains("/.local/bin/") {
            return Err(PendingUpdateError::UnsafePath);
        }
    }

    Ok(())
}

pub fn file_sha256(path: &Path) -> Result<String, PendingUpdateError> {
    let bytes = fs::read(path).map_err(|_| PendingUpdateError::Incomplete)?;
    Ok(sha256_hex(&bytes))
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    let digest = Sha256::digest(bytes);
    digest.iter().map(|byte| format!("{byte:02x}")).collect()
}

pub fn staged_bytes_match(path: &Path, expected_sha256: &str) -> Result<bool, PendingUpdateError> {
    Ok(file_sha256(path)? == expected_sha256)
}

fn assert_expected_identity(
    update: &PendingDesktopUpdate,
    expected: &ExpectedUpdateIdentity,
) -> Result<(), PendingUpdateError> {
    if expected
        .operation_id
        .as_ref()
        .is_some_and(|value| value != &update.operation_id)
        || expected
            .version
            .as_ref()
            .is_some_and(|value| value != &update.version)
        || expected
            .build_id
            .as_ref()
            .is_some_and(|value| value != &update.build_id)
    {
        return Err(PendingUpdateError::Stale);
    }

    Ok(())
}

fn validate_sha256(value: &str, field: &'static str) -> Result<(), PendingUpdateError> {
    if value.len() == 64 && value.chars().all(|character| character.is_ascii_hexdigit()) {
        Ok(())
    } else {
        Err(PendingUpdateError::InvalidIdentity(field))
    }
}

fn require_present(value: &str, field: &'static str) -> Result<(), PendingUpdateError> {
    if value.trim().is_empty() {
        Err(PendingUpdateError::InvalidIdentity(field))
    } else {
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::time::{SystemTime, UNIX_EPOCH};

    fn valid_json() -> String {
        format!(
            r#"{{
                "schema_version": 1,
                "operation_id": "op-1",
                "version": "1.2.3",
                "build_id": "2026-08-23T120000Z-abc123",
                "install_mode": "restart-ready",
                "desktop": {{
                    "sha256": "{}",
                    "signature": "dW50cnVzdGVkIGNvbW1lbnQ6IHNpZ25hdHVyZQ==",
                    "staged_path": "/Users/nckrtl/.local/share/orbit/updates/desktop-cccc.tar.gz",
                    "version": "1.2.3",
                    "platform": "darwin",
                    "architecture": "arm64"
                }},
                "agent": {{
                    "sha256": "{}",
                    "bin_path": "/Users/nckrtl/.local/bin/orbit-agent"
                }},
                "cli": {{
                    "sha256": "{}",
                    "bin_path": "/Users/nckrtl/.local/bin/orbit"
                }}
            }}"#,
            "c".repeat(64),
            "e".repeat(64),
            "b".repeat(64)
        )
    }

    #[test]
    fn accepts_a_complete_owner_handoff() {
        let update = parse_pending_update(&valid_json()).expect("valid");
        assert_eq!(update.install_mode, INSTALL_MODE_RESTART_READY);
        assert_eq!(update.desktop.platform, "darwin");
        assert_eq!(update.desktop.architecture, "arm64");
    }

    #[test]
    fn rejects_partial_and_stale_handoffs() {
        let mut json = valid_json();
        json = json.replace(&"c".repeat(64), "deadbeef");
        assert!(parse_pending_update(&json).is_err());

        let update = parse_pending_update(&valid_json()).expect("valid");
        let error = assert_expected_identity(
            &update,
            &ExpectedUpdateIdentity {
                operation_id: Some("other".to_string()),
                version: Some("1.2.3".to_string()),
                build_id: Some("2026-08-23T120000Z-abc123".to_string()),
            },
        )
        .expect_err("stale");
        assert_eq!(error, PendingUpdateError::Stale);
    }

    #[test]
    fn rejects_unsafe_handoff_paths_without_replacing_files() {
        let error = read_pending_update(
            "/tmp/pending-desktop-update.json",
            &ExpectedUpdateIdentity::default(),
        )
        .expect_err("unsafe");
        assert_eq!(error, PendingUpdateError::UnsafePath);
    }

    #[test]
    fn verifies_staged_bytes_against_sha256() {
        let suffix = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("clock")
            .as_nanos();
        let path = std::env::temp_dir().join(format!("orbit-staged-{suffix}.bin"));
        fs::write(&path, b"desktop-archive").expect("write");
        let digest = sha256_hex(b"desktop-archive");
        assert!(staged_bytes_match(&path, &digest).expect("hash"));
        assert!(!staged_bytes_match(&path, &"0".repeat(64)).expect("hash"));
        let _ = fs::remove_file(path);
    }
}
