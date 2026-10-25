use super::*;
use crate::commands::recipe::WorkDir;
use std::{
    fs,
    io::{BufRead, BufReader},
    path::Path,
    time::Instant,
};

fn git(path: &Path, args: &[&str], input: Option<&[u8]>) -> Vec<u8> {
    let mut child = Process::new("/usr/bin/git")
        .args([
            "-c",
            "user.name=Orbit test",
            "-c",
            "user.email=orbit@example.test",
            "-C",
        ])
        .arg(path)
        .args(args)
        .stdin(if input.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .unwrap();
    if let Some(input) = input {
        child.stdin.take().unwrap().write_all(input).unwrap();
    }
    let result = child.wait_with_output().unwrap();
    assert!(
        result.status.success(),
        "{}",
        String::from_utf8_lossy(&result.stderr)
    );
    result.stdout
}
fn commit(path: &Path, name: &str) -> String {
    fs::write(path.join(name), name).unwrap();
    git(path, &["add", name], None);
    git(path, &["commit", "--quiet", "-m", name], None);
    String::from_utf8(git(path, &["rev-parse", "HEAD"], None))
        .unwrap()
        .trim()
        .to_owned()
}
fn context() -> Context {
    Context::new(Platform::Linux, Instant::now() + Duration::from_secs(30))
}
fn decode(frames: &[u8]) -> Vec<u8> {
    let mut reader = BufReader::new(frames);
    let mut bytes = vec![];
    loop {
        let mut header = String::new();
        if reader.read_line(&mut header).unwrap() == 0 {
            break;
        }
        let size = serde_json::from_str::<serde_json::Value>(&header).unwrap()["stream_bytes"]
            .as_u64()
            .unwrap();
        let before = bytes.len();
        reader.by_ref().take(size).read_to_end(&mut bytes).unwrap();
        assert_eq!(bytes.len() - before, size as usize);
    }
    bytes
}

#[test]
fn exports_only_approved_history_as_an_indexable_thin_pack_and_repeats_without_changes() {
    check_export("sha1");
}

#[test]
fn exports_sha256_history_as_an_indexable_pack() {
    check_export("sha256");
}

fn check_export(format: &str) {
    let work = WorkDir::new().unwrap();
    let source = work.0.join("source");
    fs::create_dir(&source).unwrap();
    git(
        &source,
        &[
            "init",
            "--quiet",
            "-b",
            "main",
            &format!("--object-format={format}"),
        ],
        None,
    );
    let first = commit(&source, "first");
    let destination = work.0.join("mirror");
    git(
        &work.0,
        &["clone", "--quiet", "--bare", "source", "mirror"],
        None,
    );
    let approved = commit(&source, "approved");
    let later = commit(&source, "not-approved");
    let refs = git(&source, &["show-ref"], None);
    let index = fs::read(source.join(".git/index")).unwrap();
    let input = Input {
        checkout: source.clone(),
        commit: approved.clone(),
        haves: vec![first, "a".repeat(approved.len())],
    };
    for _ in 0..2 {
        let mut wire = vec![];
        let result = emit(&input, &context(), &mut wire, MAX_PACK_BYTES).unwrap();
        let pack = decode(&wire);
        assert_eq!(result.bytes, pack.len() as u64);
        assert!(pack.starts_with(b"PACK"));
        assert_eq!(u32::from_be_bytes(pack[8..12].try_into().unwrap()), 3);
        git(
            &destination,
            &["index-pack", "--stdin", "--fix-thin", "--strict"],
            Some(&pack),
        );
        git(&destination, &["cat-file", "-e", &approved], None);
    }
    let result = Process::new("/usr/bin/git")
        .arg("-C")
        .arg(&destination)
        .args(["cat-file", "-e", &later])
        .output()
        .unwrap();
    assert!(!result.status.success());
    assert_eq!(git(&source, &["show-ref"], None), refs);
    assert_eq!(fs::read(source.join(".git/index")).unwrap(), index);
}

#[test]
fn emits_empty_pack_when_gateway_already_has_approved_commit() {
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet"], None);
    let commit = commit(&work.0, "first");
    let input = Input {
        checkout: work.0.clone(),
        commit: commit.clone(),
        haves: vec![commit],
    };
    let mut wire = vec![];
    emit(&input, &context(), &mut wire, MAX_PACK_BYTES).unwrap();
    let pack = decode(&wire);
    assert_eq!(u32::from_be_bytes(pack[8..12].try_into().unwrap()), 0);
}

#[test]
fn validates_all_input_and_commit_presence_before_output() {
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet"], None);
    for (checkout, commit, haves) in [
        (PathBuf::from("relative"), "a".repeat(40), vec![]),
        (work.0.clone(), "HEAD".into(), vec![]),
        (work.0.clone(), "a".repeat(40), vec!["--all".into()]),
        (
            work.0.clone(),
            "a".repeat(40),
            vec!["a".repeat(40); MAX_HAVES + 1],
        ),
    ] {
        let mut output = vec![];
        assert!(matches!(
            emit(
                &Input {
                    checkout,
                    commit,
                    haves
                },
                &context(),
                &mut output,
                MAX_PACK_BYTES
            ),
            Err(Error::Invalid {})
        ));
        assert!(output.is_empty());
    }
    let mut output = vec![];
    assert!(matches!(
        emit(
            &Input {
                checkout: work.0.clone(),
                commit: "a".repeat(40),
                haves: vec![]
            },
            &context(),
            &mut output,
            MAX_PACK_BYTES
        ),
        Err(Error::MissingCommit {})
    ));
    assert!(output.is_empty());
    assert_eq!(fs::read_dir(&work.0).unwrap().count(), 1);
}

#[test]
fn rejects_oversized_packs_broken_sinks_and_expired_deadlines_without_repository_changes() {
    struct Broken;
    impl Write for Broken {
        fn write(&mut self, _: &[u8]) -> io::Result<usize> {
            Err(io::Error::new(io::ErrorKind::BrokenPipe, "gone"))
        }
        fn flush(&mut self) -> io::Result<()> {
            Ok(())
        }
    }
    let work = WorkDir::new().unwrap();
    git(&work.0, &["init", "--quiet"], None);
    let commit = commit(&work.0, "first");
    let input = Input {
        checkout: work.0.clone(),
        commit,
        haves: vec![],
    };
    let refs = git(&work.0, &["show-ref"], None);
    assert!(matches!(
        emit(&input, &context(), io::sink(), 10),
        Err(Error::TooLarge {})
    ));
    assert!(matches!(
        emit(&input, &context(), Broken, MAX_PACK_BYTES),
        Err(Error::Failed {})
    ));
    assert!(matches!(
        emit(
            &input,
            &Context::new(Platform::Linux, Instant::now()),
            io::sink(),
            MAX_PACK_BYTES
        ),
        Err(Error::Failed {})
    ));
    assert_eq!(git(&work.0, &["show-ref"], None), refs);
}
