//! `instances.*`: development source inspection and removal, and the read of a production release's Laravel routes.
//! Every refusal leaves the source in place and names the check that refused in its log line.

use super::source::*;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

command_errors! {
    pub enum SourceError {
        PathMismatch {} => "instances.path_mismatch", "The source path is not the recorded one.";
        OwnershipMismatch {} => "instances.ownership_mismatch", "The source is not owned by the managed account.";
        LayoutMismatch {} => "instances.layout_mismatch", "The source does not have the recorded Git layout.";
        OriginMismatch {} => "instances.origin_mismatch", "The source origin is not the Project repository.";
        BranchMismatch {} => "instances.branch_mismatch", "The source is not on the recorded branch.";
        WorktreesMismatch {} => "instances.worktrees_mismatch", "Another linked worktree uses the source.";
        UnsafeContent {} => "instances.unsafe_content", "The source has dirty or unpublished content.";
        SourceChanged {} => "instances.source_changed", "The source changed after its inspection.";
        Refused {} => "instances.removal_refused", "The removal evidence does not match the recorded removal.";
        Failed { step: String } => "instances.source_step_failed", "A source step failed.";
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

fn account_only(platform: Platform) -> Option<Privilege> {
    (platform == Platform::Linux).then_some(Privilege::Account)
}

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

#[derive(Deserialize)]
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
#[derive(Serialize, Deserialize, Debug, PartialEq)]
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
    const NAME: &'static str = "instances.inspect-source";
    const SUMMARY: &'static str = "Read the evidence of a development source before its removal.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = InspectSourceInput;
    type Output = InspectSourceOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
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

/// Checks an active checkout again at the destructive boundary and deletes it in the same run.
pub struct RemoveSource;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RemoveSourceInput {
    pub checkout: PathBuf,
    pub root: PathBuf,
    pub grouping_directory: PathBuf,
    pub user: String,
    pub group: String,
    pub branch: String,
    pub commit: String,
    pub source_identity: String,
    pub repository_identity: String,
    pub force: bool,
    pub own_units: Vec<String>,
    pub git_read: BTreeMap<String, String>,
}

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct Empty {}

/// Counts the live entries of a `git worktree list --porcelain -z` listing. Each must be the checkout itself. A
/// prunable entry's directory is gone, so it does not keep the checkout in use.
pub fn linked_worktrees(checkout: &Path, inventory: &[u8]) -> Result<usize, SourceError> {
    let mut count = 0;
    let mut path: Option<&[u8]> = None;
    let mut prunable = false;
    let mut flush = |path: &mut Option<&[u8]>, prunable: &mut bool| -> Result<(), SourceError> {
        if let Some(current) = path.take() {
            if !*prunable {
                count += 1;
                require(
                    current == bytes(checkout),
                    || SourceError::WorktreesMismatch {},
                    "no other linked worktree",
                )?;
            }
        }
        *prunable = false;
        Ok(())
    };
    let mut fields: Vec<&[u8]> = inventory.split(|byte| *byte == 0).collect();
    fields.pop();
    for field in fields {
        if let Some(worktree) = field.strip_prefix(b"worktree ") {
            flush(&mut path, &mut prunable)?;
            path = Some(worktree);
        } else if field == b"prunable" || field.starts_with(b"prunable ") {
            prunable = true;
        } else if field.is_empty() {
            flush(&mut path, &mut prunable)?;
        }
    }
    flush(&mut path, &mut prunable)?;
    Ok(count)
}

impl Command for RemoveSource {
    const NAME: &'static str = "instances.remove-source";
    const SUMMARY: &'static str = "Check an active development checkout again and delete it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = RemoveSourceInput;
    type Output = Empty;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
    }

    fn target(input: &RemoveSourceInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.display()))
    }

    fn run(input: RemoveSourceInput, _: &Context) -> Result<Empty, SourceError> {
        let checkout = &input.checkout;
        let grouping = &input.grouping_directory;
        let account = account(&input.user, &input.group);
        require(
            *grouping == dirname(checkout),
            PATH,
            "the grouping directory holds the checkout",
        )?;
        require(
            starts_inside(checkout, &input.root),
            PATH,
            "the checkout is inside the apps root",
        )?;
        require(
            no_symlink_below(&input.root, checkout),
            PATH,
            "no symlink below the apps root",
        )?;
        require(
            checkout.is_dir() && canonical(checkout),
            PATH,
            "the checkout is a canonical directory",
        )?;
        require(
            is_real_directory(grouping) && canonical(grouping),
            PATH,
            "the grouping directory is a canonical directory",
        )?;
        require(
            identity(checkout) == input.source_identity,
            || SourceError::SourceChanged {},
            "the checkout directory identity",
        )?;
        require(owner(checkout) == account, OWNER, "the checkout owner")?;
        require(
            owner(grouping) == account,
            OWNER,
            "the grouping directory owner",
        )?;
        let own_git = join(checkout, "/.git");
        require(
            is_real_directory(&own_git),
            LAYOUT,
            "the checkout has its own Git directory",
        )?;
        require(
            git_output(checkout, &["rev-parse", "--show-toplevel"], false).as_deref()
                == Some(bytes(checkout)),
            LAYOUT,
            "the work tree top",
        )?;
        require(
            git_output(checkout, &["rev-parse", "--absolute-git-dir"], false).as_deref()
                == Some(bytes(&own_git)),
            LAYOUT,
            "the Git directory is in the checkout",
        )?;
        require(
            git_output(
                checkout,
                &["rev-parse", "--path-format=absolute", "--git-common-dir"],
                false,
            )
            .as_deref()
                == Some(bytes(&own_git)),
            LAYOUT,
            "the checkout shares no Git directory",
        )?;
        require(
            short_branch(checkout) == input.branch.as_bytes(),
            BRANCH,
            "the checked-out branch",
        )?;
        require(
            head_commit(checkout, false).unwrap_or_default() == input.commit.as_bytes(),
            || SourceError::SourceChanged {},
            "the HEAD commit",
        )?;
        let origin = origin_url(checkout);
        require(origin.is_some(), ORIGIN, "the checkout has one origin")?;
        let origin = origin.unwrap();
        require(
            repository_identity(&origin).as_deref() == Some(input.repository_identity.as_str()),
            ORIGIN,
            "the origin is the Project repository",
        )?;
        require(
            linked_worktrees(checkout, &worktree_inventory(checkout))? == 1,
            || SourceError::WorktreesMismatch {},
            "the checkout is its only worktree",
        )?;
        if !input.force {
            let state = clean(checkout);
            require(
                state.is_some(),
                failed("read the working tree status"),
                "read the working tree status",
            )?;
            require(
                state.unwrap(),
                || SourceError::UnsafeContent {},
                "the source is clean",
            )?;
            let published = is_published(&origin, &input.commit, &input.git_read)
                .map_err(|step| SourceError::Failed { step: step.into() })?;
            require(
                published,
                || SourceError::UnsafeContent {},
                "the HEAD is published",
            )?;
        }
        require(
            stop_tree_processes(checkout, &input.own_units),
            failed("stop the processes inside the checkout"),
            "stop the processes",
        )?;
        require(
            remove_source_tree(checkout, None),
            failed("delete the checkout"),
            "delete the checkout",
        )?;
        release_empty_grouping_directory(
            checkout,
            &input.root,
            grouping,
            &account,
            failed("remove the empty grouping directory"),
        )?;
        Ok(Empty {})
    }
}

// ---------------------------------------------------------------------------------------------------------------

/// Records the removal journal in `<root>/.orbit-removals` before finalization. A retry with the same digest succeeds.
pub struct PrepareRemoval;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrepareRemovalInput {
    pub root: PathBuf,
    pub operation: String,
    pub member: String,
    pub digest: String,
    pub user: String,
    pub group: String,
    pub checkout: PathBuf,
    pub common_repository: PathBuf,
    pub layout: String,
    pub source_identity: String,
}

fn safe_name(value: &str) -> bool {
    !value.is_empty()
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"-_".contains(&byte))
}

impl Command for PrepareRemoval {
    const NAME: &'static str = "instances.prepare-source-removal";
    const SUMMARY: &'static str = "Record the removal journal of a development source.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = PrepareRemovalInput;
    type Output = Empty;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
    }

    fn target(input: &PrepareRemovalInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.display()))
    }

    fn run(input: PrepareRemovalInput, _: &Context) -> Result<Empty, SourceError> {
        let root = &input.root;
        let account = account(&input.user, &input.group);
        require(
            safe_name(&input.operation) && safe_name(&input.member),
            REFUSED,
            "the removal and member IDs",
        )?;
        let paths = removal_paths(root, &input.operation, &input.member);
        require(
            is_real_directory(root) && canonical(root),
            REFUSED,
            "the apps root is a canonical directory",
        )?;
        require(owner(root) == account, REFUSED, "the apps root owner")?;
        if exists(&paths.state) {
            require(
                is_real_directory(&paths.state) && canonical(&paths.state),
                REFUSED,
                "the removal state directory",
            )?;
        } else {
            use std::os::unix::fs::PermissionsExt;
            fs::create_dir(&paths.state).map_err(|_| SourceError::Refused {})?;
            fs::set_permissions(&paths.state, fs::Permissions::from_mode(0o700))
                .map_err(|_| SourceError::Refused {})?;
        }
        require(
            owner(&paths.state) == account,
            REFUSED,
            "the removal state directory owner",
        )?;
        for path in [&paths.receipt, &paths.quarantine, &paths.recovery] {
            require(
                !exists(path),
                REFUSED,
                "no receipt, quarantine, or recovery record yet",
            )?;
        }
        let checkout = &input.checkout;
        if input.source_identity == "absent" {
            require(
                !exists(checkout),
                REFUSED,
                "the absent source is still absent",
            )?;
        } else if input.layout == "worktree" {
            require(
                is_regular_file(&join(checkout, "/.git")),
                REFUSED,
                "the worktree has a Git file",
            )?;
            let git_dir = git_output(checkout, &["rev-parse", "--absolute-git-dir"], false);
            require(
                git_dir.is_some(),
                REFUSED,
                "read the worktree administration directory",
            )?;
            let git_dir = path_from(git_dir.unwrap());
            require(
                dirname(&git_dir) == join(&input.common_repository, "/.git/worktrees"),
                REFUSED,
                "the worktree belongs to the recorded repository",
            )?;
            require(
                is_real_directory(&git_dir),
                REFUSED,
                "the worktree administration directory",
            )?;
        } else {
            require(input.layout == "checkout", REFUSED, "a known source layout")?;
        }
        if exists(&paths.journal) {
            require(
                is_regular_file(&paths.journal),
                REFUSED,
                "the removal journal",
            )?;
            require(
                holds(&paths.journal, input.digest.as_bytes()),
                REFUSED,
                "the removal journal digest",
            )?;
            return Ok(Empty {});
        }
        write_atomically(
            &paths.state,
            &format!(".{}.{}.journal.", input.operation, input.member),
            &paths.journal,
            input.digest.as_bytes(),
        )
        .map_err(|_| SourceError::Refused {})?;
        Ok(Empty {})
    }
}

// ---------------------------------------------------------------------------------------------------------------

/// Moves a recorded source into quarantine, writes the completion receipt, and deletes the quarantine. A retry
/// resumes from the quarantine or the receipt.
pub struct FinalizeRemoval;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FinalizeRemovalInput {
    pub checkout: PathBuf,
    pub root: PathBuf,
    pub common_repository: PathBuf,
    pub layout: String,
    pub branch: String,
    pub operation: String,
    pub member: String,
    pub digest: String,
    pub receipt: String,
    pub force: bool,
    pub user: String,
    pub group: String,
    pub source_identity: String,
    pub origin: String,
    /// Base64 of the accepted `git worktree list --porcelain -z`.
    pub worktrees: String,
    pub commit: String,
    pub grouping_directory: PathBuf,
    pub unresolved: bool,
    pub independent_worktree: bool,
    pub own_units: Vec<String>,
    pub git_read: BTreeMap<String, String>,
}

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct FinalizeRemovalOutput {
    /// `finalized`, or `cross_device` when the checkout is on another filesystem than the removal state, so it stays.
    pub state: String,
    pub receipt: Option<String>,
}

impl Command for FinalizeRemoval {
    const NAME: &'static str = "instances.finalize-source-removal";
    const SUMMARY: &'static str =
        "Quarantine a recorded development source, write its receipt, and delete it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = FinalizeRemovalInput;
    type Output = FinalizeRemovalOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
    }

    fn target(input: &FinalizeRemovalInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.display()))
    }

    fn run(input: FinalizeRemovalInput, _: &Context) -> Result<FinalizeRemovalOutput, SourceError> {
        let checkout = &input.checkout;
        let root = &input.root;
        let common_repository = &input.common_repository;
        let layout = input.layout.as_str();
        let receipt = input.receipt.as_bytes();
        let account = account(&input.user, &input.group);
        require(
            safe_name(&input.operation) && safe_name(&input.member),
            REFUSED,
            "the removal and member IDs",
        )?;
        let paths = removal_paths(root, &input.operation, &input.member);
        let finalized = || {
            Ok(FinalizeRemovalOutput {
                state: "finalized".into(),
                receipt: Some(input.receipt.clone()),
            })
        };
        require_state(&paths, &input.digest, &account, REFUSED)?;
        let write_receipt = |paths: &RemovalPaths| {
            write_atomically(
                &paths.state,
                &format!(".{}.{}.receipt.", input.operation, input.member),
                &paths.receipt,
                receipt,
            )
            .map_err(|_| SourceError::Refused {})
        };
        if input.source_identity == "absent" {
            require(
                !exists(checkout),
                REFUSED,
                "the absent source is still absent",
            )?;
            require(
                !exists(&paths.quarantine),
                REFUSED,
                "an absent source has no quarantine",
            )?;
            release_empty_grouping_directory(
                checkout,
                root,
                &input.grouping_directory,
                &account,
                REFUSED,
            )?;
            if !exists(&paths.receipt) {
                write_receipt(&paths)?;
            }
            require(
                is_regular_file(&paths.receipt),
                REFUSED,
                "the completion receipt",
            )?;
            require(
                holds(&paths.receipt, receipt),
                REFUSED,
                "the completion receipt matches",
            )?;
            return finalized();
        }
        if !exists(checkout) && !exists(&paths.quarantine) {
            require(
                is_regular_file(&paths.receipt),
                REFUSED,
                "the completion receipt",
            )?;
            require(
                holds(&paths.receipt, receipt),
                REFUSED,
                "the completion receipt matches",
            )?;
            release_empty_grouping_directory(
                checkout,
                root,
                &input.grouping_directory,
                &account,
                REFUSED,
            )?;
            return finalized();
        }
        let mut physical = checkout.clone();
        if exists(checkout) {
            require(
                !exists(&paths.quarantine),
                REFUSED,
                "no quarantine beside the source",
            )?;
            require(
                !exists(&paths.receipt),
                REFUSED,
                "no completion receipt beside the source",
            )?;
        } else {
            physical = paths.quarantine.clone();
            require(
                is_real_directory(&paths.quarantine),
                REFUSED,
                "the quarantine is a directory",
            )?;
            if exists(&paths.receipt) {
                require(
                    is_regular_file(&paths.receipt),
                    REFUSED,
                    "the completion receipt",
                )?;
                require(
                    holds(&paths.receipt, receipt),
                    REFUSED,
                    "the completion receipt matches",
                )?;
            }
        }
        require(
            starts_inside(&physical, root),
            REFUSED,
            "the source is inside the apps root",
        )?;
        require(
            no_symlink_below(root, &physical),
            REFUSED,
            "no symlink below the apps root",
        )?;
        require(
            is_real_directory(&physical) && canonical(&physical),
            REFUSED,
            "the source is a canonical directory",
        )?;
        require(
            identity(&physical) == input.source_identity,
            REFUSED,
            "the source directory identity",
        )?;
        require(owner(&physical) == account, REFUSED, "the source owner")?;
        require(
            owner(&dirname(&physical)) == account,
            REFUSED,
            "the source parent owner",
        )?;
        require(
            git_output(&physical, &["rev-parse", "--show-toplevel"], false).as_deref()
                == Some(bytes(&physical)),
            REFUSED,
            "the work tree top",
        )?;
        let git_dir = git_output(&physical, &["rev-parse", "--absolute-git-dir"], false);
        let common = git_output(
            &physical,
            &["rev-parse", "--path-format=absolute", "--git-common-dir"],
            false,
        );
        require(
            git_dir.is_some() && common.is_some(),
            REFUSED,
            "read the Git layout",
        )?;
        let (git_dir, common) = (git_dir.unwrap(), common.unwrap());
        let own_git = bytes(&join(&physical, "/.git")).to_vec();
        match layout {
            "checkout" => {
                require(
                    is_real_directory(&join(&physical, "/.git")),
                    REFUSED,
                    "the checkout has its own Git directory",
                )?;
                require(
                    git_dir == own_git && common == own_git,
                    REFUSED,
                    "the checkout shares no Git directory",
                )?;
            }
            "worktree" => {
                require(
                    is_regular_file(&join(&physical, "/.git")),
                    REFUSED,
                    "the worktree has a Git file",
                )?;
                require(
                    git_dir != common,
                    REFUSED,
                    "the worktree has its own administration directory",
                )?;
                require(
                    dirname(&path_from(common.clone())) == *common_repository,
                    REFUSED,
                    "the worktree belongs to the recorded repository",
                )?;
                require(
                    is_real_directory(&join(common_repository, "/.git")),
                    REFUSED,
                    "the common Git directory",
                )?;
            }
            _ => return Err(SourceError::Refused {}),
        }
        if !input.unresolved {
            require(
                short_branch(&physical) == input.branch.as_bytes(),
                REFUSED,
                "the checked-out branch",
            )?;
        }
        let commit =
            head_commit(&physical, true).unwrap_or_else(|| ZERO_COMMIT.as_bytes().to_vec());
        require(
            commit == input.commit.as_bytes(),
            REFUSED,
            "the HEAD commit",
        )?;
        let origin = origin_url(&physical);
        require(
            origin.as_deref() == Some(input.origin.as_str()),
            REFUSED,
            "the recorded origin",
        )?;
        if layout != "worktree" || !input.force || !input.independent_worktree {
            require(
                encoded(&worktree_inventory(&physical)) == input.worktrees,
                REFUSED,
                "the accepted linked-worktree inventory",
            )?;
        }
        if !input.force {
            require(
                clean(&physical) == Some(true),
                REFUSED,
                "the source is clean",
            )?;
            let published = is_published(&input.origin, &input.commit, &input.git_read)
                .map_err(|_| SourceError::Refused {})?;
            require(published, REFUSED, "the HEAD is published")?;
        }
        // A rename keeps the checkout's device and inode only within one filesystem. Across a mount, such as a task VM
        // workspace volume, a move copies and deletes, and the identity checks would fail after the copy.
        if physical == *checkout {
            use std::os::unix::fs::MetadataExt;
            let device = |path: &Path| {
                fs::symlink_metadata(path)
                    .map(|metadata| metadata.dev())
                    .ok()
            };
            if device(checkout) != device(&paths.state) {
                eprintln!(
                    "cross-device: the checkout [{}] and [{}] are on different filesystems; Orbit does not move a checkout across a mount.",
                    checkout.display(),
                    paths.state.display()
                );
                return Ok(FinalizeRemovalOutput {
                    state: "cross_device".into(),
                    receipt: None,
                });
            }
        }
        require(
            stop_tree_processes(&physical, &input.own_units),
            REFUSED,
            "stop the processes inside the source",
        )?;
        if physical == *checkout {
            let moved = if layout == "worktree" {
                let git_dir = join(
                    std::path::Path::new("--git-dir="),
                    &join(common_repository, "/.git").display().to_string(),
                );
                git(
                    &[
                        git_dir.as_os_str(),
                        std::ffi::OsStr::new("worktree"),
                        std::ffi::OsStr::new("move"),
                        checkout.as_os_str(),
                        paths.quarantine.as_os_str(),
                    ],
                    &BTreeMap::new(),
                    false,
                )
                .status
                .success()
            } else {
                fs::rename(checkout, &paths.quarantine).is_ok()
            };
            require(moved, REFUSED, "move the source into quarantine")?;
            physical = paths.quarantine.clone();
        }
        require(
            is_real_directory(&physical),
            REFUSED,
            "the quarantine is a directory",
        )?;
        require(
            identity(&physical) == input.source_identity,
            REFUSED,
            "the quarantine directory identity",
        )?;
        if layout == "checkout" {
            require(
                !exists(&paths.recovery),
                REFUSED,
                "a checkout has no recovery record",
            )?;
        } else {
            let git_dir = git_output(&physical, &["rev-parse", "--absolute-git-dir"], false);
            let common_git = join(common_repository, "/.git");
            let worktrees = join(&common_git, "/worktrees");
            require(
                git_dir
                    .as_ref()
                    .is_some_and(|dir| dirname(&path_from(dir.clone())) == worktrees),
                REFUSED,
                "the worktree administration directory",
            )?;
            let git_dir = path_from(git_dir.unwrap());
            for (path, check) in [
                (&common_git, "the common Git directory"),
                (&worktrees, "the worktree directory"),
                (&git_dir, "the administration directory"),
            ] {
                require(is_real_directory(path), REFUSED, check)?;
                require(owner(path) == account, REFUSED, check)?;
            }
            let record = [
                encoded(bytes(&git_dir)),
                identity(&git_dir),
                identity(&common_git),
                identity(&worktrees),
            ]
            .join("\n");
            if exists(&paths.recovery) {
                require(
                    is_regular_file(&paths.recovery),
                    REFUSED,
                    "the recovery record",
                )?;
                require(
                    owner(&paths.recovery) == account,
                    REFUSED,
                    "the recovery record owner",
                )?;
                require(
                    holds(&paths.recovery, record.as_bytes()),
                    REFUSED,
                    "the recovery record matches",
                )?;
            } else {
                write_atomically(
                    &paths.state,
                    &format!(".{}.{}.recovery.", input.operation, input.member),
                    &paths.recovery,
                    record.as_bytes(),
                )
                .map_err(|_| SourceError::Refused {})?;
            }
        }
        if !exists(&paths.receipt) {
            write_receipt(&paths)?;
        } else {
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
                holds(&paths.receipt, receipt),
                REFUSED,
                "the completion receipt matches",
            )?;
        }
        let common = (layout == "worktree").then_some(common_repository.as_path());
        require(
            remove_source_tree(&paths.quarantine, common),
            REFUSED,
            "delete the quarantine",
        )?;
        require(
            !exists(&paths.quarantine),
            REFUSED,
            "the quarantine is gone",
        )?;
        release_empty_grouping_directory(
            checkout,
            root,
            &input.grouping_directory,
            &account,
            REFUSED,
        )?;
        finalized()
    }
}

// ---------------------------------------------------------------------------------------------------------------

#[derive(Deserialize)]
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

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct StateOutput {
    pub state: String,
}

struct Receipt<'a> {
    input: &'a ReceiptInput,
    paths: RemovalPaths,
    account: String,
    quarantine_present: bool,
}

/// The checks that reading and cleaning a receipt-backed removal share.
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
    const NAME: &'static str = "instances.read-removal-receipt";
    const SUMMARY: &'static str = "Report how much of a receipt-backed source removal is left.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ReceiptInput;
    type Output = StateOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
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

/// Finishes a receipt-backed removal: it deletes what is left of the quarantine and, for a worktree, only its
/// recorded Git administration entry. The receipt authorizes cleanup even when the quarantine is no longer a valid
/// repository.
pub struct CleanReceipt;

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct ReceiptOutput {
    pub receipt: String,
}

impl Command for CleanReceipt {
    const NAME: &'static str = "instances.clean-removal-receipt";
    const SUMMARY: &'static str = "Delete what is left of a receipt-backed source removal.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = ReceiptInput;
    type Output = ReceiptOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
    }

    fn target(input: &ReceiptInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.display()))
    }

    fn run(input: ReceiptInput, _: &Context) -> Result<ReceiptOutput, SourceError> {
        let grouping = input.grouping_directory.clone();
        require(grouping.is_some(), REFUSED, "the grouping directory")?;
        let receipt = authenticate_receipt(&input)?;
        let quarantine = &receipt.paths.quarantine;
        match input.layout.as_str() {
            "checkout" => {
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
                require(
                    identity(quarantine) == input.source_identity,
                    REFUSED,
                    "the quarantine directory identity",
                )?;
                require(
                    owner(quarantine) == receipt.account,
                    REFUSED,
                    "the quarantine owner",
                )?;
                require(
                    remove_source_tree(quarantine, None),
                    REFUSED,
                    "delete the quarantine",
                )?;
            }
            "worktree" => {
                let worktree = worktree_recovery(&receipt)?;
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
                let matching = worktree_entries(
                    &worktree.worktrees,
                    &input.checkout,
                    quarantine,
                    &worktree.recovery.admin,
                    REFUSED,
                )?;
                let admin = &worktree.recovery.admin;
                if exists(admin) {
                    check_admin(&receipt, &worktree, matching)?;
                    check_quarantine_git_file(&receipt, admin)?;
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
                    require(
                        fs::remove_dir_all(admin).is_ok(),
                        REFUSED,
                        "delete the administration directory",
                    )?;
                } else {
                    require(
                        matching == 0,
                        REFUSED,
                        "no other worktree entry for the source",
                    )?;
                }
                if receipt.quarantine_present {
                    require(
                        identity(quarantine) == input.source_identity,
                        REFUSED,
                        "the quarantine directory identity",
                    )?;
                    require(
                        owner(quarantine) == receipt.account,
                        REFUSED,
                        "the quarantine owner",
                    )?;
                    require(
                        remove_source_tree(quarantine, None),
                        REFUSED,
                        "delete the quarantine",
                    )?;
                }
            }
            _ => return Err(SourceError::Refused {}),
        }
        require(!exists(quarantine), REFUSED, "the quarantine is gone")?;
        release_empty_grouping_directory(
            &input.checkout,
            &input.root,
            &grouping.unwrap(),
            &receipt.account,
            REFUSED,
        )?;
        Ok(ReceiptOutput {
            receipt: input.receipt.clone(),
        })
    }
}

// ---------------------------------------------------------------------------------------------------------------

/// Reports whether a recorded source is in place, in quarantine, waiting for receipt cleanup, or removed. For a
/// removed source it retries the removal of the empty parent directory, because deletion and that cleanup are not
/// atomic.
pub struct ReadRemovalState;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReadRemovalStateInput {
    pub checkout: PathBuf,
    pub root: PathBuf,
    pub operation: String,
    pub member: String,
    pub digest: String,
    pub receipt: String,
    pub source_identity: String,
    pub user: String,
    pub group: String,
    pub grouping_directory: PathBuf,
}

impl Command for ReadRemovalState {
    const NAME: &'static str = "instances.read-source-removal-state";
    const SUMMARY: &'static str =
        "Report the state of a recorded source removal and release its empty parent.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = ReadRemovalStateInput;
    type Output = StateOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
    }

    fn target(input: &ReadRemovalStateInput) -> Option<String> {
        Some(format!("source:{}", input.checkout.display()))
    }

    fn run(input: ReadRemovalStateInput, _: &Context) -> Result<StateOutput, SourceError> {
        let checkout = &input.checkout;
        let account = account(&input.user, &input.group);
        let receipt = input.receipt.as_bytes();
        require(
            safe_name(&input.operation) && safe_name(&input.member),
            REFUSED,
            "the removal and member IDs",
        )?;
        let paths = removal_paths(&input.root, &input.operation, &input.member);
        require_state(&paths, &input.digest, &account, REFUSED)?;
        let state = |state: &str| {
            Ok(StateOutput {
                state: state.into(),
            })
        };
        if input.source_identity == "absent" {
            require(
                !exists(checkout),
                REFUSED,
                "the absent source is still absent",
            )?;
            require(
                !exists(&paths.quarantine),
                REFUSED,
                "an absent source has no quarantine",
            )?;
            if exists(&paths.receipt) {
                require(
                    is_regular_file(&paths.receipt),
                    REFUSED,
                    "the completion receipt",
                )?;
                require(
                    holds(&paths.receipt, receipt),
                    REFUSED,
                    "the completion receipt matches",
                )?;
                return state("completed");
            }
            return state("present");
        }
        if exists(checkout) {
            for path in [&paths.quarantine, &paths.receipt, &paths.recovery] {
                require(
                    !exists(path),
                    REFUSED,
                    "no quarantine, receipt, or recovery record beside the source",
                )?;
            }
            require(
                is_real_directory(checkout),
                REFUSED,
                "the source is a directory",
            )?;
            require(
                identity(checkout) == input.source_identity,
                REFUSED,
                "the source directory identity",
            )?;
            return state("present");
        }
        if exists(&paths.quarantine) {
            require(
                is_real_directory(&paths.quarantine),
                REFUSED,
                "the quarantine is a directory",
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
            if exists(&paths.receipt) {
                require(
                    is_regular_file(&paths.receipt),
                    REFUSED,
                    "the completion receipt",
                )?;
                require(
                    holds(&paths.receipt, receipt),
                    REFUSED,
                    "the completion receipt matches",
                )?;
                return state("receipt_pending_cleanup");
            }
            return state("quarantined");
        }
        require(
            is_regular_file(&paths.receipt),
            REFUSED,
            "the completion receipt",
        )?;
        require(
            holds(&paths.receipt, receipt),
            REFUSED,
            "the completion receipt matches",
        )?;
        require(
            owner(&paths.receipt) == account,
            REFUSED,
            "the completion receipt owner",
        )?;
        release_empty_grouping_directory(
            checkout,
            &input.root,
            &input.grouping_directory,
            &account,
            REFUSED,
        )?;
        state("completed")
    }
}

// ---------------------------------------------------------------------------------------------------------------

/// Checks whether a current branch or tag of the origin contains the commit, through a temporary repository.
pub struct CheckPublication;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CheckPublicationInput {
    pub origin: String,
    pub commit: String,
    pub git_read: BTreeMap<String, String>,
}

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct PublicationOutput {
    pub published: bool,
}

impl Command for CheckPublication {
    const NAME: &'static str = "instances.check-publication";
    const SUMMARY: &'static str = "Check whether the origin publishes a commit.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(300);
    type Input = CheckPublicationInput;
    type Output = PublicationOutput;
    type Error = SourceError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        account_only(platform)
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

#[derive(Deserialize)]
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

#[derive(Serialize, Deserialize, Debug, PartialEq)]
pub struct LaravelRoutesOutput {
    pub collision: bool,
}

command_errors! {
    pub enum LaravelRoutesError {
        PathMismatch {} => "instances.path_mismatch", "The application is not inside the selected release.";
        RoutesUnreadable {} => "instances.laravel_routes_unreadable", "Laravel did not list its routes.";
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
    const NAME: &'static str = "instances.inspect-laravel-routes";
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
