use super::*;
use crate::commands::image_recipe;

struct ImageHost<'a> {
    host: &'a mut Fake,
    config: PathBuf,
}
impl RecipeHost for ImageHost<'_> {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
        assert_eq!(
            call.environment
                .iter()
                .filter(|(k, _)| k == "APT_CONFIG")
                .map(|(_, v)| v.as_str())
                .collect::<Vec<_>>(),
            [self.config.to_str().unwrap()]
        );
        if (call.argv[0] == "apt-get" && ["full-upgrade", "check"].contains(&call.argv[1].as_str()))
            || (call.argv[0] == "dpkg" && call.argv[1] == "--audit")
            || (call.argv[0] == "dpkg-query"
                && call.argv.iter().any(|v| v.contains("${Architecture}")))
        {
            self.host.calls.push(call.clone());
            return Ok(Output::default());
        }
        if call
            .argv
            .starts_with(&["systemctl".into(), "show".into(), "-p".into()])
        {
            self.host.calls.push(call.clone());
            return Ok(Output {
                stdout: "not-found".into(),
                ..Output::default()
            });
        }
        self.host.execute(call)
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("No retries")
    }
}
impl Host for ImageHost<'_> {
    fn ping(&mut self, _: &Path, _: Instant) -> bool {
        panic!("Images do not run FPM")
    }
}
#[test]
fn image_packages_use_the_native_php_installer_with_the_same_snapshot_and_propagate_failure() {
    for fails in [false, true] {
        let mut f = Fixture::new();
        f.input.fpm = Fpm::None;
        if fails {
            f.host.packages.insert("php8.5-xdebug".into(), "1.0".into());
        }
        let input = image_recipe::Input {
            identity: format!("sha256:{}", "a".repeat(64)), agent_sha256:"b".repeat(64), pi_sha256:None,
            rust:BTreeMap::new(), php:Some(f.input.clone()),
            recipe:serde_json::from_value(serde_json::json!({"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}})).unwrap(),
        };
        input.validate().unwrap();
        image_recipe::apt_setup(&f.files, &input, &f.files.path(image_recipe::APT)).unwrap();
        let config = f.files.path(image_recipe::APT).join("apt.conf");
        let result = image_recipe::packages::install(
            &mut ImageHost {
                host: &mut f.host,
                config,
            },
            &f.files,
            &input,
            Instant::now() + Duration::from_secs(10),
        );
        assert!(!f.files.path("/usr/sbin/policy-rc.d").exists());
        if fails {
            assert_eq!(result.unwrap_err().stage, "php");
            assert!(!f
                .host
                .calls
                .iter()
                .any(|c| c.argv.starts_with(&["apt-get".into(), "install".into()])));
            assert!(!f.host.calls.iter().any(|c| c.argv[0] == "curl"));
        } else {
            result.unwrap();
            for pin in &f.input.versions["8.5"].packages {
                assert_eq!(f.host.packages[&pin.name], pin.version);
            }
            assert!(f.host.calls.iter().any(|c| c.argv[0] == "curl"));
        }
        assert!(!f
            .host
            .calls
            .iter()
            .any(|c| ["python3", "sh", "bash"].contains(&c.argv[0].as_str())));
    }
}

#[test]
fn image_verification_checks_native_php_and_guest_access_without_repairing_drift() {
    struct VerifyHost<'a> {
        host: &'a mut Fake,
        config: Option<String>,
        guest_broken: bool,
    }
    impl RecipeHost for VerifyHost<'_> {
        fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
            if let Some((_, config)) = call.environment.iter().find(|(k, _)| k == "APT_CONFIG") {
                if let Some(first) = &self.config {
                    assert_eq!(config, first);
                }
                self.config = Some(config.clone());
                assert!(fs::read_to_string(config)
                    .unwrap()
                    .contains("Dir::Etc::parts \"\""));
                assert!(
                    fs::read_to_string(Path::new(config).with_file_name("ubuntu.sources"))
                        .unwrap()
                        .contains("20261022T000000Z")
                );
            }
            let mut out = Output::default();
            match call.argv[0].as_str() {
                "apt-get" | "curl" | "phpenmod" | "phpdismod" | "systemctl" => {
                    panic!("Image verification must not repair PHP: {:?}", call.argv)
                }
                "dpkg-query" if call.argv.iter().any(|v| v.contains("${Architecture}")) => {}
                "env" => {
                    if call.argv.last().unwrap() == "--version" {
                        assert!(call.argv[call.argv.len() - 2].ends_with("/php8.5"));
                        out.stdout = "PHP 8.5.11".into();
                        out.code = i32::from(self.guest_broken);
                    }
                }
                _ => return self.host.execute(call),
            }
            self.host.calls.push(call.clone());
            Ok(out)
        }
        fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
            panic!("No retry")
        }
    }
    for defect in ["none", "missing", "damaged", "extra", "modules", "guest"] {
        let mut f = Fixture::new();
        f.current();
        f.input.fpm = Fpm::None;
        match defect {
            "missing" => {
                f.host.packages.remove("php8.5-cli");
            }
            "damaged" => {
                f.host
                    .damaged
                    .insert("php8.5-cli".into(), "??5?????? /usr/bin/php8.5".into());
            }
            "extra" => {
                f.host.packages.insert("php8.5-xdebug".into(), "1.0".into());
            }
            "modules" => f.host.cli_extra = "xdebug".into(),
            _ => {}
        }
        let before = f.host.packages.clone();
        write(&f.files, "/usr/local/bin/orbit-agent", "pinned agent");
        let identity = format!("sha256:{}", "a".repeat(64));
        write(
            &f.files,
            "/etc/orbit-machine-recipe.json",
            serde_json::json!({"identity":identity, "profile":"base"}).to_string(),
        );
        let account = recipe::store::Account {
            name: "orbit".into(),
            uid: f.files.uid,
            gid: f.files.gid,
            home: f.files.path("/home/orbit"),
        };
        let input = image_recipe::Input {
            identity, agent_sha256: crate::command::run::file_sha256(&f.files.path("/usr/local/bin/orbit-agent")).unwrap(),
            pi_sha256:None, rust:BTreeMap::new(), php:Some(f.input.clone()),
            recipe:serde_json::from_value(serde_json::json!({"user":"orbit","snapshot":"20261022T000000Z","architecture":"amd64","tools":{}})).unwrap(),
        };
        let mut host = VerifyHost {
            host: &mut f.host,
            config: None,
            guest_broken: defect == "guest",
        };
        for _ in 0..2 {
            host.config = None;
            let result = image_recipe::verify::verify(
                &mut host,
                &f.files,
                &input,
                &account,
                Instant::now() + Duration::from_secs(5),
            );
            assert_eq!(result.is_ok(), defect == "none", "{defect}: {result:?}");
            assert!(!Path::new(host.config.as_ref().unwrap()).exists());
        }
        assert_eq!(f.host.packages, before);
        assert!(!f.files.path(CACHE).exists());
        assert_eq!(fs::read_dir(f.files.path("/var/tmp")).unwrap().count(), 0);
        if defect == "none" {
            assert!(f
                .host
                .calls
                .iter()
                .any(|c| c.argv[0].ends_with("/php8.5") && c.argv[1] == "-m"));
            assert!(f
                .host
                .calls
                .iter()
                .any(|c| c.argv[0] == "env" && c.argv.last().unwrap() == "--version"));
        }
    }
}
