use super::*;
use crate::{command::CommandError, commands::recipe::WorkDir};
use std::os::unix::fs::{symlink, PermissionsExt};

struct Fixture {
    work: WorkDir,
    input: Input,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        for name in [
            "home",
            "shared/gateway-storage",
            "releases",
            "orbit/apps/gateway/bootstrap/cache",
        ] {
            fs::create_dir_all(work.0.join(name)).unwrap();
        }
        for name in [
            "gateway.sqlite",
            "shared/gateway.env",
            "orbit/apps/gateway/bootstrap/cache/config.php",
        ] {
            fs::write(work.0.join(name), "preserved").unwrap();
            fs::set_permissions(work.0.join(name), fs::Permissions::from_mode(0o600)).unwrap();
        }
        let input = Input {
            checkout: work.0.join("orbit/apps/gateway").to_str().unwrap().into(),
            orbit_home: work.0.join("home"),
            database: work.0.join("gateway.sqlite"),
            caller_uid: identity::effective_uid(),
            sha: None,
            runtime_cache: None,
        };
        Self { work, input }
    }
    fn inspect(&self) -> Result<Output, Error> {
        inspect(&self.input, identity::effective_uid())
    }
    fn target(&mut self) -> PathBuf {
        self.input.sha = Some("a".repeat(40));
        self.work.0.join("releases/aaaaaaaaaaaa")
    }
    fn cache(&self) -> PathBuf {
        Path::new(&self.input.checkout).join("bootstrap/cache/config.php")
    }
    fn refuse(&self) {
        assert_eq!(
            self.inspect().unwrap_err().code(),
            "gateway.release_owner_invalid"
        );
        assert_eq!(
            fs::read(self.work.0.join("gateway.sqlite")).unwrap(),
            b"preserved"
        );
        assert!(!self
            .input
            .orbit_home
            .join("gateway-release.paused")
            .exists());
    }
}

#[test]
fn accepts_owned_state_repeats_and_returns_unique_private_caches_without_creating_locks() {
    let mut f = Fixture::new();
    f.input.runtime_cache = Some(f.cache());
    let target = f.target();
    fs::create_dir_all(target.join("apps/gateway/bootstrap/cache")).unwrap();
    let cache = target.join("apps/gateway/bootstrap/cache/config.php");
    fs::write(&cache, "target configuration").unwrap();
    fs::set_permissions(&cache, fs::Permissions::from_mode(0o600)).unwrap();
    let expected = Output {
        caches: vec![f.cache(), cache.clone()],
    };
    for _ in 0..2 {
        assert_eq!(f.inspect().unwrap(), expected);
    }
    assert_eq!(fs::read(&cache).unwrap(), b"target configuration");
    assert_eq!(fs::read_dir(&f.input.orbit_home).unwrap().count(), 0);
}

#[test]
fn refuses_root_wrong_process_or_service_account_and_noncanonical_database() {
    let mut f = Fixture::new();
    assert!(inspect(&f.input, 0).is_err());
    assert!(inspect(&f.input, identity::effective_uid() + 1).is_err());
    for caller in [0, identity::effective_uid() + 1] {
        f.input.caller_uid = caller;
        f.refuse();
    }
    f.input.caller_uid = identity::effective_uid();
    symlink(&f.input.database, f.work.0.join("alias.sqlite")).unwrap();
    for path in [
        PathBuf::from("relative.sqlite"),
        f.work.0.join("alias.sqlite"),
        f.work.0.join("missing.sqlite"),
        f.work.0.clone(),
        PathBuf::from("/etc/passwd"),
    ] {
        f.input.database = path;
        f.refuse();
    }
}

#[test]
fn refuses_unsafe_required_state_without_repairing_it() {
    for name in [
        "home",
        "shared",
        "shared/gateway-storage",
        "releases",
        "shared/gateway.env",
        "orbit/apps/gateway/bootstrap/cache/config.php",
    ] {
        for fault in ["writable", "unreadable", "missing", "symlink"] {
            let f = Fixture::new();
            let path = f.work.0.join(name);
            let mode = if path.is_dir() { 0o700 } else { 0o600 };
            match fault {
                "writable" => {
                    fs::set_permissions(&path, fs::Permissions::from_mode(mode | 0o022)).unwrap()
                }
                "unreadable" => {
                    fs::set_permissions(&path, fs::Permissions::from_mode(0o000)).unwrap()
                }
                "missing" | "symlink" => {
                    let kept = f.work.0.join("kept");
                    fs::rename(&path, &kept).unwrap();
                    if fault == "symlink" {
                        symlink(kept, &path).unwrap();
                    }
                }
                _ => unreachable!(),
            }
            let before = fs::symlink_metadata(&path)
                .ok()
                .map(|m| (m.ino(), m.mode()));
            f.refuse();
            assert_eq!(
                fs::symlink_metadata(&path)
                    .ok()
                    .map(|m| (m.ino(), m.mode())),
                before,
                "{name} {fault}"
            );
            if fault == "unreadable" {
                fs::set_permissions(&path, fs::Permissions::from_mode(mode)).unwrap();
            }
        }
    }
}

#[test]
fn optional_locks_must_be_private_regular_files_and_other_existing_state_must_be_owned() {
    for name in ["gateway-release.lock", "gateway-release-step.lock"] {
        for fault in ["permissive", "special-mode", "directory", "link"] {
            let f = Fixture::new();
            let path = f.input.orbit_home.join(name);
            match fault {
                "permissive" | "special-mode" => {
                    fs::write(&path, "keep").unwrap();
                    fs::set_permissions(
                        &path,
                        fs::Permissions::from_mode(if fault == "permissive" {
                            0o644
                        } else {
                            0o1600
                        }),
                    )
                    .unwrap();
                }
                "directory" => fs::create_dir(&path).unwrap(),
                "link" => symlink(&f.input.database, &path).unwrap(),
                _ => unreachable!(),
            }
            let before = fs::symlink_metadata(&path).unwrap();
            f.refuse();
            let after = fs::symlink_metadata(&path).unwrap();
            assert_eq!((before.ino(), before.mode()), (after.ino(), after.mode()));
        }
    }
    let f = Fixture::new();
    for name in [
        "gateway-release.lock",
        "gateway-release-step.lock",
        "gateway-release.paused",
    ] {
        let path = f.input.orbit_home.join(name);
        fs::write(&path, "keep").unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o600)).unwrap();
    }
    for name in ["backups", "recovery"] {
        fs::create_dir(f.input.orbit_home.join(name)).unwrap();
    }
    assert!(f.inspect().is_ok());
    for name in ["backups", "recovery", "gateway-release.paused"] {
        let path = f.input.orbit_home.join(name);
        let mode = fs::metadata(&path).unwrap().mode();
        fs::set_permissions(&path, fs::Permissions::from_mode(mode | 0o020)).unwrap();
        assert!(f.inspect().is_err());
        assert_eq!(fs::metadata(&path).unwrap().mode(), mode | 0o020);
        fs::set_permissions(&path, fs::Permissions::from_mode(mode)).unwrap();
    }
}

#[test]
fn revalidates_prepared_target_and_runtime_cache_before_returning_any_paths() {
    for fault in [
        "missing-cache",
        "directory-mode",
        "cache-mode",
        "target-link",
        "runtime-missing",
        "runtime-mode",
    ] {
        let mut f = Fixture::new();
        let target = f.target();
        let directory = target.join("apps/gateway/bootstrap/cache");
        fs::create_dir_all(&directory).unwrap();
        fs::write(target.join("REVISION"), "a".repeat(40)).unwrap();
        let cache = directory.join("config.php");
        fs::write(&cache, "target").unwrap();
        fs::set_permissions(&cache, fs::Permissions::from_mode(0o600)).unwrap();
        match fault {
            "missing-cache" => fs::remove_file(&cache).unwrap(),
            "directory-mode" => {
                fs::set_permissions(&directory, fs::Permissions::from_mode(0o775)).unwrap()
            }
            "cache-mode" => fs::set_permissions(&cache, fs::Permissions::from_mode(0o644)).unwrap(),
            "target-link" => {
                let kept = f.work.0.join("kept");
                fs::rename(&target, &kept).unwrap();
                symlink(kept, &target).unwrap();
            }
            "runtime-missing" => f.input.runtime_cache = Some(f.work.0.join("absent.php")),
            "runtime-mode" => {
                let runtime = f.work.0.join("runtime.php");
                fs::write(&runtime, "runtime").unwrap();
                fs::set_permissions(&runtime, fs::Permissions::from_mode(0o644)).unwrap();
                f.input.runtime_cache = Some(runtime);
            }
            _ => unreachable!(),
        }
        f.refuse();
    }
    let mut f = Fixture::new();
    let target = f.target();
    assert!(f.inspect().is_ok());
    fs::create_dir(&target).unwrap();
    assert!(f.inspect().is_ok()); // An unprepared worktree has no cache to load yet.
}

#[test]
fn refuses_invalid_release_intent_before_observation() {
    for fault in ["checkout", "home", "sha", "runtime"] {
        let mut f = Fixture::new();
        match fault {
            "checkout" => f.input.checkout = "/wrong".into(),
            "home" => f.input.orbit_home = "relative".into(),
            "sha" => f.input.sha = Some("main".into()),
            "runtime" => f.input.runtime_cache = Some("relative.php".into()),
            _ => unreachable!(),
        }
        assert_eq!(
            f.inspect().unwrap_err().code(),
            "gateway.release_owner_invalid"
        );
        assert_eq!(fs::read_dir(f.work.0.join("home")).unwrap().count(), 0);
    }
}
