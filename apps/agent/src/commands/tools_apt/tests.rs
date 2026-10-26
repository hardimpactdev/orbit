use super::*;
use serde_json::json;
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
fn package_validation_refuses_before_processes_or_mutation() {
    for name in [
        "jq",
        "libc6",
        "g++",
        "libfoo-dev",
        "python3.13",
        &"a".repeat(128),
    ] {
        assert!(valid_package(name));
    }
    for name in [
        "",
        "a",
        "123",
        "Jq",
        "jq:amd64",
        "-jq",
        "jq=1.0",
        "jq*",
        "jq\nrm",
        "../jq",
        "https://example.test/jq",
        &"a".repeat(129),
    ] {
        let mut host = Fake::new("");
        assert_eq!(candidate(&mut host, name), Err(Error::Invalid {}));
        assert_eq!(plan(&mut host, name), Err(Error::Invalid {}));
        for remove in [true, false] {
            assert_eq!(mutate(&mut host, name, remove), Err(Error::Invalid {}));
        }
        assert!(host.calls.is_empty());
    }
    assert!(serde_json::from_value::<Input>(json!({"package":"jq","argv":[]})).is_err());
    assert!(serde_json::from_value::<Empty>(json!({"user":"root"})).is_err());
}
#[test]
fn versions_preserve_raw_debian_text_and_refuse_ambiguous_candidates() {
    let mut host = Fake::new("apt 3.2.0 (amd64)\nSupported modules:\n");
    assert_eq!(version(&mut host).unwrap().version, "apt 3.2.0 (amd64)");
    assert_eq!(
        host.calls,
        [("/usr/bin/apt-get".into(), vec!["--version".into()])]
    );
    let mut host = Fake::new(
        "jq:\n  Installed: 1.0\n  Candidate: 1:1.7.1-3ubuntu0.26.04.1\n  Version table:\n",
    );
    assert_eq!(
        candidate(&mut host, "jq").unwrap().version.as_deref(),
        Some("1:1.7.1-3ubuntu0.26.04.1")
    );
    assert_eq!(
        host.calls,
        [(
            "/usr/bin/apt-cache".into(),
            vec!["policy".into(), "--".into(), "jq".into()]
        )]
    );
    assert_eq!(
        candidate(&mut Fake::new("  Candidate: (none)\n"), "jq")
            .unwrap()
            .version,
        None
    );
    for invalid in [
        "",
        "Installed: (none)\n",
        "Candidate: 1\nCandidate: 2",
        "Candidate:1",
        "Candidate: ",
        "Candidate: 1.0 unexpected",
        "Candidate: 1.0\0hidden",
        &format!("Candidate: {}", "1".repeat(256)),
    ] {
        assert_eq!(
            candidate(&mut Fake::new(invalid), "jq"),
            Err(Error::Output {})
        );
    }
    for invalid in ["", "apt\0secret", &"a".repeat(256)] {
        assert_eq!(version(&mut Fake::new(invalid)), Err(Error::Output {}));
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
fn removal_plan_reports_every_unique_package_without_hiding_dependencies() {
    let mut host=Fake::new("Reading package lists...\nRemv jq:amd64 [1.7]\nRemv libonig5:amd64 [6.9]\nRemv jq:amd64 [1.7]\nRemv jq-extra [1.0]\n");
    for _ in 0..2 {
        assert_eq!(
            plan(&mut host, "jq").unwrap().packages,
            ["jq", "libonig5:amd64", "jq-extra"]
        );
    }
    assert_eq!(
        host.calls[0],
        (
            "/usr/bin/apt-get".into(),
            vec![
                "--simulate".into(),
                "remove".into(),
                "--".into(),
                "jq".into()
            ]
        )
    );
    assert!(plan(&mut Fake::new("Reading package lists...\n"), "jq")
        .unwrap()
        .packages
        .is_empty());
    for invalid in ["Remv", "Remv jq* [1]", "Remv jq[1]", " Remv jq [1]"] {
        assert_eq!(plan(&mut Fake::new(invalid), "jq"), Err(Error::Output {}));
    }
}
#[test]
fn mutations_keep_the_dpkg_wait_and_never_purge_or_autoremove() {
    let mut host = Fake::new("");
    for _ in 0..2 {
        mutate(&mut host, "jq", false).unwrap();
        mutate(&mut host, "jq", true).unwrap();
    }
    assert_eq!(host.calls.len(), 4);
    assert_eq!(
        host.calls[0],
        (
            "/usr/bin/apt-get".into(),
            vec![
                "-o",
                "DPkg::Lock::Timeout=300",
                "install",
                "--yes",
                "--no-install-recommends",
                "--",
                "jq"
            ]
            .into_iter()
            .map(str::to_owned)
            .collect()
        )
    );
    assert_eq!(
        host.calls[1],
        (
            "/usr/bin/apt-get".into(),
            vec![
                "-o",
                "DPkg::Lock::Timeout=300",
                "remove",
                "--yes",
                "--",
                "jq"
            ]
            .into_iter()
            .map(str::to_owned)
            .collect()
        )
    );
}
#[test]
fn failure_never_becomes_a_missing_package_or_successful_mutation() {
    let mut host = Fake {
        fail: true,
        ..Fake::new("private diagnostic")
    };
    assert_eq!(version(&mut host), Err(Error::Failed {}));
    assert_eq!(candidate(&mut host, "jq"), Err(Error::Failed {}));
    assert_eq!(inventory(&mut host), Err(Error::Failed {}));
    assert_eq!(plan(&mut host, "jq"), Err(Error::Failed {}));
    assert_eq!(mutate(&mut host, "jq", false), Err(Error::Failed {}));
    assert_eq!(mutate(&mut host, "jq", true), Err(Error::Failed {}));
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
