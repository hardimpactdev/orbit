use super::*;
use crate::commands::recipe::{Output, WorkDir};
use serde_json::json;
use std::{io::Write, time::Duration};
fn archive(path: &Path, link: Option<&str>, xz: bool) {
    let mut bytes = Vec::new();
    {
        let mut builder = tar::Builder::new(&mut bytes);
        let mut header = tar::Header::new_gnu();
        header.set_mode(0o755);
        header.set_size(4);
        header.set_cksum();
        builder
            .append_data(&mut header, "node/bin/node", &b"node"[..])
            .unwrap();
        if let Some(link) = link {
            let mut header = tar::Header::new_gnu();
            header.set_entry_type(tar::EntryType::Symlink);
            header.set_mode(0o777);
            header.set_size(0);
            header.set_link_name(link).unwrap();
            header.set_cksum();
            builder
                .append_data(&mut header, "node/bin/npm", io::empty())
                .unwrap();
        }
        builder.finish().unwrap();
    }
    if xz {
        let mut output = xz2::write::XzEncoder::new(fs::File::create(path).unwrap(), 1);
        output.write_all(&bytes).unwrap();
        output.finish().unwrap();
    } else {
        let mut output = flate2::write::GzEncoder::new(
            fs::File::create(path).unwrap(),
            flate2::Compression::fast(),
        );
        output.write_all(&bytes).unwrap();
        output.finish().unwrap();
    }
}
#[test]
fn extracts_xz_gzip_and_zip_without_running_an_installer() {
    let work = WorkDir::new().unwrap();
    for xz in [false, true] {
        let path = work.0.join(format!("runtime-{xz}"));
        archive(&path, Some("node"), xz);
        let target = work.0.join(format!("out-{xz}"));
        extract(&path, &target).unwrap();
        assert_eq!(fs::read(target.join("node/bin/node")).unwrap(), b"node");
        assert_eq!(
            fs::read_link(target.join("node/bin/npm")).unwrap(),
            Path::new("node")
        );
        assert_eq!(
            fs::metadata(target.join("node/bin/node")).unwrap().mode() & 0o777,
            0o755
        );
    }
    let path = work.0.join("bun.zip");
    let mut writer = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    writer
        .start_file(
            "bun-linux-x64/bun",
            zip::write::SimpleFileOptions::default().unix_permissions(0o755),
        )
        .unwrap();
    writer.write_all(b"bun").unwrap();
    writer.finish().unwrap();
    let target = work.0.join("zip-out");
    extract(&path, &target).unwrap();
    assert_eq!(fs::read(target.join("bun-linux-x64/bun")).unwrap(), b"bun");
}
#[test]
fn refuses_escaping_archive_links_and_zip_paths_before_extracting_files() {
    let work = WorkDir::new().unwrap();
    for (index, link) in ["/etc/passwd", "../../../outside"].into_iter().enumerate() {
        let path = work.0.join(format!("bad-{index}"));
        archive(&path, Some(link), false);
        let target = work.0.join(format!("out-{index}"));
        assert!(extract(&path, &target).is_err());
        assert_eq!(fs::read_dir(target).unwrap().count(), 0);
    }
    let path = work.0.join("bad.zip");
    let mut writer = zip::ZipWriter::new(fs::File::create(&path).unwrap());
    writer
        .start_file("../outside", zip::write::SimpleFileOptions::default())
        .unwrap();
    writer.write_all(b"bad").unwrap();
    writer.finish().unwrap();
    let target = work.0.join("zip-out");
    assert!(extract(&path, &target).is_err());
    assert_eq!(fs::read_dir(target).unwrap().count(), 0);
    assert!(!work.0.join("outside").exists());
}
struct Download {
    destination: PathBuf,
    responses: Vec<(i32, Vec<u8>)>,
    urls: Vec<String>,
}
impl Host for Download {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        self.urls.push(call.argv.last().unwrap().clone());
        assert!(call
            .argv
            .windows(2)
            .any(|p| p == ["--proto-redir", "=https"]));
        let (code, bytes) = self.responses.remove(0);
        fs::write(&self.destination, bytes).unwrap();
        Ok(Output {
            code,
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("no retries")
    }
}
#[test]
fn mirror_transport_errors_try_upstream_but_digest_corruption_stops() {
    let work = WorkDir::new().unwrap();
    let destination = work.0.join("git.deb");
    let digest = format!("{:x}", Sha256::digest(b"verified"));
    let input: Input = serde_json::from_value(serde_json::json!({"user":"orbit", "snapshot":"20261022T000000Z","architecture":"amd64","artifact_mirror":"https://mirror.test","tools":{"git":{"source":"artifact","version":"1:2.55","artifacts":{"amd64":{"url":"https://origin.test/git.deb","sha256":digest}}}}})).unwrap();
    let mut host = Download {
        destination: destination.clone(),
        responses: vec![(22, vec![]), (0, b"verified".to_vec())],
        urls: vec![],
    };
    download(&mut host, "git", &input.tools["git"], &input, &destination).unwrap();
    assert_eq!(
        host.urls,
        [
            format!("https://mirror.test/git_{digest}.deb"),
            "https://origin.test/git.deb".into()
        ]
    );
    let mut host = Download {
        destination: destination.clone(),
        responses: vec![(0, b"corrupt".to_vec())],
        urls: vec![],
    };
    assert!(
        download(&mut host, "git", &input.tools["git"], &input, &destination)
            .unwrap_err()
            .reason
            .contains("digest")
    );
    assert_eq!(host.urls.len(), 1);
}

#[test]
fn deb_metadata_requires_exact_name_and_epoch_version_and_accepts_architecture_independent_packages(
) {
    struct Metadata {
        output: Output,
        calls: Vec<Invocation>,
    }
    impl Host for Metadata {
        fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
            self.calls.push(call.clone());
            Ok(self.output.clone())
        }
        fn wait(&mut self, _: Duration) -> Result<(), Failure> {
            panic!("No retry")
        }
    }
    let input: Input = serde_json::from_value(
        json!({"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}}),
    )
    .unwrap();
    let pin: Tool =
        serde_json::from_value(json!({"source":"artifact","version":"2:1.0-1"})).unwrap();
    for (name, version, architecture, code, truncated, accepted) in [
        ("git", "2:1.0-1", "amd64", 0, false, true),
        ("git", "2:1.0-1", "all", 0, false, true),
        ("other", "2:1.0-1", "amd64", 0, false, false),
        ("git", "1.0-1", "amd64", 0, false, false),
        ("git", "2:1.0-1", "arm64", 0, false, false),
        ("git", "2:1.0-1", "amd64", 1, false, false),
        ("git", "2:1.0-1", "amd64", 0, true, false),
    ] {
        let mut host = Metadata {
            output: Output {
                code,
                truncated,
                stdout: format!(
                    "Package: {name}\nVersion: {version}\nArchitecture: {architecture}\n"
                ),
                ..Output::default()
            },
            calls: vec![],
        };
        assert_eq!(
            verify_deb(&mut host, Path::new("/candidate.deb"), "git", &pin, &input).is_ok(),
            accepted
        );
        assert_eq!(host.calls.len(), 1);
        assert_eq!(
            host.calls[0].argv,
            [
                "dpkg-deb",
                "-f",
                "/candidate.deb",
                "Package",
                "Version",
                "Architecture"
            ]
        );
    }
}
