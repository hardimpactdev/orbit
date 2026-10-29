use super::*;
use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};

struct Fixture(PathBuf);
impl Fixture {
    fn new() -> Self {
        let path = std::env::temp_dir().join(format!(
            "orbit-effects-{}-{:016x}",
            std::process::id(),
            rand::random::<u64>()
        ));
        Self(path)
    }
    fn store(&self) -> Store {
        Store::at(self.0.join("receipts"))
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
fn context() -> Context {
    Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(3),
    )
    .with_effect_identity(
        "11111111-1111-4111-8111-111111111111".into(),
        time::OffsetDateTime::now_utc().unix_timestamp(),
    )
    .with_effect_masks(vec![])
    .with_effect_identity(
        "11111111-1111-4111-8111-111111111111".into(),
        time::OffsetDateTime::now_utc().unix_timestamp(),
    )
}
fn code(outcome: Outcome) -> String {
    match outcome {
        Outcome::Ok(_) => "ok".into(),
        Outcome::Refused { code, .. } => code,
    }
}

#[test]
fn repeats_a_success_or_refusal_without_executing_again() {
    for outcome in [
        Outcome::Ok(json!({"done":true})),
        Outcome::refused("test.refused", "refused", json!({})),
    ] {
        let fixture = Fixture::new();
        let store = fixture.store();
        let calls = AtomicUsize::new(0);
        let first = store.execute(
            "test.effect",
            "op-42.restart",
            "fingerprint",
            &context(),
            || {
                calls.fetch_add(1, Ordering::SeqCst);
                outcome.clone()
            },
        );
        let reopened = fixture.store();
        let second = reopened.execute(
            "test.effect",
            "op-42.restart",
            "fingerprint",
            &context(),
            || panic!("must reuse the receipt"),
        );
        assert_eq!(first, second);
        assert_eq!(calls.load(Ordering::SeqCst), 1);
        let receipt = reopened
            .receipt("test.effect", "op-42.restart")
            .unwrap_or_else(|_| panic!("receipt readable"));
        assert!(receipt.finished_at.is_some());
        assert!(receipt.result_digest.is_some());
    }
}
#[test]
fn an_interruption_after_an_effect_stays_unknown_and_never_repeats() {
    let fixture = Fixture::new();
    let calls = AtomicUsize::new(0);
    let interrupted = std::panic::catch_unwind(|| {
        fixture.store().execute(
            "test.effect",
            "op-42.run",
            "fingerprint",
            &context(),
            || {
                calls.fetch_add(1, Ordering::SeqCst);
                panic!("process dies after the effect");
            },
        )
    });
    assert!(interrupted.is_err());
    assert_eq!(
        fixture
            .store()
            .receipt("test.effect", "op-42.run")
            .unwrap_or_else(|_| panic!("receipt readable"))
            .state,
        "unknown"
    );
    assert_eq!(
        code(fixture.store().execute(
            "test.effect",
            "op-42.run",
            "fingerprint",
            &context(),
            || panic!("uncertain effect cannot repeat")
        )),
        "agent.effect_unknown"
    );
    assert_eq!(calls.load(Ordering::SeqCst), 1);
}
#[test]
fn another_payload_or_command_cannot_reuse_a_key() {
    let fixture = Fixture::new();
    let store = fixture.store();
    store.execute("test.effect", "op-42.run", "a", &context(), || {
        Outcome::Ok(json!({}))
    });
    for (command, fingerprint) in [("test.other", "a"), ("test.effect", "b")] {
        assert_eq!(
            code(
                store.execute(command, "op-42.run", fingerprint, &context(), || panic!(
                    "conflicting input"
                ))
            ),
            "agent.effect_conflict"
        );
    }
    assert!(store.receipt("test.other", "op-42.run").is_err());
}
#[test]
fn receipt_read_does_not_create_a_directory_or_execute_an_absent_effect() {
    let fixture = Fixture::new();
    let receipt = fixture
        .store()
        .receipt("test.effect", "op-42.run")
        .unwrap_or_else(|_| panic!("absent is unknown"));
    assert_eq!(receipt.state, "unknown");
    assert!(!fixture.0.exists());
}
#[test]
fn concurrent_repeats_share_one_receipt() {
    let fixture = Fixture::new();
    let calls = Arc::new(AtomicUsize::new(0));
    let mut handles = Vec::new();
    for _ in 0..4 {
        let store = fixture.store();
        let calls = calls.clone();
        handles.push(std::thread::spawn(move || {
            store.execute(
                "test.effect",
                "op-42.run",
                "fingerprint",
                &context(),
                || {
                    calls.fetch_add(1, Ordering::SeqCst);
                    Outcome::Ok(json!({"done":true}))
                },
            )
        }));
    }
    for handle in handles {
        assert_eq!(code(handle.join().expect("worker")), "ok");
    }
    assert_eq!(calls.load(Ordering::SeqCst), 1);
}
#[test]
fn fingerprint_normalizes_objects_and_preserves_list_order() {
    assert_eq!(
        fingerprint("test", &json!({"a":1,"b":2})),
        fingerprint("test", &json!({"b":2,"a":1}))
    );
    assert_ne!(
        fingerprint("test", &json!([1, 2])),
        fingerprint("test", &json!([2, 1]))
    );
}
#[test]
fn unsafe_keys_and_symlink_directories_refuse_before_an_effect() {
    for key in ["", "op-0.run", "op-42.../run", "op-42./run", "op-42.Run"] {
        assert!(!valid_key(key));
    }
    let fixture = Fixture::new();
    fs::create_dir_all(&fixture.0).expect("fixture directory");
    let target = fixture.0.join("target");
    fs::create_dir(&target).expect("target directory");
    std::os::unix::fs::symlink(&target, fixture.0.join("receipts")).expect("fixture symlink");
    assert_eq!(
        code(fixture.store().execute(
            "test.effect",
            "op-42.run",
            "fingerprint",
            &context(),
            || panic!("unowned store")
        )),
        "agent.effect_receipt_unavailable"
    );
    assert_eq!(fs::read_dir(target).expect("target unchanged").count(), 0);
}
#[test]
fn schemas_accept_optional_effect_keys_for_json_mutations() {
    let entry = crate::command::registry::entry::<crate::commands::schedules::RunSchedule>();
    let base = json!({"id":"id", "user":"orbit", "working_directory":"/home/orbit"});
    assert!((entry.parse)(base.clone()).is_ok());
    let mut keyed = base;
    keyed["effect_key"] = json!("op-42.run");
    assert!((entry.parse)(keyed.clone()).is_ok());
    keyed["effect_key"] = json!("op-42.../run");
    assert!((entry.parse)(keyed).is_err());
}
#[test]
fn external_programs_share_the_store_and_cannot_reserve_or_complete_twice() {
    let fixture = Fixture::new();
    let store = fixture.store();
    assert!(
        store
            .reserve(
                "remote_program.test",
                "op-42.program",
                "input",
                Duration::from_secs(0),
                &context()
            )
            .unwrap_or_else(|_| panic!("reserve"))
            .reserved
    );
    assert!(
        !store
            .reserve(
                "remote_program.test",
                "op-42.program",
                "input",
                Duration::from_secs(0),
                &context()
            )
            .unwrap_or_else(|_| panic!("repeat"))
            .reserved
    );
    let result = json!({"outcome":"succeeded", "data":{"changed":true}, "log":""});
    let receipt = store
        .complete(
            "remote_program.test",
            "op-42.program",
            "input",
            result.clone(),
            &context(),
        )
        .unwrap_or_else(|_| panic!("save"));
    assert_eq!(receipt.state, "succeeded");
    assert_eq!(receipt.result, Some(json!({"ok":true,"data":result})));
    assert!(store
        .complete(
            "remote_program.test",
            "op-42.program",
            "input",
            result.clone(),
            &context()
        )
        .is_ok());
    assert!(store
        .complete(
            "remote_program.test",
            "op-42.program",
            "input",
            json!({}),
            &context()
        )
        .is_err());
    assert!(store
        .reserve(
            "remote_program.test",
            "op-42.program",
            "other",
            Duration::from_secs(0),
            &context()
        )
        .is_err());
    assert!(store
        .complete(
            "remote_program.test",
            "op-42.absent",
            "input",
            result,
            &context()
        )
        .is_err());
}

#[test]
fn receipt_reads_report_a_live_effect_as_running_then_return_its_result() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let other = fixture.store();
    let (started, ready) = std::sync::mpsc::channel();
    let (finish, released) = std::sync::mpsc::channel();
    let worker = std::thread::spawn(move || {
        other.execute("test.effect", "op-42.running", "input", &context(), || {
            started.send(()).unwrap();
            released.recv_timeout(Duration::from_secs(2)).unwrap();
            Outcome::Ok(json!({"done":true}))
        })
    });
    ready.recv_timeout(Duration::from_secs(2)).unwrap();
    let live = store
        .receipt("test.effect", "op-42.running")
        .unwrap_or_else(|_| panic!("live receipt"));
    assert_eq!(live.state, "running");
    assert_eq!(live.exit_code, None);
    finish.send(()).unwrap();
    assert_eq!(code(worker.join().unwrap()), "ok");
    let done = store
        .receipt("test.effect", "op-42.running")
        .unwrap_or_else(|_| panic!("finished receipt"));
    assert_eq!(done.state, "succeeded");
    assert_eq!(done.exit_code, Some(0));
}

#[test]
fn binary_input_is_bound_without_moving_its_cursor_or_persisting_bytes() {
    let fixture = Fixture::new();
    fs::create_dir_all(&fixture.0).unwrap();
    let mut hashes = Vec::new();
    for contents in [b"first private input", b"other private input"] {
        let path = fixture.0.join("input");
        fs::write(&path, contents).unwrap();
        let ctx = context().with_binary_input(File::open(path).unwrap());
        hashes.push(
            bound_fingerprint("test.binary", &json!({"name":"input"}), &ctx)
                .unwrap_or_else(|_| panic!("fingerprint")),
        );
        let mut restored = Vec::new();
        ctx.take_binary_input()
            .unwrap()
            .read_to_end(&mut restored)
            .unwrap();
        assert_eq!(restored, contents);
    }
    assert_ne!(hashes[0], hashes[1]);
}

#[test]
fn a_failed_remote_program_keeps_its_exit_code_and_result() {
    let fixture = Fixture::new();
    let store = fixture.store();
    store
        .reserve(
            "remote_program.test",
            "op-42.failed",
            "input",
            Duration::from_secs(60),
            &context(),
        )
        .unwrap_or_else(|_| panic!("reserve"));
    let result = json!({"outcome":"failed","data":{"exit_code":7},"log":""});
    let receipt = store
        .complete(
            "remote_program.test",
            "op-42.failed",
            "input",
            result.clone(),
            &context(),
        )
        .unwrap_or_else(|_| panic!("complete"));
    assert_eq!(receipt.state, "failed");
    assert_eq!(receipt.exit_code, Some(7));
    assert_eq!(receipt.result, Some(json!({"ok":true,"data":result})));
}

#[test]
fn original_masks_sanitize_receipts_before_disk_and_are_never_persisted() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let ctx = context()
        .with_effect_identity(
            "11111111-1111-4111-8111-111111111111".into(),
            time::OffsetDateTime::now_utc().unix_timestamp(),
        )
        .with_effect_masks(vec!["private-original-token".into()]);
    let outcome = store.execute("test.effect", "op-42.safe", "input", &ctx, || Outcome::Ok(json!({"classified":"laravel","echo":"prefix private-original-token suffix","nested":{"private-original-token":"private-original-token"}})));
    let json = serde_json::to_string(&outcome.to_json()).unwrap();
    assert!(!json.contains("private-original-token"));
    let persisted = fs::read_to_string(store.path("op-42.safe")).unwrap();
    assert!(!persisted.contains("private-original-token"));
    assert!(!persisted.contains("redactions"));
    let receipt = store
        .receipt("test.effect", "op-42.safe")
        .unwrap_or_else(|_| panic!("read"));
    assert!(receipt.sanitized);
    assert_eq!(receipt.result, Some(outcome.to_json()));
}
#[test]
fn missing_sanitization_metadata_refuses_before_execution_and_old_raw_receipts_are_not_recovered() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let unprotected = Context::new(
        Platform::Linux,
        std::time::Instant::now() + Duration::from_secs(3),
    );
    assert_eq!(
        code(store.execute(
            "test.effect",
            "op-42.unsafe",
            "input",
            &unprotected,
            || panic!("no masks supplied")
        )),
        "agent.effect_sanitization_required"
    );
    store.private(true).unwrap_or_else(|_| panic!("store"));
    let response = Outcome::Ok(json!({"echo":"private-old-token"}));
    store
        .save(&Stored {
            effect_key: "op-42.old".into(),
            command: "test.effect".into(),
            fingerprint: "input".into(),
            result_digest: Some(digest(&serde_json::to_vec(&response.to_json()).unwrap())),
            response: Some(response),
            finished_at: Some("2026-10-28T20:00:00Z".into()),
            running_until: None,
            sanitized: false,
            effect_nonce: Some("11111111-1111-4111-8111-111111111111".into()),
            effect_created_at: Some(time::OffsetDateTime::now_utc().unix_timestamp()),
        })
        .unwrap_or_else(|_| panic!("old fixture"));
    assert!(
        matches!(store.receipt("test.effect", "op-42.old"),Err(Outcome::Refused { code,.. }) if code=="agent.effect_unsanitized")
    );
}

#[test]
fn infrastructure_refusals_never_fill_the_result_slot() {
    for error in [
        "agent.deadline_exceeded",
        "agent.supervisor_failed",
        "agent.effect_receipt_unavailable",
        "agent.effect_unknown",
    ] {
        let fixture = Fixture::new();
        let store = fixture.store();
        assert_eq!(
            code(store.execute(
                "test.effect",
                "op-42.uncertain",
                "input",
                &context(),
                || Outcome::refused(error, "unproven", json!({}))
            )),
            error
        );
        let receipt = store
            .receipt("test.effect", "op-42.uncertain")
            .unwrap_or_else(|_| panic!("receipt"));
        assert_eq!(receipt.state, "unknown");
        assert!(receipt.result.is_none());
        assert_eq!(
            code(store.execute(
                "test.effect",
                "op-42.uncertain",
                "input",
                &context(),
                || panic!("cannot repeat")
            )),
            "agent.effect_unknown"
        );
    }
}

#[test]
fn final_save_failure_leaves_only_the_original_unfinished_intent() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let intent = fixture.0.join("original-intent");
    let path = store.path("op-42.final-save");
    let outcome = store.execute(
        "test.effect",
        "op-42.final-save",
        "input",
        &context(),
        || {
            fs::rename(&path, &intent).unwrap();
            fs::create_dir(&path).unwrap();
            Outcome::Ok(json!({"effect_applied":true}))
        },
    );
    assert_eq!(code(outcome), "agent.effect_receipt_unavailable");
    fs::remove_dir(&path).unwrap();
    fs::rename(&intent, &path).unwrap();
    let receipt = store
        .receipt("test.effect", "op-42.final-save")
        .unwrap_or_else(|_| panic!("intent readable"));
    assert_eq!(receipt.state, "unknown");
    assert!(receipt.result.is_none());
    assert_eq!(
        code(store.execute(
            "test.effect",
            "op-42.final-save",
            "input",
            &context(),
            || panic!("effect already ran")
        )),
        "agent.effect_unknown"
    );
}

#[test]
fn restored_numeric_ids_cannot_adopt_receipts_from_another_operation_nonce() {
    let fixture = Fixture::new();
    let store = fixture.store();
    store.execute(
        "test.effect",
        "op-500.restart",
        "same-input",
        &context(),
        || Outcome::Ok(json!({"old":true})),
    );
    let next = context().with_effect_identity(
        "22222222-2222-4222-8222-222222222222".into(),
        time::OffsetDateTime::now_utc().unix_timestamp(),
    );
    assert_eq!(
        code(store.execute(
            "test.effect",
            "op-500.restart",
            "same-input",
            &next,
            || panic!("conflicting identity cannot execute")
        )),
        "agent.effect_conflict"
    );
    let receipt = store
        .receipt("test.effect", "op-500.restart")
        .unwrap_or_else(|_| panic!("receipt"));
    assert_eq!(
        receipt.effect_nonce.as_deref(),
        Some("11111111-1111-4111-8111-111111111111")
    );
}

#[test]
fn masks_numbers_and_booleans_before_persisting_a_receipt() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let masks = context()
        .with_effect_masks(vec!["731".into(), "1".into(), "4.25".into(), "82".into()])
        .with_effect_boolean_masks(vec![true]);
    store.execute("test.effect", "op-42.scalars", "input", &masks, || {
        Outcome::Ok(
            json!({"integer":731,"boolean":true,"float":4.25,"whole_float":82.0,"public":false}),
        )
    });
    let result = store
        .receipt("test.effect", "op-42.scalars")
        .unwrap_or_else(|_| panic!("receipt"))
        .result
        .unwrap();
    assert_eq!(
        result["data"],
        json!({"integer":"[REDACTED]","boolean":"[REDACTED]","float":"[REDACTED]","whole_float":"[REDACTED]","public":false})
    );
    let bytes = fs::read_to_string(store.path("op-42.scalars")).unwrap();
    assert!(!bytes.contains("731"));
    assert!(!bytes.contains("4.25"));
}

#[test]
fn pruning_expired_receipts_and_locks_cannot_authorize_an_old_effect() {
    let fixture = Fixture::new();
    let store = fixture.store();
    store.execute("test.effect", "op-42.expired", "input", &context(), || {
        Outcome::Ok(json!({"done":true}))
    });
    let old = std::time::SystemTime::now() - RETENTION - Duration::from_secs(3600);
    let receipt_path = store.path("op-42.expired");
    let lock_path = store
        .directory
        .join(format!("{}.lock", digest(b"op-42.expired")));
    for path in [
        &receipt_path,
        &lock_path,
        &store.directory.join(".store.lock"),
    ] {
        OpenOptions::new()
            .write(true)
            .open(path)
            .unwrap()
            .set_modified(old)
            .unwrap();
    }
    store.prune().unwrap_or_else(|_| panic!("prune"));
    assert!(!receipt_path.exists());
    assert!(!lock_path.exists());
    let original = context().with_effect_identity(
        "11111111-1111-4111-8111-111111111111".into(),
        time::OffsetDateTime::now_utc().unix_timestamp() - RETENTION.as_secs() as i64 - 3600,
    );
    assert_eq!(
        code(store.execute(
            "test.effect",
            "op-42.expired",
            "input",
            &original,
            || panic!("expired identity cannot execute")
        )),
        "agent.effect_expired"
    );
    assert_eq!(
        store
            .receipt("test.effect", "op-42.expired")
            .unwrap_or_else(|_| panic!("unknown"))
            .state,
        "unknown"
    );
}

#[test]
fn pruning_cannot_unlink_a_lock_while_an_effect_holds_the_namespace() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let (entered, running) = std::sync::mpsc::channel();
    let (release, resume) = std::sync::mpsc::channel();
    let worker_store = fixture.store();
    let thread = std::thread::spawn(move || {
        worker_store.execute("test.effect", "op-42.active", "input", &context(), || {
            entered.send(()).unwrap();
            resume.recv().unwrap();
            Outcome::Ok(json!({}))
        })
    });
    running.recv().unwrap();
    let old = std::time::SystemTime::now() - RETENTION - Duration::from_secs(3600);
    for name in [
        format!("{}.json", digest(b"op-42.active")),
        format!("{}.lock", digest(b"op-42.active")),
        ".store.lock".into(),
    ] {
        OpenOptions::new()
            .write(true)
            .open(store.directory.join(name))
            .unwrap()
            .set_modified(old)
            .unwrap();
    }
    store.prune().unwrap_or_else(|_| panic!("busy prune skips"));
    assert!(store.path("op-42.active").exists());
    release.send(()).unwrap();
    assert_eq!(code(thread.join().unwrap()), "ok");
}

#[test]
fn numeric_and_string_masks_do_not_hide_unrelated_boolean_results() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let ctx = context().with_effect_masks(vec!["1".into(), "true".into()]);
    let outcome = store.execute("test.effect", "op-42.public-boolean", "input", &ctx, || {
        Outcome::Ok(json!({"ready":true,"secret":1,"string":"true"}))
    });
    assert_eq!(
        outcome,
        Outcome::Ok(json!({"ready":true,"secret":"[REDACTED]","string":"[REDACTED]"}))
    );
    assert_eq!(
        store
            .receipt("test.effect", "op-42.public-boolean")
            .unwrap_or_else(|_| panic!("receipt"))
            .result,
        Some(outcome.to_json())
    );
}

#[test]
fn a_proven_prestart_refusal_inside_the_effect_removes_intent_and_the_key_runs_once() {
    for error in super::super::NOT_STARTED_ERRORS
        .iter()
        .chain(super::super::PREFLIGHT_ERRORS)
    {
        let fixture = Fixture::new();
        let store = fixture.store();
        let key = "op-42.prestart";
        let ctx = context();
        assert_eq!(
            code(store.execute("test.effect", key, "input", &ctx, || {
                assert!(
                    store.path(key).exists(),
                    "the refusal happens after saving intent"
                );
                Outcome::refused(error, "Nothing started.", json!({}))
            })),
            *error
        );
        assert!(!store.path(key).exists());
        assert_eq!(
            store
                .receipt("test.effect", key)
                .unwrap_or_else(|_| panic!("receipt"))
                .state,
            "unknown"
        );
        let calls = AtomicUsize::new(0);
        let result = store.execute("test.effect", key, "input", &ctx, || {
            calls.fetch_add(1, Ordering::SeqCst);
            Outcome::Ok(json!({"ran":true}))
        });
        assert_eq!(result, Outcome::Ok(json!({"ran":true})));
        assert_eq!(
            store.execute("test.effect", key, "input", &ctx, || panic!(
                "must not repeat"
            )),
            result
        );
        assert_eq!(calls.load(Ordering::SeqCst), 1);
    }
}

#[test]
fn a_failed_prestart_intent_removal_cannot_report_that_the_key_is_unused() {
    let fixture = Fixture::new();
    let store = fixture.store();
    let key = "op-42.failed-removal";
    let ctx = context();
    let path = store.path(key);
    let original = fixture.0.join("intent");
    assert_eq!(
        code(store.execute("test.effect", key, "input", &ctx, || {
            fs::rename(&path, &original).unwrap();
            fs::create_dir(&path).unwrap();
            Outcome::refused("agent.supervisor_not_ready", "Nothing started.", json!({}))
        })),
        "agent.effect_receipt_unavailable"
    );
    fs::remove_dir(&path).unwrap();
    fs::rename(&original, &path).unwrap();
    assert_eq!(
        code(store.execute("test.effect", key, "input", &ctx, || panic!(
            "unproven cleanup cannot authorize replay"
        ))),
        "agent.effect_unknown"
    );
}
