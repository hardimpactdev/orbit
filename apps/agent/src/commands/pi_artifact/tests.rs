use super::*;
use crate::commands::recipe::WorkDir;
use sha2::{Digest, Sha256};
fn fixture() -> (WorkDir, Files, Account) {
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
    (work, files, account)
}
fn chunk(artifact: &[u8], offset: usize, end: usize) -> Chunk {
    Chunk {
        sha256: format!("{:x}", Sha256::digest(artifact)),
        offset: offset as u64,
        total: artifact.len() as u64,
        bytes: STANDARD.encode(&artifact[offset..end]),
    }
}
#[test]
fn interrupted_transfer_repeats_matching_chunks_and_installs_only_a_verified_complete_binary() {
    let (_work, files, account) = fixture();
    let bytes = b"verified Pi binary";
    let first = chunk(bytes, 0, 8);
    let result = receive(&files, &first).unwrap();
    assert_eq!(result.received_bytes, 8);
    assert!(!result.verified);
    assert!(install(&files, &account, &first.sha256).is_err());
    assert!(!account.home.join(BINARY).exists());
    assert_eq!(receive(&files, &first).unwrap().received_bytes, 8);
    // Resume after a partial write inside a chunk as well as after a lost complete reply.
    let partial = paths(&files, &first.sha256).unwrap().0;
    fs::OpenOptions::new()
        .write(true)
        .open(&partial)
        .unwrap()
        .set_len(5)
        .unwrap();
    assert_eq!(receive(&files, &first).unwrap().received_bytes, 8);
    assert!(
        receive(&files, &chunk(bytes, 8, bytes.len()))
            .unwrap()
            .verified
    );
    assert!(!partial.exists());
    assert!(receive(&files, &first).unwrap().verified);
    assert!(install(&files, &account, &first.sha256).unwrap().changed);
    assert!(!install(&files, &account, &first.sha256).unwrap().changed);
    let target = account.home.join(BINARY);
    assert_eq!(fs::read(&target).unwrap(), bytes);
    assert_eq!(fs::metadata(&target).unwrap().mode() & 0o777, 0o755);
    assert_eq!(fs::metadata(target).unwrap().uid(), account.uid);
}
#[test]
fn corrupt_completion_is_discarded_and_leaves_the_installed_binary_unchanged() {
    let (_work, files, account) = fixture();
    let old = b"previous Pi";
    let old_chunk = chunk(old, 0, old.len());
    receive(&files, &old_chunk).unwrap();
    install(&files, &account, &old_chunk.sha256).unwrap();
    let new = b"replacement Pi";
    let mut input = chunk(new, 0, new.len());
    input.bytes = STANDARD.encode(b"corrupt binary");
    assert!(matches!(
        receive(&files, &input),
        Err(Error::Invalid { .. })
    ));
    let (partial, ready) = paths(&files, &input.sha256).unwrap();
    assert!(!partial.exists());
    assert!(!ready.exists());
    assert_eq!(fs::read(account.home.join(BINARY)).unwrap(), old);
    input.bytes = STANDARD.encode(new);
    assert!(receive(&files, &input).unwrap().verified);
    install(&files, &account, &input.sha256).unwrap();
    assert_eq!(fs::read(account.home.join(BINARY)).unwrap(), new);
}
#[test]
fn conflicting_repeats_and_out_of_order_chunks_preserve_partial_bytes() {
    let (_work, files, _) = fixture();
    let bytes = b"first second";
    let mut input = chunk(bytes, 0, 5);
    receive(&files, &input).unwrap();
    input.bytes = STANDARD.encode(b"wrong");
    assert!(receive(&files, &input).is_err());
    assert!(receive(&files, &chunk(bytes, 6, bytes.len())).is_err());
    assert_eq!(
        fs::read(paths(&files, &input.sha256).unwrap().0).unwrap(),
        b"first"
    );
}
#[test]
fn invalid_data_refuses_before_creating_the_cache() {
    for case in 0..6 {
        let (_work, files, _) = fixture();
        let mut input = chunk(b"pi", 0, 2);
        match case {
            0 => input.sha256 = "../pi".into(),
            1 => input.bytes = "!!!".into(),
            2 => input.total = MAX_ARTIFACT + 1,
            3 => input.offset = u64::MAX,
            4 => input.bytes = STANDARD.encode(vec![0; MAX_CHUNK + 1]),
            _ => input.offset = 1,
        }
        assert!(matches!(
            receive(&files, &input),
            Err(Error::Invalid { .. })
        ));
        assert!(!files.path(CACHE).exists());
    }
}
#[test]
fn refuses_symlink_artifacts_and_install_destinations_without_writing_through_them() {
    let (_work, files, account) = fixture();
    let input = chunk(b"pi", 0, 2);
    let (partial, ready) = paths(&files, &input.sha256).unwrap();
    fs::create_dir_all(partial.parent().unwrap()).unwrap();
    let outside = files.path("/outside");
    std::os::unix::fs::symlink(&outside, &partial).unwrap();
    assert!(receive(&files, &input).is_err());
    assert!(!outside.exists());
    fs::remove_file(&partial).unwrap();
    receive(&files, &input).unwrap();
    fs::create_dir_all(account.home.join(".local/bin")).unwrap();
    std::os::unix::fs::symlink(&outside, account.home.join(BINARY)).unwrap();
    assert!(install(&files, &account, &input.sha256).is_err());
    assert!(!outside.exists());
    fs::write(&ready, "corrupt").unwrap();
    assert!(receive(&files, &input).is_err());
    assert!(install(&files, &account, &input.sha256).is_err());
}
