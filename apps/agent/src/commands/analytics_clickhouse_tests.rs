use super::*;
use crate::commands::recipe::WorkDir;
use sha2::{Digest, Sha256};

struct Fixture {
    _work: WorkDir,
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
        Self { _work: work, files }
    }
    fn path(&self, name: &str) -> PathBuf {
        self.files.path(ROOT).join(name)
    }
}
#[test]
fn publishes_the_four_original_plausible_files_and_repeats_without_replacement() {
    let fixture = Fixture::new();
    assert_eq!(
        configure(&fixture.files, &mut Native),
        Ok(Output { changed: true })
    );
    // Plausible Community Edition ec6c4da77654, independent of the compiled content.
    let digests = [
        "8f1e01eee64c62fbe6565d3f0a2d2cd1c2755b2f18ebc64f44af6bf47a4a1c56",
        "8fba0c4d96c8cef933b33ec80017e3024f19e1534a4cc5a2f91a69a81ae8fc12",
        "7c4e4105a0d76661a97bdcbe768d199b96706a88812ef5e37bb95ed8626c435c",
        "5d4b3297f73a757ee273bf9ea45cefd039baf2b3f29e6bf6bd7520002f9286d4",
    ];
    let inodes = FILES
        .iter()
        .zip(digests)
        .map(|((name, _), digest)| {
            let path = fixture.path(name);
            assert_eq!(
                format!("{:x}", Sha256::digest(fs::read(&path).unwrap())),
                digest
            );
            let meta = fs::metadata(path).unwrap();
            assert_eq!(
                (meta.uid(), meta.gid(), meta.mode() & 0o7777),
                (fixture.files.uid, fixture.files.gid, 0o644)
            );
            meta.ino()
        })
        .collect::<Vec<_>>();
    assert_eq!(
        configure(&fixture.files, &mut Native),
        Ok(Output { changed: false })
    );
    for ((name, _), inode) in FILES.iter().zip(inodes) {
        assert_eq!(fs::metadata(fixture.path(name)).unwrap().ino(), inode);
    }
    fs::write(fixture.path(FILES[0].0), "changed").unwrap();
    fs::remove_file(fixture.path(FILES[1].0)).unwrap();
    assert_eq!(
        configure(&fixture.files, &mut Native),
        Ok(Output { changed: true })
    );
    assert_eq!(
        configure(&fixture.files, &mut Native),
        Ok(Output { changed: false })
    );
}
#[test]
fn refuses_unsafe_later_paths_before_changing_an_earlier_file() {
    for kind in [
        "parent-link",
        "file-link",
        "parent-mode",
        "file-mode",
        "owner",
        "directory",
    ] {
        let mut fixture = Fixture::new();
        configure(&fixture.files, &mut Native).unwrap();
        fs::write(fixture.path(FILES[0].0), "keep-prior").unwrap();
        let path = fixture.path(FILES[3].0);
        match kind {
            "parent-link" => {
                fs::remove_file(&path).unwrap();
                fs::remove_dir(path.parent().unwrap()).unwrap();
                std::os::unix::fs::symlink(fixture.files.path("/etc"), path.parent().unwrap())
                    .unwrap();
            }
            "file-link" => {
                fs::remove_file(&path).unwrap();
                std::os::unix::fs::symlink(fixture.path(FILES[0].0), &path).unwrap();
            }
            "parent-mode" => {
                fs::set_permissions(path.parent().unwrap(), fs::Permissions::from_mode(0o777))
                    .unwrap()
            }
            "file-mode" => fs::set_permissions(&path, fs::Permissions::from_mode(0o666)).unwrap(),
            "owner" => fixture.files.uid += 1,
            "directory" => {
                fs::remove_file(&path).unwrap();
                fs::create_dir(&path).unwrap();
            }
            _ => unreachable!(),
        }
        assert!(
            matches!(
                configure(&fixture.files, &mut Native),
                Err(Error::Unsafe { .. })
            ),
            "{kind}"
        );
        assert_eq!(fs::read(fixture.path(FILES[0].0)).unwrap(), b"keep-prior");
    }
}
struct Failing {
    calls: usize,
    recovery_fails: bool,
}
impl Publisher for Failing {
    fn publish(&mut self, files: &Files, path: &Path, bytes: &[u8]) -> Result<(), Error> {
        self.calls += 1;
        if self.calls == 3 || self.calls > 3 && self.recovery_fails {
            return Err(failed("publish"));
        }
        Native.publish(files, path, bytes)
    }
}
#[test]
fn failed_later_publication_restores_old_bytes_or_absence_and_can_be_repeated() {
    for existing in [false, true] {
        let fixture = Fixture::new();
        if existing {
            configure(&fixture.files, &mut Native).unwrap();
            for (name, _) in FILES {
                fs::write(fixture.path(name), "prior").unwrap();
            }
        }
        assert_eq!(
            configure(
                &fixture.files,
                &mut Failing {
                    calls: 0,
                    recovery_fails: false
                }
            ),
            Err(failed("publish"))
        );
        for (name, _) in FILES {
            assert_eq!(
                fs::read(fixture.path(name)).ok(),
                existing.then(|| b"prior".to_vec())
            );
        }
        assert_eq!(
            configure(&fixture.files, &mut Native),
            Ok(Output { changed: true })
        );
        assert_eq!(
            configure(&fixture.files, &mut Native),
            Ok(Output { changed: false })
        );
    }
}
#[test]
fn recovery_failure_names_the_file_that_could_not_be_restored() {
    let fixture = Fixture::new();
    configure(&fixture.files, &mut Native).unwrap();
    for (name, _) in FILES {
        fs::write(fixture.path(name), "prior").unwrap();
    }
    assert_eq!(
        configure(
            &fixture.files,
            &mut Failing {
                calls: 0,
                recovery_fails: true
            }
        ),
        Err(Error::Recovery {
            path: fixture.path(FILES[0].0).display().to_string()
        })
    );
    assert_eq!(fs::read(fixture.path(FILES[2].0)).unwrap(), b"prior");
    assert_eq!(fs::read(fixture.path(FILES[3].0)).unwrap(), b"prior");
}
