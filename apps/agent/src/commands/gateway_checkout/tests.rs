use super::*;

#[test]
fn checkout_mutation_can_acquire_its_declared_locks() {
    let (_work, files, input) = fixture();
    let held = crate::command::lock::acquire_all(
        &files.root.join("locks"),
        Prepare::target(&input).as_deref(),
        Prepare::RESOURCES,
        std::time::Instant::now(),
    )
    .expect("checkout preparation must not wait on its own lock");
    assert!(!held.is_empty());
}
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::symlink;

fn fixture() -> (WorkDir, Files, Input) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    let input = Input {
        path: "/home/orbit/orbit/apps/gateway".into(),
    };
    fs::create_dir_all(files.path(&format!("{}/public/assets", input.path))).unwrap();
    fs::create_dir_all(files.path(&format!("{}/private", input.path))).unwrap();
    for (name, mode) in [
        (".env", 0o640),
        ("public/index.php", 0o600),
        ("public/assets/app.css", 0o600),
        ("private/secret", 0o600),
    ] {
        let p = files.path(&format!("{}/{name}", input.path));
        fs::write(&p, name).unwrap();
        fs::set_permissions(p, fs::Permissions::from_mode(mode)).unwrap();
    }
    fs::set_permissions(
        files.path(&format!("{}/public/assets", input.path)),
        fs::Permissions::from_mode(0o700),
    )
    .unwrap();
    (work, files, input)
}
fn prepare(files: &Files, input: &Input) -> Result<Output, Error> {
    apply(&plan(files, input, files.uid, files.gid)?, set)
}
fn mode(files: &Files, path: &str) -> u32 {
    fs::symlink_metadata(files.path(path)).unwrap().mode() & 0o7777
}
#[test]
fn grants_only_public_access_and_repeats_without_changes() {
    let (_work, files, input) = fixture();
    let public = files.path(&format!("{}/public", input.path));
    symlink("../private", public.join("linked-directory")).unwrap();
    symlink("../private/secret", public.join("linked-file")).unwrap();
    let before = mode(&files, "/home/orbit");
    inspect(&files, &input, files.uid).unwrap();
    assert_eq!(mode(&files, "/home/orbit"), before);
    assert!(prepare(&files, &input).unwrap().changed);
    for p in [
        "/home/orbit",
        "/home/orbit/orbit",
        "/home/orbit/orbit/apps",
        "/home/orbit/orbit/apps/gateway",
    ] {
        assert_eq!(mode(&files, p), 0o710);
    }
    for p in ["public", "public/assets"] {
        assert_eq!(mode(&files, &format!("{}/{p}", input.path)), 0o750);
    }
    for p in ["public/index.php", "public/assets/app.css"] {
        assert_eq!(mode(&files, &format!("{}/{p}", input.path)), 0o640);
    }
    assert_eq!(mode(&files, &format!("{}/.env", input.path)), 0o600);
    assert_eq!(
        mode(&files, &format!("{}/private/secret", input.path)),
        0o600
    );
    assert_eq!(
        fs::read_link(public.join("linked-directory")).unwrap(),
        Path::new("../private")
    );
    assert_eq!(
        fs::read_link(public.join("linked-file")).unwrap(),
        Path::new("../private/secret")
    );
    assert!(!prepare(&files, &input).unwrap().changed);
}
#[test]
fn resolves_relative_and_absolute_release_links_without_rewriting_immutable_trees() {
    for absolute in [false, true] {
        let (_work, files, input) = fixture();
        let current = files.path("/home/orbit/orbit");
        let release = files.path("/home/orbit/releases/0123456789ab");
        fs::create_dir_all(release.parent().unwrap()).unwrap();
        fs::rename(&current, &release).unwrap();
        let env = files.path("/home/orbit/shared/gateway.env");
        fs::create_dir_all(env.parent().unwrap()).unwrap();
        fs::rename(release.join("apps/gateway/.env"), &env).unwrap();
        symlink(
            "/home/orbit/shared/gateway.env",
            release.join("apps/gateway/.env"),
        )
        .unwrap();
        symlink(
            if absolute {
                "/home/orbit/releases/0123456789ab"
            } else {
                "releases/0123456789ab"
            },
            &current,
        )
        .unwrap();
        fs::set_permissions(&release, fs::Permissions::from_mode(0o550)).unwrap();
        let public = release.join("apps/gateway/public");
        fs::set_permissions(&public, fs::Permissions::from_mode(0o550)).unwrap();
        let before = fs::metadata(public.join("index.php")).unwrap();
        assert!(prepare(&files, &input).unwrap().changed);
        assert_eq!(mode(&files, "/home/orbit"), 0o710);
        assert_eq!(mode(&files, "/home/orbit/releases"), 0o710);
        assert_eq!(mode(&files, "/home/orbit/shared/gateway.env"), 0o600);
        assert_eq!(fs::metadata(&release).unwrap().mode() & 0o7777, 0o550);
        assert_eq!(fs::metadata(&public).unwrap().mode() & 0o7777, 0o550);
        assert_eq!(
            fs::metadata(public.join("index.php")).unwrap().mode(),
            before.mode()
        );
        assert!(!prepare(&files, &input).unwrap().changed);
        fs::set_permissions(&release, fs::Permissions::from_mode(0o750)).unwrap();
        fs::set_permissions(&public, fs::Permissions::from_mode(0o750)).unwrap();
    }
}
#[test]
fn prepares_a_new_release_with_its_shared_environment_before_it_is_read_only() {
    let (_work, files, mut input) = fixture();
    fs::create_dir(files.path("/home/orbit/releases")).unwrap();
    fs::rename(
        files.path("/home/orbit/orbit"),
        files.path("/home/orbit/releases/0123456789ab"),
    )
    .unwrap();
    input.path = "/home/orbit/releases/0123456789ab/apps/gateway".into();
    let env = files.path("/home/orbit/shared/gateway.env");
    fs::create_dir(env.parent().unwrap()).unwrap();
    fs::rename(files.path(&format!("{}/.env", input.path)), &env).unwrap();
    symlink(
        "/home/orbit/shared/gateway.env",
        files.path(&format!("{}/.env", input.path)),
    )
    .unwrap();
    assert!(prepare(&files, &input).unwrap().changed);
    assert_eq!(
        mode(&files, &format!("{}/public/index.php", input.path)),
        0o640
    );
    assert_eq!(mode(&files, "/home/orbit/shared/gateway.env"), 0o600);
    assert!(!prepare(&files, &input).unwrap().changed);
}
#[test]
fn refuses_unsafe_paths_environment_and_release_links_before_permission_changes() {
    for path in [
        "/etc",
        "/home/orbit",
        "/home/orbit/",
        "/home/orbit/../elsewhere",
        "/home/orbit/./orbit",
        "/home/orbit//orbit",
        "/home/orbit/orbit/",
        "/home/orbit/orbit\n",
    ] {
        let (_work, files, _) = fixture();
        assert!(matches!(
            prepare(&files, &Input { path: path.into() }),
            Err(Error::Invalid {})
        ));
        assert_ne!(mode(&files, "/home/orbit"), 0o710);
    }
    for name in ["public", ".env", "public/assets"] {
        let (_work, files, input) = fixture();
        let path = files.path(&format!("{}/{name}", input.path));
        if path.is_dir() {
            fs::remove_dir_all(&path).unwrap();
        } else {
            fs::remove_file(&path).unwrap();
        }
        symlink("/outside", &path).unwrap();
        if name == "public/assets" {
            // Public symlinks are data and never traversed.
            assert!(prepare(&files, &input).is_ok());
        } else {
            assert!(matches!(prepare(&files, &input), Err(Error::Invalid {})));
            assert_ne!(mode(&files, "/home/orbit"), 0o710);
        }
    }
    for to in [
        "/outside",
        "releases/../../outside",
        "releases/0123456789AB",
        "releases/0123456789ab/other",
    ] {
        let (_work, files, input) = fixture();
        let current = files.path("/home/orbit/orbit");
        fs::remove_dir_all(&current).unwrap();
        symlink(to, current).unwrap();
        assert!(matches!(prepare(&files, &input), Err(Error::Invalid {})));
        assert_ne!(mode(&files, "/home/orbit"), 0o710);
    }
}
#[test]
fn restores_all_prior_permissions_after_partial_failure_and_reports_recovery_failure() {
    for recovery_fails in [false, true] {
        let (_work, files, input) = fixture();
        let changes = plan(&files, &input, files.uid, files.gid).unwrap();
        let mut count = 0;
        let result = apply(&changes, |path, access| {
            count += 1;
            if count == 3 || recovery_fails && count == 4 {
                return Err(io::Error::other("fixture failure"));
            }
            set(path, access)
        });
        assert_eq!(
            result.unwrap_err(),
            if recovery_fails {
                Error::RecoveryFailed {}
            } else {
                failed("access")
            }
        );
        for change in &changes {
            assert!(Access::of(&fs::metadata(&change.path).unwrap()) == change.prior);
        }
    }
}
#[test]
fn environment_failure_restores_public_and_ancestor_permissions() {
    let (_work, files, input) = fixture();
    let changes = plan(&files, &input, files.uid, files.gid).unwrap();
    let mut failed_once = false;
    let result = apply(&changes, |path, access| {
        if path.ends_with(".env") && !failed_once {
            failed_once = true;
            return Err(io::Error::other("fixture failure"));
        }
        set(path, access)
    });
    assert_eq!(result.unwrap_err(), failed("environment"));
    for change in &changes {
        assert!(Access::of(&fs::metadata(&change.path).unwrap()) == change.prior);
    }
}
