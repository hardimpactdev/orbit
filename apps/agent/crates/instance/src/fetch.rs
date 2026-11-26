//! `instance.fetch_branch`: the Instance's branch in the Node's mirror of its repository, when the origin has it.

use std::os::unix::fs::{OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};

use serde::Deserialize;
use serde_json::Value;

use crate::{Failure, System};

const GIT: &str = "/usr/bin/git";
/// Where the mirrors live; a mirror path must be a `<id>.git` folder directly inside it.
const MIRRORS: &str = "/var/lib/orbit/instance-repositories";
/// A remote read's own bound, inside the step's budget.
const REMOTE_SECONDS: u32 = 80;

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct FetchInput {
    mirror: String,
    repository: String,
    branch: String,
    /// The one protocol Git may use, `https`.
    protocol: String,
    /// The `store` credential line for the repository's host: a secret, so a blob.
    credential: crate::BlobRef,
    /// A task copy's sync (feature `instance.fetch_seed`): a missing mirror is made from this mirror of the default, as the
    /// Swamp base phase makes it, and refused when this is empty. Without it a missing mirror starts empty.
    #[serde(default)]
    seed: Option<String>,
}

/// A credential file older than this is left from a stopped fetch: no fetch lives this long.
const STALE_SECONDS: u64 = 600;

/// Asks the origin for the branch; when it has it, fetches it into the mirror, made bare on first use. A branch the origin
/// lacks starts at the seed's commit, so nothing is fetched. Answers `{"commit": <the fetched commit, or null>}`.
///
/// The credential is written root-only beside the mirror for the two Git calls and removed after them, whatever they did.
///
/// # Errors
///
/// `instance.fetch_input_invalid`, `instance.fetch_credential_missing` or `instance.branch_fetch_failed`.
pub fn fetch_branch(system: &impl System, input: &Value) -> Result<String, Failure> {
    let input: FetchInput =
        serde_json::from_value(input.clone()).map_err(|error| Failure::new("instance.fetch_input_invalid", error.to_string()))?;
    check(&input).map_err(|detail| Failure::new("instance.fetch_input_invalid", detail))?;
    let line = system.blob(&input.credential.blob.sha256).map_err(|detail| Failure::new("instance.fetch_credential_missing", detail))?;
    let mirror = system.path(&input.mirror);
    let folder = mirror.parent().map_or_else(|| PathBuf::from(MIRRORS), Path::to_path_buf);
    std::fs::create_dir_all(&folder).and_then(|()| std::fs::set_permissions(&folder, std::fs::Permissions::from_mode(0o700))).map_err(failed)?;
    // Fetches of sibling Instances run side by side: each has its own file, and only a file a stopped fetch left goes.
    if let Ok(entries) = std::fs::read_dir(&folder) {
        for entry in entries.flatten() {
            let stale = entry.metadata().and_then(|meta| meta.modified()).ok().and_then(|at| at.elapsed().ok())
                .is_some_and(|age| age.as_secs() > STALE_SECONDS);
            if stale && entry.file_name().to_string_lossy().starts_with(".credential-") {
                let _ = std::fs::remove_file(entry.path());
            }
        }
    }
    seed(system, &input, &mirror)?;
    let credential = folder.join(format!(".credential-{}-{}", std::process::id(), unique()));
    let written = std::fs::File::options()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&credential)
        .and_then(|mut file| std::io::Write::write_all(&mut file, &line));
    let result = written.map_err(failed).and_then(|()| fetch(system, &input, &mirror, &credential));
    let _ = std::fs::remove_file(&credential);
    result.map(|commit| serde_json::json!({"commit": commit}).to_string())
}

/// A name no other fetch of this executor uses: the clock and a counter.
fn unique() -> String {
    static NEXT: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
    let nanos = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |at| at.as_nanos());
    format!("{nanos}-{}", NEXT.fetch_add(1, std::sync::atomic::Ordering::Relaxed))
}

/// Where a sync seeds the copy's missing mirror before it takes the mirror's name: one fixed path per copy, so the next attempt,
/// the copy's sync cleanup and its destroy each find what a stopped seed left.
pub(crate) fn seeding(mirror: &str) -> String {
    format!("{mirror}.seeding")
}

/// Whether a Node path is there: only a confirmed missing path is absent.
fn present(path: &Path) -> Result<bool, Failure> {
    match std::fs::symlink_metadata(path) {
        Ok(_) => Ok(true),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(false),
        Err(error) => Err(Failure::new("instance.branch_fetch_failed", format!("read {}: {error}", path.display()))),
    }
}

/// A sync's missing mirror: made bare from the default's mirror, which holds the seed's commit, or refused without one. A mirror is
/// there only with its `HEAD`, as the Swamp base phase reads it; a folder without one is no mirror. The seed fills the copy's fixed
/// staging repository, which takes the mirror's name only once complete, so a seed that failed or stopped never passes for a mirror.
fn seed(system: &impl System, input: &FetchInput, mirror: &Path) -> Result<(), Failure> {
    let Some(source) = &input.seed else { return Ok(()) };
    let failed = |detail: String| Failure::new("instance.branch_fetch_failed", format!("seed: {detail}"));
    if present(&mirror.join("HEAD"))? {
        return Ok(());
    }
    if source.is_empty() {
        return Err(Failure::new("instance.branch_fetch_failed", "the mirror is missing and the default has none"));
    }
    let source = system.path(source).display().to_string();
    let scratch = PathBuf::from(seeding(&mirror.display().to_string()));
    for leftover in [&scratch, &mirror.to_path_buf()] {
        if present(leftover)? {
            std::fs::remove_dir_all(leftover).map_err(|error| failed(error.to_string()))?;
        }
    }
    let target = scratch.display().to_string();
    let filled = [
        vec![GIT, "init", "--quiet", "--bare", &target],
        vec![GIT, "-c", "core.hooksPath=/dev/null", "-c", "core.fsmonitor=false", "-C", &target, "fetch", "--quiet", "--no-tags", "--", &source, "+refs/heads/*:refs/seed/*"],
    ]
    .iter()
    .try_for_each(|argv| system.run(REMOTE_SECONDS, &argv.iter().map(|part| (*part).to_owned()).collect::<Vec<_>>(), None).map(drop).map_err(failed))
    .and_then(|()| std::fs::rename(&scratch, mirror).map_err(|error| failed(error.to_string())));
    if filled.is_err() {
        let _ = std::fs::remove_dir_all(&scratch);
    }
    filled
}

fn failed(error: impl std::fmt::Display) -> Failure {
    Failure::new("instance.branch_fetch_failed", error.to_string())
}

fn check(input: &FetchInput) -> Result<(), String> {
    if !mirror_path(&input.mirror) {
        return Err("mirror is not an Orbit mirror".to_owned());
    }
    if input.protocol != "https" || !input.repository.starts_with("https://") || input.repository.bytes().any(|byte| byte.is_ascii_whitespace() || byte.is_ascii_control()) {
        return Err("repository is not an https URL".to_owned());
    }
    if !branch_name(&input.branch) {
        return Err("branch is not a branch name".to_owned());
    }
    if input.seed.as_deref().is_some_and(|seed| !seed.is_empty() && (!mirror_path(seed) || seed == input.mirror)) {
        return Err("seed is not another Orbit mirror".to_owned());
    }
    Ok(())
}

/// An Orbit mirror: a `<id>.git` folder directly inside the mirrors folder.
pub(crate) fn mirror_path(mirror: &str) -> bool {
    mirror
        .strip_prefix(MIRRORS)
        .and_then(|rest| rest.strip_prefix('/'))
        .and_then(|rest| rest.strip_suffix(".git"))
        .is_some_and(|id| !id.is_empty() && id.bytes().all(|byte| byte.is_ascii_digit()))
}

/// A branch name Git accepts that cannot read as an option or leave `refs/heads`.
pub(crate) fn branch_name(branch: &str) -> bool {
    !branch.is_empty()
        && !branch.starts_with(['-', '/', '.'])
        && !branch.ends_with(['/', '.'])
        && !std::path::Path::new(branch).extension().is_some_and(|extension| extension.eq_ignore_ascii_case("lock"))
        && !branch.contains("..")
        && !branch.contains("//")
        && !branch.contains("@{")
        && !branch.bytes().any(|byte| byte.is_ascii_control() || b" ~^:?*[\\".contains(&byte))
}

fn fetch(system: &impl System, input: &FetchInput, mirror: &Path, credential: &Path) -> Result<Option<String>, Failure> {
    let reference = format!("refs/heads/{}", input.branch);
    let remote = |mirror: Option<&Path>, rest: &[&str]| -> Vec<String> {
        let mut argv: Vec<String> = ["/usr/bin/env", "GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL=/dev/null", "GIT_TERMINAL_PROMPT=0", GIT]
            .iter()
            .map(|part| (*part).to_owned())
            .collect();
        argv.extend(["-c".to_owned(), "core.hooksPath=/dev/null".to_owned(), "-c".to_owned(), "core.fsmonitor=false".to_owned()]);
        if let Some(mirror) = mirror {
            argv.extend(["-C".to_owned(), mirror.display().to_string()]);
        }
        for setting in [
            "credential.helper=".to_owned(),
            format!("credential.helper=store --file={}", credential.display()),
            "http.followRedirects=false".to_owned(),
            "protocol.allow=never".to_owned(),
            format!("protocol.{}.allow=always", input.protocol),
        ] {
            argv.extend(["-c".to_owned(), setting]);
        }
        argv.extend(rest.iter().map(|part| (*part).to_owned()));
        argv
    };
    let heads = system
        .run(REMOTE_SECONDS, &remote(None, &["ls-remote", "--heads", "--", &input.repository, &reference]), None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("ls-remote: {detail}")))?;
    if heads.trim().is_empty() {
        return Ok(None);
    }
    if !mirror.join("HEAD").exists() {
        system
            .run(30, &[GIT.to_owned(), "init".to_owned(), "--quiet".to_owned(), "--bare".to_owned(), mirror.display().to_string()], None)
            .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("init: {detail}")))?;
    }
    let refspec = format!("+{reference}:{reference}");
    system
        .run(REMOTE_SECONDS, &remote(Some(mirror), &["fetch", "--quiet", "--no-tags", "--", &input.repository, &refspec]), None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("fetch: {detail}")))?;
    let commit = system
        .run(30, &[GIT.to_owned(), "-C".to_owned(), mirror.display().to_string(), "rev-parse".to_owned(), "--verify".to_owned(), format!("{reference}^{{commit}}")], None)
        .map_err(|detail| Failure::new("instance.branch_fetch_failed", format!("rev-parse: {detail}")))?;
    Ok(Some(commit.trim().to_owned()))
}

#[cfg(test)]
mod seed_tests {
    use super::*;

    /// Git for real, with every Orbit path inside a scratch folder.
    struct Real(tempfile::TempDir);

    impl System for Real {
        fn run(&self, _seconds: u32, argv: &[String], _stdin: Option<&[u8]>) -> Result<String, String> {
            let out = std::process::Command::new(&argv[0]).args(&argv[1..]).env("GIT_CONFIG_GLOBAL", "/dev/null").output().map_err(|error| error.to_string())?;
            if out.status.success() { Ok(String::from_utf8_lossy(&out.stdout).into_owned()) } else { Err(String::from_utf8_lossy(&out.stderr).into_owned()) }
        }
        fn blob(&self, _sha256: &str) -> Result<Vec<u8>, String> {
            Err("no blob".to_owned())
        }
        fn read(&self, path: &str) -> Result<Vec<u8>, String> {
            Err(format!("{path} not here"))
        }
        fn path(&self, value: &str) -> PathBuf {
            self.0.path().join(value.trim_start_matches('/'))
        }
    }

    fn input(seed: &str) -> FetchInput {
        FetchInput { mirror: format!("{MIRRORS}/12.git"), repository: "https://github.com/acme/app.git".to_owned(), branch: "main".to_owned(),
            protocol: "https".to_owned(), credential: crate::BlobRef { blob: crate::BlobDigest { sha256: "c".to_owned() } }, seed: Some(seed.to_owned()) }
    }

    #[test]
    fn a_failed_seed_leaves_no_mirror_for_the_retry_and_a_seed_that_completes_takes_its_name() {
        let real = Real(tempfile::tempdir().expect("scratch"));
        let folder = real.path(MIRRORS);
        std::fs::create_dir_all(&folder).expect("folder");
        let mirror = real.path(&format!("{MIRRORS}/12.git"));

        for _ in 0..2 {
            assert_eq!(seed(&real, &input(&format!("{MIRRORS}/2.git")), &mirror).map_err(|failure| failure.code), Err("instance.branch_fetch_failed"));
            assert!(!mirror.exists(), "no half-seeded mirror passes for a mirror");
            assert_eq!(std::fs::read_dir(&folder).expect("folder").count(), 0, "no seed scratch stays");
        }

        let source = real.path(&format!("{MIRRORS}/2.git"));
        let work = real.0.path().join("work");
        real.run(5, &[GIT, "init", "--quiet", "-b", "main", work.to_str().expect("path")].map(str::to_owned), None).expect("init");
        let git = |args: &[&str]| real.run(5, &[GIT, "-c", "user.name=T", "-c", "user.email=t@t", "-C", work.to_str().expect("path")].iter().chain(args).map(|part| (*part).to_owned()).collect::<Vec<_>>(), None);
        git(&["commit", "--quiet", "--allow-empty", "-m", "seed"]).expect("commit");
        real.run(5, &[GIT, "clone", "--quiet", "--bare", work.to_str().expect("path"), source.to_str().expect("path")].map(str::to_owned), None).expect("source");

        // A folder without `HEAD` is no mirror, and a stopped seed left its staging repository: the next attempt seeds anew.
        std::fs::create_dir_all(&mirror).expect("empty folder");
        assert_eq!(seed(&real, &input(""), &mirror).map_err(|failure| failure.code), Err("instance.branch_fetch_failed"), "no source: refused");
        let staging = PathBuf::from(seeding(&mirror.display().to_string()));
        real.run(5, &[GIT, "init", "--quiet", "--bare", staging.to_str().expect("path")].map(str::to_owned), None).expect("stopped seed");

        seed(&real, &input(&format!("{MIRRORS}/2.git")), &mirror).expect("seeded");
        let refs = real.run(5, &[GIT, "-C", mirror.to_str().expect("path"), "for-each-ref", "refs/seed"].map(str::to_owned), None).expect("refs");
        assert!(refs.contains("refs/seed/main"), "{refs}");
        assert!(!staging.exists(), "the staging repository took the mirror's name");
        let names: Vec<String> = std::fs::read_dir(&folder).expect("folder").flatten().map(|entry| entry.file_name().to_string_lossy().into_owned()).collect();
        assert_eq!(names.iter().filter(|name| name.starts_with("12.git")).count(), 1, "{names:?}");
    }
}
