//! The release commands against a sandbox home that the test user owns. The layout marker belongs to the test user
//! here, as root's does on a Node, and Caddy's grants land on the sandbox's own files.

use super::*;
use crate::command::identity;
use std::process::Command as Process;

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

struct Fixture {
    base: PathBuf,
    layout: Layout,
    home: ReleaseHome,
    first: String,
}

impl Fixture {
    /// An origin with a Laravel-like `public` web root on `main`, a home with its marker and `.env`, and no release.
    fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-releases-{:016x}", rand::random::<u64>()));
        let work = base.join("work");
        fs::create_dir_all(work.join("public")).unwrap();
        fs::write(work.join("public/index.php"), "<?php\n").unwrap();
        fs::create_dir_all(work.join("admin/public")).unwrap();
        fs::write(work.join("admin/public/index.php"), "<?php\n").unwrap();
        sh_git(&work, &["init", "--quiet"]);
        sh_git(&work, &["add", "."]);
        sh_git(&work, &["commit", "--quiet", "-m", "one"]);
        let first = sh_git(&work, &["rev-parse", "HEAD"]);
        let origin = base.join("origin.git");
        sh_git(
            &base,
            &[
                "clone",
                "--quiet",
                "--bare",
                work.to_str().unwrap(),
                origin.to_str().unwrap(),
            ],
        );

        let uid = rustix::process::geteuid().as_raw();
        let user = super::super::source::user_name(uid);
        let layout = Layout {
            home_root: base.join("home"),
            state_root: base.join("state"),
            root_uid: uid,
        };
        let home = ReleaseHome {
            repository: origin.display().to_string(),
            user: user.clone(),
            home: base.join("home").join(&user),
            instance: 9,
            relative_root: "public".into(),
            application_suffix: String::new(),
            served: Vec::new(),
        };
        fs::create_dir_all(home.releases()).unwrap();
        fs::write(home.environment(), "APP_KEY=x\n").unwrap();
        let state = layout.state_root.join("9");
        fs::create_dir_all(&state).unwrap();
        fs::set_permissions(&state, fs::Permissions::from_mode(0o700)).unwrap();
        let marker = state.join("release-layout");
        fs::write(
            &marker,
            format!(
                "{}\0{}\0{}\0initial\0",
                home.repository,
                user,
                home.home.display()
            ),
        )
        .unwrap();
        fs::set_permissions(&marker, fs::Permissions::from_mode(0o600)).unwrap();
        Self {
            base,
            layout,
            home,
            first,
        }
    }

    fn prepare(&self, name: &str, commit: Option<&str>) -> Result<Release, ReleaseError> {
        prepare(
            &PrepareInput {
                home: self.home.clone(),
                name: name.into(),
                branch: "main".into(),
                commit: commit.map(str::to_string),
                shared_paths: Vec::new(),
                git_read: BTreeMap::new(),
            },
            &self.layout,
        )
    }

    fn activate(&self, release: &Release) -> Result<Release, ReleaseError> {
        activate(
            &ActivateInput {
                home: self.home.clone(),
                name: release.name.clone(),
                commit: release.commit.clone(),
            },
            &self.layout,
        )
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

#[test]
fn prepares_a_release_that_links_the_stable_environment_and_leaves_current_alone() {
    let fixture = Fixture::new();
    let release = fixture.prepare("20260101000000-a1", None).unwrap();
    assert_eq!(
        release,
        Release {
            name: "20260101000000-a1".into(),
            commit: fixture.first.clone()
        }
    );
    let link = fixture.home.release(&release.name).join(".env");
    assert_eq!(fs::read_link(&link).unwrap(), Path::new("../../.env"));
    assert!(fs::symlink_metadata(fixture.home.home.join("current")).is_err());
    assert_eq!(
        fixture.prepare("20260101000000-a1", None),
        Err(invalid("the release name is free"))
    );
}

#[test]
fn activation_selects_the_release_and_inspection_and_listing_read_it() {
    let fixture = Fixture::new();
    let release = fixture.prepare("20260101000000-a1", None).unwrap();
    assert_eq!(fixture.activate(&release).unwrap(), release);
    assert_eq!(
        fs::read_link(fixture.home.home.join("current")).unwrap(),
        Path::new("releases/20260101000000-a1")
    );
    let selected = inspect(
        &InspectInput {
            home: fixture.home.clone(),
            name: None,
        },
        &fixture.layout,
    )
    .unwrap();
    assert_eq!(
        selected.release,
        Some(Release {
            name: release.name.clone(),
            commit: release.commit.clone()
        })
    );
    let listed = list(&fixture.home, &fixture.layout).unwrap();
    assert_eq!(listed.selected.as_deref(), Some(release.name.as_str()));
    assert_eq!(listed.releases, [release]);
}

#[test]
fn a_commit_outside_the_branch_is_refused() {
    let fixture = Fixture::new();
    assert_eq!(
        fixture.prepare("20260101000000-a1", Some(&"0".repeat(40))),
        Err(ReleaseError::CommitNotOnBranch {})
    );
}

#[test]
fn every_command_refuses_a_marker_for_another_repository() {
    let fixture = Fixture::new();
    let marker = fixture.layout.state_root.join("9/release-layout");
    fs::write(&marker, "elsewhere\0").unwrap();
    assert_eq!(
        fixture.prepare("20260101000000-a1", None),
        Err(invalid("the layout marker names this home"))
    );
    assert_eq!(
        list(&fixture.home, &fixture.layout).map(|_| ()),
        Err(invalid("the layout marker names this home"))
    );
}

#[test]
fn a_link_out_of_the_web_root_fails_the_release() {
    let fixture = Fixture::new();
    let release = fixture.prepare("20260101000000-a1", None).unwrap();
    std::os::unix::fs::symlink(
        "/etc",
        fixture.home.release(&release.name).join("public/escape"),
    )
    .unwrap();
    assert_eq!(
        fixture.activate(&release),
        Err(invalid("the only link in a web root is its public storage"))
    );
    assert!(list(&fixture.home, &fixture.layout)
        .unwrap()
        .releases
        .is_empty());
}

#[test]
fn prune_keeps_the_selected_previous_and_newest_releases_up_to_the_limit() {
    let fixture = Fixture::new();
    let names = [
        "initial",
        "20260101000000-a1",
        "20260102000000-a2",
        "20260103000000-a3",
    ];
    for name in names {
        fixture.prepare(name, None).unwrap();
    }
    let selected = fixture.prepare("20260104000000-a4", None).unwrap();
    fixture.activate(&selected).unwrap();
    let output = prune(
        &PruneInput {
            home: fixture.home.clone(),
            limit: 3,
            selected: selected.name.clone(),
            previous: Some("initial".into()),
        },
        &fixture.layout,
        &fixture.base.join("no-processes"),
    );
    // The process list is unreadable here, so nothing goes.
    assert_eq!(output, Err(invalid("the processes can be read")));
    fs::create_dir(fixture.base.join("no-processes")).unwrap();
    let output = prune(
        &PruneInput {
            home: fixture.home.clone(),
            limit: 3,
            selected: selected.name.clone(),
            previous: Some("initial".into()),
        },
        &fixture.layout,
        &fixture.base.join("no-processes"),
    )
    .unwrap();
    assert_eq!(
        output,
        PruneOutput {
            removed: vec!["20260101000000-a1".into(), "20260102000000-a2".into()],
            in_use: Vec::new()
        }
    );
    let left = list(&fixture.home, &fixture.layout)
        .unwrap()
        .releases
        .into_iter()
        .map(|release| release.name)
        .collect::<Vec<_>>();
    assert_eq!(left, ["20260103000000-a3", "20260104000000-a4", "initial"]);
}

#[test]
fn a_shared_path_moves_to_the_home_and_one_that_holds_the_web_root_is_refused() {
    let fixture = Fixture::new();
    let input = |paths: &[&str]| PrepareInput {
        home: fixture.home.clone(),
        name: "20260101000000-a1".into(),
        branch: "main".into(),
        commit: None,
        shared_paths: paths.iter().map(|path| path.to_string()).collect(),
        git_read: BTreeMap::new(),
    };
    let release = fixture.home.release("20260101000000-a1");
    assert_eq!(
        prepare(&input(&["public"]), &fixture.layout),
        Err(invalid("a shared path does not hold the web root"))
    );
    fs::remove_dir_all(&release).unwrap();
    prepare(&input(&["storage"]), &fixture.layout).unwrap();
    assert_eq!(
        fs::read_link(release.join("storage")).unwrap(),
        fixture.home.home.join("storage")
    );
    assert!(fixture.home.home.join("storage").is_dir());
}

#[test]
fn a_served_application_links_its_own_stable_environment() {
    let mut fixture = Fixture::new();
    fixture.home.served = vec![ServedRoot {
        web_root: "admin/public".into(),
        directory: "admin".into(),
    }];
    let release = fixture.prepare("20260101000000-a1", None).unwrap();
    let link = fixture.home.release(&release.name).join("admin/.env");
    assert_eq!(
        fs::read_link(&link).unwrap(),
        Path::new("../../../env/admin/.env")
    );
    assert_eq!(fixture.activate(&release).unwrap(), release);
    let (uid, _, _) = identity::account(&fixture.home.user).unwrap();
    assert_eq!(fs::symlink_metadata(&link).unwrap().uid(), uid);
}

#[test]
fn an_unsafe_shared_path_is_refused_before_the_release_loses_it() {
    for variant in [
        "source link",
        "destination link",
        "source file",
        "destination ancestor",
        "source ancestor",
        "seed link",
        "web root",
    ] {
        let fixture = Fixture::new();
        let release = fixture.prepare("20260101000000-a1", None).unwrap();
        let release = fixture.home.release(&release.name);
        let outside = fixture.base.join("outside");
        fs::create_dir(&outside).unwrap();
        fs::write(outside.join("keep.txt"), "untouched").unwrap();
        fs::create_dir(release.join("storage")).unwrap();
        let mut path = "storage";
        match variant {
            "source link" => {
                fs::remove_dir(release.join("storage")).unwrap();
                std::os::unix::fs::symlink(&outside, release.join("storage")).unwrap();
            }
            "destination link" => {
                std::os::unix::fs::symlink(&outside, fixture.home.home.join("storage")).unwrap()
            }
            "source file" => {
                fs::remove_dir(release.join("storage")).unwrap();
                fs::write(release.join("storage"), "untouched").unwrap();
            }
            "destination ancestor" => {
                std::os::unix::fs::symlink(&outside, fixture.home.home.join("var")).unwrap();
                path = "var/uploads";
            }
            "source ancestor" => {
                std::os::unix::fs::symlink(&outside, release.join("var")).unwrap();
                path = "var/uploads";
            }
            "seed link" => {
                std::os::unix::fs::symlink(&outside, release.join("storage/foreign")).unwrap()
            }
            _ => path = "public",
        }
        assert!(
            link_shared_paths(&fixture.home, &release, &[path.to_string()]).is_err(),
            "{variant}"
        );
        assert_eq!(
            fs::read_to_string(outside.join("keep.txt")).unwrap(),
            "untouched",
            "{variant}"
        );
        assert_eq!(
            is_link(&release.join(path)),
            variant == "source link",
            "{variant}"
        );
    }
}

#[test]
fn only_the_exact_public_storage_link_may_leave_a_web_root() {
    for (variant, allowed) in [
        ("public storage", true),
        ("unrelated link", false),
        ("wrong target", false),
        ("nested link", false),
        ("broken link", false),
        ("target ancestor link", false),
    ] {
        let fixture = Fixture::new();
        let release = fixture
            .home
            .release(&fixture.prepare("20260101000000-a1", None).unwrap().name);
        let home = &fixture.home.home;
        fs::create_dir_all(home.join("storage/app/public")).unwrap();
        std::os::unix::fs::symlink(home.join("storage"), release.join("storage")).unwrap();
        let target = if variant == "wrong target" {
            home.display().to_string()
        } else {
            "../storage/app/public".into()
        };
        std::os::unix::fs::symlink(&target, release.join("public/storage")).unwrap();
        match variant {
            "unrelated link" => {
                std::os::unix::fs::symlink(home.join(".env"), release.join("public/secret"))
                    .unwrap()
            }
            "nested link" => std::os::unix::fs::symlink(
                home.join(".env"),
                home.join("storage/app/public/secret"),
            )
            .unwrap(),
            "broken link" => {
                fs::remove_file(release.join("public/storage")).unwrap();
                std::os::unix::fs::symlink("../missing", release.join("public/storage")).unwrap();
            }
            "target ancestor link" => {
                fs::rename(home.join("storage/app/public"), home.join("uploads")).unwrap();
                std::os::unix::fs::symlink(home.join("uploads"), home.join("storage/app/public"))
                    .unwrap();
            }
            _ => {}
        }
        assert_eq!(
            fixture
                .home
                .check_web_root_links(&release, &release.join("public"))
                .is_ok(),
            allowed,
            "{variant}"
        );
    }
}

#[test]
fn prune_keeps_a_release_that_a_process_still_works_in() {
    let fixture = Fixture::new();
    for name in [
        "initial",
        "20260101000000-a1",
        "20260102000000-a2",
        "20260103000000-a3",
    ] {
        fixture.prepare(name, None).unwrap();
    }
    let selected = fixture.prepare("20260104000000-a4", None).unwrap();
    fixture.activate(&selected).unwrap();
    // A queue worker that the deployment did not restart still works in `initial`.
    let process = fixture.base.join("proc/4242");
    fs::create_dir_all(&process).unwrap();
    std::os::unix::fs::symlink(
        fixture.home.release("initial").join("public"),
        process.join("cwd"),
    )
    .unwrap();
    std::os::unix::fs::symlink("/", process.join("root")).unwrap();
    std::os::unix::fs::symlink("/usr/bin/php", process.join("exe")).unwrap();
    let output = prune(
        &PruneInput {
            home: fixture.home.clone(),
            limit: 3,
            selected: selected.name.clone(),
            previous: Some("20260103000000-a3".into()),
        },
        &fixture.layout,
        &fixture.base.join("proc"),
    )
    .unwrap();
    assert_eq!(
        output,
        PruneOutput {
            removed: vec!["20260101000000-a1".into()],
            in_use: vec!["initial".into()]
        }
    );
    assert!(fixture.home.release("initial").is_dir());
}

#[test]
fn a_rebind_moves_every_recorded_url_and_pruning_then_accepts_the_releases() {
    let mut fixture = Fixture::new();
    for name in ["initial", "20260101000000-a1"] {
        fixture.prepare(name, None).unwrap();
    }
    let selected = fixture.prepare("20260102000000-a2", None).unwrap();
    fixture.activate(&selected).unwrap();
    let binding = BindingHome {
        user: fixture.home.user.clone(),
        home: fixture.home.home.clone(),
        instance: 9,
    };
    let before = inspect_binding(&binding, &fixture.layout).unwrap();
    assert_eq!(
        before.releases.values().collect::<Vec<_>>(),
        vec![&Some(fixture.home.repository.clone()); 3]
    );
    assert!(before.markers.contains_key("release-layout"));

    let target = "https://example.test/moved.git".to_string();
    let mut changes: Vec<BindingChange> = before
        .releases
        .keys()
        .map(|name| BindingChange {
            kind: "release".into(),
            name: Some(name.clone()),
            expected: fixture.home.repository.clone(),
            target: target.clone(),
        })
        .collect();
    changes.push(BindingChange {
        kind: "release-layout".into(),
        name: None,
        expected: fixture.home.repository.clone(),
        target: target.clone(),
    });
    let input = RebindInput {
        binding: binding.clone(),
        changes,
    };
    rebind(&input, &fixture.layout).unwrap();
    // A repeated rebind converges.
    rebind(&input, &fixture.layout).unwrap();

    fixture.home.repository = target.clone();
    let after = inspect_binding(&binding, &fixture.layout).unwrap();
    assert!(after
        .releases
        .values()
        .all(|origin| origin.as_deref() == Some(target.as_str())));
    assert_eq!(
        list(&fixture.home, &fixture.layout).unwrap().releases.len(),
        3
    );
}

#[test]
fn a_rebind_refuses_a_url_that_is_neither_the_expected_nor_the_target() {
    let fixture = Fixture::new();
    fixture.prepare("initial", None).unwrap();
    let binding = BindingHome {
        user: fixture.home.user.clone(),
        home: fixture.home.home.clone(),
        instance: 9,
    };
    let change = |kind: &str, name: Option<&str>| BindingChange {
        kind: kind.into(),
        name: name.map(str::to_string),
        expected: "https://example.test/other.git".into(),
        target: "https://example.test/moved.git".into(),
    };
    assert_eq!(
        rebind(
            &RebindInput {
                binding: binding.clone(),
                changes: vec![change("release", Some("initial"))]
            },
            &fixture.layout
        ),
        Err(invalid("the release records the expected URL"))
    );
    assert_eq!(
        rebind(
            &RebindInput {
                binding,
                changes: vec![change("release-layout", None)]
            },
            &fixture.layout
        ),
        Err(invalid("the marker records the expected URL"))
    );
    assert_eq!(
        list(&fixture.home, &fixture.layout).unwrap().releases.len(),
        1
    );
}
