use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::{symlink, MetadataExt};

/// systemd as the agent sees it: each call is recorded, and a named action fails.
#[derive(Default)]
struct Fake {
    calls: Vec<String>,
    fail: Option<&'static str>,
}

impl Host for Fake {
    fn systemctl(&mut self, arguments: &[&str]) -> bool {
        self.calls.push(arguments.join(" "));
        self.fail != Some(arguments[0])
    }
}

struct Sandbox {
    _work: WorkDir,
    files: Files,
    host: Fake,
}

impl Sandbox {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let root = fs::canonicalize(&work.0).unwrap();
        for directory in ["etc/systemd/system", "usr/local/bin", "run/lock", "apps"] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        Self {
            files: Files {
                root,
                uid: rustix::process::getuid().as_raw(),
                gid: rustix::process::getgid().as_raw(),
            },
            host: Fake::default(),
            _work: work,
        }
    }

    fn path(&self, path: &str) -> PathBuf {
        self.files.path(path)
    }

    fn configure(&mut self, input: &ConfigureInput) -> Result<Configured, FootprintError> {
        configure(&self.files, &mut self.host, input, soon())
    }

    fn remove(&mut self) -> Result<Removed, FootprintError> {
        remove(&self.files, &mut self.host, soon())
    }

    fn secret(&self) -> String {
        fs::read_to_string(self.path(SECRET_PATH)).unwrap()
    }

    fn mode(&self, path: &str) -> u32 {
        fs::symlink_metadata(self.path(path)).unwrap().mode() & 0o7777
    }

    fn inode(&self, path: &str) -> u64 {
        fs::metadata(self.path(path)).unwrap().ino()
    }
}

fn soon() -> Instant {
    Instant::now() + Duration::from_millis(300)
}

fn input(secret_hash: Option<String>) -> ConfigureInput {
    ConfigureInput {
        secret_hash,
        configuration: "gateway = \"https://gateway.orbit\"\n".into(),
        certificate: "-----BEGIN CERTIFICATE-----\n".into(),
        unit: "[Service]\nExecStart=/usr/local/bin/orbit-agent observe\n".into(),
        checkouts: vec![],
        binary_changed: false,
        worker_credentials: None,
    }
}

#[test]
fn worker_configuration_carries_the_admitted_identity_and_user() {
    assert!(
        worker_configuration("gateway_url = 'https://gateway.orbit'\n")
            .unwrap()
            .is_none()
    );
    let worker = worker_configuration("[swamp_worker]\nnode_id = 9\nuser = 'nckrtl'\n")
        .unwrap()
        .unwrap();
    assert_eq!(worker.node_id, 9);
    assert_eq!(worker.user, "nckrtl");
    for value in ["true", "0", "'71'", "[]", "{node_id=9}"] {
        assert!(worker_configuration(&format!("swamp_worker = {value}\n")).is_err());
    }
}

fn hash(secret: &str) -> String {
    format!("{:x}", Sha256::digest(secret.as_bytes()))
}

const ENABLE: &str = "enable --now orbit-agent.service";
const RESTART: &str = "restart orbit-agent.service";

#[test]
fn a_fresh_node_gets_a_private_secret_its_files_and_a_restart() {
    let mut sandbox = Sandbox::new();
    let configured = sandbox.configure(&input(None)).unwrap();

    let secret = sandbox.secret();
    assert_eq!(secret.len(), 64);
    assert!(secret
        .bytes()
        .all(|byte| byte.is_ascii_hexdigit() && !byte.is_ascii_uppercase()));
    assert_eq!(
        configured,
        Configured {
            secret_hash: hash(&secret),
            restarted: true,
            unclosed: vec![]
        }
    );
    assert_eq!(sandbox.mode(LOCK_PATH), 0o700);
    assert_eq!(sandbox.mode(SECRET_PATH), 0o600);
    for (path, content) in [
        (CONFIG_PATH, input(None).configuration),
        (CA_PATH, input(None).certificate),
        (UNIT_PATH, input(None).unit),
    ] {
        assert_eq!(fs::read_to_string(sandbox.path(path)).unwrap(), content);
        assert_eq!(sandbox.mode(path), 0o644, "{path}");
    }
    assert_eq!(sandbox.host.calls, ["daemon-reload", ENABLE, RESTART]);
    assert_eq!(
        fs::read_dir(sandbox.path(LOCK_PATH))
            .unwrap()
            .map(|entry| entry.unwrap().file_name().into_string().unwrap())
            .filter(|name| name.ends_with(".orbit-candidate"))
            .count(),
        0
    );
}

#[test]
fn a_secret_that_matches_the_record_is_kept_and_an_unchanged_repeat_does_not_restart() {
    let mut sandbox = Sandbox::new();
    let first = sandbox.configure(&input(None)).unwrap();
    let secret = sandbox.secret();
    let inodes: Vec<u64> = [SECRET_PATH, CONFIG_PATH, CA_PATH, UNIT_PATH]
        .iter()
        .map(|path| sandbox.inode(path))
        .collect();
    sandbox.host.calls.clear();

    let repeated = sandbox
        .configure(&input(Some(first.secret_hash.clone())))
        .unwrap();
    assert_eq!(
        repeated,
        Configured {
            secret_hash: first.secret_hash,
            restarted: false,
            unclosed: vec![]
        }
    );
    assert_eq!(sandbox.secret(), secret);
    let unchanged: Vec<u64> = [SECRET_PATH, CONFIG_PATH, CA_PATH, UNIT_PATH]
        .iter()
        .map(|path| sandbox.inode(path))
        .collect();
    assert_eq!(unchanged, inodes, "no file is rewritten");
    assert_eq!(sandbox.host.calls, ["daemon-reload", ENABLE]);
}

#[test]
fn a_secret_without_a_matching_record_is_replaced_and_restarts_the_agent() {
    for recorded in [None, Some("0".repeat(64))] {
        let mut sandbox = Sandbox::new();
        let first = sandbox.configure(&input(None)).unwrap();
        let old = sandbox.secret();
        sandbox.host.calls.clear();

        let next = sandbox.configure(&input(recorded.clone())).unwrap();
        assert_ne!(sandbox.secret(), old, "{recorded:?}");
        assert_ne!(next.secret_hash, first.secret_hash);
        assert_eq!(next.secret_hash, hash(&sandbox.secret()));
        assert!(next.restarted);
        assert_eq!(sandbox.mode(SECRET_PATH), 0o600);
        assert_eq!(sandbox.host.calls, ["daemon-reload", ENABLE, RESTART]);
    }

    // An edited secret no longer matches the record either.
    let mut sandbox = Sandbox::new();
    let first = sandbox.configure(&input(None)).unwrap();
    fs::write(sandbox.path(SECRET_PATH), "edited").unwrap();
    let next = sandbox
        .configure(&input(Some(first.secret_hash.clone())))
        .unwrap();
    assert_ne!(next.secret_hash, first.secret_hash);
    assert_ne!(sandbox.secret(), "edited");
}

#[test]
fn a_changed_file_or_binary_restarts_the_agent() {
    let mut sandbox = Sandbox::new();
    let first = sandbox.configure(&input(None)).unwrap();

    let mut changed = input(Some(first.secret_hash.clone()));
    changed.configuration = "gateway = \"https://other.orbit\"\n".into();
    let configured = sandbox.configure(&changed).unwrap();
    assert!(configured.restarted);
    assert_eq!(
        fs::read_to_string(sandbox.path(CONFIG_PATH)).unwrap(),
        changed.configuration
    );

    sandbox.host.calls.clear();
    changed.binary_changed = true;
    assert!(sandbox.configure(&changed).unwrap().restarted);
    assert_eq!(sandbox.host.calls, ["daemon-reload", ENABLE, RESTART]);
}

#[test]
fn an_open_agent_directory_is_closed() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path(LOCK_PATH)).unwrap();
    fs::set_permissions(sandbox.path(LOCK_PATH), fs::Permissions::from_mode(0o755)).unwrap();
    sandbox.configure(&input(None)).unwrap();
    assert_eq!(sandbox.mode(LOCK_PATH), 0o700);
}

#[test]
fn instance_environments_lose_only_their_world_bits() {
    let mut sandbox = Sandbox::new();
    let checkout = |name: &str| sandbox.path(&format!("/apps/{name}"));
    for name in ["open", "closed", "linked-env", "empty"] {
        fs::create_dir_all(checkout(name)).unwrap();
    }
    fs::write(checkout("open/.env"), "SECRET=1").unwrap();
    fs::set_permissions(checkout("open/.env"), fs::Permissions::from_mode(0o664)).unwrap();
    fs::write(checkout("closed/.env"), "SECRET=1").unwrap();
    fs::set_permissions(checkout("closed/.env"), fs::Permissions::from_mode(0o640)).unwrap();
    // A link to a checkout or an environment is not followed.
    symlink(checkout("open"), checkout("link")).unwrap();
    fs::write(sandbox.path("/apps/elsewhere.env"), "SECRET=2").unwrap();
    fs::set_permissions(
        sandbox.path("/apps/elsewhere.env"),
        fs::Permissions::from_mode(0o666),
    )
    .unwrap();
    symlink(
        sandbox.path("/apps/elsewhere.env"),
        checkout("linked-env/.env"),
    )
    .unwrap();

    let mut configured = input(None);
    configured.checkouts = ["open", "closed", "linked-env", "empty", "link", "missing"]
        .iter()
        .map(|name| PathBuf::from(format!("/apps/{name}")))
        .collect();
    assert_eq!(
        sandbox.configure(&configured).unwrap().unclosed,
        Vec::<String>::new()
    );
    assert_eq!(sandbox.mode("/apps/open/.env"), 0o660);
    assert_eq!(sandbox.mode("/apps/closed/.env"), 0o640);
    assert_eq!(sandbox.mode("/apps/elsewhere.env"), 0o666);
}

#[test]
fn an_unopenable_update_lock_is_a_failure_not_a_busy_lock() {
    let mut sandbox = Sandbox::new();
    fs::create_dir_all(sandbox.path(lock::UPDATE_LOCK_LINUX)).unwrap();
    assert_eq!(
        sandbox.configure(&input(None)),
        Err(FootprintError::Failed {
            step: "update lock".into()
        })
    );
    assert!(sandbox.host.calls.is_empty());
}

#[test]
fn a_held_update_lock_refuses_before_any_change() {
    let mut sandbox = Sandbox::new();
    let _held = lock::acquire(&sandbox.path(lock::UPDATE_LOCK_LINUX), Instant::now()).unwrap();
    assert_eq!(
        sandbox.configure(&input(None)),
        Err(FootprintError::UpdateBusy {})
    );
    assert!(!sandbox.path(SECRET_PATH).exists());
    assert!(!sandbox.path(UNIT_PATH).exists());
    assert!(
        !sandbox.path(LOCK_PATH).exists(),
        "the agent directory waits for the lock"
    );
    assert!(sandbox.host.calls.is_empty());

    fs::write(sandbox.path(UNIT_PATH), "unit").unwrap();
    assert_eq!(sandbox.remove(), Err(FootprintError::UpdateBusy {}));
    assert!(sandbox.path(UNIT_PATH).exists());
    assert!(sandbox.host.calls.is_empty());
}

#[test]
fn a_failed_unit_step_or_restart_fails_the_converge() {
    for (action, step) in [
        ("daemon-reload", "agent-unit"),
        ("enable", "agent-unit"),
        ("restart", "agent-restart"),
    ] {
        let mut sandbox = Sandbox::new();
        sandbox.host.fail = Some(action);
        assert_eq!(
            sandbox.configure(&input(None)),
            Err(failed(step)),
            "{action}"
        );
    }
}

#[test]
fn removal_stops_the_observer_deletes_its_files_and_repeats() {
    let mut sandbox = Sandbox::new();
    sandbox.configure(&input(None)).unwrap();
    fs::write(sandbox.path(LINUX_BINARY), "binary").unwrap();
    sandbox.host.calls.clear();

    assert_eq!(sandbox.remove(), Ok(Removed {}));
    assert_eq!(
        sandbox.host.calls,
        [
            "stop orbit-agent.service",
            "disable orbit-agent.service",
            "daemon-reload",
            "reset-failed orbit-agent.service"
        ]
    );
    for path in [UNIT_PATH, LINUX_BINARY, LOCK_PATH] {
        assert!(!sandbox.path(path).exists(), "{path}");
    }

    sandbox.host.calls.clear();
    assert_eq!(sandbox.remove(), Ok(Removed {}));
    assert_eq!(
        sandbox.host.calls,
        ["daemon-reload", "reset-failed orbit-agent.service"]
    );
}

#[test]
fn removal_reports_a_failed_stop_after_deleting_what_it_can() {
    let mut sandbox = Sandbox::new();
    sandbox.configure(&input(None)).unwrap();
    sandbox.host.fail = Some("stop");
    assert_eq!(sandbox.remove(), Err(failed("agent-remove")));
    assert!(!sandbox.path(UNIT_PATH).exists());
    assert!(!sandbox.path(LOCK_PATH).exists());
}

#[test]
fn only_a_secret_that_matches_the_record_is_kept() {
    let hash = format!("{:x}", Sha256::digest(b"secret"));
    assert!(kept(Some(b"secret"), Some(&hash)));
    assert!(!kept(Some(b"edited"), Some(&hash)));
    assert!(!kept(None, Some(&hash)));
    assert!(!kept(Some(b"secret"), None));
}

#[test]
fn publish_writes_only_a_changed_file_with_its_mode() {
    let sandbox = Sandbox::new();
    let file = sandbox.path("/file");
    assert_eq!(publish(&file, "one", 0o600), Ok(true));
    assert_eq!(publish(&file, "one", 0o600), Ok(false));
    assert_eq!(
        fs::metadata(&file).unwrap().permissions().mode() & 0o777,
        0o600
    );
    assert_eq!(publish(&file, "two", 0o600), Ok(true));
    assert_eq!(fs::read_to_string(&file).unwrap(), "two");
}

#[test]
fn configure_secret_hash_is_live_once_and_never_saved_in_effect_receipts() {
    use crate::command::{effects::Store, Context, Outcome, Platform};
    let mut sandbox = Sandbox::new();
    let directory = sandbox._work.0.join("effect-receipts");
    let store = Store::at(directory.clone());
    let context = Context::new(Platform::Linux, soon())
        .with_effect_masks(vec![])
        .with_effect_secret_outputs(vec!["secret_hash".into()])
        .with_effect_identity(
            "11111111-1111-4111-8111-111111111111".into(),
            time::OffsetDateTime::now_utc().unix_timestamp(),
        );
    let first = store.execute(
        "agent.configure",
        "op-17.configure",
        "configure-fingerprint",
        &context,
        || Outcome::Ok(serde_json::to_value(sandbox.configure(&input(None)).unwrap()).unwrap()),
    );
    let expected = hash(&sandbox.secret());
    let Outcome::Ok(result) = first else {
        panic!("configure must succeed");
    };
    assert_eq!(result["secret_hash"], expected);
    assert_eq!(result["restarted"], true);
    let receipt = store
        .receipt("agent.configure", "op-17.configure")
        .unwrap_or_else(|_| panic!("readable"));
    assert_eq!(
        receipt.result.unwrap()["data"]["secret_hash"],
        serde_json::json!({"$secret_output":true})
    );
    for entry in fs::read_dir(directory).unwrap() {
        let path = entry.unwrap().path();
        if path
            .extension()
            .is_some_and(|extension| extension == "json")
        {
            let contents = fs::read_to_string(path).unwrap();
            assert!(!contents.contains(&expected));
            assert!(!contents.contains(&sandbox.secret()));
        }
    }
    let repeated = store.execute(
        "agent.configure",
        "op-17.configure",
        "configure-fingerprint",
        &context,
        || panic!("never repeat configure"),
    );
    let Outcome::Ok(repeated) = repeated else {
        panic!("saved receipt");
    };
    assert_eq!(
        repeated["secret_hash"],
        serde_json::json!({"$secret_output":true})
    );
}
