//! The PHP runtime commands against a sandbox that the test user owns, as root owns the paths on a Node. A stub
//! `systemctl` keeps the service state in files, and a stub `php-fpm8.5` accepts any configuration and prints the
//! settings of the `master.ini` it was given. A restart starts a new master: a new pid with its own `/proc` entry.

use super::*;
use crate::test_support::executable;
use std::os::unix::fs::symlink;

const SYSTEMCTL: &str = r#"#!/bin/sh
state=$(dirname "$0")/../state
printf '%s\n' "$*" >> "$state/calls"
verb=$1
[ "$verb" = enable ] && [ "$2" = --now ] && verb=enable-now
[ "$verb" = disable ] && [ "$2" = --now ] && verb=disable-now
[ -e "$state/fail-$verb" ] && exit 1
start() {
    next=$(($(cat "$state/pid") + 1))
    mkdir -p "$state/../proc/$next"
    # The start time, field 22, grows with every master.
    printf '%s (php-fpm8.5) S 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1 %s\n' "$next" "$next" > "$state/../proc/$next/stat"
    ln -sf "$state/../sbin/php-fpm8.5" "$state/../proc/$next/exe"
    printf '%s' "$next" > "$state/pid"
    touch "$state/active"
    # A started master opens the pool socket.
    socket=$(cat "$state/socket")
    [ -S "$socket" ] || python3 -c 'import socket, sys; socket.socket(socket.AF_UNIX).bind(sys.argv[1])' "$socket"
    chmod 0660 "$socket"
}
case $verb in
    is-active) [ -e "$state/active" ] ;;
    is-enabled) [ -e "$state/enabled" ] ;;
    show) if [ -e "$state/active" ]; then cat "$state/pid"; else printf 0; fi ;;
    enable) touch "$state/enabled" ;;
    enable-now) touch "$state/enabled"; start ;;
    restart|start) start ;;
    disable-now|stop) rm -f "$state/enabled" "$state/active" ;;
    *) ;;
esac
"#;

const FPM: &str = r#"#!/bin/sh
[ "$1" = -i ] || exit 0
ini=${PHP_INI_SCAN_DIR##*:}/master.ini
printf 'Additional .ini files parsed => %s\n' "$ini"
while IFS= read -r line; do
    case "$line" in ''|';'*) continue ;; esac
    key=${line%%=*}; key=${key% }; value=${line#*=}; value=${value# }
    [ "$key" = opcache.validate_timestamps ] && [ "$value" = 0 ] && value=Off
    printf '%s => %s => %s\n' "$key" "$value" "$value"
done < "$ini"
"#;

pub(super) struct Fixture {
    pub(super) base: PathBuf,
    pub(super) host: Host,
    pub(super) user: String,
    pub(super) home: PathBuf,
}

impl Fixture {
    pub(super) fn new() -> Self {
        let base = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!("orbit-php-runtime-{:016x}", rand::random::<u64>()));
        for directory in [
            "etc",
            "units",
            "sockets",
            "proc/sys/kernel/random",
            "sbin",
            "php",
            "tmp",
            "state",
            "bin",
        ] {
            fs::create_dir_all(base.join(directory)).unwrap();
        }
        fs::set_permissions(base.join("tmp"), fs::Permissions::from_mode(0o1777)).unwrap();
        // As on a Node, no one else may write the parents of a runtime, whatever the umask.
        fs::set_permissions(base.join("etc"), fs::Permissions::from_mode(0o755)).unwrap();
        fs::write(
            base.join("proc/sys/kernel/random/boot_id"),
            fs::read("/proc/sys/kernel/random/boot_id").unwrap(),
        )
        .unwrap();
        executable(base.join("bin/systemctl"), SYSTEMCTL);
        executable(base.join("sbin/php-fpm8.5"), FPM);
        fs::write(base.join("state/pid"), "620").unwrap();

        let uid = rustix::process::geteuid().as_raw();
        let gid = rustix::process::getegid().as_raw();
        let user = super::super::source::user_name(uid);
        let home = base.join("home").join(&user);
        fs::create_dir_all(home.join("releases/initial/public")).unwrap();
        fs::write(
            base.join("state/socket"),
            base.join(format!("sockets/{user}.sock"))
                .as_os_str()
                .as_encoded_bytes(),
        )
        .unwrap();
        let host = Host {
            homes: base.join("home"),
            etc: base.join("etc/orbit"),
            units: base.join("units"),
            sockets: base.join("sockets"),
            proc_root: base.join("proc"),
            sbin: base.join("sbin"),
            php_conf: base.join("php"),
            tmp: base.join("tmp"),
            systemctl: base.join("bin/systemctl"),
            root_uid: uid,
            root_gid: gid,
            caddy_gid: gid,
        };
        Self {
            base,
            host,
            user,
            home,
        }
    }

    fn marker(&self) -> String {
        format!("user={}\nversion=8.5\n", self.user)
    }

    fn pool(&self, release: &str, monitoring: bool) -> String {
        let status = if monitoring {
            "pm.status_path = /orbit-fpm-status\n"
        } else {
            ""
        };
        format!(
            "[orbit-{}]\nuser = {}\nlisten = {}\nchdir = {}\n{status}",
            self.user,
            self.user,
            self.host
                .sockets
                .join(format!("{}.sock", self.user))
                .display(),
            self.home.join(release).display()
        )
    }

    pub(super) fn input(&self, mode: Mode, monitoring: bool) -> ConvergeInput {
        ConvergeInput {
            mode,
            user: self.user.clone(),
            home: self.home.clone(),
            version: "8.5".into(),
            marker: self.marker(),
            configuration: Configuration {
                main: "[global]\ninclude = /etc/orbit/php-fpm/x/generated/pool.conf\ninclude = /etc/orbit/php-fpm/x/local.conf\n"
                    .into(),
                pool: self.pool("current/public", monitoring),
                local_defaults: format!("[orbit-{}]\npm.max_children = 5\n", self.user),
                master_ini: "opcache.enable = 1\nopcache.validate_timestamps = 0\n".into(),
                unit: "[Service]\nExecStart=/usr/sbin/php-fpm8.5\n".into(),
                initial_pool: self.pool("releases/initial/public", monitoring),
            },
            application: self.home.join("current/public"),
            initial_application: self.home.join("releases/initial/public"),
        }
    }

    /// Points `current` at a new release with the application directory.
    fn release(&self, name: &str) {
        fs::create_dir_all(self.home.join(format!("releases/{name}/public"))).unwrap();
        let current = self.home.join("current");
        let _ = fs::remove_file(&current);
        symlink(self.home.join(format!("releases/{name}")), current).unwrap();
    }

    fn runtime(&self) -> PathBuf {
        self.host.etc.join("php-fpm").join(&self.user)
    }

    fn calls(&self) -> Vec<String> {
        fs::read_to_string(self.base.join("state/calls"))
            .unwrap_or_default()
            .lines()
            .filter(|line| !line.starts_with("is-") && !line.starts_with("show"))
            .map(String::from)
            .collect()
    }

    fn clear_calls(&self) {
        let _ = fs::remove_file(self.base.join("state/calls"));
    }

    fn fail(&self, verb: &str) {
        fs::write(self.base.join(format!("state/fail-{verb}")), "").unwrap();
    }

    fn pid(&self) -> String {
        fs::read_to_string(self.base.join("state/pid")).unwrap()
    }
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.base);
    }
}

fn service(fixture: &Fixture) -> String {
    format!("orbit-{}-php8.5-fpm.service", fixture.user)
}

#[test]
fn publishes_a_new_runtime_once_and_keeps_a_confirmed_one_a_no_op() {
    let fixture = Fixture::new();
    let input = fixture.input(Mode::Converge, false);

    assert_eq!(
        converge(&input, &fixture.host),
        Ok(Converged { published: true })
    );

    let runtime = fixture.runtime();
    assert_eq!(
        fs::read_to_string(runtime.join("orbit.identity")).unwrap(),
        fixture.marker()
    );
    assert_eq!(
        fs::read_to_string(runtime.join("local.conf")).unwrap(),
        input.configuration.local_defaults
    );
    let pool = fs::read_to_string(runtime.join("generated/pool.conf")).unwrap();
    assert!(pool.ends_with(&format!(
        "\n; Orbit application release: {}\n",
        fixture.home.join("releases/initial/public").display()
    )));
    assert_eq!(
        fs::metadata(fixture.host.etc.clone()).unwrap().mode() & 0o7777,
        0o711
    );
    assert_eq!(fs::metadata(&runtime).unwrap().mode() & 0o7777, 0o755);
    assert_eq!(
        fs::metadata(runtime.join("generated/pool.conf"))
            .unwrap()
            .mode()
            & 0o7777,
        0o644
    );
    assert_eq!(
        fs::read_to_string(fixture.host.units.join(service(&fixture))).unwrap(),
        input.configuration.unit
    );
    assert_eq!(
        fixture.calls(),
        [
            "daemon-reload".to_string(),
            format!("enable --now {}", service(&fixture))
        ]
    );
    let applied = fs::read_to_string(runtime.join(APPLIED)).unwrap();
    assert_eq!(applied.lines().count(), 4);
    assert_eq!(applied.lines().nth(2), Some("621"));
    assert!(!runtime.join(PENDING).exists());

    let inode = fs::metadata(runtime.join(APPLIED)).unwrap().ino();
    fixture.clear_calls();
    assert_eq!(
        converge(&input, &fixture.host),
        Ok(Converged { published: false })
    );
    assert_eq!(fixture.calls(), [format!("enable {}", service(&fixture))]);
    assert_eq!(fs::metadata(runtime.join(APPLIED)).unwrap().ino(), inode);
    assert_eq!(fixture.pid(), "621");
}

#[test]
fn restarts_once_for_a_new_release_and_recovers_an_interrupted_publication() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    fixture.release("fresh");
    fixture.clear_calls();

    // A publication killed after its files: the pending receipt and the new files exist, the old master runs.
    let runtime = fixture.runtime();
    let selected = select_application_pool(&fixture.input(Mode::Converge, false)).unwrap();
    begin_generation(
        &fixture.host,
        &Runtime::new(&fixture.host, &fixture.user, "8.5", &fixture.marker()).unwrap(),
        &selected,
    )
    .unwrap();
    fs::write(runtime.join("generated/pool.conf"), &selected).unwrap();

    assert_eq!(
        converge(&fixture.input(Mode::Converge, false), &fixture.host),
        Ok(Converged { published: true })
    );
    assert_eq!(
        fixture.calls(),
        [
            "daemon-reload".to_string(),
            format!("enable {}", service(&fixture)),
            format!("restart {}", service(&fixture))
        ]
    );
    assert!(!runtime.join(PENDING).exists());
    assert_eq!(fixture.pid(), "622");

    fixture.clear_calls();
    assert_eq!(
        converge(&fixture.input(Mode::Converge, false), &fixture.host),
        Ok(Converged { published: false })
    );
    assert_eq!(fixture.calls(), [format!("enable {}", service(&fixture))]);
}

#[test]
fn a_master_restarted_outside_orbit_keeps_the_generation_applied() {
    let fixture = Fixture::new();
    let input = fixture.input(Mode::Converge, false);
    converge(&input, &fixture.host).unwrap();
    Process::new(&fixture.host.systemctl)
        .args(["restart", &service(&fixture)])
        .status()
        .unwrap();
    fixture.clear_calls();

    assert_eq!(
        converge(&input, &fixture.host),
        Ok(Converged { published: false })
    );
    assert_eq!(fixture.calls(), [format!("enable {}", service(&fixture))]);
}

#[test]
fn a_failed_restart_puts_the_previous_files_and_service_back() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let runtime = fixture.runtime();
    let pool_before = fs::read(runtime.join("generated/pool.conf")).unwrap();
    fixture.release("fresh");
    fixture.fail("restart");
    fixture.clear_calls();

    let error = converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap_err();

    assert_eq!(error, invalid("the service restarts"));
    assert_eq!(
        fs::read(runtime.join("generated/pool.conf")).unwrap(),
        pool_before
    );
    assert!(
        runtime.join(PENDING).exists(),
        "the next run publishes again"
    );
    let calls = fixture.calls();
    assert!(calls.contains(&format!("disable --now {}", service(&fixture))));
    assert_eq!(calls.last(), Some(&format!("start {}", service(&fixture))));
}

#[test]
fn refuses_local_tuning_that_names_the_pool_identity_before_any_change() {
    let fixture = Fixture::new();
    let runtime = fixture.runtime();
    fs::create_dir_all(&runtime).unwrap();
    fs::set_permissions(&runtime, fs::Permissions::from_mode(0o755)).unwrap();
    fs::write(runtime.join("orbit.identity"), fixture.marker()).unwrap();
    fs::set_permissions(
        runtime.join("orbit.identity"),
        fs::Permissions::from_mode(0o644),
    )
    .unwrap();
    for directive in ["listen = /tmp/other.sock", "chroot = /tmp/jail"] {
        fs::write(
            runtime.join("local.conf"),
            format!("[orbit-{}]\n{directive}\n", fixture.user),
        )
        .unwrap();
        fs::set_permissions(
            runtime.join("local.conf"),
            fs::Permissions::from_mode(0o644),
        )
        .unwrap();

        assert_eq!(
            converge(&fixture.input(Mode::Converge, false), &fixture.host),
            Err(invalid("local.conf only tunes the pool"))
        );
        assert!(!runtime.join("generated").exists());
        assert!(fixture.calls().is_empty());
    }
}

#[test]
fn refuses_a_runtime_whose_marker_names_another_instance() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let mut input = fixture.input(Mode::Converge, false);
    input.marker = format!("user={}\nversion=8.4\n", fixture.user);

    assert_eq!(
        converge(&input, &fixture.host),
        Err(invalid("the runtime marker is this Instance's"))
    );
}

#[test]
fn the_local_tuning_rule_allows_tuning_only() {
    assert!(local_tuning_allowed(
        "[orbit-a]\n; listen = no\npm.max_children = 5\n",
        "orbit-a",
        true
    ));
    assert!(!local_tuning_allowed("[orbit-b]\n", "orbit-a", false));
    assert!(!local_tuning_allowed(
        "  Include=/etc/x\n",
        "orbit-a",
        false
    ));
    assert!(!local_tuning_allowed(
        "env[HOME] = /root\n",
        "orbit-a",
        false
    ));
    assert!(!local_tuning_allowed(
        "pm.status_path = /x\n",
        "orbit-a",
        true
    ));
    assert!(local_tuning_allowed(
        "pm.status_path = /x\n",
        "orbit-a",
        false
    ));
}

#[test]
fn a_monitoring_change_reloads_and_never_restarts() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    fixture.clear_calls();

    assert_eq!(
        converge(&fixture.input(Mode::Monitor, true), &fixture.host),
        Ok(Converged { published: false })
    );

    let runtime = fixture.runtime();
    assert!(fs::read_to_string(runtime.join("generated/pool.conf"))
        .unwrap()
        .contains("pm.status_path = /orbit-fpm-status"));
    assert_eq!(fixture.calls(), [format!("reload {}", service(&fixture))]);
    assert!(!runtime.join(METRICS_PENDING).exists());
    assert!(!runtime.join(METRICS_BACKUP).exists());
    assert_eq!(fixture.pid(), "621");
}

#[test]
fn monitoring_leaves_a_missing_runtime_alone() {
    let fixture = Fixture::new();

    assert_eq!(
        converge(&fixture.input(Mode::Monitor, true), &fixture.host),
        Ok(Converged { published: false })
    );
    assert!(!fixture.runtime().exists());
}

#[test]
fn an_interrupted_monitoring_change_finishes_before_convergence() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let runtime = fixture.runtime();
    let old = fs::read(runtime.join("generated/pool.conf")).unwrap();
    let new = select_application_pool(&fixture.input(Mode::Monitor, true)).unwrap();
    // Killed after it published the new pool and before the reload.
    fs::write(runtime.join(METRICS_BACKUP), &old).unwrap();
    fs::write(
        runtime.join(METRICS_PENDING),
        format!("active {}\n", sha256(&new)),
    )
    .unwrap();
    for name in [METRICS_BACKUP, METRICS_PENDING] {
        fs::set_permissions(runtime.join(name), fs::Permissions::from_mode(0o600)).unwrap();
    }
    fs::write(runtime.join("generated/pool.conf"), &new).unwrap();
    fixture.clear_calls();

    converge(&fixture.input(Mode::Converge, true), &fixture.host).unwrap();

    assert!(!runtime.join(METRICS_PENDING).exists());
    assert!(!runtime.join(METRICS_BACKUP).exists());
    assert_eq!(fixture.calls()[0], format!("reload {}", service(&fixture)));
}

#[test]
fn removes_the_service_and_its_files_and_keeps_local_tuning() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let input = RemoveInput {
        user: fixture.user.clone(),
        version: "8.5".into(),
        marker: fixture.marker(),
    };

    assert_eq!(remove(&input, &fixture.host), Ok(Removed { removed: true }));

    let runtime = fixture.runtime();
    assert!(!runtime.join("generated").exists());
    assert_eq!(
        fs::read_to_string(runtime.join("orbit.identity")).unwrap(),
        fixture.marker()
    );
    assert!(!runtime.join(APPLIED).exists());
    assert!(runtime.join("local.conf").exists());
    assert!(!fixture.host.units.join(service(&fixture)).exists());
    assert!(!fixture
        .host
        .sockets
        .join(format!("{}.sock", fixture.user))
        .exists());
    assert_eq!(
        remove(&input, &fixture.host),
        Ok(Removed { removed: false })
    );

    let tuning = fs::read(runtime.join("local.conf")).unwrap();
    let mut foreign = fixture.input(Mode::Converge, false);
    foreign.marker = "foreign runtime\n".into();
    assert_eq!(
        converge(&foreign, &fixture.host),
        Err(invalid("the runtime marker is this Instance's"))
    );
    assert!(!runtime.join("generated").exists());
    assert_eq!(fs::read(runtime.join("local.conf")).unwrap(), tuning);

    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    assert_eq!(fs::read(runtime.join("local.conf")).unwrap(), tuning);
    assert!(fixture.host.active(&service(&fixture)));
}

#[test]
fn removal_keeps_everything_while_a_master_still_runs() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    fixture.fail("disable-now");
    let input = RemoveInput {
        user: fixture.user.clone(),
        version: "8.5".into(),
        marker: fixture.marker(),
    };

    assert_eq!(
        remove(&input, &fixture.host),
        Err(invalid("the service stops"))
    );
    assert!(fixture.runtime().join("generated/pool.conf").exists());
}

#[test]
fn a_failed_monitoring_reload_keeps_its_journal_and_the_next_run_puts_the_old_pool_back() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let runtime = fixture.runtime();
    let original = fs::read(runtime.join("generated/pool.conf")).unwrap();
    fixture.fail("reload");

    assert_eq!(
        converge(&fixture.input(Mode::Monitor, true), &fixture.host),
        Err(invalid("the PHP-FPM service reloads"))
    );
    assert!(runtime.join(METRICS_PENDING).exists());
    assert!(runtime.join(METRICS_BACKUP).exists());

    fs::remove_file(fixture.base.join("state/fail-reload")).unwrap();
    fixture.clear_calls();
    converge(&fixture.input(Mode::Monitor, false), &fixture.host).unwrap();

    assert_eq!(
        fs::read(runtime.join("generated/pool.conf")).unwrap(),
        original
    );
    assert!(!runtime.join(METRICS_PENDING).exists());
    assert!(!runtime.join(METRICS_BACKUP).exists());
    assert_eq!(fixture.calls(), [format!("reload {}", service(&fixture))]);
}

#[test]
fn a_journal_of_a_stopped_master_resolves_without_starting_or_reloading_it() {
    let fixture = Fixture::new();
    converge(&fixture.input(Mode::Converge, false), &fixture.host).unwrap();
    let runtime = fixture.runtime();
    let pool = fs::read(runtime.join("generated/pool.conf")).unwrap();
    fs::remove_file(fixture.base.join("state/active")).unwrap();
    fs::write(runtime.join(METRICS_BACKUP), &pool).unwrap();
    fs::write(
        runtime.join(METRICS_PENDING),
        format!("stopped {}\n", sha256(&pool)),
    )
    .unwrap();
    for name in [METRICS_BACKUP, METRICS_PENDING] {
        fs::set_permissions(runtime.join(name), fs::Permissions::from_mode(0o600)).unwrap();
    }
    fixture.clear_calls();

    converge(&fixture.input(Mode::Monitor, false), &fixture.host).unwrap();

    assert!(!runtime.join(METRICS_PENDING).exists());
    assert!(fixture.calls().is_empty(), "{:?}", fixture.calls());
}

#[test]
fn refuses_a_served_pool_outside_the_releases() {
    let fixture = Fixture::new();
    let mut input = fixture.input(Mode::Converge, false);
    input
        .configuration
        .initial_pool
        .push_str("[orbit-served]\nchdir = /tmp\n");

    assert_eq!(
        converge(&input, &fixture.host),
        Err(invalid("a served application directory is in a release"))
    );
    assert!(!fixture.runtime().join("generated").exists());
}

#[test]
fn refuses_a_shared_orbit_path_that_is_not_a_directory_and_leaves_it() {
    let fixture = Fixture::new();
    fs::write(&fixture.host.etc, "not a directory").unwrap();

    assert_eq!(
        converge(&fixture.input(Mode::Converge, false), &fixture.host),
        Err(invalid("/etc/orbit is a root-owned directory"))
    );
    assert_eq!(
        fs::read_to_string(&fixture.host.etc).unwrap(),
        "not a directory"
    );
}
