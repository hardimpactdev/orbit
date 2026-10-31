//! Read-only Instance source, publication, removal-state and production-route observations.

use super::source::*;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

command_errors! {
    pub enum SourceError {
        PathMismatch {} => "instance.path_mismatch", "The source path is not the recorded one.";
        OwnershipMismatch {} => "instance.ownership_mismatch", "The source is not owned by the managed account.";
        LayoutMismatch {} => "instance.layout_mismatch", "The source does not have the recorded Git layout.";
        OriginMismatch {} => "instance.origin_mismatch", "The source origin is not the Project repository.";
        BranchMismatch {} => "instance.branch_mismatch", "The source is not on the recorded branch.";
        Refused {} => "instance.removal_refused", "The removal evidence does not match the recorded removal.";
        Failed { step: String } => "instance.source_step_failed", "A source step failed.";
    }
}

fn account(user: &str, group: &str) -> String {
    format!("{user}:{group}")
}

fn failed(step: &str) -> impl Fn() -> SourceError + '_ {
    move || SourceError::Failed {
        step: step.to_string(),
    }
}

const PATH: fn() -> SourceError = || SourceError::PathMismatch {};
const OWNER: fn() -> SourceError = || SourceError::OwnershipMismatch {};
const LAYOUT: fn() -> SourceError = || SourceError::LayoutMismatch {};
const ORIGIN: fn() -> SourceError = || SourceError::OriginMismatch {};
const BRANCH: fn() -> SourceError = || SourceError::BranchMismatch {};
const REFUSED: fn() -> SourceError = || SourceError::Refused {};

fn short_branch(directory: &Path) -> Vec<u8> {
    git_output(
        directory,
        &["symbolic-ref", "--quiet", "--short", "HEAD"],
        false,
    )
    .unwrap_or_default()
}

fn head_commit(directory: &Path, quiet: bool) -> Option<Vec<u8>> {
    git_output(
        directory,
        &["rev-parse", "--verify", "HEAD^{commit}"],
        quiet,
    )
}

// ---------------------------------------------------------------------------------------------------------------

/// Reads a source's evidence for Instance removal. It changes nothing.
pub struct InspectSource;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectSourceInput {
    pub checkout: PathBuf,
    pub root: PathBuf,
    pub user: String,
    pub group: String,
    /// `checkout` or `worktree`.
    pub layout: String,
    pub branch: String,
    pub inspect_content: bool,
    pub unresolved: bool,
    pub allow_absent: bool,
    pub origin: String,
    pub prepare_id: String,
}

/// Each value in base64, because paths, branches, and the worktree listing are bytes.
#[derive(schemars::JsonSchema, Serialize, Deserialize, Debug, PartialEq)]
pub struct InspectSourceOutput {
    pub top: String,
    pub common: String,
    pub origin: String,
    pub branch: String,
    pub commit: String,
    /// `""` when content was not inspected, else `"0"` or `"1"`.
    pub dirty: String,
    /// `"<device>:<inode>"` or `"absent"`.
    pub source_identity: String,
    /// `git worktree list --porcelain -z`.
    pub worktrees: String,
}

impl Command for InspectSource {
    const NAME: &'static str = "instance.inspect-source";
    const SUMMARY: &'static str = "Read the evidence of a development source before its removal.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = InspectSourceInput;
    type Output = InspectSourceOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: InspectSourceInput, _: &Context) -> Result<InspectSourceOutput, SourceError> {
        let checkout = &input.checkout;
        let account = account(&input.user, &input.group);
        require(
            starts_inside(checkout, &input.root),
            PATH,
            "the checkout is inside the apps root",
        )?;
        require(
            no_symlink_below(Path::new(""), checkout),
            PATH,
            "no symlink in the checkout path",
        )?;
        if input.allow_absent && !exists(checkout) {
            require(
                matches!(input.layout.as_str(), "checkout" | "worktree"),
                PATH,
                "a known source layout",
            )?;
            let mut worktrees = b"worktree ".to_vec();
            worktrees.extend_from_slice(bytes(checkout));
            worktrees.extend_from_slice(b"\0\0");
            return Ok(InspectSourceOutput {
                top: encoded(bytes(checkout)),
                common: encoded(bytes(&join(checkout, "/.git"))),
                origin: encoded(input.origin.as_bytes()),
                branch: encoded(input.branch.as_bytes()),
                commit: encoded(ZERO_COMMIT.as_bytes()),
                dirty: encoded(b""),
                source_identity: encoded(b"absent"),
                worktrees: encoded(&worktrees),
            });
        }
        require(checkout.is_dir(), PATH, "the checkout is a directory")?;
        require(canonical(checkout), PATH, "the checkout path is canonical")?;
        require(owner(checkout) == account, OWNER, "the checkout owner")?;
        require(
            owner(&dirname(checkout)) == account,
            OWNER,
            "the checkout parent owner",
        )?;
        if !input.prepare_id.is_empty() {
            require(
                !is_symlink(&join(checkout, "/.git")),
                OWNER,
                "the Git directory is not a symlink",
            )?;
            let git_dir = git_output(checkout, &["rev-parse", "--absolute-git-dir"], false);
            require(git_dir.is_some(), OWNER, "read the Git directory")?;
            let marker = join(&path_from(git_dir.unwrap()), "/orbit-source-prepare");
            require(
                is_regular_file(&marker),
                OWNER,
                "the source preparation receipt",
            )?;
            require(
                owner(&marker) == account,
                OWNER,
                "the source preparation receipt owner",
            )?;
            let expected = format!("{}:{}", input.prepare_id, identity(checkout));
            require(
                read_text(&marker) == expected.as_bytes(),
                OWNER,
                "the source preparation receipt names this attempt and directory",
            )?;
        }
        let top = git_output(checkout, &["rev-parse", "--show-toplevel"], false);
        let git_dir = git_output(checkout, &["rev-parse", "--absolute-git-dir"], false);
        let common = git_output(
            checkout,
            &["rev-parse", "--path-format=absolute", "--git-common-dir"],
            false,
        );
        require(
            top.is_some() && git_dir.is_some() && common.is_some(),
            LAYOUT,
            "read the Git layout",
        )?;
        let (top, git_dir, common) = (top.unwrap(), git_dir.unwrap(), common.unwrap());
        require(
            top == bytes(checkout),
            LAYOUT,
            "the checkout is the work tree top",
        )?;
        let own_git = bytes(&join(checkout, "/.git")).to_vec();
        match input.layout.as_str() {
            "checkout" => {
                require(
                    is_real_directory(&join(checkout, "/.git")),
                    LAYOUT,
                    "the checkout has its own Git directory",
                )?;
                require(
                    git_dir == own_git,
                    LAYOUT,
                    "the Git directory is in the checkout",
                )?;
                require(
                    common == own_git,
                    LAYOUT,
                    "the checkout shares no Git directory",
                )?;
            }
            "worktree" => {
                require(
                    is_regular_file(&join(checkout, "/.git")),
                    LAYOUT,
                    "the worktree has a Git file",
                )?;
                require(
                    git_dir != common,
                    LAYOUT,
                    "the worktree has its own administration directory",
                )?;
                require(
                    is_real_directory(&path_from(common.clone())),
                    LAYOUT,
                    "the common Git directory",
                )?;
            }
            _ => return Err(SourceError::LayoutMismatch {}),
        }
        let commit = if input.unresolved {
            head_commit(checkout, true).unwrap_or_else(|| ZERO_COMMIT.as_bytes().to_vec())
        } else {
            let commit = head_commit(checkout, false);
            require(commit.is_some(), LAYOUT, "the checkout has a HEAD commit")?;
            commit.unwrap()
        };
        let origin = origin_url(checkout);
        require(origin.is_some(), ORIGIN, "the checkout has one origin")?;
        let branch = short_branch(checkout);
        if !input.unresolved {
            require(
                branch == input.branch.as_bytes(),
                BRANCH,
                "the checked-out branch",
            )?;
        }
        let dirty = if input.inspect_content {
            let state = clean(checkout);
            require(
                state.is_some(),
                failed("read the working tree status"),
                "read the working tree status",
            )?;
            if state.unwrap() {
                "0"
            } else {
                "1"
            }
        } else {
            ""
        };
        Ok(InspectSourceOutput {
            top: encoded(&top),
            common: encoded(&common),
            origin: encoded(origin.unwrap().as_bytes()),
            branch: encoded(&branch),
            commit: encoded(&commit),
            dirty: encoded(dirty.as_bytes()),
            source_identity: encoded(identity(checkout).as_bytes()),
            worktrees: encoded(&worktree_inventory(checkout)),
        })
    }
}

// ---------------------------------------------------------------------------------------------------------------

fn safe_name(value: &str) -> bool {
    !value.is_empty()
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"-_".contains(&byte))
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReceiptInput {
    pub checkout: PathBuf,
    pub root: PathBuf,
    pub common_repository: PathBuf,
    pub layout: String,
    pub operation: String,
    pub member: String,
    pub digest: String,
    pub receipt: String,
    pub user: String,
    pub group: String,
    pub source_identity: String,
    #[serde(default)]
    pub grouping_directory: Option<PathBuf>,
}

#[derive(schemars::JsonSchema, Serialize, Deserialize, Debug, PartialEq)]
pub struct StateOutput {
    pub state: String,
}

struct Receipt<'a> {
    input: &'a ReceiptInput,
    paths: RemovalPaths,
    account: String,
    quarantine_present: bool,
}

/// Authenticate the immutable native removal state before observing it.
fn authenticate_receipt(input: &ReceiptInput) -> Result<Receipt<'_>, SourceError> {
    require(
        safe_name(&input.operation) && safe_name(&input.member),
        REFUSED,
        "the removal and member IDs",
    )?;
    let paths = removal_paths(&input.root, &input.operation, &input.member);
    let account = account(&input.user, &input.group);
    require_state(&paths, &input.digest, &account, REFUSED)?;
    require(
        is_regular_file(&paths.receipt),
        REFUSED,
        "the completion receipt",
    )?;
    require(
        owner(&paths.receipt) == account,
        REFUSED,
        "the completion receipt owner",
    )?;
    require(
        holds(&paths.receipt, input.receipt.as_bytes()),
        REFUSED,
        "the completion receipt matches",
    )?;
    require(
        !exists(&input.checkout),
        REFUSED,
        "the source left its path",
    )?;
    let quarantine_present = exists(&paths.quarantine);
    if quarantine_present {
        require(
            is_real_directory(&paths.quarantine) && canonical(&paths.quarantine),
            REFUSED,
            "the quarantine directory",
        )?;
        require(
            identity(&paths.quarantine) == input.source_identity,
            REFUSED,
            "the quarantine directory identity",
        )?;
        require(
            owner(&paths.quarantine) == account,
            REFUSED,
            "the quarantine owner",
        )?;
    }
    Ok(Receipt {
        input,
        paths,
        account,
        quarantine_present,
    })
}

struct Worktree {
    recovery: Recovery,
    common_git: PathBuf,
    worktrees: PathBuf,
}

fn worktree_recovery(receipt: &Receipt) -> Result<Worktree, SourceError> {
    require(
        is_regular_file(&receipt.paths.recovery),
        REFUSED,
        "the recovery record",
    )?;
    require(
        owner(&receipt.paths.recovery) == receipt.account,
        REFUSED,
        "the recovery record owner",
    )?;
    let recovery = recovery_record(
        &receipt.paths.recovery,
        &receipt.input.common_repository,
        REFUSED,
    )?;
    let common_git = join(&receipt.input.common_repository, "/.git");
    let worktrees = join(&common_git, "/worktrees");
    Ok(Worktree {
        recovery,
        common_git,
        worktrees,
    })
}

/// Checks the administration directory and the quarantine's Git file. Returns whether the administration is complete.
fn check_admin(
    receipt: &Receipt,
    worktree: &Worktree,
    matching: usize,
) -> Result<bool, SourceError> {
    let admin = &worktree.recovery.admin;
    let mut complete = false;
    if exists(admin) {
        require(
            is_real_directory(admin),
            REFUSED,
            "the administration directory",
        )?;
        require(
            identity(admin) == worktree.recovery.admin_identity,
            REFUSED,
            "the administration directory identity",
        )?;
        require(
            owner(admin) == receipt.account,
            REFUSED,
            "the administration directory owner",
        )?;
        let gitdir = join(admin, "/gitdir");
        if exists(&gitdir) {
            require(
                is_regular_file(&gitdir),
                REFUSED,
                "the administration gitdir file",
            )?;
            require(
                holds(&gitdir, bytes(&join(&receipt.paths.quarantine, "/.git"))),
                REFUSED,
                "the administration points at the quarantine",
            )?;
            require(
                matching == 1,
                REFUSED,
                "one worktree entry for the quarantine",
            )?;
            complete = true;
        } else {
            require(
                matching == 0,
                REFUSED,
                "no other worktree entry for the source",
            )?;
        }
    } else {
        require(
            matching == 0,
            REFUSED,
            "no other worktree entry for the source",
        )?;
    }
    Ok(complete)
}

fn check_quarantine_git_file(receipt: &Receipt, admin: &Path) -> Result<bool, SourceError> {
    let git_file = join(&receipt.paths.quarantine, "/.git");
    if !exists(&git_file) {
        return Ok(false);
    }
    require(
        is_regular_file(&git_file),
        REFUSED,
        "the quarantine Git file",
    )?;
    let mut expected = b"gitdir: ".to_vec();
    expected.extend_from_slice(bytes(admin));
    require(
        holds(&git_file, &expected),
        REFUSED,
        "the quarantine Git file points at the administration",
    )?;
    Ok(true)
}

/// Reports how much of a receipt-backed removal is left. It changes nothing.
pub struct ReadReceipt;

impl Command for ReadReceipt {
    const NAME: &'static str = "instance.read-removal-receipt";
    const SUMMARY: &'static str = "Report how much of a receipt-backed source removal is left.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ReceiptInput;
    type Output = StateOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: ReceiptInput, _: &Context) -> Result<StateOutput, SourceError> {
        let receipt = authenticate_receipt(&input)?;
        let state = |state: &str| {
            Ok(StateOutput {
                state: state.into(),
            })
        };
        if input.layout == "checkout" {
            require(
                receipt.quarantine_present,
                REFUSED,
                "a checkout keeps its quarantine until cleanup",
            )?;
            require(
                !exists(&receipt.paths.recovery),
                REFUSED,
                "a checkout has no recovery record",
            )?;
            return state("incomplete");
        }
        require(input.layout == "worktree", REFUSED, "a known source layout")?;
        let worktree = worktree_recovery(&receipt)?;
        let common_present = exists(&worktree.common_git);
        if common_present {
            require(
                is_real_directory(&worktree.common_git),
                REFUSED,
                "the common Git directory",
            )?;
            require(
                identity(&worktree.common_git) == worktree.recovery.common_identity,
                REFUSED,
                "the common Git directory identity",
            )?;
            require(
                owner(&worktree.common_git) == receipt.account,
                REFUSED,
                "the common Git directory owner",
            )?;
        } else {
            require(
                !exists(&input.common_repository),
                REFUSED,
                "the common repository is gone with its Git directory",
            )?;
        }
        let worktrees_present = exists(&worktree.worktrees);
        if worktrees_present {
            require(
                common_present,
                REFUSED,
                "the worktree directory is inside the common Git directory",
            )?;
            require(
                is_real_directory(&worktree.worktrees),
                REFUSED,
                "the worktree directory",
            )?;
            require(
                identity(&worktree.worktrees) == worktree.recovery.worktrees_identity,
                REFUSED,
                "the worktree directory identity",
            )?;
            require(
                owner(&worktree.worktrees) == receipt.account,
                REFUSED,
                "the worktree directory owner",
            )?;
        }
        let matching = if worktrees_present {
            worktree_entries(
                &worktree.worktrees,
                &input.checkout,
                &receipt.paths.quarantine,
                &worktree.recovery.admin,
                REFUSED,
            )?
        } else {
            0
        };
        let admin_present = exists(&worktree.recovery.admin);
        let admin_complete = check_admin(&receipt, &worktree, matching)?;
        let git_file_present = check_quarantine_git_file(&receipt, &worktree.recovery.admin)?;
        if !receipt.quarantine_present {
            return state(if admin_present {
                "incomplete"
            } else {
                "complete"
            });
        }
        state(if admin_complete && git_file_present {
            "intact"
        } else {
            "incomplete"
        })
    }
}

/// Checks whether a current branch or tag of the origin contains the commit, through a temporary repository.
pub struct CheckPublication;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CheckPublicationInput {
    pub origin: String,
    pub commit: String,
    pub git_read: GitRead,
}

#[derive(schemars::JsonSchema, Serialize, Deserialize, Debug, PartialEq)]
pub struct PublicationOutput {
    pub published: bool,
}

impl Command for CheckPublication {
    const NAME: &'static str = "instance.check-publication";
    const SUMMARY: &'static str = "Check whether the origin publishes a commit.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = CheckPublicationInput;
    type Output = PublicationOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: CheckPublicationInput, _: &Context) -> Result<PublicationOutput, SourceError> {
        let published = is_published(&input.origin, &input.commit, &input.git_read)
            .map_err(|step| SourceError::Failed { step: step.into() })?;
        Ok(PublicationOutput { published })
    }
}

// ---------------------------------------------------------------------------------------------------------------

/// Reads whether a production release's Laravel routes claim the `app` or `apps` path that Reverb needs. It runs
/// `php artisan route:list` as the production user and changes nothing.
pub struct InspectLaravelRoutes;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectLaravelRoutesInput {
    /// The production user that owns the release.
    pub user: String,
    /// The persistent production home with `current` and `releases`.
    pub home: PathBuf,
    /// The application directory inside the selected release.
    pub application: PathBuf,
    /// The selected PHP version, such as `8.5`. The command runs `php<version>`.
    pub php_version: String,
}

#[derive(schemars::JsonSchema, Serialize, Deserialize, Debug, PartialEq)]
pub struct LaravelRoutesOutput {
    pub collision: bool,
}

command_errors! {
    pub enum LaravelRoutesError {
        PathMismatch {} => "instance.path_mismatch", "The application is not inside the selected release.";
        RoutesUnreadable {} => "instance.laravel_routes_unreadable", "Laravel did not list its routes.";
    }
}

/// The pattern of a route's first segment: each parameter matches any text without a slash, and an optional
/// parameter may also drop the Symfony separator before it.
pub fn first_segment_pattern(segment: &str) -> String {
    let parameter = regex::Regex::new(r"\{[^{}]+\}").expect("a valid pattern");
    let mut pattern = String::new();
    let mut position = 0;
    for found in parameter.find_iter(segment) {
        let literal = &segment[position..found.start()];
        let separator = literal
            .chars()
            .last()
            .filter(|last| ",;.:-_~+*=@|".contains(*last));
        match separator {
            Some(last) if found.as_str().ends_with("?}") => {
                pattern += &regex::escape(&literal[..literal.len() - last.len_utf8()]);
                pattern += &format!("(?:{}[^/]*)?", regex::escape(&last.to_string()));
            }
            _ => {
                pattern += &regex::escape(literal);
                pattern += "[^/]*";
            }
        }
        position = found.end();
    }
    pattern + &regex::escape(&segment[position..])
}

/// Whether any listed route's first segment can match `app` or `apps`. The root fallback runs after explicit
/// routes, and parameter constraints are unavailable, so the match is conservative.
pub fn routes_collide(uris: &[String]) -> bool {
    uris.iter()
        .map(|uri| uri.trim_matches('/'))
        .filter(|uri| *uri != "{fallbackPlaceholder}")
        .filter_map(|uri| {
            regex::Regex::new(&format!(
                "^(?:{})$",
                first_segment_pattern(&uri.split('/').next().unwrap_or("").to_lowercase())
            ))
            .ok()
        })
        .any(|pattern| {
            ["app", "apps"]
                .iter()
                .any(|prefix| pattern.is_match(prefix))
        })
}

impl Command for InspectLaravelRoutes {
    const NAME: &'static str = "instance.inspect-laravel-routes";
    const SUMMARY: &'static str =
        "Read whether a production release's Laravel routes claim the Reverb paths.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InspectLaravelRoutesInput;
    type Output = LaravelRoutesOutput;
    type Error = LaravelRoutesError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Owner)
    }

    fn run(
        input: InspectLaravelRoutesInput,
        context: &Context,
    ) -> Result<LaravelRoutesOutput, LaravelRoutesError> {
        let path = || LaravelRoutesError::PathMismatch {};
        let current = input.home.join("current");
        require(
            canonical(&input.home) && is_symlink(&current),
            path,
            "the production current link",
        )?;
        require(
            owner(&input.home).starts_with(&format!("{}:", input.user)),
            path,
            "the production user owns the home",
        )?;
        let release = fs::canonicalize(&current).map_err(|_| path())?;
        let application = fs::canonicalize(&input.application).map_err(|_| path())?;
        require(
            release.parent() == Some(input.home.join("releases").as_path())
                && application.starts_with(&release),
            path,
            "the application is inside the selected release",
        )?;
        let valid_version = input
            .php_version
            .split_once('.')
            .is_some_and(|(major, minor)| {
                !major.is_empty()
                    && !minor.is_empty()
                    && major
                        .bytes()
                        .chain(minor.bytes())
                        .all(|byte| byte.is_ascii_digit())
            });
        require(
            valid_version,
            || LaravelRoutesError::RoutesUnreadable {},
            "a PHP version",
        )?;
        let mut command = std::process::Command::new(format!("php{}", input.php_version));
        command
            .args(["artisan", "route:list", "--json", "--no-interaction"])
            .current_dir(&application)
            .stdin(std::process::Stdio::null())
            .stderr(std::process::Stdio::null());
        crate::command::identity::drop_to(&mut command, &input.user)
            .map_err(|_| LaravelRoutesError::RoutesUnreadable {})?;
        let wait = context.deadline().min(Instant::now() + ROUTE_LIST_LIMIT);
        let stdout = output_before(command, wait).ok_or(LaravelRoutesError::RoutesUnreadable {})?;
        let routes: Vec<serde_json::Value> =
            serde_json::from_slice(&stdout).map_err(|_| LaravelRoutesError::RoutesUnreadable {})?;
        let uris: Option<Vec<String>> = routes
            .iter()
            .map(|route| route.get("uri")?.as_str().map(str::to_string))
            .collect();
        let uris = uris.ok_or(LaravelRoutesError::RoutesUnreadable {})?;
        Ok(LaravelRoutesOutput {
            collision: routes_collide(&uris),
        })
    }
}

/// How long Laravel may take to list its routes; a release that hangs at boot fails the check.
const ROUTE_LIST_LIMIT: Duration = Duration::from_secs(45);

/// The standard output of a command that exits successfully before `deadline`, or `None`. A command still running
/// at the deadline is killed, so a read leaves no process behind.
fn output_before(mut command: std::process::Command, deadline: Instant) -> Option<Vec<u8>> {
    use std::io::Read;
    let mut child = command.stdout(std::process::Stdio::piped()).spawn().ok()?;
    let mut stdout = child.stdout.take()?;
    let reader = std::thread::spawn(move || {
        let mut bytes = Vec::new();
        stdout.read_to_end(&mut bytes).map(|_| bytes)
    });
    loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                return status.success().then(|| reader.join().ok()?.ok()).flatten()
            }
            Ok(None) if Instant::now() < deadline => std::thread::sleep(Duration::from_millis(50)),
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                return None;
            }
        }
    }
}

#[cfg(test)]
#[path = "instances_tests.rs"]
mod tests;

// ---------------------------------------------------------------------------------------------------------------

/// Whether nothing, not even a dangling link, occupies a path where an Instance's source will go.
pub struct CheckPathFree;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PathInput {
    pub path: PathBuf,
}

#[derive(schemars::JsonSchema, Serialize, Debug, PartialEq)]
pub struct PathFree {
    pub free: bool,
}

impl Command for CheckPathFree {
    const NAME: &'static str = "instance.check-path-free";
    const SUMMARY: &'static str = "Check that nothing occupies the path of a new Instance source.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = PathInput;
    type Output = PathFree;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: PathInput, _: &Context) -> Result<PathFree, SourceError> {
        Ok(PathFree {
            free: !exists(&input.path),
        })
    }
}
