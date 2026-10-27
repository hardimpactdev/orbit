use super::*;
use crate::command::CommandError;
use crate::commands::peer_dns::tests::Fixture;
const KEY: &str = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";
const SYSTEMCTL: &str = r#"
printf '%s\n' "systemctl $*" >> "$ROOT/commands.log"
case "$1" in
is-active) cat "$ROOT/state/active"; exit 0;;
is-enabled) cat "$ROOT/state/enabled"; exit 0;;
unmask|disable) echo disabled > "$ROOT/state/enabled";;
enable) if [ "${2:-}" = --runtime ]; then echo enabled-runtime; else echo enabled; fi > "$ROOT/state/enabled";;
mask) if [ "${2:-}" = --runtime ]; then echo masked-runtime; else echo masked; fi > "$ROOT/state/enabled";;
start|restart)
 if [ -f "$ROOT/state/restart-failure" ]; then rm "$ROOT/state/restart-failure"; exit 1; fi
 if [ -f "$ROOT/state/recovery-service-failure" ]; then exit 1; fi
 echo active > "$ROOT/state/active"
 cp "$ROOT/wireguard/orbit.conf" "$ROOT/state/started-config"
 hook=$(sed -n 's/^PostUp = //p' "$ROOT/wireguard/orbit.conf")
 if [ -n "$hook" ]; then PATH="$ROOT/bin:$PATH"; eval "${hook//%i/orbit}"; fi
 ;;
stop)
 echo inactive > "$ROOT/state/active"
 if [ -f "$ROOT/state/saveconfig" ]; then printf 'changed by SaveConfig\n' >> "$ROOT/wireguard/orbit.conf"; fi
 ;;
*) exit 1;;
esac
"#;
const WG: &str = r#"
printf '%s\n' "wg $*" >> "$ROOT/commands.log"
case "$1" in
 genkey) printf '%s\n' 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';;
 pubkey) cat >/dev/null; if [ -f "$ROOT/state/key-failure" ]; then exit 1; fi; printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';;
 show) if [ -f "$ROOT/state/live-key-failure" ]; then rm "$ROOT/state/live-key-failure"; exit 1; fi; printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';;
 *) exit 1;;
esac
"#;
fn fixture(underlay: bool) -> Fixture {
    let f = Fixture::new(underlay, false);
    for (name, body) in [("systemctl", SYSTEMCTL), ("wg", WG)] {
        let path = f.root.join("bin").join(name);
        crate::test_support::executable(
            &path,
            format!(
                "#!/bin/bash\nset -eu\nROOT='{}'\n{body}\n",
                f.root.display()
            ),
        );
    }
    f.write("state/active", "active\n");
    f.write("state/enabled", "enabled\n");
    f
}
fn input(retain: bool) -> Input {
    Input {
        server_public_key: KEY.into(),
        peer_public_key: KEY.into(),
        endpoint: "192.0.2.1:51820".into(),
        address: "10.43.0.7/24".into(),
        subnet: "10.43.0.0/24".into(),
        dns_server: "10.43.0.53".into(),
        domains: vec!["custom.internal".into(), "orbit".into()],
        operator_dns: None,
        retain,
    }
}
#[test]
fn keys_are_created_once_and_recoverable_reads_never_rewrite_either_key() {
    let f = fixture(false);
    fs::remove_file(f.host.path("orbit.key")).unwrap();
    assert_eq!(
        prepare_key(KeyInput { existing: true }, &f.host).unwrap_err(),
        Error::KeyMissing {}
    );
    assert!(!f.host.path("orbit.key").exists());
    for _ in 0..2 {
        assert_eq!(
            prepare_key(KeyInput { existing: false }, &f.host)
                .unwrap()
                .public_key,
            KEY
        );
    }
    assert_eq!(f.read("commands.log").matches("wg genkey").count(), 1);
    assert_eq!(
        fs::metadata(&f.host.wireguard).unwrap().mode() & 0o777,
        0o700
    );
    for name in ["orbit.key", "orbit.public"] {
        assert_eq!(
            fs::metadata(f.host.path(name)).unwrap().mode() & 0o777,
            0o600
        );
    }
    f.write("wireguard/orbit.public", "keep-public-file");
    let key_inode = fs::metadata(f.host.path("orbit.key")).unwrap().ino();
    assert_eq!(
        prepare_key(KeyInput { existing: true }, &f.host)
            .unwrap()
            .public_key,
        KEY
    );
    assert_eq!(f.read("wireguard/orbit.public"), "keep-public-file");
    assert_eq!(
        fs::metadata(f.host.path("orbit.key")).unwrap().ino(),
        key_inode
    );
    f.flag("key-failure");
    assert_eq!(
        prepare_key(KeyInput { existing: true }, &f.host).unwrap_err(),
        Error::KeyFailed {}
    );
}
#[test]
fn publication_keeps_private_keys_off_arguments_preserves_protocols_and_repeats() {
    let f = fixture(false);
    for _ in 0..2 {
        assert_eq!(publish_peer(input(false), &f.host).unwrap().public_key, KEY);
        assert!(!f.host.path(STATE).exists());
        assert!(!f.host.path(CANDIDATE).exists());
        assert_eq!(
            f.read("wireguard/orbit.dns-link"),
            "orbit\n10.43.0.53\ncustom.internal\norbit\n"
        );
        assert_eq!(
            f.read("state/resolver/orbit/domain"),
            "~custom.internal\n~orbit\n"
        );
        assert_eq!(f.read("state/resolver/orbit/default-route"), "no\n");
        assert_eq!(f.read("state/resolver/orbit/llmnr"), "resolve\n");
        assert_eq!(f.read("state/resolver/eth0/dns"), "198.51.100.53\n");
        assert!(!f
            .read("commands.log")
            .contains("stable-private-key-material"));
        assert!(!f.read("wireguard/orbit.conf").contains("ListenPort"));
        assert!(!f.read("wireguard/orbit.conf").contains("PreDown"));
    }
}

#[test]
fn routing_domains_that_match_accounts_never_expand_to_home_directories() {
    let f = fixture(false);
    let mut i = input(false);
    i.domains = vec!["root".into(), "orbit".into()];
    publish_peer(i, &f.host).unwrap();
    assert_eq!(f.read("state/resolver/orbit/domain"), "~root\n~orbit\n");
}
#[test]
fn retained_publication_commits_only_the_expected_live_key_and_commit_repeats() {
    let f = fixture(false);
    publish_peer(input(true), &f.host).unwrap();
    let saved = fs::read(f.host.path(STATE)).unwrap();
    assert_eq!(
        fs::metadata(f.host.path(STATE)).unwrap().mode() & 0o777,
        0o600
    );
    assert_eq!(
        publish_peer(input(false), &f.host).unwrap_err(),
        Error::Pending {}
    );
    assert_eq!(
        commit(
            CommitInput {
                public_key: "BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=".into()
            },
            &f.host
        ),
        Err(Error::Commit {})
    );
    assert_eq!(fs::read(f.host.path(STATE)).unwrap(), saved);
    for _ in 0..2 {
        commit(
            CommitInput {
                public_key: KEY.into(),
            },
            &f.host,
        )
        .unwrap();
    }
    assert!(!f.host.path(STATE).exists());
}
#[test]
fn rollback_restores_exact_files_modes_resolver_flags_and_persistent_or_runtime_states() {
    for active in [true, false] {
        for state in [
            "enabled",
            "enabled-runtime",
            "disabled",
            "masked",
            "masked-runtime",
        ] {
            let f = fixture(false);
            f.write(
                "state/active",
                if active { "active\n" } else { "inactive\n" },
            );
            f.write("state/enabled", state);
            fs::set_permissions(f.host.path("orbit.conf"), fs::Permissions::from_mode(0o640))
                .unwrap();
            f.flag("saveconfig");
            let before = f.state();
            let original = f.read("wireguard/orbit.conf");
            publish_peer(input(true), &f.host).unwrap();
            for _ in 0..2 {
                rollback(&f.host).unwrap();
            }
            // An inactive interface has no live resolver object to restore; the fixture keeps its old files.
            if active {
                assert_eq!(f.state(), before, "{active} {state}");
                assert_eq!(f.read("state/started-config"), original);
            }
            assert_eq!(f.read("wireguard/orbit.conf"), original);
            assert_eq!(
                f.read("state/active").trim(),
                if active { "active" } else { "inactive" }
            );
            assert_eq!(f.read("state/enabled").trim(), state);
            assert_eq!(
                fs::metadata(f.host.path("orbit.conf")).unwrap().mode() & 0o777,
                0o640
            );
            assert!(!f.host.path(STATE).exists());
        }
    }
}
#[test]
fn late_failures_restore_state_and_failed_recovery_keeps_a_reusable_snapshot() {
    for fault in ["restart-failure", "live-key-failure", "apply-failure"] {
        let f = fixture(false);
        let before = f.state();
        f.flag(fault);
        assert_eq!(
            publish_peer(input(false), &f.host).unwrap_err(),
            Error::Failed {},
            "{fault}"
        );
        assert_eq!(f.state(), before, "{fault}");
    }
    let f = fixture(false);
    let before = f.state();
    f.flag("recovery-service-failure");
    assert_eq!(
        publish_peer(input(false), &f.host).unwrap_err(),
        Error::Recovery {}
    );
    assert!(f.host.path(STATE).exists());
    f.clear("recovery-service-failure");
    rollback(&f.host).unwrap();
    assert_eq!(f.state(), before);
}
#[test]
fn restores_absent_files_and_dns_symlinks_without_widening_or_dereferencing_them() {
    let f = fixture(false);
    f.write("state/active", "inactive");
    f.write("state/enabled", "disabled");
    fs::remove_file(f.host.path("orbit.conf")).unwrap();
    fs::remove_file(f.host.path("orbit.dns-link")).unwrap();
    publish_peer(input(true), &f.host).unwrap();
    rollback(&f.host).unwrap();
    assert!(!f.host.path("orbit.conf").exists());
    assert!(!f.host.path("orbit.dns-link").exists());
    assert_eq!(f.read("state/active").trim(), "inactive");
    let f = fixture(false);
    let target = f.root.join("original-dns");
    fs::rename(f.host.path("orbit.dns-link"), &target).unwrap();
    std::os::unix::fs::symlink(&target, f.host.path("orbit.dns-link")).unwrap();
    let before = fs::read(&target).unwrap();
    publish_peer(input(true), &f.host).unwrap();
    rollback(&f.host).unwrap();
    assert_eq!(
        fs::read_link(f.host.path("orbit.dns-link")).unwrap(),
        target
    );
    assert_eq!(fs::read(target).unwrap(), before);
}
#[test]
fn refuses_bad_input_files_service_states_and_stale_transactions_before_publication() {
    for kind in [
        "input",
        "domain",
        "endpoint",
        "key",
        "service",
        "enabled",
        "live-link",
        "key-link",
        "pending",
        "unreadable-snapshot",
        "uplink",
        "candidate",
    ] {
        let f = fixture(false);
        let mut i = input(false);
        match kind {
            "input" => i.address = "bad".into(),
            "domain" => i.domains = vec![".".into()],
            "endpoint" => i.endpoint = "evil\nkey:51820".into(),
            "key" => i.peer_public_key = "invalid".into(),
            "service" => f.write("state/active", "failed"),
            "enabled" => f.write("state/enabled", "static"),
            "live-link" => {
                fs::rename(f.host.path("orbit.conf"), f.root.join("prior")).unwrap();
                std::os::unix::fs::symlink(f.root.join("prior"), f.host.path("orbit.conf"))
                    .unwrap();
            }
            "key-link" => {
                fs::remove_file(f.host.path("orbit.key")).unwrap();
                std::os::unix::fs::symlink("elsewhere", f.host.path("orbit.key")).unwrap();
            }
            "pending" => f.write("wireguard/.orbit.conf.rollback", "keep"),
            "unreadable-snapshot" => {
                f.write("wireguard/.orbit.peer-transaction", "dns-repair-v2\n")
            }
            "uplink" => f.write("state/resolver/eth0/dns", ""),
            "candidate" => f.flag("candidate-failure"),
            _ => unreachable!(),
        }
        let before = f.state();
        let e = publish_peer(i, &f.host).unwrap_err();
        assert!(
            matches!(
                e,
                Error::Unsupported {} | Error::Pending {} | Error::Uplink {} | Error::Failed {}
            ),
            "{kind}: {e:?}"
        );
        assert_eq!(f.state(), before, "{kind}");
        assert!(!e.message().contains("stable-private-key-material"));
    }
}
#[test]
fn migrates_underlay_dns_and_can_restore_every_previous_setting() {
    for retain in [false, true] {
        let f = fixture(true);
        let before = f.state();
        publish_peer(input(retain), &f.host).unwrap();
        assert_eq!(f.read("state/resolver/eth0/dns"), "198.51.100.53\n");
        assert_eq!(f.read("state/resolver/eth0/default-route"), "yes\n");
        assert_eq!(f.read("state/resolver/eth0/dnssec"), "yes\n");
        if retain {
            rollback(&f.host).unwrap();
            assert_eq!(f.state(), before);
        }
    }
    let f = fixture(true);
    let before = f.state();
    f.flag("provider-failure");
    assert_eq!(
        publish_peer(input(false), &f.host).unwrap_err(),
        Error::Unsupported {}
    );
    assert_eq!(f.state(), before);
}
#[test]
fn accepts_the_full_namespace_and_keeps_operator_dns_separate() {
    let f = fixture(false);
    let mut i = input(false);
    i.domains = (0..512).map(|n| format!("domain-{n}.test")).collect();
    publish_peer(i, &f.host).unwrap();
    assert_eq!(f.read("wireguard/orbit.dns-link").lines().count(), 514);
    let mut i = input(false);
    i.operator_dns = Some("10.43.0.1".into());
    publish_peer(i, &f.host).unwrap();
    assert!(f.read("wireguard/orbit.conf").contains("DNS = 10.43.0.1"));
    assert!(!f.read("wireguard/orbit.conf").contains("PostUp"));
    assert!(!f.host.path("orbit.dns-link").exists());
}

#[test]
fn rollback_restores_live_dns_selection_without_a_saved_dns_link_file() {
    let f = fixture(false);
    fs::remove_file(f.host.path("orbit.dns-link")).unwrap();
    f.write(
        "state/resolver/orbit/dns",
        "10.43.0.1\n2001:db8::53#resolver.test\n",
    );
    f.write("state/resolver/orbit/domain", "~.\nsearch.test\n");
    let before = f.state();
    let mut i = input(true);
    i.operator_dns = Some("10.43.0.1".into());
    publish_peer(i, &f.host).unwrap();
    // A real tunnel restart replaces the link object, as wg-quick's resolvconf setup does on an operator.
    f.write("state/resolver/orbit/dns", "10.43.0.1\n");
    f.write("state/resolver/orbit/domain", "~.\n");
    rollback(&f.host).unwrap();
    assert_eq!(f.state(), before);
}
#[test]
fn all_peer_mutations_share_the_existing_dns_lock() {
    let f = fixture(false);
    let before = f.state();
    let _held = peer_dns::hold(&f.host.peer_lock, Duration::from_secs(1), true)
        .unwrap()
        .unwrap();
    assert_eq!(
        prepare_key(KeyInput { existing: false }, &f.host).unwrap_err(),
        Error::Busy {}
    );
    assert_eq!(
        publish_peer(input(false), &f.host).unwrap_err(),
        Error::Busy {}
    );
    assert_eq!(
        commit(
            CommitInput {
                public_key: KEY.into()
            },
            &f.host
        ),
        Err(Error::Busy {})
    );
    assert_eq!(rollback(&f.host), Err(Error::Busy {}));
    assert_eq!(f.state(), before);
}

#[test]
fn dns_repair_refuses_a_retained_peer_publication_without_touching_its_snapshot() {
    let f = fixture(false);
    publish_peer(input(true), &f.host).unwrap();
    let before = f.state();
    let saved = fs::read(f.host.path(STATE)).unwrap();
    let i = input(false);
    assert_eq!(
        peer_dns::repair(
            &peer_dns::RepairInput {
                dns_server: i.dns_server,
                public_key: i.peer_public_key,
                domains: i.domains,
            },
            &f.host
        ),
        Err(peer_dns::RepairError::RecoveryPending {})
    );
    assert_eq!(f.state(), before);
    assert_eq!(fs::read(f.host.path(STATE)).unwrap(), saved);
    rollback(&f.host).unwrap();
}

#[test]
fn oversized_snapshot_is_refused_before_any_publication() {
    let f = fixture(false);
    // Each file is within its capture limit, but JSON byte arrays expand beyond the read bound.
    f.write("wireguard/orbit.conf", &"x".repeat(256 * 1024));
    f.write(
        "wireguard/orbit.dns-link",
        &format!(
            "orbit\n10.43.0.53\n{}",
            format!("{}\n", "x".repeat(253)).repeat(1000)
        ),
    );
    let before = f.state();
    assert_eq!(
        publish_peer(input(true), &f.host).unwrap_err(),
        Error::Unsupported {}
    );
    assert_eq!(f.state(), before);
    assert!(!f.host.path(STATE).exists());
    assert!(!f.host.path(CANDIDATE).exists());
}

#[test]
fn malformed_and_foreign_recovery_records_are_left_untouched() {
    for contents in ["dns-repair-v2\n", "{", r#"{"peer_transaction":true}"#] {
        let f = fixture(false);
        f.write("wireguard/.orbit.peer-transaction", contents);
        let before = f.state();
        assert_eq!(rollback(&f.host), Err(Error::Unsupported {}));
        assert_eq!(
            commit(
                CommitInput {
                    public_key: KEY.into()
                },
                &f.host
            ),
            Err(Error::Unsupported {})
        );
        assert_eq!(f.state(), before);
        assert_eq!(f.read("wireguard/.orbit.peer-transaction"), contents);
    }
}

#[test]
fn orphaned_recovery_artifacts_refuse_publication_without_overwriting_evidence() {
    for name in [
        CANDIDATE,
        ".orbit.dns-link.candidate",
        ".orbit.peer-transaction.candidate",
        ".orbit.conf.restore",
        ".orbit.dns-link.restore",
    ] {
        let f = fixture(false);
        fs::write(f.host.path(name), "retained evidence").unwrap();
        let before = f.state();
        assert_eq!(
            publish_peer(input(false), &f.host).unwrap_err(),
            Error::Pending {}
        );
        assert_eq!(f.state(), before);
        assert_eq!(
            fs::read_to_string(f.host.path(name)).unwrap(),
            "retained evidence"
        );
    }
}

#[test]
fn global_dns_and_delayed_provider_dns_allow_publication() {
    let f = fixture(false);
    f.write("state/resolver/eth0/dns", "");
    f.write("state/global-dns", "198.51.100.53");
    f.write("state/resolver/orbit/nta", ".");
    let before = f.state();
    publish_peer(input(true), &f.host).unwrap();
    rollback(&f.host).unwrap();
    assert_eq!(f.state(), before);

    let f = fixture(true);
    let before = f.state();
    f.flag("provider-delay");
    publish_peer(input(true), &f.host).unwrap();
    rollback(&f.host).unwrap();
    assert_eq!(f.state(), before);
}

#[test]
fn failed_config_publication_restores_the_original_state() {
    let mut f = fixture(false);
    let before = f.state();
    f.host.fail_rename = Some((f.host.path(CANDIDATE), f.host.path("orbit.conf")));
    assert_eq!(
        publish_peer(input(false), &f.host).unwrap_err(),
        Error::Failed {}
    );
    assert_eq!(f.state(), before);
}
