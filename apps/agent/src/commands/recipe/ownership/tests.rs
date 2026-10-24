use super::*;
use crate::commands::recipe::{
    types::{Architecture, Artifact, Source, Tool},
    Output, WorkDir,
};
use std::{collections::VecDeque, time::Duration};
fn fixture() -> (WorkDir, Files) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    for path in [
        "/etc/apt/sources.list.d",
        "/etc/apt/preferences.d",
        "/usr/bin",
    ] {
        fs::create_dir_all(files.path(path)).unwrap();
    }
    (work, files)
}
fn input(names: &[&str]) -> Input {
    Input {
        user: "orbit".into(),
        snapshot: "20261022T000000Z".into(),
        architecture: Architecture::Amd64,
        git_packages: vec![],
        artifact_mirror: String::new(),
        vp_lock: None,
        tools: names
            .iter()
            .map(|name| {
                (
                    (*name).into(),
                    Tool {
                        source: Source::Artifact,
                        version: "1:7.0.1".into(),
                        package: None,
                        lock_sha256: None,
                        source_commit: None,
                        artifacts: [(
                            "amd64".into(),
                            Artifact {
                                url: Some(format!("https://example.test/{name}.deb")),
                                filename: None,
                                version: None,
                                sha256: Some("a".repeat(64)),
                                sha512: None,
                            },
                        )]
                        .into(),
                    },
                )
            })
            .collect(),
    }
}
#[derive(Default)]
struct Fake {
    inventory: String,
    instances: String,
    calls: Vec<Invocation>,
    apt: VecDeque<Output>,
}
impl Host for Fake {
    fn execute(&mut self, invocation: &Invocation) -> Result<Output, Failure> {
        self.calls.push(invocation.clone());
        let stdout = match invocation.argv[0].as_str() {
            "dpkg" if invocation.argv[1] == "--print-architecture" => "amd64\n".into(),
            "dpkg-query" => self.inventory.clone(),
            "incus" => self.instances.clone(),
            "logger" => String::new(),
            "apt-get" => {
                if invocation.argv[1] == "remove" {
                    self.inventory.clear();
                }
                return Ok(self.apt.pop_front().expect("unexpected apt call"));
            }
            _ => panic!("unexpected invocation {invocation:?}"),
        };
        Ok(Output {
            stdout,
            ..Output::default()
        })
    }
    fn wait(&mut self, _: Duration) -> Result<(), Failure> {
        panic!("unexpected retry")
    }
}
fn packages(names: &[&str], version: &str) -> String {
    names
        .iter()
        .map(|name| format!("{name}\tamd64\t{version}\tinstall ok installed\n"))
        .collect()
}

#[test]
fn incus_changes_refuse_active_instances_in_every_project_even_without_the_meta_package() {
    let (_work, files) = fixture();
    let input = input(&["incus", "incus-base", "incus-client"]);
    for names in [&["incus", "incus-base"][..], &["incus-base"][..]] {
        let mut host = Fake { inventory: packages(names, "1:7.0.0"), instances: r#"[{"project":"task","name":"stopped","type":"virtual-machine","status":"Stopped"},{"project":"other","name":"busy","type":"container","status":"Frozen"}]"#.into(), ..Fake::default() };
        for _ in 0..2 {
            let error = incus_preflight(&mut host, &files, &input).unwrap_err();
            assert_eq!(error.stage, "incus:running");
            assert!(error.reason.contains("other/busy (container, Frozen)"));
            assert!(!error.reason.contains("stopped"));
        }
        assert!(host
            .calls
            .iter()
            .all(|call| ["dpkg", "dpkg-query", "incus"].contains(&call.argv[0].as_str())));
        assert!(host
            .calls
            .iter()
            .any(|call| call.argv == ["incus", "list", "--all-projects", "--format=json"]));
    }
}

#[test]
fn unchanged_incus_needs_no_drain_and_invalid_inventory_fails_closed() {
    let (_work, files) = fixture();
    let input = input(&["incus", "incus-base", "incus-client"]);
    pin(&files, &input.incus(), "incus").unwrap();
    let mut host = Fake {
        inventory: packages(&["incus", "incus-base", "incus-client"], "1:7.0.1"),
        ..Fake::default()
    };
    incus_preflight(&mut host, &files, &input).unwrap();
    assert!(!host.calls.iter().any(|call| call.argv[0] == "incus"));
    for invalid in [
        "{}",
        "null",
        "[null]",
        r#"[{"project":"default","name":"vm","type":"virtual-machine"}]"#,
        r#"[{"project":"","name":"vm","type":"virtual-machine","status":"Stopped"}]"#,
    ] {
        let mut host = Fake {
            inventory: packages(&["incus-base"], "1:7.0.0"),
            instances: invalid.into(),
            ..Fake::default()
        };
        assert_eq!(
            incus_preflight(&mut host, &files, &input)
                .unwrap_err()
                .stage,
            "incus:inventory"
        );
    }
    let mut host = Fake {
        inventory: packages(&["incus-base"], "1:7.0.0"),
        instances: "[]".into(),
        ..Fake::default()
    };
    incus_preflight(&mut host, &files, &input).unwrap();
}

#[test]
fn incus_requires_three_pins_and_firmware_aliases_are_repeatable_without_changing_existing_data() {
    let (_work, files) = fixture();
    assert_eq!(
        configure_incus(&mut Fake::default(), &files, &input(&["incus"]), false)
            .unwrap_err()
            .stage,
        "incus:incomplete"
    );
    let mut input = input(&["incus", "incus-base", "incus-client"]);
    let firmware = files.path("/opt/incus/share/qemu");
    fs::create_dir_all(&firmware).unwrap();
    for name in [
        "OVMF_CODE.4MB.fd",
        "OVMF_VARS.4MB.fd",
        "OVMF_VARS.4MB.ms.fd",
    ] {
        fs::write(firmware.join(name), "firmware").unwrap();
    }
    let conflict = firmware.join("OVMF_VARS_4M.fd");
    fs::write(&conflict, "existing nvram").unwrap();
    assert!(incus_firmware(&files, &input).is_err());
    assert!(!firmware.join("OVMF_CODE_4M.fd").exists());
    assert_eq!(fs::read_to_string(&conflict).unwrap(), "existing nvram");
    fs::remove_file(conflict).unwrap();
    for _ in 0..2 {
        incus_firmware(&files, &input).unwrap();
    }
    assert_eq!(
        fs::read_link(firmware.join("OVMF_CODE_4M.ms.fd")).unwrap(),
        Path::new("OVMF_CODE.4MB.fd")
    );
    input.architecture = Architecture::Arm64;
    for _ in 0..2 {
        incus_firmware(&files, &input).unwrap();
    }
    assert_eq!(
        fs::read_link(firmware.join("AAVMF_VARS.ms.fd")).unwrap(),
        Path::new("OVMF_VARS.4MB.ms.fd")
    );
}

#[test]
fn node_consolidation_checks_replacement_and_removal_plan_before_mutating_anything() {
    let (_work, files) = fixture();
    let mut input = input(&["node"]);
    input.tools.get_mut("node").unwrap().version = "24.1.0".into();
    let source = files.apt().join("sources.list.d/node.list");
    let content = "deb https://deb.nodesource.com/node_24.x nodistro main\n";
    fs::write(&source, content).unwrap();
    let mut host = Fake {
        inventory: packages(&["nodejs"], "24.0.0"),
        ..Fake::default()
    };
    assert!(consolidate_node(&mut host, &files, &input, None).is_err());
    assert!(!host.calls.iter().any(|call| call.argv[0] == "apt-get"));
    assert_eq!(fs::read_to_string(&source).unwrap(), content);
    host.apt.push_back(Output {
        stdout: "Remv nodejs [24.0.0]\nRemv other [1.0]\n".into(),
        ..Output::default()
    });
    assert!(consolidate_node(&mut host, &files, &input, Some("24.1.0")).is_err());
    assert_eq!(fs::read_to_string(&source).unwrap(), content);
    host.apt.push_back(Output {
        stdout: "Remv nodejs [24.0.0]\n".into(),
        ..Output::default()
    });
    host.apt.push_back(Output::default());
    let actions = consolidate_node(&mut host, &files, &input, Some("24.1.0")).unwrap();
    assert!(actions.contains(&"remove_nodejs_package".into()));
    assert!(!source.exists());
    assert!(consolidate_node(&mut host, &files, &input, Some("24.1.0"))
        .unwrap()
        .is_empty());
}

#[test]
fn node_source_preferences_and_keys_are_backed_up_but_custom_or_referenced_files_refuse() {
    let (_work, files) = fixture();
    let input = input(&["node"]);
    let source = files.apt().join("sources.list.d/node.list");
    fs::write(
        &source,
        "deb https://deb.nodesource.com/node_24.x nodistro main\n",
    )
    .unwrap();
    let preference = files.apt().join("preferences.d/nodejs");
    fs::write(
        &preference,
        "Package: nodejs other\nPin: origin deb.nodesource.com\nPin-Priority: 600\n",
    )
    .unwrap();
    assert!(node_plan(&mut Fake::default(), &files, &input).is_err());
    fs::write(
        &preference,
        "Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 600\n",
    )
    .unwrap();
    let key = files.path("/usr/share/keyrings/nodesource.gpg");
    fs::create_dir_all(key.parent().unwrap()).unwrap();
    fs::write(&key, "key").unwrap();
    let other = files.apt().join("sources.list.d/other.list");
    fs::write(
        &other,
        format!(
            "deb [signed-by={}] https://other.test/ubuntu resolute main\n",
            key.display()
        ),
    )
    .unwrap();
    assert!(node_plan(&mut Fake::default(), &files, &input)
        .unwrap_err()
        .reason
        .contains("still referenced"));
    fs::remove_file(&other).unwrap();
    consolidate_node(&mut Fake::default(), &files, &input, Some("1:7.0.1")).unwrap();
    assert!(!preference.exists());
    assert!(!key.exists());
    assert_eq!(
        fs::read_to_string(
            files
                .apt()
                .join("nodesource.gpg.orbit-node-usr-keyring-backup")
        )
        .unwrap(),
        "key"
    );
}

#[test]
fn only_dangling_exact_corepack_links_are_removed() {
    let (_work, files) = fixture();
    let bin = files.path("/usr/bin");
    symlink(
        "../lib/node_modules/corepack/dist/pnpm.js",
        bin.join("pnpm"),
    )
    .unwrap();
    symlink("/other/yarn.js", bin.join("yarn")).unwrap();
    let target = files.path("/usr/lib/node_modules/corepack/dist/yarnpkg.js");
    fs::create_dir_all(target.parent().unwrap()).unwrap();
    fs::write(&target, "keep").unwrap();
    symlink(
        "../lib/node_modules/corepack/dist/yarnpkg.js",
        bin.join("yarnpkg"),
    )
    .unwrap();
    assert_eq!(dangling_corepack(&files), [bin.join("pnpm")]);
}
#[test]
fn git_siblings_are_checked_across_architectures_before_provider_changes() {
    let (_work, files) = fixture();
    let mut input = input(&["git", "git-man"]);
    input.git_packages = vec!["git".into(), "git-man".into()];
    let source = files.apt().join("sources.list.d/git.sources");
    fs::write(&source,"Types: deb\nURIs: https://ppa.launchpadcontent.net/git-core/ppa/ubuntu\nSuites: resolute\nComponents: main\n").unwrap();
    for (inventory, expected) in [
        ("git\tgit\tinstalled\ngit-man\tgit\tinstalled\n", None),
        (
            "git-email\tgit\tinstalled\n",
            Some("git-dependency:git-email"),
        ),
        (
            "git-svn:i386\tgit\tinstalled\n",
            Some("git-dependency:git-svn:i386"),
        ),
        (
            "git-email\tgit\tconfig-files\nother\tother-source\tinstalled\n",
            None,
        ),
        ("malformed", Some("inventory:git")),
    ] {
        let mut host = Fake {
            inventory: inventory.into(),
            ..Default::default()
        };
        let result = check_git_siblings(&mut host, &input);
        assert_eq!(result.err().map(|e| e.stage), expected.map(str::to_string));
        assert!(source.exists());
        assert_eq!(host.calls.len(), 1);
    }
}
#[test]
fn git_retirement_is_planned_without_changes_and_pins_require_verified_receipts() {
    let (_work, files) = fixture();
    let mut input = input(&["git", "git-man"]);
    input.git_packages = vec!["git".into(), "git-man".into()];
    let source = files.apt().join("sources.list.d/git.sources");
    let bytes=b"Types: deb\nURIs: https://ppa.launchpadcontent.net/git-core/ppa/ubuntu\nSuites: resolute\nComponents: main\n";
    fs::write(&source, bytes).unwrap();
    assert_eq!(
        configure_git(&files, &input, false).unwrap(),
        vec!["retire_git_source", "pin_git"]
    );
    assert!(source.exists());
    configure_git(&files, &input, true).unwrap();
    assert!(!source.exists());
    assert_eq!(
        fs::read(files.apt().join("git.sources.orbit-git-backup")).unwrap(),
        bytes
    );
    assert!(!files.apt().join("preferences.d/orbit-git").exists());
    assert!(record_receipt(&files, &input.git(), &BTreeMap::new(), "git").is_err());
    assert!(!files.apt().join("orbit-git.json").exists());
    let versions = input
        .tools
        .iter()
        .map(|(name, pin)| (name.clone(), Some(pin.version.clone())))
        .collect();
    record_receipt(&files, &input.git(), &versions, "git").unwrap();
    pin(&files, &input.git(), "git").unwrap();
    assert!(configure_git(&files, &input, false).unwrap().is_empty());
    fs::remove_file(files.apt().join("preferences.d/orbit-git")).unwrap();
    std::os::unix::fs::symlink(&source, files.apt().join("preferences.d/orbit-git")).unwrap();
    assert_eq!(
        configure_git(&files, &input, false).unwrap_err().stage,
        "apt-source"
    );
}
