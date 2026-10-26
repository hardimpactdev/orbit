use super::*;
use crate::command::CommandError;
use std::{os::unix::fs::symlink, time::Instant};

fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(20))
}
fn run(input: &Input) -> Result<Output, Error> {
    let context = context();
    prepare(input, |program, args, input| {
        execute(&context, program, args, input)
    })
}
fn fixture() -> (WorkDir, Input) {
    let work = WorkDir::new().unwrap();
    let home = work.0.join("home");
    fs::create_dir(&home).unwrap();
    (work, Input { orbit_home: home })
}
fn bytes(input: &Input) -> Vec<Vec<u8>> {
    [
        "ssh/id_ed25519",
        "ssh/id_ed25519.pub",
        "wireguard/private.key",
        "wireguard/public.key",
    ]
    .map(|p| fs::read(input.orbit_home.join(p)).unwrap())
    .to_vec()
}
#[test]
fn creates_real_keys_repeats_preserves_inodes_and_repairs_permissions_without_rotation() {
    let (_work, input) = fixture();
    let first = run(&input).unwrap();
    let saved = bytes(&input);
    let inode = fs::metadata(input.orbit_home.join("ssh/id_ed25519"))
        .unwrap()
        .ino();
    for (path, expected) in [
        ("ssh", 0o700),
        ("wireguard", 0o700),
        ("ssh/id_ed25519", 0o600),
        ("ssh/id_ed25519.pub", 0o644),
        ("wireguard/private.key", 0o600),
        ("wireguard/public.key", 0o644),
    ] {
        assert_eq!(
            fs::metadata(input.orbit_home.join(path)).unwrap().mode() & 0o777,
            expected
        );
        fs::set_permissions(
            input.orbit_home.join(path),
            fs::Permissions::from_mode(0o755),
        )
        .unwrap();
    }
    assert_eq!(
        run(&input).unwrap().wireguard_public_key,
        first.wireguard_public_key
    );
    assert_eq!(bytes(&input), saved);
    assert_eq!(
        fs::metadata(input.orbit_home.join("ssh/id_ed25519"))
            .unwrap()
            .ino(),
        inode
    );
    for public in ["ssh/id_ed25519.pub", "wireguard/public.key"] {
        fs::remove_file(input.orbit_home.join(public)).unwrap();
    }
    assert_eq!(
        run(&input).unwrap().wireguard_public_key,
        first.wireguard_public_key
    );
    assert_eq!(bytes(&input), saved);
}
#[test]
fn invalid_paths_and_partial_or_mismatched_keys_refuse_without_mutating_live_state() {
    let (work, input) = fixture();
    for home in [
        PathBuf::from("relative"),
        work.0.join("missing"),
        input.orbit_home.join(".."),
        input.orbit_home.join("."),
    ] {
        assert_eq!(
            run(&Input { orbit_home: home }).unwrap_err(),
            Error::Invalid {}
        );
    }
    symlink(&input.orbit_home, work.0.join("alias")).unwrap();
    assert_eq!(
        run(&Input {
            orbit_home: work.0.join("alias")
        })
        .unwrap_err(),
        Error::Invalid {}
    );
    symlink(&work.0, input.orbit_home.join("ssh")).unwrap();
    assert_eq!(run(&input).unwrap_err(), Error::Invalid {});
    assert!(!input.orbit_home.join("wireguard").exists());
    fs::remove_file(input.orbit_home.join("ssh")).unwrap();
    run(&input).unwrap();
    let saved = bytes(&input);
    for (path, replacement, expected) in [
        ("ssh/id_ed25519", "not a key", Error::Ssh {}),
        (
            "ssh/id_ed25519.pub",
            "ssh-ed25519 invalid",
            Error::Invalid {},
        ),
        ("wireguard/private.key", "not a key", Error::Invalid {}),
        ("wireguard/public.key", "mismatch", Error::Invalid {}),
    ] {
        fs::write(input.orbit_home.join(path), replacement).unwrap();
        let before = bytes(&input);
        assert_eq!(run(&input).unwrap_err(), expected);
        assert_eq!(bytes(&input), before);
        for (path, content) in [
            "ssh/id_ed25519",
            "ssh/id_ed25519.pub",
            "wireguard/private.key",
            "wireguard/public.key",
        ]
        .iter()
        .zip(&saved)
        {
            fs::write(input.orbit_home.join(path), content).unwrap();
        }
    }
    fs::remove_file(input.orbit_home.join("ssh/id_ed25519")).unwrap();
    assert_eq!(run(&input).unwrap_err(), Error::Invalid {});
}
#[test]
fn key_process_failure_and_deadline_keep_live_identity_absent_and_hide_diagnostics() {
    for failing in ["/usr/bin/ssh-keygen", "/usr/bin/wg"] {
        let (_work, input) = fixture();
        let context = context();
        let error = prepare(&input, |program, args, input| {
            if program == failing {
                Err(())
            } else {
                execute(&context, program, args, input)
            }
        })
        .unwrap_err();
        assert_eq!(
            error.code(),
            if failing.ends_with("wg") {
                "vpn.key_generation_failed"
            } else {
                "gateway.ssh_key_failed"
            }
        );
        assert_eq!(fs::read_dir(&input.orbit_home).unwrap().count(), 0);
    }
    let (_work, input) = fixture();
    let expired = Context::new(Platform::Linux, Instant::now());
    assert_eq!(Prepare::run(input, &expired).unwrap_err(), Error::Ssh {});
}
#[test]
fn unwritable_identity_home_reports_directory_failure_without_publishing_a_key() {
    let (_work, input) = fixture();
    fs::set_permissions(&input.orbit_home, fs::Permissions::from_mode(0o500)).unwrap();
    assert_eq!(run(&input).unwrap_err(), Error::Directory {});
    assert_eq!(fs::read_dir(&input.orbit_home).unwrap().count(), 0);
    fs::set_permissions(&input.orbit_home, fs::Permissions::from_mode(0o700)).unwrap();
}
#[test]
fn self_access_preserves_existing_keys_repairs_modes_repeats_and_returns_the_real_fingerprint() {
    let (work, input) = fixture();
    run(&input).unwrap();
    let public_key = fs::read_to_string(input.orbit_home.join("ssh/id_ed25519.pub")).unwrap();
    let host = input.orbit_home.join("ssh/id_ed25519.pub");
    let home = work.0.join("account");
    fs::create_dir(&home).unwrap();
    fs::create_dir(home.join(".ssh")).unwrap();
    fs::write(
        home.join(".ssh/authorized_keys"),
        "unrelated key without newline",
    )
    .unwrap();
    let access = AccessInput {
        user: "ignored-by-fixture".into(),
        public_key: public_key.trim().into(),
    };
    let first = self_access(&access, &home, &host).unwrap();
    let real = execute(
        &context(),
        "/usr/bin/ssh-keygen",
        &["-lf", host.to_str().unwrap(), "-E", "sha256"],
        None,
    )
    .unwrap();
    assert!(real.contains(&first.fingerprint));
    let path = home.join(".ssh/authorized_keys");
    assert_eq!(
        fs::read_to_string(&path).unwrap(),
        format!("unrelated key without newline\n{}\n", access.public_key)
    );
    let inode = fs::metadata(&path).unwrap().ino();
    assert_eq!(self_access(&access, &home, &host).unwrap(), first);
    assert_eq!(fs::metadata(&path).unwrap().ino(), inode);
    assert_eq!(fs::metadata(&path).unwrap().mode() & 0o777, 0o600);
    assert_eq!(
        fs::metadata(home.join(".ssh")).unwrap().mode() & 0o777,
        0o700
    );
}
#[test]
fn self_access_refuses_unknown_accounts_bad_keys_and_symlinks_before_authorizing_anything() {
    let (work, input) = fixture();
    run(&input).unwrap();
    let host = input.orbit_home.join("ssh/id_ed25519.pub");
    let public_key = fs::read_to_string(&host).unwrap();
    let mut access = AccessInput {
        user: "orbit-no-such-test-user".into(),
        public_key: public_key.trim().into(),
    };
    assert_eq!(
        SelfAccess::run(
            AccessInput {
                user: access.user.clone(),
                public_key: access.public_key.clone()
            },
            &context()
        )
        .unwrap_err(),
        Error::SelfAccess {}
    );
    assert_eq!(
        self_access(&access, &work.0, &work.0.join("missing")).unwrap_err(),
        Error::SelfAccess {}
    );
    assert!(!work.0.join(".ssh").exists());
    let valid = access.public_key.clone();
    access.public_key = "invalid".into();
    assert_eq!(
        self_access(&access, &work.0, &host).unwrap_err(),
        Error::Invalid {}
    );
    access.public_key = valid;
    fs::create_dir(work.0.join(".ssh")).unwrap();
    fs::write(work.0.join("unrelated"), "preserved").unwrap();
    symlink(
        work.0.join("unrelated"),
        work.0.join(".ssh/authorized_keys"),
    )
    .unwrap();
    assert_eq!(
        self_access(&access, &work.0, &host).unwrap_err(),
        Error::Invalid {}
    );
    assert_eq!(
        fs::read_to_string(work.0.join("unrelated")).unwrap(),
        "preserved"
    );
}
