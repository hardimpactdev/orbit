//! The hub commands against a sandbox `/etc/wireguard`. The stub `systemctl` keeps `wg-quick@orbit`'s active and
//! enabled state in files, and every stub logs its call.

use super::*;

const SYSTEMCTL: &str = r#"printf '%s\n' "systemctl $*" >> "$ROOT/commands.log"
case "$1" in
    is-active) [ -e "$ROOT/state/active" ] ;;
    is-enabled) [ -e "$ROOT/state/enabled" ] ;;
    enable) touch "$ROOT/state/enabled" ;;
    disable) rm -f "$ROOT/state/enabled" ;;
    start|restart)
        if [ "$1" = restart ] && [ -e "$ROOT/state/restart-failure" ]; then rm -f "$ROOT/state/restart-failure"; exit 1; fi
        [ ! -e "$ROOT/state/start-failure" ] || exit 1; touch "$ROOT/state/active" ;;
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

const SYSCTL: &str = r#"printf '%s\n' "sysctl $*" >> "$ROOT/commands.log"
[ "$1" = -p ] || exit 1
[ ! -e "$ROOT/state/forwarding-failure" ] || exit 1
cp "$2" "$ROOT/state/forwarding""#;

const OLD: &str = "[Interface]\nPrivateKey = old\n";
const NEW: &str = "[Interface]\nPrivateKey = new-secret\n";

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
        for directory in ["wireguard", "bin", "state", "sysctl"] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        for (name, body) in [
            ("systemctl", SYSTEMCTL),
            ("wg-quick", WG_QUICK),
            ("wg", WG),
            ("sysctl", SYSCTL),
        ] {
            let path = root.join("bin").join(name);
            crate::test_support::executable(
                &path,
                format!("#!/bin/bash\nset -eu\nROOT='{}'\n{body}\n", root.display()),
            );
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
    assert_eq!(
        fixture.project(NEW),
        Err(HubError::StartFailed {}),
        "tool calls: {:?}",
        fixture.commands()
    );
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
    assert_eq!(
        fixture.project(NEW),
        Err(HubError::StartFailed {}),
        "tool calls: {:?}",
        fixture.commands()
    );
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

impl Fixture {
    fn bootstrap(&self, config: &str) -> Result<Projected, HubError> {
        publish_hub(
            &ProjectInput {
                config: config.into(),
            },
            &self.host,
            Some(&self.root.join("sysctl")),
        )
    }
}

#[test]
fn bootstrap_installs_forwarding_and_restarts_the_hub_on_every_converge() {
    let fixture = Fixture::new(true, true);
    for _ in 0..2 {
        fixture.bootstrap(NEW).unwrap();
    }
    assert_eq!(fixture.live(), NEW);
    let forwarding = fixture
        .root
        .join("sysctl/90-orbit-wireguard-forwarding.conf");
    assert_eq!(
        fs::read_to_string(&forwarding).unwrap(),
        "net.ipv4.ip_forward=1\n"
    );
    assert_eq!(fs::metadata(&forwarding).unwrap().mode() & 0o777, 0o644);
    assert_eq!(
        fs::read_to_string(fixture.root.join("state/forwarding")).unwrap(),
        "net.ipv4.ip_forward=1\n"
    );
    assert_eq!(
        fixture
            .commands()
            .iter()
            .filter(|c| c.as_str() == "systemctl restart wg-quick@orbit")
            .count(),
        2
    );
    assert!(!fixture
        .commands()
        .iter()
        .any(|c| c.starts_with("wg syncconf")));
    assert_eq!(fixture.service(), (true, true));
    assert!(fixture.leftovers().is_empty());
}

#[test]
fn bootstrap_refuses_invalid_config_and_forwarding_before_live_publication() {
    for forwarding_failure in [false, true] {
        let fixture = Fixture::new(true, true);
        let forwarding = fixture
            .root
            .join("sysctl/90-orbit-wireguard-forwarding.conf");
        fs::write(&forwarding, "old forwarding").unwrap();
        if forwarding_failure {
            fixture.flag("forwarding-failure");
        }
        assert_eq!(
            fixture.bootstrap(if forwarding_failure { NEW } else { "INVALID" }),
            Err(if forwarding_failure {
                HubError::ForwardingInvalid {}
            } else {
                HubError::ConfigInvalid {}
            })
        );
        assert_eq!(fixture.live(), OLD);
        assert_eq!(fs::read_to_string(forwarding).unwrap(), "old forwarding");
        assert!(fixture.leftovers().is_empty());
        assert!(!fixture
            .root
            .join("sysctl/.90-orbit-wireguard-forwarding.conf.candidate")
            .exists());
        assert!(!fixture
            .commands()
            .iter()
            .any(|c| c.starts_with("systemctl")));
    }
}

#[test]
fn failed_bootstrap_restart_restores_the_prior_config_and_service() {
    for (active, enabled) in [(true, true), (false, false)] {
        let fixture = Fixture::new(active, enabled);
        fixture.flag("restart-failure");
        assert_eq!(fixture.bootstrap(NEW), Err(HubError::StartFailed {}));
        assert_eq!(fixture.live(), OLD);
        assert_eq!(fixture.service(), (active, enabled));
        assert!(fixture.leftovers().is_empty());
    }
}

#[test]
fn forwarding_publication_failure_keeps_the_live_hub() {
    let mut fixture = Fixture::new(true, true);
    fixture.host.fail_rename = Some((
        fixture
            .root
            .join("sysctl/.90-orbit-wireguard-forwarding.conf.candidate"),
        fixture
            .root
            .join("sysctl/90-orbit-wireguard-forwarding.conf"),
    ));
    assert_eq!(
        fixture.bootstrap(NEW),
        Err(HubError::ForwardingInstallFailed {})
    );
    assert_eq!(fixture.live(), OLD);
    assert!(fixture.leftovers().is_empty());
    assert!(!fixture
        .root
        .join("sysctl/.90-orbit-wireguard-forwarding.conf.candidate")
        .exists());
}

#[test]
fn shell_hooks_and_empty_configs_are_refused_before_any_file_or_program_change() {
    for config in [
        "",
        "[Interface]\nPostUp = true",
        "PreUp=true",
        "PreDown = true",
        "  PostDown = true",
    ] {
        let fixture = Fixture::new(true, true);
        assert_eq!(fixture.bootstrap(config), Err(HubError::ConfigInvalid {}));
        assert_eq!(fixture.project(config), Err(HubError::ConfigInvalid {}));
        assert_eq!(fixture.live(), OLD);
        assert!(fixture.commands().is_empty());
        assert!(fixture.leftovers().is_empty());
    }
}

#[test]
fn bootstrap_reports_candidate_write_failures_without_changing_live_files() {
    for forwarding in [false, true] {
        let fixture = Fixture::new(true, true);
        let blocked = if forwarding {
            fixture
                .root
                .join("sysctl/.90-orbit-wireguard-forwarding.conf.candidate")
        } else {
            fixture.host.path("orbit-candidate.conf")
        };
        fs::create_dir(&blocked).unwrap();
        assert_eq!(
            fixture.bootstrap(NEW),
            Err(if forwarding {
                HubError::ForwardingInstallFailed {}
            } else {
                HubError::InstallFailed {}
            })
        );
        assert_eq!(fixture.live(), OLD);
        assert_eq!(fixture.service(), (true, true));
        assert!(!fixture
            .commands()
            .iter()
            .any(|c| c.starts_with("systemctl")));
    }
}
