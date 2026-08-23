use serde_json::Value;

pub fn resolve_tauri_updater_pubkey(
    base_config: &str,
    overlay_config: Option<&str>,
) -> Option<String> {
    let mut config: Value = serde_json::from_str(base_config).ok()?;

    if let Some(overlay) = overlay_config {
        let merge_config: Value = serde_json::from_str(overlay).ok()?;
        json_patch::merge(&mut config, &merge_config);
    }

    config
        .get("plugins")?
        .get("updater")?
        .get("pubkey")?
        .as_str()
        .map(str::trim)
        .filter(|key| !key.is_empty())
        .map(ToString::to_string)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn overlay_pubkey_from_tauri_config_wins_over_conf_file() {
        let base = r#"{"plugins":{"updater":{"pubkey":"file-key"}}}"#;
        let overlay = r#"{"version":"0.1.196","plugins":{"updater":{"pubkey":"overlay-key"}}}"#;

        assert_eq!(
            resolve_tauri_updater_pubkey(base, Some(overlay)).as_deref(),
            Some("overlay-key")
        );
        assert_eq!(
            resolve_tauri_updater_pubkey(base, None).as_deref(),
            Some("file-key")
        );
    }
}
