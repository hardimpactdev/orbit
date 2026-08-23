pub fn orbit_version() -> &'static str {
    option_env!("ORBIT_VERSION").unwrap_or(env!("CARGO_PKG_VERSION"))
}

pub fn versions_match(root_version: &str, cargo_version: &str, tauri_version: &str) -> bool {
    !root_version.is_empty() && root_version == cargo_version && root_version == tauri_version
}

pub fn reject_hardcoded_desktop_drift(source: &str) -> bool {
    !source.contains("\"version\": \"0.1.0\"") || source.contains("ORBIT_VERSION")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn release_identity_uses_a_single_version() {
        assert!(versions_match("1.2.3", "1.2.3", "1.2.3"));
        assert!(!versions_match("1.2.3", "0.1.0", "0.1.0"));
        assert!(!orbit_version().is_empty());
    }
}
