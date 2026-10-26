use super::*;
use crate::commands::recipe::WorkDir;
use std::{io::Read, os::unix::fs::MetadataExt};
struct Fixture {
    _work: WorkDir,
    checkout: String,
    layout: Layout,
    first: String,
    second: String,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let base = work.0.join("home");
        fs::create_dir_all(base.join("shared")).unwrap();
        let checkout = base.join("orbit/apps/gateway").to_str().unwrap().to_owned();
        let layout = Layout::new(&checkout).unwrap();
        let repo = git2::Repository::init_bare(layout.shared().join("orbit.git")).unwrap();
        repo.remote("origin", "https://github.com/hardimpactdev/orbit.git")
            .unwrap();
        let tree_id = repo.treebuilder(None).unwrap().write().unwrap();
        let tree = repo.find_tree(tree_id).unwrap();
        let signature =
            git2::Signature::new("Orbit", "orbit@example.test", &git2::Time::new(0, 0)).unwrap();
        let first = repo
            .commit(Some("HEAD"), &signature, &signature, "first", &tree, &[])
            .unwrap();
        let parent = repo.find_commit(first).unwrap();
        let second = repo
            .commit(
                Some("HEAD"),
                &signature,
                &signature,
                "second",
                &tree,
                &[&parent],
            )
            .unwrap();
        let first = first.to_string();
        let second = second.to_string();
        for sha in [&first, &second] {
            let path = base.join("releases").join(&sha[..12]);
            fs::create_dir_all(&path).unwrap();
            fs::write(path.join("REVISION"), format!("{sha}\n")).unwrap();
            fs::write(path.join("file"), sha).unwrap();
        }
        symlink(format!("releases/{}", &first[..12]), &layout.current).unwrap();
        Self {
            _work: work,
            checkout,
            layout,
            first,
            second,
        }
    }
    fn input(&self) -> SwitchInput {
        SwitchInput {
            checkout: self.checkout.clone(),
            release: self.second[..12].into(),
        }
    }
    fn next(&self) -> PathBuf {
        self.layout.base.join("orbit.next")
    }
    fn current(&self) -> PathBuf {
        fs::read_link(&self.layout.current).unwrap()
    }
    fn forward(&self, sha: Option<&str>, force: bool) -> ForwardInput {
        ForwardInput {
            checkout: self.checkout.clone(),
            sha: sha.map(Into::into),
            force,
        }
    }
}
fn rename(a: &Path, b: &Path) -> io::Result<()> {
    fs::rename(a, b)
}
#[test]
fn atomic_switch_preserves_open_readers_and_repeats_without_replacing_link() {
    let f = Fixture::new();
    let mut old = fs::File::open(f.layout.current.join("file")).unwrap();
    assert_eq!(
        switch(&f.input(), rename).unwrap().previous.as_deref(),
        Some(&f.first[..12])
    );
    assert_eq!(
        fs::read_to_string(f.layout.current.join("file")).unwrap(),
        f.second
    );
    let mut content = String::new();
    old.read_to_string(&mut content).unwrap();
    assert_eq!(content, f.first);
    let inode = fs::symlink_metadata(&f.layout.current).unwrap().ino();
    assert_eq!(
        switch(&f.input(), rename).unwrap().previous.as_deref(),
        Some(&f.second[..12])
    );
    assert_eq!(
        fs::symlink_metadata(&f.layout.current).unwrap().ino(),
        inode
    );
    assert!(!f.next().exists());
}
#[test]
fn recognizes_absolute_release_links_and_allows_absent_current() {
    for absent in [false, true] {
        let f = Fixture::new();
        fs::remove_file(&f.layout.current).unwrap();
        if !absent {
            symlink(
                f.layout.base.join("releases").join(&f.first[..12]),
                &f.layout.current,
            )
            .unwrap();
        }
        assert_eq!(
            switch(&f.input(), rename).unwrap().previous,
            (!absent).then(|| f.first[..12].to_owned())
        );
    }
}
#[test]
fn interrupted_atomic_switch_leaves_current_and_cleans_candidate() {
    let f = Fixture::new();
    let before = f.current();
    assert!(matches!(
        switch(&f.input(), |_, _| Err(io::Error::other("rename failed"))),
        Err(Error::SwitchFailed {})
    ));
    assert_eq!(f.current(), before);
    assert!(!f.next().exists());
    switch(&f.input(), rename).unwrap();
}
#[test]
fn refuses_in_place_checkout_and_foreign_current_links_before_staging() {
    for link in [false, true] {
        let f = Fixture::new();
        fs::remove_file(&f.layout.current).unwrap();
        if link {
            symlink("foreign", &f.layout.current).unwrap();
        } else {
            fs::create_dir(&f.layout.current).unwrap();
        }
        assert!(matches!(
            switch(&f.input(), rename),
            Err(Error::NotAdopted {})
        ));
        assert!(!f.next().exists());
        if link {
            assert_eq!(f.current(), PathBuf::from("foreign"));
        } else {
            assert!(f.layout.current.is_dir());
        }
    }
}
#[test]
fn refuses_foreign_staging_files_directories_and_links_without_changing_them() {
    for kind in ["file", "directory", "link"] {
        let f = Fixture::new();
        let before = f.current();
        match kind {
            "file" => fs::write(f.next(), "keep").unwrap(),
            "directory" => fs::create_dir(f.next()).unwrap(),
            _ => symlink(format!("releases/{}", &f.first[..12]), f.next()).unwrap(),
        }
        let inode = fs::symlink_metadata(f.next()).unwrap().ino();
        assert!(matches!(
            switch(&f.input(), rename),
            Err(Error::Conflict {})
        ));
        assert_eq!(fs::symlink_metadata(f.next()).unwrap().ino(), inode);
        assert_eq!(f.current(), before);
    }
}
#[test]
fn resumes_its_existing_candidate_link() {
    let f = Fixture::new();
    symlink(format!("releases/{}", &f.second[..12]), f.next()).unwrap();
    switch(&f.input(), rename).unwrap();
    assert_eq!(
        f.current(),
        PathBuf::from(format!("releases/{}", &f.second[..12]))
    );
}
#[test]
fn refuses_missing_partial_mismatched_or_linked_revision_before_switch() {
    for kind in ["missing", "partial", "wrong", "link", "large"] {
        let f = Fixture::new();
        let before = f.current();
        let release = f.layout.base.join("releases").join(&f.second[..12]);
        let revision = release.join("REVISION");
        match kind {
            "missing" => fs::remove_dir_all(&release).unwrap(),
            "partial" => fs::remove_file(&revision).unwrap(),
            "wrong" => fs::write(&revision, &f.first).unwrap(),
            "large" => fs::write(&revision, "a".repeat(1024)).unwrap(),
            _ => {
                fs::remove_file(&revision).unwrap();
                symlink(
                    f.layout
                        .base
                        .join("releases")
                        .join(&f.first[..12])
                        .join("REVISION"),
                    &revision,
                )
                .unwrap();
            }
        }
        assert!(matches!(
            switch(&f.input(), rename),
            Err(Error::NotPrepared {})
        ));
        assert_eq!(f.current(), before);
        assert!(!f.next().exists());
    }
}
#[test]
fn refuses_symlinked_release_tree_and_noncanonical_inputs() {
    let f = Fixture::new();
    let before = f.current();
    for checkout in [
        "/orbit/apps/gateway".into(),
        format!("{}/../home/orbit/apps/gateway", f.layout.base.display()),
        format!("{}\n", f.checkout),
    ] {
        assert!(matches!(
            switch(
                &SwitchInput {
                    checkout,
                    release: f.second[..12].into()
                },
                rename
            ),
            Err(Error::Invalid {})
        ));
    }
    let mut input = f.input();
    input.release = "../foreign".into();
    assert!(matches!(switch(&input, rename), Err(Error::Invalid {})));
    let releases = f.layout.base.join("releases");
    fs::rename(&releases, f.layout.base.join("elsewhere")).unwrap();
    symlink("elsewhere", &releases).unwrap();
    assert!(matches!(
        switch(&f.input(), rename),
        Err(Error::Conflict {})
    ));
    assert_eq!(f.current(), before);
    assert!(!f.next().exists());
}
#[test]
fn ancestry_accepts_descendants_same_commit_and_force_and_reports_source() {
    let f = Fixture::new();
    let before = f.current();
    for (sha, force) in [
        (None, false),
        (Some(f.first.as_str()), false),
        (Some(f.second.as_str()), false),
        (Some("0000000000000000000000000000000000000000"), true),
    ] {
        let result = forward(&f.forward(sha, force)).unwrap();
        assert_eq!(result.source.as_deref(), Some(&f.first[..12]));
        assert_eq!(result.source_sha.as_deref(), Some(f.first.as_str()));
    }
    assert_eq!(f.current(), before);
    assert!(matches!(
        forward(&f.forward(Some("bad"), true)),
        Err(Error::Invalid {})
    ));
}
#[test]
fn ancestry_refuses_downgrade_and_missing_commits_without_mutation() {
    let f = Fixture::new();
    switch(&f.input(), rename).unwrap();
    let before = f.current();
    for sha in [f.first.as_str(), "0000000000000000000000000000000000000000"] {
        for _ in 0..2 {
            assert!(
                matches!(forward(&f.forward(Some(sha), false)), Err(Error::Downgrade { current_sha, .. }) if current_sha == f.second)
            );
        }
    }
    assert_eq!(f.current(), before);
}
#[test]
fn ancestry_without_prepared_current_reports_null_and_does_not_require_repository() {
    let f = Fixture::new();
    fs::remove_file(&f.layout.current).unwrap();
    fs::remove_dir_all(f.layout.shared()).unwrap();
    let result = forward(&f.forward(Some(&f.second), false)).unwrap();
    assert!(result.source.is_none() && result.source_sha.is_none());
}
#[test]
fn source_reads_origin_repeatedly_and_never_returns_credentials_or_local_paths() {
    let f = Fixture::new();
    let input = SourceInput {
        checkout: f.checkout.clone(),
    };
    let repo = git2::Repository::open_bare(f.layout.shared().join("orbit.git")).unwrap();
    for origin in [
        "https://github.com/hardimpactdev/orbit.git",
        "git@github.com:hardimpactdev/orbit.git",
        "ssh://git@github.com/hardimpactdev/orbit.git",
    ] {
        repo.remote_set_url("origin", origin).unwrap();
        for _ in 0..2 {
            assert_eq!(
                crate::commands::source::repository_identity(&source(&input).unwrap().origin)
                    .as_deref(),
                Some("github.com/hardimpactdev/orbit")
            );
        }
    }
    for origin in [
        "https://token@github.com/hardimpactdev/orbit.git",
        "ssh://user:secret@github.com/repo",
        "/private/checkout",
    ] {
        repo.remote_set_url("origin", origin).unwrap();
        assert!(matches!(source(&input), Err(Error::SourceUnknown {})));
    }
    repo.remote_delete("origin").unwrap();
    assert!(matches!(source(&input), Err(Error::SourceUnknown {})));
}

#[test]
fn inventory_reads_prepared_and_incomplete_releases_without_changing_them() {
    let f = Fixture::new();
    let releases = f.layout.base.join("releases");
    for id in ["000000000002", "000000000001", "not-a-release"] {
        fs::create_dir(releases.join(id)).unwrap();
    }
    for id in ["000000000003", "000000000004", "000000000005"] {
        fs::create_dir(releases.join(id)).unwrap();
    }
    fs::write(releases.join("000000000003/REVISION"), &f.first).unwrap();
    symlink("missing", releases.join("000000000004/REVISION")).unwrap();
    fs::write(releases.join("000000000005/REVISION"), "f".repeat(1024)).unwrap();
    symlink(&f.first[..12], releases.join("000000000006")).unwrap();
    let timestamp = std::time::UNIX_EPOCH + Duration::from_secs(123);
    for sha in [&f.first, &f.second] {
        fs::File::open(releases.join(&sha[..12]).join("REVISION"))
            .unwrap()
            .set_times(fs::FileTimes::new().set_modified(timestamp))
            .unwrap();
    }
    let mut expected = vec![f.first[..12].to_owned(), f.second[..12].to_owned()];
    expected.sort_by(|a, b| b.cmp(a));
    let before = fs::symlink_metadata(&f.layout.current).unwrap();
    let input = InventoryInput {
        running_application: None,
        checkout: f.checkout.clone(),
    };
    for _ in 0..2 {
        let observed = inventory(&input).unwrap();
        assert_eq!(observed.current.as_deref(), Some(&f.first[..12]));
        assert_eq!(
            observed.commits,
            BTreeMap::from([
                (f.first[..12].into(), f.first.clone()),
                (f.second[..12].into(), f.second.clone()),
            ])
        );
        assert_eq!(
            observed
                .incomplete
                .keys()
                .map(String::as_str)
                .collect::<Vec<_>>(),
            ["000000000001", "000000000002"]
        );
        assert_eq!(observed.state, "adopted");
        assert_eq!(observed.retained, expected);
        assert_eq!(
            fs::symlink_metadata(&f.layout.current).unwrap().ino(),
            before.ino()
        );
        assert_eq!(
            fs::metadata(releases.join(&f.first[..12]).join("REVISION"))
                .unwrap()
                .mtime(),
            123
        );
    }
    fs::File::open(releases.join(&f.first[..12]).join("REVISION"))
        .unwrap()
        .set_times(fs::FileTimes::new().set_modified(timestamp + Duration::from_secs(1)))
        .unwrap();
    assert_eq!(inventory(&input).unwrap().retained[0], f.first[..12]);
}

#[test]
fn inventory_handles_missing_in_place_foreign_and_absolute_current_paths() {
    for kind in ["missing", "in_place", "foreign", "absolute"] {
        let f = Fixture::new();
        fs::remove_file(&f.layout.current).unwrap();
        match kind {
            "in_place" => fs::create_dir(&f.layout.current).unwrap(),
            "foreign" => symlink("foreign", &f.layout.current).unwrap(),
            "absolute" => symlink(
                f.layout.base.join("releases").join(&f.first[..12]),
                &f.layout.current,
            )
            .unwrap(),
            _ => {}
        }
        fs::remove_dir_all(f.layout.base.join("releases")).unwrap();
        let observed = inventory(&InventoryInput {
            running_application: None,
            checkout: f.checkout.clone(),
        })
        .unwrap();
        assert_eq!(
            observed.current,
            (kind == "absolute").then(|| f.first[..12].into())
        );
        assert_eq!(
            observed.state,
            if kind == "in_place" {
                "in_place"
            } else {
                "unreadable"
            }
        );
        assert!(!observed.superseded);
        assert!(
            observed.commits.is_empty()
                && observed.retained.is_empty()
                && observed.incomplete.is_empty()
        );
    }
}

#[test]
fn inventory_refuses_invalid_layout_and_linked_inventory_without_mutation() {
    let f = Fixture::new();
    assert!(matches!(
        inventory(&InventoryInput {
            running_application: None,
            checkout: format!("{}/../orbit/apps/gateway", f.layout.base.display())
        }),
        Err(Error::Invalid {})
    ));
    let releases = f.layout.base.join("releases");
    fs::rename(&releases, f.layout.base.join("foreign")).unwrap();
    symlink("foreign", &releases).unwrap();
    for _ in 0..2 {
        assert!(matches!(
            inventory(&InventoryInput {
                running_application: None,
                checkout: f.checkout.clone()
            }),
            Err(Error::Conflict {})
        ));
        assert_eq!(fs::read_link(&releases).unwrap(), PathBuf::from("foreign"));
    }
}

#[test]
fn inventory_observes_serving_application_changes_and_incomplete_current() {
    let f = Fixture::new();
    for sha in [&f.first, &f.second] {
        fs::create_dir_all(
            f.layout
                .base
                .join("releases")
                .join(&sha[..12])
                .join("apps/gateway"),
        )
        .unwrap();
    }
    let mut input = InventoryInput {
        checkout: f.checkout.clone(),
        running_application: Some(
            f.layout
                .base
                .join("releases")
                .join(&f.first[..12])
                .join("apps/gateway"),
        ),
    };
    assert!(!inventory(&input).unwrap().superseded);
    switch(&f.input(), rename).unwrap();
    for _ in 0..2 {
        let observed = inventory(&input).unwrap();
        assert!(observed.superseded);
        assert_eq!(observed.state, "adopted");
        assert_eq!(observed.current.as_deref(), Some(&f.second[..12]));
    }
    input.running_application = Some(PathBuf::from(&f.checkout));
    assert!(!inventory(&input).unwrap().superseded);
    input.running_application = Some(f.layout.base.join("missing"));
    assert!(!inventory(&input).unwrap().superseded);
    fs::remove_file(f.layout.current.join("REVISION")).unwrap();
    let observed = inventory(&input).unwrap();
    assert_eq!(observed.state, "unreadable");
    assert!(observed.incomplete.contains_key(&f.second[..12]));
    input.running_application = Some(PathBuf::from("relative"));
    assert!(matches!(inventory(&input), Err(Error::Invalid {})));
}

#[test]
fn inventory_refuses_an_unreadable_releases_directory_without_changing_it() {
    use std::os::unix::fs::PermissionsExt;
    let f = Fixture::new();
    let releases = f.layout.base.join("releases");
    fs::set_permissions(&releases, fs::Permissions::from_mode(0o300)).unwrap();
    let input = InventoryInput {
        running_application: None,
        checkout: f.checkout.clone(),
    };
    let observed: Vec<_> = (0..2).map(|_| inventory(&input)).collect();
    let mode = fs::metadata(&releases).unwrap().mode() & 0o7777;
    fs::set_permissions(&releases, fs::Permissions::from_mode(0o755)).unwrap();
    for result in observed {
        assert!(matches!(result, Err(Error::Conflict {})));
    }
    assert_eq!(mode, 0o300);
    assert_eq!(
        f.current(),
        PathBuf::from(format!("releases/{}", &f.first[..12]))
    );
    assert!(inventory(&input)
        .unwrap()
        .commits
        .contains_key(&f.first[..12]));
}

#[test]
fn inventory_names_digit_only_releases_with_json_strings() {
    let f = Fixture::new();
    let releases = f.layout.base.join("releases");
    let prepared = "983536432205";
    let sha = format!("{prepared}{}", "a".repeat(28));
    fs::create_dir(releases.join(prepared)).unwrap();
    fs::write(releases.join(prepared).join("REVISION"), format!("{sha}\n")).unwrap();
    fs::create_dir(releases.join("000000000777")).unwrap();
    let observed = serde_json::to_value(
        inventory(&InventoryInput {
            running_application: None,
            checkout: f.checkout.clone(),
        })
        .unwrap(),
    )
    .unwrap();
    assert_eq!(observed["commits"][prepared], serde_json::json!(sha));
    assert!(observed["incomplete"]["000000000777"].is_i64());
    assert!(observed["retained"]
        .as_array()
        .unwrap()
        .contains(&serde_json::json!(prepared)));
}
