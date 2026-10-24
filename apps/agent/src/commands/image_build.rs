//! Image build records have fixed paths and contain data, never executable source.
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum Error {
        Failed { operation: String, reason: String } => "task_vm.image_build_failed", "The image build record could not be written.";
    }
}
fn failed(operation: &str, reason: impl ToString) -> Error {
    Error::Failed {
        operation: operation.into(),
        reason: reason.to_string(),
    }
}
#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Marker {
    identity: String,
    profile: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Log {
    profile: String,
    phase: String,
    text: String,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Ready {
    ready: bool,
}

fn regular(path: &Path) -> io::Result<()> {
    match fs::symlink_metadata(path) {
        Ok(meta)
            if meta.is_file()
                && meta.uid() == rustix::process::geteuid().as_raw()
                && meta.mode() & 0o022 == 0 =>
        {
            Ok(())
        }
        Ok(_) => Err(io::Error::other(
            "The image record has unsafe ownership or file type",
        )),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e),
    }
}
fn valid_marker(input: &Marker) -> bool {
    input.profile == "base"
        && input
            .identity
            .strip_prefix("sha256:")
            .is_some_and(|digest| {
                digest.len() == 64
                    && digest
                        .bytes()
                        .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            })
}
fn marker(root: &Path, input: &Marker) -> Result<Ready, Error> {
    if !valid_marker(input) {
        return Err(failed(
            "marker",
            "The recipe identity or profile is invalid",
        ));
    }
    let path = root.join("etc/orbit-machine-recipe.json");
    let write = || -> io::Result<()> {
        regular(&path)?;
        if path.exists() {
            let old: Marker =
                serde_json::from_slice(&fs::read(&path)?).map_err(io::Error::other)?;
            if !valid_marker(&old) {
                return Err(io::Error::other("The existing recipe marker is invalid"));
            }
        }
        let candidate = path.with_extension(format!("new-{:016x}", rand::random::<u64>()));
        let result = (|| {
            let mut file = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o644)
                .open(&candidate)?;
            serde_json::to_writer(&mut file, input)?;
            file.write_all(b"\n")?;
            file.set_permissions(fs::Permissions::from_mode(0o644))?;
            file.sync_all()?;
            fs::rename(&candidate, &path)
        })();
        let _ = fs::remove_file(candidate);
        result
    };
    write().map_err(|e| failed("marker", e))?;
    Ok(Ready { ready: true })
}
fn log_path(root: &Path, input: &Log) -> Result<PathBuf, Error> {
    if input.profile != "base"
        || ![
            "reset",
            "launch",
            "boot",
            "apt-source",
            "packages",
            "javascript",
            "rust",
            "agent",
            "pi",
            "prepare",
            "clean",
            "publish",
            "smoke",
            "topology-seed",
            "verify",
        ]
        .contains(&input.phase.as_str())
        || input.text.len() > 65536
    {
        return Err(failed(
            "log",
            "The recipe profile, phase or observation size is invalid",
        ));
    }
    Ok(root
        .join("var/log/orbit/recipe-images")
        .join(format!("{}-{}.log", input.profile, input.phase)))
}
fn directory(path: &Path, private: bool) -> io::Result<()> {
    match fs::symlink_metadata(path) {
        Ok(meta)
            if meta.is_dir()
                && meta.uid() == rustix::process::geteuid().as_raw()
                && meta.mode() & 0o022 == 0 =>
        {
            if private {
                fs::set_permissions(path, fs::Permissions::from_mode(0o700))
            } else {
                Ok(())
            }
        }
        Ok(_) => Err(io::Error::other(
            "The image log directory has unsafe ownership or file type",
        )),
        Err(e) if e.kind() == io::ErrorKind::NotFound => fs::DirBuilder::new()
            .mode(if private { 0o700 } else { 0o755 })
            .create(path),
        Err(e) => Err(e),
    }
}
fn append(root: &Path, input: &Log) -> Result<Ready, Error> {
    let path = log_path(root, input)?;
    let write = || -> io::Result<()> {
        // Prove an existing destination before changing either directory.
        regular(&path)?;
        directory(&root.join("var/log/orbit"), false)?;
        directory(path.parent().unwrap(), true)?;
        let mut file = fs::OpenOptions::new()
            .append(true)
            .create(true)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW)
            .open(&path)?;
        file.set_permissions(fs::Permissions::from_mode(0o600))?;
        file.write_all(crate::logs::redact::redact_text(&input.text).as_bytes())?;
        file.sync_all()
    };
    write().map_err(|e| failed("log", e))?;
    Ok(Ready { ready: true })
}
pub struct WriteMarker;
impl Command for WriteMarker {
    const NAME: &'static str = "image.recipe-marker";
    const SUMMARY: &'static str = "Record the recipe identity of the base image builder.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    const RESOURCES: &'static [&'static str] = &["image-recipe-records"];
    type Input = Marker;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Marker, _: &Context) -> Result<Ready, Error> {
        marker(Path::new("/"), &input)
    }
}
pub struct AppendLog;
impl Command for AppendLog {
    const NAME: &'static str = "image.recipe-log";
    const SUMMARY: &'static str =
        "Append a sanitized observation to a fixed private image phase log.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    const RESOURCES: &'static [&'static str] = &["image-recipe-records"];
    type Input = Log;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Log, _: &Context) -> Result<Ready, Error> {
        append(Path::new("/"), &input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    struct Fixture(PathBuf);
    impl Fixture {
        fn new() -> Self {
            let root = std::env::temp_dir().join(format!(
                "orbit-image-records-{:016x}",
                rand::random::<u64>()
            ));
            fs::create_dir_all(root.join("etc")).unwrap();
            fs::create_dir_all(root.join("var/log")).unwrap();
            Self(root)
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.0).unwrap();
        }
    }
    #[test]
    fn marker_repeats_replaces_owned_identity_and_refuses_foreign_files() {
        let f = Fixture::new();
        let path = f.0.join("etc/orbit-machine-recipe.json");
        let mut input = Marker {
            identity: format!("sha256:{}", "a".repeat(64)),
            profile: "base".into(),
        };
        marker(&f.0, &input).unwrap();
        marker(&f.0, &input).unwrap();
        input.identity = format!("sha256:{}", "b".repeat(64));
        marker(&f.0, &input).unwrap();
        let result: Marker = serde_json::from_slice(&fs::read(&path).unwrap()).unwrap();
        assert_eq!(result.identity, input.identity);
        assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o644);
        fs::write(&path, "foreign").unwrap();
        assert!(marker(&f.0, &input).is_err());
        assert_eq!(fs::read(&path).unwrap(), b"foreign");
        fs::remove_file(&path).unwrap();
        std::os::unix::fs::symlink(f.0.join("outside"), &path).unwrap();
        assert!(marker(&f.0, &input).is_err());
        assert!(!f.0.join("outside").exists());
    }
    #[test]
    fn invalid_inputs_never_create_records() {
        let f = Fixture::new();
        for (identity, profile) in [
            (format!("sha256:{}", "x".repeat(64)), "base"),
            ("a".repeat(64), "base"),
            (format!("sha256:{}", "a".repeat(64)), "../base"),
        ] {
            assert!(marker(
                &f.0,
                &Marker {
                    identity,
                    profile: profile.into()
                }
            )
            .is_err());
        }
        for (phase, text) in [("../escape", String::new()), ("boot", "x".repeat(65537))] {
            assert!(append(
                &f.0,
                &Log {
                    profile: "base".into(),
                    phase: phase.into(),
                    text
                }
            )
            .is_err());
        }
        assert_eq!(fs::read_dir(f.0.join("etc")).unwrap().count(), 0);
        assert_eq!(fs::read_dir(f.0.join("var/log")).unwrap().count(), 0);
    }
    #[test]
    fn logs_append_redacted_observations_privately_and_refuse_links() {
        let f = Fixture::new();
        let input = Log {
            profile: "base".into(),
            phase: "boot".into(),
            text: "PASSWORD=sentinel\nnext\n".into(),
        };
        append(&f.0, &input).unwrap();
        append(&f.0, &input).unwrap();
        let path = log_path(&f.0, &input).unwrap();
        let text = fs::read_to_string(&path).unwrap();
        assert!(!text.contains("sentinel"));
        assert_eq!(text.matches("next").count(), 2);
        assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o600);
        assert_eq!(
            fs::metadata(path.parent().unwrap()).unwrap().mode() & 0o777,
            0o700
        );
        fs::remove_file(&path).unwrap();
        std::os::unix::fs::symlink(f.0.join("outside"), &path).unwrap();
        assert!(append(&f.0, &input).is_err());
        assert!(!f.0.join("outside").exists());
    }
    #[test]
    fn filesystem_failures_are_typed_and_do_not_leave_candidates() {
        let f = Fixture::new();
        fs::remove_dir(f.0.join("etc")).unwrap();
        assert!(
            matches!(marker(&f.0,&Marker{identity:"a".repeat(64),profile:"base".into()}),Err(Error::Failed{operation,..}) if operation=="marker")
        );
        fs::write(f.0.join("var/log/orbit"), "foreign").unwrap();
        assert!(
            matches!(append(&f.0,&Log{profile:"base".into(),phase:"boot".into(),text:String::new()}),Err(Error::Failed{operation,..}) if operation=="log")
        );
        assert_eq!(fs::read(f.0.join("var/log/orbit")).unwrap(), b"foreign");
    }
}
