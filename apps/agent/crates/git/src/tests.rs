//! Real Git on throwaway repositories.

use std::path::{Path, PathBuf};
use std::process::Command;

use serde_json::json;

use super::*;

struct Scratch {
    dir: tempfile::TempDir,
    system: NodeSystem,
}

fn scratch() -> Scratch {
    let dir = tempfile::tempdir().expect("scratch");
    std::fs::create_dir(dir.path().join("blobs")).expect("blobs");
    let system = NodeSystem::new(dir.path().join("blobs"));
    Scratch { dir, system }
}

impl Scratch {
    fn at(&self, name: &str) -> PathBuf {
        self.dir.path().join(name)
    }
}

/// Test-side Git with an identity, never the primitive's own call.
fn git(at: &Path, args: &[&str]) -> String {
    let out = Command::new(GIT)
        .env("GIT_CONFIG_GLOBAL", "/dev/null")
        .args([
            "-c",
            "user.name=T",
            "-c",
            "user.email=t@t",
            "-c",
            "init.defaultBranch=main",
            "-C",
        ])
        .arg(at)
        .args(args)
        .output()
        .expect("git");
    assert!(
        out.status.success(),
        "git {args:?}: {}",
        String::from_utf8_lossy(&out.stderr)
    );
    String::from_utf8_lossy(&out.stdout).trim().to_owned()
}

/// A work tree on `main` with two commits; answers both.
fn work(s: &Scratch) -> (PathBuf, String, String) {
    let tree = s.at("work");
    std::fs::create_dir(&tree).expect("work");
    git(&tree, &["init", "--quiet"]);
    std::fs::write(tree.join("a.txt"), "one\n").expect("file");
    git(&tree, &["add", "a.txt"]);
    git(&tree, &["commit", "--quiet", "-m", "one"]);
    let first = git(&tree, &["rev-parse", "HEAD"]);
    std::fs::write(tree.join("a.txt"), "two\n").expect("file");
    git(&tree, &["commit", "--quiet", "-am", "two"]);
    (tree.clone(), first, git(&tree, &["rev-parse", "HEAD"]))
}

fn code<T: std::fmt::Debug>(result: Result<T, Failure>) -> &'static str {
    result.expect_err("refused").code
}

#[test]
fn fetch_from_a_bundle_makes_the_repository_then_repeats_with_no_change() {
    let s = scratch();
    let (tree, _, second) = work(&s);
    let bundle = s.at("main.bundle");
    git(
        &tree,
        &[
            "bundle",
            "create",
            "--quiet",
            bundle.to_str().expect("path"),
            "main",
        ],
    );
    let repository = s.at("mirror.git");
    let input = json!({"repository": repository, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/main", "into": "refs/remotes/origin/main", "create": true});

    assert_eq!(
        fetch(&s.system, &input),
        Ok(fetch::Fetched {
            commit: Some(second.clone()),
            changes: 2
        })
    );
    assert_eq!(
        git(&repository, &["rev-parse", "refs/remotes/origin/main"]),
        second
    );
    assert_eq!(
        fetch(&s.system, &input),
        Ok(fetch::Fetched {
            commit: Some(second),
            changes: 0
        }),
        "a repeat changes nothing"
    );

    let lacking = json!({"repository": repository, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/gone", "into": "refs/heads/gone"});
    assert_eq!(
        fetch(&s.system, &lacking),
        Ok(fetch::Fetched {
            commit: None,
            changes: 0
        }),
        "a ref the source lacks changes nothing"
    );
}

#[test]
fn fetch_refuses_options_other_transports_and_missing_repositories() {
    let s = scratch();
    let repository = s.at("mirror.git");
    let with = |source: serde_json::Value, reference: &str, into: &str| json!({"repository": repository, "source": source, "ref": reference, "into": into, "create": true});
    for url in [
        "--upload-pack=touch /tmp/x",
        "ext::sh -c touch% /tmp/x",
        "file:///etc",
        "http://example.com/r.git",
        "https://user:pw@example.com/r.git",
        "https://example.com/r .git",
        "-https://x",
    ] {
        assert_eq!(
            code(fetch(
                &s.system,
                &with(
                    json!({"url": {"url": url}}),
                    "refs/heads/main",
                    "refs/heads/main"
                )
            )),
            "git.input_invalid",
            "{url}"
        );
    }
    for reference in [
        "--all",
        "-x",
        "main",
        "refs/heads/../x",
        "refs/heads/a b",
        "refs/heads/x.lock",
        "refs/heads/@{1}",
        "refs/.hidden/x",
    ] {
        assert_eq!(
            code(fetch(
                &s.system,
                &with(
                    json!({"bundle": {"path": "/tmp/b"}}),
                    reference,
                    "refs/heads/main"
                )
            )),
            "git.input_invalid",
            "{reference}"
        );
        assert_eq!(
            code(fetch(
                &s.system,
                &with(
                    json!({"bundle": {"path": "/tmp/b"}}),
                    "refs/heads/main",
                    reference
                )
            )),
            "git.input_invalid",
            "{reference}"
        );
    }
    for bad in ["relative", "/a/../b", "/a/./b", "/a//b", "/a/"] {
        assert_eq!(
            code(fetch(
                &s.system,
                &with(
                    json!({"bundle": {"path": bad}}),
                    "refs/heads/main",
                    "refs/heads/main"
                )
            )),
            "git.input_invalid",
            "{bad}"
        );
    }
    assert_eq!(
        code(fetch(
            &s.system,
            &json!({"repository": repository, "source": {"bundle": {"path": "/b"}}, "ref": "refs/heads/m", "into": "refs/heads/m", "argv": ["x"]})
        )),
        "git.input_invalid",
        "unknown field"
    );
    let credential = json!({"url": {"url": "https://example.com/r.git", "credential": "https://u:token@example.com"}});
    assert_eq!(
        code(fetch(
            &s.system,
            &with(credential, "refs/heads/main", "refs/heads/main")
        )),
        "git.input_invalid",
        "an inline secret refuses"
    );
    let missing = json!({"url": {"url": "https://example.com/r.git", "credential": {"blob": {"sha256": "a".repeat(64)}}}});
    assert_eq!(
        code(fetch(
            &s.system,
            &with(missing, "refs/heads/main", "refs/heads/main")
        )),
        "git.credential_missing"
    );
}

#[test]
fn fetch_refuses_a_missing_bundle_and_a_missing_repository_without_create() {
    let s = scratch();
    let repository = s.at("mirror.git");
    let no_bundle = json!({"repository": repository, "source": {"bundle": {"path": "/nonexistent.bundle"}}, "ref": "refs/heads/main", "into": "refs/heads/main", "create": true});
    assert_eq!(code(fetch(&s.system, &no_bundle)), "git.fetch_failed");
    std::fs::write(s.at("plain"), "x").expect("plain");
    let absent = json!({"repository": repository, "source": {"bundle": {"path": s.at("plain")}}, "ref": "refs/heads/main", "into": "refs/heads/main"});
    assert_eq!(code(fetch(&s.system, &absent)), "git.repository_missing");
    assert!(!repository.exists(), "nothing was made");
}

#[test]
fn fetch_refuses_a_folder_that_is_no_repository_top_a_folder_as_bundle_and_an_unreachable_remote() {
    let s = scratch();
    // A folder in a repository that is not its own top refuses: discovery never climbs to the parent repository.
    let (tree, _, _) = work(&s);
    std::fs::create_dir(tree.join("inner")).expect("inner");
    let bundle = s.at("b.bundle");
    git(
        &tree,
        &[
            "bundle",
            "create",
            "--quiet",
            bundle.to_str().expect("path"),
            "main",
        ],
    );
    let inner = json!({"repository": tree.join("inner"), "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    assert_eq!(code(fetch(&s.system, &inner)), "git.fetch_failed");
    // A repository folder given as the bundle is no plain file.
    let folder = json!({"repository": tree, "source": {"bundle": {"path": tree}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    assert_eq!(code(fetch(&s.system, &folder)), "git.input_invalid");
    // An unreachable https remote fails; the credential file never stays.
    let remote = json!({"repository": tree, "source": {"url": {"url": "https://127.0.0.1:9/r.git"}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    assert_eq!(code(fetch(&s.system, &remote)), "git.fetch_failed");
}

#[test]
fn checkout_renames_switches_creates_resets_and_cleans_then_repeats_with_no_change() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    let order = |value: serde_json::Value| checkout(&s.system, &value);

    let renamed = order(json!({"worktree": tree, "branch": "task-1", "rename_from": "main"}))
        .expect("renamed");
    assert_eq!((renamed.branch.as_str(), renamed.changes), ("task-1", 1));
    assert_eq!(git(&tree, &["branch", "--show-current"]), "task-1");
    assert_eq!(
        order(json!({"worktree": tree, "branch": "task-1", "rename_from": "main"}))
            .expect("repeat")
            .changes,
        0
    );

    let made = order(json!({"worktree": tree, "branch": "old", "start": first})).expect("made");
    assert_eq!((made.head.as_str(), made.changes), (first.as_str(), 1));
    assert_eq!(
        order(json!({"worktree": tree, "branch": "task-1"}))
            .expect("switched")
            .head,
        second
    );

    std::fs::write(tree.join("a.txt"), "local\n").expect("dirty");
    std::fs::write(tree.join("new.txt"), "untracked\n").expect("untracked");
    std::fs::create_dir(tree.join("dir"))
        .and_then(|()| std::fs::write(tree.join("dir/f"), "x"))
        .expect("untracked dir");
    let destroy = json!({"worktree": tree, "branch": "task-1", "reset": first, "clean": true, "destructive": true});
    let reset = order(destroy.clone()).expect("reset");
    assert_eq!((reset.head.as_str(), reset.changes), (first.as_str(), 2));
    assert_eq!(
        std::fs::read_to_string(tree.join("a.txt")).expect("a"),
        "one\n"
    );
    assert!(
        !tree.join("new.txt").exists() && !tree.join("dir").exists(),
        "clean removed untracked files"
    );
    assert_eq!(
        order(destroy).expect("repeat").changes,
        0,
        "a repeat changes nothing"
    );
}

#[test]
fn checkout_refuses_destruction_without_its_flag_and_options_as_values() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    std::fs::write(tree.join("keep.txt"), "mine\n").expect("untracked");
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "clean": true})
        )),
        "git.destructive_not_allowed"
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "reset": first})
        )),
        "git.destructive_not_allowed"
    );
    assert!(tree.join("keep.txt").exists(), "nothing was removed");
    for name in ["-f", "--orphan", "a..b", "HEAD@{1}", "x.lock", "a b"] {
        assert_eq!(
            code(checkout(
                &s.system,
                &json!({"worktree": tree, "branch": name})
            )),
            "git.input_invalid",
            "{name}"
        );
        assert_eq!(
            code(checkout(
                &s.system,
                &json!({"worktree": tree, "branch": "x", "rename_from": name})
            )),
            "git.input_invalid",
            "{name}"
        );
    }
    for start in ["--detach", "HEAD", "main", "abc"] {
        assert_eq!(
            code(checkout(
                &s.system,
                &json!({"worktree": tree, "branch": "x", "start": start})
            )),
            "git.input_invalid",
            "{start}"
        );
    }
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "missing"})
        )),
        "git.checkout_failed",
        "no start for a missing branch"
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "x", "force": true})
        )),
        "git.input_invalid",
        "unknown field"
    );
    assert_eq!(git(&tree, &["branch", "--show-current"]), "main");
}

#[test]
fn ref_ensure_compares_and_swaps_then_repeats_with_no_change() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    let order = |value: serde_json::Value| ref_ensure(&s.system, &value);

    let made = order(json!({"repository": tree, "ref": "refs/orbit/base", "state": "present", "commit": first, "expect": null})).expect("made");
    assert_eq!(
        made,
        reference::RefEnsured {
            commit: Some(first.clone()),
            changes: 1
        }
    );
    let moved = json!({"repository": tree, "ref": "refs/orbit/base", "state": "present", "commit": second, "expect": first});
    assert_eq!(order(moved.clone()).expect("moved").changes, 1);
    assert_eq!(
        order(moved).expect("repeat").changes,
        0,
        "a repeat changes nothing"
    );
    assert_eq!(git(&tree, &["rev-parse", "refs/orbit/base"]), second);

    // The ref holds neither the desired nor the expected value: refused, unchanged.
    assert_eq!(
        code(order(
            json!({"repository": tree, "ref": "refs/orbit/base", "state": "present", "commit": first, "expect": null})
        )),
        "git.ref_conflict"
    );
    assert_eq!(
        code(order(
            json!({"repository": tree, "ref": "refs/orbit/base", "state": "absent", "expect": first})
        )),
        "git.ref_conflict"
    );
    assert_eq!(git(&tree, &["rev-parse", "refs/orbit/base"]), second);

    let gone =
        json!({"repository": tree, "ref": "refs/orbit/base", "state": "absent", "expect": second});
    assert_eq!(
        order(gone.clone()).expect("deleted"),
        reference::RefEnsured {
            commit: None,
            changes: 1
        }
    );
    assert_eq!(
        order(gone).expect("repeat"),
        reference::RefEnsured {
            commit: None,
            changes: 0
        }
    );
}

#[test]
fn ref_ensure_refuses_bad_input() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    let with = |value: serde_json::Value| code(ref_ensure(&s.system, &value));
    assert_eq!(
        with(json!({"repository": tree, "ref": "refs/x", "state": "present", "commit": first})),
        "git.input_invalid",
        "expect is required"
    );
    for reference in ["--stdin", "-d", "HEAD", "refs/x/../y", "refs/x y"] {
        assert_eq!(
            with(
                json!({"repository": tree, "ref": reference, "state": "present", "commit": first, "expect": null})
            ),
            "git.input_invalid",
            "{reference}"
        );
    }
    for value in [
        "HEAD",
        "main",
        "--stdin",
        &first[..12],
        &first.to_uppercase(),
    ] {
        assert_eq!(
            with(
                json!({"repository": tree, "ref": "refs/x", "state": "present", "commit": value, "expect": null})
            ),
            "git.input_invalid",
            "{value}"
        );
    }
    assert_eq!(
        with(
            json!({"repository": tree, "ref": "refs/x", "state": "absent", "commit": first, "expect": null})
        ),
        "git.input_invalid"
    );
    assert_eq!(
        with(json!({"repository": tree, "ref": "refs/x", "state": "present", "expect": null})),
        "git.input_invalid"
    );
    // A commit the repository lacks fails Git's own check and leaves no ref.
    assert_eq!(
        with(
            json!({"repository": tree, "ref": "refs/x", "state": "present", "commit": "1".repeat(40), "expect": null})
        ),
        "git.ref_failed"
    );
    assert!(resolve(&s.system, "t", &tree, "refs/x")
        .expect("read")
        .is_none());
}

#[test]
fn worktree_read_counts_changes_and_ahead_behind_and_writes_nothing() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    git(&tree, &["update-ref", "refs/remotes/origin/main", &first]);
    std::fs::write(tree.join("a.txt"), "changed\n").expect("unstaged");
    std::fs::write(tree.join("b.txt"), "b\n").expect("staged");
    git(&tree, &["add", "b.txt"]);
    std::fs::write(tree.join("c.txt"), "c\n").expect("untracked");
    let index = std::fs::read(tree.join(".git/index")).expect("index");

    let read = worktree_read(
        &s.system,
        &json!({"worktree": tree, "against": "refs/remotes/origin/main"}),
    )
    .expect("read");
    assert_eq!(
        read,
        read::Worktree::Present {
            head: Some(second),
            branch: Some("main".to_owned()),
            staged: 1,
            unstaged: 1,
            unmerged: 0,
            untracked: 1,
            ahead: Some(1),
            behind: Some(0)
        }
    );
    assert_eq!(
        std::fs::read(tree.join(".git/index")).expect("index"),
        index,
        "the read wrote nothing"
    );
    assert_eq!(
        serde_json::to_value(worktree_read(&s.system, &json!({"worktree": tree})).expect("read"))
            .expect("json")["state"],
        "present"
    );
    assert!(serde_json::to_value(
        worktree_read(&s.system, &json!({"worktree": tree})).expect("read")
    )
    .expect("json")
    .get("ahead")
    .is_none());

    assert_eq!(
        worktree_read(&s.system, &json!({"worktree": s.at("none")})),
        Ok(read::Worktree::Absent)
    );
    std::fs::create_dir(tree.join("sub")).expect("sub");
    assert_eq!(
        code(worktree_read(
            &s.system,
            &json!({"worktree": tree.join("sub")})
        )),
        "git.read_failed",
        "not the work tree's own folder"
    );
    assert_eq!(
        code(worktree_read(
            &s.system,
            &json!({"worktree": tree, "against": "--all"})
        )),
        "git.input_invalid"
    );
}
