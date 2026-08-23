use crate::paths::is_protected_system_launcher;
use crate::pending_update::{file_sha256, PendingDesktopUpdate, PendingUpdateError};
use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum InstallError {
    UnsafePath,
    HashMismatch,
    Io(String),
}

impl From<PendingUpdateError> for InstallError {
    fn from(error: PendingUpdateError) -> Self {
        match error {
            PendingUpdateError::UnsafePath => Self::UnsafePath,
            _ => Self::Io(error.to_string()),
        }
    }
}

pub fn atomic_replace_file(
    source: &Path,
    destination: &Path,
    mode: u32,
) -> Result<(), InstallError> {
    if is_protected_system_launcher(&destination.to_string_lossy()) {
        return Err(InstallError::UnsafePath);
    }

    let parent = destination.parent().ok_or(InstallError::UnsafePath)?;
    fs::create_dir_all(parent).map_err(|error| InstallError::Io(error.to_string()))?;

    let temporary = parent.join(format!(
        ".{}.tmp.{}",
        destination
            .file_name()
            .and_then(|name| name.to_str())
            .unwrap_or("orbit-artifact"),
        std::process::id()
    ));

    fs::copy(source, &temporary).map_err(|error| InstallError::Io(error.to_string()))?;
    fs::set_permissions(&temporary, fs::Permissions::from_mode(mode))
        .map_err(|error| InstallError::Io(error.to_string()))?;
    fs::rename(&temporary, destination).map_err(|error| {
        let _ = fs::remove_file(&temporary);
        InstallError::Io(error.to_string())
    })?;

    Ok(())
}

pub fn install_owner_binaries(
    update: &PendingDesktopUpdate,
    staged_agent: &Path,
    staged_cli: &Path,
    agent_destination: &Path,
    cli_destination: &Path,
) -> Result<(), InstallError> {
    if !crate::pending_update::staged_bytes_match(staged_agent, &update.agent.sha256)? {
        return Err(InstallError::HashMismatch);
    }

    if !crate::pending_update::staged_bytes_match(staged_cli, &update.cli.sha256)? {
        return Err(InstallError::HashMismatch);
    }

    atomic_replace_file(staged_agent, agent_destination, 0o755)?;
    atomic_replace_file(staged_cli, cli_destination, 0o755)?;

    Ok(())
}

pub fn installed_hashes_match(
    path: &Path,
    expected_sha256: &str,
) -> Result<bool, PendingUpdateError> {
    if !path.exists() {
        return Ok(false);
    }

    Ok(file_sha256(path)? == expected_sha256)
}

pub fn reconcile_installed_identity(
    path: &Path,
    expected_sha256: &str,
    staged: &Path,
) -> Result<bool, InstallError> {
    if installed_hashes_match(path, expected_sha256)? {
        return Ok(true);
    }

    if crate::pending_update::staged_bytes_match(staged, expected_sha256)? {
        atomic_replace_file(staged, path, 0o755)?;
        return Ok(true);
    }

    Ok(false)
}

pub fn desktop_archive_ready(update: &PendingDesktopUpdate) -> Result<(), InstallError> {
    let staged = PathBuf::from(&update.desktop.staged_path);

    if !crate::pending_update::staged_bytes_match(&staged, &update.desktop.sha256)? {
        return Err(InstallError::HashMismatch);
    }

    if update.desktop.signature.trim().is_empty() {
        return Err(InstallError::HashMismatch);
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::pending_update::{sha256_hex, ArtifactIdentity, DesktopIdentity};
    use std::sync::atomic::{AtomicU64, Ordering};
    use std::time::{SystemTime, UNIX_EPOCH};

    static NEXT_ID: AtomicU64 = AtomicU64::new(0);

    fn temp_dir() -> PathBuf {
        let suffix = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("clock")
            .as_nanos();
        let sequence = NEXT_ID.fetch_add(1, Ordering::Relaxed);
        let path = std::env::temp_dir().join(format!(
            "orbit-install-{}-{suffix}-{sequence}",
            std::process::id()
        ));
        fs::create_dir_all(&path).expect("dir");
        path
    }

    fn update_for(agent: &str, cli: &str, desktop: &str) -> PendingDesktopUpdate {
        PendingDesktopUpdate {
            schema_version: 1,
            operation_id: "op".to_string(),
            version: "1.2.3".to_string(),
            build_id: "build".to_string(),
            install_mode: "restart-ready".to_string(),
            desktop: DesktopIdentity {
                sha256: sha256_hex(desktop.as_bytes()),
                signature: "signature".to_string(),
                staged_path: "/Users/nckrtl/.local/share/orbit/updates/desktop.tar.gz".to_string(),
                version: "1.2.3".to_string(),
                platform: "darwin".to_string(),
                architecture: "arm64".to_string(),
            },
            agent: ArtifactIdentity {
                sha256: sha256_hex(agent.as_bytes()),
                bin_path: Some("/Users/nckrtl/.local/bin/orbit-agent".to_string()),
            },
            cli: ArtifactIdentity {
                sha256: sha256_hex(cli.as_bytes()),
                bin_path: Some("/Users/nckrtl/.local/bin/orbit".to_string()),
            },
        }
    }

    #[test]
    fn replaces_owner_binaries_atomically_and_rejects_hash_mismatch() {
        let root = temp_dir();
        let staged_agent = root.join("agent-new");
        let staged_cli = root.join("cli-new");
        let agent_dest = root.join("bin/orbit-agent");
        let cli_dest = root.join("bin/orbit");
        fs::write(&staged_agent, b"agent-v2").expect("agent");
        fs::write(&staged_cli, b"cli-v2").expect("cli");

        let update = update_for("agent-v2", "cli-v2", "desktop");
        install_owner_binaries(&update, &staged_agent, &staged_cli, &agent_dest, &cli_dest)
            .expect("install");
        assert_eq!(fs::read_to_string(&agent_dest).expect("read"), "agent-v2");
        assert_eq!(fs::read_to_string(&cli_dest).expect("read"), "cli-v2");

        fs::write(&staged_agent, b"tampered").expect("tamper");
        let error =
            install_owner_binaries(&update, &staged_agent, &staged_cli, &agent_dest, &cli_dest)
                .expect_err("mismatch");
        assert_eq!(error, InstallError::HashMismatch);
        assert_eq!(fs::read_to_string(&agent_dest).expect("kept"), "agent-v2");

        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn refuses_protected_system_launchers() {
        let error = atomic_replace_file(
            Path::new("/etc/hosts"),
            Path::new("/usr/local/bin/orbit"),
            0o755,
        )
        .expect_err("protected");
        assert_eq!(error, InstallError::UnsafePath);
    }

    #[test]
    fn interrupted_install_can_resume_from_verified_stage() {
        let root = temp_dir();
        let staged = root.join("agent-new");
        let dest = root.join("bin/orbit-agent");
        fs::write(&staged, b"agent-v2").expect("stage");
        fs::create_dir_all(dest.parent().expect("parent")).expect("dir");
        fs::write(&dest, b"agent-v1").expect("old");
        let digest = sha256_hex(b"agent-v2");
        assert!(reconcile_installed_identity(&dest, &digest, &staged).expect("resume"));
        assert_eq!(fs::read_to_string(&dest).expect("read"), "agent-v2");
        let _ = fs::remove_dir_all(root);
    }
}
