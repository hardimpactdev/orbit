use super::*;
use crate::commands::recipe::WorkDir;
use std::{fs, io::Write, path::Path, process::Stdio, time::Instant};
fn git(path: &Path, args: &[&str]) -> String {
    let output = Process::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Orbit test",
            "-c",
            "user.email=orbit@example.test",
            "-C",
        ])
        .arg(path)
        .args(args)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    String::from_utf8(output.stdout).unwrap().trim().to_owned()
}
fn fixture(format: &str) -> (WorkDir, Input, Vec<u8>) {
    let work = WorkDir::new().unwrap();
    let source = work.0.join("source");
    let target = work.0.join("target");
    for path in [&source, &target] {
        fs::create_dir(path).unwrap();
        git(
            path,
            &[
                "init",
                "--quiet",
                "-b",
                "main",
                &format!("--object-format={format}"),
            ],
        );
    }
    fs::write(source.join("file"), "binary\0file\n").unwrap();
    git(&source, &["add", "file"]);
    git(&source, &["commit", "--quiet", "-m", "one"]);
    let commit = git(&source, &["rev-parse", "HEAD"]);
    let pack = Process::new("/usr/bin/git")
        .arg("-C")
        .arg(&source)
        .args(["pack-objects", "--all", "--stdout", "--quiet"])
        .output()
        .unwrap();
    assert!(pack.status.success());
    let input = Input {
        checkout: target,
        default_branch: Some("main".into()),
        updates: vec![
            Update {
                reference: "refs/remotes/origin/main".into(),
                commit: Some(commit.clone()),
            },
            Update {
                reference: "refs/worktree/orbit/ci-baseline".into(),
                commit: Some(commit),
            },
        ],
    };
    (work, input, pack.stdout)
}
fn context(pack: &[u8]) -> Context {
    let work = WorkDir::new().unwrap();
    let path = work.0.join("pack");
    fs::write(&path, pack).unwrap();
    let file = fs::File::open(path).unwrap();
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(10)).with_binary_input(file)
}
#[test]
fn imports_and_deletes_approved_refs_repeatedly_for_both_object_formats() {
    for format in ["sha1", "sha256"] {
        let (_work, mut input, pack) = fixture(format);
        for _ in 0..2 {
            assert_eq!(
                import(&input, &context(&pack)).unwrap(),
                Output { imported: true }
            );
            assert_eq!(
                git(&input.checkout, &["rev-parse", "refs/remotes/origin/main"]),
                *input.updates[0].commit.as_ref().unwrap()
            );
            assert_eq!(
                git(
                    &input.checkout,
                    &["symbolic-ref", "refs/remotes/origin/HEAD"]
                ),
                "refs/remotes/origin/main"
            );
            assert_eq!(
                git(&input.checkout, &["show", "refs/remotes/origin/main:file"]).as_bytes(),
                b"binary\0file"
            );
        }
        input.updates[0].commit = None;
        input.default_branch = None;
        for _ in 0..2 {
            import(&input, &context(&pack)).unwrap();
        }
        assert_eq!(
            git(
                &input.checkout,
                &["for-each-ref", "refs/remotes/origin/main"]
            ),
            ""
        );
    }
}
#[test]
fn refuses_invalid_refs_and_ids_before_importing_any_objects() {
    let (_work, mut input, pack) = fixture("sha1");
    for reference in [
        "refs/heads/main",
        "refs/remotes/origin/HEAD",
        "refs/remotes/origin/../bad",
        "refs/remotes/origin/a\nupdate refs/heads/main",
        "refs/orbit/ci-cache/bad",
    ] {
        input.updates[0].reference = reference.into();
        assert!(matches!(
            import(&input, &context(&pack)),
            Err(Error::Invalid {})
        ));
        assert_eq!(
            git(&input.checkout, &["count-objects", "-v"])
                .lines()
                .find(|line| line.starts_with("in-pack:")),
            Some("in-pack: 0")
        );
    }
    input.updates[0].reference = "refs/remotes/origin/main".into();
    input.updates[0].commit = Some("HEAD".into());
    assert!(matches!(
        import(&input, &context(&pack)),
        Err(Error::Invalid {})
    ));
}
#[test]
fn rejects_corrupt_or_missing_packs_and_never_updates_refs() {
    let (_work, input, mut pack) = fixture("sha1");
    pack[12] ^= 1;
    assert!(matches!(
        import(&input, &context(&pack)),
        Err(Error::Pack {})
    ));
    assert!(matches!(
        import(
            &input,
            &Context::new(Platform::Linux, Instant::now() + Duration::from_secs(5))
        ),
        Err(Error::Pack {})
    ));
    assert_eq!(git(&input.checkout, &["for-each-ref"]), "");
}
#[test]
fn missing_target_or_expired_deadline_preserves_all_refs() {
    let (_work, mut input, pack) = fixture("sha1");
    input.updates[1].commit = Some("a".repeat(40));
    assert!(matches!(
        import(&input, &context(&pack)),
        Err(Error::Failed {})
    ));
    assert_eq!(git(&input.checkout, &["for-each-ref"]), "");
    assert!(matches!(
        import(&input, &Context::new(Platform::Linux, Instant::now())),
        Err(Error::Failed {})
    ));
}
#[test]
fn linked_worktree_baselines_stay_private_and_hooks_do_not_run() {
    let (work, input, pack) = fixture("sha1");
    import(&input, &context(&pack)).unwrap();
    git(
        &input.checkout,
        &[
            "checkout",
            "--quiet",
            "-B",
            "main",
            "refs/remotes/origin/main",
        ],
    );
    let other = work.0.join("other");
    git(
        &input.checkout,
        &[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "other",
            other.to_str().unwrap(),
        ],
    );
    let marker = work.0.join("hook-ran");
    let hook = input.checkout.join(".git/hooks/reference-transaction");
    fs::write(&hook, format!("#!/bin/sh\ntouch '{}'\n", marker.display())).unwrap();
    use std::os::unix::fs::PermissionsExt;
    fs::set_permissions(hook, fs::Permissions::from_mode(0o755)).unwrap();
    let linked = Input {
        checkout: other,
        default_branch: None,
        updates: vec![Update {
            reference: "refs/worktree/orbit/ci-baseline".into(),
            commit: input.updates[0].commit.clone(),
        }],
    };
    import(&linked, &context(&pack)).unwrap();
    let delete = Input {
        updates: vec![Update {
            reference: "refs/worktree/orbit/ci-baseline".into(),
            commit: None,
        }],
        ..linked
    };
    import(&delete, &context(&pack)).unwrap();
    assert_eq!(
        git(
            &input.checkout,
            &["rev-parse", "refs/worktree/orbit/ci-baseline"]
        ),
        *input.updates[0].commit.as_ref().unwrap()
    );
    assert!(!marker.exists());
}
#[test]
fn imports_a_thin_pack_against_existing_objects() {
    let (work, input, pack) = fixture("sha1");
    import(&input, &context(&pack)).unwrap();
    let source = work.0.join("source");
    let old = input.updates[0].commit.as_ref().unwrap();
    fs::write(source.join("file"), "next\0binary\n").unwrap();
    git(&source, &["commit", "--quiet", "-am", "next"]);
    let next = git(&source, &["rev-parse", "HEAD"]);
    let mut child = Process::new("/usr/bin/git")
        .arg("-C")
        .arg(&source)
        .args(["pack-objects", "--revs", "--thin", "--stdout", "--quiet"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .spawn()
        .unwrap();
    child
        .stdin
        .take()
        .unwrap()
        .write_all(format!("{next}\n^{old}\n").as_bytes())
        .unwrap();
    let output = child.wait_with_output().unwrap();
    assert!(output.status.success());
    let input = Input {
        updates: vec![Update {
            reference: "refs/remotes/origin/main".into(),
            commit: Some(next.clone()),
        }],
        ..input
    };
    import(&input, &context(&output.stdout)).unwrap();
    assert_eq!(
        git(&input.checkout, &["rev-parse", "refs/remotes/origin/main"]),
        next
    );
}
