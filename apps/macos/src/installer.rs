use crate::paths::is_protected_system_launcher;
use crate::pending_update::{file_sha256, sha256_hex, PendingDesktopUpdate, PendingUpdateError};
use base64::Engine;
use minisign_verify::{PublicKey, Signature};
use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum InstallError {
    UnsafePath,
    HashMismatch,
    InvalidSignature,
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

pub fn trusted_updater_pubkey() -> Result<String, InstallError> {
    option_env!("ORBIT_UPDATER_PUBKEY")
        .map(str::trim)
        .filter(|key| !key.is_empty())
        .map(ToString::to_string)
        .ok_or(InstallError::InvalidSignature)
}

pub fn verify_updater_signature(
    data: &[u8],
    signature: &str,
    pubkey: &str,
) -> Result<(), InstallError> {
    if signature.trim().is_empty() || pubkey.trim().is_empty() {
        return Err(InstallError::InvalidSignature);
    }

    let public_key_text = decode_minisign_text(pubkey)?;
    let signature_text = decode_minisign_text(signature)?;
    let public_key =
        PublicKey::decode(&public_key_text).map_err(|_| InstallError::InvalidSignature)?;
    let decoded_signature =
        Signature::decode(&signature_text).map_err(|_| InstallError::InvalidSignature)?;

    public_key
        .verify(data, &decoded_signature, true)
        .map_err(|_| InstallError::InvalidSignature)?;

    Ok(())
}

pub fn desktop_archive_ready(update: &PendingDesktopUpdate) -> Result<(), InstallError> {
    desktop_archive_ready_with_pubkey(update, &trusted_updater_pubkey()?)
}

pub fn desktop_archive_ready_with_pubkey(
    update: &PendingDesktopUpdate,
    pubkey: &str,
) -> Result<(), InstallError> {
    let staged = PathBuf::from(&update.desktop.staged_path);
    let bytes = fs::read(&staged).map_err(|_| PendingUpdateError::Incomplete)?;

    if sha256_hex(&bytes) != update.desktop.sha256 {
        return Err(InstallError::HashMismatch);
    }

    verify_updater_signature(&bytes, &update.desktop.signature, pubkey)
}

fn decode_minisign_text(encoded: &str) -> Result<String, InstallError> {
    let decoded = base64::engine::general_purpose::STANDARD
        .decode(encoded.trim())
        .map_err(|_| InstallError::InvalidSignature)?;

    String::from_utf8(decoded).map_err(|_| InstallError::InvalidSignature)
}

pub fn install_desktop_bundle(
    update: &PendingDesktopUpdate,
    destination_app: &Path,
) -> Result<(), InstallError> {
    install_desktop_bundle_with_pubkey(update, destination_app, &trusted_updater_pubkey()?)
}

pub fn install_desktop_bundle_with_pubkey(
    update: &PendingDesktopUpdate,
    destination_app: &Path,
    pubkey: &str,
) -> Result<(), InstallError> {
    desktop_archive_ready_with_pubkey(update, pubkey)?;

    if destination_app.as_os_str().is_empty() {
        return Err(InstallError::UnsafePath);
    }

    let parent = destination_app.parent().ok_or(InstallError::UnsafePath)?;
    fs::create_dir_all(parent).map_err(|error| InstallError::Io(error.to_string()))?;

    let staging = parent.join(format!(".orbit-desktop-{}.staging", std::process::id()));
    let _ = fs::remove_dir_all(&staging);
    fs::create_dir_all(&staging).map_err(|error| InstallError::Io(error.to_string()))?;

    let status = std::process::Command::new("tar")
        .args([
            "-xzf",
            &update.desktop.staged_path,
            "-C",
            &staging.to_string_lossy(),
        ])
        .status()
        .map_err(|error| InstallError::Io(error.to_string()))?;

    if !status.success() {
        let _ = fs::remove_dir_all(&staging);
        return Err(InstallError::Io(
            "desktop archive extract failed".to_string(),
        ));
    }

    let extracted = fs::read_dir(&staging)
        .map_err(|error| InstallError::Io(error.to_string()))?
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .find(|path| path.extension().and_then(|ext| ext.to_str()) == Some("app"))
        .ok_or_else(|| {
            InstallError::Io("desktop archive did not contain an app bundle".to_string())
        })?;

    let temporary = parent.join(format!(
        ".{}.tmp.{}",
        destination_app
            .file_name()
            .and_then(|name| name.to_str())
            .unwrap_or("Orbit Desktop.app"),
        std::process::id()
    ));
    let _ = fs::remove_dir_all(&temporary);
    fs::rename(&extracted, &temporary).map_err(|error| {
        let _ = fs::remove_dir_all(&staging);
        InstallError::Io(error.to_string())
    })?;
    let _ = fs::remove_dir_all(&staging);

    if destination_app.exists() {
        let backup = parent.join(format!(
            ".{}.bak.{}",
            destination_app
                .file_name()
                .and_then(|name| name.to_str())
                .unwrap_or("Orbit Desktop.app"),
            std::process::id()
        ));
        fs::rename(destination_app, &backup).map_err(|error| {
            let _ = fs::remove_dir_all(&temporary);
            InstallError::Io(error.to_string())
        })?;
        if let Err(error) = fs::rename(&temporary, destination_app) {
            let _ = fs::rename(&backup, destination_app);
            let _ = fs::remove_dir_all(&temporary);
            return Err(InstallError::Io(error.to_string()));
        }
        let _ = fs::remove_dir_all(&backup);
    } else if let Err(error) = fs::rename(&temporary, destination_app) {
        let _ = fs::remove_dir_all(&temporary);
        return Err(InstallError::Io(error.to_string()));
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

    #[test]
    fn rejects_invalid_updater_signatures_without_replacing_the_app() {
        let root = temp_dir();
        let source_app = root.join("Orbit Desktop.app");
        fs::create_dir_all(source_app.join("Contents/MacOS")).expect("app");
        fs::write(source_app.join("Contents/MacOS/orbit-macos"), b"desktop-v2").expect("bin");
        let archive = root.join("desktop.tar.gz");
        let status = std::process::Command::new("tar")
            .args([
                "-czf",
                &archive.to_string_lossy(),
                "-C",
                &root.to_string_lossy(),
                "Orbit Desktop.app",
            ])
            .status()
            .expect("tar");
        assert!(status.success());
        fs::remove_dir_all(&source_app).expect("remove source");

        let digest = sha256_hex(&fs::read(&archive).expect("read archive"));
        let mut update = update_for("agent-v2", "cli-v2", "desktop");
        update.desktop.sha256 = digest;
        update.desktop.staged_path = archive.to_string_lossy().into_owned();
        update.desktop.signature = "dW50cnVzdGVkIGNvbW1lbnQ6IG5vdC1hLXNpZ25hdHVyZQ==".to_string();

        let destination = root.join("installed/Orbit Desktop.app");
        fs::create_dir_all(destination.join("Contents/MacOS")).expect("existing");
        fs::write(
            destination.join("Contents/MacOS/orbit-macos"),
            b"desktop-v1",
        )
        .expect("existing bin");

        let error = desktop_archive_ready(&update).expect_err("invalid signature");
        assert_eq!(error, InstallError::InvalidSignature);
        let install_error = install_desktop_bundle(&update, &destination).expect_err("rejected");
        assert_eq!(install_error, InstallError::InvalidSignature);
        assert_eq!(
            fs::read_to_string(destination.join("Contents/MacOS/orbit-macos")).expect("kept"),
            "desktop-v1"
        );

        update.desktop.signature.clear();
        assert_eq!(
            desktop_archive_ready(&update).expect_err("empty"),
            InstallError::InvalidSignature
        );

        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn extracts_a_verified_desktop_archive_into_the_destination_app() {
        let root = temp_dir();
        let source_app = root.join("Orbit Desktop.app");
        fs::create_dir_all(source_app.join("Contents/MacOS")).expect("app");
        fs::write(source_app.join("Contents/MacOS/orbit-macos"), b"desktop-v2").expect("bin");
        let archive = root.join("desktop.tar.gz");
        let status = std::process::Command::new("tar")
            .args([
                "-czf",
                &archive.to_string_lossy(),
                "-C",
                &root.to_string_lossy(),
                "Orbit Desktop.app",
            ])
            .status()
            .expect("tar");
        assert!(status.success());
        fs::remove_dir_all(&source_app).expect("remove source");

        let bytes = fs::read(&archive).expect("read archive");
        let digest = sha256_hex(&bytes);
        let (pubkey, signature) = sign_archive(&bytes);
        let mut update = update_for("agent-v2", "cli-v2", "desktop");
        update.desktop.sha256 = digest;
        update.desktop.staged_path = archive.to_string_lossy().into_owned();
        update.desktop.signature = signature;

        let destination = root.join("installed/Orbit Desktop.app");
        install_desktop_bundle_with_pubkey(&update, &destination, &pubkey)
            .expect("install desktop");
        assert_eq!(
            fs::read_to_string(destination.join("Contents/MacOS/orbit-macos")).expect("read"),
            "desktop-v2"
        );

        fs::write(&archive, b"tampered-archive").expect("tamper");
        let error = install_desktop_bundle_with_pubkey(&update, &destination, &pubkey)
            .expect_err("mismatch");
        assert_eq!(error, InstallError::HashMismatch);
        assert_eq!(
            fs::read_to_string(destination.join("Contents/MacOS/orbit-macos")).expect("kept"),
            "desktop-v2"
        );

        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn verifies_signatures_against_the_embedded_trusted_pubkey() {
        let pubkey = trusted_updater_pubkey().expect("embedded pubkey");
        assert_eq!(pubkey, env!("ORBIT_UPDATER_PUBKEY"));
        assert!(include_str!("installer.rs").contains("ORBIT_UPDATER_PUBKEY"));
        assert!(
            include_str!(concat!(env!("CARGO_MANIFEST_DIR"), "/build.rs")).contains("TAURI_CONFIG")
        );
        assert!(pubkey.starts_with("dW50cnVzdGVk"));
        assert_eq!(
            verify_updater_signature(b"desktop-archive", "not-base64", &pubkey),
            Err(InstallError::InvalidSignature)
        );

        let (test_pubkey, signature) = sign_archive(b"desktop-archive");
        verify_updater_signature(b"desktop-archive", &signature, &test_pubkey).expect("valid");
        assert_eq!(
            verify_updater_signature(b"other-bytes", &signature, &test_pubkey),
            Err(InstallError::InvalidSignature)
        );
        assert_eq!(
            verify_updater_signature(b"desktop-archive", &signature, &pubkey),
            Err(InstallError::InvalidSignature)
        );
    }

    fn sign_archive(bytes: &[u8]) -> (String, String) {
        let keypair = minisign::KeyPair::generate_unencrypted_keypair().expect("keypair");
        let signature =
            minisign::sign(Some(&keypair.pk), &keypair.sk, bytes, None, None).expect("sign");
        let pubkey = keypair.pk.to_box().expect("public key box").to_string();

        (
            base64::engine::general_purpose::STANDARD.encode(pubkey),
            base64::engine::general_purpose::STANDARD.encode(signature.into_string()),
        )
    }
}
