//! The `env_keys` fact of one Instance `.env`: its kind, the key names when the agent may read the file, and whether
//! Git tracks `.env.testing` beside it. Orbit writes a managed `.env` as 0640 with group `orbit-env`, which the agent's
//! unit holds; a file the agent can't read, such as a hand-made 0600 `.env`, reports `keys` as null. The agent never
//! sends a value.

use serde_json::{json, Value};
use std::collections::BTreeSet;
use std::io::Read;
use std::path::Path;

/// A `.env` larger than this is not read; it reports `keys` as null.
const MAX_BYTES: u64 = 1 << 20;
/// At most this many key names are reported.
const MAX_KEYS: usize = 2_000;

/// The `env_keys` data of `path`.
pub fn observe(path: &Path) -> Value {
    let kind = match std::fs::symlink_metadata(path) {
        Err(_) => "absent",
        Ok(metadata) if metadata.file_type().is_symlink() => "symlink",
        Ok(metadata) if metadata.is_file() => "regular",
        Ok(_) => "other",
    };
    let keys = (kind == "regular")
        .then(|| read(path))
        .flatten()
        .map(|text| key_names(&text));
    json!({
        "kind": kind,
        "keys": keys,
        "testing_tracked": path.parent().is_some_and(testing_tracked),
    })
}

fn read(path: &Path) -> Option<String> {
    let mut text = String::new();
    let mut file = std::fs::File::open(path).ok()?.take(MAX_BYTES + 1);
    file.read_to_string(&mut text).ok()?;
    (text.len() as u64 <= MAX_BYTES).then_some(text)
}

/// The names of `NAME=value` and `export NAME=value` lines, sorted and unique. Values are never kept.
pub fn key_names(text: &str) -> Vec<String> {
    let names: BTreeSet<String> = text
        .lines()
        .filter_map(|line| {
            let line = line.trim_start_matches([' ', '\t']);
            let line = line
                .strip_prefix("export")
                .filter(|rest| rest.starts_with([' ', '\t']))
                .map_or(line, |rest| rest.trim_start_matches([' ', '\t']));
            let end = line
                .find(|c: char| !(c.is_ascii_alphanumeric() || c == '_' || c == '.'))
                .unwrap_or(line.len());
            let (name, rest) = line.split_at(end);
            let valid = name.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_')
                && name.len() <= 255;
            (valid && rest.trim_start_matches([' ', '\t']).starts_with('='))
                .then(|| name.to_owned())
        })
        .collect();
    names.into_iter().take(MAX_KEYS).collect()
}

/// Whether the Git work tree that holds `directory` tracks `directory/.env.testing`.
fn testing_tracked(directory: &Path) -> bool {
    let Ok(repository) = git2::Repository::discover(directory) else {
        return false;
    };
    let (Some(workdir), Ok(index)) = (repository.workdir(), repository.index()) else {
        return false;
    };
    let workdir = std::fs::canonicalize(workdir).unwrap_or_else(|_| workdir.to_path_buf());
    let directory = std::fs::canonicalize(directory).unwrap_or_else(|_| directory.to_path_buf());
    directory
        .join(".env.testing")
        .strip_prefix(&workdir)
        .ok()
        .and_then(Path::to_str)
        .is_some_and(|relative| index.get_path(Path::new(relative), 0).is_some())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::PermissionsExt;

    fn dir(name: &str) -> std::path::PathBuf {
        let path =
            std::env::temp_dir().join(format!("orbit-agent-env-{name}-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&path);
        std::fs::create_dir_all(&path).unwrap();
        path
    }

    #[test]
    fn key_names_keep_names_only_and_skip_comments_and_noise() {
        let text = "APP_KEY=base64:secret\n# COMMENTED=1\n  export DB_HOST = 10.0.0.1\nexportX=1\n\n1BAD=2\nNO_EQUALS\nAPP_KEY=again\nmail.from=x\n";
        assert_eq!(
            key_names(text),
            ["APP_KEY", "DB_HOST", "exportX", "mail.from"]
        );
        assert!(!format!("{:?}", key_names(text)).contains("secret"));
    }

    #[test]
    fn a_readable_file_reports_its_key_names_and_a_missing_one_none() {
        let path = dir("readable");
        assert_eq!(
            observe(&path.join(".env")),
            json!({"kind": "absent", "keys": null, "testing_tracked": false})
        );
        std::fs::write(path.join(".env"), "APP_ENV=local\nAPP_KEY=secret\n").unwrap();
        let data = observe(&path.join(".env"));
        assert_eq!(
            data,
            json!({"kind": "regular", "keys": ["APP_ENV", "APP_KEY"], "testing_tracked": false})
        );
        assert!(!data.to_string().contains("secret"));
    }

    #[test]
    fn an_unreadable_file_reports_no_keys() {
        // Root reads any file; this check needs a user without that power, as the agent's unit is.
        if unsafe { libc::geteuid() } == 0 {
            return;
        }
        let path = dir("private");
        std::fs::write(path.join(".env"), "APP_KEY=secret\n").unwrap();
        std::fs::set_permissions(path.join(".env"), std::fs::Permissions::from_mode(0o000))
            .unwrap();
        assert_eq!(observe(&path.join(".env"))["keys"], Value::Null);
    }

    #[test]
    fn a_link_reports_its_kind_and_no_keys() {
        let path = dir("link");
        std::fs::write(path.join("target"), "APP_KEY=secret\n").unwrap();
        std::os::unix::fs::symlink(path.join("target"), path.join(".env")).unwrap();
        assert_eq!(
            observe(&path.join(".env")),
            json!({"kind": "symlink", "keys": null, "testing_tracked": false})
        );
    }

    #[test]
    fn a_tracked_env_testing_is_reported() {
        let path = dir("tracked");
        let repository = git2::Repository::init(&path).unwrap();
        std::fs::create_dir_all(path.join("web")).unwrap();
        std::fs::write(path.join("web/.env.testing"), "APP_ENV=testing\n").unwrap();
        let mut index = repository.index().unwrap();
        index.add_path(Path::new("web/.env.testing")).unwrap();
        index.write().unwrap();
        assert_eq!(observe(&path.join("web/.env"))["testing_tracked"], true);
        assert_eq!(observe(&path.join(".env"))["testing_tracked"], false);
    }
}
