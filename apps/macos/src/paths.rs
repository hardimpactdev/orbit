use std::path::{Path, PathBuf};

pub const PENDING_UPDATE_FILE_NAME: &str = "pending-desktop-update.json";

pub fn owner_config_dir(home: &Path) -> PathBuf {
    home.join(".config").join("orbit")
}

pub fn pending_update_path(home: &Path) -> PathBuf {
    owner_config_dir(home).join(PENDING_UPDATE_FILE_NAME)
}

pub fn owner_bin_dir(home: &Path) -> PathBuf {
    home.join(".local").join("bin")
}

pub fn owner_agent_bin(home: &Path) -> PathBuf {
    owner_bin_dir(home).join("orbit-agent")
}

pub fn owner_cli_bin(home: &Path) -> PathBuf {
    owner_bin_dir(home).join("orbit")
}

pub fn owner_updates_dir(home: &Path) -> PathBuf {
    home.join(".local")
        .join("share")
        .join("orbit")
        .join("updates")
}

pub fn legacy_launch_agent_plist(home: &Path) -> PathBuf {
    home.join("Library")
        .join("LaunchAgents")
        .join("dev.orbit.agent.plist")
}

pub fn is_safe_owner_path(path: &str, required_fragment: &str) -> bool {
    !path.is_empty()
        && path.starts_with('/')
        && !path.contains('\0')
        && !path.contains("..")
        && path.contains(required_fragment)
}

pub fn is_safe_handoff_path(path: &str) -> bool {
    Path::new(path).file_name().and_then(|name| name.to_str()) == Some(PENDING_UPDATE_FILE_NAME)
        && is_safe_owner_path(path, "/.config/orbit/")
}

pub fn is_safe_staged_path(path: &str) -> bool {
    is_safe_owner_path(path, "/.local/share/orbit/updates/")
}

pub fn is_protected_system_launcher(path: &str) -> bool {
    path.starts_with("/usr/")
        || path.starts_with("/bin/")
        || path.starts_with("/sbin/")
        || path.starts_with("/opt/")
        || path.starts_with("/Library/LaunchDaemons/")
        || path.starts_with("/Library/LaunchAgents/")
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    fn home() -> PathBuf {
        PathBuf::from("/Users/nckrtl")
    }

    #[test]
    fn owner_paths_stay_under_the_user_home() {
        assert_eq!(
            pending_update_path(&home()),
            PathBuf::from("/Users/nckrtl/.config/orbit/pending-desktop-update.json")
        );
        assert_eq!(
            owner_agent_bin(&home()),
            PathBuf::from("/Users/nckrtl/.local/bin/orbit-agent")
        );
        assert_eq!(
            owner_cli_bin(&home()),
            PathBuf::from("/Users/nckrtl/.local/bin/orbit")
        );
        assert_eq!(
            legacy_launch_agent_plist(&home()),
            PathBuf::from("/Users/nckrtl/Library/LaunchAgents/dev.orbit.agent.plist")
        );
    }

    #[test]
    fn rejects_unsafe_handoff_and_staged_paths() {
        assert!(!is_safe_handoff_path("/tmp/pending-desktop-update.json"));
        assert!(!is_safe_handoff_path(
            "/Users/nckrtl/.config/orbit/../evil/pending-desktop-update.json"
        ));
        assert!(!is_safe_staged_path("/tmp/desktop.tar.gz"));
        assert!(is_safe_handoff_path(
            "/Users/nckrtl/.config/orbit/pending-desktop-update.json"
        ));
        assert!(is_safe_staged_path(
            "/Users/nckrtl/.local/share/orbit/updates/desktop-cccc.tar.gz"
        ));
    }

    #[test]
    fn protected_system_launchers_are_not_mutated() {
        assert!(is_protected_system_launcher(
            "/Library/LaunchDaemons/dev.orbit.agent.plist"
        ));
        assert!(is_protected_system_launcher("/usr/local/bin/orbit"));
        assert!(!is_protected_system_launcher(
            "/Users/nckrtl/.local/bin/orbit-agent"
        ));
    }
}
