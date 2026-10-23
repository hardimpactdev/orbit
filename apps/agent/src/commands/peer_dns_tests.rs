//! Peer DNS against a sandbox `/etc/wireguard` and stub tools. The stub `resolvectl` keeps each link's settings in
//! files, the stub `networkctl reconfigure` restores the provider DNS of `eth0`, and every stub logs its call.

use super::*;
use std::{collections::BTreeMap, os::unix::fs::PermissionsExt, path::PathBuf};

const KEY: &str = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";

const SYSTEMCTL: &str = r#"printf '%s\n' "systemctl $*" >> "$ROOT/commands.log"
if [ "${1:-}" = is-active ] && [ "${2:-}" = --quiet ] && [ "${3:-}" = wg-quick@orbit ]; then exit 0; fi
exit 1"#;

const WG: &str = r#"printf '%s\n' "wg $*" >> "$ROOT/commands.log"
if [ "$*" = 'show orbit public-key' ]; then printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; exit 0; fi
exit 1"#;

const WG_QUICK: &str = r#"printf '%s\n' "wg-quick $*" >> "$ROOT/commands.log"
if [ -f "$ROOT/state/candidate-failure" ]; then exit 1; fi
[ "${1:-}" = strip ]"#;

const RESOLVECTL: &str = r#"printf '%s\n' "resolvectl $*" >> "$ROOT/commands.log"
if [ -f "$ROOT/state/apply-failure" ] && [ "$*" = 'domain orbit ~custom.internal ~orbit' ]; then exit 1; fi
if [ -f "$ROOT/state/recovery-failure" ] && { [ "$*" = 'domain orbit ~orbit' ] || [ "$*" = 'domain eth0 ~orbit' ]; }; then exit 1; fi
if [ "$*" = dns ]; then
    if [ -f "$ROOT/state/provider-pending" ]; then
        rm -f "$ROOT/state/provider-pending"
        printf '198.51.100.53\n' > "$ROOT/state/resolver/eth0/dns"
        printf '~underlay.example\n' > "$ROOT/state/resolver/eth0/domain"
        printf 'Global: \nLink 7 (eth0): \n'
        exit 0
    fi
    printf 'Global: %s\n' "$(cat "$ROOT/state/global-dns" 2>/dev/null || true)"
    for dir in "$ROOT/state/resolver/"*; do
        printf 'Link 7 (%s): %s\n' "${dir##*/}" "$(cat "$dir/dns")"
    done
    exit 0
fi
if [ "${1:-}" = query ]; then
    [ "${4:-}" = gateway.orbit ] || exit 1
    printf 'gateway.orbit: 10.43.0.1 -- link: orbit\n\n'
    exit 0
fi
operation=$1
link=$2
shift 2
resolver="$ROOT/state/resolver/$link"
[ -d "$resolver" ] || mkdir -p "$resolver"
case "$operation" in
    default-route|llmnr|mdns|dnssec|dnsovertls|nta)
        if [ "$#" = 0 ]; then
            printf 'Link 7 (%s): %s\n' "$link" "$(cat "$resolver/$operation")"
        else
            printf '%s\n' "$1" > "$resolver/$operation"
        fi
        ;;
    dns|domain)
        if [ "$#" = 0 ]; then
            printf 'Link 7 (%s): %s\n' "$link" "$(paste -sd ' ' "$resolver/$operation")"
            exit 0
        fi
        if [ "$link" = eth0 ]; then touch "$resolver/transient"; fi
        : > "$resolver/$operation"
        for value in "$@"; do
            if [ -n "$value" ]; then printf '%s\n' "$value" >> "$resolver/$operation"; fi
        done
        ;;
    revert)
        rm -f "$resolver/transient"
        : > "$resolver/dns"
        : > "$resolver/domain"
        for setting in default-route llmnr mdns dnssec dnsovertls nta; do printf 'default\n' > "$resolver/$setting"; done
        ;;
    *) exit 1 ;;
esac"#;

const NETWORKCTL: &str = r#"printf '%s\n' "networkctl $*" >> "$ROOT/commands.log"
if [ "${1:-}" = --no-legend ]; then
    if [ -f "$ROOT/state/provider-pending" ]; then printf '2 eth0 ether routable configuring\n'; else printf '2 eth0 ether routable configured\n'; fi
    exit 0
fi
if [ -f "$ROOT/state/provider-failure" ]; then exit 1; fi
if [ -f "$ROOT/state/resolver/eth0/transient" ]; then exit 0; fi
printf 'yes\n' > "$ROOT/state/resolver/eth0/default-route"
if [ -f "$ROOT/state/provider-delay" ]; then touch "$ROOT/state/provider-pending"; exit 0; fi
printf '198.51.100.53\n' > "$ROOT/state/resolver/eth0/dns"
printf '~underlay.example\n' > "$ROOT/state/resolver/eth0/domain""#;

#[derive(Debug, PartialEq)]
struct Link {
    dns: Vec<String>,
    domains: Vec<String>,
    unrelated: BTreeMap<String, String>,
}

#[derive(Debug, PartialEq)]
struct State {
    live: String,
    dns: String,
    private_key: String,
    orbit: Link,
    eth0: Link,
    artifacts: Vec<String>,
}

struct Fixture {
    root: PathBuf,
    host: Host,
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.root);
    }
}

fn domains(names: &[&str]) -> Vec<String> {
    names.iter().map(ToString::to_string).collect()
}

impl Fixture {
    fn new(previous_underlay: bool, pre_down: bool) -> Self {
        let root = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-peer-dns-{:016x}", rand::random::<u64>()));
        for directory in [
            "wireguard",
            "bin",
            "state/resolver/orbit",
            "state/resolver/eth0",
        ] {
            fs::create_dir_all(root.join(directory)).unwrap();
        }
        for (name, body) in [
            ("systemctl", SYSTEMCTL),
            ("wg", WG),
            ("wg-quick", WG_QUICK),
            ("resolvectl", RESOLVECTL),
            ("networkctl", NETWORKCTL),
        ] {
            let path = root.join("bin").join(name);
            fs::write(
                &path,
                format!("#!/bin/bash\nset -eu\nROOT='{}'\n{body}\n", root.display()),
            )
            .unwrap();
            fs::set_permissions(&path, fs::Permissions::from_mode(0o755)).unwrap();
        }
        let fixture = Self {
            host: Host {
                wireguard: root.join("wireguard"),
                dns_records: root.join("records.conf"),
                peer_lock: root.join("peer.lock"),
                bin: Some(root.join("bin")),
                root_uid: rustix::process::getuid().as_raw(),
                root_gid: rustix::process::getgid().as_raw(),
                lock_wait: Duration::from_millis(200),
                inspect_lock_wait: Duration::from_millis(200),
                provider_wait: Duration::from_secs(5),
                fail_rename: None,
            },
            root,
        };
        fixture.resolver(
            "orbit",
            "10.43.0.53\n",
            "~orbit\n",
            [
                "no",
                "resolve",
                "yes",
                "allow-downgrade",
                "opportunistic",
                "corp.example",
            ],
        );
        fixture.resolver(
            "eth0",
            "198.51.100.53\n",
            "~underlay.example\n",
            [
                if previous_underlay { "no" } else { "yes" },
                "yes",
                "resolve",
                "yes",
                "no",
                "underlay.example",
            ],
        );
        let mut live =
            "[Interface]\nPrivateKey = stable-private-key\nAddress = 10.43.0.7/24\n".to_string();
        if !previous_underlay {
            live.push_str("PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit\n");
            if pre_down {
                live.push_str("PreDown = resolvectl revert %i\n");
            }
        }
        live.push_str("\n[Peer]\nPublicKey = stable-server-key\nEndpoint = 192.0.2.1:51820\nAllowedIPs = 10.43.0.0/24\nPersistentKeepalive = 25\n");
        let mut dns = "orbit\n10.43.0.53\norbit\n";
        if previous_underlay {
            dns = "eth0\n10.0.0.2\norbit\n";
            fixture.write("state/resolver/orbit/dns", "");
            fixture.write("state/resolver/orbit/domain", "");
            fixture.write("state/resolver/orbit/default-route", "yes");
            fixture.write("state/resolver/eth0/dns", "10.0.0.2\n");
            fixture.write("state/resolver/eth0/domain", "~orbit\n");
        }
        fixture.write("wireguard/orbit.conf", &live);
        fixture.write("wireguard/orbit.dns-link", dns);
        fixture.write("wireguard/orbit.key", "stable-private-key-material");
        fixture
    }

    fn write(&self, path: &str, content: &str) {
        fs::write(self.root.join(path), content).unwrap();
    }

    fn flag(&self, name: &str) {
        self.write(&format!("state/{name}"), "1");
    }

    fn clear(&self, name: &str) {
        let _ = fs::remove_file(self.root.join("state").join(name));
    }

    fn resolver(&self, link: &str, dns: &str, domain: &str, unrelated: [&str; 6]) {
        self.write(&format!("state/resolver/{link}/dns"), dns);
        self.write(&format!("state/resolver/{link}/domain"), domain);
        for (setting, value) in [
            "default-route",
            "llmnr",
            "mdns",
            "dnssec",
            "dnsovertls",
            "nta",
        ]
        .iter()
        .zip(unrelated)
        {
            self.write(
                &format!("state/resolver/{link}/{setting}"),
                &format!("{value}\n"),
            );
        }
    }

    fn read(&self, path: &str) -> String {
        fs::read_to_string(self.root.join(path)).unwrap_or_default()
    }

    fn values(&self, path: &str) -> Vec<String> {
        self.read(path)
            .split('\n')
            .filter(|line| !line.is_empty())
            .map(ToString::to_string)
            .collect()
    }

    fn link(&self, link: &str) -> Link {
        Link {
            dns: self.values(&format!("state/resolver/{link}/dns")),
            domains: self.values(&format!("state/resolver/{link}/domain")),
            unrelated: [
                "default-route",
                "llmnr",
                "mdns",
                "dnssec",
                "dnsovertls",
                "nta",
            ]
            .iter()
            .map(|setting| {
                let value = self.read(&format!("state/resolver/{link}/{setting}"));
                (setting.to_string(), value.trim().to_string())
            })
            .collect(),
        }
    }

    fn state(&self) -> State {
        let mut artifacts: Vec<String> = fs::read_dir(self.root.join("wireguard"))
            .unwrap()
            .map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned())
            .filter(|name| name.starts_with(".orbit") || name == "orbit-candidate.conf")
            .collect();
        artifacts.sort();
        State {
            live: self.read("wireguard/orbit.conf"),
            dns: self.read("wireguard/orbit.dns-link"),
            private_key: self.read("wireguard/orbit.key"),
            orbit: self.link("orbit"),
            eth0: self.link("eth0"),
            artifacts,
        }
    }

    fn commands(&self) -> Vec<String> {
        self.values("commands.log")
    }

    fn repair(&self, names: &[&str]) -> Result<Repaired, RepairError> {
        repair(
            &RepairInput {
                dns_server: "10.43.0.53".into(),
                public_key: KEY.into(),
                domains: domains(names),
            },
            &self.host,
        )
    }
}

#[test]
fn repairs_live_and_saved_dns_repeatedly_without_restarting_the_tunnel() {
    let fixture = Fixture::new(false, true);
    let before = fixture.state();
    fixture.repair(&["orbit"]).unwrap();
    let first = fixture.state();
    fixture.repair(&["orbit"]).unwrap();
    let second = fixture.state();
    assert_eq!(second.live, first.live);
    assert!(second
        .live
        .contains("PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit\n"));
    assert!(second.live.contains("PrivateKey = stable-private-key\n"));
    assert!(!second.live.contains("PreDown ="));
    assert_eq!(second.dns, "orbit\n10.43.0.53\norbit\n");
    assert_eq!(second.orbit.dns, ["10.43.0.53"]);
    assert_eq!(second.orbit.domains, ["~orbit"]);
    assert_eq!(second.orbit.unrelated, before.orbit.unrelated);
    assert_eq!(second.eth0, before.eth0);
    assert_eq!(second.private_key, "stable-private-key-material");
    assert!(second.artifacts.is_empty());
    let commands = fixture.commands();
    assert!(commands.contains(&"resolvectl dns orbit 10.43.0.53".to_string()));
    assert!(commands.contains(&"resolvectl domain orbit ~orbit".to_string()));
    assert!(!commands.iter().any(|command| command.contains("restart")
        || command.contains("systemctl stop")
        || command.contains("systemctl start")));
}

#[test]
fn adds_a_hook_to_a_hookless_configuration_and_replaces_an_old_one() {
    let live = "[Interface]\nAddress = 10.43.0.7/24\n\n[Peer]\nAllowedIPs = 10.43.0.0/24";
    assert_eq!(
        Repair::candidate(live, "HOOK\n", "eth0").unwrap(),
        "[Interface]\nHOOK\nAddress = 10.43.0.7/24\n\n[Peer]\nAllowedIPs = 10.43.0.0/24\n"
    );
    assert_eq!(Repair::candidate(live, "HOOK\n", "orbit"), None);
    let hooked = "[Interface]\nPostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit\nPreDown = resolvectl dns %i ''; resolvectl domain %i ''\n[Peer]\n";
    assert_eq!(
        Repair::candidate(hooked, "HOOK\n", "orbit").unwrap(),
        "[Interface]\nHOOK\n[Peer]\n"
    );
    let twice = format!("{hooked}PostUp = resolvectl dns %i 1.1.1.1; resolvectl domain %i x\n");
    assert_eq!(Repair::candidate(&twice, "HOOK\n", "orbit"), None);
}

#[test]
fn migrates_an_old_underlay_selection_and_restores_provider_dns() {
    let fixture = Fixture::new(true, false);
    let before = fixture.state();
    fixture.repair(&["custom.internal", "orbit"]).unwrap();
    let after = fixture.state();
    let mut unrelated = before.eth0.unrelated.clone();
    unrelated.insert("default-route".into(), "yes".into());
    assert_eq!(after.eth0.unrelated, unrelated);
    assert!(after.live.contains(
        "PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~custom.internal \\~orbit\n"
    ));
    assert_eq!(after.dns, "orbit\n10.43.0.53\ncustom.internal\norbit\n");
    assert_eq!(after.eth0.dns, ["198.51.100.53"]);
    assert_eq!(after.eth0.domains, ["~underlay.example"]);
    assert_eq!(after.orbit.unrelated["default-route"], "no");
    let commands = fixture.commands();
    assert!(commands.contains(&"resolvectl revert eth0".to_string()));
    assert!(commands.contains(&"networkctl reconfigure eth0".to_string()));
    assert!(after.artifacts.is_empty());
}

#[test]
fn waits_for_networkd_and_resolved_to_expose_the_provider_server() {
    let fixture = Fixture::new(true, false);
    fixture.flag("provider-delay");
    fixture.repair(&["custom.internal", "orbit"]).unwrap();
    assert_eq!(fixture.state().eth0.dns, ["198.51.100.53"]);
    let listings = fixture
        .commands()
        .iter()
        .filter(|command| *command == "resolvectl dns")
        .count();
    assert!(listings >= 2);
}

#[test]
fn restores_everything_when_the_provider_does_not_come_back() {
    let fixture = Fixture::new(true, false);
    let before = fixture.state();
    fixture.flag("provider-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::UplinkMissing {})
    );
    assert_eq!(fixture.state(), before);
}

#[test]
fn restores_the_saved_uplink_after_a_failure_that_follows_its_revert() {
    let fixture = Fixture::new(true, false);
    let before = fixture.state();
    fixture.flag("apply-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::ApplyFailed {})
    );
    assert!(fixture
        .commands()
        .contains(&"resolvectl revert eth0".to_string()));
    assert_eq!(fixture.state(), before);
}

#[test]
fn a_later_repair_completes_a_failed_restore_of_a_migration() {
    let fixture = Fixture::new(true, false);
    let before = fixture.state();
    fixture.flag("apply-failure");
    fixture.flag("recovery-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::RecoveryFailed {})
    );
    fixture.clear("apply-failure");
    fixture.clear("recovery-failure");
    fixture.flag("provider-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::UplinkMissing {})
    );
    assert_eq!(fixture.state(), before);
}

#[test]
fn repairs_the_largest_namespace() {
    let fixture = Fixture::new(false, true);
    let mut names: Vec<String> = (0..511)
        .map(|index| {
            format!(
                "{}.{}.{}.{:x>61}",
                "a".repeat(63),
                "b".repeat(63),
                "c".repeat(63),
                index
            )
        })
        .collect();
    names.push("orbit".into());
    let names: Vec<&str> = names.iter().map(String::as_str).collect();
    fixture.repair(&names).unwrap();
    let state = fixture.state();
    assert_eq!(lines(&state.dns).len(), 514);
    assert_eq!(state.orbit.domains.len(), 512);
}

#[test]
fn refuses_while_another_peer_operation_holds_the_lock() {
    let fixture = Fixture::new(false, true);
    let before = fixture.state();
    let _held = hold(&fixture.host.peer_lock, Duration::ZERO, true)
        .unwrap()
        .unwrap();
    assert_eq!(fixture.repair(&["orbit"]), Err(RepairError::Busy {}));
    assert_eq!(
        inspect(
            &InspectInput {
                server: "10.43.0.53".into()
            },
            &fixture.host
        ),
        Err(InspectError::Busy {})
    );
    assert_eq!(fixture.state(), before);
    assert!(fixture.commands().is_empty());
}

#[test]
fn refuses_while_peer_convergence_recovery_is_pending() {
    let fixture = Fixture::new(false, true);
    fixture.write(
        "wireguard/.orbit.peer-transaction",
        "active\nenabled\n1\n1\n",
    );
    fixture.write("wireguard/.orbit.conf.rollback", "peer backup");
    fixture.write("wireguard/.orbit.dns-link.rollback", "peer dns backup");
    let before = fixture.state();
    assert_eq!(
        fixture.repair(&["orbit"]),
        Err(RepairError::RecoveryPending {})
    );
    assert_eq!(fixture.state(), before);
    assert!(!fixture
        .commands()
        .contains(&"resolvectl dns orbit 10.43.0.53".to_string()));
}

#[test]
fn rejects_an_invalid_candidate_before_changing_anything() {
    let fixture = Fixture::new(false, true);
    let before = fixture.state();
    fixture.flag("candidate-failure");
    assert_eq!(
        fixture.repair(&["orbit"]),
        Err(RepairError::CandidateInvalid {})
    );
    assert_eq!(fixture.state(), before);
}

#[test]
fn restores_the_exact_previous_state_when_the_live_apply_fails() {
    let fixture = Fixture::new(false, true);
    let before = fixture.state();
    fixture.flag("apply-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::ApplyFailed {})
    );
    assert_eq!(fixture.state(), before);
    let commands = fixture.commands();
    for expected in [
        "resolvectl domain orbit ~custom.internal ~orbit",
        "resolvectl dns orbit 10.43.0.53",
        "resolvectl domain orbit ~orbit",
    ] {
        assert!(commands.contains(&expected.to_string()), "{expected}");
    }
}

#[test]
fn restores_the_old_link_when_the_dns_state_does_not_publish() {
    let mut fixture = Fixture::new(true, false);
    let before = fixture.state();
    fixture.host.fail_rename = Some((
        fixture.host.path(".orbit.dns-link.candidate"),
        fixture.host.path("orbit.dns-link"),
    ));
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::ApplyFailed {})
    );
    assert_eq!(fixture.state(), before);
    let commands = fixture.commands();
    assert!(commands.contains(&"resolvectl dns eth0 10.0.0.2".to_string()));
    assert!(commands.contains(&"resolvectl domain eth0 ~orbit".to_string()));
    assert!(!commands.contains(&"resolvectl revert eth0".to_string()));
    assert!(!commands.contains(&"resolvectl domain eth0 ~custom.internal ~orbit".to_string()));
}

#[test]
fn keeps_its_recovery_files_after_a_failed_restore_and_completes_on_retry() {
    let fixture = Fixture::new(false, true);
    let before = fixture.state();
    fixture.flag("apply-failure");
    fixture.flag("recovery-failure");
    assert_eq!(
        fixture.repair(&["custom.internal", "orbit"]),
        Err(RepairError::RecoveryFailed {})
    );
    let failed = fixture.state();
    assert_eq!(failed.live, before.live);
    assert_eq!(failed.dns, before.dns);
    assert_eq!(failed.eth0, before.eth0);
    for artifact in [
        ".orbit.conf.rollback",
        ".orbit.dns-link.rollback",
        ".orbit.peer-transaction",
    ] {
        assert!(
            failed.artifacts.contains(&artifact.to_string()),
            "{artifact}"
        );
    }
    assert!(fixture
        .read("wireguard/.orbit.peer-transaction")
        .starts_with("dns-repair-v2\nno\n"));
    fixture.clear("apply-failure");
    fixture.clear("recovery-failure");
    fixture.repair(&["custom.internal", "orbit"]).unwrap();
    let retried = fixture.state();
    assert_eq!(retried.dns, "orbit\n10.43.0.53\ncustom.internal\norbit\n");
    assert_eq!(retried.orbit.domains, ["~custom.internal", "~orbit"]);
    assert_eq!(retried.orbit.unrelated, before.orbit.unrelated);
    assert_eq!(retried.eth0, before.eth0);
    assert!(retried.artifacts.is_empty());
}

#[test]
fn refuses_split_dns_without_an_uplink_server() {
    let fixture = Fixture::new(false, true);
    fixture.write("state/resolver/eth0/dns", "");
    let before = fixture.state();
    assert_eq!(
        fixture.repair(&["orbit"]),
        Err(RepairError::UplinkMissing {})
    );
    assert_eq!(fixture.state(), before);
}

#[test]
fn refuses_invalid_input_before_taking_the_lock() {
    let fixture = Fixture::new(false, true);
    assert_eq!(
        fixture.repair(&["orbit", "."]),
        Err(RepairError::StateUnsupported {})
    );
    assert_eq!(fixture.repair(&[]), Err(RepairError::StateUnsupported {}));
    assert!(!fixture.host.peer_lock.exists());
}

#[test]
fn counts_only_usable_servers_off_the_orbit_link_as_an_uplink() {
    for (link, global, usable) in [
        ("", "", false),
        ("10.43.0.53", "", false),
        ("127.0.0.53", "", false),
        ("", "198.51.100.53", true),
        ("198.51.100.53", "", true),
        ("", "2001:db8::53#resolver.example", true),
        ("fe80::53%eth0", "", true),
        ("", "::1", false),
        ("999.0.0.53", "", false),
        ("10.0.0.2", "", false),
    ] {
        let listing =
            format!("Global: {global}\nLink 3 (orbit): 198.51.100.9\nLink 7 (eth0): {link}");
        assert_eq!(
            usable_uplink(&listing, "10.43.0.53", "10.0.0.2"),
            usable,
            "{link} {global}"
        );
    }
}

#[test]
fn keeps_an_uplink_snapshot_in_the_transaction_format() {
    let fixture = Fixture::new(true, false);
    let snapshot = capture_uplink(&fixture.host, "eth0").unwrap();
    let decoded = String::from_utf8(STANDARD.decode(&snapshot).unwrap()).unwrap();
    let fields = lines(&decoded);
    assert_eq!(fields.len(), 9);
    assert_eq!(fields[0], "eth0");
    assert_eq!(
        STANDARD
            .decode(fields[1].strip_prefix("v:").unwrap())
            .unwrap(),
        b"10.0.0.2\n"
    );
    fixture.write("state/resolver/eth0/llmnr", "no\n");
    assert!(restore_uplink(&fixture.host, &snapshot, true));
    assert_eq!(fixture.link("eth0").unrelated["llmnr"], "yes");
    assert!(capture_uplink(&fixture.host, "orbit").is_none());
}

#[test]
fn inspects_saved_and_live_state() {
    let fixture = Fixture::new(false, true);
    let state = inspect(
        &InspectInput {
            server: "10.43.0.53".into(),
        },
        &fixture.host,
    )
    .unwrap();
    assert_eq!(
        state,
        PeerDnsState {
            state: "orbit\n10.43.0.53\norbit".into(),
            hooks: "PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit".into(),
            dns: "Link 7 (orbit): 10.43.0.53".into(),
            domains: "Link 7 (orbit): ~orbit".into(),
            default_route: "Link 7 (orbit): no".into(),
            uplink: true,
        }
    );
}

#[test]
fn answers_each_lookup_or_none() {
    let fixture = Fixture::new(false, true);
    assert_eq!(
        query(
            &QueryInput {
                names: domains(&["gateway.orbit", "example.com"])
            },
            &fixture.host
        ),
        Ok(Answers {
            answers: vec![Some("gateway.orbit: 10.43.0.1 -- link: orbit".into()), None]
        })
    );
    assert_eq!(
        query(
            &QueryInput {
                names: domains(&["bad name"])
            },
            &fixture.host
        ),
        Err(QueryError::NameInvalid {})
    );
}
