use super::*;
use crate::commands::recipe::WorkDir;
use std::collections::VecDeque;

struct Fixture {
    work: WorkDir,
    files: Files,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let files = Files {
            root: work.0.clone(),
            uid: rustix::process::geteuid().as_raw(),
            gid: rustix::process::getegid().as_raw(),
        };
        fs::create_dir_all(work.0.join("etc/caddy")).unwrap();
        Self { work, files }
    }
    fn current(&self) -> PathBuf {
        self.files.path(&input().directory)
    }
    fn names(&self) -> Vec<String> {
        let mut names = fs::read_dir(self.work.0.join("etc/caddy"))
            .unwrap()
            .map(|e| e.unwrap().file_name().to_string_lossy().into_owned())
            .collect::<Vec<_>>();
        names.sort();
        names
    }
    fn runtime(&self) -> Fake {
        Fake {
            current: self.current(),
            ..Fake::default()
        }
    }
}
fn input() -> InstallInput {
    InstallInput {
        directory: "/etc/caddy/orbit-websocket-cert-current".into(),
        certificate_file: "reverb.pem".into(),
        key_file: "reverb.key".into(),
        certificate: "leaf-one".into(),
        key: "key-one".into(),
        service: "caddy".into(),
    }
}
struct Fake {
    valid: bool,
    active: Option<bool>,
    reloads: VecDeque<bool>,
    seen: Vec<Option<Vec<u8>>>,
    current: PathBuf,
    state_reads: usize,
    block_restore: bool,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            valid: true,
            active: Some(true),
            reloads: VecDeque::new(),
            seen: vec![],
            current: PathBuf::new(),
            state_reads: 0,
            block_restore: false,
        }
    }
}
impl Runtime for Fake {
    fn valid(&mut self, _: &Path, _: &Path) -> bool {
        self.valid
    }
    fn active(&mut self) -> io::Result<bool> {
        self.state_reads += 1;
        self.active.ok_or_else(|| io::Error::other("unreadable"))
    }
    fn reload(&mut self) -> io::Result<()> {
        self.seen
            .push(fs::read(self.current.join("reverb.pem")).ok());
        if self.block_restore {
            fs::set_permissions(
                self.current.parent().unwrap(),
                fs::Permissions::from_mode(0o555),
            )?;
        }
        if self.reloads.pop_front().unwrap_or(true) {
            Ok(())
        } else {
            Err(io::Error::other("reload failed"))
        }
    }
}

#[test]
fn publishes_complete_pairs_repeats_and_keeps_inactive_caddy_stopped() {
    for active in [true, false] {
        let fixture = Fixture::new();
        let mut runtime = fixture.runtime();
        runtime.active = Some(active);
        let mut input = input();
        assert_eq!(
            install(&input, &fixture.files, &mut runtime),
            Ok(Installed { changed: true })
        );
        assert_eq!(
            install(&input, &fixture.files, &mut runtime),
            Ok(Installed { changed: false })
        );
        input.certificate = "leaf-two".into();
        input.key = "key-two".into();
        assert_eq!(
            install(&input, &fixture.files, &mut runtime),
            Ok(Installed { changed: true })
        );
        assert_eq!(
            fs::read(fixture.current().join("reverb.pem")).unwrap(),
            b"leaf-two"
        );
        assert_eq!(
            fs::read(fixture.current().join("reverb.key")).unwrap(),
            b"key-two"
        );
        assert_eq!(runtime.state_reads, 2);
        assert_eq!(
            runtime.seen,
            if active {
                vec![Some(b"leaf-one".to_vec()), Some(b"leaf-two".to_vec())]
            } else {
                vec![]
            }
        );
        assert_eq!(fixture.names(), ["orbit-websocket-cert-current"]);
        assert_eq!(
            fs::metadata(fixture.current()).unwrap().mode() & 0o7777,
            0o750
        );
        assert_eq!(
            fs::metadata(fixture.current().join("reverb.key"))
                .unwrap()
                .mode()
                & 0o7777,
            0o640
        );
        let remove_input = RemoveInput {
            directory: input.directory,
        };
        assert_eq!(remove(&remove_input, &fixture.files), Ok(Removed {}));
        assert_eq!(remove(&remove_input, &fixture.files), Ok(Removed {}));
        assert!(fixture.names().is_empty());
    }
}

#[test]
fn input_and_key_validation_refuse_before_live_mutation() {
    let fixture = Fixture::new();
    let mut runtime = fixture.runtime();
    for directory in [
        "/",
        "/etc/caddy",
        "/etc/caddy/orbit-cert-current",
        "/etc/caddy/orbit-a/../b-cert-current",
        "/tmp/orbit-a-cert-current",
    ] {
        let mut input = input();
        input.directory = directory.into();
        assert_eq!(
            install(&input, &fixture.files, &mut runtime),
            Err(Error::Input {})
        );
        assert_eq!(
            remove(
                &RemoveInput {
                    directory: directory.into()
                },
                &fixture.files
            ),
            Err(Error::Input {})
        );
    }
    for (cert, key, service) in [
        ("../leaf.pem", "../leaf.key", "caddy"),
        ("a.pem", "b.key", "caddy"),
        ("a.pem", "a.key", "other"),
    ] {
        let mut input = input();
        input.certificate_file = cert.into();
        input.key_file = key.into();
        input.service = service.into();
        assert_eq!(
            install(&input, &fixture.files, &mut runtime),
            Err(Error::Input {})
        );
    }
    let mut empty = input();
    empty.key.clear();
    assert_eq!(
        install(&empty, &fixture.files, &mut runtime),
        Err(Error::Input {})
    );
    for case in 0..2 {
        let mut pem = input();
        match case {
            0 => pem.certificate.push('\0'),
            _ => pem.key = "x".repeat(1024 * 1024 + 1),
        }
        assert_eq!(
            install(&pem, &fixture.files, &mut runtime),
            Err(Error::Input {})
        );
    }
    runtime.valid = false;
    assert_eq!(
        install(&input(), &fixture.files, &mut runtime),
        Err(Error::Invalid {})
    );
    assert!(fixture.names().is_empty());
    assert_eq!(runtime.state_reads, 0);
    assert!(runtime.seen.is_empty());
}

#[test]
fn refuses_foreign_parents_symlinks_permissions_and_unexpected_files() {
    for kind in [
        "parent",
        "uid",
        "link",
        "file-link",
        "mode",
        "extra",
        "names",
    ] {
        let mut fixture = Fixture::new();
        let mut runtime = fixture.runtime();
        install(&input(), &fixture.files, &mut runtime).unwrap();
        let key = fixture.current().join("reverb.key");
        match kind {
            "parent" => fs::set_permissions(
                fixture.work.0.join("etc/caddy"),
                fs::Permissions::from_mode(0o777),
            )
            .unwrap(),
            "uid" => fixture.files.uid += 1,
            "link" => {
                fs::rename(fixture.current(), fixture.work.0.join("saved")).unwrap();
                std::os::unix::fs::symlink(fixture.work.0.join("saved"), fixture.current())
                    .unwrap();
            }
            "file-link" => {
                fs::rename(&key, fixture.work.0.join("key")).unwrap();
                std::os::unix::fs::symlink(fixture.work.0.join("key"), &key).unwrap();
            }
            "mode" => fs::set_permissions(&key, fs::Permissions::from_mode(0o644)).unwrap(),
            "extra" => fs::write(fixture.current().join("operator-owned"), "keep").unwrap(),
            "names" => {
                fs::rename(&key, fixture.current().join("other.key")).unwrap();
            }
            _ => unreachable!(),
        }
        let mut candidate = input();
        candidate.certificate = "new".into();
        assert_eq!(
            install(&candidate, &fixture.files, &mut runtime),
            Err(Error::Unsafe {}),
            "{kind}"
        );
        assert_eq!(
            remove(
                &RemoveInput {
                    directory: candidate.directory
                },
                &fixture.files
            ),
            Err(Error::Unsafe {}),
            "{kind}"
        );
        assert!(fixture.current().exists());
        assert_eq!(runtime.seen.len(), 1);
    }
}

#[test]
fn unreadable_service_state_and_failed_reload_preserve_the_previous_pair() {
    for exists in [false, true] {
        let fixture = Fixture::new();
        let mut runtime = fixture.runtime();
        if exists {
            install(&input(), &fixture.files, &mut runtime).unwrap();
        }
        runtime.seen.clear();
        let mut next = input();
        next.certificate = "new-leaf".into();
        next.key = "new-key".into();
        runtime.active = None;
        assert_eq!(
            install(&next, &fixture.files, &mut runtime),
            Err(failed("service-state"))
        );
        assert_eq!(fixture.current().exists(), exists);
        runtime.active = Some(true);
        runtime.reloads = VecDeque::from([false, true]);
        assert_eq!(
            install(&next, &fixture.files, &mut runtime),
            Err(failed("reload"))
        );
        let old = exists.then(|| b"leaf-one".to_vec());
        assert_eq!(runtime.seen, [Some(b"new-leaf".to_vec()), old.clone()]);
        assert_eq!(fs::read(fixture.current().join("reverb.pem")).ok(), old);
        assert_eq!(fixture.names().len(), usize::from(exists));
    }
}

#[test]
fn reports_failed_recovery_and_preserves_the_prior_pair() {
    for block_restore in [false, true] {
        let fixture = Fixture::new();
        let mut runtime = fixture.runtime();
        install(&input(), &fixture.files, &mut runtime).unwrap();
        let mut next = input();
        next.certificate = "new-leaf".into();
        runtime.reloads = VecDeque::from([false, false]);
        runtime.block_restore = block_restore;
        let result = install(&next, &fixture.files, &mut runtime);
        fs::set_permissions(
            fixture.work.0.join("etc/caddy"),
            fs::Permissions::from_mode(0o755),
        )
        .unwrap();
        let Err(Error::Recovery { directory }) = result else {
            panic!("expected explicit recovery failure: {result:?}")
        };
        assert_eq!(
            fs::read(Path::new(&directory).join("reverb.pem")).unwrap(),
            b"leaf-one"
        );
    }
}

#[test]
fn failed_first_publication_recovery_reports_the_live_pair_and_absent_removal_needs_no_parent() {
    let fixture = Fixture::new();
    let mut runtime = fixture.runtime();
    runtime.reloads = VecDeque::from([false]);
    runtime.block_restore = true;
    let result = install(&input(), &fixture.files, &mut runtime);
    fs::set_permissions(
        fixture.work.0.join("etc/caddy"),
        fs::Permissions::from_mode(0o755),
    )
    .unwrap();
    assert_eq!(
        result,
        Err(Error::Recovery {
            directory: fixture.current().display().to_string()
        })
    );
    assert_eq!(
        fs::read(fixture.current().join("reverb.pem")).unwrap(),
        b"leaf-one"
    );
    remove(
        &RemoveInput {
            directory: input().directory,
        },
        &fixture.files,
    )
    .unwrap();
    fs::remove_dir(fixture.work.0.join("etc/caddy")).unwrap();
    remove(
        &RemoveInput {
            directory: input().directory,
        },
        &fixture.files,
    )
    .unwrap();
}

#[test]
fn shared_scope_validation_accepts_role_and_extension_names() {
    for (scope, name) in [
        ("websocket", "reverb"),
        ("analytics", "analytics"),
        ("ai-router", "ai_router"),
        ("proxycli", "proxycli"),
    ] {
        let mut input = input();
        input.directory = format!("/etc/caddy/orbit-{scope}-cert-current").into();
        input.certificate_file = format!("{name}.pem");
        input.key_file = format!("{name}.key");
        assert_eq!(validate(&input), Ok(()));
    }
}

#[test]
fn native_validation_checks_real_pem_pairs_before_publication() {
    let fixture = Fixture::new();
    for stem in ["a", "b"] {
        assert!(Process::new("/usr/bin/openssl")
            .current_dir(&fixture.work.0)
            .args([
                "req",
                "-x509",
                "-newkey",
                "ec",
                "-pkeyopt",
                "ec_paramgen_curve:prime256v1",
                "-nodes",
                "-days",
                "1",
                "-subj",
                "/CN=fixture.test",
                "-keyout",
                &format!("{stem}.key"),
                "-out",
                &format!("{stem}.pem"),
            ])
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .unwrap()
            .success());
    }
    let mut runtime = Native;
    assert!(runtime.valid(&fixture.work.0.join("a.pem"), &fixture.work.0.join("a.key")));
    assert!(!runtime.valid(&fixture.work.0.join("a.pem"), &fixture.work.0.join("b.key")));
    fs::write(fixture.work.0.join("a.pem"), "not a certificate").unwrap();
    assert!(!runtime.valid(&fixture.work.0.join("a.pem"), &fixture.work.0.join("a.key")));
}
