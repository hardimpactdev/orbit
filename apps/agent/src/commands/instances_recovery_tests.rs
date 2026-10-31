//! Observe native removal journals without performing source cleanup.
use super::*;

pub(super) fn tree_bytes(root: &Path) -> std::collections::BTreeMap<PathBuf, Vec<u8>> {
    fn visit(root: &Path, path: &Path, rows: &mut std::collections::BTreeMap<PathBuf, Vec<u8>>) {
        for entry in fs::read_dir(path).unwrap() {
            let entry = entry.unwrap();
            if entry.file_type().unwrap().is_dir() {
                visit(root, &entry.path(), rows);
            } else {
                rows.insert(
                    entry.path().strip_prefix(root).unwrap().into(),
                    fs::read(entry.path()).unwrap(),
                );
            }
        }
    }
    let mut rows = std::collections::BTreeMap::new();
    visit(root, root, &mut rows);
    rows
}

const DIGEST: &str = "1111111111111111111111111111111111111111111111111111111111111111";
const RECEIPT: &str = "2222222222222222222222222222222222222222222222222222222222222222";

/// Native execution owns these files. Observer fixtures write them directly;
/// they do not retain an agent preparation or finalization command.
fn receipt_fixture(worktree: bool) -> (Sandbox, ReceiptInput, RemovalPaths) {
    let sandbox = Sandbox::new();
    let common = sandbox.checkout("default");
    let checkout = if worktree {
        let checkout = sandbox.project.join("feature");
        sh_git(
            &common,
            &[
                "worktree",
                "add",
                "-b",
                "feature",
                checkout.to_str().unwrap(),
            ],
        );
        checkout
    } else {
        common.clone()
    };
    let source_identity = identity(&checkout);
    let paths = removal_paths(&sandbox.root, "native-root", "7");
    fs::create_dir(&paths.state).unwrap();
    fs::write(&paths.journal, format!("{DIGEST}\n")).unwrap();
    fs::write(&paths.receipt, format!("{RECEIPT}\n")).unwrap();
    if worktree {
        sh_git(
            &common,
            &[
                "worktree",
                "move",
                checkout.to_str().unwrap(),
                paths.quarantine.to_str().unwrap(),
            ],
        );
        let admin = PathBuf::from(sh_git(
            &paths.quarantine,
            &["rev-parse", "--absolute-git-dir"],
        ));
        fs::write(
            &paths.recovery,
            format!(
                "{}\n{}\n{}\n{}\n",
                encoded(bytes(&admin)),
                identity(&admin),
                identity(&common.join(".git")),
                identity(admin.parent().unwrap())
            ),
        )
        .unwrap();
    } else {
        fs::rename(&checkout, &paths.quarantine).unwrap();
    }
    let (user, group) = me();
    let input = ReceiptInput {
        checkout,
        root: sandbox.root.clone(),
        common_repository: common,
        layout: if worktree { "worktree" } else { "checkout" }.into(),
        operation: "native-root".into(),
        member: "7".into(),
        digest: DIGEST.into(),
        receipt: RECEIPT.into(),
        user,
        group,
        source_identity,
        grouping_directory: Some(sandbox.project.clone()),
    };
    (sandbox, input, paths)
}

#[test]
fn observing_a_checkout_receipt_never_cleans_its_quarantine_or_empty_parent() {
    let (sandbox, input, paths) = receipt_fixture(false);
    fs::remove_dir_all(paths.quarantine.join(".git")).unwrap();
    let before = tree_bytes(&sandbox.root);
    assert_eq!(
        ReadReceipt::run(input, &context()).unwrap().state,
        "incomplete"
    );
    assert_eq!(tree_bytes(&sandbox.root), before);
    assert!(paths.quarantine.is_dir());
    assert!(sandbox.project.is_dir());
}

#[test]
fn observing_an_intact_worktree_receipt_preserves_git_and_quarantine() {
    let (sandbox, input, _) = receipt_fixture(true);
    let before = tree_bytes(&sandbox.root);
    assert_eq!(ReadReceipt::run(input, &context()).unwrap().state, "intact");
    assert_eq!(tree_bytes(&sandbox.root), before);
}

#[test]
fn observing_an_interrupted_worktree_cleanup_never_finishes_it() {
    let (sandbox, input, paths) = receipt_fixture(true);
    let admin = PathBuf::from(sh_git(
        &paths.quarantine,
        &["rev-parse", "--absolute-git-dir"],
    ));
    fs::remove_file(admin.join("gitdir")).unwrap();
    fs::remove_file(paths.quarantine.join(".git")).unwrap();
    let before = tree_bytes(&sandbox.root);
    assert_eq!(
        ReadReceipt::run(input, &context()).unwrap().state,
        "incomplete"
    );
    assert_eq!(tree_bytes(&sandbox.root), before);
    assert!(admin.is_dir());
    assert!(paths.quarantine.is_dir());
}

#[test]
fn receipt_observation_refuses_changed_quarantine_without_deleting_it() {
    let (sandbox, input, paths) = receipt_fixture(false);
    fs::rename(&paths.quarantine, sandbox.project.join("preserved")).unwrap();
    fs::create_dir(&paths.quarantine).unwrap();
    fs::write(paths.quarantine.join("new-source"), "keep").unwrap();
    let before = tree_bytes(&sandbox.root);
    assert_eq!(
        code(ReadReceipt::run(input, &context())),
        "instance.removal_refused"
    );
    assert_eq!(tree_bytes(&sandbox.root), before);
}
