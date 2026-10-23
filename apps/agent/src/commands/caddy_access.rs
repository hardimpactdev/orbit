//! `instances.grant-development-caddy-access`: denies Caddy each development checkout, then grants it each Web root,
//! the Laravel public storage, and passage through the directories above them. Every writer of a checkout is the
//! managed account or the operator, so the walk's ACL tools run as the managed account and act by name: an entry the
//! account does not own fails the walk with "Operation not permitted", and the walk never skips it. Root acts only on
//! a directory that root owns, on a path that root owns from `/` down, above every checkout. Caddy's own view decides
//! what it may already pass. A failed walk restores what it granted from a snapshot in a private directory under
//! `/var/tmp`, and the deny on the rest stays. This replaces the Bash of `DevelopmentCaddyAccessCommand`, step for
//! step.

use super::source::{is_real_directory, is_symlink, realpath};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    ffi::OsStr,
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    process::{Command as Process, Output, Stdio},
    time::{Duration, SystemTime},
};

command_errors! {
    pub enum AccessError {
        Invalid { check: String } => "instances.caddy_access_invalid", "A checkout or Web root does not pass its checks.";
        Failed { reason: String } => "instances.caddy_access_failed", "The Caddy access walk failed; what it granted is restored.";
        RecoveryFailed { snapshots: String } => "instances.caddy_access_recovery_failed", "The Caddy access walk failed and its recovery failed; the snapshots remain.";
    }
}

fn invalid(check: &str) -> AccessError {
    AccessError::Invalid {
        check: check.into(),
    }
}

fn check(condition: bool, what: &str) -> Result<(), AccessError> {
    if condition {
        Ok(())
    } else {
        Err(invalid(what))
    }
}

#[derive(Deserialize, Clone)]
#[serde(deny_unknown_fields)]
pub struct Site {
    pub checkout: PathBuf,
    /// The Web root, relative to the checkout.
    pub relative_root: String,
    /// The application directory that holds `storage`.
    pub application: PathBuf,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct AccessInput {
    /// The managed account that owns the checkouts.
    pub user: String,
    pub sites: Vec<Site>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Granted {
    /// Served entries whose mode hides them from Caddy, at most 100 and a marker when there are more.
    pub unreadable: Vec<String>,
}

/// One site after its checks: the real checkout, its Web root, its application, its storage target, and its Git
/// directory.
struct Checked {
    checkout: PathBuf,
    root: PathBuf,
    application: PathBuf,
    storage: Option<PathBuf>,
    git: PathBuf,
}

/// Runs a program as `user`, from `/`, with standard input closed.
fn as_user(user: &str, program: &str, arguments: &[&OsStr]) -> Option<Output> {
    let mut command = Process::new(program);
    command
        .args(arguments)
        .current_dir("/")
        .stdin(Stdio::null());
    identity::drop_to(&mut command, user).ok()?;
    command.output().ok()
}

fn as_root(program: &str, arguments: &[&OsStr]) -> Option<Output> {
    Process::new(program)
        .args(arguments)
        .current_dir("/")
        .stdin(Stdio::null())
        .output()
        .ok()
}

/// A walk succeeds when the tool does, or when its only errors are entries that vanished meanwhile: an entry setup
/// removed needs no ACL, and one created in its place inherits its directory's default ACL.
fn walked(output: Option<Output>) -> Result<Vec<u8>, String> {
    let output = output.ok_or_else(|| "a walk tool cannot start".to_string())?;
    if output.status.success() {
        return Ok(output.stdout);
    }
    let errors = String::from_utf8_lossy(&output.stderr);
    if !errors.trim().is_empty()
        && errors
            .lines()
            .all(|line| line.ends_with(": No such file or directory"))
    {
        return Ok(output.stdout);
    }
    Err(errors.trim().to_string())
}

fn git(user: &str, directory: &Path, arguments: &[&str]) -> Option<String> {
    let mut all: Vec<&OsStr> = [
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
    ]
    .map(OsStr::new)
    .to_vec();
    all.push(directory.as_os_str());
    all.extend(arguments.iter().map(OsStr::new));
    let output = as_user(user, "git", &all)?;
    output.status.success().then(|| {
        String::from_utf8_lossy(&output.stdout)
            .trim_end_matches('\n')
            .to_string()
    })
}

fn owned_by(path: &Path, uid: u32) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.uid() == uid)
}

/// Root owns the path and every directory above it.
fn root_owned(path: &Path) -> bool {
    path.ancestors().all(|ancestor| owned_by(ancestor, 0))
}

/// Whether Caddy may read (`r`) or search (`x`) the path, as Bash's `test` checks it with faccessat, ACLs included.
fn caddy_can(mode: &str, path: &Path) -> bool {
    let flag = format!("-{mode}");
    as_user(
        "caddy",
        "bash",
        &[
            "-c".as_ref(),
            r#"test "$1" "$2""#.as_ref(),
            "caddy-access".as_ref(),
            flag.as_ref(),
            path.as_os_str(),
        ],
    )
    .is_some_and(|output| output.status.success())
}

/// The ACL change that lets Caddy pass a directory: execute for Caddy's entry and for the mask, nothing else.
fn passage_grant(acl: &str) -> String {
    let field = |prefix: &str| {
        acl.lines()
            .find_map(|line| line.strip_prefix(prefix))
            .map(|rest| rest.chars().take(3).collect::<String>())
    };
    let caddy = field("user:caddy:").unwrap_or_else(|| "---".into());
    let mask = field("mask::")
        .or_else(|| field("group::"))
        .unwrap_or_else(|| "---".into());
    format!("u:caddy:{}x,m::{}x", &caddy[..2], &mask[..2])
}

/// Checks one site and resolves a development default that still serves through `current` in the old release layout.
fn check_site(user: &str, uid: u32, site: &Site) -> Result<Checked, AccessError> {
    let (mut checkout, mut application) = (site.checkout.clone(), site.application.clone());
    if is_symlink(&checkout) {
        check(
            checkout.file_name() == Some(OsStr::new("current")),
            "a linked checkout is current",
        )?;
        let home = checkout
            .parent()
            .ok_or_else(|| invalid("current has a home"))?
            .to_path_buf();
        check(
            is_real_directory(&home.join(".git")),
            "the home has its Git directory",
        )?;
        let state = home.join(".git/orbit-development-releases");
        check(
            is_real_directory(&state),
            "the release state is a directory",
        )?;
        let identity = fs::read_to_string(state.join("identity"))
            .ok()
            .filter(|_| !is_symlink(&state.join("identity")));
        let link = fs::read_link(&checkout).map_err(|_| invalid("current is a link"))?;
        let name = link
            .strip_prefix("releases")
            .ok()
            .and_then(|name| name.to_str())
            .filter(|name| super::production::valid_name(name))
            .ok_or_else(|| invalid("current names a release"))?
            .to_string();
        let record = state.join(format!("release-{name}"));
        check(
            !is_symlink(&record)
                && fs::read_to_string(&record)
                    .ok()
                    .zip(identity)
                    .is_some_and(|(record, identity)| record == format!("{identity}:{name}")),
            "the release record names this release",
        )?;
        let resolved = realpath(&checkout).ok_or_else(|| invalid("current resolves"))?;
        check(
            resolved == home.join("releases").join(&name),
            "current is the named release",
        )?;
        check(
            git(
                user,
                &resolved,
                &["rev-parse", "--path-format=absolute", "--git-common-dir"],
            )
            .as_deref()
                == Some(home.join(".git").to_string_lossy().as_ref()),
            "the release shares the home's repository",
        )?;
        application = resolved.join(application.strip_prefix(&checkout).unwrap_or(Path::new("")));
        checkout = resolved;
    }
    check(
        is_real_directory(&checkout) && realpath(&checkout).as_deref() == Some(checkout.as_path()),
        "the checkout is a canonical directory",
    )?;
    check(
        git(user, &checkout, &["rev-parse", "--show-toplevel"]).as_deref()
            == Some(checkout.to_string_lossy().as_ref()),
        "the checkout is a work tree root",
    )?;
    check(
        owned_by(&checkout, uid),
        "the managed account owns the checkout",
    )?;
    let common = git(
        user,
        &checkout,
        &["rev-parse", "--path-format=absolute", "--git-common-dir"],
    )
    .ok_or_else(|| invalid("the checkout has a Git directory"))?;
    let git_directory = PathBuf::from(common);
    check(
        is_real_directory(&git_directory)
            && realpath(&git_directory).as_deref() == Some(git_directory.as_path()),
        "the Git directory is canonical",
    )?;
    check(
        owned_by(&git_directory, uid),
        "the managed account owns the Git directory",
    )?;
    let root = checkout.join(&site.relative_root);
    check(
        is_real_directory(&root) && realpath(&root).as_deref() == Some(root.as_path()),
        "the Web root is a canonical directory",
    )?;

    let links = walked(as_user(
        user,
        "find",
        &[
            "-P".as_ref(),
            root.as_os_str(),
            "-type".as_ref(),
            "l".as_ref(),
            "-print0".as_ref(),
        ],
    ))
    .map_err(|reason| AccessError::Failed { reason })?;
    let mut storage = None;
    for link in links
        .split(|byte| *byte == 0)
        .filter(|link| !link.is_empty())
    {
        let link = PathBuf::from(String::from_utf8_lossy(link).into_owned());
        let expected = application.join("storage/app/public");
        check(
            root == application.join("public") && link == root.join("storage"),
            "the only link in a Web root is its public storage",
        )?;
        check(
            realpath(&link).as_deref() == Some(expected.as_path()) && is_real_directory(&expected),
            "the storage link names the application's public storage",
        )?;
        let nested = walked(as_user(
            user,
            "find",
            &[
                "-P".as_ref(),
                expected.as_os_str(),
                "-type".as_ref(),
                "l".as_ref(),
                "-print".as_ref(),
                "-quit".as_ref(),
            ],
        ))
        .map_err(|reason| AccessError::Failed { reason })?;
        check(nested.is_empty(), "the public storage holds no link")?;
        storage = Some(expected);
    }
    Ok(Checked {
        checkout,
        root,
        application,
        storage,
        git: git_directory,
    })
}

/// The walk after the checks, with the two snapshot files it writes first.
struct Walk<'a> {
    user: &'a str,
    saved: PathBuf,
    root_saved: PathBuf,
    root_grants: Vec<PathBuf>,
}

impl Walk<'_> {
    fn user(&self, program: &str, arguments: &[&OsStr]) -> Result<Vec<u8>, AccessError> {
        walked(as_user(self.user, program, arguments))
            .map_err(|reason| AccessError::Failed { reason })
    }

    fn root(&self, program: &str, arguments: &[&OsStr]) -> Result<Vec<u8>, AccessError> {
        walked(as_root(program, arguments)).map_err(|reason| AccessError::Failed { reason })
    }

    fn save(&self, acl: Vec<u8>, root: bool) -> Result<(), AccessError> {
        use std::io::Write;
        let path = if root { &self.root_saved } else { &self.saved };
        fs::OpenOptions::new()
            .append(true)
            .open(path)
            .and_then(|mut file| file.write_all(&acl))
            .map_err(|_| AccessError::Failed {
                reason: "a snapshot cannot be written".into(),
            })
    }

    fn snapshot(&mut self, sites: &[Checked]) -> Result<(), AccessError> {
        for site in sites {
            let acl = self.user(
                "getfacl",
                &[
                    "-R".as_ref(),
                    "-P".as_ref(),
                    "-p".as_ref(),
                    "--".as_ref(),
                    site.root.as_os_str(),
                ],
            )?;
            self.save(acl, false)?;
            for ancestor in site.root.ancestors().skip(1) {
                if !root_owned(ancestor) {
                    let acl = self.user(
                        "getfacl",
                        &["-p".as_ref(), "--".as_ref(), ancestor.as_os_str()],
                    )?;
                    self.save(acl, false)?;
                } else if !caddy_can("x", ancestor) {
                    let acl = self.root(
                        "getfacl",
                        &["-p".as_ref(), "--".as_ref(), ancestor.as_os_str()],
                    )?;
                    self.save(acl, true)?;
                    self.root_grants.push(ancestor.to_path_buf());
                }
            }
            if let Some(storage) = &site.storage {
                let (first, second) = (
                    site.application.join("storage"),
                    site.application.join("storage/app"),
                );
                let acl = self.user(
                    "getfacl",
                    &[
                        "-p".as_ref(),
                        "--".as_ref(),
                        first.as_os_str(),
                        second.as_os_str(),
                    ],
                )?;
                self.save(acl, false)?;
                let acl = self.user(
                    "getfacl",
                    &[
                        "-R".as_ref(),
                        "-P".as_ref(),
                        "-p".as_ref(),
                        "--".as_ref(),
                        storage.as_os_str(),
                    ],
                )?;
                self.save(acl, false)?;
            }
        }
        // Linked worktrees keep shared Git metadata outside their checkout trees; its directory ACL is saved too.
        let mut arguments: Vec<&OsStr> = vec!["-p".as_ref(), "--".as_ref()];
        arguments.extend(sites.iter().map(|site| site.git.as_os_str()));
        let acl = self.user("getfacl", &arguments)?;
        self.save(acl, false)
    }

    /// Default ACLs before access ACLs, so a file created during the walk ends up with the right entry.
    fn tree(&self, path: &Path, default: &str, access: &str) -> Result<(), AccessError> {
        self.user(
            "find",
            &[
                "-P".as_ref(),
                path.as_os_str(),
                "-type".as_ref(),
                "d".as_ref(),
                "-exec".as_ref(),
                "setfacl".as_ref(),
                "-m".as_ref(),
                default.as_ref(),
                "--".as_ref(),
                "{}".as_ref(),
                "+".as_ref(),
            ],
        )?;
        self.user(
            "setfacl",
            &[
                "-P".as_ref(),
                "-R".as_ref(),
                "-n".as_ref(),
                "-m".as_ref(),
                access.as_ref(),
                "--".as_ref(),
                path.as_os_str(),
            ],
        )?;
        Ok(())
    }

    fn pass(&self, directory: &Path) -> Result<(), AccessError> {
        let acl = String::from_utf8_lossy(&self.user(
            "getfacl",
            &["-cp".as_ref(), "--".as_ref(), directory.as_os_str()],
        )?)
        .into_owned();
        self.user(
            "setfacl",
            &[
                "-n".as_ref(),
                "-m".as_ref(),
                passage_grant(&acl).as_ref(),
                "--".as_ref(),
                directory.as_os_str(),
            ],
        )?;
        Ok(())
    }

    fn grant(&self, sites: &[Checked]) -> Result<Vec<String>, AccessError> {
        for site in sites {
            self.user(
                "setfacl",
                &[
                    "-n".as_ref(),
                    "-m".as_ref(),
                    "u:caddy:---".as_ref(),
                    "--".as_ref(),
                    site.git.as_os_str(),
                ],
            )?;
        }
        // Every deny comes before any grant, so a nested worktree keeps its own Web root.
        for site in sites {
            self.tree(&site.checkout, "d:u:caddy:---", "u:caddy:---")?;
        }
        let mut unreadable = Vec::new();
        for site in sites {
            for ancestor in site.root.ancestors().skip(1) {
                if caddy_can("x", ancestor) {
                    continue;
                }
                if !root_owned(ancestor) {
                    self.pass(ancestor)?;
                } else if self.root_grants.iter().any(|saved| saved == ancestor) {
                    let acl = String::from_utf8_lossy(&self.root(
                        "getfacl",
                        &["-cp".as_ref(), "--".as_ref(), ancestor.as_os_str()],
                    )?)
                    .into_owned();
                    self.root(
                        "setfacl",
                        &[
                            "-n".as_ref(),
                            "-m".as_ref(),
                            passage_grant(&acl).as_ref(),
                            "--".as_ref(),
                            ancestor.as_os_str(),
                        ],
                    )?;
                } else {
                    return Err(AccessError::Failed {
                        reason: format!(
                            "Caddy cannot pass {}, which was not saved before the walk.",
                            ancestor.display()
                        ),
                    });
                }
            }
            // The grants keep each entry's mask, so a mode 0600 file in the Web root stays hidden from Caddy.
            self.tree(&site.root, "d:u:caddy:r-x", "u:caddy:r-X")?;
            let mut served = vec![site.root.clone()];
            if let Some(storage) = &site.storage {
                self.pass(&site.application.join("storage"))?;
                self.pass(&site.application.join("storage/app"))?;
                self.tree(storage, "d:u:caddy:r-x", "u:caddy:r-X")?;
            }
            if !caddy_can("r", &site.root) || !caddy_can("x", &site.root) {
                return Err(AccessError::Failed { reason: format!("Caddy cannot read the Web root {}, whose mode hides it from the group class.", site.root.display()) });
            }
            if let Some(storage) = &site.storage {
                if !caddy_can("r", storage) || !caddy_can("x", storage) {
                    return Err(AccessError::Failed { reason: format!("Caddy cannot read the public storage {}, whose mode hides it from the group class.", storage.display()) });
                }
                served.push(storage.clone());
            }
            unreadable.extend(hidden_from_caddy(&served));
        }
        unreadable.truncate(101);
        Ok(unreadable)
    }
}

/// The served entries that Caddy cannot read or, for a directory, enter.
fn hidden_from_caddy(served: &[PathBuf]) -> Vec<String> {
    let mut arguments: Vec<&OsStr> = vec!["-P".as_ref()];
    arguments.extend(served.iter().map(|path| path.as_os_str()));
    arguments.extend(
        [
            "-mindepth",
            "1",
            "(",
            "-type",
            "d",
            "!",
            "(",
            "-readable",
            "-executable",
            ")",
            "-print",
            "-prune",
            ")",
            "-o",
            "(",
            "!",
            "-type",
            "l",
            "!",
            "-type",
            "d",
            "!",
            "-readable",
            "-print",
            ")",
        ]
        .map(OsStr::new),
    );
    as_user("caddy", "find", &arguments)
        .map(|output| {
            String::from_utf8_lossy(&output.stdout)
                .lines()
                .take(101)
                .map(String::from)
                .collect()
        })
        .unwrap_or_default()
}

/// The account's snapshot directories older than three days, which a failed recovery left.
fn prune_old_snapshots(uid: u32) {
    let Ok(entries) = fs::read_dir("/var/tmp") else {
        return;
    };
    let old = SystemTime::now() - Duration::from_secs(3 * 24 * 3600);
    for entry in entries.flatten() {
        let stale = entry
            .file_name()
            .to_string_lossy()
            .starts_with("orbit-caddy-acl.")
            && fs::symlink_metadata(entry.path()).is_ok_and(|metadata| {
                metadata.is_dir()
                    && metadata.uid() == uid
                    && metadata.modified().is_ok_and(|modified| modified < old)
            });
        if stale {
            let _ = fs::remove_dir_all(entry.path());
        }
    }
}

pub fn grant(input: &AccessInput) -> Result<Granted, AccessError> {
    let (uid, _, _) =
        identity::account(&input.user).map_err(|_| invalid("the managed account exists"))?;
    check(uid != 0, "the managed account is not root")?;
    let sites = input
        .sites
        .iter()
        .map(|site| check_site(&input.user, uid, site))
        .collect::<Result<Vec<_>, _>>()?;
    if sites.is_empty() {
        return Ok(Granted {
            unreadable: Vec::new(),
        });
    }
    prune_old_snapshots(uid);
    // The account owns the snapshots, so its own restore can read them; Caddy cannot.
    let snapshots = as_user(
        &input.user,
        "mktemp",
        &[
            "-d".as_ref(),
            "-p".as_ref(),
            "/var/tmp".as_ref(),
            "orbit-caddy-acl.XXXXXX".as_ref(),
        ],
    )
    .filter(|output| output.status.success())
    .map(|output| {
        PathBuf::from(
            String::from_utf8_lossy(&output.stdout)
                .trim_end()
                .to_string(),
        )
    })
    .ok_or_else(|| AccessError::Failed {
        reason: "the snapshot directory cannot be made".into(),
    })?;
    check(
        fs::symlink_metadata(&snapshots)
            .is_ok_and(|metadata| metadata.uid() == uid && metadata.mode() & 0o777 == 0o700),
        "the snapshot directory is private",
    )?;
    let mut walk = Walk {
        user: &input.user,
        saved: snapshots.join("managed"),
        root_saved: snapshots.join("root"),
        root_grants: Vec::new(),
    };
    for file in [&walk.saved, &walk.root_saved] {
        as_user(&input.user, "touch", &["--".as_ref(), file.as_os_str()])
            .filter(|output| output.status.success())
            .ok_or_else(|| AccessError::Failed {
                reason: "a snapshot cannot be made".into(),
            })?;
    }
    if let Err(error) = walk.snapshot(&sites) {
        let _ = fs::remove_dir_all(&snapshots);
        return Err(error);
    }
    match walk.grant(&sites) {
        Ok(unreadable) => {
            let _ = fs::remove_dir_all(&snapshots);
            Ok(Granted { unreadable })
        }
        Err(error) => {
            let restore = |saved: &Path, root: bool| {
                let has = fs::metadata(saved).is_ok_and(|metadata| metadata.len() > 0);
                let arguments = [OsStr::new("--restore"), saved.as_os_str()];
                !has || if root {
                    walked(as_root("setfacl", &arguments)).is_ok()
                } else {
                    walked(as_user(&input.user, "setfacl", &arguments)).is_ok()
                }
            };
            let recovered = restore(&walk.saved, false) & restore(&walk.root_saved, true);
            if !recovered {
                return Err(AccessError::RecoveryFailed {
                    snapshots: snapshots.display().to_string(),
                });
            }
            let _ = fs::remove_dir_all(&snapshots);
            Err(error)
        }
    }
}

pub struct GrantDevelopmentCaddyAccess;

impl Command for GrantDevelopmentCaddyAccess {
    const NAME: &'static str = "instances.grant-development-caddy-access";
    const SUMMARY: &'static str =
        "Deny Caddy development checkouts and grant it each Web root and the passage to it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = AccessInput;
    type Output = Granted;
    type Error = AccessError;

    /// The walk runs as the managed account; root grants only root-owned directories above the checkouts.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn target(input: &AccessInput) -> Option<String> {
        Some(format!("caddy-access:{}", input.user))
    }

    fn run(input: AccessInput, _: &Context) -> Result<Granted, AccessError> {
        grant(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_passage_grant_adds_execute_to_caddy_and_the_mask_only() {
        assert_eq!(
            passage_grant("user::rwx\nuser:caddy:r--\ngroup::r-x\nmask::r--\nother::---\n"),
            "u:caddy:r-x,m::r-x"
        );
        assert_eq!(
            passage_grant("user::rwx\ngroup::rw-\nother::---\n"),
            "u:caddy:--x,m::rwx"
        );
    }

    #[test]
    fn a_walk_forgives_only_vanished_entries() {
        let output = |code: i32, errors: &str| {
            use std::os::unix::process::ExitStatusExt;
            Some(Output {
                status: std::process::ExitStatus::from_raw(code << 8),
                stdout: b"out".to_vec(),
                stderr: errors.as_bytes().to_vec(),
            })
        };
        assert_eq!(walked(output(0, "")), Ok(b"out".to_vec()));
        assert_eq!(
            walked(output(1, "find: './gone': No such file or directory\n")),
            Ok(b"out".to_vec())
        );
        assert!(walked(output(1, "setfacl: x: Operation not permitted\n")).is_err());
        assert!(walked(output(1, "")).is_err());
    }
}
