use super::*;
#[cfg(target_os = "linux")]
use std::time::Instant;
struct Fake {
    text: String,
    calls: Vec<(String, Vec<String>)>,
    fail: bool,
}
impl Fake {
    fn new(text: &str) -> Self {
        Self {
            text: text.into(),
            calls: vec![],
            fail: false,
        }
    }
}
impl Host for Fake {
    fn run(&mut self, binary: &str, args: &[&str]) -> Result<String> {
        self.calls.push((
            binary.into(),
            args.iter().map(|arg| arg.to_string()).collect(),
        ));
        if self.fail {
            Err(Error::Failed {})
        } else {
            Ok(self.text.clone())
        }
    }
}
#[test]
fn inventory_preserves_duplicates_and_isolates_unverifiable_packages() {
    let mut host=Fake::new("jq\tinstall ok installed\t1.7.1\njq\tinstall ok installed\t1.7.2\nheld\thold ok installed\t1.0\nbad\tinstall ok installed\t1.0\0secret\nUPPER\tinstall ok installed\t1.0\nx\tinstall ok installed\t1.0\n123\tinstall ok installed\t1.0\n\n");
    let expected = InventoryOutput {
        packages: vec![
            Package {
                name: "jq".into(),
                version: Some("1.7.1".into()),
            },
            Package {
                name: "jq".into(),
                version: Some("1.7.2".into()),
            },
            Package {
                name: "held".into(),
                version: None,
            },
            Package {
                name: "bad".into(),
                version: None,
            },
        ],
    };
    assert_eq!(inventory(&mut host), Ok(expected));
    assert_eq!(
        host.calls[0],
        (
            "/usr/bin/dpkg-query".into(),
            vec![
                "--show".into(),
                "--showformat=${Package}\\t${Status}\\t${Version}\\n".into()
            ]
        )
    );
    for status in [
        "unknown ok not-installed",
        "deinstall ok config-files",
        "deinstall ok not-installed",
        "purge ok config-files",
        "purge ok not-installed",
    ] {
        assert!(inventory(&mut Fake::new(&format!("jq\t{status}\t1.0\n")))
            .unwrap()
            .packages
            .is_empty());
    }
    assert!(inventory(&mut Fake::new("")).unwrap().packages.is_empty());
    for text in [
        "jq\tinstall ok installed\n",
        "jq\tinstall ok installed\t1\t2",
    ] {
        assert_eq!(inventory(&mut Fake::new(text)), Err(Error::Output {}));
    }
}
#[test]
#[cfg(target_os = "linux")]
fn native_runner_refuses_nonzero_truncated_and_expired_results() {
    let context = Context::new(Platform::current(), Instant::now() + Duration::from_secs(5));
    let mut host = Native(&context);
    assert_eq!(host.run("/usr/bin/false", &[]), Err(Error::Failed {}));
    assert_eq!(
        host.run("/usr/bin/head", &["-c", "1048577", "/dev/zero"]),
        Err(Error::Output {})
    );
    let expired = Context::new(Platform::current(), Instant::now());
    assert_eq!(
        Native(&expired).run("/usr/bin/true", &[]),
        Err(Error::Failed {})
    );
}
