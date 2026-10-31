use super::*;
use crate::commands::recipe::WorkDir;

fn fixture() -> (WorkDir, Files, Account, Input) {
    let work = WorkDir::new().unwrap();
    let uid = rustix::process::getuid().as_raw();
    let gid = rustix::process::getgid().as_raw();
    let files = Files {
        root: work.0.clone(),
        uid,
        gid,
    };
    let account = Account {
        name: "orbit".into(),
        uid,
        gid,
        home: files.path("/home/orbit"),
    };
    fs::create_dir_all(&account.home).unwrap();
    let input = Input {
        user: "orbit".into(),
        origin: "http://10.44.0.3:8317".into(),
        model_key: "k".repeat(64),
        token: "t".repeat(64),
        models: vec!["provider/model".into()],
    };
    (work, files, account, input)
}

#[test]
fn publishes_private_files_and_repeats_without_replacing_unchanged_credentials() {
    let (_work, files, account, mut input) = fixture();
    let configured = configure(&files, &account, &input).unwrap();
    assert!(configured.changed);
    assert_eq!(configured.binary, account.home.join(".local/bin/pi-server"));
    assert_eq!(
        configured.token_file,
        account.home.join(".pi/agent/orbit-token")
    );
    let directory = account.home.join(".pi/agent");
    let models = directory.join("models.json");
    let token = directory.join("orbit-token");
    let expected = serde_json::json!({"providers": {"orbit-task-vm": {
        "baseUrl": "http://10.44.0.3:8317/v1", "api": "openai-responses", "apiKey": "k".repeat(64),
        "models": [{"id": "provider/model", "reasoning": true}]
    }}});
    assert_eq!(
        serde_json::from_slice::<serde_json::Value>(&fs::read(&models).unwrap()).unwrap(),
        expected
    );
    assert_eq!(fs::read_to_string(&token).unwrap(), "t".repeat(64));
    for (path, mode) in [
        (&directory, 0o700),
        (&account.home.join(".pi"), 0o700),
        (&models, 0o600),
        (&token, 0o600),
    ] {
        let meta = fs::metadata(path).unwrap();
        assert_eq!(
            (meta.mode() & 0o7777, meta.uid(), meta.gid()),
            (mode, account.uid, account.gid)
        );
    }
    let inode = fs::metadata(&models).unwrap().ino();
    assert!(!configure(&files, &account, &input).unwrap().changed);
    assert_eq!(fs::metadata(&models).unwrap().ino(), inode);
    input.token = "n".repeat(64);
    assert!(configure(&files, &account, &input).unwrap().changed);
    assert_eq!(fs::metadata(&models).unwrap().ino(), inode);
    assert_eq!(fs::read_to_string(token).unwrap(), input.token);
    assert_eq!(fs::read_dir(directory).unwrap().count(), 2);
}

#[test]
fn rejects_invalid_input_before_creating_any_path_and_never_returns_credentials() {
    for case in 0..9 {
        let (_work, files, account, mut input) = fixture();
        match case {
            0 => input.origin = "file:///tmp/secret".into(),
            1 => input.origin = "https://secret@example.test".into(),
            2 => input.origin = "http://example.test/v1".into(),
            3 => input.origin = "http://example.test?key=secret".into(),
            4 => input.model_key = "private-secret".into(),
            5 => input.token.push('\n'),
            6 => input.models.clear(),
            7 => input.models = vec!["model\ninjected".into()],
            _ => input.models = vec!["model".into(); 257],
        }
        let error = configure(&files, &account, &input).unwrap_err();
        assert!(matches!(error, Error::Invalid { .. }));
        let description = format!("{error:?}");
        assert!(!description.contains("private-secret"));
        assert!(!description.contains(&input.token));
        assert!(!account.home.join(".pi").exists());
    }
}

#[test]
fn refuses_links_and_wrong_file_types_before_replacing_either_secret() {
    for name in [
        ".pi",
        ".pi/agent",
        ".pi/agent/models.json",
        ".pi/agent/orbit-token",
    ] {
        let (_work, files, account, input) = fixture();
        let path = account.home.join(name);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        let outside = files.path("/outside");
        fs::write(&outside, "untouched").unwrap();
        std::os::unix::fs::symlink(&outside, &path).unwrap();
        assert!(matches!(
            configure(&files, &account, &input),
            Err(Error::Failed { .. })
        ));
        assert_eq!(fs::read_to_string(&outside).unwrap(), "untouched");
        assert!(fs::symlink_metadata(&path).unwrap().is_symlink());
    }
    let (_work, files, account, input) = fixture();
    fs::create_dir_all(account.home.join(".pi/agent/orbit-token")).unwrap();
    let models = account.home.join(".pi/agent/models.json");
    fs::write(&models, "keep this configuration").unwrap();
    assert!(matches!(
        configure(&files, &account, &input),
        Err(Error::Failed { .. })
    ));
    assert_eq!(
        fs::read_to_string(models).unwrap(),
        "keep this configuration"
    );
}

#[test]
fn repairs_private_modes_without_accepting_another_home_owner() {
    let (_work, files, mut account, input) = fixture();
    configure(&files, &account, &input).unwrap();
    for (path, mode) in [
        (".pi", 0o755),
        (".pi/agent", 0o755),
        (".pi/agent/orbit-token", 0o644),
    ] {
        fs::set_permissions(account.home.join(path), fs::Permissions::from_mode(mode)).unwrap();
    }
    assert!(configure(&files, &account, &input).unwrap().changed);
    assert!(!configure(&files, &account, &input).unwrap().changed);
    account.uid += 1;
    assert!(matches!(
        configure(&files, &account, &input),
        Err(Error::Failed { .. })
    ));
}

#[test]
fn reports_io_failure_without_writing_credentials() {
    let (_work, files, account, input) = fixture();
    fs::set_permissions(&account.home, fs::Permissions::from_mode(0o500)).unwrap();
    let result = configure(&files, &account, &input);
    fs::set_permissions(&account.home, fs::Permissions::from_mode(0o700)).unwrap();
    assert!(matches!(result, Err(Error::Failed { .. })));
    assert!(!account.home.join(".pi").exists());
}
