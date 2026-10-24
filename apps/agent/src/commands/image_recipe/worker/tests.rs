use super::*;
use serde_json::json;
use std::os::unix::fs::PermissionsExt;
const BOOT: &str = "12345678-1234-1234-1234-123456789abc";
fn fixture() -> (WorkDir, Files, Start, Account) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    for path in [
        "/run/orbit-recipe-images",
        "/proc/sys/kernel/random",
        "/etc",
        "/usr/local/bin",
        "/home/orbit",
    ] {
        fs::create_dir_all(files.path(path)).unwrap();
    }
    fs::write(files.path("/proc/sys/kernel/random/boot_id"), BOOT).unwrap();
    fs::write(
        files.path("/etc/os-release"),
        "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    )
    .unwrap();
    fs::write(files.path("/usr/local/bin/orbit-agent"), "agent").unwrap();
    let identity = format!("sha256:{}", "a".repeat(64));
    let input = serde_json::from_value(json!({"key":{"identity":identity,"profile":"base","phase":"agent","execution":"test"},"boot_id":BOOT,
        "recipe":{"identity":identity,"agent_sha256":file_sha256(&files.path("/usr/local/bin/orbit-agent")).unwrap(),"pi_sha256":null,"php":null,"rust":{},
            "recipe":{"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}}}})).unwrap();
    let account = Account {
        name: "orbit".into(),
        uid,
        gid,
        home: files.path("/home/orbit"),
    };
    (work, files, input, account)
}
struct Fake {
    calls: Vec<Invocation>,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<recipe::Output, Failure> {
        self.calls.push(call.clone());
        let stdout = match call.argv[0].as_str() {
            "dpkg" => "amd64",
            "dpkg-query" => "",
            _ => panic!("Unexpected mutation: {:?}", call.argv),
        };
        Ok(recipe::Output {
            stdout: stdout.into(),
            ..recipe::Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No retry")
    }
}
impl php_build::Host for Fake {
    fn ping(&mut self, _: &Path, _: Instant) -> bool {
        panic!("No FPM")
    }
}
#[test]
fn service_reads_only_private_typed_input_bound_to_its_unit_and_boot() {
    let (_work, files, mut input, _) = fixture();
    let unit = input.key.unit();
    let path = input.key.path(&files);
    fs::write(&path, serde_json::to_vec(&input).unwrap()).unwrap();
    fs::set_permissions(&path, fs::Permissions::from_mode(0o600)).unwrap();
    assert_eq!(load(&files, &unit).unwrap().key.unit(), unit);
    assert!(load(&files, "../../etc/passwd").is_err());
    input.key.execution = "different".into();
    fs::write(&path, serde_json::to_vec(&input).unwrap()).unwrap();
    assert!(load(&files, &unit).is_err());
    input.key.execution = "test".into();
    input.boot_id = "00000000-0000-0000-0000-000000000000".into();
    fs::write(&path, serde_json::to_vec(&input).unwrap()).unwrap();
    assert!(load(&files, &unit).is_err());
    fs::remove_file(&path).unwrap();
    std::os::unix::fs::symlink(files.path("/outside"), &path).unwrap();
    assert!(load(&files, &unit).is_err());
}
#[test]
fn agent_phase_preserves_delivery_pin_and_repeats_without_installation() {
    let (_work, files, input, account) = fixture();
    let mut host = Fake { calls: vec![] };
    for _ in 0..2 {
        execute(
            &mut host,
            &files,
            &input,
            &account,
            Instant::now() + Duration::from_secs(1),
        )
        .unwrap();
    }
    assert_eq!(
        fs::read(files.path("/usr/local/bin/orbit-agent")).unwrap(),
        b"agent"
    );
    fs::write(files.path("/usr/local/bin/orbit-agent"), "changed delivery").unwrap();
    assert!(execute(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(1)
    )
    .is_err());
    assert!(host
        .calls
        .iter()
        .all(|call| ["dpkg", "dpkg-query"].contains(&call.argv[0].as_str())));
}
#[test]
fn unsupported_platform_refuses_before_phase_mutation_and_service_errors_are_nonzero() {
    let (_work, files, mut input, account) = fixture();
    input.key.phase = Phase::AptSource;
    fs::write(
        files.path("/etc/os-release"),
        "ID=debian\nVERSION_CODENAME=trixie\n",
    )
    .unwrap();
    let mut host = Fake { calls: vec![] };
    assert!(execute(
        &mut host,
        &files,
        &input,
        &account,
        Instant::now() + Duration::from_secs(1)
    )
    .is_err());
    assert!(host.calls.is_empty());
    assert!(!files.path(APT).exists());
    assert_ne!(service(&[]), 0);
    assert_ne!(service(&[input.key.unit()]), 0);
}
