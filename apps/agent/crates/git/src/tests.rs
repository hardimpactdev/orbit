//! Real Git on throwaway repositories.

use std::path::{Path, PathBuf};
use std::process::Command;

use serde_json::json;

use std::os::unix::ffi::OsStrExt as _;

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
    let together = json!({"worktree": tree, "branch": "task-1", "reset": first, "clean": true, "destructive": true});
    assert_eq!(
        code(order(together)),
        "git.input_invalid",
        "clean is an order of its own"
    );
    let reset = json!({"worktree": tree, "branch": "task-1", "reset": first, "destructive": true});
    let clean = json!({"worktree": tree, "branch": "task-1", "clean": true, "destructive": true});
    assert_eq!(order(reset.clone()).expect("reset").changes, 1);
    let cleaned = order(clean.clone()).expect("clean");
    assert_eq!(
        (cleaned.head.as_str(), cleaned.changes),
        (first.as_str(), 1)
    );
    assert_eq!(
        std::fs::read_to_string(tree.join("a.txt")).expect("a"),
        "one\n"
    );
    assert!(
        !tree.join("new.txt").exists() && !tree.join("dir").exists(),
        "clean removed untracked files"
    );
    assert_eq!(
        order(reset).expect("repeat").changes,
        0,
        "a repeat changes nothing"
    );
    assert_eq!(
        order(clean).expect("repeat").changes,
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

/// Every file below a folder with its modification time and length, to prove a read wrote nothing.
fn snapshot(folder: &Path) -> Vec<(PathBuf, std::time::SystemTime, u64)> {
    let mut found = Vec::new();
    let mut pending = vec![folder.to_path_buf()];
    while let Some(next) = pending.pop() {
        for entry in std::fs::read_dir(&next).expect("folder").flatten() {
            let meta = entry.metadata().expect("meta");
            found.push((entry.path(), meta.modified().expect("mtime"), meta.len()));
            if meta.is_dir() {
                pending.push(entry.path());
            }
        }
    }
    found.sort();
    found
}

#[test]
fn worktree_read_counts_changes_heads_stashes_and_ahead_behind_and_writes_nothing() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    git(&tree, &["update-ref", "refs/remotes/origin/main", &first]);
    git(&tree, &["branch", "side", &first]);
    std::fs::write(tree.join("a.txt"), "stashed\n").expect("stash");
    git(&tree, &["stash", "--quiet"]);
    std::fs::write(tree.join("a.txt"), "changed\n").expect("unstaged");
    std::fs::write(tree.join("b.txt"), "b\n").expect("staged");
    git(&tree, &["add", "b.txt"]);
    std::fs::write(tree.join("c.txt"), "c\n").expect("untracked");
    let before = snapshot(&tree.join(".git"));
    std::thread::sleep(std::time::Duration::from_millis(20));

    let read = worktree_read(
        &s.system,
        &json!({"worktree": tree, "against": "refs/remotes/origin/main"}),
    )
    .expect("read");
    let read::Worktree::Present {
        head,
        branch,
        staged,
        unstaged,
        unmerged,
        modified,
        untracked,
        stashes,
        heads,
        ahead,
        behind,
    } = read
    else {
        panic!("present")
    };
    assert_eq!(
        (head.as_deref(), branch.as_deref()),
        (Some(second.as_str()), Some("main"))
    );
    assert_eq!(
        (staged, unstaged, unmerged, modified.count, untracked.count),
        (1, 1, 0, 2, 1)
    );
    assert_eq!(
        (untracked.paths.as_slice(), untracked.truncated),
        (&["c.txt".to_owned()][..], false)
    );
    assert_eq!(
        (modified.paths.as_slice(), modified.truncated),
        (&["a.txt".to_owned(), "b.txt".to_owned()][..], false)
    );
    let stash = git(&tree, &["rev-parse", "refs/stash"]);
    assert_eq!(stashes.len(), 1);
    assert_eq!(stashes[0].commit, stash);
    assert!(stashes[0].label.starts_with("stash@{0}: "), "{stashes:?}");
    assert_eq!(heads.get("refs/heads/side"), Some(&first));
    assert_eq!(heads.get("refs/heads/main"), Some(&second));
    assert_eq!((ahead, behind), (Some(1), Some(0)));
    assert_eq!(
        snapshot(&tree.join(".git")),
        before,
        "the read wrote nothing under .git"
    );

    let clean = worktree_read(&s.system, &json!({"worktree": s.at("none")})).expect("absent");
    assert_eq!(clean, read::Worktree::Absent);
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

#[test]
fn worktree_read_refuses_a_split_index_and_symbolic_branches() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    git(
        &tree,
        &["symbolic-ref", "refs/heads/alias", "refs/heads/main"],
    );
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.ref_symbolic"
    );
    git(&tree, &["symbolic-ref", "--delete", "refs/heads/alias"]);
    git(&tree, &["update-index", "--split-index"]);
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.repository_refused",
        "a shared index file refuses"
    );
}

#[test]
fn repositories_whose_configuration_can_redirect_or_run_a_program_refuse() {
    for (key, value) in [
        (
            "url.https://evil.example/.insteadOf",
            "https://example.com/",
        ),
        ("protocol.ext.allow", "always"),
        ("filter.x.smudge", "touch /tmp/pwned"),
        ("core.sshCommand", "touch /tmp/pwned"),
        ("core.fsmonitor", "touch /tmp/pwned"),
        ("diff.external", "touch /tmp/pwned"),
        ("credential.helper", "!touch /tmp/pwned"),
        ("include.path", "/tmp/other"),
    ] {
        let s = scratch();
        let (tree, first, _) = work(&s);
        git(&tree, &["config", key, value]);
        assert_eq!(
            code(worktree_read(&s.system, &json!({"worktree": tree}))),
            "git.repository_refused",
            "{key}"
        );
        assert_eq!(
            code(checkout(
                &s.system,
                &json!({"worktree": tree, "branch": "main"})
            )),
            "git.repository_refused",
            "{key}"
        );
        assert_eq!(
            code(ref_ensure(
                &s.system,
                &json!({"repository": tree, "ref": "refs/x", "state": "present", "commit": first, "expect": null})
            )),
            "git.repository_refused",
            "{key}"
        );
    }
}

#[test]
fn ref_ensure_refuses_the_null_id_and_symbolic_refs() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    for zero in ["0".repeat(40), "0".repeat(64)] {
        assert_eq!(
            code(ref_ensure(
                &s.system,
                &json!({"repository": tree, "ref": "refs/x", "state": "present", "commit": zero, "expect": null})
            )),
            "git.input_invalid"
        );
        assert_eq!(
            code(ref_ensure(
                &s.system,
                &json!({"repository": tree, "ref": "refs/heads/main", "state": "absent", "expect": zero})
            )),
            "git.input_invalid",
            "the null id is no expect"
        );
    }
    git(
        &tree,
        &["symbolic-ref", "refs/heads/alias", "refs/heads/main"],
    );
    let main = git(&tree, &["rev-parse", "refs/heads/main"]);
    assert_eq!(
        code(ref_ensure(
            &s.system,
            &json!({"repository": tree, "ref": "refs/heads/alias", "state": "present", "commit": first, "expect": main})
        )),
        "git.ref_symbolic"
    );
    assert_eq!(
        code(ref_ensure(
            &s.system,
            &json!({"repository": tree, "ref": "refs/heads/alias", "state": "absent", "expect": main})
        )),
        "git.ref_symbolic"
    );
    assert_eq!(
        git(&tree, &["rev-parse", "refs/heads/main"]),
        main,
        "the referent never moved"
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "alias"})
        )),
        "git.ref_symbolic"
    );
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
    let into = json!({"repository": tree, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/main", "into": "refs/heads/alias"});
    assert_eq!(code(fetch(&s.system, &into)), "git.ref_symbolic");
}

#[test]
fn checkout_never_overwrites_ignored_or_untracked_paths_nor_removes_a_nested_repository() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    // The other branch tracks `build.log`, which is an ignored local file on main.
    git(&tree, &["switch", "--quiet", "-c", "other"]);
    std::fs::write(tree.join("build.log"), "tracked\n").expect("tracked");
    git(&tree, &["add", "build.log"]);
    git(&tree, &["commit", "--quiet", "-m", "log"]);
    git(&tree, &["switch", "--quiet", "main"]);
    std::fs::write(tree.join(".git/info/exclude"), "build.log\n").expect("exclude");
    std::fs::write(tree.join("build.log"), "mine\n").expect("ignored");
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "other"})
        )),
        "git.checkout_refused"
    );
    assert_eq!(
        std::fs::read_to_string(tree.join("build.log")).expect("kept"),
        "mine\n"
    );
    let other = git(&tree, &["rev-parse", "other"]);
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "reset": other, "destructive": true})
        )),
        "git.checkout_refused",
        "reset over an ignored file"
    );
    std::fs::remove_file(tree.join("build.log")).expect("gone");

    // A nested repository: never removed by clean, and a reset whose tree needs its place refuses.
    let nested = tree.join("vendor");
    std::fs::create_dir(&nested).expect("nested");
    git(&nested, &["init", "--quiet"]);
    std::fs::write(nested.join("keep"), "x").expect("nested file");
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true})
        )),
        "git.nested_repository",
        "a clean with another repository among the untracked paths refuses"
    );
    assert!(nested.join("keep").exists(), "the nested repository stays");
    assert_eq!(first.len(), second.len());
}

#[test]
fn checkout_reset_refuses_submodules_and_skip_worktree_and_an_unborn_branch() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    git(&tree, &["update-index", "--skip-worktree", "a.txt"]);
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "reset": first, "destructive": true})
        )),
        "git.checkout_refused"
    );
    git(&tree, &["update-index", "--no-skip-worktree", "a.txt"]);
    git(&tree, &["update-index", "--assume-unchanged", "a.txt"]);
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true})
        )),
        "git.checkout_refused"
    );
    git(&tree, &["update-index", "--no-assume-unchanged", "a.txt"]);
    git(
        &tree,
        &[
            "update-index",
            "--add",
            "--cacheinfo",
            &format!("160000,{first},sub"),
        ],
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "reset": first, "destructive": true})
        )),
        "git.checkout_refused",
        "a submodule"
    );

    let empty = s.at("empty");
    std::fs::create_dir(&empty).expect("empty");
    git(&empty, &["init", "--quiet"]);
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": empty, "branch": "main"})
        )),
        "git.checkout_refused",
        "HEAD names an unborn branch"
    );
}

#[test]
fn a_fetch_reads_the_source_first_and_makes_its_repository_in_the_source_format() {
    let s = scratch();
    let source = s.at("sha256");
    std::fs::create_dir(&source).expect("source");
    git(&source, &["init", "--quiet", "--object-format=sha256"]);
    git(
        &source,
        &["commit", "--quiet", "--allow-empty", "-m", "one"],
    );
    let bundle = s.at("sha256.bundle");
    git(
        &source,
        &[
            "bundle",
            "create",
            "--quiet",
            bundle.to_str().expect("path"),
            "main",
        ],
    );
    let repository = s.at("mirror.git");

    let lacking = json!({"repository": repository, "create": true, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/gone", "into": "refs/heads/gone"});
    assert_eq!(
        fetch(&s.system, &lacking),
        Ok(fetch::Fetched {
            commit: None,
            changes: 0
        })
    );
    assert!(
        !repository.exists(),
        "a source without the ref leaves no repository"
    );

    let wanted = json!({"repository": repository, "create": true, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/main", "into": "refs/heads/main"});
    let fetched = fetch(&s.system, &wanted).expect("fetched");
    assert_eq!(
        (fetched.commit.as_deref().map(str::len), fetched.changes),
        (Some(64), 2)
    );
    assert_eq!(
        git(&repository, &["rev-parse", "--show-object-format"]),
        "sha256"
    );
}

#[test]
fn credentials_never_reach_a_file_an_error_or_a_url() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    let line = b"https://x-access-token:hunter2secret@127.0.0.1:9\n";
    let digest = {
        use sha2::Digest as _;
        sha2::Sha256::digest(line)
            .iter()
            .fold(String::new(), |mut hex, byte| {
                let _ = std::fmt::Write::write_fmt(&mut hex, format_args!("{byte:02x}"));
                hex
            })
    };
    std::fs::write(s.at("blobs").join(&digest), line).expect("blob");
    let before = snapshot(&tree);
    let order = json!({"repository": tree, "source": {"url": {"url": "https://127.0.0.1:9/r.git", "credential": {"blob": {"sha256": digest}}}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    let failure = fetch(&s.system, &order).expect_err("unreachable");
    assert_eq!(failure.code, "git.fetch_failed");
    assert!(
        !failure.detail.contains("hunter2secret"),
        "{}",
        failure.detail
    );
    assert_eq!(
        snapshot(&tree),
        before,
        "no credential file in the repository"
    );

    let other = json!({"repository": tree, "source": {"url": {"url": "https://example.com/r.git", "credential": {"blob": {"sha256": digest}}}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    assert_eq!(
        code(fetch(&s.system, &other)),
        "git.input_invalid",
        "a credential for another host refuses"
    );
    for url in [
        "https://example.com/r.git?token=secret",
        "https://example.com/r.git#x",
        "https://u@example.com/r.git",
        "https://example.com/%2e%2e/r.git",
    ] {
        let order = json!({"repository": tree, "source": {"url": {"url": url}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
        let failure = fetch(&s.system, &order).expect_err("refused");
        assert_eq!(failure.code, "git.input_invalid", "{url}");
        assert!(!failure.detail.contains("secret"), "{}", failure.detail);
    }
    let inline = json!({"repository": tree, "source": {"url": {"url": "https://example.com/r.git", "credential": "https://u:inlinesecret@example.com"}}, "ref": "refs/heads/main", "into": "refs/heads/x"});
    let failure = fetch(&s.system, &inline).expect_err("refused");
    assert!(
        !failure.detail.contains("inlinesecret"),
        "serde's echo is dropped: {}",
        failure.detail
    );
    assert_eq!(
        redact("fatal: https://u:tok@host/x and tok", &["tok"]),
        "fatal: https://***@host/x and ***"
    );
}

#[test]
fn a_reset_never_removes_a_folder_or_repository_that_replaced_a_tracked_file() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    // The tracked `a.txt` is replaced on disk by a folder holding a repository with committed work.
    std::fs::remove_file(tree.join("a.txt")).expect("gone");
    let nested = tree.join("a.txt");
    std::fs::create_dir(&nested).expect("folder");
    git(&nested, &["init", "--quiet"]);
    std::fs::write(nested.join("work.txt"), "mine\n").expect("work");
    git(&nested, &["add", "work.txt"]);
    git(&nested, &["commit", "--quiet", "-m", "committed work"]);
    let order = json!({"worktree": tree, "branch": "main", "reset": first, "destructive": true});
    assert_eq!(code(checkout(&s.system, &order)), "git.nested_repository");
    assert!(
        nested.join("work.txt").exists() && nested.join(".git").exists(),
        "the nested repository stays"
    );

    // Ordinary untracked contents in that folder refuse too.
    std::fs::remove_dir_all(&nested).expect("reset fixture");
    std::fs::create_dir(&nested).expect("folder");
    std::fs::write(nested.join("note"), "mine\n").expect("note");
    assert_eq!(code(checkout(&s.system, &order)), "git.checkout_refused");
    assert!(nested.join("note").exists());
}

#[test]
fn a_clean_refuses_a_bare_repository_or_a_deeper_nested_one_before_removing_anything() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    std::fs::write(tree.join("junk.txt"), "x").expect("junk");
    let bare = tree.join("backup.git");
    git(
        &tree,
        &["init", "--quiet", "--bare", bare.to_str().expect("path")],
    );
    let order = json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true});
    assert_eq!(
        code(checkout(&s.system, &order)),
        "git.nested_repository",
        "a bare repository"
    );
    assert!(
        bare.join("HEAD").exists() && tree.join("junk.txt").exists(),
        "nothing was removed"
    );
    std::fs::remove_dir_all(&bare).expect("gone");

    let deep = tree.join("outer/inner");
    std::fs::create_dir_all(&deep).expect("deep");
    git(&deep, &["init", "--quiet"]);
    std::fs::write(tree.join("outer/file"), "x").expect("file");
    assert_eq!(
        code(checkout(&s.system, &order)),
        "git.nested_repository",
        "a deeper nested repository"
    );
    assert!(
        deep.join(".git").exists() && tree.join("junk.txt").exists(),
        "nothing was removed"
    );
    std::fs::remove_dir_all(tree.join("outer")).expect("gone");
    assert_eq!(checkout(&s.system, &order).expect("clean").changes, 1);
    assert_eq!(checkout(&s.system, &order).expect("repeat").changes, 0);
}

#[test]
fn a_switch_beside_an_untracked_sibling_in_a_shared_folder_succeeds() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    git(&tree, &["switch", "--quiet", "-c", "other"]);
    std::fs::create_dir(tree.join("share")).expect("share");
    std::fs::write(tree.join("share/upstream"), "u\n").expect("upstream");
    git(&tree, &["add", "share/upstream"]);
    git(&tree, &["commit", "--quiet", "-m", "upstream"]);
    git(&tree, &["switch", "--quiet", "main"]);
    std::fs::create_dir_all(tree.join("share")).expect("share");
    std::fs::write(tree.join("share/local"), "mine\n").expect("local");
    let switched =
        checkout(&s.system, &json!({"worktree": tree, "branch": "other"})).expect("switch");
    assert_eq!(switched.changes, 1);
    assert!(tree.join("share/local").exists() && tree.join("share/upstream").exists());
}

#[test]
fn checkout_validates_the_branch_head_names_itself_not_its_referent() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    git(
        &tree,
        &["symbolic-ref", "refs/heads/alias", "refs/heads/main"],
    );
    git(&tree, &["symbolic-ref", "HEAD", "refs/heads/alias"]);
    let main = git(&tree, &["rev-parse", "refs/heads/main"]);
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main"})
        )),
        "git.ref_symbolic"
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "main", "reset": first, "destructive": true})
        )),
        "git.ref_symbolic"
    );
    assert_eq!(
        code(checkout(
            &s.system,
            &json!({"worktree": tree, "branch": "renamed", "rename_from": "main"})
        )),
        "git.ref_symbolic"
    );
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.ref_symbolic"
    );
    assert_eq!(
        git(&tree, &["rev-parse", "refs/heads/main"]),
        main,
        "the referent never moved"
    );
}

#[test]
fn a_reset_to_a_commit_with_a_submodule_refuses_before_any_change() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    git(
        &tree,
        &[
            "update-index",
            "--add",
            "--cacheinfo",
            &format!("160000,{first},sub"),
        ],
    );
    git(&tree, &["commit", "--quiet", "-m", "submodule"]);
    let with = git(&tree, &["rev-parse", "HEAD"]);
    git(&tree, &["reset", "--quiet", "--hard", &second]);
    let order = json!({"worktree": tree, "branch": "main", "reset": with, "destructive": true});
    assert_eq!(code(checkout(&s.system, &order)), "git.checkout_refused");
    assert_eq!(git(&tree, &["rev-parse", "HEAD"]), second, "nothing moved");
}

#[test]
fn a_fetch_that_makes_a_child_of_a_repository_never_reads_that_repository_configuration() {
    let s = scratch();
    let (tree, _, second) = work(&s);
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
    // The parent's own configuration rewrites the bundle path to a source that does not exist.
    git(
        &tree,
        &[
            "config",
            "url./nonexistent.bundle.insteadOf",
            bundle.to_str().expect("path"),
        ],
    );
    let child = tree.join("mirror.git");
    let order = json!({"repository": child, "create": true, "source": {"bundle": {"path": bundle}}, "ref": "refs/heads/main", "into": "refs/heads/main"});
    assert_eq!(
        fetch(&s.system, &order),
        Ok(fetch::Fetched {
            commit: Some(second),
            changes: 2
        })
    );
}

#[test]
fn a_read_returns_every_branch_by_its_full_name_and_refuses_only_an_active_split_index() {
    let s = scratch();
    let (tree, first, second) = work(&s);
    git(&tree, &["update-ref", "refs/heads/refs/heads/main", &first]);
    let read::Worktree::Present { heads, .. } =
        worktree_read(&s.system, &json!({"worktree": tree})).expect("read")
    else {
        panic!("present")
    };
    assert_eq!(heads.get("refs/heads/main"), Some(&second));
    assert_eq!(
        heads.get("refs/heads/refs/heads/main"),
        Some(&first),
        "no name collapses: {heads:?}"
    );

    git(&tree, &["update-index", "--split-index"]);
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.repository_refused"
    );
    git(&tree, &["update-index", "--no-split-index"]);
    git(&tree, &["config", "core.splitIndex", "false"]);
    assert!(
        worktree_read(&s.system, &json!({"worktree": tree})).is_ok(),
        "a leftover shared index file and splitIndex=false read"
    );

    std::fs::write(tree.join(std::ffi::OsStr::from_bytes(b"raw-\xff")), "x")
        .expect("a name that is not UTF-8");
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.read_failed",
        "a path is never changed"
    );
}

#[test]
fn a_read_returns_every_path_up_to_its_bound_and_says_when_it_truncates() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    // 400 untracked files with 200-byte names, quotes included: about 90 KiB of names, over the bound.
    for index in 0..400 {
        std::fs::write(tree.join(format!("{index:03}-{}\"q", "x".repeat(195))), "x").expect("file");
    }
    let read = worktree_read(&s.system, &json!({"worktree": tree})).expect("read");
    assert!(
        read::embedded(&read) <= read::ANSWER_BYTES,
        "the embedded answer fits: {}",
        read::embedded(&read)
    );
    let read::Worktree::Present { untracked, .. } = read else {
        panic!("present")
    };
    assert_eq!((untracked.count, untracked.truncated), (400, true));
    assert!(
        !untracked.paths.is_empty() && untracked.paths.len() < 400,
        "{}",
        untracked.paths.len()
    );
    assert!(
        untracked.paths[0].starts_with("000-"),
        "Git's order is kept"
    );
}

#[test]
fn checkout_refuses_any_repository_below_the_work_tree_before_any_change() {
    for (what, make) in [
        ("an ignored nested repository", "ignored/inner"),
        ("a deep untracked one", "a/b/c/inner"),
        ("a bare one", "bare.git"),
        ("a .git file", "linked"),
    ] {
        let s = scratch();
        let (tree, first, second) = work(&s);
        let at = tree.join(make);
        std::fs::create_dir_all(&at).expect("folder");
        match what {
            "a bare one" => {
                git(
                    &tree,
                    &["init", "--quiet", "--bare", at.to_str().expect("path")],
                );
            }
            "a .git file" => {
                std::fs::write(at.join(".git"), "gitdir: /nonexistent\n").expect("gitfile");
            }
            _ => {
                git(&at, &["init", "--quiet"]);
            }
        }
        std::fs::write(tree.join(".git/info/exclude"), "ignored/\n").expect("exclude");
        for order in [
            json!({"worktree": tree, "branch": "main", "reset": first, "destructive": true}),
            json!({"worktree": tree, "branch": "other", "start": first}),
            json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true}),
        ] {
            assert_eq!(
                code(checkout(&s.system, &order)),
                "git.nested_repository",
                "{what}: {order}"
            );
        }
        assert_eq!(
            git(&tree, &["rev-parse", "HEAD"]),
            second,
            "{what}: nothing moved"
        );
        assert!(std::fs::symlink_metadata(&at).is_ok(), "{what}: it stays");
    }
    // A link to a repository is not followed.
    let s = scratch();
    let (tree, _, _) = work(&s);
    let outside = s.at("outside");
    std::fs::create_dir(&outside).expect("outside");
    git(&outside, &["init", "--quiet"]);
    std::os::unix::fs::symlink(&outside, tree.join("link")).expect("link");
    assert_eq!(
        checkout(&s.system, &json!({"worktree": tree, "branch": "main"}))
            .expect("no walk through a link")
            .changes,
        0
    );
}

#[test]
fn clean_runs_only_as_its_own_order_on_the_current_branch() {
    let s = scratch();
    let (tree, first, _) = work(&s);
    for order in [
        json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true, "reset": first}),
        json!({"worktree": tree, "branch": "new", "clean": true, "destructive": true, "start": first}),
        json!({"worktree": tree, "branch": "new", "clean": true, "destructive": true, "rename_from": "main"}),
        json!({"worktree": tree, "branch": "other", "clean": true, "destructive": true}),
    ] {
        assert_eq!(
            code(checkout(&s.system, &order)),
            "git.input_invalid",
            "{order}"
        );
    }
}

#[test]
fn the_split_index_check_reads_the_index_header_and_writes_nothing() {
    let s = scratch();
    let (tree, _, _) = work(&s);
    let index = |tree: &Path| std::fs::read(tree.join(".git/index")).expect("index");
    assert_eq!(read::linked(&index(&tree), 20), Some(false));
    for version in ["3", "4", "2"] {
        git(&tree, &["update-index", "--index-version", version]);
        assert_eq!(
            read::linked(&index(&tree), 20),
            Some(false),
            "version {version}"
        );
    }
    git(&tree, &["update-index", "--split-index"]);
    assert_eq!(read::linked(&index(&tree), 20), Some(true));
    let before = snapshot(&tree.join(".git"));
    std::thread::sleep(std::time::Duration::from_millis(20));
    assert_eq!(
        code(worktree_read(&s.system, &json!({"worktree": tree}))),
        "git.repository_refused"
    );
    assert_eq!(
        snapshot(&tree.join(".git")),
        before,
        "the refused read wrote nothing"
    );
    git(&tree, &["update-index", "--no-split-index"]);
    let leftover = std::fs::read_dir(tree.join(".git"))
        .expect("git")
        .flatten()
        .any(|entry| {
            entry
                .file_name()
                .to_string_lossy()
                .starts_with("sharedindex.")
        });
    assert!(leftover, "a leftover shared index file");
    assert_eq!(read::linked(&index(&tree), 20), Some(false));
    let before = snapshot(&tree.join(".git"));
    std::thread::sleep(std::time::Duration::from_millis(20));
    assert!(worktree_read(&s.system, &json!({"worktree": tree})).is_ok());
    assert_eq!(
        snapshot(&tree.join(".git")),
        before,
        "the read wrote nothing"
    );
    assert_eq!(read::linked(b"not an index", 20), None);
}
