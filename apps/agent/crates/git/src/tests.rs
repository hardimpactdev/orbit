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
    std::fs::write(tree.join(std::ffi::OsStr::from_bytes(b"raw-\xff")), "x")
        .expect("a name that is not UTF-8");
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
        (1, 1, 0, 2, 2)
    );
    assert!(
        untracked.names.contains(&"c.txt".to_owned())
            && untracked.names.iter().any(|name| name.starts_with("raw-")),
        "{untracked:?}"
    );
    assert_eq!(stashes, 1);
    assert_eq!(heads.get("side"), Some(&first));
    assert_eq!(heads.get("main"), Some(&second));
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
    let cleaned = checkout(
        &s.system,
        &json!({"worktree": tree, "branch": "main", "clean": true, "destructive": true}),
    )
    .expect("clean");
    assert_eq!(
        cleaned.changes, 0,
        "a nested repository is not untracked work that clean converges"
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
