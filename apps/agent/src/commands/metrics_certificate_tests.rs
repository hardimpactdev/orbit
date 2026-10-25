use super::*;
use crate::commands::recipe::WorkDir;
use std::{collections::VecDeque, os::unix::fs::MetadataExt, process::Command as Process};

/// Caddy under test: reads the certificate pair with the real `openssl` check, answers its state, and records the
/// version that `current` selects at each reload.
struct Fake {
    current: PathBuf,
    active: Option<bool>,
    reloads: VecDeque<bool>,
    seen: Vec<Option<PathBuf>>,
}

impl Runtime for Fake {
    fn valid(&mut self, certificate: &Path, key: &Path) -> bool {
        Native.valid(certificate, key)
    }

    fn active(&mut self) -> io::Result<bool> {
        self.active
            .ok_or_else(|| io::Error::other("Caddy state unavailable"))
    }

    fn reload(&mut self) -> io::Result<()> {
        self.seen.push(fs::canonicalize(&self.current).ok());
        if self.reloads.pop_front().unwrap_or(true) {
            Ok(())
        } else {
            Err(io::Error::other("reload failed"))
        }
    }
}

struct Fixture {
    _work: WorkDir,
    paths: Paths,
    gid: u32,
    caddy: Fake,
}

impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let root = fs::canonicalize(&work.0).unwrap();
        fs::create_dir_all(root.join("etc/caddy")).unwrap();
        let paths = Paths {
            root,
            uid: rustix::process::geteuid().as_raw(),
        };
        let caddy = Fake {
            current: paths.current(),
            active: Some(true),
            reloads: VecDeque::new(),
            seen: vec![],
        };
        Self {
            _work: work,
            paths,
            gid: rustix::process::getegid().as_raw(),
            caddy,
        }
    }

    fn publish(
        &mut self,
        version: &str,
        pair: &Pair,
    ) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
        publish(
            &self.paths,
            self.gid,
            &mut self.caddy,
            version,
            &pair.certificate,
            &pair.key,
        )
    }

    fn restore(
        &mut self,
        change: &str,
        previous: Option<&Path>,
    ) -> Result<MetricsCertificateOutput, MetricsCertificateError> {
        restore(&self.paths, &mut self.caddy, change, previous)
    }

    fn version(&self, name: &str) -> PathBuf {
        self.paths.versions().join(name)
    }

    /// The version directory `current` selects, or none without a link.
    fn selected(&self) -> Option<PathBuf> {
        fs::read_link(self.paths.current()).ok()
    }

    /// The names in the versions directory, without the owner mark.
    fn versions(&self) -> Vec<String> {
        let mut names: Vec<String> = fs::read_dir(self.paths.versions())
            .unwrap()
            .map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned())
            .filter(|name| name != ".orbit-owner")
            .collect();
        names.sort();
        names
    }
}

struct Pair {
    certificate: String,
    key: String,
}

/// A fresh self-signed Ed25519 certificate and its key.
fn pair() -> Pair {
    let work = WorkDir::new().unwrap();
    let output = Process::new("openssl")
        .args([
            "req",
            "-x509",
            "-newkey",
            "ed25519",
            "-nodes",
            "-keyout",
            "key.pem",
            "-out",
            "cert.pem",
            "-days",
            "1",
            "-subj",
            "/CN=metrics.orbit",
        ])
        .current_dir(&work.0)
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    Pair {
        certificate: fs::read_to_string(work.0.join("cert.pem")).unwrap(),
        key: fs::read_to_string(work.0.join("key.pem")).unwrap(),
    }
}

fn reason(result: Result<MetricsCertificateOutput, MetricsCertificateError>) -> String {
    match result {
        Err(MetricsCertificateError::Failed { reason }) => reason,
        other => panic!("expected a refusal, got {other:?}"),
    }
}

fn created() -> MetricsCertificateOutput {
    MetricsCertificateOutput::Published {
        change: "created",
        previous: None,
    }
}

#[test]
fn a_first_publish_creates_an_owned_version_selects_it_and_reloads_caddy() {
    let mut fixture = Fixture::new();
    let pair = pair();
    assert_eq!(fixture.publish("a1", &pair), Ok(created()));
    let version = fixture.version("a1");
    assert_eq!(fixture.selected(), Some(version.clone()));
    assert_eq!(fixture.caddy.seen, vec![Some(version.clone())]);
    assert_eq!(fixture.versions(), vec!["a1"]);
    assert_eq!(
        fs::read_to_string(fixture.paths.versions().join(".orbit-owner")).unwrap(),
        OWNER_MARK
    );
    assert_eq!(
        fs::read_to_string(version.join("metrics.pem")).unwrap(),
        pair.certificate
    );
    assert_eq!(
        fs::read_to_string(version.join("metrics.key")).unwrap(),
        pair.key
    );
    for (path, mode) in [
        (fixture.paths.versions(), 0o750),
        (version.clone(), 0o750),
        (version.join("metrics.pem"), 0o640),
        (version.join("metrics.key"), 0o640),
    ] {
        let metadata = fs::metadata(&path).unwrap();
        assert_eq!(metadata.mode() & 0o7777, mode, "{}", path.display());
        assert_eq!(metadata.gid(), fixture.gid, "{}", path.display());
    }
}

#[test]
fn a_repeat_with_the_same_pair_changes_nothing_and_does_not_reload() {
    let mut fixture = Fixture::new();
    let pair = pair();
    fixture.publish("a1", &pair).unwrap();
    assert_eq!(
        fixture.publish("b2", &pair),
        Ok(MetricsCertificateOutput::Published {
            change: "unchanged",
            previous: None,
        })
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(fixture.versions(), vec!["a1"]);
    assert_eq!(fixture.caddy.seen.len(), 1);
}

#[test]
fn a_new_pair_replaces_the_current_version_and_keeps_the_previous_one() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    assert_eq!(
        fixture.publish("b2", &pair()),
        Ok(MetricsCertificateOutput::Published {
            change: "replaced",
            previous: Some(fixture.version("a1")),
        })
    );
    assert_eq!(fixture.selected(), Some(fixture.version("b2")));
    assert_eq!(fixture.versions(), vec!["a1", "b2"]);
    assert_eq!(
        fixture.caddy.seen.last(),
        Some(&Some(fixture.version("b2")))
    );
}

#[test]
fn an_inactive_caddy_is_not_reloaded() {
    let mut fixture = Fixture::new();
    fixture.caddy.active = Some(false);
    assert_eq!(fixture.publish("a1", &pair()), Ok(created()));
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert!(fixture.caddy.seen.is_empty());
}

#[test]
fn an_unreadable_caddy_state_refuses_before_the_version_changes() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    fixture.caddy.active = None;
    assert_eq!(
        reason(fixture.publish("b2", &pair())),
        "the Caddy service state is not readable"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(fixture.versions(), vec!["a1"]);
}

#[test]
fn a_key_of_another_certificate_is_refused_without_a_change() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    let mismatched = Pair {
        certificate: pair().certificate,
        key: pair().key,
    };
    assert_eq!(
        reason(fixture.publish("b2", &mismatched)),
        "the key does not belong to the certificate"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(fixture.versions(), vec!["a1"]);
    assert_eq!(fixture.caddy.seen.len(), 1);
}

#[test]
fn a_version_that_is_not_hexadecimal_is_refused_before_any_change() {
    let mut fixture = Fixture::new();
    for version in ["", "../a1", "a1.candidate", "g1"] {
        assert_eq!(
            reason(fixture.publish(version, &pair())),
            "the version is not a hexadecimal name"
        );
    }
    assert!(!fixture.paths.versions().exists());
}

#[test]
fn a_failed_reload_selects_the_previous_version_again_and_drops_the_new_one() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    fixture.caddy.reloads = VecDeque::from([false, true]);
    assert_eq!(
        reason(fixture.publish("b2", &pair())),
        "Caddy did not reload with the new Metrics certificate"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(fixture.versions(), vec!["a1"]);
    // The failed reload saw the new version; the recovery reload saw the previous one.
    assert_eq!(
        fixture.caddy.seen[1..],
        [Some(fixture.version("b2")), Some(fixture.version("a1"))]
    );
}

#[test]
fn a_failed_first_reload_removes_the_link_and_the_new_version() {
    let mut fixture = Fixture::new();
    fixture.caddy.reloads = VecDeque::from([false, true]);
    assert_eq!(
        reason(fixture.publish("a1", &pair())),
        "Caddy did not reload with the new Metrics certificate"
    );
    assert_eq!(fixture.selected(), None);
    assert!(fixture.versions().is_empty());
    assert_eq!(fixture.caddy.seen, vec![Some(fixture.version("a1")), None]);
}

/// A publish that stopped after it staged its version, before it selected it, leaves that version behind.
/// The Gateway's retry with the same version replaces the leftover.
#[test]
fn a_leftover_version_directory_is_replaced_on_a_repeat_with_the_same_version() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    let leftover = fixture.version("b2");
    fs::create_dir(&leftover).unwrap();
    fs::write(leftover.join("metrics.pem"), "stale").unwrap();
    fs::write(leftover.join("metrics.key"), "stale").unwrap();
    let candidate = fixture.version("b2.candidate");
    fs::create_dir(&candidate).unwrap();
    fs::write(candidate.join("metrics.pem"), "stale").unwrap();
    let pair = pair();
    assert_eq!(
        fixture.publish("b2", &pair),
        Ok(MetricsCertificateOutput::Published {
            change: "replaced",
            previous: Some(fixture.version("a1")),
        })
    );
    assert_eq!(fixture.selected(), Some(leftover.clone()));
    assert_eq!(
        fs::read_to_string(leftover.join("metrics.pem")).unwrap(),
        pair.certificate
    );
    assert_eq!(fixture.versions(), vec!["a1", "b2"]);
}

#[test]
fn the_current_version_is_never_overwritten_with_another_pair() {
    let mut fixture = Fixture::new();
    let first = pair();
    fixture.publish("a1", &first).unwrap();
    assert_eq!(
        reason(fixture.publish("a1", &pair())),
        "the version is current with another Metrics certificate"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(
        fs::read_to_string(fixture.version("a1").join("metrics.pem")).unwrap(),
        first.certificate
    );
    assert_eq!(fixture.caddy.seen.len(), 1);
}

#[test]
fn restoring_a_created_publish_removes_the_link_and_its_version() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    assert_eq!(
        fixture.restore("created", None),
        Ok(MetricsCertificateOutput::Done {})
    );
    assert_eq!(fixture.selected(), None);
    assert!(fixture.versions().is_empty());
    assert_eq!(fixture.caddy.seen.last(), Some(&None));
}

#[test]
fn restoring_a_replaced_publish_selects_the_previous_version_and_removes_the_new_one() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    let Ok(MetricsCertificateOutput::Published {
        previous: Some(previous),
        ..
    }) = fixture.publish("b2", &pair())
    else {
        panic!("expected a replacement");
    };
    assert_eq!(
        fixture.restore("replaced", Some(&previous)),
        Ok(MetricsCertificateOutput::Done {})
    );
    assert_eq!(fixture.selected(), Some(fixture.version("a1")));
    assert_eq!(fixture.versions(), vec!["a1"]);
    assert_eq!(
        fixture.caddy.seen.last(),
        Some(&Some(fixture.version("a1")))
    );
}

#[test]
fn a_restore_to_a_path_outside_the_versions_is_refused_without_a_change() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    fixture.publish("b2", &pair()).unwrap();
    let outside = fixture.paths.root.join("etc/caddy/outside");
    fs::create_dir(&outside).unwrap();
    let escape = fixture.paths.versions().join("../outside");
    for previous in [
        outside.as_path(),
        escape.as_path(),
        &fixture.paths.versions(),
    ] {
        assert_eq!(
            reason(fixture.restore("replaced", Some(previous))),
            "the previous Metrics certificate lies outside its versions",
            "{}",
            previous.display()
        );
    }
    assert_eq!(
        reason(fixture.restore("replaced", None)),
        "the change to restore is not valid"
    );
    assert_eq!(
        reason(fixture.restore("created", Some(&fixture.version("a1")))),
        "the change to restore is not valid"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("b2")));
    assert_eq!(fixture.versions(), vec!["a1", "b2"]);
    assert_eq!(fixture.caddy.seen.len(), 2);
}

#[test]
fn a_failed_restore_reload_selects_the_published_version_again() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    fixture.publish("b2", &pair()).unwrap();
    fixture.caddy.reloads = VecDeque::from([false, true]);
    assert_eq!(
        reason(fixture.restore("replaced", Some(&fixture.version("a1")))),
        "Caddy did not reload with the restored Metrics certificate"
    );
    assert_eq!(fixture.selected(), Some(fixture.version("b2")));
    assert_eq!(fixture.versions(), vec!["a1", "b2"]);
}

#[test]
fn remove_refuses_versions_without_the_owner_mark() {
    let fixture = Fixture::new();
    let versions = fixture.paths.versions();
    fs::create_dir(&versions).unwrap();
    fs::write(versions.join("keep.pem"), "foreign").unwrap();
    assert_eq!(
        reason(remove(&fixture.paths)),
        "the Metrics certificate versions do not carry Orbit's owner mark"
    );
    assert_eq!(
        fs::read_to_string(versions.join("keep.pem")).unwrap(),
        "foreign"
    );
}

#[test]
fn remove_deletes_the_link_and_every_version_and_repeats_as_done() {
    let mut fixture = Fixture::new();
    fixture.publish("a1", &pair()).unwrap();
    fixture.publish("b2", &pair()).unwrap();
    assert_eq!(
        remove(&fixture.paths),
        Ok(MetricsCertificateOutput::Done {})
    );
    assert_eq!(fixture.selected(), None);
    assert!(!fixture.paths.versions().exists());
    assert_eq!(
        remove(&fixture.paths),
        Ok(MetricsCertificateOutput::Done {})
    );
    assert!(fs::symlink_metadata(fixture.paths.current()).is_err());
}
