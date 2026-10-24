use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::{symlink, PermissionsExt};

const GIT: &str = "Types: deb\nURIs: https://ppa.launchpadcontent.net/git-core/ppa/ubuntu/\nSuites: resolute\nComponents: main\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n fixture-key\n -----END PGP PUBLIC KEY BLOCK-----\n";
fn fixture() -> (WorkDir, Files) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.apt().join("sources.list.d")).unwrap();
    fs::create_dir_all(files.apt().join("preferences.d")).unwrap();
    (work, files)
}

#[test]
fn deb822_handles_inline_keys_and_names_each_malformed_field() {
    let source = Path::new("/etc/apt/sources.list.d/git.sources");
    let fields = deb822(GIT, source).unwrap();
    assert!(fields["signed-by"].contains("fixture-key"));
    for (value, reason) in [
        ("  stray\n", "continuation"),
        ("Types deb\n", "separator"),
        ("Types: deb\ntypes: deb-src", "duplicate"),
        ("bad key: value", "field name"),
    ] {
        let error = deb822(value, source).unwrap_err();
        assert_eq!(error.stage, "apt-source");
        assert!(error.reason.contains(source.to_str().unwrap()));
        assert!(error.reason.contains(reason), "{error:?}");
    }
}

#[test]
fn provider_retirement_keeps_unrelated_content_and_durable_backups_then_repeats() {
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list.d/git.sources");
    let unrelated = "Types: deb\nURIs: https://packages.example.test/ubuntu\nSuites: resolute\nComponents: main\n";
    let content = format!("{GIT}\n{unrelated}");
    fs::write(&source, &content).unwrap();
    let list = files.apt().join("sources.list");
    let list_content = "# Git notes\ndeb-src [signed-by=/key] http://ppa.launchpad.net/git-core/ppa/ubuntu resolute main\n";
    fs::write(&list, list_content).unwrap();
    let changes = provider_changes(&files, Provider::Git).unwrap();
    assert_eq!(changes.len(), 2);
    assert_eq!(fs::read_to_string(&source).unwrap(), content);
    assert!(!files.apt().join("git.sources.orbit-git-backup").exists());
    retire(&files, &changes, "git").unwrap();
    assert_eq!(
        fs::read_to_string(&source).unwrap(),
        format!("\n\n{unrelated}")
    );
    assert_eq!(fs::read_to_string(&list).unwrap(), "# Git notes\n");
    assert_eq!(
        fs::read_to_string(files.apt().join("git.sources.orbit-git-backup")).unwrap(),
        content
    );
    assert_eq!(
        fs::read_to_string(files.apt().join("sources.list.orbit-git-backup")).unwrap(),
        list_content
    );
    assert!(provider_changes(&files, Provider::Git).unwrap().is_empty());
    fs::write(&source, GIT.replace("fixture-key", "replacement-key")).unwrap();
    let error = provider_changes(&files, Provider::Git).unwrap_err();
    assert!(error.reason.contains("differs from the source"));
    assert!(fs::read_to_string(&source)
        .unwrap()
        .contains("replacement-key"));
}

#[test]
fn sources_refuse_mixed_fields_wrong_suites_and_conflicting_backups_before_any_retirement() {
    let (_work, files) = fixture();
    let first = files.apt().join("sources.list.d/a.sources");
    let second = files.apt().join("sources.list.d/b.sources");
    fs::write(&first, GIT).unwrap();
    for invalid in [
        GIT.replace("Suites: resolute", "Suites: noble"),
        GIT.replace("Components: main", "Components: main universe"),
        GIT.replace("Types: deb", "Types: deb rpm"),
        GIT.replace("/ubuntu/\n", "/ubuntu/ https://foreign.test/\n"),
        GIT.replace("Suites: resolute", "Suites: resolute\nSuites: resolute"),
    ] {
        fs::write(&second, &invalid).unwrap();
        assert!(provider_changes(&files, Provider::Git).is_err());
        assert_eq!(fs::read_to_string(&first).unwrap(), GIT);
        assert_eq!(fs::read_to_string(&second).unwrap(), invalid);
        assert!(!files.apt().join("a.sources.orbit-git-backup").exists());
    }
    fs::write(&second, GIT).unwrap();
    let changes = provider_changes(&files, Provider::Git).unwrap();
    fs::write(files.apt().join("b.sources.orbit-git-backup"), "other").unwrap();
    assert!(retire(&files, &changes, "git").is_err());
    assert_eq!(fs::read_to_string(&first).unwrap(), GIT);
    assert!(!files.apt().join("a.sources.orbit-git-backup").exists());
}

#[test]
fn symlinked_sources_and_backups_are_never_changed() {
    let (_work, files) = fixture();
    let target = files.apt().join("original");
    fs::write(&target, GIT).unwrap();
    let source = files.apt().join("sources.list.d/git.sources");
    symlink(&target, &source).unwrap();
    let error = provider_changes(&files, Provider::Git).unwrap_err();
    assert!(error.reason.contains("symlink"));
    fs::remove_file(&source).unwrap();
    fs::write(&source, GIT).unwrap();
    symlink(&target, files.apt().join("git.sources.orbit-git-backup")).unwrap();
    assert!(provider_changes(&files, Provider::Git)
        .unwrap_err()
        .reason
        .contains("symlink"));
    assert_eq!(fs::read_to_string(&target).unwrap(), GIT);
}

#[test]
fn single_source_files_are_removed_and_comments_are_preserved() {
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list.d/git.sources");
    fs::write(&source, GIT).unwrap();
    retire(
        &files,
        &provider_changes(&files, Provider::Git).unwrap(),
        "git",
    )
    .unwrap();
    assert!(!source.exists());
    fs::write(&source, format!("# {}", GIT.lines().nth(1).unwrap())).unwrap();
    assert!(provider_changes(&files, Provider::Git).unwrap().is_empty());
    fs::write(
        files.apt().join("sources.list"),
        "deb https://ppa.launchpadcontent.net/git-core/ppa/ubuntu",
    )
    .unwrap();
    assert!(provider_changes(&files, Provider::Git)
        .unwrap_err()
        .reason
        .contains("list entry"));
}

#[test]
fn each_provider_has_one_closed_source_shape() {
    for (provider, uri, suite, component) in [
        (
            Provider::Docker,
            "https://download.docker.com/linux/ubuntu",
            "noble",
            "stable",
        ),
        (
            Provider::Node,
            "https://deb.nodesource.com/node_24.x",
            "nodistro",
            "main",
        ),
        (
            Provider::Incus,
            "https://pkgs.zabbly.com/incus/lts-7.0",
            "resolute",
            "main",
        ),
    ] {
        let (_work, files) = fixture();
        let source = files.apt().join("sources.list");
        fs::write(&source, format!("deb {uri} {suite} {component}\n")).unwrap();
        retire(
            &files,
            &provider_changes(&files, provider).unwrap(),
            provider.owner(),
        )
        .unwrap();
        assert!(!source.exists());
        fs::write(
            &source,
            format!("deb {uri}/unexpected {suite} {component}\n"),
        )
        .unwrap();
        assert!(provider_changes(&files, provider).is_err());
    }
}

#[test]
fn only_exact_snapshot_sources_are_repairable() {
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list.d/ubuntu.sources");
    let original = snapshot_source("20261013T000000Z");
    fs::write(&source, &original).unwrap();
    assert_eq!(
        ubuntu_repair(&files).unwrap(),
        Some(original.as_bytes().to_vec())
    );
    for content in [
        format!("{original}# user edit\n"),
        original.replace("Check-Valid-Until: no\n", ""),
        rolling_source("arm64"),
    ] {
        fs::write(&source, &content).unwrap();
        assert_eq!(ubuntu_repair(&files).unwrap(), None);
        assert_eq!(fs::read_to_string(&source).unwrap(), content);
    }
    fs::remove_file(&source).unwrap();
    let target = files.apt().join("original");
    fs::write(&target, &original).unwrap();
    symlink(&target, &source).unwrap();
    assert_eq!(ubuntu_repair(&files).unwrap(), None);
    assert!(rolling_source("arm64").contains("ports.ubuntu.com/ubuntu-ports"));
    assert!(rolling_source("amd64").contains("security.ubuntu.com/ubuntu"));
}

#[test]
fn signed_source_evidence_is_scoped_to_uri_suite_component_and_keyring() {
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list.d/mirror.sources");
    let content = format!("Types: deb\nURIs: https://mirror.test/ubuntu/\nSuites: resolute resolute-security\nComponents: main universe\nSigned-By: {UBUNTU_KEYRING}\n");
    fs::write(&source, &content).unwrap();
    let trusted = signed_sources(&files, None).unwrap();
    assert_eq!(trusted.len(), 4);
    assert!(trusted.contains(&("https://mirror.test/ubuntu".into(), "resolute/main".into())));
    for content in [
        content.replace(UBUNTU_KEYRING, "/keyring/other.gpg"),
        format!("{content}Enabled: no\n"),
        content.replace("Types: deb", "Types: deb-src"),
    ] {
        fs::write(&source, content).unwrap();
        assert!(signed_sources(&files, None).unwrap().is_empty());
    }
    fs::write(files.apt().join("sources.list"), format!("deb [arch=amd64 signed-by={UBUNTU_KEYRING}] https://mirror.test/ubuntu resolute main # preserved\n")).unwrap();
    assert_eq!(signed_sources(&files, None).unwrap().len(), 1);
}

#[test]
fn receipt_requires_the_exact_owned_coherent_package_set() {
    let (_work, files) = fixture();
    let receipt = files.apt().join("orbit-git.json");
    let names = ["git".into(), "git-man".into()].into();
    files
        .publish_root(&receipt, br#"{"git":"1:2.55.0-1","git-man":"1:2.55.0-1"}"#)
        .unwrap();
    assert!(artifact_owned(&files, "git", "1:2.55.0-1", &names, "git"));
    assert!(!artifact_owned(&files, "git", "1:2.56.0-1", &names, "git"));
    for invalid in [
        r#"{"git":"1:2.55.0-1"}"#,
        r#"{"git":"1:2.55.0-1","git-man":"2"}"#,
        r#"{"git":"bad","git-man":"bad"}"#,
    ] {
        fs::write(&receipt, invalid).unwrap();
        assert!(!artifact_owned(&files, "git", "1:2.55.0-1", &names, "git"));
    }
    fs::write(&receipt, br#"{"git":"1:2.55.0-1","git-man":"1:2.55.0-1"}"#).unwrap();
    fs::set_permissions(&receipt, fs::Permissions::from_mode(0o666)).unwrap();
    assert!(!artifact_owned(&files, "git", "1:2.55.0-1", &names, "git"));
}

#[test]
fn atomic_publication_refuses_wrong_types_and_owners_without_changing_targets() {
    let (_work, files) = fixture();
    let original = files.apt().join("original");
    fs::write(&original, "original").unwrap();
    let link = files.apt().join("link");
    symlink(&original, &link).unwrap();
    let directory = files.apt().join("directory");
    fs::create_dir(&directory).unwrap();
    for (path, uid, allow, reason) in [
        (&link, files.uid, false, "symlink"),
        (&link, files.uid + 1, true, "UID"),
        (&directory, files.uid, false, "regular file"),
        (&original, files.uid + 1, false, "UID"),
    ] {
        let error = files
            .publish(path, b"replacement", 0o644, uid, files.gid, allow)
            .unwrap_err();
        assert!(error.reason.contains(reason));
        assert!(error.reason.contains(path.to_str().unwrap()));
    }
    assert_eq!(fs::read_to_string(&original).unwrap(), "original");
    assert!(link.is_symlink());
    for _ in 0..2 {
        files.publish_root(&original, b"replacement").unwrap();
    }
    assert_eq!(fs::read_to_string(&original).unwrap(), "replacement");
    assert!(!fs::read_dir(files.apt()).unwrap().any(|p| p
        .unwrap()
        .file_name()
        .to_string_lossy()
        .starts_with(".orbit-recipe-")));
}
