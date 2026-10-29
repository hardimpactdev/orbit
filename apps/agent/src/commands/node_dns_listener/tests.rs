use super::*;
use crate::commands::recipe::WorkDir;

#[derive(Default)]
struct FakeHost {
    active: bool,
    calls: Vec<&'static str>,
    fail: Option<&'static str>,
}
impl FakeHost {
    fn call(&mut self, name: &'static str) -> bool {
        self.calls.push(name);
        if self.fail == Some(name) {
            self.fail = None;
            false
        } else {
            true
        }
    }
}
impl Host for FakeHost {
    fn validate(&mut self, release: &Path, service: &Path, socket: &Path) -> bool {
        assert!(release.join("serve.php").is_file());
        assert!(service.is_file() && socket.is_file());
        self.call("validate")
    }
    fn active(&mut self) -> bool {
        self.calls.push("active");
        self.active
    }
    fn reload(&mut self) -> bool {
        self.call("reload")
    }
    fn restart(&mut self) -> bool {
        self.call("restart")
    }
}
fn input(contents: &str) -> Input {
    let digest = format!("{:x}", Sha256::digest(contents.as_bytes()));
    let id = format!("{:x}", Sha256::digest(format!("serve.php\0{digest}\n")))[..16].to_owned();
    Input {
        service: include_str!("test.service").replace("RELEASE", &id),
        socket: include_str!("test.socket").into(),
        release_id: id,
        files: vec![ReleaseFile {
            path: "serve.php".into(),
            contents: contents.into(),
            sha256: digest,
        }],
    }
}
fn fixture() -> (WorkDir, Files, FakeHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    (work, files, FakeHost::default())
}
fn catalog(files: &Files) -> std::path::PathBuf {
    let path = files.path("/var/lib/orbit/private-dns/catalog.json");
    fs::create_dir_all(path.parent().unwrap()).unwrap();
    fs::write(&path, "unchanged catalog").unwrap();
    path
}
#[test]
fn fresh_install_stages_units_without_starting_and_repeats_without_replacing_them() {
    let (_work, files, mut host) = fixture();
    let input = input("<?php // release one");
    let catalog = catalog(&files);
    assert!(install(&files, &mut host, &input).unwrap().changed);
    assert_eq!(host.calls, ["validate", "active", "reload"]);
    assert_eq!(fs::read_to_string(&catalog).unwrap(), "unchanged catalog");
    let inode = fs::metadata(files.path(UNIT)).unwrap().ino();
    host.calls.clear();
    assert!(!install(&files, &mut host, &input).unwrap().changed);
    assert_eq!(host.calls, ["validate"]);
    assert_eq!(fs::metadata(files.path(UNIT)).unwrap().ino(), inode);
    assert_eq!(fs::read_dir(files.path(ROOT)).unwrap().count(), 1);
}
#[test]
fn active_upgrade_restarts_only_service_against_existing_catalog_and_preserves_old_release() {
    let (_work, files, mut host) = fixture();
    let old = input("<?php // old");
    let new = input("<?php // new");
    install(&files, &mut host, &old).unwrap();
    let catalog = catalog(&files);
    host.calls.clear();
    host.active = true;
    install(&files, &mut host, &new).unwrap();
    assert_eq!(host.calls, ["validate", "active", "reload", "restart"]);
    assert_eq!(fs::read_to_string(files.path(UNIT)).unwrap(), new.service);
    assert!(files.path(ROOT).join(&old.release_id).is_dir());
    assert_eq!(fs::read_to_string(catalog).unwrap(), "unchanged catalog");
}
#[test]
fn failed_validation_or_activation_preserves_old_units_and_catalog() {
    for failure in ["validate", "reload", "restart"] {
        let (_work, files, mut host) = fixture();
        let old = input("<?php // old");
        let new = input("<?php // new");
        install(&files, &mut host, &old).unwrap();
        let catalog = catalog(&files);
        host.active = true;
        host.calls.clear();
        host.fail = Some(failure);
        assert!(install(&files, &mut host, &new).is_err());
        assert_eq!(fs::read_to_string(files.path(UNIT)).unwrap(), old.service);
        assert_eq!(fs::read_to_string(files.path(SOCKET)).unwrap(), old.socket);
        assert_eq!(fs::read_to_string(catalog).unwrap(), "unchanged catalog");
        if failure == "validate" {
            assert_eq!(host.calls, ["validate"]);
        } else {
            assert_eq!(&host.calls[host.calls.len() - 2..], ["reload", "restart"]);
        }
    }
}
#[test]
fn repairs_corrupt_owned_release_without_touching_catalog() {
    let (_work, files, mut host) = fixture();
    let input = input("<?php // correct");
    install(&files, &mut host, &input).unwrap();
    let path = files.path(ROOT).join(&input.release_id).join("serve.php");
    fs::write(&path, "corrupt").unwrap();
    let catalog = catalog(&files);
    host.active = true;
    host.calls.clear();
    assert!(install(&files, &mut host, &input).unwrap().changed);
    assert_eq!(fs::read_to_string(path).unwrap(), input.files[0].contents);
    assert_eq!(fs::read_to_string(catalog).unwrap(), "unchanged catalog");
    assert_eq!(host.calls.last(), Some(&"restart"));
}
#[test]
fn refuses_changed_digests_paths_unit_commands_and_symlinks_before_host_commands() {
    for variant in 0..6 {
        let (_work, files, mut host) = fixture();
        let mut input = input("<?php // correct");
        match variant {
            0 => input.files[0].sha256 = "0".repeat(64),
            1 => input.files[0].path = "../serve.php".into(),
            2 => input.service.push_str("ExecStartPre=/bin/false\n"),
            3 => input.socket.push_str("ListenStream=0.0.0.0:80\n"),
            4 => input.release_id = "a".repeat(16),
            _ => {
                fs::create_dir_all(files.path("/etc/systemd/system")).unwrap();
                std::os::unix::fs::symlink("/dev/null", files.path(UNIT)).unwrap();
            }
        }
        assert!(install(&files, &mut host, &input).is_err());
        assert!(host.calls.is_empty());
    }
}
