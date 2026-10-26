//! Project checkout observations, batched per Node. Git runs only as the recorded owner.
use super::source;
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::HashSet,
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};
command_errors! {
    pub enum Error {
        Invalid {} => "doctor.project_input_invalid", "The Project observation input is invalid.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    repository: String,
    checkouts: Vec<Checkout>,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
struct Checkout {
    instance_id: u64,
    path: PathBuf,
    root: PathBuf,
    user: String,
    production: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    checkouts: Vec<Observation>,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
struct Observation {
    instance_id: u64,
    matches: Option<bool>,
}
fn absolute(path: &Path) -> bool {
    path.is_absolute()
        && !path
            .components()
            .any(|p| matches!(p, std::path::Component::ParentDir))
}
fn git_at(path: &Path, user: &str, args: &[&str]) -> Result<Option<String>, ()> {
    let mut command = Process::new("/usr/bin/timeout");
    command
        .args([
            "--kill-after=1s",
            "5s",
            "/usr/bin/git",
            "-c",
            "core.hooksPath=/dev/null",
            "-c",
            "core.fsmonitor=false",
            "-C",
        ])
        .arg(path)
        .args(args)
        .env_clear()
        .env("PATH", "/usr/bin:/bin")
        .env("GIT_OPTIONAL_LOCKS", "0")
        .stdin(Stdio::null())
        .stderr(Stdio::null());
    let account = identity::passwd(user).ok_or(())?;
    command
        .env("HOME", account.home)
        .env("USER", user)
        .env("LOGNAME", user);
    identity::drop_to(&mut command, user).map_err(|_| ())?;
    let output = command.output().map_err(|_| ())?;
    match output.status.code() {
        Some(124 | 137) | None => Err(()),
        Some(0) => String::from_utf8(output.stdout)
            .map(|s| Some(s.trim_end_matches('\n').into()))
            .map_err(|_| ()),
        _ => Ok(None),
    }
}
fn observe(checkout: &Checkout, repository: &str) -> Option<bool> {
    if !absolute(&checkout.path)
        || !absolute(&checkout.root)
        || !checkout.path.starts_with(&checkout.root)
    {
        return None;
    }
    if !source::is_real_directory(&checkout.path) {
        return Some(false);
    }
    if checkout.production {
        let account = identity::passwd(&checkout.user)?;
        if source::is_symlink(&checkout.root) {
            return None;
        }
        if !source::canonical(&checkout.path)
            || !fs::metadata(&checkout.path).is_ok_and(|m| m.uid() == account.uid)
            || git_at(
                &checkout.path,
                &checkout.user,
                &["rev-parse", "--show-toplevel"],
            )
            .ok()?
            .as_deref()
                != checkout.path.to_str()
        {
            return Some(false);
        }
    }
    let origin = git_at(
        &checkout.path,
        &checkout.user,
        &["config", "--get", "remote.origin.url"],
    )
    .ok()?;
    Some(
        origin
            .and_then(|url| source::repository_identity(&url))
            .as_deref()
            == Some(repository),
    )
}
fn inspect(input: Input) -> Result<Output, Error> {
    let repository = source::repository_identity(&input.repository).ok_or(Error::Invalid {})?;
    let mut seen = HashSet::new();
    if input.checkouts.len() > 4096
        || input.checkouts.iter().any(|c| {
            c.instance_id == 0
                || !seen.insert(c.instance_id)
                || c.user.is_empty()
                || c.user.contains(['\0', '\n', '\r'])
        })
    {
        return Err(Error::Invalid {});
    }
    Ok(Output {
        checkouts: input
            .checkouts
            .iter()
            .map(|c| Observation {
                instance_id: c.instance_id,
                matches: observe(c, &repository),
            })
            .collect(),
    })
}
pub struct InspectProject;
impl Command for InspectProject {
    const NAME: &'static str = "doctor.inspect-project";
    const SUMMARY: &'static str =
        "Observe the repository identity of settled Project checkouts in one call.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Owner)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        inspect(input)
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::symlink;
    struct Fixture {
        root: PathBuf,
        checkout: PathBuf,
        user: String,
    }
    impl Fixture {
        fn new() -> Self {
            let root = std::env::temp_dir().join(format!(
                "orbit-doctor-project-{:016x}",
                rand::random::<u64>()
            ));
            let checkout = root.join("apps/project/development");
            fs::create_dir_all(&checkout).unwrap();
            let repository = git2::Repository::init(&checkout).unwrap();
            repository
                .remote("origin", "https://github.com/acme/project.git")
                .unwrap();
            Self {
                root,
                checkout,
                user: identity::user_name(identity::effective_uid()).unwrap(),
            }
        }
        fn input(&self, production: bool) -> Input {
            Input {
                repository: "https://github.com/acme/project.git".into(),
                checkouts: vec![Checkout {
                    instance_id: 7,
                    path: self.checkout.clone(),
                    root: self.root.join("apps"),
                    user: self.user.clone(),
                    production,
                }],
            }
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.root);
        }
    }
    #[test]
    fn matches_raw_origins_and_equivalent_ssh_urls_without_running_hooks_or_rewrites() {
        let f = Fixture::new();
        let repo = git2::Repository::open(&f.checkout).unwrap();
        repo.config()
            .unwrap()
            .set_str("url.git@other.test:.insteadOf", "https://github.com/")
            .unwrap();
        for origin in [
            "https://github.com/acme/project.git",
            "git@github.com:acme/project.git",
            "ssh://git@github.com/acme/project",
            "https://github.com/acme/project",
        ] {
            repo.remote_set_url("origin", origin).unwrap();
            for production in [false, true] {
                let first = inspect(f.input(production)).unwrap();
                assert_eq!(first.checkouts[0].matches, Some(true));
                assert_eq!(first, inspect(f.input(production)).unwrap());
            }
        }
        for origin in [
            "git@github.com:fork/project.git",
            "git@gitlab.com:acme/project.git",
            "not a repository",
        ] {
            repo.remote_set_url("origin", origin).unwrap();
            assert_eq!(
                inspect(f.input(false)).unwrap().checkouts[0].matches,
                Some(false)
            );
        }
        repo.remote_delete("origin").unwrap();
        assert_eq!(
            inspect(f.input(false)).unwrap().checkouts[0].matches,
            Some(false)
        );
    }
    #[test]
    fn reports_missing_and_linked_checkouts_without_mutation_and_keeps_partial_failures() {
        let f = Fixture::new();
        let mut input = f.input(false);
        input.checkouts[0].root = f.root.join("elsewhere");
        let mut second = f.input(false).checkouts.remove(0);
        second.instance_id = 8;
        input.checkouts.push(second);
        assert_eq!(
            inspect(input).unwrap().checkouts,
            vec![
                Observation {
                    instance_id: 7,
                    matches: None
                },
                Observation {
                    instance_id: 8,
                    matches: Some(true)
                }
            ]
        );
        let link = f.root.join("apps/link");
        symlink(&f.checkout, &link).unwrap();
        for path in [link, f.root.join("apps/missing")] {
            let mut input = f.input(false);
            input.checkouts[0].path = path;
            assert_eq!(inspect(input).unwrap().checkouts[0].matches, Some(false));
        }
        assert!(!f.root.join("apps/missing").exists());
        assert!(f.checkout.join(".git").is_dir());
    }
    #[test]
    fn production_requires_a_canonical_checkout_root_and_owner() {
        let f = Fixture::new();
        let link = f.root.join("linked");
        symlink(f.root.join("apps"), &link).unwrap();
        let mut input = f.input(true);
        input.checkouts[0].root = link.clone();
        input.checkouts[0].path = link.join("project/development");
        assert_eq!(inspect(input).unwrap().checkouts[0].matches, None);
        let mut input = f.input(true);
        input.checkouts[0].user = "nobody".into();
        assert_eq!(inspect(input).unwrap().checkouts[0].matches, Some(false));
        let mut input = f.input(true);
        input.checkouts[0].path = f.checkout.join("subdir");
        fs::create_dir(&input.checkouts[0].path).unwrap();
        assert_eq!(inspect(input).unwrap().checkouts[0].matches, Some(false));
    }
    #[test]
    fn unknown_owner_is_unavailable_and_invalid_envelopes_refuse_before_observation() {
        let f = Fixture::new();
        let mut input = f.input(false);
        input.checkouts[0].user = "orbit-no-such-user".into();
        assert_eq!(inspect(input).unwrap().checkouts[0].matches, None);
        let mut input = f.input(false);
        input.repository = "bad repository".into();
        assert!(inspect(input).is_err());
        let mut input = f.input(false);
        input.checkouts.push(f.input(false).checkouts.remove(0));
        assert!(inspect(input).is_err());
        assert!(serde_json::from_value::<Input>(serde_json::json!({"repository":"https://example.test/repo", "checkouts":[], "script":"x"})).is_err());
        assert!(f.checkout.join(".git").is_dir());
    }
}

command_errors! {
    pub enum SourceError {
        Invalid {} => "doctor.source_input_invalid", "The Instance source observation input is invalid.";
        Unavailable {} => "doctor.source_inspection_failed", "The Instance source could not be observed.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SourceInput {
    repository: String,
    checkout: PathBuf,
    root: PathBuf,
    user: String,
    group: String,
    layout: String,
    starting_commit: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct SourceOutput {
    checkout: bool,
    layout: bool,
    origin: bool,
    history: bool,
}
fn inspect_source(input: &SourceInput) -> Result<SourceOutput, SourceError> {
    if source::repository_identity(&input.repository).is_none()
        || !input.checkout.is_absolute()
        || !absolute(&input.root)
        || !["checkout", "worktree"].contains(&input.layout.as_str())
        || (!input.starting_commit.is_empty()
            && (input.starting_commit.len() != 40
                || !input.starting_commit.bytes().all(|b| b.is_ascii_hexdigit())))
    {
        return Err(SourceError::Invalid {});
    }
    let account = identity::passwd(&input.user)
        .filter(|p| p.uid != 0)
        .ok_or(SourceError::Invalid {})?;
    let group = identity::group_id(&input.group).ok_or(SourceError::Invalid {})?;
    let checkout = input.checkout != input.root
        && input.checkout.starts_with(&input.root)
        && source::is_real_directory(&input.checkout)
        && source::canonical(&input.checkout)
        && fs::metadata(&input.checkout).is_ok_and(|m| m.uid() == account.uid && m.gid() == group);
    let git = |args: &[&str]| {
        git_at(&input.checkout, &input.user, args).map_err(|_| SourceError::Unavailable {})
    };
    let mut layout = false;
    if checkout && git(&["rev-parse", "--show-toplevel"])?.as_deref() == input.checkout.to_str() {
        let git_dir = git(&["rev-parse", "--absolute-git-dir"])?;
        let common_dir = git(&["rev-parse", "--path-format=absolute", "--git-common-dir"])?;
        let dot_git = input.checkout.join(".git");
        layout = match input.layout.as_str() {
            "checkout" => {
                source::is_real_directory(&dot_git)
                    && git_dir.as_deref() == dot_git.to_str()
                    && common_dir.as_deref() == dot_git.to_str()
            }
            _ => {
                source::is_regular_file(&dot_git)
                    && git_dir.is_some()
                    && common_dir.is_some()
                    && git_dir != common_dir
            }
        };
    }
    let origin =
        git(&["config", "--get", "remote.origin.url"])?.as_deref() == Some(&input.repository);
    let history = !input.starting_commit.is_empty()
        && git(&[
            "rev-parse",
            "--verify",
            &format!("{}^{{commit}}", input.starting_commit),
        ])?
        .as_deref()
            == Some(&input.starting_commit)
        && git(&["merge-base", &input.starting_commit, "HEAD"])?.is_some();
    Ok(SourceOutput {
        checkout,
        layout,
        origin,
        history,
    })
}
pub struct InspectDevelopmentSource;
impl Command for InspectDevelopmentSource {
    const NAME: &'static str = "doctor.inspect-development-source";
    const SUMMARY: &'static str =
        "Observe development checkout ownership, Git layout, origin, and recorded history.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = SourceInput;
    type Output = SourceOutput;
    type Error = SourceError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Owner)
    }
    fn run(input: SourceInput, _: &Context) -> Result<SourceOutput, SourceError> {
        inspect_source(&input)
    }
}

#[cfg(test)]
mod source_tests {
    use super::*;
    use std::os::unix::fs::symlink;
    struct Fixture {
        root: PathBuf,
        input: SourceInput,
    }
    impl Fixture {
        fn new() -> Self {
            let root = std::env::temp_dir().join(format!(
                "orbit-doctor-source-{:016x}",
                rand::random::<u64>()
            ));
            let checkout = root.join("project/development");
            fs::create_dir_all(&checkout).unwrap();
            let repo = git2::Repository::init(&checkout).unwrap();
            repo.remote("origin", "https://github.com/acme/project.git")
                .unwrap();
            let user = identity::user_name(identity::effective_uid()).unwrap();
            let group = identity::group_name(fs::metadata(&checkout).unwrap().gid()).unwrap();
            let input = SourceInput {
                repository: "https://github.com/acme/project.git".into(),
                checkout,
                root: root.clone(),
                user,
                group,
                layout: "checkout".into(),
                starting_commit: String::new(),
            };
            let mut f = Self { root, input };
            f.commit("initial");
            f.input.starting_commit = f.commit("recorded");
            f
        }
        fn git(&self, args: &[&str]) -> String {
            git_at(&self.input.checkout, &self.input.user, args)
                .unwrap()
                .unwrap()
        }
        fn commit(&self, file: &str) -> String {
            let repo = git2::Repository::open(&self.input.checkout).unwrap();
            fs::write(self.input.checkout.join(file), file).unwrap();
            let mut index = repo.index().unwrap();
            index.add_path(Path::new(file)).unwrap();
            index.write().unwrap();
            let tree_id = index.write_tree().unwrap();
            let tree = repo.find_tree(tree_id).unwrap();
            let sig = git2::Signature::now("Doctor", "doctor@example.test").unwrap();
            let parent = repo.head().ok().and_then(|h| h.peel_to_commit().ok());
            let parents = parent.as_ref().into_iter().collect::<Vec<_>>();
            repo.commit(Some("HEAD"), &sig, &sig, file, &tree, &parents)
                .unwrap()
                .to_string()
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.root);
        }
    }
    #[test]
    fn preserves_branch_detached_and_older_common_history_without_writing() {
        let f = Fixture::new();
        let initial = inspect_source(&f.input).unwrap();
        assert_eq!(
            initial,
            SourceOutput {
                checkout: true,
                layout: true,
                origin: true,
                history: true
            }
        );
        assert_eq!(initial, inspect_source(&f.input).unwrap());
        f.git(&["switch", "--quiet", "--create", "feature"]);
        f.commit("feature");
        assert!(inspect_source(&f.input).unwrap().history);
        f.git(&[
            "switch",
            "--quiet",
            "--create",
            "older",
            &format!("{}~1", f.input.starting_commit),
        ]);
        f.commit("older");
        assert!(inspect_source(&f.input).unwrap().history);
        f.git(&[
            "switch",
            "--quiet",
            "--detach",
            &format!("{}~1", f.input.starting_commit),
        ]);
        assert!(inspect_source(&f.input).unwrap().history);
        assert_eq!(f.git(&["status", "--porcelain"]), "");
    }
    #[test]
    fn distinguishes_replaced_missing_and_unrecorded_history() {
        let mut f = Fixture::new();
        f.git(&["switch", "--quiet", "--orphan", "replacement"]);
        f.commit("other-root");
        assert!(!inspect_source(&f.input).unwrap().history);
        f.input.starting_commit = "a".repeat(40);
        assert!(!inspect_source(&f.input).unwrap().history);
        f.input.starting_commit.clear();
        assert!(!inspect_source(&f.input).unwrap().history);
    }
    #[test]
    fn compares_raw_origin_exactly_even_when_git_rewrites_urls() {
        let f = Fixture::new();
        f.git(&[
            "config",
            "url.git@other.test:.insteadOf",
            "https://github.com/",
        ]);
        assert!(inspect_source(&f.input).unwrap().origin);
        f.git(&[
            "remote",
            "set-url",
            "origin",
            "git@github.com:acme/project.git",
        ]);
        assert!(!inspect_source(&f.input).unwrap().origin);
    }
    #[test]
    fn rejects_shared_checkout_administration_but_accepts_a_recorded_worktree() {
        let mut f = Fixture::new();
        let worktree = f.root.join("linked");
        f.git(&[
            "worktree",
            "add",
            "--quiet",
            "-b",
            "linked",
            worktree.to_str().unwrap(),
        ]);
        f.input.checkout = worktree;
        assert!(!inspect_source(&f.input).unwrap().layout);
        f.input.layout = "worktree".into();
        assert!(inspect_source(&f.input).unwrap().layout);
        let mut f = Fixture::new();
        let shared = f.root.join("shared.git");
        fs::rename(f.input.checkout.join(".git"), &shared).unwrap();
        fs::create_dir(f.input.checkout.join(".git")).unwrap();
        fs::write(
            f.input.checkout.join(".git/commondir"),
            format!("{}\n", shared.display()),
        )
        .unwrap();
        fs::write(
            f.input.checkout.join(".git/HEAD"),
            fs::read(shared.join("HEAD")).unwrap(),
        )
        .unwrap();
        assert!(!inspect_source(&f.input).unwrap().layout);
        f.input.layout = "worktree".into();
        assert!(!inspect_source(&f.input).unwrap().layout);
    }
    #[test]
    fn distinguishes_checkout_ownership_layout_and_symlinks_from_git_identity() {
        let mut f = Fixture::new();
        let original = f.input.checkout.clone();
        let link = f.root.join("link");
        symlink(&original, &link).unwrap();
        for path in [link, original.join("../development")] {
            f.input.checkout = path;
            assert_eq!(
                inspect_source(&f.input).unwrap(),
                SourceOutput {
                    checkout: false,
                    layout: false,
                    origin: true,
                    history: true
                }
            );
        }
        f.input.checkout = original;
        f.input.root = f.root.join("elsewhere");
        assert!(!inspect_source(&f.input).unwrap().checkout);
        f.input.root = f.root.clone();
        f.input.group = "root".into();
        assert!(!inspect_source(&f.input).unwrap().checkout);
        f.input.checkout = f.root.join("absent");
        assert_eq!(
            inspect_source(&f.input).unwrap(),
            SourceOutput {
                checkout: false,
                layout: false,
                origin: false,
                history: false
            }
        );
        assert!(!f.input.checkout.exists());
    }
    #[test]
    fn refuses_invalid_intent_or_unavailable_execution_without_changing_source() {
        let mut f = Fixture::new();
        f.input.user = "root".into();
        assert!(matches!(
            inspect_source(&f.input),
            Err(SourceError::Invalid {})
        ));
        f.input.user = "nobody".into();
        assert!(matches!(
            inspect_source(&f.input),
            Err(SourceError::Unavailable {})
        ));
        assert!(serde_json::from_value::<SourceInput>(serde_json::json!({"script":"x"})).is_err());
        assert!(f.input.checkout.join(".git").is_dir());
    }
}
