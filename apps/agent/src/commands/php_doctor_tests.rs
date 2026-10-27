use super::super::tests::Fixture;
use super::*;
use std::os::unix::fs::symlink;
fn write(path: &Path, bytes: impl AsRef<[u8]>, mode: u32) {
    fs::write(path, bytes).unwrap();
    fs::set_permissions(path, fs::Permissions::from_mode(mode)).unwrap();
}
fn input(f: &Fixture) -> Input {
    let runtime = f.input(Mode::Converge, false);
    Input {
        user: f.user.clone(),
        home: f.home.clone(),
        root: "public".into(),
        application_suffix: String::new(),
        environment: "APP_KEY=private\n".into(),
        caddy_sites: vec!["example.test {\n    respond ok\n}\n".into()],
        association: true,
        runtime: Some(ExpectedRuntime {
            version: runtime.version,
            marker: runtime.marker,
            configuration: runtime.configuration,
            application: runtime.application,
            initial_application: runtime.initial_application,
        }),
    }
}
fn fixture() -> Fixture {
    let f = Fixture::new();
    converge(&f.input(Mode::Converge, false), &f.host).unwrap();
    write(&f.home.join(".env"), b"APP_KEY=private\n", 0o600);
    fs::write(
        f.base.join("Caddyfile"),
        b"example.test {\n    respond ok\n}\n",
    )
    .unwrap();
    let pid = fs::read_to_string(f.base.join("state/pid")).unwrap();
    let process = f.host.proc_root.join(&pid);
    fs::create_dir_all(process.join(format!("task/{pid}"))).unwrap();
    fs::create_dir_all(process.join("fd")).unwrap();
    fs::write(process.join("status"), b"Uid:\t0\t0\t0\t0\n").unwrap();
    fs::write(process.join(format!("task/{pid}/children")), b"").unwrap();
    fs::create_dir_all(f.host.proc_root.join("net")).unwrap();
    fs::write(
        f.host.proc_root.join("net/unix"),
        format!(
            "0: 2 0 00010000 0001 01 100 {}\n",
            f.host.sockets.join(format!("{}.sock", f.user)).display()
        ),
    )
    .unwrap();
    symlink("socket:[100]", process.join("fd/8")).unwrap();
    let runtime = Runtime::new(&f.host, &f.user, "8.5", "").unwrap();
    for (name, value) in [
        ("ActiveState", "active".into()),
        ("User", "".into()),
        ("MainPID", pid),
        (
            "ExecStart",
            format!(
                "{{ path={} ; argv[]={0} --nodaemonize --fpm-config {} ; }}",
                f.host.fpm("8.5").display(),
                runtime.generated.join("php-fpm.conf").display()
            ),
        ),
        (
            "Environment",
            format!(
                "PHP_INI_SCAN_DIR={}/fpm/conf.d:{}",
                f.host.php_conf.join("8.5").display(),
                runtime.generated.display()
            ),
        ),
    ] {
        fs::write(f.base.join("state").join(name), value).unwrap();
    }
    crate::test_support::executable(
        &f.host.systemctl,
        br#"#!/bin/sh
state=$(dirname "$0")/../state
case "$2" in
 --property=*) property=${2#--property=} ;;
 --property) property=$3 ;;
 *) exit 1 ;;
esac
cat "$state/$property"
"#,
    );
    f
}
fn observe(f: &Fixture) -> Output {
    inspect(input(f), &f.host, &f.base.join("Caddyfile")).unwrap()
}
fn pid(f: &Fixture) -> String {
    fs::read_to_string(f.base.join("state/MainPID")).unwrap()
}
#[test]
fn repeats_real_production_observations_without_returning_environment_or_changing_files() {
    let f = fixture();
    let first = observe(&f);
    assert_eq!(
        first,
        Output {
            home: true,
            selection: true,
            root: true,
            environment: true,
            php: Some(true),
            caddy: Some(true)
        }
    );
    assert_eq!(first, observe(&f));
    assert!(!serde_json::to_string(&first).unwrap().contains("private"));
    assert_eq!(
        fs::read_to_string(f.home.join(".env")).unwrap(),
        "APP_KEY=private\n"
    );
    for forbidden in [
        "chroot = /jail",
        "listen = /tmp/socket",
        "include = /tmp/other",
        "[another-pool]",
    ] {
        write(
            &f.host.etc.join(format!("php-fpm/{}/local.conf", f.user)),
            forbidden,
            0o644,
        );
        assert_eq!(observe(&f).php, Some(false), "{forbidden}");
    }
}
#[test]
fn checks_selected_release_roots_nested_environment_links_and_exact_content() {
    let f = fixture();
    symlink("releases/initial", f.home.join("current")).unwrap();
    symlink("../../.env", f.home.join("releases/initial/.env")).unwrap();
    let observed = observe(&f);
    assert!(observed.selection && observed.root && observed.environment);
    assert_eq!(observed.php, Some(false)); // Initial pool is only accepted before selection.
    fs::create_dir_all(f.home.join("releases/initial/server/web/public")).unwrap();
    symlink(
        "../../../../.env",
        f.home.join("releases/initial/server/web/.env"),
    )
    .unwrap();
    let mut data = input(&f);
    data.root = "server/web/public".into();
    data.application_suffix = "/server/web".into();
    assert!(
        inspect(data, &f.host, &f.base.join("Caddyfile"))
            .unwrap()
            .environment
    );
    fs::remove_file(f.home.join("releases/initial/server/web/.env")).unwrap();
    fs::write(
        f.home.join("releases/initial/server/web/.env"),
        "APP_KEY=private\n",
    )
    .unwrap();
    let mut data = input(&f);
    data.root = "server/web/public".into();
    data.application_suffix = "/server/web".into();
    assert!(
        !inspect(data, &f.host, &f.base.join("Caddyfile"))
            .unwrap()
            .environment
    );
    fs::remove_file(f.home.join("current")).unwrap();
    symlink("../outside", f.home.join("current")).unwrap();
    let observed = observe(&f);
    assert!(!observed.selection && !observed.root && !observed.environment);
    fs::remove_file(f.home.join("current")).unwrap();
    write(&f.home.join(".env"), b"APP_KEY=other\n", 0o600);
    assert!(!observe(&f).environment);
    write(&f.home.join(".env"), b"APP_KEY=private\n", 0o644);
    assert!(!observe(&f).environment);
}
#[test]
fn finds_exact_caddy_sites_without_following_fragment_imports() {
    let f = fixture();
    for (content, expected) in [
        ("example.test {\n    respond ok\n}\nelse.test {}\n", true),
        ("example.test {\n    respond edited\n}\n", false),
        ("import fragments/*.caddy\n", false),
    ] {
        fs::write(f.base.join("Caddyfile"), content).unwrap();
        assert_eq!(observe(&f).caddy, Some(expected));
    }
    fs::remove_file(f.base.join("Caddyfile")).unwrap();
    assert_eq!(observe(&f).caddy, Some(false));
}
#[test]
fn keeps_failed_service_observations_distinct_from_drift() {
    let f = fixture();
    for (property, value, expected) in [
        ("ActiveState", "inactive", Some(false)),
        ("ActiveState", "unexpected", None),
        ("User", "not-root", Some(false)),
        ("MainPID", "invalid", None),
        ("MainPID", "1", None),
        (
            "ExecStart",
            "{ path=/other ; argv[]=/other ; }",
            Some(false),
        ),
        ("ExecStart", "invalid", None),
        ("Environment", "PHP_INI_SCAN_DIR=/other", Some(false)),
    ] {
        let path = f.base.join("state").join(property);
        let old = fs::read(&path).unwrap();
        fs::write(&path, value).unwrap();
        assert_eq!(observe(&f).php, expected, "{property}={value}");
        fs::write(path, old).unwrap();
    }
    fs::remove_file(f.base.join("state/ExecStart")).unwrap();
    assert_eq!(observe(&f).php, None);
}
#[test]
fn requires_the_expected_root_master_and_available_process_evidence() {
    let f = fixture();
    let process = f.host.proc_root.join(pid(&f));
    fs::write(process.join("status"), "Uid:\t1\t1\t1\t1\n").unwrap();
    assert_eq!(observe(&f).php, Some(false));
    fs::remove_file(process.join("status")).unwrap();
    assert_eq!(observe(&f).php, None);
    fs::write(process.join("status"), "Uid:\t0\t0\t0\t0\n").unwrap();
    fs::remove_file(process.join("exe")).unwrap();
    symlink("/usr/bin/false", process.join("exe")).unwrap();
    assert_eq!(observe(&f).php, Some(false));
}
fn worker(
    f: &Fixture,
    child: u32,
    identity: (u32, u32),
    parent: &str,
    state: &str,
    flags: u64,
    root: &Path,
) {
    let (uid, gid) = identity;
    let process = f.host.proc_root.join(child.to_string());
    fs::create_dir_all(&process).unwrap();
    fs::write(process.join("status"), format!("State:\t{state}\nPPid:\t{parent}\nUid:\t{uid}\t{uid}\t{uid}\t{uid}\nGid:\t{gid}\t{gid}\t{gid}\t{gid}\n")).unwrap();
    let mut fields = vec!["1".to_string(); 20];
    fields[0] = state.into();
    fields[6] = flags.to_string();
    fields[19] = "9001".into();
    fs::write(
        process.join("stat"),
        format!("{child} (php-fpm) {}\n", fields.join(" ")),
    )
    .unwrap();
    let _ = fs::remove_file(process.join("root"));
    symlink(root, process.join("root")).unwrap();
}
#[test]
fn checks_live_workers_and_skips_only_workers_that_have_exited() {
    let f = fixture();
    let master = pid(&f);
    let account = identity::passwd(&f.user).unwrap();
    let children = f
        .host
        .proc_root
        .join(format!("{master}/task/{master}/children"));
    fs::write(&children, "700 ").unwrap();
    assert_eq!(observe(&f).php, Some(true));
    for (uid, gid, parent, state, flags, root, expected) in [
        (
            account.uid,
            account.gid,
            master.as_str(),
            "S",
            0,
            Path::new("/"),
            Some(true),
        ),
        (
            account.uid + 1,
            account.gid,
            master.as_str(),
            "S",
            0,
            Path::new("/"),
            Some(false),
        ),
        (
            account.uid,
            account.gid + 1,
            master.as_str(),
            "S",
            0,
            Path::new("/"),
            Some(false),
        ),
        (
            account.uid,
            account.gid,
            "999",
            "S",
            0,
            Path::new("/"),
            None,
        ),
        (
            account.uid,
            account.gid,
            master.as_str(),
            "S",
            0,
            f.base.as_path(),
            Some(false),
        ),
        (
            account.uid + 1,
            account.gid,
            master.as_str(),
            "Z",
            0,
            Path::new("/"),
            Some(true),
        ),
        (
            account.uid + 1,
            account.gid,
            master.as_str(),
            "S",
            4,
            Path::new("/"),
            Some(true),
        ),
    ] {
        worker(&f, 700, (uid, gid), parent, state, flags, root);
        assert_eq!(
            observe(&f).php,
            expected,
            "{uid}/{gid}/{parent}/{state}/{flags}"
        );
    }
    worker(
        &f,
        700,
        (account.uid, account.gid),
        &master,
        "S",
        0,
        Path::new("/"),
    );
    fs::remove_file(f.host.proc_root.join("700/status")).unwrap();
    assert_eq!(observe(&f).php, None);
    fs::remove_dir_all(f.host.proc_root.join("700")).unwrap();
    fs::write(&children, "700 701\n").unwrap();
    worker(
        &f,
        701,
        (account.uid + 1, account.gid),
        &master,
        "S",
        0,
        Path::new("/"),
    );
    assert_eq!(observe(&f).php, Some(false));
}
#[test]
fn identifies_only_the_master_listening_socket_and_ignores_closed_descriptors() {
    let f = fixture();
    let master = pid(&f);
    let table = f.host.proc_root.join("net/unix");
    let original = fs::read_to_string(&table).unwrap();
    fs::write(
        &table,
        format!(
            "{original}{}",
            original.replace("00010000 0001 01 100", "00000000 0001 03 101")
        ),
    )
    .unwrap();
    assert_eq!(observe(&f).php, Some(true));
    fs::write(f.host.proc_root.join(format!("{master}/fd/7")), "").unwrap();
    assert_eq!(observe(&f).php, Some(true));
    fs::write(&table, format!("{original}{original}")).unwrap();
    assert_eq!(observe(&f).php, None);
    fs::write(&table, &original).unwrap();
    let fd = f.host.proc_root.join(format!("{master}/fd/8"));
    fs::remove_file(&fd).unwrap();
    symlink("socket:[999]", &fd).unwrap();
    assert_eq!(observe(&f).php, Some(false));
    fs::remove_file(&table).unwrap();
    assert_eq!(observe(&f).php, None);
}
#[test]
fn reuses_generation_receipts_and_never_probes_a_non_php_runtime() {
    let f = fixture();
    let runtime = Runtime::new(&f.host, &f.user, "8.5", "").unwrap();
    fs::write(runtime.receipt(PENDING), "pending").unwrap();
    assert_eq!(observe(&f).php, Some(false));
    fs::remove_file(runtime.receipt(PENDING)).unwrap();
    assert_eq!(observe(&f).php, Some(true));
    fs::remove_file(runtime.receipt(APPLIED)).unwrap();
    assert_eq!(observe(&f).php, Some(false));
    fs::remove_file(&f.host.systemctl).unwrap();
    let mut data = input(&f);
    data.runtime = None;
    assert_eq!(
        inspect(data, &f.host, &f.base.join("Caddyfile"))
            .unwrap()
            .php,
        Some(true)
    );
    let mut data = input(&f);
    data.runtime = None;
    data.association = false;
    assert_eq!(
        inspect(data, &f.host, &f.base.join("Caddyfile"))
            .unwrap()
            .php,
        Some(false)
    );
    let mut data = input(&f);
    data.user = "../root".into();
    assert!(inspect(data, &f.host, &f.base.join("Caddyfile")).is_err());
    assert!(serde_json::from_value::<Input>(serde_json::json!({"script":"secret"})).is_err());
}

#[test]
fn makes_changing_process_identity_unavailable_and_accepts_confirmed_restart_generations() {
    let f = fixture();
    let master = pid(&f).parse::<u32>().unwrap();
    let stat = f.host.proc_root.join(format!("{master}/stat"));
    let old = fs::read_to_string(&stat).unwrap();
    assert_eq!(
        stable_process(&f.host, master, || {
            let (prefix, _) = old.trim_end().rsplit_once(' ').unwrap();
            fs::write(&stat, format!("{prefix} 99999\n")).unwrap();
            Ok(true)
        }),
        Err(())
    );
    assert_eq!(observe(&f).php, Some(true));
    fs::write(&stat, &old).unwrap();
    let runtime = Runtime::new(&f.host, &f.user, "8.5", "").unwrap();
    let configuration = f.input(Mode::Converge, false);
    let pool = select_application_pool(&configuration).unwrap();
    write(
        &runtime.receipt(APPLIED),
        format!(
            "{}\nother-invalid-boot\n{master}\n0\n",
            pool_generation(&pool)
        ),
        0o600,
    );
    assert_eq!(observe(&f).php, Some(false));
    confirm_generation(&f.host, &runtime, &pool).unwrap();
    let original = fs::read(&f.host.systemctl).unwrap();
    crate::test_support::executable(&f.host.systemctl, br#"#!/bin/sh
state=$(dirname "$0")/../state
property=${2#--property=}
if [ "$property" = MainPID ]; then
 if [ -e "$state/observed-pid" ]; then printf 999; else touch "$state/observed-pid"; cat "$state/MainPID"; fi
else
 cat "$state/$property"
fi
"#);
    assert_eq!(observe(&f).php, None);
    crate::test_support::executable(&f.host.systemctl, original);
    fs::remove_dir_all(f.host.proc_root.join(master.to_string())).unwrap();
    assert_eq!(
        socket_matches(&f.host, master, &runtime.socket, identity::effective_uid()),
        Err(())
    );
}

#[test]
fn accepts_the_exact_selected_application_pool_and_refuses_old_home_working_directory() {
    let f = fixture();
    symlink("releases/initial", f.home.join("current")).unwrap();
    let runtime = Runtime::new(&f.host, &f.user, "8.5", "").unwrap();
    let configuration = f.input(Mode::Converge, false);
    let pool = select_application_pool(&configuration).unwrap();
    write(&runtime.generated.join("pool.conf"), &pool, 0o644);
    confirm_generation(&f.host, &runtime, &pool).unwrap();
    assert_eq!(observe(&f).php, Some(true));
    let old = String::from_utf8(pool).unwrap().replace(
        &f.home.join("current/public").display().to_string(),
        &f.home.display().to_string(),
    );
    write(&runtime.generated.join("pool.conf"), old, 0o644);
    assert_eq!(observe(&f).php, Some(false));
}
