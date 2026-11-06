use super::*;
use crate::commands::recipe::{
    types::{Architecture, Artifact, Source, Tool},
    Output, WorkDir,
};
use std::{collections::VecDeque, os::unix::fs::symlink, time::Duration};
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
