//! `nodes.publish-tmpfiles`: Orbit's systemd-tmpfiles rule, `/etc/tmpfiles.d/orbit.conf`, root's `0644` file. It
//! writes the rule with one rename only when the file differs in content, owner, or mode, and says whether it did.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::{Path, PathBuf},
    time::Duration,
};

const PATH: &str = "/etc/tmpfiles.d/orbit.conf";

command_errors! {
    pub enum TmpfilesError {
        Invalid { check: String } => "nodes.tmpfiles_invalid", "The rule does not pass its checks.";
        Failed { reason: String } => "nodes.tmpfiles_failed", "The rule could not be written.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TmpfilesInput {
    pub rule: String,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Tmpfiles {
    pub changed: bool,
}

/// Whether the file is the owner's regular `0644` file with this content.
fn current(path: &Path, rule: &str, owner: (u32, u32)) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| {
        metadata.is_file()
            && (metadata.uid(), metadata.gid()) == owner
            && metadata.mode() & 0o7777 == 0o644
    }) && fs::read(path).ok().as_deref() == Some(rule.as_bytes())
}

pub fn publish(path: &Path, rule: &str, owner: (u32, u32)) -> Result<Tmpfiles, TmpfilesError> {
    if rule.is_empty()
        || rule.contains('\0')
        || !rule.lines().all(|line| {
            line.is_empty()
                || line.starts_with('#')
                || line.starts_with("e /tmp/orbit-")
                || line.starts_with("e /dev/shm/orbit-")
        })
    {
        return Err(TmpfilesError::Invalid {
            check: "the rule only empties Orbit's scratch directories".into(),
        });
    }
    if current(path, rule, owner) {
        return Ok(Tmpfiles { changed: false });
    }
    let candidate = PathBuf::from(format!("{}.{:016x}", path.display(), rand::random::<u64>()));
    let written = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o644)
        .open(&candidate)
        .and_then(|mut file| file.write_all(rule.as_bytes()))
        .and_then(|()| fs::rename(&candidate, path));
    if let Err(error) = written {
        let _ = fs::remove_file(&candidate);
        return Err(TmpfilesError::Failed {
            reason: error.to_string(),
        });
    }
    Ok(Tmpfiles { changed: true })
}

pub struct PublishTmpfiles;

impl Command for PublishTmpfiles {
    const NAME: &'static str = "nodes.publish-tmpfiles";
    const SUMMARY: &'static str = "Publish Orbit's tmpfiles rule for its scratch directories.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = TmpfilesInput;
    type Output = Tmpfiles;
    type Error = TmpfilesError;

    /// `/etc/tmpfiles.d` belongs to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: TmpfilesInput, _: &Context) -> Result<Tmpfiles, TmpfilesError> {
        publish(Path::new(PATH), &input.rule, (0, 0))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const RULE: &str =
        "# Managed by Orbit.\ne /tmp/orbit-* - - - 1d\ne /dev/shm/orbit-* - - - 1d\n";

    #[test]
    fn writes_once_then_reports_no_change() {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-tmpfiles-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(&base).unwrap();
        let path = base.join("orbit.conf");
        let me = (
            rustix::process::getuid().as_raw(),
            rustix::process::getgid().as_raw(),
        );

        assert_eq!(publish(&path, RULE, me), Ok(Tmpfiles { changed: true }));
        assert_eq!(fs::read_to_string(&path).unwrap(), RULE);
        assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o644);
        assert_eq!(publish(&path, RULE, me), Ok(Tmpfiles { changed: false }));
        let longer = format!("{RULE}e /tmp/orbit-x - - - 2d\n");
        assert_eq!(publish(&path, &longer, me), Ok(Tmpfiles { changed: true }));
        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn refuses_a_rule_that_reaches_beyond_orbits_scratch() {
        assert!(matches!(
            publish(
                Path::new("/nonexistent/orbit.conf"),
                "r /etc/passwd\n",
                (0, 0)
            ),
            Err(TmpfilesError::Invalid { .. })
        ));
        assert!(matches!(
            publish(Path::new("/nonexistent/orbit.conf"), "", (0, 0)),
            Err(TmpfilesError::Invalid { .. })
        ));
    }
}
