//! Destructive-boundary and process tests belong beside the native implementation.
use super::recovery::tree_bytes;
use super::*;
use std::io::{BufRead, BufReader};
use std::process::{Child, Stdio};

#[test]
fn finalization_removes_only_empty_grouping_directories() {
    for remaining in ["empty", "sibling", "notes"] {
        let sandbox = Sandbox::new();
        let checkout = sandbox.checkout("dev");
        if remaining == "sibling" {
            sandbox.checkout("other");
        } else if remaining == "notes" {
            fs::write(sandbox.project.join("notes"), "keep\n").unwrap();
        }
        let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
        recorded.prepare(DIGEST).unwrap();
        let mut input = recorded.finalization();
        input.force = true;
        finalize_removal(input, &context()).unwrap();
        assert!(!checkout.exists());
        assert!(sandbox.root.is_dir());
        assert_eq!(sandbox.project.is_dir(), remaining != "empty");
        if remaining == "sibling" {
            assert!(sandbox.project.join("other/.git").is_dir());
        } else if remaining == "notes" {
            assert_eq!(fs::read(sandbox.project.join("notes")).unwrap(), b"keep\n");
        }
    }
}

#[test]
fn unresolved_and_absent_source_can_be_finalized_without_publication() {
    for state in ["unborn", "resolved", "absent"] {
        let sandbox = Sandbox::new();
        let checkout = sandbox.checkout("dev");
        let mut recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
        let mut input = recorded.finalization();
        input.force = true;
        input.unresolved = true;
        input.branch.clear();
        if state == "unborn" {
            sh_git(&checkout, &["symbolic-ref", "HEAD", "refs/heads/unborn"]);
            input.commit = ZERO_COMMIT.into();
        } else if state == "absent" {
            fs::remove_dir_all(&checkout).unwrap();
            input.commit = ZERO_COMMIT.into();
            input.source_identity = "absent".into();
            recorded.source_identity = "absent".into();
        }
        let mut inspection = inspect_input(&sandbox, &checkout);
        inspection.unresolved = true;
        inspection.allow_absent = true;
        inspection.inspect_content = false;
        inspection.branch.clear();
        let observed = InspectSource::run(inspection, &context()).unwrap();
        assert_eq!(decode(&observed.commit), input.commit.as_bytes());
        input.worktrees = observed.worktrees;
        recorded.prepare(DIGEST).unwrap();

        FinalizeRemoval::run_with(input, &|_, _, _| {
            panic!("failed creation must not query publication")
        })
        .unwrap();

        assert!(!checkout.exists());
        assert!(!sandbox.project.exists());
        assert_eq!(recorded.state(), "completed");
    }
}

#[test]
fn absent_source_refuses_a_new_directory_and_repeats_empty_parent_cleanup() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let mut recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    let input = || {
        let (user, group) = me();
        FinalizeRemovalInput {
            checkout: checkout.clone(),
            root: sandbox.root.clone(),
            common_repository: checkout.clone(),
            layout: "checkout".into(),
            branch: String::new(),
            operation: recorded.operation.clone(),
            member: "7".into(),
            digest: DIGEST.into(),
            receipt: RECEIPT.into(),
            force: true,
            user,
            group,
            source_identity: "absent".into(),
            origin: ORIGIN.into(),
            worktrees: String::new(),
            commit: ZERO_COMMIT.into(),
            grouping_directory: sandbox.project.clone(),
            unresolved: true,
            independent_worktree: false,
            own_units: vec![],
            git_read: GitRead::default(),
        }
    };
    fs::remove_dir_all(&checkout).unwrap();
    recorded.source_identity = "absent".into();
    recorded.prepare(DIGEST).unwrap();
    fs::create_dir(&checkout).unwrap();
    fs::write(checkout.join("foreign"), "appeared after acceptance").unwrap();
    assert_eq!(
        code(finalize_removal(input(), &context())),
        "instances.removal_refused"
    );
    assert_eq!(
        fs::read(checkout.join("foreign")).unwrap(),
        b"appeared after acceptance"
    );
    assert!(!removal_paths(&sandbox.root, &recorded.operation, "7")
        .receipt
        .exists());
    fs::remove_dir_all(&checkout).unwrap();
    finalize_removal(input(), &context()).unwrap();
    assert!(!sandbox.project.exists());
    fs::create_dir(&sandbox.project).unwrap();
    finalize_removal(input(), &context()).unwrap();
    assert!(!sandbox.project.exists());
}

#[test]
fn deletion_refuses_changed_checkout_or_grouping_ownership() {
    let Some(group) = rustix::process::getgroups()
        .unwrap()
        .into_iter()
        .find(|group| *group != rustix::process::getegid())
    else {
        eprintln!("ownership drift requires a supplementary group");
        return;
    };
    for durable in [false, true] {
        for grouping in [false, true] {
            let sandbox = Sandbox::new();
            let checkout = sandbox.checkout("dev");
            let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
            recorded.prepare(DIGEST).unwrap();
            let mut finalization = recorded.finalization();
            finalization.force = true;
            let mut direct = remove_input(&sandbox, &checkout, "dev");
            direct.force = true;
            let before = tree_bytes(&checkout);
            std::os::unix::fs::chown(
                if grouping {
                    &sandbox.project
                } else {
                    &checkout
                },
                None,
                Some(group.as_raw()),
            )
            .unwrap();
            let result = if durable {
                code(finalize_removal(finalization, &context()))
            } else {
                code(remove_source(direct, &context()))
            };
            assert_eq!(
                result,
                if durable {
                    "instances.removal_refused"
                } else {
                    "instances.ownership_mismatch"
                }
            );
            assert_eq!(tree_bytes(&checkout), before);
            assert!(!recorded.quarantine().exists());
        }
    }
}

#[test]
fn detached_checkouts_support_direct_and_recorded_removal() {
    for durable in [false, true] {
        let sandbox = Sandbox::new();
        let checkout = sandbox.checkout("dev");
        let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
        let mut finalization = recorded.finalization();
        sh_git(&checkout, &["checkout", "--quiet", "--detach"]);
        let mut inspection = inspect_input(&sandbox, &checkout);
        inspection.branch.clear();
        assert!(decode(&InspectSource::run(inspection, &context()).unwrap().branch).is_empty());
        if durable {
            recorded.prepare(DIGEST).unwrap();
            finalization.branch.clear();
            finalization.worktrees = encoded(&worktree_inventory(&checkout));
            finalize_removal(finalization, &context()).unwrap();
            assert_eq!(recorded.state(), "completed");
        } else {
            let mut input = remove_input(&sandbox, &checkout, "");
            input.force = true;
            remove_source(input, &context()).unwrap();
        }
        assert!(!checkout.exists());
        assert!(!sandbox.project.exists());
    }
}

#[test]
fn finalization_rechecks_publication_of_the_accepted_commit_before_moving_source() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    sh_git(
        &checkout,
        &[
            "commit",
            "--quiet",
            "--allow-empty",
            "-m",
            "New accepted head",
        ],
    );
    let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    let input = recorded.finalization();
    let before = tree_bytes(&checkout);
    assert_eq!(
        code(finalize_removal(input, &context())),
        "instances.removal_refused"
    );
    assert_eq!(tree_bytes(&checkout), before);
    assert!(!recorded.quarantine().exists());
    sh_git(
        &checkout,
        &[
            "push",
            "--quiet",
            sandbox.origin.to_str().unwrap(),
            "HEAD:accepted",
        ],
    );
    finalize_removal(recorded.finalization(), &context()).unwrap();
    assert_eq!(recorded.state(), "completed");
}

#[test]
fn finalization_requires_the_origin_from_the_latest_inspection() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
    recorded.prepare(DIGEST).unwrap();
    let input = recorded.finalization();
    let equivalent = "https://EXAMPLE.TEST:443/acme/site.git/";
    sh_git(&checkout, &["remote", "set-url", "origin", equivalent]);
    let before = tree_bytes(&checkout);
    assert_eq!(
        code(finalize_removal(input, &context())),
        "instances.removal_refused"
    );
    assert_eq!(tree_bytes(&checkout), before);
    let mut input = recorded.finalization();
    input.origin = equivalent.into();

    FinalizeRemoval::run_with(input, &|origin, _, _| {
        assert_eq!(origin, equivalent);
        Ok(true)
    })
    .unwrap();

    assert_eq!(recorded.state(), "completed");
    assert!(!checkout.exists());
}

#[test]
fn deletion_rechecks_replaced_paths_branches_origins_and_shared_administration() {
    for force in [false, true] {
        for (fault, expected) in [
            ("replacement", "instances.source_changed"),
            ("symlink", "instances.path_mismatch"),
            ("branch", "instances.branch_mismatch"),
            ("origin", "instances.origin_mismatch"),
            ("shared-git", "instances.layout_mismatch"),
        ] {
            let sandbox = Sandbox::new();
            let checkout = sandbox.checkout("dev");
            let mut input = remove_input(&sandbox, &checkout, "dev");
            input.force = force;
            let decoy = sandbox.checkout("decoy");
            match fault {
                "replacement" | "symlink" => {
                    fs::rename(&checkout, sandbox.base.join("original")).unwrap();
                    if fault == "replacement" {
                        sandbox.checkout("dev");
                    } else {
                        std::os::unix::fs::symlink(&decoy, &checkout).unwrap();
                    }
                }
                "branch" => {
                    sh_git(&checkout, &["checkout", "--quiet", "-b", "elsewhere"]);
                }
                "origin" => {
                    sh_git(
                        &checkout,
                        &[
                            "remote",
                            "set-url",
                            "origin",
                            "ssh://git@example.test/other/site.git",
                        ],
                    );
                }
                "shared-git" => {
                    fs::write(
                        checkout.join(".git/commondir"),
                        format!("{}\n", decoy.join(".git").display()),
                    )
                    .unwrap();
                }
                _ => unreachable!(),
            }
            let before = tree_bytes(&checkout);
            let decoy_before = tree_bytes(&decoy);
            assert_eq!(
                code(RemoveSource::run_with(input, &|_, _, _| panic!(
                    "identity refusal must precede publication"
                ))),
                expected,
                "{fault}, force {force}"
            );
            assert_eq!(tree_bytes(&checkout), before, "{fault}, force {force}");
            assert_eq!(tree_bytes(&decoy), decoy_before);
        }
    }
}

#[test]
fn inspection_and_current_publication_leave_the_index_refs_and_objects_unchanged() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(checkout.join("tracked"), "tracked\n").unwrap();
    sh_git(&checkout, &["add", "tracked"]);
    sh_git(&checkout, &["commit", "--quiet", "-m", "Tracked file"]);
    sh_git(
        &checkout,
        &[
            "push",
            "--quiet",
            sandbox.origin.to_str().unwrap(),
            "HEAD:main",
        ],
    );
    let head = Sandbox::head(&checkout);
    let publisher = sandbox.checkout("publisher");
    sh_git(
        &publisher,
        &[
            "commit",
            "--quiet",
            "--allow-empty",
            "-m",
            "Published descendant",
        ],
    );
    sh_git(
        &publisher,
        &[
            "push",
            "--quiet",
            sandbox.origin.to_str().unwrap(),
            "HEAD:new-tip",
        ],
    );
    sh_git(
        &sandbox.base,
        &[
            "--git-dir",
            sandbox.origin.to_str().unwrap(),
            "update-ref",
            "-d",
            "refs/heads/main",
        ],
    );
    let tip = Sandbox::head(&publisher);
    let contains_tip = || {
        Process::new("git")
            .args(["cat-file", "-e", &format!("{tip}^{{commit}}")])
            .current_dir(&checkout)
            .output()
            .unwrap()
            .status
            .success()
    };
    assert!(!contains_tip());
    fs::File::open(checkout.join("tracked"))
        .unwrap()
        .set_times(
            fs::FileTimes::new()
                .set_modified(std::time::SystemTime::now() + Duration::from_secs(10)),
        )
        .unwrap();
    let before = tree_bytes(&checkout.join(".git"));
    let index_time = fs::metadata(checkout.join(".git/index"))
        .unwrap()
        .modified()
        .unwrap();

    assert_eq!(
        decode(
            &InspectSource::run(inspect_input(&sandbox, &checkout), &context())
                .unwrap()
                .dirty
        ),
        b"0"
    );
    assert!(publication_at(&sandbox.root, ORIGIN, &head, &GitRead::default()).unwrap());
    assert!(!contains_tip());
    sh_git(
        &sandbox.base,
        &[
            "--git-dir",
            sandbox.origin.to_str().unwrap(),
            "update-ref",
            "-d",
            "refs/heads/new-tip",
        ],
    );
    assert_eq!(
        code(remove_source(
            remove_input(&sandbox, &checkout, "dev"),
            &context()
        )),
        "instances.unsafe_content"
    );

    assert_eq!(tree_bytes(&checkout.join(".git")), before);
    assert_eq!(
        fs::metadata(checkout.join(".git/index"))
            .unwrap()
            .modified()
            .unwrap(),
        index_time
    );
    assert_eq!(fs::read(checkout.join("tracked")).unwrap(), b"tracked\n");
}

#[test]
fn forced_removal_skips_content_and_publication_reads() {
    let sandbox = Sandbox::new();
    let checkout = sandbox.checkout("dev");
    fs::write(checkout.join("untracked"), "unpublished work").unwrap();
    let mut inspection = inspect_input(&sandbox, &checkout);
    inspection.inspect_content = false;
    assert!(decode(&InspectSource::run(inspection, &context()).unwrap().dirty).is_empty());
    let mut input = remove_input(&sandbox, &checkout, "dev");
    input.force = true;
    RemoveSource::run_with(input, &|_, _, _| panic!("force must not query publication")).unwrap();
    assert!(!checkout.exists());
}

#[test]
fn removal_enforces_the_shared_repository_identity_cases_in_both_modes() {
    let cases: serde_json::Value = serde_json::from_str(include_str!(
        "../../contract/fixtures/repository-identity.json"
    ))
    .unwrap();
    for case in cases.as_array().unwrap() {
        let origin = case["origin"].as_str().unwrap();
        let expected = case["node"].as_str();
        for force in [false, true] {
            let sandbox = Sandbox::new();
            let checkout = sandbox.checkout("dev");
            let mut input = remove_input(&sandbox, &checkout, "dev");
            input.force = force;
            input.repository_identity = expected.unwrap_or(IDENTITY).into();
            sh_git(&checkout, &["remote", "set-url", "origin", origin]);
            let config = fs::read(checkout.join(".git/config")).unwrap();
            let result = RemoveSource::run_with(input, &|observed, _, _| {
                assert!(!force, "forced removal must not query publication");
                assert_eq!(observed, origin);
                Ok(true)
            });
            assert_eq!(
                code(result),
                if expected.is_some() {
                    "ok"
                } else {
                    "instances.origin_mismatch"
                },
                "origin {origin:?}, force {force}"
            );
            if expected.is_none() {
                assert_eq!(fs::read(checkout.join(".git/config")).unwrap(), config);
            } else {
                assert!(!checkout.exists());
            }
        }
    }
}

#[test]
fn inspection_preserves_origin_linefeeds_and_deletion_refuses_them() {
    for origin in [ORIGIN, "https://example.test/acme/site.git"] {
        for force in [false, true] {
            let sandbox = Sandbox::new();
            let checkout = sandbox.checkout("dev");
            let mut removal = remove_input(&sandbox, &checkout, "dev");
            removal.force = force;
            sh_git(
                &checkout,
                &["remote", "set-url", "origin", &format!("{origin}\n")],
            );
            let config = fs::read(checkout.join(".git/config")).unwrap();
            let mut inspection = inspect_input(&sandbox, &checkout);
            inspection.inspect_content = !force;
            assert_eq!(
                decode(&InspectSource::run(inspection, &context()).unwrap().origin),
                format!("{origin}\n").as_bytes()
            );
            assert_eq!(
                code(RemoveSource::run_with(removal, &|_, _, _| panic!(
                    "invalid origin reached publication"
                ))),
                "instances.origin_mismatch"
            );
            assert_eq!(fs::read(checkout.join(".git/config")).unwrap(), config);
        }
    }
}

#[test]
fn removal_uses_the_configured_origin_despite_rewrites_and_accepts_equivalent_urls() {
    for force in [false, true] {
        for origin in [
            ORIGIN,
            "https://example.test/acme/site.git",
            "ssh://git@example.test/other/site.git",
        ] {
            let sandbox = Sandbox::new();
            let checkout = sandbox.checkout("dev");
            let mut input = remove_input(&sandbox, &checkout, "dev");
            input.force = force;
            sh_git(
                &checkout,
                &[
                    "config",
                    "url.https://rewritten.test/.insteadOf",
                    "ssh://git@example.test/",
                ],
            );
            sh_git(&checkout, &["remote", "set-url", "origin", origin]);
            assert_eq!(
                decode(
                    &InspectSource::run(inspect_input(&sandbox, &checkout), &context())
                        .unwrap()
                        .origin
                ),
                origin.as_bytes()
            );
            let result = RemoveSource::run_with(input, &|observed, _, _| {
                assert_eq!(observed, origin);
                assert!(!force);
                Ok(true)
            });
            if origin.contains("/other/") {
                assert_eq!(code(result), "instances.origin_mismatch");
                assert!(checkout.join(".git").is_dir());
            } else {
                result.unwrap();
                assert!(!checkout.exists());
            }
        }
    }
}

/// A shell waits on its open input pipe without creating children. The ready line acknowledges exec.
struct WaitingProcess(Child);

impl WaitingProcess {
    fn start(directory: &Path, executable: &Path) -> Self {
        let child = Process::new(executable)
            .args(["-c", "printf 'ready\\n'; read -r -t 60"])
            .current_dir(directory)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .spawn()
            .unwrap();
        let mut process = Self(child);
        let mut ready = String::new();
        BufReader::new(process.0.stdout.take().unwrap())
            .read_line(&mut ready)
            .unwrap();
        assert_eq!(ready, "ready\n");
        process
    }

    fn running(&mut self) -> bool {
        self.0.try_wait().unwrap().is_none()
    }
}

impl Drop for WaitingProcess {
    fn drop(&mut self) {
        let _ = self.0.kill();
        let _ = self.0.wait();
    }
}

fn copy_shell(path: &Path) {
    // Keep the writable executable descriptor out of concurrent test forks.
    assert!(Process::new("cp")
        .arg("/bin/bash")
        .arg(path)
        .status()
        .unwrap()
        .success());
}

#[test]
fn removal_stops_checkout_processes_and_preserves_siblings_and_terminal_servers() {
    for recorded_removal in [false, true] {
        let sandbox = Sandbox::new();
        let checkout = sandbox.checkout("dev");
        let sibling = sandbox.checkout("dev-sibling");
        let executable = checkout.join("private-shell");
        copy_shell(&executable);
        let mut stopped = [
            WaitingProcess::start(&checkout, Path::new("/bin/bash")),
            WaitingProcess::start(&sandbox.base, &executable),
        ];
        let mut kept = vec![
            WaitingProcess::start(&sibling, Path::new("/bin/bash")),
            WaitingProcess::start(&sandbox.base, Path::new("/bin/bash")),
        ];
        for name in ["tmux: server", "screen", "SCREEN"] {
            let executable = sandbox.base.join(name);
            copy_shell(&executable);
            kept.push(WaitingProcess::start(&checkout, &executable));
        }

        if recorded_removal {
            let recorded = Recorded::new(&sandbox, checkout.clone(), "checkout", None);
            recorded.prepare(DIGEST).unwrap();
            let mut input = recorded.finalization();
            input.force = true;
            finalize_removal(input, &context()).unwrap();
        } else {
            let mut input = remove_input(&sandbox, &checkout, "dev");
            input.force = true;
            remove_source(input, &context()).unwrap();
        }

        assert!(!checkout.exists());
        assert!(stopped.iter_mut().all(|process| !process.running()));
        assert!(kept.iter_mut().all(WaitingProcess::running));
        assert!(sibling.join(".git").is_dir());
    }
}
