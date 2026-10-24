use super::*;
use crate::command::Command;

struct Fixture {
    _work: WorkDir,
    files: Files,
    account: Account,
}
impl Fixture {
    fn new() -> Self {
        let work = WorkDir::new().unwrap();
        let uid = identity::effective_uid();
        let gid = rustix::process::getegid().as_raw();
        let files = Files {
            root: work.0.clone(),
            uid,
            gid,
        };
        let account = Account {
            name: "mini".into(),
            uid,
            gid,
            home: files.path("/Users/mini"),
        };
        fs::create_dir_all(&account.home).unwrap();
        Self {
            _work: work,
            files,
            account,
        }
    }
    fn direct(&self, prefix: &Path) {
        fs::create_dir_all(prefix.join(".git")).unwrap();
        fs::create_dir_all(prefix.join("bin")).unwrap();
        fs::write(prefix.join("bin/brew"), b"fixture").unwrap();
        fs::set_permissions(prefix.join("bin/brew"), fs::Permissions::from_mode(0o755)).unwrap();
    }
    fn nested(&self, prefix: &Path) {
        fs::create_dir_all(prefix.join("Homebrew/.git")).unwrap();
        fs::create_dir_all(prefix.join("bin")).unwrap();
        symlink("../Homebrew/bin/brew", prefix.join("bin/brew")).unwrap();
    }
}
#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    output: Option<recipe::Output>,
    fail: bool,
}
impl Host for Fake {
    fn execute(
        &mut self,
        call: &Invocation,
    ) -> std::result::Result<recipe::Output, recipe::Failure> {
        self.calls.push(call.clone());
        if self.fail {
            return Err(recipe::Failure::new("probe", "unavailable"));
        }
        Ok(self.output.clone().unwrap_or(recipe::Output {
            stdout: format!("{ORIGIN}\n"),
            ..Default::default()
        }))
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("No retries")
    }
}

#[test]
fn reads_all_supported_layouts_and_returns_native_home_without_mutation() {
    for location in [
        "/opt/homebrew",
        "/usr/local",
        "/Users/mini/homebrew",
        "/Users/mini/.homebrew",
    ] {
        for nested in [false, true] {
            let fixture = Fixture::new();
            let prefix = fixture.files.path(location);
            if nested {
                fixture.nested(&prefix);
            } else {
                fixture.direct(&prefix);
            }
            let mut host = Fake::default();
            for _ in 0..2 {
                assert_eq!(
                    inspect(&mut host, &fixture.files, &fixture.account).unwrap(),
                    Output {
                        prefix: prefix.clone(),
                        home: fixture.account.home.clone()
                    }
                );
            }
            assert_eq!(host.calls.len(), 2);
            for call in host.calls {
                let repo = if nested {
                    prefix.join("Homebrew")
                } else {
                    prefix.clone()
                };
                assert_eq!(
                    call.argv,
                    [
                        "/usr/bin/git",
                        "-C",
                        repo.to_str().unwrap(),
                        "config",
                        "--get",
                        "remote.origin.url"
                    ]
                );
                assert_eq!(
                    call.environment,
                    vec![("HOME".into(), fixture.account.home.to_str().unwrap().into())]
                );
            }
        }
    }
}

#[test]
fn ordinary_prefix_contents_do_not_count_as_homebrew() {
    let fixture = Fixture::new();
    fs::create_dir_all(fixture.files.path("/usr/local/bin")).unwrap();
    fs::write(fixture.files.path("/usr/local/bin/other"), b"unrelated").unwrap();
    let mut host = Fake::default();
    assert_eq!(
        inspect(&mut host, &fixture.files, &fixture.account),
        Err(Error::Absent {})
    );
    assert!(host.calls.is_empty());
}

#[test]
fn ambiguous_and_conflicting_candidates_refuse_the_whole_scope() {
    for conflict in [true, false] {
        let fixture = Fixture::new();
        fixture.direct(&fixture.files.path("/opt/homebrew"));
        if conflict {
            fs::create_dir_all(fixture.account.home.join("homebrew/Homebrew")).unwrap();
        } else {
            fixture.nested(&fixture.files.path("/usr/local"));
        }
        assert_eq!(
            inspect(&mut Fake::default(), &fixture.files, &fixture.account),
            Err(Error::Conflict {})
        );
    }
}

#[test]
fn rejects_symlinks_wrong_ownership_and_ambiguous_repository_shapes() {
    for defect in [
        "prefix_link",
        "git_link",
        "brew_link",
        "not_executable",
        "no_repo",
        "both_repos",
        "owner",
        "nested_link",
        "nested_git_link",
        "wrong_link",
        "regular_nested_brew",
    ] {
        let mut fixture = Fixture::new();
        let prefix = fixture.files.path("/opt/homebrew");
        if matches!(
            defect,
            "nested_link" | "nested_git_link" | "wrong_link" | "regular_nested_brew"
        ) {
            fixture.nested(&prefix);
        } else {
            fixture.direct(&prefix);
        }
        match defect {
            "prefix_link" => {
                fs::rename(&prefix, prefix.with_extension("real")).unwrap();
                symlink(prefix.with_extension("real"), &prefix).unwrap();
            }
            "git_link" => {
                fs::remove_dir(prefix.join(".git")).unwrap();
                symlink(&fixture.account.home, prefix.join(".git")).unwrap();
            }
            "brew_link" => {
                fs::remove_file(prefix.join("bin/brew")).unwrap();
                symlink(&fixture.account.home, prefix.join("bin/brew")).unwrap();
            }
            "not_executable" => {
                fs::set_permissions(prefix.join("bin/brew"), fs::Permissions::from_mode(0o644))
                    .unwrap()
            }
            "no_repo" => fs::remove_dir(prefix.join(".git")).unwrap(),
            "both_repos" => fs::create_dir_all(prefix.join("Homebrew/.git")).unwrap(),
            "owner" => fixture.account.uid += 1,
            "nested_link" => {
                fs::rename(prefix.join("Homebrew"), prefix.join("real")).unwrap();
                symlink("real", prefix.join("Homebrew")).unwrap();
            }
            "nested_git_link" => {
                fs::remove_dir(prefix.join("Homebrew/.git")).unwrap();
                symlink(&fixture.account.home, prefix.join("Homebrew/.git")).unwrap();
            }
            "wrong_link" => {
                fs::remove_file(prefix.join("bin/brew")).unwrap();
                symlink("/tmp/brew", prefix.join("bin/brew")).unwrap();
            }
            "regular_nested_brew" => {
                fs::remove_file(prefix.join("bin/brew")).unwrap();
                fs::write(prefix.join("bin/brew"), b"fixture").unwrap();
            }
            _ => unreachable!(),
        }
        let mut host = Fake::default();
        assert_eq!(
            inspect(&mut host, &fixture.files, &fixture.account),
            Err(Error::Conflict {}),
            "{defect}"
        );
        assert!(host.calls.is_empty(), "{defect}");
    }
}

#[test]
fn rejects_foreign_unreadable_and_truncated_origins() {
    let fixture = Fixture::new();
    fixture.direct(&fixture.files.path("/opt/homebrew"));
    for output in [
        recipe::Output {
            stdout: "https://example.org/brew".into(),
            ..Default::default()
        },
        recipe::Output {
            stdout: ORIGIN.into(),
            code: 1,
            ..Default::default()
        },
        recipe::Output {
            stdout: ORIGIN.into(),
            truncated: true,
            ..Default::default()
        },
        recipe::Output {
            stdout: format!("{ORIGIN}\nextra"),
            ..Default::default()
        },
    ] {
        assert_eq!(
            inspect(
                &mut Fake {
                    output: Some(output),
                    ..Default::default()
                },
                &fixture.files,
                &fixture.account
            ),
            Err(Error::Conflict {})
        );
    }
    assert_eq!(
        inspect(
            &mut Fake {
                fail: true,
                ..Default::default()
            },
            &fixture.files,
            &fixture.account
        ),
        Err(Error::Unreadable {})
    );
}

#[test]
fn missing_or_unsafe_home_refuses_before_git() {
    for home in [
        "/",
        "/Users/../mini",
        "/Users/mini/",
        "/Users/mini with spaces",
        "/missing",
        "relative",
    ] {
        let mut fixture = Fixture::new();
        fixture.account.home = home.into();
        let mut host = Fake::default();
        assert_eq!(
            inspect(&mut host, &fixture.files, &fixture.account),
            Err(Error::Unreadable {})
        );
        assert!(host.calls.is_empty());
    }
}

#[test]
fn macos_can_inspect_as_account_but_cannot_materialize() {
    assert_eq!(
        Inspect::privilege(Platform::Macos),
        Some(Privilege::Account)
    );
    assert_eq!(Materialize::privilege(Platform::Macos), None);
}
