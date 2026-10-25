//! The development source commands, run directly against temporary checkouts. Every refusal leaves the source in
//! place. The publication observation reads the local bare fixture repository. No test changes the process environment.

use super::*;
use crate::command::{CommandError, Context, Platform};
use base64::Engine;
use std::{
    os::unix::fs::PermissionsExt, process::Command as Process, sync::OnceLock, time::Instant,
};

const ORIGIN: &str = "ssh://git@example.test/acme/site.git";
const IDENTITY: &str = "example.test/acme/site";

fn sh_git(directory: &Path, arguments: &[&str]) -> String {
    let output = Process::new("git")
        .args([
            "-c",
            "user.name=Orbit",
            "-c",
            "user.email=orbit@example.test",
            "-c",
            "init.defaultBranch=main",
        ])
        .args(arguments)
        .current_dir(directory)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "git {arguments:?}: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().to_string()
}

fn me() -> (String, String) {
    let uid = rustix::process::geteuid().as_raw();
    let gid = rustix::process::getegid().as_raw();
    (user_name(uid), group_name(gid))
}

fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(60))
}

/// An origin with one published commit on `main`, shared by every test.
fn origin_fixture() -> &'static Path {
    static ORIGIN_FIXTURE: OnceLock<PathBuf> = OnceLock::new();
    ORIGIN_FIXTURE.get_or_init(|| {
        let directory =
            std::env::temp_dir().join(format!("orbit-source-origin-{}", std::process::id()));
        let _ = fs::remove_dir_all(&directory);
        fs::create_dir_all(&directory).unwrap();
        let origin = directory.join("origin.git");
        sh_git(
            &directory,
            &["init", "--quiet", "--bare", origin.to_str().unwrap()],
        );
        let seed = directory.join("seed");
        sh_git(
            &directory,
            &[
                "clone",
                "--quiet",
                origin.to_str().unwrap(),
                seed.to_str().unwrap(),
            ],
        );
        sh_git(
            &seed,
            &["commit", "--quiet", "--allow-empty", "-m", "Initial"],
        );
        sh_git(&seed, &["push", "--quiet", "origin", "HEAD:main"]);
        origin
    })
}

/// An apps root with one Project directory and its own copy of the origin.
struct Sandbox {
    base: PathBuf,
    root: PathBuf,
    project: PathBuf,
    origin: PathBuf,
}

impl Sandbox {
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-source-{:016x}", rand::random::<u64>()));
        let root = base.join("apps");
        let project = root.join("acme");
        fs::create_dir_all(&project).unwrap();
        let origin = base.join("origin.git");
        let copied = Process::new("cp")
            .args(["-a"])
            .arg(origin_fixture())
            .arg(&origin)
            .status()
            .unwrap();
        assert!(copied.success());
        Self {
            base,
            root,
            project,
            origin,
        }
    }

    fn checkout(&self, name: &str) -> PathBuf {
        let path = self.project.join(name);
        sh_git(
            &self.base,
            &[
                "clone",
                "--quiet",
                self.origin.to_str().unwrap(),
                path.to_str().unwrap(),
            ],
        );
        sh_git(&path, &["remote", "set-url", "origin", ORIGIN]);
        sh_git(&path, &["checkout", "--quiet", "-b", name]);
        path
    }

    fn head(path: &Path) -> String {
        sh_git(path, &["rev-parse", "HEAD"])
    }
}

impl Drop for Sandbox {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

fn code<T>(result: Result<T, SourceError>) -> &'static str {
    match result {
        Ok(_) => "ok",
        Err(error) => error.code(),
    }
}

fn decode(value: &str) -> Vec<u8> {
    base64::engine::general_purpose::STANDARD
        .decode(value)
        .unwrap()
}

fn publication_at(
    root: &Path,
    origin: &str,
    commit: &str,
    _: &GitRead,
) -> Result<bool, &'static str> {
    assert_eq!(origin, ORIGIN);
    is_published(
        root.parent().unwrap().join("origin.git").to_str().unwrap(),
        commit,
        &GitRead::default(),
    )
}
fn remove_source(input: RemoveSourceInput, _: &Context) -> Result<Empty, SourceError> {
    let root = input.root.clone();
    RemoveSource::run_with(input, &|origin, commit, read| {
        publication_at(&root, origin, commit, read)
    })
}
fn finalize_removal(
    input: FinalizeRemovalInput,
    _: &Context,
) -> Result<FinalizeRemovalOutput, SourceError> {
    let root = input.root.clone();
    FinalizeRemoval::run_with(input, &|origin, commit, read| {
        publication_at(&root, origin, commit, read)
    })
}

// ---- inspect ----------------------------------------------------------------------------------------------------

fn inspect_input(sandbox: &Sandbox, checkout: &Path) -> InspectSourceInput {
    let (user, group) = me();
    InspectSourceInput {
        checkout: checkout.into(),
        root: sandbox.root.clone(),
        user,
        group,
        layout: "checkout".into(),
        branch: "dev".into(),
        inspect_content: true,
        unresolved: false,
        allow_absent: false,
        origin: ORIGIN.into(),
        prepare_id: String::new(),
    }
}

#[test]
fn inspection_reports_the_checkout_evidence() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let output = InspectSource::run(inspect_input(&sandbox, &checkout), &context()).unwrap();
    assert_eq!(decode(&output.top), bytes(&checkout));
    assert_eq!(decode(&output.common), bytes(&checkout.join(".git")));
    assert_eq!(decode(&output.origin), ORIGIN.as_bytes());
    assert_eq!(decode(&output.branch), b"dev");
    assert_eq!(decode(&output.commit), Sandbox::head(&checkout).as_bytes());
    assert_eq!(decode(&output.dirty), b"0");
    assert_eq!(
        decode(&output.source_identity),
        identity(&checkout).as_bytes()
    );
    let mut listed = b"worktree ".to_vec();
    listed.extend_from_slice(bytes(&checkout));
    listed.push(0);
    assert!(decode(&output.worktrees).starts_with(&listed));
}

#[test]
fn inspection_names_each_refused_identity_check() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let decoy = sandbox.base.join("decoy");
    fs::create_dir(&decoy).unwrap();
    std::os::unix::fs::symlink(&decoy, sandbox.project.join("linked")).unwrap();
    let with = |change: &dyn Fn(&mut InspectSourceInput)| {
        let mut input = inspect_input(&sandbox, &checkout);
        change(&mut input);
        code(InspectSource::run(input, &context()))
    };
    assert_eq!(
        with(&|input| input.checkout = sandbox.base.join("outside")),
        "instances.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.checkout = sandbox.project.join("linked")),
        "instances.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.checkout = sandbox.project.join("missing")),
        "instances.path_mismatch"
    );
    assert_eq!(
        with(&|input| input.user = "someone-else".into()),
        "instances.ownership_mismatch"
    );
    assert_eq!(
        with(&|input| input.prepare_id = "another-attempt".into()),
        "instances.ownership_mismatch"
    );
    assert_eq!(
        with(&|input| input.layout = "worktree".into()),
        "instances.layout_mismatch"
    );
    assert_eq!(
        with(&|input| input.branch = "main".into()),
        "instances.branch_mismatch"
    );
    sh_git(&checkout, &["remote", "remove", "origin"]);
    assert_eq!(with(&|_| {}), "instances.origin_mismatch");
    assert!(checkout.join(".git").is_dir());
}

#[test]
fn inspection_reports_dirty_source_only_when_asked() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(checkout.join("new.txt"), "work\n").unwrap();
    assert_eq!(
        decode(
            &InspectSource::run(inspect_input(&sandbox, &checkout), &context())
                .unwrap()
                .dirty
        ),
        b"1"
    );
    let mut input = inspect_input(&sandbox, &checkout);
    input.inspect_content = false;
    assert_eq!(
        decode(&InspectSource::run(input, &context()).unwrap().dirty),
        b""
    );
}

#[test]
fn inspection_accepts_the_preparation_receipt_of_this_attempt() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(
        checkout.join(".git/orbit-source-prepare"),
        format!("attempt-1:{}\n", identity(&checkout)),
    )
    .unwrap();
    let mut input = inspect_input(&sandbox, &checkout);
    input.prepare_id = "attempt-1".into();
    assert!(InspectSource::run(input, &context()).is_ok());
}

#[test]
fn inspection_reports_an_absent_source_when_absence_is_allowed() {
    let sandbox = Sandbox::new();
    let reserved = sandbox.project.join("reserved");
    let mut input = inspect_input(&sandbox, &reserved);
    input.allow_absent = true;
    input.layout = "worktree".into();
    let output = InspectSource::run(input, &context()).unwrap();
    assert_eq!(decode(&output.source_identity), b"absent");
    let mut listed = b"worktree ".to_vec();
    listed.extend_from_slice(bytes(&reserved));
    listed.extend_from_slice(b"\0\0");
    assert_eq!(decode(&output.worktrees), listed);
}

// ---- remove -----------------------------------------------------------------------------------------------------

fn remove_input(sandbox: &Sandbox, checkout: &Path, branch: &str) -> RemoveSourceInput {
    let (user, group) = me();
    RemoveSourceInput {
        checkout: checkout.into(),
        root: sandbox.root.clone(),
        grouping_directory: sandbox.project.clone(),
        user,
        group,
        branch: branch.into(),
        commit: Sandbox::head(checkout),
        source_identity: identity(checkout),
        repository_identity: IDENTITY.into(),
        force: false,
        own_units: vec![],
        git_read: GitRead::default(),
    }
}

#[test]
fn removal_deletes_a_clean_published_checkout_and_its_empty_parent() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    assert_eq!(
        remove_source(remove_input(&sandbox, &checkout, "dev"), &context()),
        Ok(Empty {})
    );
    assert!(!exists(&checkout));
    assert!(!exists(&sandbox.project));
}

#[test]
fn removal_keeps_a_parent_that_holds_another_checkout() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    sandbox.checkout("other");
    assert!(remove_source(remove_input(&sandbox, &checkout, "dev"), &context()).is_ok());
    assert!(sandbox.project.join("other/.git").is_dir());
}

#[test]
fn removal_refuses_dirty_or_unpublished_source_unless_forced() {
    let sandbox = Sandbox::new();
    let dirty = sandbox.checkout("dev");
    fs::write(dirty.join("new.txt"), "work\n").unwrap();
    let unpublished = sandbox.checkout("unpublished");
    sh_git(
        &unpublished,
        &["commit", "--quiet", "--allow-empty", "-m", "Local only"],
    );
    assert_eq!(
        code(remove_source(
            remove_input(&sandbox, &dirty, "dev"),
            &context()
        )),
        "instances.unsafe_content"
    );
    assert_eq!(
        code(remove_source(
            remove_input(&sandbox, &unpublished, "unpublished"),
            &context()
        )),
        "instances.unsafe_content"
    );
    assert!(dirty.join("new.txt").is_file());
    assert!(unpublished.join(".git").is_dir());
    let mut forced = remove_input(&sandbox, &unpublished, "unpublished");
    forced.force = true;
    assert!(remove_source(forced, &context()).is_ok());
    assert!(!exists(&unpublished));
}

#[test]
fn removal_refuses_a_source_that_changed_after_inspection() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let inspected = Sandbox::head(&checkout);
    sh_git(
        &checkout,
        &["commit", "--quiet", "--allow-empty", "-m", "Later"],
    );
    let mut input = remove_input(&sandbox, &checkout, "dev");
    input.commit = inspected;
    input.force = true;
    assert_eq!(
        code(remove_source(input, &context())),
        "instances.source_changed"
    );
    let mut input = remove_input(&sandbox, &checkout, "dev");
    input.source_identity = "1:1".into();
    input.force = true;
    assert_eq!(
        code(remove_source(input, &context())),
        "instances.source_changed"
    );
    assert!(checkout.join(".git").is_dir());
}

#[test]
fn removal_refuses_each_identity_mismatch_even_when_forced() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let forced = |change: &dyn Fn(&mut RemoveSourceInput)| {
        let mut input = remove_input(&sandbox, &checkout, "dev");
        input.force = true;
        change(&mut input);
        code(remove_source(input, &context()))
    };
    assert_eq!(
        forced(&|input| input.grouping_directory = sandbox.root.clone()),
        "instances.path_mismatch"
    );
    assert_eq!(
        forced(&|input| input.user = "someone-else".into()),
        "instances.ownership_mismatch"
    );
    assert_eq!(
        forced(&|input| input.branch = "main".into()),
        "instances.branch_mismatch"
    );
    assert_eq!(
        forced(&|input| input.repository_identity = "example.test/acme/other".into()),
        "instances.origin_mismatch"
    );
    sh_git(
        &checkout,
        &[
            "remote",
            "set-url",
            "origin",
            "ssh://git@example.test/acme/site.git\n",
        ],
    );
    assert_eq!(forced(&|_| {}), "instances.origin_mismatch");
    assert!(checkout.join(".git").is_dir());
}

#[test]
fn removal_refuses_a_live_linked_worktree_and_ignores_a_prunable_one() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let linked = sandbox.base.join("linked");
    sh_git(
        &checkout,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "linked",
            linked.to_str().unwrap(),
        ],
    );
    let forced = || {
        let mut input = remove_input(&sandbox, &checkout, "dev");
        input.force = true;
        code(remove_source(input, &context()))
    };
    assert_eq!(forced(), "instances.worktrees_mismatch");
    fs::remove_dir_all(&linked).unwrap();
    assert_eq!(forced(), "ok");
}

#[test]
fn removal_refuses_shared_git_administration_as_a_layout_mismatch() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let shared = sandbox.base.join("shared.git");
    assert!(Process::new("cp")
        .arg("-a")
        .arg(checkout.join(".git"))
        .arg(&shared)
        .status()
        .unwrap()
        .success());
    fs::write(
        checkout.join(".git/commondir"),
        format!("{}\n", shared.display()),
    )
    .unwrap();
    let mut input = remove_input(&sandbox, &checkout, "dev");
    input.force = true;
    assert_eq!(
        code(remove_source(input, &context())),
        "instances.layout_mismatch"
    );
    assert!(shared.is_dir());
}

// ---- recorded removal -------------------------------------------------------------------------------------------

struct Recorded<'a> {
    sandbox: &'a Sandbox,
    checkout: PathBuf,
    layout: &'static str,
    common: PathBuf,
    source_identity: String,
    operation: String,
}

const DIGEST: &str = "1111111111111111111111111111111111111111111111111111111111111111";
const RECEIPT: &str = "2222222222222222222222222222222222222222222222222222222222222222";

impl<'a> Recorded<'a> {
    fn new(
        sandbox: &'a Sandbox,
        checkout: PathBuf,
        layout: &'static str,
        common: Option<PathBuf>,
    ) -> Self {
        Self {
            source_identity: identity(&checkout),
            common: common.unwrap_or_else(|| checkout.clone()),
            operation: format!("op-{:016x}", rand::random::<u64>()),
            sandbox,
            checkout,
            layout,
        }
    }

    fn quarantine(&self) -> PathBuf {
        removal_paths(&self.sandbox.root, &self.operation, "7").quarantine
    }

    fn prepare(&self, digest: &str) -> Result<Empty, SourceError> {
        let (user, group) = me();
        PrepareRemoval::run(
            PrepareRemovalInput {
                root: self.sandbox.root.clone(),
                operation: self.operation.clone(),
                member: "7".into(),
                digest: digest.into(),
                user,
                group,
                checkout: self.checkout.clone(),
                common_repository: self.common.clone(),
                layout: self.layout.into(),
                source_identity: self.source_identity.clone(),
            },
            &context(),
        )
    }

    fn state(&self) -> String {
        let (user, group) = me();
        ReadRemovalState::run(
            ReadRemovalStateInput {
                checkout: self.checkout.clone(),
                root: self.sandbox.root.clone(),
                operation: self.operation.clone(),
                member: "7".into(),
                digest: DIGEST.into(),
                receipt: RECEIPT.into(),
                source_identity: self.source_identity.clone(),
                user,
                group,
                grouping_directory: self.sandbox.project.clone(),
            },
            &context(),
        )
        .map(|output| output.state)
        .unwrap_or_else(|error| error.code().to_string())
    }

    fn finalization(&self) -> FinalizeRemovalInput {
        let (user, group) = me();
        FinalizeRemovalInput {
            checkout: self.checkout.clone(),
            root: self.sandbox.root.clone(),
            common_repository: self.common.clone(),
            layout: self.layout.into(),
            branch: sh_git(&self.checkout, &["symbolic-ref", "--short", "HEAD"]),
            operation: self.operation.clone(),
            member: "7".into(),
            digest: DIGEST.into(),
            receipt: RECEIPT.into(),
            force: false,
            user,
            group,
            source_identity: self.source_identity.clone(),
            origin: ORIGIN.into(),
            worktrees: encoded(&worktree_inventory(&self.checkout)),
            commit: Sandbox::head(&self.checkout),
            grouping_directory: self.sandbox.project.clone(),
            unresolved: false,
            independent_worktree: false,
            own_units: vec![],
            git_read: GitRead::default(),
        }
    }

    fn receipt_input(&self) -> ReceiptInput {
        let (user, group) = me();
        ReceiptInput {
            checkout: self.checkout.clone(),
            root: self.sandbox.root.clone(),
            common_repository: self.common.clone(),
            layout: self.layout.into(),
            operation: self.operation.clone(),
            member: "7".into(),
            digest: DIGEST.into(),
            receipt: RECEIPT.into(),
            user,
            group,
            source_identity: self.source_identity.clone(),
            grouping_directory: Some(self.sandbox.project.clone()),
        }
    }
}

#[test]
fn preparation_records_the_journal_once_and_refuses_another_digest() {
    let sandbox = Sandbox::new();
    let recorded = Recorded::new(&sandbox, sandbox.checkout("dev"), "checkout", None);
    assert_eq!(recorded.prepare(DIGEST), Ok(Empty {}));
    assert_eq!(recorded.prepare(DIGEST), Ok(Empty {}));
    let paths = removal_paths(&sandbox.root, &recorded.operation, "7");
    assert_eq!(
        fs::read_to_string(&paths.journal).unwrap(),
        format!("{DIGEST}\n")
    );
    assert_eq!(
        fs::metadata(&paths.journal).unwrap().permissions().mode() & 0o777,
        0o600
    );
    assert_eq!(
        fs::metadata(&paths.state).unwrap().permissions().mode() & 0o777,
        0o700
    );
    assert_eq!(
        code(recorded.prepare(&"0".repeat(64))),
        "instances.removal_refused"
    );
}

#[test]
fn finalization_moves_a_checkout_through_quarantine_and_writes_its_receipt() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    assert_eq!(recorded.state(), "present");
    let output = finalize_removal(recorded.finalization(), &context()).unwrap();
    assert_eq!(
        output,
        FinalizeRemovalOutput {
            state: "finalized".into(),
            receipt: Some(RECEIPT.into())
        }
    );
    assert!(!exists(&checkout));
    assert!(!exists(&recorded.quarantine()));
    assert!(!exists(&sandbox.project));
    assert_eq!(recorded.state(), "completed");
}

#[test]
fn finalization_refuses_dirty_source_or_a_replaced_directory_before_moving_it() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    fs::write(checkout.join("new.txt"), "work\n").unwrap();
    assert_eq!(
        code(finalize_removal(recorded.finalization(), &context())),
        "instances.removal_refused"
    );
    fs::remove_file(checkout.join("new.txt")).unwrap();
    let mut replaced = recorded.finalization();
    replaced.source_identity = "1:1".into();
    assert_eq!(
        code(finalize_removal(replaced, &context())),
        "instances.removal_refused"
    );
    assert!(checkout.join(".git").is_dir());
    assert!(!exists(&recorded.quarantine()));
}

#[test]
fn cleanup_finishes_a_removal_that_stopped_after_its_receipt() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    // Finalization moved the checkout and wrote the receipt, then stopped before it deleted the quarantine.
    let paths = removal_paths(&sandbox.root, &recorded.operation, "7");
    fs::rename(&checkout, &paths.quarantine).unwrap();
    write_atomically(
        &paths.state,
        ".receipt.",
        &paths.receipt,
        RECEIPT.as_bytes(),
    )
    .unwrap();
    assert_eq!(recorded.state(), "receipt_pending_cleanup");
    fs::remove_dir_all(paths.quarantine.join(".git")).unwrap();
    assert_eq!(
        ReadReceipt::run(recorded.receipt_input(), &context())
            .unwrap()
            .state,
        "incomplete"
    );
    assert_eq!(
        CleanReceipt::run(recorded.receipt_input(), &context()).unwrap(),
        ReceiptOutput {
            receipt: RECEIPT.into()
        }
    );
    assert!(!exists(&paths.quarantine));
    assert_eq!(recorded.state(), "completed");
}

#[test]
fn finalization_of_a_quarantined_worktree_keeps_its_branch_and_repository() {
    let sandbox = Sandbox::new();
    let repository = sandbox.checkout("default");
    let worktree = sandbox.project.join("feature");
    sh_git(
        &repository,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "feature",
            worktree.to_str().unwrap(),
        ],
    );
    sh_git(
        &worktree,
        &[
            "push",
            "--quiet",
            sandbox.origin.to_str().unwrap(),
            "feature",
        ],
    );
    let recorded = Recorded::new(
        &sandbox,
        worktree.clone(),
        "worktree",
        Some(repository.clone()),
    );
    recorded.prepare(DIGEST).unwrap();
    let mut input = recorded.finalization();
    // Finalization moved the worktree into quarantine, then stopped.
    sh_git(
        &repository,
        &[
            "worktree",
            "move",
            worktree.to_str().unwrap(),
            recorded.quarantine().to_str().unwrap(),
        ],
    );
    assert_eq!(recorded.state(), "quarantined");
    input.force = true;
    input.independent_worktree = true;
    input.worktrees = String::new();
    let commit = sh_git(&repository, &["rev-parse", "feature"]);
    input.commit = commit.clone();
    assert_eq!(
        finalize_removal(input, &context()).unwrap().state,
        "finalized"
    );
    assert!(!exists(&worktree));
    assert!(!exists(&recorded.quarantine()));
    assert_eq!(
        sh_git(&repository, &["worktree", "list", "--porcelain"])
            .matches("worktree ")
            .count(),
        1
    );
    assert_eq!(
        sh_git(
            &repository,
            &["rev-parse", "--verify", "refs/heads/feature"]
        ),
        commit
    );
}

// ---- publication and processes ----------------------------------------------------------------------------------

#[test]
fn publication_finds_a_commit_on_a_branch_of_the_origin_and_not_a_local_one() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let published = Sandbox::head(&checkout);
    sh_git(
        &checkout,
        &["commit", "--quiet", "--allow-empty", "-m", "Local only"],
    );
    let check = |commit: String| {
        CheckPublication::run(
            CheckPublicationInput {
                origin: sandbox.origin.to_str().unwrap().into(),
                commit,
                git_read: GitRead::default(),
            },
            &context(),
        )
    };
    assert_eq!(check(published), Ok(PublicationOutput { published: true }));
    assert_eq!(
        check(Sandbox::head(&checkout)),
        Ok(PublicationOutput { published: false })
    );
    let unreachable = CheckPublication::run(
        CheckPublicationInput {
            origin: sandbox
                .base
                .join("missing-origin.git")
                .to_str()
                .unwrap()
                .into(),
            commit: "0".repeat(40),
            git_read: GitRead::default(),
        },
        &context(),
    );
    assert!(matches!(unreachable, Err(SourceError::Failed { .. })));
}

fn spawn_in(directory: &Path) -> std::process::Child {
    Process::new("sleep")
        .arg("30")
        .current_dir(directory)
        .spawn()
        .unwrap()
}

#[test]
fn the_process_stop_ends_a_process_inside_the_tree() {
    let sandbox = Sandbox::new();
    let tree = sandbox.checkout("dev");
    let mut child = spawn_in(&tree);
    std::thread::sleep(Duration::from_millis(200));
    assert!(tree_processes(&tree, &[]).contains(&(child.id() as i32)));
    assert!(stop_tree_processes(&tree, &[]));
    assert!(child.wait().is_ok());
}

#[test]
fn the_process_stop_leaves_the_processes_of_the_instances_own_units_running() {
    let sandbox = Sandbox::new();
    let tree = sandbox.checkout("dev");
    let mut child = spawn_in(&tree);
    std::thread::sleep(Duration::from_millis(200));
    let unit = String::from_utf8(read_text(Path::new(&format!(
        "/proc/{}/cgroup",
        child.id()
    ))))
    .unwrap();
    let unit = unit.rsplit('/').next().unwrap().to_string();
    assert!(!tree_processes(&tree, std::slice::from_ref(&unit)).contains(&(child.id() as i32)));
    let _ = child.kill();
    let _ = child.wait();
}

#[test]
fn linked_worktree_counting_skips_prunable_entries_and_refuses_another_tree() {
    let checkout = Path::new("/apps/acme/dev");
    assert_eq!(
        linked_worktrees(checkout, b"worktree /apps/acme/dev\0HEAD x\0\0").unwrap(),
        1
    );
    assert_eq!(linked_worktrees(checkout, b"worktree /apps/acme/dev\0\0worktree /gone\0prunable gitdir file points to non-existent location\0\0").unwrap(), 1);
    assert_eq!(
        linked_worktrees(checkout, b"worktree /apps/acme/dev\0\0worktree /other\0\0"),
        Err(SourceError::WorktreesMismatch {})
    );
}

// ---- Laravel routes ---------------------------------------------------------------------------------------------

#[test]
fn routes_collide_only_where_a_first_segment_can_match_the_reverb_paths() {
    for (uri, collision) in [
        ("dashboard", false),
        ("broadcasting/auth", false),
        ("application/{id}", false),
        ("application", false),
        ("app-store/{id}", false),
        ("api/users", false),
        ("powersync/sync/stream", false),
        ("{fallbackPlaceholder}", false),
        ("app/{id}", true),
        ("apps", true),
        ("APP", true),
        ("APPS", true),
        ("/App/page", true),
        ("app/{fallbackPlaceholder}", true),
        ("ap{suffix}", true),
        ("app{suffix?}", true),
        ("app.{format?}", true),
        ("{any}", true),
        ("{page?}", true),
    ] {
        assert_eq!(routes_collide(&[uri.to_string()]), collision, "{uri}");
    }
    assert!(!routes_collide(&[
        "dashboard".to_string(),
        "{fallbackPlaceholder}".to_string()
    ]));
    assert!(routes_collide(&[
        "dashboard".to_string(),
        "apps".to_string()
    ]));
}

/// Fake PHP binaries on `PATH`: `php9.9` lists a route, `php9.8` fails, and `php9.7` and `php9.6` print what is not a
/// route list.
fn fake_php() -> &'static Path {
    static DIRECTORY: OnceLock<PathBuf> = OnceLock::new();
    DIRECTORY.get_or_init(|| {
        let directory = std::env::temp_dir().join(format!("orbit-fake-php-{}", std::process::id()));
        fs::create_dir_all(&directory).unwrap();
        for (name, body) in [
            ("php9.9", "printf '%s' '[{\"uri\":\"app/{x}\"}]'\nexit 0"),
            ("php9.8", "echo secret-env-value >&2\nexit 1"),
            ("php9.7", "printf '%s' '{\"sensitive\":\"value\"}'\nexit 0"),
            (
                "php9.6",
                "printf '%s' 'sensitive application error'\nexit 0",
            ),
            ("php9.5", "exec sleep 30"),
        ] {
            let path = directory.join(name);
            fs::write(&path, format!("#!/bin/sh\n{body}\n")).unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
        }
        let path = format!(
            "{}:{}",
            directory.display(),
            std::env::var("PATH").unwrap_or_default()
        );
        std::env::set_var("PATH", path);
        directory
    })
}

fn release_fixture(base: &Path) -> (PathBuf, PathBuf) {
    fake_php();
    let home = base.join("home");
    let release = home.join("releases/r1");
    fs::create_dir_all(&release).unwrap();
    std::os::unix::fs::symlink(&release, home.join("current")).unwrap();
    (home, release)
}

#[test]
fn laravel_routes_are_read_inside_the_selected_release_and_failures_print_nothing() {
    let sandbox = Sandbox::new();
    let (user, _) = me();
    let (home, release) = release_fixture(&sandbox.base);
    let input = |application: PathBuf, php_version: &str| InspectLaravelRoutesInput {
        user: user.clone(),
        home: home.clone(),
        application,
        php_version: php_version.into(),
    };
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.9"), &context()),
        Ok(LaravelRoutesOutput { collision: true })
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(sandbox.base.clone(), "9.9"), &context()),
        Err(LaravelRoutesError::PathMismatch {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.8"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.7"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.6"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    // A release that hangs at boot fails at the deadline, and its process does not outlive the read.
    let started = Instant::now();
    let short = Context::new(Platform::Linux, Instant::now() + Duration::from_millis(500));
    assert_eq!(
        InspectLaravelRoutes::run(input(release.clone(), "9.5"), &short),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
    assert!(started.elapsed() < Duration::from_secs(5));
    assert_eq!(
        InspectLaravelRoutes::run(input(release, "../../bin/sh"), &context()),
        Err(LaravelRoutesError::RoutesUnreadable {})
    );
}

#[test]
fn laravel_routes_refuse_a_current_link_outside_the_releases() {
    let sandbox = Sandbox::new();
    let (user, _) = me();
    let (home, _) = release_fixture(&sandbox.base);
    let elsewhere = sandbox.base.join("elsewhere");
    fs::create_dir(&elsewhere).unwrap();
    fs::remove_file(home.join("current")).unwrap();
    std::os::unix::fs::symlink(&elsewhere, home.join("current")).unwrap();
    let input = InspectLaravelRoutesInput {
        user,
        home,
        application: elsewhere,
        php_version: "9.9".into(),
    };
    assert_eq!(
        InspectLaravelRoutes::run(input, &context()),
        Err(LaravelRoutesError::PathMismatch {})
    );
}

#[test]
fn a_path_is_free_only_when_nothing_and_no_dangling_link_occupies_it() {
    let base = fs::canonicalize(std::env::temp_dir())
        .unwrap()
        .join(format!("orbit-path-free-{:016x}", rand::random::<u64>()));
    fs::create_dir_all(&base).unwrap();
    let context = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(5),
    );
    let check = |path: &Path| {
        CheckPathFree::run(PathInput { path: path.into() }, &context)
            .unwrap()
            .free
    };

    assert!(check(&base.join("new")));
    std::os::unix::fs::symlink(base.join("missing"), base.join("dangling")).unwrap();
    assert!(!check(&base.join("dangling")));
    assert!(!check(&base));
    fs::remove_dir_all(&base).unwrap();
}
