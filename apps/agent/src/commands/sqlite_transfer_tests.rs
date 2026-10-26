use super::*;
use crate::commands::recipe::WorkDir;
use std::{
    io::{BufRead, BufReader},
    os::unix::fs::{symlink, PermissionsExt},
    time::Instant,
};
struct Fixture {
    source: PathBuf,
    incoming: PathBuf,
    work: WorkDir,
    bytes: Vec<u8>,
    digest: String,
}
impl Fixture {
    fn new() -> Self {
        let operation = format!("{:064x}", rand::random::<u128>());
        let source = PathBuf::from(format!("/tmp/orbit-sqlite-{operation}.sqlite"));
        let incoming = PathBuf::from(format!(
            "/tmp/orbit-sqlite-{}-{:016x}",
            &operation[48..],
            rand::random::<u64>()
        ));
        for path in [&source, &incoming] {
            OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(path)
                .unwrap();
        }
        rusqlite::Connection::open(&source)
            .unwrap()
            .execute_batch("CREATE TABLE rows (value BLOB); INSERT INTO rows VALUES (X'00FF0A');")
            .unwrap();
        let bytes = fs::read(&source).unwrap();
        let digest = crate::command::run::hex(&Sha256::digest(&bytes));
        Self {
            source,
            incoming,
            work: WorkDir::new().unwrap(),
            bytes,
            digest,
        }
    }
    fn context(&self) -> Context {
        let path = self.work.0.join("body");
        fs::write(&path, &self.bytes).unwrap();
        Context::new(Platform::Linux, Instant::now() + Duration::from_secs(5))
            .with_binary_input(File::open(path).unwrap())
    }
    fn input(&self) -> ReceiveInput {
        ReceiveInput {
            path: self.incoming.clone(),
            base: self.work.0.clone(),
            bytes: self.bytes.len() as u64,
            sha256: self.digest.clone(),
        }
    }
    fn read(&self) -> ReadInput {
        ReadInput {
            path: self.source.clone(),
            bytes: self.bytes.len() as u64,
            sha256: self.digest.clone(),
        }
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.source);
        let _ = fs::remove_file(&self.incoming);
    }
}
fn decode(data: &[u8]) -> Vec<u8> {
    let mut reader = BufReader::new(data);
    let mut bytes = vec![];
    loop {
        let mut line = String::new();
        if reader.read_line(&mut line).unwrap() == 0 {
            break;
        }
        let size = serde_json::from_str::<serde_json::Value>(&line).unwrap()["stream_bytes"]
            .as_u64()
            .unwrap();
        reader.by_ref().take(size).read_to_end(&mut bytes).unwrap();
    }
    bytes
}
#[test]
fn streams_exact_bytes_and_repeated_upload_preserves_the_prepared_inode() {
    let f = Fixture::new();
    let inode = fs::metadata(&f.incoming).unwrap().ino();
    for _ in 0..2 {
        let mut output = vec![];
        let result = read(&f.read(), &f.context(), &mut output).unwrap();
        assert_eq!(decode(&output), f.bytes);
        assert_eq!(result.sha256, f.digest);
        receive(&f.input(), &f.context()).unwrap();
        assert_eq!(fs::read(&f.incoming).unwrap(), f.bytes);
        assert_eq!(fs::metadata(&f.incoming).unwrap().ino(), inode);
        assert_eq!(fs::metadata(&f.incoming).unwrap().mode() & 0o7777, 0o600);
    }
}
#[test]
fn receives_a_development_clone_only_into_its_hidden_checkout_file() {
    let f = Fixture::new();
    let path = f
        .work
        .0
        .join(format!(".orbit-sqlite-{:032x}", rand::random::<u128>()));
    let input = ReceiveInput {
        path: path.clone(),
        ..f.input()
    };
    for _ in 0..2 {
        receive(&input, &f.context()).unwrap();
    }
    assert_eq!(fs::read(&path).unwrap(), f.bytes);
    let input = ReceiveInput {
        path: f.work.0.join("database.sqlite"),
        ..input
    };
    assert!(matches!(
        receive(&input, &f.context()),
        Err(Error::Invalid {})
    ));
    assert!(!input.path.exists());
}
#[test]
fn rejects_bad_headers_sizes_and_digests_before_changing_prepared_files() {
    let mut f = Fixture::new();
    fs::write(&f.incoming, b"keep this pending state").unwrap();
    let mut input = f.input();
    input.sha256 = "0".repeat(64);
    assert!(matches!(
        receive(&input, &f.context()),
        Err(Error::Refused {})
    ));
    let mut input = f.input();
    input.bytes += 1;
    assert!(matches!(
        receive(&input, &f.context()),
        Err(Error::Refused {})
    ));
    f.bytes[0] = b'X';
    f.digest = crate::command::run::hex(&Sha256::digest(&f.bytes));
    assert!(matches!(
        receive(&f.input(), &f.context()),
        Err(Error::Refused {})
    ));
    assert_eq!(fs::read(&f.incoming).unwrap(), b"keep this pending state");
}
#[test]
fn refuses_links_public_files_and_unmanaged_sources_without_emitting_bytes() {
    let f = Fixture::new();
    let mut output = vec![];
    fs::set_permissions(&f.source, fs::Permissions::from_mode(0o644)).unwrap();
    assert!(matches!(
        read(&f.read(), &f.context(), &mut output),
        Err(Error::Refused {})
    ));
    fs::set_permissions(&f.source, fs::Permissions::from_mode(0o600)).unwrap();
    let real = f.work.0.join("real");
    fs::rename(&f.source, &real).unwrap();
    symlink(&real, &f.source).unwrap();
    assert!(matches!(
        read(&f.read(), &f.context(), &mut output),
        Err(Error::Refused {})
    ));
    assert!(output.is_empty());
    let mut input = f.read();
    input.path = real;
    assert!(matches!(
        read(&input, &f.context(), &mut output),
        Err(Error::Invalid {})
    ));
    fs::remove_file(&f.incoming).unwrap();
    symlink(&input.path, &f.incoming).unwrap();
    assert!(matches!(
        receive(&f.input(), &f.context()),
        Err(Error::Refused {})
    ));
    assert_eq!(fs::read(input.path).unwrap(), f.bytes);
}
#[test]
fn a_missing_prepared_file_is_not_recreated_and_an_expired_deadline_does_not_write() {
    let f = Fixture::new();
    fs::remove_file(&f.incoming).unwrap();
    assert!(matches!(
        receive(&f.input(), &f.context()),
        Err(Error::Refused {})
    ));
    assert!(!f.incoming.exists());
    let context = Context::new(Platform::Linux, Instant::now())
        .with_binary_input(File::open(&f.source).unwrap());
    assert!(matches!(
        receive(&f.input(), &context),
        Err(Error::Failed {})
    ));
}
#[test]
fn bounds_metadata_and_propagates_output_failure_without_binary_diagnostics() {
    let f = Fixture::new();
    let mut input = f.read();
    input.bytes = MAX_BYTES + 1;
    assert!(matches!(
        read(&input, &f.context(), Vec::new()),
        Err(Error::Invalid {})
    ));
    struct Broken;
    impl Write for Broken {
        fn write(&mut self, _: &[u8]) -> std::io::Result<usize> {
            Err(std::io::Error::other("private database"))
        }
        fn flush(&mut self) -> std::io::Result<()> {
            Ok(())
        }
    }
    assert!(matches!(
        read(&f.read(), &f.context(), Broken),
        Err(Error::Failed {})
    ));
}
