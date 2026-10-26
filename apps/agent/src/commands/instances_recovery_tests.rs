//! Real filesystem checkpoints formerly exercised through the Gateway's local-agent transport.
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

fn worktree_set(sandbox: &Sandbox) -> (PathBuf, PathBuf, PathBuf) {
    let repository = sandbox.checkout("default");
    let worktree = sandbox.project.join("feature");
    let sibling = sandbox.project.join("sibling");
    for (name, path) in [("feature", &worktree), ("sibling", &sibling)] {
        sh_git(
            &repository,
            &[
                "worktree",
                "add",
                "--quiet",
                "-b",
                name,
                path.to_str().unwrap(),
            ],
        );
    }
    (repository, worktree, sibling)
}

#[test]
fn fixed_worktree_removal_rechecks_each_shrinking_inventory_and_preserves_origin_refs() {
    let sandbox = Sandbox::new();
    let (repository, first, second) = worktree_set(&sandbox);
    let records = [
        Recorded::new(
            &sandbox,
            first.clone(),
            "worktree",
            Some(repository.clone()),
        ),
        Recorded::new(
            &sandbox,
            second.clone(),
            "worktree",
            Some(repository.clone()),
        ),
        Recorded::new(&sandbox, repository.clone(), "checkout", None),
    ];
    let origin_before = tree_bytes(&sandbox.origin);
    for (position, record) in records.iter().enumerate() {
        record.prepare(DIGEST).unwrap();
        let mut input = record.finalization();
        input.force = true;
        if position == 1 {
            let unknown = sandbox.project.join("unknown");
            sh_git(
                &repository,
                &[
                    "worktree",
                    "add",
                    "-b",
                    "unknown",
                    unknown.to_str().unwrap(),
                ],
            );
            let before = tree_bytes(&repository);
            assert_eq!(
                code(finalize_removal(input, &context())),
                "instance.removal_refused"
            );
            assert_eq!(tree_bytes(&repository), before);
            assert!(unknown.exists());
            assert!(second.exists());
            assert!(!record.quarantine().exists());
            sh_git(
                &repository,
                &["worktree", "remove", unknown.to_str().unwrap()],
            );
            input = record.finalization();
            input.force = true;
        }
        finalize_removal(input, &context()).unwrap();
        assert_eq!(record.state(), "completed");
        assert!(!record.checkout.exists());
        if position < 2 {
            assert!(repository.join(".git").is_dir());
            assert_eq!(
                sh_git(&repository, &["rev-parse", "refs/heads/feature"]),
                sh_git(&repository, &["rev-parse", "HEAD"])
            );
        }
    }
    assert!(!sandbox.project.exists());
    assert!(sandbox.root.exists());
    assert_eq!(tree_bytes(&sandbox.origin), origin_before);
    assert_eq!(records[0].state(), "completed");

    fs::create_dir_all(&first).unwrap();
    fs::write(first.join("foreign"), "new work").unwrap();
    assert_eq!(records[0].state(), "instance.removal_refused");
    assert_eq!(
        fs::read_to_string(first.join("foreign")).unwrap(),
        "new work"
    );
}

#[test]
fn absent_worktree_finalization_preserves_the_seed_siblings_and_branches() {
    let sandbox = Sandbox::new();
    let (repository, checkout, sibling) = worktree_set(&sandbox);
    let mut recorded = Recorded::new(
        &sandbox,
        checkout.clone(),
        "worktree",
        Some(repository.clone()),
    );
    let mut input = recorded.finalization();
    sh_git(
        &repository,
        &["worktree", "remove", checkout.to_str().unwrap()],
    );
    input.source_identity = "absent".into();
    input.unresolved = true;
    input.force = true;
    input.commit = ZERO_COMMIT.into();
    input.branch.clear();
    recorded.source_identity = "absent".into();
    let repository_before = tree_bytes(&repository);
    let sibling_before = tree_bytes(&sibling);

    recorded.prepare(DIGEST).unwrap();
    FinalizeRemoval::run_with(input, &|_, _, _| {
        panic!("absent source must not query publication")
    })
    .unwrap();

    assert!(!checkout.exists());
    assert_eq!(tree_bytes(&repository), repository_before);
    assert_eq!(tree_bytes(&sibling), sibling_before);
    assert_eq!(recorded.state(), "completed");
}

fn stage_worktree_receipt(recorded: &Recorded<'_>) -> PathBuf {
    recorded.prepare(DIGEST).unwrap();
    sh_git(
        &recorded.common,
        &[
            "worktree",
            "move",
            recorded.checkout.to_str().unwrap(),
            recorded.quarantine().to_str().unwrap(),
        ],
    );
    let admin = PathBuf::from(sh_git(
        &recorded.quarantine(),
        &["rev-parse", "--absolute-git-dir"],
    ));
    let record = format!(
        "{}\n{}\n{}\n{}",
        encoded(bytes(&admin)),
        identity(&admin),
        identity(&recorded.common.join(".git")),
        identity(admin.parent().unwrap())
    );
    let paths = removal_paths(&recorded.sandbox.root, &recorded.operation, "7");
    write_atomically(
        &paths.state,
        ".recovery.",
        &paths.recovery,
        record.as_bytes(),
    )
    .unwrap();
    write_atomically(
        &paths.state,
        ".receipt.",
        &paths.receipt,
        RECEIPT.as_bytes(),
    )
    .unwrap();
    admin
}

#[test]
fn acknowledged_worktree_cleanup_resumes_after_each_partially_deleted_git_structure() {
    for fault in ["git-file", "admin-entry", "quarantine", "admin-gitdir"] {
        let sandbox = Sandbox::new();
        let (repository, worktree, sibling) = worktree_set(&sandbox);
        let recorded = Recorded::new(
            &sandbox,
            worktree.clone(),
            "worktree",
            Some(repository.clone()),
        );
        let commit = Sandbox::head(&worktree);
        let sibling_index = fs::read(sibling.join(".git")).unwrap();
        let admin = stage_worktree_receipt(&recorded);
        match fault {
            "git-file" => fs::remove_file(recorded.quarantine().join(".git")).unwrap(),
            "admin-entry" => fs::remove_dir_all(&admin).unwrap(),
            "quarantine" => fs::remove_dir_all(recorded.quarantine()).unwrap(),
            "admin-gitdir" => fs::remove_file(admin.join("gitdir")).unwrap(),
            _ => unreachable!(),
        }
        assert_eq!(
            ReadReceipt::run(recorded.receipt_input(), &context())
                .unwrap()
                .state,
            "incomplete",
            "{fault}"
        );
        assert_eq!(
            CleanReceipt::run(recorded.receipt_input(), &context())
                .unwrap()
                .receipt,
            RECEIPT,
            "{fault}"
        );
        assert_eq!(
            ReadReceipt::run(recorded.receipt_input(), &context())
                .unwrap()
                .state,
            "complete",
            "{fault}"
        );
        assert_eq!(
            CleanReceipt::run(recorded.receipt_input(), &context())
                .unwrap()
                .receipt,
            RECEIPT,
            "{fault}"
        );
        assert!(!exists(&recorded.quarantine()) && !exists(&admin));
        assert!(repository.join(".git").is_dir() && sibling.is_dir());
        assert_eq!(fs::read(sibling.join(".git")).unwrap(), sibling_index);
        assert_eq!(sh_git(&sibling, &["status", "--porcelain"]), "");
        assert_eq!(sh_git(&repository, &["rev-parse", "feature"]), commit);
    }
}

#[test]
fn receipt_recovery_refuses_changed_or_ambiguous_worktree_administration_without_deleting_it() {
    for fault in ["changed", "ambiguous", "replaced-admin", "git-link"] {
        let sandbox = Sandbox::new();
        let (repository, worktree, sibling) = worktree_set(&sandbox);
        let recorded = Recorded::new(&sandbox, worktree, "worktree", Some(repository));
        let admin = stage_worktree_receipt(&recorded);
        match fault {
            "changed" => fs::write(
                admin.join("gitdir"),
                format!("{}/.git\n", sibling.display()),
            )
            .unwrap(),
            "ambiguous" => {
                assert!(Process::new("cp")
                    .arg("-a")
                    .arg(&admin)
                    .arg(admin.parent().unwrap().join("duplicate"))
                    .status()
                    .unwrap()
                    .success());
            }
            "replaced-admin" => {
                let kept = sandbox.base.join("kept-admin");
                fs::rename(&admin, &kept).unwrap();
                assert!(Process::new("cp")
                    .arg("-a")
                    .arg(kept)
                    .arg(&admin)
                    .status()
                    .unwrap()
                    .success());
            }
            "git-link" => {
                fs::write(recorded.quarantine().join(".git"), "gitdir: /wrong\n").unwrap()
            }
            _ => unreachable!(),
        }
        let admin_link = fs::read(admin.join("gitdir")).unwrap();
        let quarantine_link = fs::read(recorded.quarantine().join(".git")).unwrap();
        assert_eq!(
            code(ReadReceipt::run(recorded.receipt_input(), &context())),
            "instance.removal_refused",
            "{fault}"
        );
        assert_eq!(
            code(CleanReceipt::run(recorded.receipt_input(), &context())),
            "instance.removal_refused",
            "{fault}"
        );
        assert!(recorded.quarantine().is_dir() && sibling.is_dir());
        assert_eq!(fs::read(admin.join("gitdir")).unwrap(), admin_link);
        assert_eq!(
            fs::read(recorded.quarantine().join(".git")).unwrap(),
            quarantine_link
        );
    }
}

#[test]
fn a_receipt_does_not_authorize_a_replacement_quarantine() {
    let sandbox = Sandbox::new();
    let recorded = Recorded::new(&sandbox, sandbox.checkout("dev"), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    let paths = removal_paths(&sandbox.root, &recorded.operation, "7");
    fs::rename(&recorded.checkout, &paths.quarantine).unwrap();
    write_atomically(
        &paths.state,
        ".receipt.",
        &paths.receipt,
        RECEIPT.as_bytes(),
    )
    .unwrap();
    let kept = sandbox.base.join("kept");
    fs::rename(&paths.quarantine, &kept).unwrap();
    fs::create_dir(&paths.quarantine).unwrap();
    fs::write(paths.quarantine.join("sentinel"), "keep").unwrap();
    assert_eq!(recorded.state(), "instance.removal_refused");
    assert_eq!(
        code(CleanReceipt::run(recorded.receipt_input(), &context())),
        "instance.removal_refused"
    );
    assert!(kept.join(".git").is_dir());
    assert_eq!(
        fs::read(paths.quarantine.join("sentinel")).unwrap(),
        b"keep"
    );
}

#[test]
fn missing_ambiguous_and_mismatched_recovery_evidence_refuses_without_further_deletion() {
    for fault in ["missing", "ambiguous", "journal", "receipt"] {
        let sandbox = Sandbox::new();
        let recorded = Recorded::new(&sandbox, sandbox.checkout("dev"), "checkout", None);
        recorded.prepare(DIGEST).unwrap();
        let mut input = recorded.finalization();
        input.force = true;
        let paths = removal_paths(&sandbox.root, &recorded.operation, "7");
        let preserved = sandbox.base.join("kept");
        match fault {
            "missing" => fs::rename(&recorded.checkout, &preserved).unwrap(),
            "ambiguous" => {
                fs::rename(&recorded.checkout, &paths.quarantine).unwrap();
                fs::create_dir(&recorded.checkout).unwrap();
            }
            "journal" => fs::write(&paths.journal, "wrong\n").unwrap(),
            "receipt" => {
                fs::rename(&recorded.checkout, &preserved).unwrap();
                fs::write(&paths.receipt, "wrong\n").unwrap();
            }
            _ => unreachable!(),
        }
        assert_eq!(recorded.state(), "instance.removal_refused", "{fault}");
        assert_eq!(
            code(finalize_removal(input, &context())),
            "instance.removal_refused",
            "{fault}"
        );
        assert!(preserved.is_dir() || recorded.checkout.is_dir() || paths.quarantine.is_dir());
    }
}

#[test]
fn finalization_rechecks_identity_branch_head_origin_and_inventory_even_with_force() {
    for fault in [
        "physical",
        "origin",
        "control-byte",
        "branch",
        "head",
        "inventory",
        "shared-git",
    ] {
        let sandbox = Sandbox::new();
        let recorded = Recorded::new(&sandbox, sandbox.checkout("dev"), "checkout", None);
        recorded.prepare(DIGEST).unwrap();
        let mut input = recorded.finalization();
        input.force = true;
        match fault {
            "physical" => {
                let kept = sandbox.base.join("kept");
                fs::rename(&recorded.checkout, &kept).unwrap();
                assert!(Process::new("cp")
                    .arg("-a")
                    .arg(kept)
                    .arg(&recorded.checkout)
                    .status()
                    .unwrap()
                    .success());
            }
            "origin" => {
                sh_git(
                    &recorded.checkout,
                    &[
                        "remote",
                        "set-url",
                        "origin",
                        "https://example.test/other.git",
                    ],
                );
            }
            "control-byte" => {
                sh_git(
                    &recorded.checkout,
                    &["remote", "set-url", "origin", &format!("{ORIGIN}\n")],
                );
            }
            "branch" => {
                sh_git(&recorded.checkout, &["branch", "-m", "changed"]);
            }
            "head" => {
                sh_git(
                    &recorded.checkout,
                    &["commit", "--quiet", "--allow-empty", "-m", "changed"],
                );
            }
            "inventory" => {
                sh_git(
                    &recorded.checkout,
                    &[
                        "worktree",
                        "add",
                        "--quiet",
                        "-b",
                        "late",
                        sandbox.project.join("late").to_str().unwrap(),
                    ],
                );
            }
            "shared-git" => {
                let shared = sandbox.base.join("shared.git");
                assert!(Process::new("cp")
                    .arg("-a")
                    .arg(recorded.checkout.join(".git"))
                    .arg(&shared)
                    .status()
                    .unwrap()
                    .success());
                fs::write(
                    recorded.checkout.join(".git/commondir"),
                    format!("{}\n", shared.display()),
                )
                .unwrap();
            }
            _ => unreachable!(),
        }
        let before = worktree_inventory(&recorded.checkout);
        assert_eq!(
            code(finalize_removal(input, &context())),
            "instance.removal_refused",
            "{fault}"
        );
        assert!(recorded.checkout.join(".git").is_dir());
        assert!(!exists(&recorded.quarantine()));
        assert_eq!(worktree_inventory(&recorded.checkout), before);
    }
}

#[test]
fn forced_independent_worktree_removal_allows_sibling_changes_but_refuses_its_own_drift() {
    for quarantined in [false, true] {
        for change in [
            "new-sibling",
            "sibling-commit",
            "sibling-moved",
            "sibling-removed",
            "own-branch",
            "own-head",
            "own-origin",
            "own-replaced",
        ] {
            let sandbox = Sandbox::new();
            let (repository, worktree, sibling) = worktree_set(&sandbox);
            let recorded = Recorded::new(
                &sandbox,
                worktree.clone(),
                "worktree",
                Some(repository.clone()),
            );
            recorded.prepare(DIGEST).unwrap();
            let mut input = recorded.finalization();
            input.force = true;
            input.independent_worktree = true;
            input.worktrees.clear();
            if quarantined {
                sh_git(
                    &repository,
                    &[
                        "worktree",
                        "move",
                        worktree.to_str().unwrap(),
                        recorded.quarantine().to_str().unwrap(),
                    ],
                );
            }
            let physical = if quarantined {
                recorded.quarantine()
            } else {
                worktree.clone()
            };
            let sibling_after = if change == "sibling-moved" {
                sandbox
                    .root
                    .join(".orbit-removals/independent.999.quarantine")
            } else {
                sibling.clone()
            };
            match change {
                "new-sibling" => {
                    sh_git(
                        &repository,
                        &[
                            "worktree",
                            "add",
                            "--quiet",
                            "-b",
                            "late",
                            sandbox.project.join("late").to_str().unwrap(),
                        ],
                    );
                }
                "sibling-commit" => {
                    sh_git(
                        &sibling,
                        &["commit", "--quiet", "--allow-empty", "-m", "sibling"],
                    );
                }
                "sibling-moved" => {
                    sh_git(
                        &repository,
                        &[
                            "worktree",
                            "move",
                            sibling.to_str().unwrap(),
                            sibling_after.to_str().unwrap(),
                        ],
                    );
                }
                "sibling-removed" => {
                    sh_git(
                        &repository,
                        &["worktree", "remove", sibling.to_str().unwrap()],
                    );
                }
                "own-replaced" => {
                    fs::rename(&physical, sandbox.base.join("kept-worktree")).unwrap();
                    fs::create_dir(&physical).unwrap();
                }
                "own-branch" => {
                    sh_git(&physical, &["branch", "-m", "changed"]);
                }
                "own-head" => {
                    sh_git(
                        &physical,
                        &["commit", "--quiet", "--allow-empty", "-m", "changed"],
                    );
                }
                "own-origin" => {
                    sh_git(
                        &repository,
                        &[
                            "remote",
                            "set-url",
                            "origin",
                            "https://example.test/other.git",
                        ],
                    );
                }
                _ => unreachable!(),
            }
            let before = worktree_inventory(&repository);
            let mut shared_before = tree_bytes(&repository.join(".git"));
            shared_before.retain(|path, _| !path.starts_with("worktrees/feature"));
            let sibling_before = sibling_after.exists().then(|| tree_bytes(&sibling_after));
            let result = finalize_removal(input, &context());
            if change.starts_with("own-") {
                assert_eq!(
                    code(result),
                    "instance.removal_refused",
                    "{change}/{quarantined}"
                );
                assert!(physical.is_dir());
                assert_eq!(worktree_inventory(&repository), before);
            } else {
                assert_eq!(result.unwrap().receipt.as_deref(), Some(RECEIPT));
                assert!(!exists(&physical));
                assert_eq!(
                    sh_git(&repository, &["rev-parse", "feature"]),
                    Sandbox::head(&repository)
                );
            }
            assert!(repository.join(".git").is_dir());
            let mut shared_after = tree_bytes(&repository.join(".git"));
            shared_after.retain(|path, _| !path.starts_with("worktrees/feature"));
            assert_eq!(shared_after, shared_before, "{change}/{quarantined}");
            if let Some(sibling_before) = sibling_before {
                assert_eq!(tree_bytes(&sibling_after), sibling_before);
                assert_eq!(sh_git(&sibling_after, &["status", "--porcelain"]), "");
            } else {
                assert!(!sibling_after.exists());
            }
        }
    }
}

#[test]
fn a_new_dirty_worktree_is_refused_before_quarantine_and_force_can_resume_it() {
    let sandbox = Sandbox::new();
    let (repository, worktree, sibling) = worktree_set(&sandbox);
    let recorded = Recorded::new(
        &sandbox,
        worktree.clone(),
        "worktree",
        Some(repository.clone()),
    );
    recorded.prepare(DIGEST).unwrap();
    let input = recorded.finalization();
    fs::write(worktree.join("unfinished"), "keep work").unwrap();
    let inventory = worktree_inventory(&repository);
    assert_eq!(
        code(finalize_removal(input, &context())),
        "instance.removal_refused"
    );
    assert_eq!(fs::read(worktree.join("unfinished")).unwrap(), b"keep work");
    assert!(!exists(&recorded.quarantine()));
    assert_eq!(worktree_inventory(&repository), inventory);
    let mut forced = recorded.finalization();
    forced.force = true;
    forced.independent_worktree = true;
    sh_git(
        &repository,
        &[
            "worktree",
            "move",
            worktree.to_str().unwrap(),
            recorded.quarantine().to_str().unwrap(),
        ],
    );
    assert_eq!(
        finalize_removal(forced, &context())
            .unwrap()
            .receipt
            .as_deref(),
        Some(RECEIPT)
    );
    assert!(repository.is_dir() && sibling.is_dir());
    assert!(!exists(&recorded.quarantine()));
}

#[test]
fn checkout_quarantine_resumes_before_and_after_receipt_and_repeated_finalization_is_stable() {
    for with_receipt in [false, true] {
        let sandbox = Sandbox::new();
        let recorded = Recorded::new(&sandbox, sandbox.checkout("dev"), "checkout", None);
        recorded.prepare(DIGEST).unwrap();
        let mut input = recorded.finalization();
        let repeated = recorded.finalization();
        let paths = removal_paths(&sandbox.root, &recorded.operation, "7");
        fs::rename(&recorded.checkout, &paths.quarantine).unwrap();
        input.worktrees = encoded(&worktree_inventory(&paths.quarantine));
        if with_receipt {
            write_atomically(
                &paths.state,
                ".receipt.",
                &paths.receipt,
                RECEIPT.as_bytes(),
            )
            .unwrap();
        }
        assert_eq!(
            recorded.state(),
            if with_receipt {
                "receipt_pending_cleanup"
            } else {
                "quarantined"
            }
        );
        assert_eq!(
            finalize_removal(input, &context())
                .unwrap()
                .receipt
                .as_deref(),
            Some(RECEIPT)
        );
        assert!(!paths.quarantine.exists() && !sandbox.project.exists());
        assert_eq!(recorded.state(), "completed");
        assert_eq!(
            finalize_removal(repeated, &context())
                .unwrap()
                .receipt
                .as_deref(),
            Some(RECEIPT)
        );
        assert_eq!(
            fs::read(paths.receipt).unwrap(),
            format!("{RECEIPT}\n").as_bytes()
        );
    }
}
