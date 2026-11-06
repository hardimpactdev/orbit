use super::*;
use crate::commands::recipe::WorkDir;
use std::os::unix::fs::symlink;

const NODE: &str = "Types: deb\nURIs: https://deb.nodesource.com/node_24.x/\nSuites: nodistro\nComponents: main\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n fixture-key\n -----END PGP PUBLIC KEY BLOCK-----\n";
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
    let source = Path::new("/etc/apt/sources.list.d/node.sources");
    let fields = deb822(NODE, source).unwrap();
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
    let source = files.apt().join("sources.list.d/node.sources");
    let unrelated = "Types: deb\nURIs: https://packages.example.test/ubuntu\nSuites: nodistro\nComponents: main\n";
    let content = format!("{NODE}\n{unrelated}");
    fs::write(&source, &content).unwrap();
    let list = files.apt().join("sources.list");
    let list_content = "# Node notes\ndeb-src [signed-by=/key] https://deb.nodesource.com/node_24.x nodistro main\n";
    fs::write(&list, list_content).unwrap();
    let changes = provider_changes(&files).unwrap();
    assert_eq!(changes.len(), 2);
    assert_eq!(fs::read_to_string(&source).unwrap(), content);
    assert!(!files.apt().join("node.sources.orbit-node-backup").exists());
    retire(&files, &changes).unwrap();
    assert_eq!(
        fs::read_to_string(&source).unwrap(),
        format!("\n\n{unrelated}")
    );
    assert_eq!(fs::read_to_string(&list).unwrap(), "# Node notes\n");
    assert_eq!(
        fs::read_to_string(files.apt().join("node.sources.orbit-node-backup")).unwrap(),
        content
    );
    assert_eq!(
        fs::read_to_string(files.apt().join("sources.list.orbit-node-backup")).unwrap(),
        list_content
    );
    assert!(provider_changes(&files).unwrap().is_empty());
    fs::write(&source, NODE.replace("fixture-key", "replacement-key")).unwrap();
    let error = provider_changes(&files).unwrap_err();
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
    fs::write(&first, NODE).unwrap();
    for invalid in [
        NODE.replace("Suites: nodistro", "Suites: noble"),
        NODE.replace("Components: main", "Components: main universe"),
        NODE.replace("Types: deb", "Types: deb rpm"),
        NODE.replace("node_24.x/\n", "node_24.x/ https://foreign.test/\n"),
        NODE.replace("Suites: nodistro", "Suites: nodistro\nSuites: nodistro"),
    ] {
        fs::write(&second, &invalid).unwrap();
        assert!(provider_changes(&files).is_err());
        assert_eq!(fs::read_to_string(&first).unwrap(), NODE);
        assert_eq!(fs::read_to_string(&second).unwrap(), invalid);
        assert!(!files.apt().join("a.sources.orbit-node-backup").exists());
    }
    fs::write(&second, NODE).unwrap();
    let changes = provider_changes(&files).unwrap();
    fs::write(files.apt().join("b.sources.orbit-node-backup"), "other").unwrap();
    assert!(retire(&files, &changes).is_err());
    assert_eq!(fs::read_to_string(&first).unwrap(), NODE);
    assert!(!files.apt().join("a.sources.orbit-node-backup").exists());
}

#[test]
fn symlinked_sources_and_backups_are_never_changed() {
    let (_work, files) = fixture();
    let target = files.apt().join("original");
    fs::write(&target, NODE).unwrap();
    let source = files.apt().join("sources.list.d/node.sources");
    symlink(&target, &source).unwrap();
    let error = provider_changes(&files).unwrap_err();
    assert!(error.reason.contains("symlink"));
    fs::remove_file(&source).unwrap();
    fs::write(&source, NODE).unwrap();
    symlink(&target, files.apt().join("node.sources.orbit-node-backup")).unwrap();
    assert!(provider_changes(&files)
        .unwrap_err()
        .reason
        .contains("symlink"));
    assert_eq!(fs::read_to_string(&target).unwrap(), NODE);
}

#[test]
fn single_source_files_are_removed_and_comments_are_preserved() {
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list.d/node.sources");
    fs::write(&source, NODE).unwrap();
    retire(&files, &provider_changes(&files).unwrap()).unwrap();
    assert!(!source.exists());
    fs::write(&source, format!("# {}", NODE.lines().nth(1).unwrap())).unwrap();
    assert!(provider_changes(&files).unwrap().is_empty());
    fs::write(
        files.apt().join("sources.list"),
        "deb https://deb.nodesource.com/node_24.x",
    )
    .unwrap();
    assert!(provider_changes(&files)
        .unwrap_err()
        .reason
        .contains("list entry"));
}

#[test]
fn the_node_provider_has_one_closed_source_shape() {
    let (uri, suite, component) = ("https://deb.nodesource.com/node_24.x", "nodistro", "main");
    let (_work, files) = fixture();
    let source = files.apt().join("sources.list");
    fs::write(&source, format!("deb {uri} {suite} {component}\n")).unwrap();
    retire(&files, &provider_changes(&files).unwrap()).unwrap();
    assert!(!source.exists());
    fs::write(
        &source,
        format!("deb {uri}/unexpected {suite} {component}\n"),
    )
    .unwrap();
    assert!(provider_changes(&files).is_err());
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
