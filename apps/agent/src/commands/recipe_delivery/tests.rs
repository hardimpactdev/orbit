use super::*;
use std::{fs, path::PathBuf, sync::mpsc, thread};

struct Fixture(PathBuf);
impl Fixture {
    fn new() -> Self {
        let path = std::env::temp_dir().join(format!(
            "orbit-recipe-delivery-{:016x}",
            rand::random::<u64>()
        ));
        fs::create_dir(&path).unwrap();
        fs::write(path.join("agent"), "installed agent").unwrap();
        Self(path)
    }
    fn input(&self) -> Input {
        Input {
            user: "orbit".into(),
            identity: format!("sha256:{}", "a".repeat(64)),
            agent_sha256: file_sha256(&self.0.join("agent")).unwrap(),
            cli_version: "0.4681.0".into(),
        }
    }
    fn verify(
        &self,
        input: &Input,
        version: Option<String>,
    ) -> Result<Verified, VerificationError> {
        verify(
            input,
            &self.0.join("lock"),
            &self.0.join("agent"),
            Instant::now(),
            || version,
        )
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

#[test]
fn returns_the_recipe_only_for_matching_installed_versions_and_can_repeat() {
    let f = Fixture::new();
    let input = f.input();
    for _ in 0..2 {
        let result = f.verify(&input, Some(input.cli_version.clone())).unwrap();
        assert_eq!(result.identity, input.identity);
        assert_eq!(result.agent_sha256, input.agent_sha256);
        assert_eq!(result.cli_version, input.cli_version);
        assert!(lock::acquire(&f.0.join("lock"), Instant::now()).is_ok());
    }
}

#[test]
fn refuses_invalid_input_before_creating_the_lock_or_observing_versions() {
    let f = Fixture::new();
    for field in ["identity", "agent", "cli", "user"] {
        let mut input = f.input();
        match field {
            "identity" => input.identity.clear(),
            "agent" => input.agent_sha256 = "z".repeat(64),
            "cli" => input.cli_version = "latest".into(),
            _ => input.user.clear(),
        }
        assert_eq!(
            verify(
                &input,
                &f.0.join("lock"),
                &f.0.join("agent"),
                Instant::now(),
                || panic!("invalid input must not inspect the CLI")
            ),
            Err(VerificationError::Invalid {})
        );
        assert!(!f.0.join("lock").exists());
    }
}

#[test]
fn refuses_missing_or_mismatched_binaries_without_changing_them() {
    let f = Fixture::new();
    let input = f.input();
    for observed in [None, Some("0.4680.0".into())] {
        assert_eq!(
            f.verify(&input, observed.clone()),
            Err(VerificationError::Mismatch {
                component: "cli".into(),
                expected: input.cli_version.clone(),
                observed
            })
        );
    }
    fs::write(f.0.join("agent"), "another agent").unwrap();
    let other = file_sha256(&f.0.join("agent")).unwrap();
    assert_eq!(
        f.verify(&input, Some(input.cli_version.clone())),
        Err(VerificationError::Mismatch {
            component: "agent".into(),
            expected: input.agent_sha256.clone(),
            observed: Some(other)
        })
    );
    assert_eq!(
        fs::read_to_string(f.0.join("agent")).unwrap(),
        "another agent"
    );
    fs::remove_file(f.0.join("agent")).unwrap();
    assert_eq!(
        f.verify(&input, Some(input.cli_version.clone())),
        Err(VerificationError::Mismatch {
            component: "agent".into(),
            expected: input.agent_sha256.clone(),
            observed: None
        })
    );
    assert!(!f.0.join("agent").exists());
}

#[test]
fn busy_lock_never_observes_versions_and_an_invalid_lock_reports_its_own_failure() {
    let f = Fixture::new();
    let input = f.input();
    let held = lock::acquire(&f.0.join("lock"), Instant::now()).unwrap();
    assert_eq!(
        verify(
            &input,
            &f.0.join("lock"),
            &f.0.join("agent"),
            Instant::now(),
            || panic!("busy lock must not inspect")
        ),
        Err(VerificationError::Busy {})
    );
    drop(held);
    assert_eq!(
        verify(
            &input,
            &f.0.join("agent/lock"),
            &f.0.join("agent"),
            Instant::now(),
            || panic!("invalid lock must not inspect")
        ),
        Err(VerificationError::LockFailed {})
    );
    assert_eq!(
        fs::read_to_string(f.0.join("agent")).unwrap(),
        "installed agent"
    );
}

#[test]
fn a_racing_update_cannot_use_an_observation_from_before_the_lock() {
    let f = Fixture::new();
    let input = f.input();
    let lock_path = f.0.join("lock");
    let binary = f.0.join("agent");
    let held = lock::acquire(&lock_path, Instant::now()).unwrap();
    let (started, ready) = mpsc::channel();
    let worker = thread::spawn(move || {
        started.send(()).unwrap();
        verify(
            &input,
            &lock_path,
            &binary,
            Instant::now() + Duration::from_secs(5),
            || Some(input.cli_version.clone()),
        )
    });
    ready.recv().unwrap();
    // The updater replaces the file while holding the same actual OS lock.
    fs::write(f.0.join("candidate"), "updated agent").unwrap();
    fs::rename(f.0.join("candidate"), f.0.join("agent")).unwrap();
    drop(held);
    assert!(
        matches!(worker.join().unwrap(), Err(VerificationError::Mismatch { component, .. }) if component == "agent")
    );
}

#[test]
fn the_update_lock_stays_held_through_cli_verification() {
    let f = Fixture::new();
    let input = f.input();
    let lock_path = f.0.join("lock");
    verify(
        &input,
        &lock_path,
        &f.0.join("agent"),
        Instant::now(),
        || {
            assert!(matches!(
                lock::acquire(&lock_path, Instant::now()),
                Err(lock::LockError::Busy(_))
            ));
            Some(input.cli_version.clone())
        },
    )
    .unwrap();
}
