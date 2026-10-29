use super::*;
use crate::commands::recipe::Output;
use sha2::{Digest, Sha256};
use std::os::unix::fs::PermissionsExt;
use std::{
    io::{Cursor, Write},
    os::unix::fs::symlink,
    time::Duration,
};

struct Fake {
    calls: Vec<Vec<String>>,
    fail_load: bool,
}
impl Host for Fake {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        assert_eq!(call.argv[0], PARSER);
        self.calls.push(call.argv.clone());
        let failed = self.fail_load && call.argv[1] == "--replace";
        if failed {
            self.fail_load = false;
        }
        Ok(Output {
            code: if failed { 1 } else { 0 },
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No waits")
    }
}
fn fixture() -> (WorkDir, Files, Input, PathBuf, Fake) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::geteuid().as_raw(),
        gid: rustix::process::getegid().as_raw(),
    };
    fs::create_dir_all(files.path("/etc/apparmor.d")).unwrap();
    let mut writer = zip::ZipWriter::new(Cursor::new(Vec::new()));
    for (name, bytes, mode) in [
        (EXECUTABLE, b"verified-browser".as_slice(), 0o755),
        (
            "chrome-headless-shell-linux64/resources.pak",
            b"verified-resources".as_slice(),
            0o644,
        ),
    ] {
        writer
            .start_file(
                name,
                zip::write::SimpleFileOptions::default().unix_permissions(mode),
            )
            .unwrap();
        writer.write_all(bytes).unwrap();
    }
    let bytes = writer.finish().unwrap().into_inner();
    let archive = work.0.join("download.zip");
    fs::write(&archive, &bytes).unwrap();
    let digest = format!("{:x}", Sha256::digest(&bytes));
    let input: Input = serde_json::from_value(serde_json::json!({"user":"orbit","snapshot":"20261028T000000Z","architecture":"amd64","tools":{NAME:{"source":"artifact","version":"150.0.7871.24","artifacts":{"amd64":{"url":"https://storage.googleapis.com/chrome-for-testing-public/150.0.7871.24/linux64/chrome-headless-shell-linux64.zip","sha256":digest}}}}})).unwrap();
    (
        work,
        files,
        input,
        archive,
        Fake {
            calls: vec![],
            fail_load: false,
        },
    )
}

#[test]
fn installs_verified_immutable_runtime_and_loads_only_its_exact_policy() {
    let (_work, files, input, archive, mut host) = fixture();
    install(&mut host, &files, &input, Some(&archive)).unwrap();
    let pin = &input.tools[NAME];
    let root = directory(&files, pin);
    assert_eq!(
        fs::read(root.join(EXECUTABLE)).unwrap(),
        b"verified-browser"
    );
    assert_eq!(
        fs::read(root.join("chrome-headless-shell-linux64/resources.pak")).unwrap(),
        b"verified-resources"
    );
    for path in [&root, &root.join(EXECUTABLE), &files.path(POLICY)] {
        let m = fs::symlink_metadata(path).unwrap();
        assert_eq!(m.uid(), files.uid);
        assert_eq!(m.mode() & 0o022, 0);
    }
    let text = fs::read_to_string(files.path(POLICY)).unwrap();
    assert!(text.contains("profile orbit-chrome-headless-shell /opt/orbit/chrome-headless-shell/150.0.7871.24/chrome-headless-shell-linux64/chrome-headless-shell flags=(unconfined)"));
    assert!(text.contains("userns,"));
    assert!(!text.contains('*'));
    assert!(!text.contains("/home/"));
    assert_eq!(host.calls[0][1], "--skip-kernel-load");
    assert_eq!(host.calls[1][1], "--replace");
    assert_eq!(installed(&files, pin).unwrap(), Some(pin.version.clone()));
    install(&mut host, &files, &input, None).unwrap(); // Convergence reloads an unloaded policy without downloading again.
    assert_eq!(host.calls.len(), 4);
    fs::remove_file(files.path(POLICY)).unwrap();
    assert_eq!(installed(&files, pin).unwrap(), None);
    install(&mut host, &files, &input, None).unwrap();
    assert!(installed(&files, pin).unwrap().is_some());
}

#[test]
fn rejects_corrupt_downloads_and_never_publishes_a_policy_for_them() {
    let (_work, files, input, archive, mut host) = fixture();
    fs::write(&archive, b"corrupt artifact").unwrap();
    assert!(install(&mut host, &files, &input, Some(&archive)).is_err());
    assert!(!files.path(POLICY).exists());
    assert!(!directory(&files, &input.tools[NAME]).exists());
    assert!(host.calls.is_empty());
}

#[test]
fn refuses_symlinked_or_writable_ancestors_before_granting_userns() {
    for symlinked in [false, true] {
        let (_work, files, input, archive, mut host) = fixture();
        let opt = files.path("/opt");
        if symlinked {
            let elsewhere = files.path("/elsewhere");
            fs::create_dir(&elsewhere).unwrap();
            symlink(elsewhere, &opt).unwrap();
        } else {
            fs::create_dir(&opt).unwrap();
            fs::set_permissions(&opt, fs::Permissions::from_mode(0o777)).unwrap();
        }
        assert!(install(&mut host, &files, &input, Some(&archive)).is_err());
        assert!(!files.path(POLICY).exists());
        assert!(host.calls.is_empty());
    }
}

#[test]
fn refuses_changed_executables_writable_resources_and_unrelated_policies() {
    for change in [
        "executable",
        "resource",
        "resource-link",
        "policy",
        "policy-link",
    ] {
        let (_work, files, input, archive, mut host) = fixture();
        install(&mut host, &files, &input, Some(&archive)).unwrap();
        host.calls.clear();
        let root = directory(&files, &input.tools[NAME]);
        match change {
            "executable" => fs::write(root.join(EXECUTABLE), b"replaced").unwrap(),
            "resource" => fs::set_permissions(
                root.join("chrome-headless-shell-linux64/resources.pak"),
                fs::Permissions::from_mode(0o666),
            )
            .unwrap(),
            "resource-link" => {
                let resource = root.join("chrome-headless-shell-linux64/resources.pak");
                fs::remove_file(&resource).unwrap();
                symlink(files.path("/application-owned-resource"), resource).unwrap();
            }
            "policy" => fs::write(files.path(POLICY), "operator policy").unwrap(),
            _ => {
                fs::remove_file(files.path(POLICY)).unwrap();
                symlink(files.path("/operator-policy"), files.path(POLICY)).unwrap();
            }
        }
        assert!(
            install(&mut host, &files, &input, Some(&archive)).is_err(),
            "{change}"
        );
        assert!(host.calls.is_empty());
    }
}

#[test]
fn refuses_foreign_ownership_and_cleans_up_an_initial_failed_policy_load() {
    let (_work, mut files, input, archive, mut host) = fixture();
    let owner = files.uid;
    files.uid = owner + 1;
    assert!(install(&mut host, &files, &input, Some(&archive)).is_err());
    assert!(host.calls.is_empty());
    files.uid = owner;
    host.fail_load = true;
    assert!(install(&mut host, &files, &input, Some(&archive)).is_err());
    assert!(!files.path(POLICY).exists());
    assert_eq!(host.calls.last().unwrap()[1], "--remove");
    install(&mut host, &files, &input, None).unwrap();
    assert!(installed(&files, &input.tools[NAME]).unwrap().is_some());
}

#[test]
fn restores_the_previous_policy_when_kernel_loading_fails() {
    let (_work, files, input, archive, mut host) = fixture();
    let old = policy("149.0.1.2");
    fs::write(files.path(POLICY), &old).unwrap();
    // Fail only the first kernel load, after successful syntax validation.
    struct FailOnce(Fake);
    impl Host for FailOnce {
        fn execute(&mut self, c: &Invocation) -> Result<Output, Failure> {
            if c.argv[1] == "--replace" && self.0.calls.len() == 1 {
                self.0.fail_load = true;
            }
            self.0.execute(c)
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No waits")
        }
    }
    let mut failing = FailOnce(host);
    assert!(install(&mut failing, &files, &input, Some(&archive)).is_err());
    assert_eq!(fs::read_to_string(files.path(POLICY)).unwrap(), old);
    assert_eq!(installed(&files, &input.tools[NAME]).unwrap(), None);
    host = failing.0;
    install(&mut host, &files, &input, None).unwrap();
    assert!(installed(&files, &input.tools[NAME]).unwrap().is_some());
}

#[test]
fn refuses_unsupported_architecture_or_unofficial_browser_before_installing() {
    for mutation in ["arm64", "url", "version"] {
        let (_work, files, mut input, archive, mut host) = fixture();
        match mutation {
            "arm64" => {
                let mut raw = serde_json::to_value(&input).unwrap();
                raw["architecture"] = serde_json::json!("arm64");
                assert!(serde_json::from_value::<Input>(raw).is_err());
                assert!(!valid(&input.tools[NAME], "arm64"));
                assert!(!files.path(POLICY).exists());
                assert!(host.calls.is_empty());
                continue;
            }
            "url" => {
                input
                    .tools
                    .get_mut(NAME)
                    .unwrap()
                    .artifacts
                    .get_mut("amd64")
                    .unwrap()
                    .url = Some("https://other.test/chrome.zip".into())
            }
            _ => input.tools.get_mut(NAME).unwrap().version = "150.0.1.2/../../escape".into(),
        }
        assert!(input.validate().is_err());
        assert!(install(&mut host, &files, &input, Some(&archive)).is_err());
        assert!(!files.path(POLICY).exists());
        assert!(host.calls.is_empty());
    }
}
