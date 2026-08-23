use crate::paths::{is_safe_staged_path, owner_updates_dir};
use crate::pending_update::{sha256_hex, DesktopIdentity, PendingDesktopUpdate};
use std::path::{Path, PathBuf};

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GatewaySelectedManifest {
    pub version: String,
    pub build_id: String,
    pub operation_id: String,
    pub desktop: DesktopIdentity,
    pub agent_sha256: String,
    pub cli_sha256: String,
}

pub fn staging_path_for(home: &Path, kind: &str, sha256: &str) -> PathBuf {
    owner_updates_dir(home).join(format!("{kind}-{}", &sha256[..sha256.len().min(12)]))
}

pub fn reject_partial_stage(path: &Path, expected_sha256: &str, bytes: &[u8]) -> bool {
    if !is_safe_staged_path(&path.to_string_lossy()) {
        return true;
    }

    sha256_hex(bytes) != expected_sha256
}

pub fn restart_ready_handoff(
    manifest: &GatewaySelectedManifest,
    home: &Path,
) -> PendingDesktopUpdate {
    PendingDesktopUpdate {
        schema_version: 1,
        operation_id: manifest.operation_id.clone(),
        version: manifest.version.clone(),
        build_id: manifest.build_id.clone(),
        install_mode: crate::pending_update::INSTALL_MODE_RESTART_READY.to_string(),
        desktop: manifest.desktop.clone(),
        agent: crate::pending_update::ArtifactIdentity {
            sha256: manifest.agent_sha256.clone(),
            bin_path: Some(
                crate::paths::owner_agent_bin(home)
                    .to_string_lossy()
                    .into_owned(),
            ),
        },
        cli: crate::pending_update::ArtifactIdentity {
            sha256: manifest.cli_sha256.clone(),
            bin_path: Some(
                crate::paths::owner_cli_bin(home)
                    .to_string_lossy()
                    .into_owned(),
            ),
        },
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn background_checks_stage_under_owner_updates_and_reject_partial_bytes() {
        let home = PathBuf::from("/Users/nckrtl");
        let sha = "c".repeat(64);
        let path = staging_path_for(&home, "desktop", &sha);

        assert!(path
            .to_string_lossy()
            .contains("/.local/share/orbit/updates/desktop-"));
        assert!(reject_partial_stage(&path, &sha, b"partial"));
        assert!(!reject_partial_stage(
            &path,
            &sha256_hex(b"complete"),
            b"complete"
        ));
    }
}
