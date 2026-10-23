//! The hub commands against a sandbox `/etc/wireguard`. The stub `systemctl` keeps `wg-quick@orbit`'s active and
//! enabled state in files, and every stub logs its call.

use super::*;

const SYSTEMCTL: &str = r#"printf '%s\n' "systemctl $*" >> "$ROOT/commands.log"
case "$1" in
    is-active) [ -e "$ROOT/state/active" ] ;;
    is-enabled) [ -e "$ROOT/state/enabled" ] ;;
    enable) touch "$ROOT/state/enabled" ;;
    disable) rm -f "$ROOT/state/enabled" ;;
    start|restart) [ ! -e "$ROOT/state/start-failure" ] || exit 1; touch "$ROOT/state/active" ;;
    stop) rm -f "$ROOT/state/active" ;;
    *) exit 1 ;;
esac"#;

const WG_QUICK: &str = r#"printf '%s\n' "wg-quick $*" >> "$ROOT/commands.log"
[ "$1" = strip ] || exit 1
! grep -q INVALID "$2" || exit 1
grep -v '^PostUp' "$2""#;

const WG: &str = r#"printf '%s\n' "wg $*" >> "$ROOT/commands.log"
[ "$1" = syncconf ] || exit 1
if [ -e "$ROOT/state/sync-failure" ]; then rm -f "$ROOT/state/sync-failure"; exit 1; fi
cp "$3" "$ROOT/state/synced""#;

const OLD: &str = "[Interface]\nPrivateKey = old\n";
const NEW: &str = "[Interface]\nPrivateKey = new-secret\nPostUp = true\n";

struct Fixture {
    root: PathBuf,
    host: Host,
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}

impl Fixture {
    fn new(active: bool, enabled: bool) -> Self {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-wireguard-hub-{:016x}",
                rand::random::<u64>()
            ));
        for directory in ["wireguard", "bin", "state"] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        for (name, body) in [("systemctl", SYSTEMCTL), ("wg-quick", WG_QUICK), ("wg", WG)] {
            let path = root.join("bin").join(name);
            fs::write(
                &path,
                format!("#!/bin/bash\nset -eu\nROOT='{}'\n{body}\n", root.display()),
            )
            .unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
        }
        fs::write(root.join("wireguard/orbit.conf"), OLD).unwrap();
        for (on, name) in [(active, "active"), (enabled, "enabled")] {
            if on {
                fs::write(root.join("state").join(name), "").unwrap();
            }
        }
        Self {
            host: Host {
                wireguard: root.join("wireguard"),
                dns_records: root.join("orbit-records.conf"),
                peer_lock: root.join("peer.lock"),
                bin: Some(root.join("bin")),
                root_uid: rustix::process::getuid().as_raw(),
                root_gid: rustix::process::getgid().as_raw(),
                lock_wait: Duration::from_millis(200),
                inspect_lock_wait: Duration::from_millis(200),
                provider_wait: Duration::from_secs(1),
                fail_rename: None,
            },
            root,
        }
    }

    fn project(&self, config: &str) -> Result<Projected, HubError> {
        project(
            &ProjectInput {
                config: config.into(),
            },
            &self.host,
        )
    }

    fn flag(&self, name: &str) {
        fs::write(self.root.join("state").join(name), "").unwrap();
    }

    fn live(&self) -> String {
        fs::read_to_string(self.host.path("orbit.conf")).unwrap()
    }

    fn service(&self) -> (bool, bool) {
        (
            self.root.join("state/active").exists(),
            self.root.join("state/enabled").exists(),
        )
    }

    /// The files left in `/etc/wireguard` besides the live configuration.
    fn leftovers(&self) -> Vec<String> {
        fs::read_dir(&self.host.wireguard)
            .unwrap()
            .map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned())
            .filter(|name| name != "orbit.conf")
            .collect()
    }

    fn commands(&self) -> Vec<String> {
        fs::read_to_string(self.root.join("commands.log"))
            .unwrap_or_default()
            .lines()
            .map(|line| {
                line.replace(&self.host.wireguard.display().to_string(), "WG")
                    .split("orbit-wireguard.")
                    .next()
                    .unwrap()
                    .to_string()
            })
            .collect()
    }
}

#[test]
fn syncs_a_running_hub_without_restarting_it() {
    let fixture = Fixture::new(true, true);
    fixture.project(NEW).unwrap();
    assert_eq!(fixture.live(), NEW);
    let metadata = fs::metadata(fixture.host.path("orbit.conf")).unwrap();
    assert_eq!(metadata.mode() & 0o777, 0o600);
    assert_eq!(
        fs::metadata(&fixture.host.wireguard).unwrap().mode() & 0o777,
        0o700
    );
    assert_eq!(
        fs::read_to_string(fixture.root.join("state/synced")).unwrap(),
        "[Interface]\nPrivateKey = new-secret\n"
    );
    assert_eq!(fixture.service(), (true, true));
    assert!(fixture.leftovers().is_empty());
    assert_eq!(
        fixture.commands(),
        [
            "wg-quick strip WG/orbit-candidate.conf",
            "systemctl is-active --quiet wg-quick@orbit",
            "systemctl is-enabled --quiet wg-quick@orbit",
            "systemctl enable wg-quick@orbit",
            "wg-quick strip WG/orbit.conf",
            "wg syncconf orbit WG/",
            "systemctl is-active --quiet wg-quick@orbit",
            "systemctl is-enabled --quiet wg-quick@orbit",
        ]
    );
}

#[test]
fn enables_and_starts_a_stopped_hub() {
    let fixture = Fixture::new(false, false);
    fixture.project(NEW).unwrap();
    assert_eq!(fixture.live(), NEW);
    assert_eq!(fixture.service(), (true, true));
    assert!(fixture.leftovers().is_empty());
    let commands = fixture.commands();
    assert!(commands.contains(&"systemctl start wg-quick@orbit".to_string()));
    assert!(!commands
        .iter()
        .any(|command| command.starts_with("wg syncconf")
            || command.starts_with("systemctl restart")));
}

#[test]
fn puts_the_previous_configuration_back_when_the_sync_fails() {
    let fixture = Fixture::new(true, true);
    fixture.flag("sync-failure");
    assert_eq!(fixture.project(NEW), Err(HubError::StartFailed {}));
    assert_eq!(fixture.live(), OLD);
    assert_eq!(fixture.service(), (true, true));
    assert!(fixture.leftovers().is_empty());
    assert_eq!(
        fs::read_to_string(fixture.root.join("state/synced")).unwrap(),
        OLD
    );
}

#[test]
fn stops_and_disables_again_when_the_start_fails() {
    let fixture = Fixture::new(false, false);
    fixture.flag("start-failure");
    assert_eq!(fixture.project(NEW), Err(HubError::StartFailed {}));
    assert_eq!(fixture.live(), OLD);
    assert_eq!(fixture.service(), (false, false));
    assert!(fixture.leftovers().is_empty());
    let commands = fixture.commands();
    assert!(commands.ends_with(&[
        "systemctl start wg-quick@orbit".to_string(),
        "systemctl stop wg-quick@orbit".to_string(),
        "systemctl disable wg-quick@orbit".to_string(),
    ]));
}

#[test]
fn refuses_a_configuration_wg_quick_rejects_without_touching_the_hub() {
    let fixture = Fixture::new(true, true);
    assert_eq!(fixture.project("INVALID"), Err(HubError::ConfigInvalid {}));
    assert_eq!(fixture.live(), OLD);
    assert!(fixture.leftovers().is_empty());
    assert_eq!(
        fixture.commands(),
        ["wg-quick strip WG/orbit-candidate.conf"]
    );
}

#[test]
fn inspects_the_interface_and_the_file_digests() {
    let fixture = Fixture::new(true, true);
    let state = inspect(&fixture.host).unwrap();
    assert!(state.active);
    assert_eq!(
        state.config_sha256,
        Some(format!("{:x}", sha2::Sha256::digest(OLD)))
    );
    assert_eq!(state.dns_records_sha256, None);
    fs::write(&fixture.host.dns_records, "records").unwrap();
    fs::remove_file(fixture.root.join("state/active")).unwrap();
    let state = inspect(&fixture.host).unwrap();
    assert!(!state.active);
    assert_eq!(
        state.dns_records_sha256,
        Some(format!("{:x}", sha2::Sha256::digest("records")))
    );
}
