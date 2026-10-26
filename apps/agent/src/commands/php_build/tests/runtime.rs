use super::super::runtime as native;
use super::*;
use std::{
    io::{Read, Write},
    os::unix::net::UnixListener,
};

#[test]
fn settings_only_repair_reloads_all_active_masters_then_is_current() {
    for scope in [Fpm::None, Fpm::Shared, Fpm::All] {
        let mut f = Fixture::new();
        f.current();
        f.input.fpm = scope;
        f.host.active.extend([UNIT.into(), DEDICATED.into()]);
        let report = f.apply().unwrap();
        assert_eq!(report.data.reloaded, [UNIT, DEDICATED]);
        assert!(report.data.restarted.is_empty());
        let report = f.apply().unwrap();
        assert!(matches!(report.outcome, operations::Outcome::Current));
        assert!(report.data.reloaded.is_empty());
    }
}

#[test]
fn package_update_restarts_selected_masters_and_probes_every_socket() {
    for scope in [Fpm::Shared, Fpm::All] {
        let mut f = Fixture::new();
        f.old();
        f.input.fpm = scope;
        f.host.active.extend([UNIT.into(), DEDICATED.into()]);
        write(
            &f.files,
            "/etc/php/8.5/fpm/pool.d/www.conf",
            "listen=/run/php/shared.sock\n",
        );
        write(
            &f.files,
            "/etc/orbit/example.conf",
            "listen=/run/php/dedicated.sock\n",
        );
        let report = f.apply().unwrap();
        assert!(report.data.reloaded.is_empty());
        if scope == Fpm::All {
            assert_eq!(report.data.restarted, [UNIT, DEDICATED]);
            assert_eq!(f.host.pings.len(), 2);
        } else {
            assert_eq!(report.data.restarted, [UNIT]);
            assert_eq!(report.data.stale_masters, [DEDICATED]);
            assert_eq!(f.host.pings.len(), 1);
        }
    }
}

#[test]
fn invalid_master_is_never_restarted_and_only_supported_scopes_defer_it() {
    for (scope, unit, deferred) in [
        (Fpm::All, UNIT, false),
        (Fpm::Shared, UNIT, true),
        (Fpm::None, DEDICATED, true),
    ] {
        let mut f = Fixture::new();
        f.current();
        f.input.fpm = scope;
        f.host.active.insert(unit.into());
        f.host.invalid.insert(unit.into());
        let result = f.apply();
        if deferred {
            assert_eq!(result.unwrap().data.deferred, [unit]);
        } else {
            let error = result.unwrap_err();
            assert_eq!(error.kind, Kind::FpmInvalid);
            assert_eq!(error.data.restored, Some(true));
        }
        assert!(f
            .calls("systemctl")
            .iter()
            .all(|c| c.argv[1] != "reload" && c.argv[1] != "restart"));
    }
}

#[test]
fn later_invalid_master_restores_settings_and_reloads_earlier_master() {
    let mut f = Fixture::new();
    f.current();
    f.host.active.extend([UNIT.into(), DEDICATED.into()]);
    f.host.invalid.insert(DEDICATED.into());
    let path = write(
        &f.files,
        "/etc/php/8.5/cli/conf.d/99-cve-2026-109618-upload-progress.ini",
        "previous\n",
    );
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::FpmInvalid);
    assert_eq!(error.data.restored, Some(true));
    assert_eq!(fs::read_to_string(path).unwrap(), "previous\n");
    let reloads: Vec<_> = f
        .calls("systemctl")
        .iter()
        .filter(|c| c.argv[1] == "reload")
        .map(|c| c.argv[2].clone())
        .collect();
    assert_eq!(reloads, [UNIT, UNIT]);
}

#[test]
fn unhealthy_pool_restores_packages_modules_and_refreshes_touched_master() {
    let mut f = Fixture::new();
    f.old();
    f.host.active.insert(UNIT.into());
    f.host.ping = false;
    write(
        &f.files,
        "/etc/php/8.5/fpm/pool.d/www.conf",
        "listen=/run/php/shared.sock\n",
    );
    let error = f.apply().unwrap_err();
    assert_eq!(error.kind, Kind::RolledBack);
    assert_eq!(error.data.restored, Some(true));
    assert_eq!(f.host.packages["php8.5-fpm"], "1.0");
    assert_eq!(
        f.calls("systemctl")
            .iter()
            .filter(|c| c.argv[1] == "restart")
            .count(),
        2
    );
}

#[test]
fn pcov_is_cli_only_and_opentelemetry_is_fpm_only() {
    let mut f = Fixture::new();
    f.current();
    f.input
        .versions
        .get_mut("8.5")
        .unwrap()
        .extensions
        .insert("pcov".into());
    f.input.pcov = true;
    f.input.opentelemetry = true;
    module_link(&f.files, "fpm", "pcov");
    module_link(&f.files, "cli", "opentelemetry");
    f.host.active.insert(UNIT.into());
    f.apply().unwrap();
    let links = native::links(&f.files, "8.5").unwrap();
    assert!(links["cli"].contains_key("20-pcov.ini"));
    assert!(!links["fpm"].contains_key("20-pcov.ini"));
    assert!(!links["cli"].contains_key("20-opentelemetry.ini"));
    assert!(links["fpm"].contains_key("20-opentelemetry.ini"));
    assert!(matches!(
        f.apply().unwrap().outcome,
        operations::Outcome::Current
    ));
    f.input.pcov = false;
    f.input.opentelemetry = false;
    f.apply().unwrap();
    let links = native::links(&f.files, "8.5").unwrap();
    assert!(links.values().all(BTreeMap::is_empty));
}

#[test]
fn missing_fpm_telemetry_rolls_back_the_previous_module_links() {
    struct Missing(Fake);
    impl RecipeHost for Missing {
        fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
            let mut result = self.0.execute(call)?;
            if call.argv[0].ends_with("/php-fpm8.5") && call.argv[1] == "-m" {
                result.stdout = result
                    .stdout
                    .lines()
                    .filter(|v| *v != "opentelemetry")
                    .collect::<Vec<_>>()
                    .join("\n");
            }
            Ok(result)
        }
        fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
            panic!("No retry")
        }
    }
    impl Host for Missing {
        fn ping(&mut self, path: &Path, deadline: Instant) -> bool {
            self.0.ping(path, deadline)
        }
    }
    let mut f = Fixture::new();
    f.current();
    f.input.opentelemetry = true;
    f.host.active.insert(UNIT.into());
    let before = native::links(&f.files, "8.5").unwrap();
    let mut host = Missing(f.host);
    let error = operations::apply(
        &mut host,
        &f.files,
        &f.input,
        Instant::now() + Duration::from_secs(5),
    )
    .unwrap_err();
    assert_eq!(error.kind, Kind::RolledBack);
    assert_eq!(error.data.restored, Some(true));
    assert_eq!(native::links(&f.files, "8.5").unwrap(), before);
}

#[test]
fn image_php_sets_both_upload_progress_mitigations_without_starting_services() {
    let mut f = Fixture::new();
    f.input.fpm = Fpm::None;
    f.apply().unwrap();
    for sapi in ["cli", "fpm"] {
        assert_eq!(
            fs::read_to_string(f.files.path(&format!(
                "/etc/php/8.5/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini"
            )))
            .unwrap(),
            "session.upload_progress.enabled = 0\n"
        );
    }
    assert!(f
        .calls("systemctl")
        .iter()
        .all(|c| ["list-units", "is-active", "show"].contains(&c.argv[1].as_str())));
}

#[test]
fn disabled_policy_preserves_masks_stops_active_units_and_refuses_failure() {
    for enabled in ["enabled", "masked", "disabled"] {
        let mut f = Fixture::new();
        f.input.fpm = Fpm::Disabled;
        f.host.active.insert(UNIT.into());
        f.host.enabled.insert(UNIT.into(), enabled.into());
        assert!(f.apply().unwrap().data.fpm_disabled);
        assert!(!f.host.active.contains(UNIT));
        assert_eq!(
            f.host.enabled[UNIT],
            if enabled == "masked" {
                "masked"
            } else {
                "disabled"
            }
        );
        assert!(matches!(
            f.apply().unwrap().outcome,
            operations::Outcome::Current
        ));
    }
    let mut f = Fixture::new();
    f.current();
    f.input.fpm = Fpm::Disabled;
    f.host.enabled.insert(UNIT.into(), "enabled".into());
    f.fail("systemctl disable", 1);
    assert_eq!(f.apply().unwrap_err().kind, Kind::FpmInvalid);
}

#[test]
fn fastcgi_accepts_fragmented_end_request_and_rejects_malformed_or_stalled_replies() {
    use crate::commands::php_runtime::record;
    for reply in [
        Some(record(3, &[0; 8])),
        Some(record(3, &[0; 7])),
        Some(vec![2, 3, 0, 1, 0, 0, 0, 0]),
        None,
    ] {
        let work = recipe::WorkDir::new().unwrap();
        let path = work.0.join("fpm.sock");
        let listener = UnixListener::bind(&path).unwrap();
        let good = reply.as_ref().is_some_and(|r| r == &record(3, &[0; 8]));
        // Only the stalled server needs a deadline shorter than its 100 ms sleep; a reply gets time to arrive under load.
        let wait = Duration::from_millis(if reply.is_some() { 5000 } else { 50 });
        let server = std::thread::spawn(move || {
            let (mut socket, _) = listener.accept().unwrap();
            socket
                .set_read_timeout(Some(Duration::from_secs(1)))
                .unwrap();
            loop {
                let mut header = [0; 8];
                socket.read_exact(&mut header).unwrap();
                let length = usize::from(u16::from_be_bytes([header[4], header[5]]))
                    + usize::from(header[6]);
                let mut body = vec![0; length];
                socket.read_exact(&mut body).unwrap();
                if header[1] == 5 {
                    break;
                }
            }
            if let Some(reply) = reply {
                for fragment in reply.chunks(3) {
                    if socket.write_all(fragment).is_err() {
                        break;
                    }
                }
            } else {
                std::thread::sleep(Duration::from_millis(100));
            }
        });
        assert_eq!(native::ping(&path, Instant::now() + wait).is_ok(), good);
        server.join().unwrap();
    }
}
