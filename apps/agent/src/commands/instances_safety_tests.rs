//! Read-only source and publication observations leave Git state unchanged.
use super::recovery::tree_bytes;
use super::*;

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
    assert!(!publication_at(&sandbox.root, ORIGIN, &head, &GitRead::default()).unwrap());

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
fn inspection_preserves_origin_linefeeds_without_changing_config() {
    for origin in [ORIGIN, "https://example.test/acme/site.git"] {
        let sandbox = Sandbox::new();
        let checkout = sandbox.checkout("dev");
        sh_git(
            &checkout,
            &["remote", "set-url", "origin", &format!("{origin}\n")],
        );
        let config = fs::read(checkout.join(".git/config")).unwrap();
        assert_eq!(
            decode(
                &InspectSource::run(inspect_input(&sandbox, &checkout), &context())
                    .unwrap()
                    .origin
            ),
            format!("{origin}\n").as_bytes()
        );
        assert_eq!(fs::read(checkout.join(".git/config")).unwrap(), config);
    }
}
