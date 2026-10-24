use super::*;
use crate::commands::recipe::WorkDir;
use std::{io::Read, os::unix::fs::PermissionsExt, time::Instant};
const STAMP: &str = "20261024T120000Z";
struct Fixture {
    _work: WorkDir,
    layout: Layout,
    input: SwitchInput,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let base = work.0.join("home");
        let checkout = base.join("orbit/apps/gateway");
        fs::create_dir_all(checkout.join("storage/logs")).unwrap();
        fs::write(checkout.join("storage/logs/.gitignore"), "*\n!.gitignore\n").unwrap();
        fs::write(checkout.join("storage/logs/laravel.log"), "original log").unwrap();
        fs::set_permissions(
            checkout.join("storage/logs/laravel.log"),
            fs::Permissions::from_mode(0o640),
        )
        .unwrap();
        let current = base.join("orbit");
        let repo = git2::Repository::init(&current).unwrap();
        let mut index = repo.index().unwrap();
        index
            .add_path(Path::new("apps/gateway/storage/logs/.gitignore"))
            .unwrap();
        index.write().unwrap();
        let tree = repo.find_tree(index.write_tree().unwrap()).unwrap();
        let signature =
            git2::Signature::new("Orbit", "orbit@example.test", &git2::Time::new(0, 0)).unwrap();
        let sha = repo
            .commit(Some("HEAD"), &signature, &signature, "fixture", &tree, &[])
            .unwrap()
            .to_string();
        let id = &sha[..12];
        fs::create_dir_all(base.join("shared")).unwrap();
        fs::create_dir_all(base.join("releases").join(id).join("apps/gateway")).unwrap();
        fs::write(base.join("releases").join(id).join("REVISION"), &sha).unwrap();
        let input = SwitchInput {
            checkout: checkout.to_str().unwrap().into(),
            release: id.into(),
            stamp: STAMP.into(),
        };
        let layout = Layout::new(&input.checkout).unwrap();
        Self {
            _work: work,
            layout,
            input,
        }
    }
    fn source(&self) -> PathBuf {
        self.layout.current.join("apps/gateway/storage")
    }
    fn target(&self) -> PathBuf {
        self.layout.shared().join("gateway-storage")
    }
    fn parking(&self) -> PathBuf {
        self.layout.current.join("apps/gateway/storage.adopt-link")
    }
    fn storage(&self) -> StorageInput {
        StorageInput {
            checkout: self.input.checkout.clone(),
        }
    }
    fn finish(&self) -> FinishInput {
        FinishInput {
            checkout: self.input.checkout.clone(),
            stamp: STAMP.into(),
        }
    }
}
fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30))
}
#[test]
fn storage_preserves_open_files_permissions_and_tracked_index_and_repeats_without_replacement() {
    let f = Fixture::new();
    let original = fs::metadata(f.source()).unwrap().ino();
    let mut open = fs::File::open(f.source().join("logs/laravel.log")).unwrap();
    let out = storage(&f.storage(), &mut Native(&context()), |first, second| {
        exchange(first, second)?;
        assert_eq!(
            fs::read_to_string(f.source().join("logs/laravel.log")).unwrap(),
            "original log"
        );
        Ok(())
    })
    .unwrap();
    assert_eq!(out.storage, "moved");
    assert_eq!(fs::metadata(f.target()).unwrap().ino(), original);
    assert_eq!(
        fs::metadata(f.target().join("logs/laravel.log"))
            .unwrap()
            .mode()
            & 0o777,
        0o640
    );
    let mut text = String::new();
    open.read_to_string(&mut text).unwrap();
    assert_eq!(text, "original log");
    let index = f.layout.current.join(".git/index");
    let bytes = fs::read(&index).unwrap();
    let ino = fs::metadata(&index).unwrap().ino();
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), exchange)
            .unwrap()
            .storage,
        "existing"
    );
    assert_eq!(fs::read(&index).unwrap(), bytes);
    assert_eq!(fs::metadata(&index).unwrap().ino(), ino);
    assert!(meta(&f.parking()).unwrap().is_none());
    let status = recipe::run(
        &mut Native(&context()),
        &Invocation::new(&[
            "/usr/bin/git",
            "-C",
            f.layout.current.to_str().unwrap(),
            "status",
            "--porcelain",
            "--untracked-files=no",
        ]),
        true,
    )
    .unwrap();
    assert!(
        !status
            .stdout
            .lines()
            .any(|s| s.as_bytes().get(1) == Some(&b'D')),
        "{}",
        status.stdout
    );
}
#[test]
fn unavailable_exchange_keeps_storage_and_cleans_staging_without_marking_index() {
    let f = Fixture::new();
    let before = fs::read(f.layout.current.join(".git/index")).unwrap();
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), |_, _| Err(
            io::Error::from_raw_os_error(libc::EOPNOTSUPP)
        ))
        .unwrap_err(),
        failed("storage-exchange")
    );
    assert!(fs::symlink_metadata(f.source()).unwrap().is_dir());
    assert!(meta(&f.target()).unwrap().is_none() && meta(&f.parking()).unwrap().is_none());
    assert_eq!(
        before,
        fs::read(f.layout.current.join(".git/index")).unwrap()
    );
}
#[test]
fn second_exchange_failure_keeps_storage_reachable_and_repeat_finishes_the_move() {
    let f = Fixture::new();
    let mut n = 0;
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), |a, b| {
            n += 1;
            if n == 2 {
                Err(io::Error::other("injected"))
            } else {
                exchange(a, b)
            }
        })
        .unwrap_err(),
        failed("storage-exchange")
    );
    assert_eq!(
        fs::read_to_string(f.source().join("logs/laravel.log")).unwrap(),
        "original log"
    );
    let status = recipe::run(
        &mut Native(&context()),
        &Invocation::new(&[
            "/usr/bin/git",
            "-C",
            f.layout.current.to_str().unwrap(),
            "status",
            "--porcelain",
            "--untracked-files=no",
        ]),
        true,
    )
    .unwrap();
    assert!(
        status.stdout.is_empty(),
        "An interrupted move must pass adoption's clean-checkout guard: {}",
        status.stdout
    );
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), exchange)
            .unwrap()
            .storage,
        "moved"
    );
    assert!(fs::symlink_metadata(f.target()).unwrap().is_dir());
}
#[test]
fn storage_recovers_pre_exchange_links_and_final_cleanup() {
    for finished in [false, true] {
        let f = Fixture::new();
        symlink(f.parking(), f.target()).unwrap();
        symlink(f.target(), f.parking()).unwrap();
        if finished {
            exchange(&f.source(), &f.parking()).unwrap();
            exchange(&f.target(), &f.parking()).unwrap();
        }
        storage(&f.storage(), &mut Native(&context()), exchange).unwrap();
        assert!(meta(&f.parking()).unwrap().is_none());
        assert_eq!(
            fs::read_to_string(f.source().join("logs/laravel.log")).unwrap(),
            "original log"
        );
    }
}
#[test]
fn storage_conflicts_and_missing_source_refuse_before_changing_either_staging_name() {
    let f = Fixture::new();
    symlink(f.parking(), f.target()).unwrap();
    fs::write(f.parking(), "foreign").unwrap();
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), exchange).unwrap_err(),
        Error::StorageConflict {}
    );
    assert_eq!(fs::read_link(f.target()).unwrap(), f.parking());
    assert_eq!(fs::read_to_string(f.parking()).unwrap(), "foreign");
    let f = Fixture::new();
    fs::rename(f.source(), f.layout.base.join("saved")).unwrap();
    assert_eq!(
        storage(&f.storage(), &mut Native(&context()), exchange).unwrap_err(),
        Error::StorageMissing {}
    );
    assert!(meta(&f.target()).unwrap().is_none());
}
#[test]
fn switch_and_restore_preserve_the_checkout_and_repeat() {
    let f = Fixture::new();
    let ino = fs::metadata(&f.layout.current).unwrap().ino();
    let out = switch(&f.input, exchange).unwrap();
    assert_eq!(out.method, "exchange");
    assert_eq!(out.gap_us, 0);
    assert_eq!(fs::metadata(&out.from).unwrap().ino(), ino);
    assert_eq!(
        fs::read_link(&f.layout.current).unwrap(),
        Path::new("releases").join(&f.input.release)
    );
    assert_eq!(switch(&f.input, exchange).unwrap().from, out.from);
    assert!(restore(&f.input, exchange).unwrap().changed);
    assert_eq!(fs::metadata(&f.layout.current).unwrap().ino(), ino);
    assert!(!restore(&f.input, exchange).unwrap().changed);
    assert!(meta(Path::new(&out.from)).unwrap().is_none());
}
#[test]
fn switching_refuses_unprepared_release_and_foreign_staging_or_kept_paths_without_mutation() {
    for which in ["revision", "next", "kept"] {
        let f = Fixture::new();
        let path = match which {
            "revision" => f
                .layout
                .base
                .join("releases")
                .join(&f.input.release)
                .join("REVISION"),
            "next" => f.layout.next(),
            _ => f.layout.kept(STAMP).unwrap(),
        };
        fs::write(&path, "foreign").unwrap();
        let e = switch(&f.input, exchange).unwrap_err();
        assert_eq!(
            e,
            if which == "revision" {
                Error::NotPrepared {}
            } else {
                Error::Unsafe {}
            }
        );
        assert_eq!(fs::read_to_string(path).unwrap(), "foreign");
        assert!(fs::symlink_metadata(&f.layout.current).unwrap().is_dir());
    }
}
#[test]
fn failed_switch_and_restore_exchanges_keep_the_serving_path() {
    let f = Fixture::new();
    assert_eq!(
        switch(&f.input, |_, _| Err(io::Error::other("no exchange"))).unwrap_err(),
        failed("switch-exchange")
    );
    assert!(fs::symlink_metadata(&f.layout.current).unwrap().is_dir());
    assert!(meta(&f.layout.next()).unwrap().is_none());
    switch(&f.input, exchange).unwrap();
    assert_eq!(
        restore(&f.input, |_, _| Err(io::Error::other("no exchange"))).unwrap_err(),
        failed("restore-exchange")
    );
    assert_eq!(
        fs::read_link(&f.layout.current).unwrap(),
        Path::new("releases").join(&f.input.release)
    );
    assert!(fs::symlink_metadata(f.layout.kept(STAMP).unwrap())
        .unwrap()
        .is_dir());
}
#[test]
fn finishes_a_switch_after_exchange_and_refuses_cleanup_of_foreign_state() {
    let f = Fixture::new();
    symlink(
        Path::new("releases").join(&f.input.release),
        f.layout.next(),
    )
    .unwrap();
    exchange(&f.layout.next(), &f.layout.current).unwrap();
    let out = finish(&f.finish()).unwrap();
    assert_eq!(
        out.path,
        Some(f.layout.kept(STAMP).unwrap().to_string_lossy().into())
    );
    assert_eq!(finish(&f.finish()).unwrap().path, None);
    restore(&f.input, exchange).unwrap();
    fs::write(f.layout.next(), "foreign").unwrap();
    assert_eq!(finish(&f.finish()).unwrap_err(), Error::Unsafe {});
    assert_eq!(fs::read_to_string(f.layout.next()).unwrap(), "foreign");
}
#[test]
fn validates_identity_and_path_components_before_changes() {
    let mut f = Fixture::new();
    for stamp in ["", "../escape", "20261024X120000Z"] {
        f.input.stamp = stamp.into();
        assert_eq!(switch(&f.input, exchange).unwrap_err(), Error::Invalid {});
    }
    f.input.stamp = STAMP.into();
    f.input.release = "../escape".into();
    assert_eq!(switch(&f.input, exchange).unwrap_err(), Error::Invalid {});
    for checkout in [
        "relative/apps/gateway",
        "/tmp/../apps/gateway",
        "/tmp//orbit/apps/gateway",
        "/orbit/apps/gateway",
        "/tmp/releases/apps/gateway",
    ] {
        assert!(matches!(Layout::new(checkout), Err(Error::Invalid {})));
    }
    let f = Fixture::new();
    let actual = f.layout.base.with_extension("real");
    fs::rename(&f.layout.base, &actual).unwrap();
    symlink(&actual, &f.layout.base).unwrap();
    assert!(matches!(
        Layout::new(&f.input.checkout),
        Err(Error::Unsafe {})
    ));
}

#[test]
fn refuses_checkout_identity_changes_and_unrelated_current_links() {
    let f = Fixture::new();
    let repository = git2::Repository::open(&f.layout.current).unwrap();
    let parent = repository.head().unwrap().peel_to_commit().unwrap();
    let tree = parent.tree().unwrap();
    let signature =
        git2::Signature::new("Orbit", "orbit@example.test", &git2::Time::new(1, 0)).unwrap();
    repository
        .commit(
            Some("HEAD"),
            &signature,
            &signature,
            "different checkout",
            &tree,
            &[&parent],
        )
        .unwrap();
    assert_eq!(switch(&f.input, exchange).unwrap_err(), Error::Unsafe {});
    assert!(meta(&f.layout.next()).unwrap().is_none());
    let f = Fixture::new();
    switch(&f.input, exchange).unwrap();
    fs::remove_file(&f.layout.current).unwrap();
    symlink("foreign", &f.layout.current).unwrap();
    assert_eq!(restore(&f.input, exchange).unwrap_err(), Error::Unsafe {});
    assert_eq!(
        fs::read_link(&f.layout.current).unwrap(),
        Path::new("foreign")
    );
}

#[test]
fn restore_cleans_its_leftover_link_after_a_lost_result() {
    let f = Fixture::new();
    switch(&f.input, exchange).unwrap();
    let kept = f.layout.kept(STAMP).unwrap();
    exchange(&kept, &f.layout.current).unwrap();
    assert!(!restore(&f.input, exchange).unwrap().changed);
    assert!(meta(&kept).unwrap().is_none());
}
