//! Development runtime hibernation on a Node: the awake and cold markers Caddy reads, the modification times the
//! Gateway compares, and an Instance checkout's dependencies, which hibernation inspects, prunes, and restores.

use super::caddy::{chown, group_id};
use crate::command::{identity, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, UNIX_EPOCH},
};

command_errors! {
    pub enum HibernationError {
        Failed { step: String } => "hibernation.node_failed", "A hibernation step failed on the Node.";
    }
}

fn failed(step: &str) -> HibernationError {
    HibernationError::Failed { step: step.into() }
}

/// Host roots whose modes the directory setup never changes.
const PROTECTED: [&str; 9] = [
    "/dev", "/dev/shm", "/etc", "/proc", "/run", "/sys", "/tmp", "/var", "/var/tmp",
];

/// Makes each ancestor of `directory` below the protected roots passable, then `directory` itself for Caddy.
fn ensure_directory(directory: &Path, mode: u32) -> std::io::Result<()> {
    let mut ancestors: Vec<&Path> = directory
        .ancestors()
        .skip(1)
        .take_while(|ancestor| {
            *ancestor != Path::new("/") && !PROTECTED.contains(&ancestor.to_string_lossy().as_ref())
        })
        .collect();
    ancestors.reverse();
    for ancestor in ancestors {
        if !ancestor.is_dir() {
            fs::DirBuilder::new().mode(0o755).create(ancestor)?;
        }
        fs::set_permissions(ancestor, fs::Permissions::from_mode(0o755))?;
    }
    if !directory.is_dir() {
        fs::DirBuilder::new().mode(0o755).create(directory)?;
    }
    let caddy =
        group_id("caddy").ok_or_else(|| std::io::Error::other("the caddy group does not exist"))?;
    chown(directory, 0, caddy)?;
    fs::set_permissions(directory, fs::Permissions::from_mode(mode))
}

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum MarkerInput {
    /// Prepares the marker and access log directories, then creates the marker readable by Caddy.
    Mark {
        marker_directory: PathBuf,
        log_directory: PathBuf,
        path: PathBuf,
    },
    Remove {
        path: PathBuf,
    },
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}

pub fn marker(input: &MarkerInput) -> Result<Done, HibernationError> {
    match input {
        MarkerInput::Mark {
            marker_directory,
            log_directory,
            path,
        } => {
            let step = "hibernation-marker";
            ensure_directory(marker_directory, 0o755).map_err(|_| failed(step))?;
            ensure_directory(log_directory, 0o2775).map_err(|_| failed(step))?;
            if !path.starts_with(marker_directory) {
                return Err(failed(step));
            }
            fs::OpenOptions::new()
                .create(true)
                .append(true)
                .open(path)
                .and_then(|file| file.set_modified(std::time::SystemTime::now()))
                .and_then(|()| fs::set_permissions(path, fs::Permissions::from_mode(0o644)))
                .map_err(|_| failed(step))?;
        }
        MarkerInput::Remove { path } => match fs::remove_file(path) {
            Err(error) if error.kind() != std::io::ErrorKind::NotFound => {
                return Err(failed("hibernation-marker"))
            }
            _ => {}
        },
    }
    Ok(Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TimesInput {
    pub paths: Vec<PathBuf>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Times {
    /// Each path's modification time in Unix seconds, or null when it does not exist.
    pub times: BTreeMap<String, Option<u64>>,
}

pub fn times(input: &TimesInput) -> Times {
    Times {
        times: input
            .paths
            .iter()
            .map(|path| {
                let time = fs::metadata(path)
                    .and_then(|metadata| metadata.modified())
                    .ok()
                    .and_then(|time| time.duration_since(UNIX_EPOCH).ok())
                    .map(|duration| duration.as_secs())
                    .filter(|seconds| *seconds > 0);
                (path.to_string_lossy().into_owned(), time)
            })
            .collect(),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CheckoutInput {
    /// The Instance's checkout, whose newest source file dates its last activity.
    pub repository: PathBuf,
    /// The application directory that holds the dependencies.
    pub checkout: PathBuf,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Checkout {
    pub composer_json: bool,
    pub composer_lock: bool,
    pub vendor_present: bool,
    pub vendor_symlink: bool,
    pub package_json: bool,
    pub node_modules_present: bool,
    pub node_modules_symlink: bool,
    pub javascript_locks: Vec<String>,
    pub source_mtime: Option<u64>,
}

fn regular_file(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.is_file())
}

fn is_symlink(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.file_type().is_symlink())
}

fn real_directory(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.is_dir())
}

/// The newest modification time of a source file, skipping dependencies and Git.
fn newest_source(directory: &Path, newest: &mut u64) {
    let Ok(entries) = fs::read_dir(directory) else {
        return;
    };
    for entry in entries.flatten() {
        let name = entry.file_name();
        if matches!(name.to_str(), Some("vendor" | "node_modules" | ".git")) {
            continue;
        }
        let Ok(metadata) = entry.path().symlink_metadata() else {
            continue;
        };
        if metadata.is_dir() {
            newest_source(&entry.path(), newest);
        } else if metadata.is_file() {
            *newest = (*newest).max(u64::try_from(metadata.mtime()).unwrap_or(0));
        }
    }
}

fn canonical(path: &Path) -> bool {
    fs::canonicalize(path).ok().as_deref() == Some(path)
}

pub fn inspect(input: &CheckoutInput) -> Result<Checkout, HibernationError> {
    let checkout = &input.checkout;
    if !canonical(checkout) {
        return Err(failed("hibernation-inspect"));
    }
    let mut newest = 0;
    newest_source(&input.repository, &mut newest);
    Ok(Checkout {
        composer_json: regular_file(&checkout.join("composer.json")),
        composer_lock: regular_file(&checkout.join("composer.lock")),
        vendor_present: real_directory(&checkout.join("vendor")),
        vendor_symlink: is_symlink(&checkout.join("vendor")),
        package_json: regular_file(&checkout.join("package.json")),
        node_modules_present: real_directory(&checkout.join("node_modules")),
        node_modules_symlink: is_symlink(&checkout.join("node_modules")),
        javascript_locks: [
            "package-lock.json",
            "yarn.lock",
            "pnpm-lock.yaml",
            "bun.lock",
            "bun.lockb",
        ]
        .into_iter()
        .filter(|lock| regular_file(&checkout.join(lock)))
        .map(String::from)
        .collect(),
        source_mtime: (newest > 0).then_some(newest),
    })
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PruneInput {
    pub checkout: PathBuf,
    /// `vendor` or `node_modules`.
    pub targets: Vec<String>,
}

pub fn prune(input: &PruneInput) -> Result<Done, HibernationError> {
    let step = "hibernation-prune";
    if !canonical(&input.checkout) {
        return Err(failed(step));
    }
    for target in &input.targets {
        if !matches!(target.as_str(), "vendor" | "node_modules") {
            return Err(failed(step));
        }
        let path = input.checkout.join(target);
        if !real_directory(&path) {
            return Err(failed(step));
        }
        fs::remove_dir_all(&path).map_err(|_| failed(step))?;
    }
    Ok(Done {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RestoreInput {
    pub user: String,
    pub checkout: PathBuf,
    pub composer: bool,
    pub javascript: bool,
}

/// Runs a dependency installer as the managed account in the application directory.
fn install(
    user: &str,
    checkout: &Path,
    program: &str,
    arguments: &[&str],
    environment: &[(&str, &str)],
) -> bool {
    let mut command = Process::new(program);
    command
        .args(arguments)
        .envs(environment.iter().copied())
        .current_dir(checkout)
        .stdin(Stdio::null())
        .stdout(Stdio::null());
    identity::drop_to(&mut command, user).is_ok()
        && command.status().is_ok_and(|status| status.success())
}

pub fn restore(input: &RestoreInput) -> Result<Done, HibernationError> {
    let checkout = input.checkout.to_string_lossy();
    if input.composer {
        let directory = format!("--working-dir={checkout}");
        let installed = install(
            &input.user,
            &input.checkout,
            "/usr/bin/composer",
            &["install", "--no-interaction", "--prefer-dist", &directory],
            &[("COMPOSER_HOME", "/opt/orbit/composer")],
        );
        if !installed {
            return Err(failed("restore-composer"));
        }
    }
    if input.javascript
        && !install(
            &input.user,
            &input.checkout,
            "/usr/local/bin/vp",
            &["install", "--frozen-lockfile"],
            &[],
        )
    {
        return Err(failed("restore-javascript"));
    }
    Ok(Done {})
}

pub struct MarkHibernation;

impl Command for MarkHibernation {
    const NAME: &'static str = "hibernation.marker";
    const SUMMARY: &'static str = "Create or remove a hibernation marker that Caddy reads.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = MarkerInput;
    type Output = Done;
    type Error = HibernationError;

    /// The marker directories belong to root and Caddy's group.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: MarkerInput, _: &Context) -> Result<Done, HibernationError> {
        marker(&input)
    }
}

pub struct ReadHibernationTimes;

impl Command for ReadHibernationTimes {
    const NAME: &'static str = "hibernation.times";
    const SUMMARY: &'static str =
        "Read the modification times of hibernation markers and access logs.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = TimesInput;
    type Output = Times;
    type Error = HibernationError;

    /// Access logs belong to root and Caddy.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: TimesInput, _: &Context) -> Result<Times, HibernationError> {
        Ok(times(&input))
    }
}

pub struct InspectCheckout;

impl Command for InspectCheckout {
    const NAME: &'static str = "hibernation.inspect-checkout";
    const SUMMARY: &'static str =
        "Report an Instance checkout's dependency files and its last source change.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = CheckoutInput;
    type Output = Checkout;
    type Error = HibernationError;

    /// Root reads any checkout without its owner's permissions.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: CheckoutInput, _: &Context) -> Result<Checkout, HibernationError> {
        inspect(&input)
    }
}

pub struct PruneCheckout;

impl Command for PruneCheckout {
    const NAME: &'static str = "hibernation.prune-checkout";
    const SUMMARY: &'static str = "Remove an Instance checkout's vendor or node_modules directory.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = PruneInput;
    type Output = Done;
    type Error = HibernationError;

    /// Root removes dependency trees whatever their files' owners.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: PruneInput, _: &Context) -> Result<Done, HibernationError> {
        prune(&input)
    }
}

pub struct RestoreDependencies;

impl Command for RestoreDependencies {
    const NAME: &'static str = "hibernation.restore-dependencies";
    const SUMMARY: &'static str =
        "Install an Instance checkout's Composer or JavaScript dependencies again.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = RestoreInput;
    type Output = Done;
    type Error = HibernationError;

    /// The installers run project code, as the managed account.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: RestoreInput, _: &Context) -> Result<Done, HibernationError> {
        restore(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn inspection_reports_dependency_files_and_prune_removes_only_real_dependency_directories() {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-hibernation-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("vendor")).unwrap();
        fs::create_dir_all(root.join("app")).unwrap();
        fs::write(root.join("composer.json"), "{}").unwrap();
        fs::write(root.join("app/source.php"), "<?php").unwrap();
        fs::write(root.join("yarn.lock"), "").unwrap();
        std::os::unix::fs::symlink(root.join("app"), root.join("node_modules")).unwrap();
        let input = CheckoutInput {
            repository: root.clone(),
            checkout: root.clone(),
        };

        let checkout = inspect(&input).unwrap();
        assert!(checkout.composer_json && !checkout.composer_lock && checkout.vendor_present);
        assert!(checkout.node_modules_symlink && !checkout.node_modules_present);
        assert_eq!(checkout.javascript_locks, ["yarn.lock"]);
        assert!(checkout.source_mtime.is_some());

        let prune_input = |target: &str| PruneInput {
            checkout: root.clone(),
            targets: vec![target.into()],
        };
        assert_eq!(
            prune(&prune_input("node_modules")),
            Err(failed("hibernation-prune"))
        );
        assert_eq!(prune(&prune_input("app")), Err(failed("hibernation-prune")));
        assert_eq!(prune(&prune_input("vendor")), Ok(Done {}));
        assert!(!root.join("vendor").exists() && root.join("app/source.php").exists());
        let _ = fs::remove_dir_all(&root);
    }

    #[test]
    fn times_answer_null_for_a_missing_path() {
        let file = std::env::temp_dir().join(format!(
            "orbit-hibernation-time-{:016x}",
            rand::random::<u64>()
        ));
        fs::write(&file, "").unwrap();
        let missing = file.with_extension("missing");
        let answer = times(&TimesInput {
            paths: vec![file.clone(), missing.clone()],
        });
        assert!(answer.times[&file.to_string_lossy().into_owned()].is_some());
        assert_eq!(answer.times[&missing.to_string_lossy().into_owned()], None);
        let _ = fs::remove_file(&file);
    }
}
