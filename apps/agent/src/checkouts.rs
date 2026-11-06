//! Git checkouts under an app-dev Node's apps root, `<apps_root>/<project>/<name>`, reported as one `checkout` fact
//! each and a `checkouts` index (Node agent observer pass). The agent reads Git files with libgit2 and starts no
//! program. It reacts to file events on the Git files that name a branch or commit, and rescans everything at start
//! and every [`facts::RESYNC_INTERVAL`].

use crate::facts;
use crate::workspace::{open_checkout, WatchFacts};
use git2::Repository;
use notify::{RecursiveMode, Watcher};
use serde_json::{json, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::io::Read;
use std::os::unix::fs::MetadataExt;
use std::path::{Path, PathBuf};
use std::time::Duration;
use tokio::sync::{mpsc, watch};

pub const MAX_CHECKOUTS: usize = 4096;
const COMPOSER_BYTES: u64 = 1_048_576;
/// Events that arrive within this window are read together, so one `git checkout` is one read.
const DEBOUNCE: Duration = Duration::from_secs(2);
/// The file names in a work tree whose change changes the fact; other work tree events are ignored.
const WORK_TREE_FILES: [&str; 3] = ["composer.json", "artisan", ".env"];
/// The Git files and directories whose change changes the fact.
const GIT_FILES: [&str; 4] = ["HEAD", "packed-refs", "config", "refs"];

/// The checkouts `<root>/<project>/<name>` that hold `.git`, sorted. Symlinks and hidden names are skipped.
pub fn find(root: &Path) -> Vec<PathBuf> {
    let mut found: Vec<PathBuf> = directories(root)
        .into_iter()
        .flat_map(|project| directories(&project))
        .filter(|checkout| checkout.join(".git").symlink_metadata().is_ok())
        .collect();
    found.sort();
    found.truncate(MAX_CHECKOUTS);
    found
}

fn directories(path: &Path) -> Vec<PathBuf> {
    let Ok(entries) = std::fs::read_dir(path) else {
        return Vec::new();
    };
    entries
        .flatten()
        .filter(|entry| entry.file_type().is_ok_and(|kind| kind.is_dir()))
        .filter(|entry| !entry.file_name().to_string_lossy().starts_with('.'))
        .map(|entry| entry.path())
        .collect()
}

/// The origin with any credential masked as `REDACTED`, the same as the Gateway's interim read: a `user:secret@` of
/// any scheme, and any user of `http` or `https`.
pub fn mask_origin(url: &str) -> String {
    let Some(scheme_end) = url.find("://") else {
        return url.to_owned();
    };
    let scheme = &url[..scheme_end];
    let rest = &url[scheme_end + 3..];
    let authority = &rest[..rest.find('/').unwrap_or(rest.len())];
    let Some(at) = authority.rfind('@') else {
        return url.to_owned();
    };
    let secret = authority[..at].contains(':');
    if secret || scheme == "http" || scheme == "https" {
        return format!("{scheme}://REDACTED@{}", &rest[at + 1..]);
    }
    url.to_owned()
}

fn text(path: &Path) -> String {
    path.to_string_lossy().trim_end_matches('/').to_owned()
}

/// The application directory of a checkout: the subdirectory `applications` names for its Project, or the checkout.
pub fn application(checkout: &Path, applications: &BTreeMap<String, String>) -> PathBuf {
    let project = checkout
        .parent()
        .and_then(Path::file_name)
        .map(|name| name.to_string_lossy().into_owned());
    match project.and_then(|project| applications.get(&project)) {
        Some(directory)
            if !directory
                .split('/')
                .any(|part| part.is_empty() || part == "." || part == "..") =>
        {
            checkout.join(directory)
        }
        _ => checkout.to_path_buf(),
    }
}

/// The `checkout` fact of one checkout, or an error when it is not a Git work tree root.
pub fn read(checkout: &Path, application: &Path) -> Result<Value, git2::Error> {
    let repo = open_checkout(&checkout.to_string_lossy())?;
    let owner = std::fs::symlink_metadata(checkout)
        .ok()
        .and_then(|metadata| orbit_agent_contract::identity::user_name(metadata.uid()));
    let top_level = repo.workdir().map(text);
    let common_dir = text(repo.commondir());
    let head = repo
        .head()
        .ok()
        .and_then(|head| head.target())
        .map(|oid| oid.to_string());
    let branch = crate::workspace::head_ref(repo.path())
        .and_then(|name| name.strip_prefix("refs/heads/").map(str::to_owned));
    let default_branch = repo
        .find_reference("refs/remotes/origin/HEAD")
        .ok()
        .and_then(|reference| reference.symbolic_target().map(str::to_owned))
        .and_then(|name| name.strip_prefix("refs/remotes/origin/").map(str::to_owned));
    let origin = repo
        .find_remote("origin")
        .ok()
        .and_then(|remote| remote.url().map(mask_origin));
    let artisan = match std::fs::symlink_metadata(application.join("artisan")) {
        Ok(metadata) if metadata.file_type().is_symlink() => "symlink",
        Ok(metadata) if metadata.is_file() => "regular",
        _ => "absent",
    };
    Ok(json!({
        "owner": owner,
        "top_level": top_level,
        "common_dir": common_dir,
        "linked": repo.is_worktree(),
        "worktrees": worktrees(&repo),
        "origin": origin,
        "branch": branch,
        "head": head,
        "default_branch": default_branch,
        "application": text(application),
        "artisan": artisan,
        "composer": composer(&application.join("composer.json")),
        "env": env(&application.join(".env")),
    }))
}

/// The kind, mode and owner of `.env`, from `stat` alone: the agent never opens it, and may not be able to.
fn env(path: &Path) -> Value {
    let Ok(metadata) = std::fs::symlink_metadata(path) else {
        return Value::Null;
    };
    let kind = if metadata.file_type().is_symlink() {
        "symlink"
    } else if metadata.is_file() {
        "regular"
    } else {
        "other"
    };
    json!({
        "kind": kind,
        "mode": format!("{:04o}", metadata.mode() & 0o7777),
        "owner": orbit_agent_contract::identity::user_name(metadata.uid()),
    })
}

/// Every work tree path of the repository: the main one, then each linked one, sorted.
fn worktrees(repo: &Repository) -> Vec<String> {
    let common = repo.commondir();
    let main = Repository::open(common).ok();
    let mut paths: BTreeSet<String> = main
        .as_ref()
        .and_then(Repository::workdir)
        .map(text)
        .into_iter()
        .collect();
    if let Ok(names) = main.as_ref().unwrap_or(repo).worktrees() {
        for name in names.iter().flatten() {
            if let Ok(worktree) = main.as_ref().unwrap_or(repo).find_worktree(name) {
                paths.insert(text(worktree.path()));
            }
        }
    }
    paths.into_iter().take(256).collect()
}

/// The text of `composer.json`, at most 1 MiB, or null when it is absent, too large, or not UTF-8.
fn composer(path: &Path) -> Option<String> {
    let metadata = std::fs::symlink_metadata(path).ok()?;
    if !metadata.is_file() || metadata.len() > COMPOSER_BYTES {
        return None;
    }
    let mut text = String::new();
    std::fs::File::open(path)
        .ok()?
        .take(COMPOSER_BYTES)
        .read_to_string(&mut text)
        .ok()?;
    Some(text)
}

/// The paths to watch for one checkout: its Git files and its application directory.
fn watch_paths(checkout: &Path, application: &Path) -> Vec<(PathBuf, RecursiveMode)> {
    let Ok(repo) = open_checkout(&checkout.to_string_lossy()) else {
        return Vec::new();
    };
    let gitdir = repo.path().to_path_buf();
    let common = repo.commondir().to_path_buf();
    let mut paths = vec![
        (gitdir.clone(), RecursiveMode::NonRecursive),
        (common.join("refs/heads"), RecursiveMode::Recursive),
        (
            common.join("refs/remotes/origin"),
            RecursiveMode::NonRecursive,
        ),
        (application.to_path_buf(), RecursiveMode::NonRecursive),
    ];
    if common != gitdir {
        paths.push((common, RecursiveMode::NonRecursive));
    }
    paths
}

/// Whether an event path can change a fact: a Git file, a branch ref, or `composer.json` or `artisan`.
fn relevant(path: &Path) -> bool {
    let name = path
        .file_name()
        .map(|name| name.to_string_lossy())
        .unwrap_or_default();
    WORK_TREE_FILES.contains(&name.as_ref())
        || GIT_FILES.contains(&name.as_ref())
        || path.components().any(|part| part.as_os_str() == "refs")
}

/// The checkouts an event path belongs to: a path inside the checkout, its Git directory, or its common directory.
fn owners(path: &Path, watched: &BTreeMap<PathBuf, Vec<PathBuf>>) -> Vec<PathBuf> {
    watched
        .iter()
        .filter(|(_, roots)| roots.iter().any(|root| path.starts_with(root)))
        .map(|(checkout, _)| checkout.clone())
        .collect()
}

/// Watches the checkouts of the listed apps root and reports them. Without an apps root it reports nothing.
pub async fn watch_checkouts(
    client: reqwest::Client,
    gateway: String,
    mut listed: watch::Receiver<WatchFacts>,
) {
    let (events_tx, mut events) = mpsc::unbounded_channel::<PathBuf>();
    // Each rescan builds a new watcher; dropping the old one removes all its watches.
    let mut _watcher: Option<notify::RecommendedWatcher> = None;
    let mut reporter = facts::Reporter::new(client, &gateway);
    let mut retry = facts::RetryTimer::default();
    let mut resync = tokio::time::interval(facts::RESYNC_INTERVAL);
    resync.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
    // Each checkout with the directories whose events belong to it.
    let mut watched: BTreeMap<PathBuf, Vec<PathBuf>> = BTreeMap::new();
    let current = listed.borrow_and_update().clone();
    let mut root: Option<PathBuf> = current.apps_root.map(PathBuf::from);
    let mut applications = current.applications;
    let mut pending: BTreeSet<PathBuf> = BTreeSet::new();
    let mut rescan = true;
    loop {
        if root.is_none() {
            _watcher = None;
            watched.clear();
            pending.clear();
            reporter.drop_failed("checkout");
            reporter.drop_failed("checkouts");
            rescan = false;
        }
        if let (Some(apps_root), true) = (root.clone(), rescan || !pending.is_empty()) {
            let paths = if rescan {
                let tx = events_tx.clone();
                let mut fresh = match notify::recommended_watcher(
                    move |event: notify::Result<notify::Event>| {
                        if let Ok(event) = event {
                            for path in event.paths {
                                let _ = tx.send(path);
                            }
                        }
                    },
                ) {
                    Ok(fresh) => fresh,
                    Err(error) => {
                        eprintln!("orbit-agent: checkout watcher cannot start: {error}");
                        return;
                    }
                };
                watched.clear();
                let found = find(&apps_root);
                let _ = fresh.watch(&apps_root, RecursiveMode::NonRecursive);
                for project in directories(&apps_root) {
                    let _ = fresh.watch(&project, RecursiveMode::NonRecursive);
                }
                for checkout in &found {
                    let app = application(checkout, &applications);
                    let mut roots = vec![checkout.clone()];
                    for (path, mode) in watch_paths(checkout, &app) {
                        if fresh.watch(&path, mode).is_ok() {
                            roots.push(path);
                        }
                    }
                    watched.insert(checkout.clone(), roots);
                }
                _watcher = Some(fresh);
                found
            } else {
                std::mem::take(&mut pending).into_iter().collect()
            };
            // Reading every checkout on a large Node takes seconds of file I/O, so it runs off the async workers.
            let dirs = applications.clone();
            let read_all = tokio::task::spawn_blocking(move || {
                paths
                    .iter()
                    .filter_map(|checkout| {
                        Some((
                            text(checkout),
                            read(checkout, &application(checkout, &dirs)).ok()?,
                        ))
                    })
                    .collect::<Vec<_>>()
            })
            .await
            .unwrap_or_default();
            for (subject, data) in read_all {
                reporter.report("checkout", Some(&subject), &data).await;
            }
            if rescan {
                let index =
                    json!({"paths": watched.keys().map(|path| text(path)).collect::<Vec<_>>()});
                reporter.report("checkouts", None, &index).await;
                reporter.retain("checkout", |subject| {
                    watched.contains_key(Path::new(subject))
                });
            }
            rescan = false;
        }
        retry.update(reporter.has_failed());
        tokio::select! {
            changed = listed.changed() => {
                if changed.is_err() {
                    return;
                }
                let current = listed.borrow().clone();
                let next = current.apps_root.map(PathBuf::from);
                if next != root || current.applications != applications {
                    root = next;
                    applications = current.applications;
                    rescan = true;
                }
            }
            _ = resync.tick() => rescan = true,
            () = retry.due() => reporter.retry().await,
            Some(path) = events.recv() => {
                let mut paths = vec![path];
                let deadline = tokio::time::sleep(DEBOUNCE);
                tokio::pin!(deadline);
                loop {
                    tokio::select! {
                        _ = &mut deadline => break,
                        Some(path) = events.recv() => paths.push(path),
                    }
                }
                for path in paths {
                    let top = root.as_ref().is_some_and(|root| {
                        path.parent() == Some(root.as_path()) || path.parent().and_then(Path::parent) == Some(root.as_path())
                    });
                    if top {
                        // A project or checkout appeared or went: find the set again.
                        rescan = true;
                    } else if relevant(&path) {
                        pending.extend(owners(&path, &watched));
                    }
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use git2::Signature;

    struct Dir(PathBuf);
    impl Dir {
        fn new(name: &str) -> Self {
            let path =
                std::env::temp_dir().join(format!("orbit-checkouts-{name}-{}", std::process::id()));
            let _ = std::fs::remove_dir_all(&path);
            std::fs::create_dir_all(&path).unwrap();
            Self(path)
        }
    }
    impl Drop for Dir {
        fn drop(&mut self) {
            let _ = std::fs::remove_dir_all(&self.0);
        }
    }

    fn commit(repo: &Repository) -> git2::Oid {
        let sig = Signature::now("t", "t@example.com").unwrap();
        let tree = repo
            .find_tree(repo.index().unwrap().write_tree().unwrap())
            .unwrap();
        repo.commit(Some("HEAD"), &sig, &sig, "init", &tree, &[])
            .unwrap()
    }

    #[test]
    fn origins_mask_credentials_as_the_interim_read_does() {
        assert_eq!(
            mask_origin("https://x-token:ghp_secret@github.com/acme/app.git"),
            "https://REDACTED@github.com/acme/app.git"
        );
        assert_eq!(
            mask_origin("https://token@github.com/acme/app.git"),
            "https://REDACTED@github.com/acme/app.git"
        );
        assert_eq!(
            mask_origin("ssh://git:pw@example.com/app.git"),
            "ssh://REDACTED@example.com/app.git"
        );
        assert_eq!(
            mask_origin("ssh://git@example.com/app.git"),
            "ssh://git@example.com/app.git"
        );
        assert_eq!(
            mask_origin("git@github.com:acme/app.git"),
            "git@github.com:acme/app.git"
        );
        assert_eq!(
            mask_origin("https://github.com/acme/app.git"),
            "https://github.com/acme/app.git"
        );
    }

    #[test]
    fn find_lists_project_checkouts_two_levels_down_and_skips_the_rest() {
        let root = Dir::new("find");
        Repository::init(root.0.join("acme/default")).unwrap();
        Repository::init(root.0.join("acme/feature")).unwrap();
        std::fs::create_dir_all(root.0.join("acme/notes")).unwrap();
        std::fs::create_dir_all(root.0.join(".trash/old/.git")).unwrap();
        Repository::init(root.0.join("acme/default/nested/deep")).unwrap();

        assert_eq!(
            find(&root.0),
            vec![root.0.join("acme/default"), root.0.join("acme/feature")]
        );
    }

    #[test]
    fn a_checkout_reports_its_branch_head_origin_worktrees_and_application() {
        crate::workspace::configure_libgit2().unwrap();
        let root = Dir::new("read");
        let path = root.0.join("operator/default");
        let repo = Repository::init(&path).unwrap();
        repo.remote("origin", "https://x:secret@github.com/acme/operator.git")
            .unwrap();
        let head = commit(&repo);
        std::fs::create_dir_all(path.join("server/web")).unwrap();
        std::fs::write(
            path.join("server/web/composer.json"),
            "{\"require\":{\"php\":\"^8.4\"}}",
        )
        .unwrap();
        std::fs::write(path.join("server/web/artisan"), "#!/usr/bin/env php").unwrap();
        let applications = BTreeMap::from([("operator".to_owned(), "server/web".to_owned())]);
        let app = application(&path, &applications);

        let data = read(&path, &app).unwrap();

        let branch = crate::workspace::head_ref(repo.path())
            .unwrap()
            .trim_start_matches("refs/heads/")
            .to_owned();
        assert_eq!(data["branch"], json!(branch));
        assert_eq!(data["head"], json!(head.to_string()));
        assert_eq!(
            data["origin"],
            json!("https://REDACTED@github.com/acme/operator.git")
        );
        assert_eq!(data["linked"], json!(false));
        assert_eq!(
            data["worktrees"],
            json!([text(&std::fs::canonicalize(&path).unwrap())])
        );
        assert_eq!(data["application"], json!(text(&path.join("server/web"))));
        assert_eq!(data["artisan"], json!("regular"));
        assert_eq!(data["composer"], json!("{\"require\":{\"php\":\"^8.4\"}}"));
        assert_eq!(data["default_branch"], Value::Null);
        assert_eq!(data["env"], Value::Null);

        std::fs::write(path.join("server/web/.env"), "APP_KEY=secret").unwrap();
        std::fs::set_permissions(
            path.join("server/web/.env"),
            std::os::unix::fs::PermissionsExt::from_mode(0o640),
        )
        .unwrap();
        let env = &read(&path, &app).unwrap()["env"];
        assert_eq!(env["kind"], json!("regular"));
        assert_eq!(env["mode"], json!("0640"));

        repo.set_head_detached(head).unwrap();
        assert_eq!(read(&path, &app).unwrap()["branch"], Value::Null);
    }

    #[test]
    fn an_application_directory_never_leaves_the_checkout() {
        let checkout = Path::new("/apps/acme/default");
        let unsafe_dirs = BTreeMap::from([("acme".to_owned(), "../etc".to_owned())]);
        assert_eq!(application(checkout, &unsafe_dirs), checkout);
        assert_eq!(application(checkout, &BTreeMap::new()), checkout);
    }

    #[test]
    fn only_git_files_and_application_files_are_relevant() {
        assert!(relevant(Path::new("/apps/acme/default/.git/HEAD")));
        assert!(relevant(Path::new(
            "/apps/acme/default/.git/refs/heads/feature"
        )));
        assert!(relevant(Path::new("/apps/acme/default/composer.json")));
        assert!(relevant(Path::new("/apps/acme/default/.env")));
        assert!(!relevant(Path::new("/apps/acme/default/.git/index")));
        assert!(!relevant(Path::new("/apps/acme/default/README.md")));
    }
}
