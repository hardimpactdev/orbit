use super::*;
use std::os::unix::fs::MetadataExt;

struct Fake {
    exists: bool,
    version: String,
    addresses: Vec<Ipv4Addr>,
    validation: Option<String>,
    active: bool,
    reload: bool,
    default: bool,
    calls: Vec<String>,
    validated: Vec<PathBuf>,
}
impl Default for Fake {
    fn default() -> Self {
        Self {
            exists: true,
            version: "v2.11.4 h1:hash".into(),
            addresses: vec!["10.44.0.9".parse().unwrap()],
            validation: None,
            active: true,
            reload: true,
            default: false,
            calls: vec![],
            validated: vec![],
        }
    }
}
impl Runtime for Fake {
    fn binary_exists(&self) -> bool {
        self.exists
    }
    fn version(&mut self) -> String {
        if self.exists {
            self.version.clone()
        } else {
            String::new()
        }
    }
    fn addresses(&mut self) -> Result<Vec<Ipv4Addr>, String> {
        Ok(self.addresses.clone())
    }
    fn validate(&mut self, path: &Path) -> Result<(), String> {
        self.validated.push(path.into());
        self.validation.clone().map_or(Ok(()), Err)
    }
    fn service(&mut self, args: &[&str]) -> bool {
        self.calls.push(args.join(" "));
        match args[0] {
            "is-active" => self.active,
            "reload-or-restart" | "reload" => self.reload,
            _ => true,
        }
    }
    fn reload_failure(&mut self, _: i64) -> String {
        "Error: address already in use".into()
    }
    fn package_default(&mut self, _: &Path) -> bool {
        self.default
    }
}
struct Fixture {
    layout: Layout,
    runtime: Fake,
}
impl Fixture {
    fn new() -> Self {
        let root = std::env::temp_dir().join(format!(
            "orbit-caddy-publication-{:016x}",
            rand::random::<u64>()
        ));
        fs::create_dir(&root).unwrap();
        Self {
            layout: Layout {
                root,
                uid: rustix::process::geteuid().as_raw(),
                gid: Some(rustix::process::getegid().as_raw()),
            },
            runtime: Fake::default(),
        }
    }
    fn path(&self, name: &str) -> PathBuf {
        self.layout.root.join(name)
    }
    fn write(&self, name: &str, content: &str) {
        let path = self.path(name);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, content).unwrap();
    }
    fn push(&mut self, input: &PublishInput) -> Result<Published, PublicationError> {
        publish(input, &self.layout, &mut self.runtime)
    }
    fn backups(&self) -> Vec<PathBuf> {
        fs::read_dir(self.path("orbit-backups"))
            .map(|entries| entries.map(|entry| entry.unwrap().path()).collect())
            .unwrap_or_default()
    }
    fn versions(&self) -> Vec<PathBuf> {
        fs::read_dir(self.path("orbit-versions"))
            .map(|entries| entries.map(|entry| entry.unwrap().path()).collect())
            .unwrap_or_default()
    }
}
impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.layout.root);
    }
}
fn input(site: &str) -> PublishInput {
    let content = format!("{MARKER}\n{site} {{\n respond hi\n}}\n");
    PublishInput {
        version: version(content.as_bytes()),
        content,
        listen_addresses: vec!["10.44.0.9".parse().unwrap()],
        unserved: false,
    }
}
fn stage(result: Result<Published, PublicationError>, expected: &str) {
    assert!(
        matches!(&result, Err(PublicationError::Failed { stage, .. }) if stage == expected),
        "{result:?}"
    );
}
#[test]
fn publishes_private_version_validates_candidate_and_changes_nothing_twice() {
    let mut f = Fixture::new();
    let input = input("shop.test");
    assert_eq!(
        f.push(&input),
        Ok(Published {
            result: Publication::Published
        })
    );
    let published = f.path(&format!("orbit-versions/{}/Caddyfile", input.version));
    assert_eq!(fs::read_link(f.path("Caddyfile")).unwrap(), published);
    assert_eq!(fs::read_to_string(&published).unwrap(), input.content);
    assert_eq!(fs::metadata(&published).unwrap().mode() & 0o7777, 0o640);
    assert_eq!(
        fs::metadata(published.parent().unwrap()).unwrap().mode() & 0o7777,
        0o750
    );
    assert_eq!(f.runtime.calls, ["enable --quiet", "reload-or-restart"]);
    assert_eq!(
        f.runtime.validated,
        [f.path(&format!(
            "orbit-versions/.{}.candidate/Caddyfile",
            input.version
        ))]
    );
    f.runtime.calls.clear();
    f.runtime.validated.clear();
    assert_eq!(
        f.push(&input),
        Ok(Published {
            result: Publication::Unchanged
        })
    );
    assert!(f.runtime.calls.is_empty());
    assert!(f.runtime.validated.is_empty());
    assert!(f.backups().is_empty());
}
#[test]
fn refuses_invalid_inputs_release_and_addresses_before_writing() {
    let mut f = Fixture::new();
    let mut input = input("shop.test");
    input.version = "../../foreign".into();
    assert_eq!(f.push(&input), Err(PublicationError::Invalid {}));
    input.version = version(input.content.as_bytes());
    for version in ["2.6.2", "", "2.x.0", "2.9"] {
        f.runtime.version = version.into();
        stage(f.push(&input), "release");
    }
    f.runtime.version = "2.9.0".into();
    f.runtime.addresses.clear();
    stage(f.push(&input), "addresses");
    assert!(f.versions().is_empty());
    assert!(f.backups().is_empty());
    assert!(!exists(&f.path("Caddyfile")));
    assert!(f.runtime.validated.is_empty());
    assert!(f.runtime.calls.is_empty());
}
#[test]
fn validation_failure_preserves_live_file_without_backup_or_activation() {
    let mut f = Fixture::new();
    f.write("Caddyfile", "foreign");
    f.runtime.validation = Some("Error: broken directive".into());
    stage(f.push(&input("shop.test")), "validate");
    assert_eq!(fs::read_to_string(f.path("Caddyfile")).unwrap(), "foreign");
    assert!(f.versions().is_empty());
    assert!(f.backups().is_empty());
    assert!(f.runtime.calls.is_empty());
}
#[test]
fn backs_up_foreign_files_but_not_the_package_default() {
    for default in [false, true] {
        let mut f = Fixture::new();
        f.write("Caddyfile", "foreign");
        f.runtime.default = default;
        f.push(&input("shop.test")).unwrap();
        assert_eq!(f.backups().len(), usize::from(!default));
        if !default {
            assert_eq!(
                fs::read_to_string(f.backups()[0].join("Caddyfile")).unwrap(),
                "foreign"
            );
        }
    }
}
#[test]
fn preserves_external_symlink_targets_and_old_fragment_backups() {
    for fragments in [false, true] {
        let mut f = Fixture::new();
        let directory = if fragments {
            "orbit-versions/0123456789abcdef"
        } else {
            "sites"
        };
        f.write(&format!("{directory}/Caddyfile"), "foreign");
        if fragments {
            f.write(&format!("{directory}/fragments/extra.caddy"), "custom");
        }
        symlink(
            f.path(&format!("{directory}/Caddyfile")),
            f.path("Caddyfile"),
        )
        .unwrap();
        f.push(&input("shop.test")).unwrap();
        let backup = &f.backups()[0];
        if fragments {
            assert_eq!(
                fs::read_to_string(backup.join("0123456789abcdef/fragments/extra.caddy")).unwrap(),
                "custom"
            );
        } else {
            assert_eq!(
                fs::read_to_string(backup.join("Caddyfile")).unwrap(),
                "foreign"
            );
        }
        assert_eq!(
            fs::read_to_string(f.path(&format!("{directory}/Caddyfile"))).unwrap(),
            "foreign"
        );
    }
}
#[test]
fn backs_up_hand_edits_and_keeps_existing_backups() {
    let mut f = Fixture::new();
    let input = input("shop.test");
    f.push(&input).unwrap();
    f.write("orbit-backups/20260101T000000Z/Caddyfile", "kept");
    fs::write(f.path("Caddyfile"), format!("{}# edit\n", input.content)).unwrap();
    f.push(&input).unwrap();
    assert_eq!(f.backups().len(), 2);
    assert!(f.backups().iter().any(|backup| fs::read_to_string(
        backup.join(&input.version).join("Caddyfile")
    )
    .is_ok_and(|content| content.ends_with("# edit\n"))));
    assert_eq!(
        fs::read_to_string(f.path("Caddyfile")).unwrap(),
        input.content
    );
    assert_eq!(f.versions().len(), 1);
    f.push(&super::tests::input("next.test")).unwrap();
    assert_eq!(f.backups().len(), 2);
}
#[test]
fn failed_reload_restores_a_regular_file_symlink_or_absence_and_never_restarts() {
    for previous in ["regular", "symlink", "absent", "edited"] {
        for active in [true, false] {
            let mut f = Fixture::new();
            let first = input("shop.test");
            match previous {
                "regular" => f.write("Caddyfile", "foreign"),
                "symlink" | "edited" => {
                    f.push(&first).unwrap();
                    if previous == "edited" {
                        fs::write(f.path("Caddyfile"), format!("{}# edit\n", first.content))
                            .unwrap();
                    }
                }
                _ => {}
            }
            let before = fs::read(f.path("Caddyfile")).ok();
            let target = fs::read_link(f.path("Caddyfile")).ok();
            f.runtime.calls.clear();
            f.runtime.reload = false;
            f.runtime.active = active;
            let next = if previous == "edited" {
                first
            } else {
                input("next.test")
            };
            stage(f.push(&next), "reload");
            assert_eq!(fs::read(f.path("Caddyfile")).ok(), before);
            assert_eq!(fs::read_link(f.path("Caddyfile")).ok(), target);
            assert!(!f.runtime.calls.iter().any(|call| call == "restart"));
            if previous != "absent" {
                assert_eq!(
                    f.runtime.calls.last().unwrap(),
                    if active { "reload" } else { "start" }
                );
            }
            assert_eq!(
                f.versions().len(),
                usize::from(previous == "symlink" || previous == "edited")
            );
        }
    }
}
#[test]
fn absent_caddy_skips_only_unserved_inactive_nodes_and_moves_stale_paths() {
    for kind in ["absent", "file", "symlink", "matching"] {
        let mut f = Fixture::new();
        let mut input = input("shop.test");
        input.unserved = true;
        f.runtime.exists = false;
        f.runtime.active = false;
        match kind {
            "file" => f.write("Caddyfile", "stale"),
            "symlink" => {
                f.write("old", "stale");
                symlink(f.path("old"), f.path("Caddyfile")).unwrap();
            }
            "matching" => f.write("Caddyfile", &input.content),
            _ => {}
        }
        assert_eq!(
            f.push(&input),
            Ok(Published {
                result: Publication::Unchanged
            })
        );
        assert_eq!(exists(&f.path("Caddyfile")), kind == "matching");
        assert_eq!(
            f.backups().len(),
            usize::from(kind == "file" || kind == "symlink")
        );
        if kind == "symlink" {
            assert!(fs::symlink_metadata(f.backups()[0].join("Caddyfile"))
                .unwrap()
                .is_symlink());
        }
        assert!(f.runtime.validated.is_empty());
        assert_eq!(f.runtime.calls, ["is-active --quiet"]);
        f.runtime.active = true;
        stage(f.push(&input), "release");
        f.runtime.active = false;
        input.unserved = false;
        stage(f.push(&input), "release");
    }
}
#[test]
fn keeps_nine_other_versions_and_backs_up_edited_versions_before_pruning() {
    let mut f = Fixture::new();
    for age in 1..=12 {
        let input = input(&format!("site-{age}.test"));
        let directory = format!("orbit-versions/{}", input.version);
        f.write(
            &format!("{directory}/Caddyfile"),
            &if age == 12 {
                format!("{}# edit\n", input.content)
            } else {
                input.content
            },
        );
        let times = fs::FileTimes::new()
            .set_modified(std::time::UNIX_EPOCH + Duration::from_secs(1000 - age));
        fs::File::open(f.path(&directory))
            .unwrap()
            .set_times(times)
            .unwrap();
    }
    f.push(&input("current.test")).unwrap();
    assert_eq!(f.versions().len(), 10);
    assert_eq!(f.backups().len(), 1);
    let edited = input("site-12.test");
    assert!(
        fs::read_to_string(f.backups()[0].join(&edited.version).join("Caddyfile"))
            .unwrap()
            .ends_with("# edit\n")
    );
    assert!(!f
        .path(&format!("orbit-versions/{}", edited.version))
        .exists());
}
#[test]
fn reads_only_the_live_file_and_refuses_oversized_or_non_file_content() {
    let f = Fixture::new();
    f.write("versions/one", "import other/*\n");
    symlink(f.path("versions/one"), f.path("Caddyfile")).unwrap();
    assert_eq!(
        read_live(&f.path("Caddyfile")).unwrap().content,
        "import other/*\n"
    );
    fs::remove_file(f.path("Caddyfile")).unwrap();
    assert_eq!(read_live(&f.path("Caddyfile")).unwrap().content, "");
    f.write("Caddyfile", &"x".repeat(LIMIT as usize + 1));
    assert!(
        matches!(read_live(&f.path("Caddyfile")), Err(PublicationError::Failed { stage, .. }) if stage == "read-live")
    );
    fs::remove_file(f.path("Caddyfile")).unwrap();
    fs::create_dir(f.path("Caddyfile")).unwrap();
    assert!(read_live(&f.path("Caddyfile")).is_err());
}

#[test]
fn unserved_node_needs_no_caddy_group_and_failed_backup_keeps_the_stale_path() {
    let mut f = Fixture::new();
    let mut input = input("shop.test");
    input.unserved = true;
    f.runtime.exists = false;
    f.runtime.active = false;
    f.layout.gid = None;
    f.write("Caddyfile", "stale");
    f.write("orbit-backups", "occupied");
    stage(f.push(&input), "release");
    assert_eq!(fs::read_to_string(f.path("Caddyfile")).unwrap(), "stale");
    fs::remove_file(f.path("orbit-backups")).unwrap();
    f.push(&input).unwrap();
    assert!(!exists(&f.path("Caddyfile")));
    assert_eq!(
        fs::read_to_string(f.backups()[0].join("Caddyfile")).unwrap(),
        "stale"
    );
}

#[test]
fn filesystem_failures_do_not_change_the_live_file_or_reload() {
    for obstruction in ["orbit-versions", "orbit-backups"] {
        let mut f = Fixture::new();
        f.write("Caddyfile", "foreign");
        f.write(obstruction, "occupied");
        stage(
            f.push(&input("shop.test")),
            if obstruction == "orbit-versions" {
                "write"
            } else {
                "backup"
            },
        );
        assert_eq!(fs::read_to_string(f.path("Caddyfile")).unwrap(), "foreign");
        assert!(f.runtime.calls.is_empty());
        if obstruction == "orbit-backups" {
            assert!(f.versions().is_empty());
        }
    }
}

#[test]
fn pruning_keeps_an_edited_version_when_its_backup_cannot_be_created() {
    let mut f = Fixture::new();
    let edited = input("edited.test");
    f.write(
        &format!("orbit-versions/{}/Caddyfile", edited.version),
        "hand edit",
    );
    fs::File::open(f.path(&format!("orbit-versions/{}", edited.version)))
        .unwrap()
        .set_times(fs::FileTimes::new().set_modified(std::time::UNIX_EPOCH))
        .unwrap();
    for index in 0..9 {
        let old = input(&format!("old-{index}.test"));
        f.write(
            &format!("orbit-versions/{}/Caddyfile", old.version),
            &old.content,
        );
    }
    f.write("orbit-backups", "occupied");
    f.push(&input("live.test")).unwrap();
    assert_eq!(f.versions().len(), 11);
    assert_eq!(
        fs::read_to_string(f.path(&format!("orbit-versions/{}/Caddyfile", edited.version)))
            .unwrap(),
        "hand edit"
    );
}
