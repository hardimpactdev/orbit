//! Real APT resolution with private status, cache and lists. Never fetch or install on the host.
use super::*;
use crate::commands::recipe::Output;
use serde_json::json;
struct Fixture {
    _work: WorkDir,
    files: Files,
    input: Input,
    host: Resolver,
}
struct Resolver {
    root: PathBuf,
    results: Vec<(String, String)>,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let root = work.0.clone();
        let files = Files {
            root: root.clone(),
            uid: rustix::process::getuid().as_raw(),
            gid: rustix::process::getgid().as_raw(),
        };
        for directory in [
            "/etc/apt/apt.conf.d",
            "/etc/apt/sources.list.d",
            "/etc/apt/preferences.d",
            "/lists/partial",
            "/cache/archives/partial",
            "/usr/sbin",
        ] {
            fs::create_dir_all(files.path(directory)).unwrap();
        }
        fs::write(
            files.path("/etc/apt/apt.conf"),
            "ImageTest::Main \"loaded\";\n",
        )
        .unwrap();
        fs::write(
            files.path("/etc/apt/apt.conf.d/01-test"),
            "ImageTest::Parts \"loaded\";\n",
        )
        .unwrap();
        fs::write(
            files.path("/etc/apt/sources.list.d/live.list"),
            "deb https://live.invalid/ubuntu resolute main\n",
        )
        .unwrap();
        fs::write(files.path("/status"), "Package: bind9-libs\nStatus: install ok installed\nArchitecture: amd64\nVersion: 2.0\nDescription: fixture\n\nPackage: phased-library\nStatus: install ok installed\nArchitecture: amd64\nVersion: 1.0\nDescription: fixture\n\n").unwrap();
        let prefix = "snapshot.ubuntu.com_ubuntu_20261014T000000Z_dists_resolute";
        fs::write(files.path(&format!("/lists/{prefix}_Release")), "Origin: Ubuntu\nLabel: Ubuntu\nSuite: resolute\nCodename: resolute\nVersion: 26.04\nArchitectures: amd64\nComponents: main\n").unwrap();
        let mut packages = String::new();
        for (name, version, dependency) in [
            ("bind9-dnsutils", "1.0", "bind9-libs (= 1.0)"),
            ("bind9-dnsutils", "2.0", "bind9-libs (= 2.0)"),
            ("bind9-libs", "1.0", ""),
            ("bind9-libs", "2.0", ""),
            ("phased-library", "1.0", ""),
            ("phased-library", "2.0", ""),
        ] {
            packages.push_str(&format!(
                "Package: {name}\nVersion: {version}\nArchitecture: amd64\n"
            ));
            if !dependency.is_empty() {
                packages.push_str(&format!("Depends: {dependency}\n"));
            }
            if name != "bind9-dnsutils" && version == "2.0" {
                packages.push_str("Phased-Update-Percentage: 0\n");
            }
            packages.push_str(&format!(
                "Filename: pool/{name}_{version}_amd64.deb\nSize: 1\nDescription: fixture\n\n"
            ));
        }
        fs::write(
            files.path(&format!("/lists/{prefix}_main_binary-amd64_Packages")),
            packages,
        )
        .unwrap();
        let input = serde_json::from_value(json!({"identity":format!("sha256:{}", "a".repeat(64)), "agent_sha256":"b".repeat(64), "pi_sha256":null,"php":null,"rust":{},"recipe":{"user":"orbit","snapshot":"20261014T000000Z","architecture":"amd64","tools":{"bind9-dnsutils":{"source":"ubuntu","package":"bind9-dnsutils","version":"2.0"}}}})).unwrap();
        Self {
            _work: work,
            files,
            input,
            host: Resolver {
                root,
                results: vec![],
            },
        }
    }
}
impl Resolver {
    fn apt(&self, args: &[String], config: &Path) -> Output {
        let isolation = format!("\nDir::Etc \"{0}/etc/apt\";\nDir::State::lists \"{0}/lists\";\nDir::State::status \"{0}/status\";\nDir::State::extended_states \"{0}/extended_states\";\nDir::Cache \"{0}/cache\";\nDir::Cache::pkgcache \"\";\nDir::Cache::srcpkgcache \"\";\nAPT::Architecture \"amd64\";\nAPT::Machine-ID \"12345678-1234-1234-1234-123456789abc\";\n", self.root.display());
        let private = self.root.join("isolated.conf");
        fs::write(&private, fs::read_to_string(config).unwrap() + &isolation).unwrap();
        let output = std::process::Command::new(&args[0])
            .args(&args[1..])
            .env("APT_CONFIG", &private)
            .env("LC_ALL", "C")
            .output()
            .unwrap();
        Output {
            code: output.status.code().unwrap_or(-1),
            stdout: String::from_utf8(output.stdout).unwrap(),
            stderr: String::from_utf8(output.stderr).unwrap(),
            truncated: false,
        }
    }
    fn checked(&self, args: &[String], config: &Path) -> Output {
        let out = self.apt(args, config);
        assert_eq!(out.code, 0, "{args:?}: {}\n{}", out.stdout, out.stderr);
        assert_eq!(out.stderr, "");
        out
    }
}
impl Host for Resolver {
    fn execute(&mut self, call: &Invocation) -> Result<Output, Failure> {
        let config = call
            .environment
            .iter()
            .find(|(k, _)| k == "APT_CONFIG")
            .expect("Image invocation must carry APT_CONFIG")
            .1
            .clone();
        let args = &call.argv;
        if args[0] == "apt-get" {
            let mode = if args[1] == "update" {
                "--print-uris"
            } else {
                "--simulate"
            };
            let mut simulate = vec![args[0].clone(), mode.into()];
            simulate.extend_from_slice(&args[1..]);
            let out = self.checked(&simulate, Path::new(&config));
            if args[1] == "install" {
                let mut uri = vec![
                    args[0].clone(),
                    "--print-uris".into(),
                    "--download-only".into(),
                ];
                uri.extend_from_slice(&args[1..]);
                let output = self.checked(&uri, Path::new(&config));
                let uris: Vec<_> = output
                    .stdout
                    .lines()
                    .filter(|line| line.starts_with('\''))
                    .map(|line| line.split_whitespace().next().unwrap())
                    .collect();
                assert_eq!(uris, ["'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/pool/bind9-dnsutils_2.0_amd64.deb'"]);
            }
            self.results.push((args[1].clone(), out.stdout.clone()));
            return Ok(out);
        }
        let stdout = match args[0].as_str() {
            "dpkg" if args[1] == "--print-architecture" => "amd64",
            "dpkg-query" if args[1] == "-W" => "bind9-libs\tamd64\t2.0\tinstall ok installed\nphased-library\tamd64\t1.0\tinstall ok installed\n",
            "dpkg" if args[1] == "--audit" => "",
            "systemctl" if args[1] == "show" => "not-found",
            "apt-cache" | "apt-config" => return Ok(self.checked(args, Path::new(&config))),
            _ => panic!("Unexpected image invocation {args:?}"),
        };
        Ok(Output {
            stdout: stdout.into(),
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("No network retry")
    }
}
impl php_build::Host for Resolver {
    fn ping(&mut self, _: &Path, _: Instant) -> bool {
        panic!("No FPM")
    }
}
#[test]
fn snapshot_configuration_excludes_stock_sources_and_hooks_without_warnings() {
    let mut f = Fixture::new();
    apt_setup(&f.files, &f.input, &f.files.path(APT)).unwrap();
    let mut host = AptHost {
        host: &mut f.host,
        config: apt_check(&f.input, &f.files.path(APT)).unwrap(),
    };
    let dump = host
        .execute(&Invocation::new(&["apt-config", "dump"]))
        .unwrap()
        .stdout;
    assert!(!dump.contains("ImageTest::"));
    assert!(dump.contains("APT::Get::Always-Include-Phased-Updates \"true\""));
    apt_source(&mut f.host, &f.files, &f.input).unwrap();
    let update = &f.host.results[0].1;
    assert!(update.contains("https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/"));
    assert!(!update.contains("live.invalid"));
}
#[test]
fn native_package_phase_uses_real_snapshot_resolution_without_downgrades_or_phased_holds() {
    let mut f = Fixture::new();
    apt_source(&mut f.host, &f.files, &f.input).unwrap();
    packages::install(
        &mut f.host,
        &f.files,
        &f.input,
        Instant::now() + Duration::from_secs(30),
    )
    .unwrap();
    assert_eq!(
        f.host
            .results
            .iter()
            .map(|(phase, _)| phase.as_str())
            .collect::<Vec<_>>(),
        ["update", "full-upgrade", "install", "check"]
    );
    assert!(f.host.results[1]
        .1
        .contains("Inst phased-library [1.0] (2.0"));
    let install = &f.host.results[2].1;
    assert!(install.contains("Inst bind9-dnsutils (2.0 Ubuntu:26.04/resolute [amd64])"));
    assert!(!install.contains("DOWNGRADED"));
    assert!(!install.contains("Inst bind9-libs [2.0] (1.0"));
    assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
}
#[test]
fn strict_dependencies_require_the_current_snapshot_candidate_pin() {
    let f = Fixture::new();
    fs::write(f.files.path("/status"), "").unwrap();
    apt_setup(&f.files, &f.input, &f.files.path(APT)).unwrap();
    let config = apt_check(&f.input, &f.files.path(APT)).unwrap();
    let stale = f.host.apt(
        &[
            "apt-get",
            "--simulate",
            "install",
            "-y",
            "bind9-dnsutils=1.0",
        ]
        .map(str::to_owned),
        &config,
    );
    assert_ne!(stale.code, 0);
    let resolved = f.host.checked(
        &[
            "apt-get",
            "--simulate",
            "install",
            "-y",
            "bind9-dnsutils=2.0",
        ]
        .map(str::to_owned),
        &config,
    );
    assert!(resolved.stdout.contains("Inst bind9-libs (2.0"));
    assert!(!resolved.stdout.contains("DOWNGRADED"));
    let uris = f.host.checked(
        &[
            "apt-get",
            "--print-uris",
            "--download-only",
            "install",
            "-y",
            "bind9-dnsutils=2.0",
        ]
        .map(str::to_owned),
        &config,
    );
    assert!(uris
        .stdout
        .contains("snapshot.ubuntu.com/ubuntu/20261014T000000Z/pool/bind9-libs_2.0"));
    assert!(!uris.stdout.contains("live.invalid"));
}
