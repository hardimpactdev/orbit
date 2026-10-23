//! Checks and steps that the development source commands share. A failed check ends the command with the error that
//! its caller names, through [`require`]. Paths are compared as the shell compared them: `stat`, `test`, and
//! `realpath -e` look at the path itself, and command output loses its trailing newlines.

use std::{
    collections::BTreeMap,
    ffi::{CStr, OsStr, OsString},
    fs,
    io::Write,
    os::unix::{
        ffi::{OsStrExt, OsStringExt},
        fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    },
    path::{Path, PathBuf},
    process::{Command, Output, Stdio},
    time::{Duration, Instant},
};

pub const ZERO_COMMIT: &str = "0000000000000000000000000000000000000000";

/// Ends a command when `condition` is false, and logs the check that failed.
pub fn require<E>(condition: bool, error: impl FnOnce() -> E, check: &str) -> Result<(), E> {
    if condition {
        Ok(())
    } else {
        eprintln!("refused: {check}");
        Err(error())
    }
}

/// What a shell command substitution keeps of `output`: no NUL bytes and no trailing newlines.
pub fn substitution(output: &[u8]) -> Vec<u8> {
    let mut kept: Vec<u8> = output.iter().copied().filter(|byte| *byte != 0).collect();
    while kept.last() == Some(&b'\n') {
        kept.pop();
    }
    kept
}

/// Runs Git without hooks, fsmonitor, or optional locks, with standard input closed.
pub fn git(arguments: &[&OsStr], environment: &BTreeMap<String, String>, quiet: bool) -> Output {
    let mut command = Command::new("git");
    command
        .args([
            "-c",
            "core.hooksPath=/dev/null",
            "-c",
            "core.fsmonitor=false",
        ])
        .args(arguments)
        .env("GIT_OPTIONAL_LOCKS", "0")
        .envs(environment)
        .stdin(Stdio::null())
        .stderr(if quiet {
            Stdio::null()
        } else {
            Stdio::inherit()
        });
    command.output().unwrap_or_else(|error| {
        eprintln!("git: {error}");
        Output {
            status: failed_status(),
            stdout: Vec::new(),
            stderr: Vec::new(),
        }
    })
}

fn failed_status() -> std::process::ExitStatus {
    use std::os::unix::process::ExitStatusExt;
    std::process::ExitStatus::from_raw(1 << 8)
}

/// A shorthand for Git arguments that mix strings and paths.
#[macro_export]
macro_rules! git_args {
    ($($argument:expr),* $(,)?) => { &[$(std::ffi::OsStr::new($argument)),*] };
}

pub fn git_in(directory: &Path, arguments: &[&str], quiet: bool) -> Output {
    let mut all: Vec<&OsStr> = vec![OsStr::new("-C"), directory.as_os_str()];
    all.extend(arguments.iter().map(OsStr::new));
    git(&all, &BTreeMap::new(), quiet)
}

/// Git's output as a command substitution keeps it, or `None` when Git fails.
pub fn git_output(directory: &Path, arguments: &[&str], quiet: bool) -> Option<Vec<u8>> {
    let output = git_in(directory, arguments, quiet);
    output
        .status
        .success()
        .then(|| substitution(&output.stdout))
}

pub fn bytes(path: &Path) -> &[u8] {
    path.as_os_str().as_bytes()
}

pub fn path_from(bytes: Vec<u8>) -> PathBuf {
    PathBuf::from(OsString::from_vec(bytes))
}

pub fn join(path: &Path, suffix: &str) -> PathBuf {
    let mut joined = path.as_os_str().to_os_string();
    joined.push(suffix);
    PathBuf::from(joined)
}

/// `stat -c '%U:%G'` of the path itself, or `""` when it cannot be read.
pub fn owner(path: &Path) -> String {
    let Ok(metadata) = fs::symlink_metadata(path) else {
        return String::new();
    };
    format!(
        "{}:{}",
        user_name(metadata.uid()),
        group_name(metadata.gid())
    )
}

pub fn user_name(uid: u32) -> String {
    // SAFETY: getpwuid returns a pointer into static storage or null; the name is copied at once.
    unsafe {
        let entry = libc::getpwuid(uid);
        if entry.is_null() {
            return "UNKNOWN".into();
        }
        CStr::from_ptr((*entry).pw_name)
            .to_string_lossy()
            .into_owned()
    }
}

pub fn group_name(gid: u32) -> String {
    // SAFETY: getgrgid returns a pointer into static storage or null; the name is copied at once.
    unsafe {
        let entry = libc::getgrgid(gid);
        if entry.is_null() {
            return "UNKNOWN".into();
        }
        CStr::from_ptr((*entry).gr_name)
            .to_string_lossy()
            .into_owned()
    }
}

/// `stat -c '%d:%i'` of the path itself, or `""` when it cannot be read.
pub fn identity(path: &Path) -> String {
    fs::symlink_metadata(path)
        .map(|metadata| format!("{}:{}", metadata.dev(), metadata.ino()))
        .unwrap_or_default()
}

pub fn exists(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}

pub fn is_symlink(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok_and(|metadata| metadata.file_type().is_symlink())
}

/// The path with every link resolved, as `realpath -e` prints it, or `None` when it does not exist.
pub fn realpath(path: &Path) -> Option<PathBuf> {
    fs::canonicalize(path).ok()
}

/// Whether `realpath -e` of the path is the path itself.
pub fn canonical(path: &Path) -> bool {
    fs::canonicalize(path).is_ok_and(|resolved| resolved == path)
}

/// `test -f path && test ! -L path`.
pub fn is_regular_file(path: &Path) -> bool {
    path.is_file() && !is_symlink(path)
}

/// `test -d path && test ! -L path`.
pub fn is_real_directory(path: &Path) -> bool {
    path.is_dir() && !is_symlink(path)
}

/// Whether the file holds exactly `text` and one newline.
pub fn holds(path: &Path, text: &[u8]) -> bool {
    fs::read(path).is_ok_and(|content| {
        content.len() == text.len() + 1 && content.starts_with(text) && content.ends_with(b"\n")
    })
}

pub fn read_text(path: &Path) -> Vec<u8> {
    fs::read(path)
        .map(|content| substitution(&content))
        .unwrap_or_default()
}

/// `dirname` of a path.
pub fn dirname(path: &Path) -> PathBuf {
    let raw = bytes(path);
    let stripped = trim_end(raw, b'/');
    if stripped.is_empty() {
        return PathBuf::from(if raw.starts_with(b"/") { "/" } else { "." });
    }
    let Some(slash) = stripped.iter().rposition(|byte| *byte == b'/') else {
        return PathBuf::from(".");
    };
    let parent = trim_end(&stripped[..slash], b'/');
    if parent.is_empty() {
        PathBuf::from("/")
    } else {
        path_from(parent.to_vec())
    }
}

fn trim_end(value: &[u8], byte: u8) -> &[u8] {
    let mut end = value.len();
    while end > 0 && value[end - 1] == byte {
        end -= 1;
    }
    &value[..end]
}

pub fn starts_inside(path: &Path, root: &Path) -> bool {
    let mut prefix = bytes(root).to_vec();
    prefix.push(b'/');
    bytes(path).starts_with(&prefix)
}

/// Whether no path component after `base` is a symlink. An empty base checks every component from `/`.
pub fn no_symlink_below(base: &Path, path: &Path) -> bool {
    let base_bytes = bytes(base);
    let raw = bytes(path);
    let relative = if base_bytes.is_empty() {
        raw.strip_prefix(b"/").unwrap_or(raw)
    } else if raw.len() > base_bytes.len() {
        &raw[base_bytes.len() + 1..]
    } else {
        b""
    };
    let mut current = base_bytes.to_vec();
    for segment in relative.split(|byte| *byte == b'/') {
        current.push(b'/');
        current.extend_from_slice(segment);
        if is_symlink(&path_from(current.clone())) {
            return false;
        }
    }
    true
}

/// Writes `text` and a newline to a private file in `directory`, then renames it to `path`.
pub fn write_atomically(
    directory: &Path,
    prefix: &str,
    path: &Path,
    text: &[u8],
) -> std::io::Result<()> {
    let candidate = directory.join(format!("{prefix}{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)?;
        file.write_all(text)?;
        file.write_all(b"\n")?;
        file.sync_all()?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o600))?;
        fs::rename(&candidate, path)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    result
}

/// The checkout's `remote.origin.url` without its one trailing newline, or `None` when Git has none.
pub fn origin_url(checkout: &Path) -> Option<String> {
    let output = git_in(checkout, &["config", "--get", "remote.origin.url"], false);
    if !output.status.success() || !output.stdout.ends_with(b"\n") {
        return None;
    }
    let mut value: Vec<u8> = output
        .stdout
        .iter()
        .copied()
        .filter(|byte| *byte != 0)
        .collect();
    value.pop();
    String::from_utf8(value).ok()
}

/// The raw `git worktree list --porcelain -z` output. A failed listing is empty.
pub fn worktree_inventory(checkout: &Path) -> Vec<u8> {
    git_in(checkout, &["worktree", "list", "--porcelain", "-z"], false).stdout
}

/// Whether the checkout has no changes, untracked files included. `None` when Git cannot read its status.
pub fn clean(checkout: &Path) -> Option<bool> {
    let output = git_in(
        checkout,
        &["status", "--porcelain", "--untracked-files=all"],
        false,
    );
    output
        .status
        .success()
        .then(|| trim_end(&output.stdout, b'\n').is_empty())
}

/// Creates a directory that only its owner can read, such as one that holds a private repository's fetched objects.
pub fn private_directory(path: &Path) -> std::io::Result<()> {
    std::os::unix::fs::DirBuilderExt::mode(&mut fs::DirBuilder::new(), 0o700).create(path)
}

/// Whether a current branch or tag of the origin contains the commit. The origin's refs go into a temporary
/// repository outside the checkout, and only the fetch sees the Git read environment. `Err` when a step fails.
pub fn is_published(
    origin: &str,
    commit: &str,
    git_read: &BTreeMap<String, String>,
) -> Result<bool, &'static str> {
    let scratch =
        std::env::temp_dir().join(format!("orbit-publication-{:016x}", rand::random::<u64>()));
    private_directory(&scratch).map_err(|_| "create a scratch directory")?;
    let result = (|| {
        let repository = scratch.join("repository.git");
        let git_dir = join(Path::new("--git-dir="), &repository.display().to_string());
        let none = BTreeMap::new();
        let ok = |arguments: &[&OsStr], environment: &BTreeMap<String, String>| {
            git(arguments, environment, false).status.success()
        };
        if !ok(
            &[
                OsStr::new("init"),
                OsStr::new("--bare"),
                OsStr::new("--quiet"),
                repository.as_os_str(),
            ],
            &none,
        ) {
            return Err("initialize a scratch repository");
        }
        if !ok(
            &[
                git_dir.as_os_str(),
                OsStr::new("remote"),
                OsStr::new("add"),
                OsStr::new("origin"),
                OsStr::new(origin),
            ],
            &none,
        ) {
            return Err("add the origin to the scratch repository");
        }
        let fetch = [
            git_dir.as_os_str(),
            OsStr::new("fetch"),
            OsStr::new("--quiet"),
            OsStr::new("--no-tags"),
            OsStr::new("--filter=blob:none"),
            OsStr::new("origin"),
            OsStr::new("+refs/heads/*:refs/remotes/origin/*"),
            OsStr::new("+refs/tags/*:refs/tags/*"),
        ];
        if !ok(&fetch, git_read) {
            return Err("fetch the origin refs");
        }
        let object = format!("{commit}^{{commit}}");
        if !git(
            &[
                git_dir.as_os_str(),
                OsStr::new("cat-file"),
                OsStr::new("-e"),
                OsStr::new(&object),
            ],
            &none,
            true,
        )
        .status
        .success()
        {
            return Ok(false);
        }
        let listing = git(
            &[
                git_dir.as_os_str(),
                OsStr::new("for-each-ref"),
                OsStr::new("--format=%(refname)"),
                OsStr::new("refs/remotes/origin"),
                OsStr::new("refs/tags"),
            ],
            &none,
            false,
        );
        for advertised in listing
            .stdout
            .split(|byte| *byte == b'\n')
            .filter(|line| !line.is_empty())
        {
            let mut reference = advertised.to_vec();
            reference.extend_from_slice(b"^{commit}");
            let reference = OsString::from_vec(reference);
            let tip = git(
                &[
                    git_dir.as_os_str(),
                    OsStr::new("rev-parse"),
                    OsStr::new("--verify"),
                    &reference,
                ],
                &none,
                true,
            );
            if !tip.status.success() {
                continue;
            }
            let tip = OsString::from_vec(substitution(&tip.stdout));
            if git(
                &[
                    git_dir.as_os_str(),
                    OsStr::new("merge-base"),
                    OsStr::new("--is-ancestor"),
                    OsStr::new(commit),
                    &tip,
                ],
                &none,
                false,
            )
            .status
            .success()
            {
                return Ok(true);
            }
        }
        Ok(false)
    })();
    let _ = fs::remove_dir_all(&scratch);
    result
}

/// The repository identity of an origin: the lowercase host and the path without `.git`, or `None` when the origin
/// is not an SSH shorthand, an `https` URL without credentials, or an `ssh` URL without a password. The Gateway's
/// `GitRepositoryIdentity` gives the same identity; `contract/fixtures/repository-identity.json` holds both sides.
pub fn repository_identity(origin: &str) -> Option<String> {
    if origin.is_empty() || origin.chars().any(separator_or_other) {
        return None;
    }
    let (host, path) = if let Some(rest) = origin.strip_prefix("git@") {
        let (host, path) = rest.split_once(':')?;
        if host.is_empty()
            || path.is_empty()
            || host.contains(['?', '#'])
            || path.contains(['?', '#'])
        {
            return None;
        }
        (host, path)
    } else {
        let (scheme, rest) = origin.split_once("://")?;
        if rest.contains(['?', '#']) {
            return None;
        }
        let (authority, path) = rest
            .find('/')
            .map_or((rest, ""), |slash| rest.split_at(slash));
        let (userinfo, hostport) = match authority.rfind('@') {
            Some(at) => (Some(&authority[..at]), &authority[at + 1..]),
            None => (None, authority),
        };
        match scheme {
            "https" if userinfo.is_none() => {}
            "ssh" if userinfo.is_none_or(|userinfo| !userinfo.contains(':')) => {}
            _ => return None,
        }
        let host = strip_port(hostport)?;
        if host.is_empty() || path.is_empty() {
            return None;
        }
        (host, path)
    };
    let mut path = path.trim_matches('/');
    if let Some(stripped) = path.strip_suffix(".git") {
        path = stripped;
    }
    Some(format!(
        "{}/{}",
        host.to_ascii_lowercase(),
        path.trim_end_matches('/')
    ))
}

/// The host without its port, or `None` when the port is invalid. This follows PHP's `parse_url`, which both PHP
/// rules use: the port follows the last `:` after any `]`, and it is empty, or at most five characters that start like
/// a decimal number from 0 to 65535. Characters after the number are ignored.
fn strip_port(hostport: &str) -> Option<&str> {
    let after_bracket = hostport.rfind(']').map_or(0, |end| end + 1);
    let Some(colon) = hostport[after_bracket..]
        .rfind(':')
        .map(|index| index + after_bracket)
    else {
        return Some(hostport);
    };
    let port = &hostport[colon + 1..];
    (port.is_empty() || php_port(port)).then_some(&hostport[..colon])
}

/// Whether `parse_url` accepts a port: what `strtol` reads from it must have a digit and lie in 0 to 65535.
fn php_port(port: &str) -> bool {
    if port.len() > 5 {
        return false;
    }
    let (negative, unsigned) = match port.as_bytes().first() {
        Some(b'+') => (false, &port[1..]),
        Some(b'-') => (true, &port[1..]),
        _ => (false, port),
    };
    let digits = unsigned.bytes().take_while(u8::is_ascii_digit).count();
    let value: u32 = match unsigned[..digits].parse() {
        Ok(value) => value,
        Err(_) => return false,
    };
    value <= 65535 && (!negative || value == 0)
}

/// Unicode separators (`\p{Z}`) and other characters (`\p{C}`), unassigned code points included, as PHP matches them.
fn separator_or_other(character: char) -> bool {
    static PATTERN: std::sync::OnceLock<regex::Regex> = std::sync::OnceLock::new();
    PATTERN
        .get_or_init(|| regex::Regex::new(r"[\p{Z}\p{C}]").unwrap())
        .is_match(character.encode_utf8(&mut [0; 4]))
}

/// The systemd unit of a process: the last part of its cgroup path.
fn process_unit(pid: &str) -> Vec<u8> {
    let cgroup = read_text(&Path::new("/proc").join(pid).join("cgroup"));
    cgroup
        .rsplit(|byte| *byte == b'/')
        .next()
        .unwrap_or_default()
        .to_vec()
}

/// This account's processes whose working directory or executable is inside the tree, without the processes of the
/// units in `own_units` and without a tmux or screen server, which hosts sessions that work elsewhere.
pub fn tree_processes(tree: &Path, own_units: &[String]) -> Vec<i32> {
    let uid = rustix::process::geteuid().as_raw();
    let me = std::process::id().to_string();
    let tree_bytes = bytes(tree);
    let mut inside = tree_bytes.to_vec();
    inside.push(b'/');
    let mut deleted = tree_bytes.to_vec();
    deleted.extend_from_slice(b" (deleted)");
    let mut found = Vec::new();
    let Ok(entries) = fs::read_dir("/proc") else {
        return found;
    };
    for entry in entries.flatten() {
        let name = entry.file_name();
        let Some(pid) = name
            .to_str()
            .filter(|name| name.bytes().all(|byte| byte.is_ascii_digit()) && *name != me)
        else {
            continue;
        };
        for link in ["cwd", "exe"] {
            let path = Path::new("/proc").join(pid).join(link);
            let Ok(metadata) = fs::symlink_metadata(&path) else {
                continue;
            };
            if metadata.uid() != uid {
                continue;
            }
            let Ok(target) = fs::read_link(&path) else {
                continue;
            };
            let target = bytes(&target);
            if target != tree_bytes && !target.starts_with(&inside) && target != deleted.as_slice()
            {
                continue;
            }
            let unit = process_unit(pid);
            if !unit.is_empty()
                && own_units
                    .iter()
                    .any(|own| own.as_bytes() == unit.as_slice())
            {
                continue;
            }
            let command = read_text(&Path::new("/proc").join(pid).join("comm"));
            if [b"tmux: server".as_slice(), b"screen", b"SCREEN"].contains(&command.as_slice()) {
                continue;
            }
            if let Ok(pid) = pid.parse() {
                found.push(pid);
                break;
            }
        }
    }
    found
}

/// Stops the tree's processes: SIGTERM, then SIGKILL, each for up to 5 seconds. Returns whether they stopped.
pub fn stop_tree_processes(tree: &Path, own_units: &[String]) -> bool {
    for signal in [libc::SIGTERM, libc::SIGKILL] {
        let started = Instant::now();
        loop {
            let processes = tree_processes(tree, own_units);
            if processes.is_empty() {
                return true;
            }
            for pid in processes {
                // SAFETY: kill with a pid and a signal number has no memory effects.
                unsafe { libc::kill(pid, signal) };
            }
            if started.elapsed() >= Duration::from_secs(5) {
                break;
            }
            std::thread::sleep(Duration::from_millis(100));
        }
    }
    eprintln!("Processes inside {} did not stop.", tree.display());
    false
}

/// Deletes the tree. A linked worktree is removed through its common repository, which keeps its branch.
pub fn remove_source_tree(tree: &Path, common_repository: Option<&Path>) -> bool {
    match common_repository {
        Some(common) => {
            let git_dir = join(
                Path::new("--git-dir="),
                &join(common, "/.git").display().to_string(),
            );
            git(
                &[
                    git_dir.as_os_str(),
                    OsStr::new("worktree"),
                    OsStr::new("remove"),
                    OsStr::new("--force"),
                    tree.as_os_str(),
                ],
                &BTreeMap::new(),
                false,
            )
            .status
            .success()
        }
        None => fs::remove_dir_all(tree).is_ok() || !exists(tree),
    }
}

/// Removes the checkout's parent, the Project's directory, when the account owns it and it is empty.
pub fn release_empty_grouping_directory<E>(
    checkout: &Path,
    root: &Path,
    grouping: &Path,
    account: &str,
    error: impl Fn() -> E,
) -> Result<(), E> {
    require(!exists(checkout), &error, "the checkout is gone")?;
    require(
        grouping != root,
        &error,
        "the grouping directory is not the apps root",
    )?;
    require(
        grouping == dirname(checkout),
        &error,
        "the grouping directory holds the checkout",
    )?;
    require(
        starts_inside(grouping, root),
        &error,
        "the grouping directory is inside the apps root",
    )?;
    if !exists(grouping) || is_symlink(grouping) || !grouping.is_dir() {
        return Ok(());
    }
    if !canonical(grouping) || owner(grouping) != account {
        return Ok(());
    }
    if fs::read_dir(grouping).is_ok_and(|mut entries| entries.next().is_some()) {
        return Ok(());
    }
    fs::remove_dir(grouping).map_err(|failure| {
        eprintln!("rmdir {}: {failure}", grouping.display());
        error()
    })
}

/// The removal state directory and this member's journal, receipt, quarantine, and recovery paths.
pub struct RemovalPaths {
    pub state: PathBuf,
    pub journal: PathBuf,
    pub receipt: PathBuf,
    pub quarantine: PathBuf,
    pub recovery: PathBuf,
}

pub fn removal_paths(root: &Path, operation: &str, member: &str) -> RemovalPaths {
    let state = join(root, "/.orbit-removals");
    let prefix = join(&state, &format!("/{operation}.{member}"));
    RemovalPaths {
        journal: join(&prefix, ".journal"),
        receipt: join(&prefix, ".receipt"),
        quarantine: join(&prefix, ".quarantine"),
        recovery: join(&prefix, ".recovery"),
        state,
    }
}

/// The state directory and the member's journal exist, belong to the account, and the journal holds the digest.
pub fn require_state<E>(
    paths: &RemovalPaths,
    digest: &str,
    account: &str,
    error: impl Fn() -> E,
) -> Result<(), E> {
    require(
        is_real_directory(&paths.state) && canonical(&paths.state),
        &error,
        "the removal state directory",
    )?;
    require(
        owner(&paths.state) == account,
        &error,
        "the removal state directory owner",
    )?;
    require(
        is_regular_file(&paths.journal),
        &error,
        "the removal journal",
    )?;
    require(
        owner(&paths.journal) == account,
        &error,
        "the removal journal owner",
    )?;
    require(
        holds(&paths.journal, digest.as_bytes()),
        &error,
        "the removal journal digest",
    )
}

/// The worktree recovery record: its administration directory and three identities, read and checked.
pub struct Recovery {
    pub admin: PathBuf,
    pub admin_identity: String,
    pub common_identity: String,
    pub worktrees_identity: String,
}

pub fn recovery_record<E>(
    recovery: &Path,
    common_repository: &Path,
    error: impl Fn() -> E,
) -> Result<Recovery, E> {
    use base64::Engine;
    let content = fs::read(recovery).ok();
    require(content.is_some(), &error, "read the recovery record")?;
    let content = content.unwrap();
    let mut fields: Vec<&[u8]> = content.split(|byte| *byte == b'\n').collect();
    if fields.last() == Some(&b"".as_slice()) {
        fields.pop();
    }
    require(
        fields.len() == 4,
        &error,
        "the recovery record has four fields",
    )?;
    let decoded = base64::engine::general_purpose::STANDARD
        .decode(fields[0])
        .ok();
    require(
        decoded.is_some(),
        &error,
        "decode the administration directory",
    )?;
    let admin = substitution(&decoded.unwrap());
    require(
        base64::engine::general_purpose::STANDARD
            .encode(&admin)
            .as_bytes()
            == fields[0],
        &error,
        "the administration directory encoding",
    )?;
    let text = |field: &[u8]| String::from_utf8_lossy(field).into_owned();
    let (admin_identity, common_identity, worktrees_identity) =
        (text(fields[1]), text(fields[2]), text(fields[3]));
    for value in [&admin_identity, &common_identity, &worktrees_identity] {
        let valid = value.split_once(':').is_some_and(|(device, inode)| {
            !device.is_empty()
                && !inode.is_empty()
                && device
                    .bytes()
                    .chain(inode.bytes())
                    .all(|byte| byte.is_ascii_digit())
        });
        require(valid, &error, "a recorded identity")?;
    }
    let admin = path_from(admin);
    require(
        dirname(&admin) == join(common_repository, "/.git/worktrees"),
        &error,
        "the administration directory location",
    )?;
    Ok(Recovery {
        admin,
        admin_identity,
        common_identity,
        worktrees_identity,
    })
}

/// How many Git worktree entries point at the checkout or its quarantine. Each one must be `admin`.
pub fn worktree_entries<E>(
    worktrees: &Path,
    checkout: &Path,
    quarantine: &Path,
    admin: &Path,
    error: impl Fn() -> E,
) -> Result<usize, E> {
    let mut names: Vec<OsString> = fs::read_dir(worktrees)
        .map(|entries| {
            entries
                .flatten()
                .map(|entry| entry.file_name())
                .filter(|name| !name.as_bytes().starts_with(b"."))
                .collect()
        })
        .unwrap_or_default();
    names.sort();
    let mut matching = 0;
    for name in names {
        let candidate = worktrees.join(&name);
        let gitdir = candidate.join("gitdir");
        if is_regular_file(&gitdir)
            && (holds(&gitdir, bytes(&join(checkout, "/.git")))
                || holds(&gitdir, bytes(&join(quarantine, "/.git"))))
        {
            require(
                candidate == admin,
                &error,
                "one worktree entry for the source",
            )?;
            matching += 1;
        }
    }
    require(
        matching <= 1,
        &error,
        "at most one worktree entry for the source",
    )?;
    Ok(matching)
}

/// Base64 of bytes, as the inspection result carries every value.
pub fn encoded(value: &[u8]) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(value)
}

#[cfg(test)]
mod tests {
    #[test]
    fn a_private_directory_is_readable_only_by_its_owner() {
        use std::os::unix::fs::PermissionsExt;
        let path =
            std::env::temp_dir().join(format!("orbit-private-{:016x}", rand::random::<u64>()));
        private_directory(&path).unwrap();
        let mode = fs::metadata(&path).unwrap().permissions().mode() & 0o777;
        fs::remove_dir(&path).unwrap();
        assert_eq!(mode, 0o700);
    }

    use super::*;

    #[test]
    fn substitution_drops_nul_bytes_and_trailing_newlines() {
        assert_eq!(substitution(b"a\0b\n\n"), b"ab");
        assert_eq!(substitution(b"\n"), b"");
    }

    #[test]
    fn dirname_matches_the_shell() {
        for (path, parent) in [
            ("/a/b", "/a"),
            ("/a/b/", "/a"),
            ("/a", "/"),
            ("a", "."),
            ("/", "/"),
            ("//a//b//", "//a"),
        ] {
            assert_eq!(dirname(Path::new(path)), PathBuf::from(parent), "{path}");
        }
    }

    #[test]
    fn repository_identity_follows_the_shared_cases() {
        let cases: serde_json::Value = serde_json::from_str(include_str!(
            "../../contract/fixtures/repository-identity.json"
        ))
        .unwrap();
        for case in cases.as_array().unwrap() {
            let origin = case["origin"].as_str().unwrap();
            let node = case["node"].as_str();
            assert_eq!(repository_identity(origin).as_deref(), node, "{origin}");
        }
    }
}
