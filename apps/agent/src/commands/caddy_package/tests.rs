use super::*;
use crate::commands::recipe::{Failure, Output};
use sha2::{Digest, Sha512};
use std::path::Path;
struct FakeHost {
    version: Option<String>,
    architecture: String,
    installed: Option<String>,
    calls: Vec<Invocation>,
    fail: Option<&'static str>,
    payload: Vec<u8>,
}
impl Host for FakeHost {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        self.calls.push(invocation.clone());
        let args: Vec<_> = invocation.argv.iter().map(String::as_str).collect();
        if self.fail == Some(args[0]) {
            return Ok(Output {
                code: 1,
                stderr: "forced failure".into(),
                ..Default::default()
            });
        }
        let stdout = match args.as_slice() {
            ["caddy", "version"] => match &self.version {
                Some(v) => format!("v{v} h1:build\n"),
                None => {
                    return Ok(Output {
                        code: 127,
                        ..Default::default()
                    })
                }
            },
            ["dpkg", "--compare-versions", current, "ge", minimum] => {
                let parts = |s: &str| {
                    s.split('.')
                        .map(|n| n.parse::<u32>().unwrap())
                        .collect::<Vec<_>>()
                };
                return Ok(Output {
                    code: i32::from(parts(current) < parts(minimum)),
                    ..Default::default()
                });
            }
            ["dpkg", "--print-architecture"] => self.architecture.clone(),
            ["curl", ..] => {
                let output = args.iter().position(|s| *s == "--output").unwrap();
                fs::write(args[output + 1], &self.payload).unwrap();
                String::new()
            }
            ["sysctl", "--quiet", load] => {
                assert_eq!(
                    fs::read_to_string(load.strip_prefix("--load=").unwrap()).unwrap(),
                    "net.ipv4.tcp_migrate_req = 1\n"
                );
                String::new()
            }
            ["apt-get", ..] => {
                self.version = self.installed.clone();
                String::new()
            }
            _ => panic!("unexpected invocation: {args:?}"),
        };
        Ok(Output {
            stdout,
            ..Default::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected wait")
    }
}
fn fixture() -> (WorkDir, Files, PackageInput, FakeHost) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    for directory in [
        "/etc/sysctl.d",
        "/etc/apt/sources.list.d",
        "/usr/share/keyrings",
    ] {
        fs::create_dir_all(files.path(directory)).unwrap();
    }
    let payload = b"verified package fixture".to_vec();
    let digest = format!("{:x}", Sha512::digest(&payload));
    let input = PackageInput {
        release: "2.11.7".into(),
        amd64_sha512: digest,
        minimum_version: "2.9.0".into(),
    };
    let host = FakeHost {
        version: None,
        architecture: "amd64".into(),
        installed: Some("2.11.7".into()),
        calls: vec![],
        fail: None,
        payload,
    };
    (work, files, input, host)
}
fn programs(host: &FakeHost) -> Vec<&str> {
    host.calls.iter().map(|c| c.argv[0].as_str()).collect()
}
#[test]
fn installs_missing_or_old_packages_on_amd64_and_repeats() {
    for version in [None, Some("2.6.2")] {
        let (_work, files, input, mut host) = fixture();
        host.version = version.map(str::to_string);
        let result = install(&input, &files, &mut host).unwrap();
        assert_eq!(
            result,
            Package {
                changed: true,
                version: "2.11.7".into()
            }
        );
        let download = host.calls.iter().find(|c| c.argv[0] == "curl").unwrap();
        assert_eq!(download.argv.last().unwrap(), "https://github.com/caddyserver/caddy/releases/download/v2.11.7/caddy_2.11.7_linux_amd64.deb");
        assert!(download
            .argv
            .windows(2)
            .any(|a| a == ["--proto-redir", "=https"]));
        let apt = host.calls.iter().find(|c| c.argv[0] == "apt-get").unwrap();
        assert_eq!(
            &apt.argv[..10],
            [
                "apt-get",
                "-o",
                "DPkg::Lock::Timeout=300",
                "-o",
                "Dpkg::Options::=--force-confold",
                "install",
                "--yes",
                "--no-install-recommends",
                "--no-remove",
                "--"
            ]
        );
        assert_eq!(
            apt.environment,
            [("DEBIAN_FRONTEND".into(), "noninteractive".into())]
        );
        assert!(
            !Path::new(apt.argv.last().unwrap()).exists(),
            "package staging survived completion"
        );
        let setting = files.path(SETTING);
        let meta = fs::metadata(&setting).unwrap();
        assert_eq!(
            (meta.uid(), meta.gid(), meta.mode() & 0o7777),
            (files.uid, files.gid, 0o644)
        );
        assert_eq!(
            fs::read_to_string(&setting).unwrap(),
            "net.ipv4.tcp_migrate_req = 1\n"
        );
        host.calls.clear();
        assert!(!install(&input, &files, &mut host).unwrap().changed);
        assert_eq!(programs(&host), ["caddy", "dpkg", "sysctl", "dpkg"]);
        assert_eq!(fs::metadata(setting).unwrap().ino(), meta.ino());
    }
}
#[test]
fn leaves_supported_versions_installed_and_removes_only_obsolete_sources() {
    for version in ["2.9.0", "2.11.4", "2.12.0"] {
        let (_work, files, input, mut host) = fixture();
        host.version = Some(version.into());
        for name in LEGACY {
            fs::write(files.path(name), "old").unwrap();
        }
        let retained = files.path("/etc/apt/sources.list.d/keep.sources");
        fs::write(&retained, "keep").unwrap();
        assert!(install(&input, &files, &mut host).unwrap().changed);
        assert!(LEGACY.iter().all(|name| !files.path(name).exists()));
        assert_eq!(fs::read_to_string(retained).unwrap(), "keep");
        assert_eq!(programs(&host), ["caddy", "dpkg", "sysctl", "dpkg"]);
        assert!(!install(&input, &files, &mut host).unwrap().changed);
    }
}
#[test]
fn repairs_kernel_content_and_does_not_publish_when_the_kernel_refuses() {
    let (_work, files, input, mut host) = fixture();
    host.version = Some("2.11.7".into());
    let path = files.path(SETTING);
    fs::write(&path, "net.ipv4.tcp_migrate_req = 0\n").unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o644)).unwrap();
    host.fail = Some("sysctl");
    assert!(matches!(
        install(&input, &files, &mut host),
        Err(PackageError::Failed { .. })
    ));
    assert_eq!(
        fs::read_to_string(&path).unwrap(),
        "net.ipv4.tcp_migrate_req = 0\n"
    );
    host.fail = None;
    assert!(install(&input, &files, &mut host).unwrap().changed);
    assert_eq!(
        fs::read_to_string(path).unwrap(),
        "net.ipv4.tcp_migrate_req = 1\n"
    );
}
#[test]
fn refuses_bad_pins_or_paths_before_any_command_or_write() {
    for case in 0..4 {
        let (_work, files, mut input, mut host) = fixture();
        match case {
            0 => input.release = "../other".into(),
            1 => input.minimum_version = "invalid".into(),
            2 => input.amd64_sha512 = "a".repeat(127),
            _ => input.amd64_sha512 = "x".repeat(128),
        }
        assert_eq!(
            install(&input, &files, &mut host).unwrap_err(),
            PackageError::Invalid {}
        );
        assert!(host.calls.is_empty());
        assert!(!files.path(SETTING).exists());
    }
}
#[test]
fn refuses_symlinks_wrong_types_or_unsafe_ownership_and_mode_before_commands() {
    for name in [SETTING, LEGACY[0], LEGACY[1], "/etc/sysctl.d"] {
        for link in [true, false] {
            let (_work, files, input, mut host) = fixture();
            let path = files.path(name);
            if path.is_dir() {
                fs::remove_dir(&path).unwrap();
            }
            let outside = files.path("/outside");
            fs::write(&outside, "keep").unwrap();
            if link {
                std::os::unix::fs::symlink(&outside, &path).unwrap();
            } else if name == "/etc/sysctl.d" {
                fs::write(&path, "keep").unwrap();
            } else {
                fs::create_dir(&path).unwrap();
            }
            assert_eq!(
                install(&input, &files, &mut host).unwrap_err(),
                PackageError::Unsafe {}
            );
            assert!(host.calls.is_empty());
            assert_eq!(fs::read_to_string(outside).unwrap(), "keep");
        }
    }
    let (_work, mut files, input, mut host) = fixture();
    let path = files.path(SETTING);
    fs::write(&path, "keep").unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o666)).unwrap();
    assert_eq!(
        install(&input, &files, &mut host).unwrap_err(),
        PackageError::Unsafe {}
    );
    fs::set_permissions(&path, fs::Permissions::from_mode(0o644)).unwrap();
    files.uid += 1;
    assert_eq!(
        install(&input, &files, &mut host).unwrap_err(),
        PackageError::Unsafe {}
    );
    assert!(host.calls.is_empty());
    assert_eq!(fs::read_to_string(path).unwrap(), "keep");
}
#[test]
fn refuses_bad_digest_and_unsupported_architecture_before_kernel_or_package_mutation() {
    for architecture in [None, Some("arm64"), Some("riscv64")] {
        let (_work, files, input, mut host) = fixture();
        match architecture {
            None => host.payload = b"wrong bytes".to_vec(),
            Some(other) => host.architecture = other.into(),
        }
        assert!(matches!(
            install(&input, &files, &mut host),
            Err(PackageError::Failed { .. })
        ));
        assert!(!programs(&host)
            .iter()
            .any(|p| matches!(*p, "sysctl" | "apt-get")));
        if architecture.is_some() {
            assert!(!programs(&host).contains(&"curl"));
        }
        assert!(!files.path(SETTING).exists());
    }
}
#[test]
fn reports_download_install_and_post_install_version_failures() {
    for failure in ["curl", "apt-get", "too-old", "absent", "malformed"] {
        let (_work, files, input, mut host) = fixture();
        match failure {
            "curl" | "apt-get" => host.fail = Some(failure),
            "too-old" => host.installed = Some("2.8.4".into()),
            "absent" => host.installed = None,
            _ => host.installed = Some("garbled".into()),
        }
        assert!(matches!(
            install(&input, &files, &mut host),
            Err(PackageError::Failed { .. })
        ));
        if failure == "curl" {
            assert!(!files.path(SETTING).exists());
        }
        for call in &host.calls {
            if call.argv[0] == "curl" {
                let i = call.argv.iter().position(|a| a == "--output").unwrap();
                assert!(!Path::new(&call.argv[i + 1]).parent().unwrap().exists());
            }
        }
    }
}

#[test]
fn rejects_caller_selected_paths_and_sources() {
    for field in [
        "kernel_setting_path",
        "kernel_setting",
        "legacy_paths",
        "release_url",
        "arm64_sha512",
    ] {
        let mut value = serde_json::json!({"release":"2.11.7","minimum_version":"2.9.0","amd64_sha512":"a".repeat(128)});
        value[field] = serde_json::json!("/tmp/other");
        assert!(serde_json::from_value::<PackageInput>(value).is_err());
    }
}
