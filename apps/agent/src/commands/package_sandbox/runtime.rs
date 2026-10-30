//! Package code sees a private filesystem, UID and cgroup, never the executing account.
use super::{check_account, directory, Account, Accounts, Native, STATE, USER};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    io::Read,
    os::unix::fs::{symlink, DirBuilderExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};
mod files;
mod network;
const MAX_BYTES: usize = 64 * 1024 * 1024;
const MAX_OUTPUT: u64 = 8 * 1024 * 1024;
command_errors! { pub enum Error {
 Invalid {} => "package.input_invalid", "The package job input is outside its fixed contract.";
 Unavailable {} => "package.isolation_unavailable", "The package identity, runtime or filesystem isolation is unavailable.";
 Failed {} => "package.sandbox_failed", "The isolated package command did not produce a bounded result.";
}}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum Input {
    Open {
        job: String,
    },
    Run {
        job: String,
        tool: Tool,
        cwd: String,
        args: Vec<String>,
    },
    Close {
        job: String,
    },
    Cancel {
        job: String,
    },
}
#[derive(schemars::JsonSchema, Deserialize, Debug)]
#[serde(rename_all = "snake_case")]
pub enum Tool {
    Composer,
    Npm,
    Bun,
    Vite,
    Node,
    ExpoCatalog,
}
impl Tool {
    fn executable(&self, cwd: &str) -> String {
        match self {
            Self::Composer => "/usr/bin/composer".into(),
            Self::Npm => "/opt/package-runtime/node".into(),
            Self::Bun => "/opt/package-runtime/bun".into(),
            Self::Node => "/opt/package-runtime/node".into(),
            Self::ExpoCatalog => "/usr/bin/python3".into(),
            Self::Vite => format!("/work/{cwd}/node_modules/.bin/vp"),
        }
    }
}
#[derive(schemars::JsonSchema, Serialize, Debug)]
#[serde(tag = "status", rename_all = "snake_case")]
pub enum Output {
    Opened {
        job: String,
    },
    Executed {
        code: i32,
        stdout: String,
        stderr: String,
        files: BTreeMap<String, String>,
    },
    Closed {},
    Cancelled {},
}
fn account() -> Result<Account, Error> {
    let package = Native.lookup(USER).ok_or(Error::Unavailable {})?;
    let root = Native.lookup("root").ok_or(Error::Unavailable {})?;
    check_account(&package, &root).map_err(|_| Error::Unavailable {})?;
    for owner in ["orbit", "orbit-swamp-worker"] {
        if let Some(owner) = Native.lookup(owner) {
            check_account(&package, &owner).map_err(|_| Error::Unavailable {})?;
        }
    }
    // Refuse a manually aliased account rather than giving project code the UID
    // or primary group of another local owner. useradd allocates distinct IDs.
    let passwd = fs::read_to_string("/etc/passwd").map_err(|_| Error::Unavailable {})?;
    for line in passwd.lines() {
        let fields: Vec<_> = line.split(':').collect();
        if fields.len() >= 4
            && fields[0] != USER
            && (fields[2].parse::<u32>().ok() == Some(package.uid)
                || fields[3].parse::<u32>().ok() == Some(package.gid))
        {
            return Err(Error::Unavailable {});
        }
    }
    directory(Path::new("/opt/orbit/swamp-package-runtime"), 0, false)
        .map_err(|_| Error::Unavailable {})?;
    Ok(package)
}
fn relative(value: &str, dot: bool) -> bool {
    (dot && value == ".")
        || (!value.is_empty()
            && value.len() <= 1024
            && !value
                .split('/')
                .any(|s| s.is_empty() || s == "." || s == "..")
            && !value.contains(['\\', '\0', '\n', ':'])
            && Path::new(value)
                .components()
                .all(|c| matches!(c, Component::Normal(_))))
}
fn manifest(value: &str) -> bool {
    relative(value, false)
        && !value.split('/').any(|s| s == "node_modules" || s == ".git")
        && matches!(
            Path::new(value).file_name().and_then(|v| v.to_str()),
            Some(
                "package.json"
                    | "package-lock.json"
                    | "npm-shrinkwrap.json"
                    | "bun.lock"
                    | "composer.json"
                    | "composer.lock"
                    | "pnpm-workspace.yaml"
                    | "pnpm-lock.yaml"
            )
        )
}
fn valid_id(id: &str) -> bool {
    id.len() == 32
        && id
            .bytes()
            .all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase())
}
fn job_path(root: &Path, id: &str) -> Result<PathBuf, Error> {
    if !valid_id(id) {
        return Err(Error::Invalid {});
    }
    let path = root.join(id);
    directory(&path, 0, true).map_err(|_| Error::Unavailable {})?;
    Ok(path)
}
fn mkdir(path: &Path, account: Option<&Account>, mode: u32) -> Result<(), Error> {
    fs::DirBuilder::new()
        .mode(mode)
        .create(path)
        .map_err(|_| Error::Failed {})?;
    if let Some(a) = account {
        rustix::fs::chown(
            path,
            Some(rustix::fs::Uid::from_raw(a.uid)),
            Some(rustix::fs::Gid::from_raw(a.gid)),
        )
        .map_err(|_| Error::Failed {})?;
    }
    Ok(())
}
fn stage(root: &Path, files: &BTreeMap<String, String>, a: &Account) -> Result<(), Error> {
    if files.is_empty()
        || files.len() > 512
        || files
            .iter()
            .any(|(p, v)| !manifest(p) || v.len() > MAX_BYTES)
        || files.values().map(String::len).sum::<usize>() > MAX_BYTES
    {
        return Err(Error::Invalid {});
    }
    for path in ["work", "home", "tmp", "cache"] {
        mkdir(&root.join(path), Some(a), 0o700)?;
    }
    for path in ["usr", "etc", "dev", "proc", "sys", "run", "opt"] {
        mkdir(&root.join(path), None, 0o755)?;
    }
    for (name, target) in [
        ("bin", "usr/bin"),
        ("sbin", "usr/sbin"),
        ("lib", "usr/lib"),
        ("lib64", "usr/lib64"),
    ] {
        symlink(target, root.join(name)).map_err(|_| Error::Failed {})?;
    }
    fs::write(
        root.join("etc/passwd"),
        format!(
            "{USER}:x:{}:{}:Package job:/home:/usr/sbin/nologin\n",
            a.uid, a.gid
        ),
    )
    .map_err(|_| Error::Failed {})?;
    fs::write(root.join("etc/group"), format!("{USER}:x:{}:\n", a.gid))
        .map_err(|_| Error::Failed {})?;
    fs::write(
        root.join("etc/nsswitch.conf"),
        "passwd: files\ngroup: files\nhosts: files dns\n",
    )
    .map_err(|_| Error::Failed {})?;
    // Public DNS only. No private-address exception may turn a fleet endpoint
    // into an alternate source of repository or issuer credentials.
    fs::write(
        root.join("etc/resolv.conf"),
        "nameserver 1.1.1.1\nnameserver 8.8.8.8\noptions timeout:2 attempts:2\n",
    )
    .map_err(|_| Error::Failed {})?;
    let php = fs::canonicalize("/usr/bin/php").map_err(|_| Error::Unavailable {})?;
    if !php.starts_with("/usr/bin") {
        return Err(Error::Unavailable {});
    }
    fs::remove_file(root.join("bin")).map_err(|_| Error::Failed {})?;
    symlink("usr/bin", root.join("bin")).map_err(|_| Error::Failed {})?;
    mkdir(&root.join("etc/alternatives"), None, 0o755)?;
    symlink(php, root.join("etc/alternatives/php")).map_err(|_| Error::Failed {})?;
    update_files(root, files, a)?;
    Ok(())
}
fn update_files(root: &Path, files: &BTreeMap<String, String>, a: &Account) -> Result<(), Error> {
    if files.is_empty()
        || files.len() > 512
        || files
            .iter()
            .any(|(p, v)| !manifest(p) || v.len() > MAX_BYTES)
        || files.values().map(String::len).sum::<usize>() > MAX_BYTES
    {
        return Err(Error::Invalid {});
    }
    for (path, content) in files {
        self::files::write(root, path, content, a)?;
    }
    Ok(())
}

fn parent_unit() -> Result<String, Error> {
    let membership = fs::read_to_string("/proc/self/cgroup").map_err(|_| Error::Unavailable {})?;
    let unit = membership
        .lines()
        .find_map(|line| line.strip_prefix("0::/system.slice/"))
        .ok_or(Error::Unavailable {})?;
    if !unit.starts_with("orbit-command-package-session-")
        || !unit.ends_with(".service")
        || !unit
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b".-".contains(&b))
    {
        return Err(Error::Unavailable {});
    }
    Ok(unit.into())
}
fn arguments(
    root: &Path,
    a: &Account,
    unit: &str,
    parent: &str,
    seconds: u64,
    cwd: &str,
) -> Vec<String> {
    let mut args = vec![
        "--quiet".into(),
        "--wait".into(),
        "--pipe".into(),
        "--collect".into(),
        "--service-type=exec".into(),
        format!("--unit={unit}"),
        format!("--uid={}", a.uid),
        format!("--gid={}", a.gid),
    ];
    for prop in [format!("RootDirectory={}",root.display()),
format!("WorkingDirectory=/work/{cwd}"),
format!("BindsTo={parent}"),format!("After={parent}"),format!("RuntimeMaxSec={seconds}"),"KillMode=control-group".into(),
"TimeoutStopSec=5".into(),
"SendSIGKILL=yes".into(),
"NoNewPrivileges=yes".into(),
"CapabilityBoundingSet=".into(),
"AmbientCapabilities=".into(),
"SupplementaryGroups=".into(),
"PrivatePIDs=yes".into(),
"PrivateMounts=yes".into(),
"PrivateIPC=yes".into(),
"PrivateDevices=yes".into(),
"ProtectSystem=strict".into(),
"ProtectProc=invisible".into(),
"ProcSubset=pid".into(),
"ProtectControlGroups=yes".into(),
"ProtectKernelTunables=yes".into(),
"ProtectKernelModules=yes".into(),
"RestrictSUIDSGID=yes".into(),
"RestrictNamespaces=yes".into(),
"LockPersonality=yes".into(),
"ReadWritePaths=+/work +/home +/tmp +/cache".into(),
"BindReadOnlyPaths=/usr /etc/ssl/certs /etc/php /opt/orbit/swamp-package-runtime:/opt/package-runtime".into(),
"RestrictAddressFamilies=AF_INET AF_INET6".into(),
"SystemCallArchitectures=native".into(),
"SystemCallFilter=~socketpair io_uring_setup io_uring_enter io_uring_register".into(),
"SystemCallErrorNumber=EPERM".into(),
"IPAddressDeny=0.0.0.0/8 127.0.0.0/8 ::/128 ::1/128 169.254.0.0/16 fe80::/10 224.0.0.0/4 ff00::/8 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 fc00::/7".into(),
"MemoryMax=768M".into(),
"TasksMax=64".into(),
"UMask=0077".into()] {args.push(format!("--property={prop}"));}
    for (key, value) in [
        ("PATH", "/opt/package-runtime:/usr/bin:/bin"),
        ("HOME", "/home"),
        ("TMPDIR", "/tmp"),
        ("XDG_CONFIG_HOME", "/home"),
        ("XDG_CACHE_HOME", "/cache"),
        ("LANG", "C.UTF-8"),
        ("COMPOSER_HOME", "/home"),
        ("COMPOSER_CACHE_DIR", "/cache/composer"),
        ("COMPOSER_NO_INTERACTION", "1"),
        ("COMPOSER_ALLOW_SUPERUSER", "0"),
        ("npm_config_userconfig", "/dev/null"),
        ("npm_config_globalconfig", "/dev/null"),
        ("npm_config_cache", "/cache/npm"),
        ("npm_config_ignore_scripts", "true"),
        ("npm_config_registry", "https://registry.npmjs.org/"),
        ("BUN_INSTALL_CACHE_DIR", "/cache/bun"),
        ("GIT_CONFIG_NOSYSTEM", "1"),
        ("GIT_CONFIG_GLOBAL", "/dev/null"),
        ("GIT_TERMINAL_PROMPT", "0"),
        ("SSL_CERT_FILE", "/etc/ssl/certs/ca-certificates.crt"),
    ] {
        args.push(format!("--setenv={key}={value}"));
    }
    args
}
fn package_unit(job: &str) -> String {
    format!("orbit-package-{job}.service")
}
fn package_cgroup(job: &str) -> PathBuf {
    Path::new("/sys/fs/cgroup/system.slice").join(package_unit(job))
}
fn quiesce(job: &str) -> Result<(), Error> {
    let unit = package_unit(job);
    let output = Process::new("/usr/bin/systemctl")
        .args(["stop", &unit])
        .output()
        .map_err(|_| Error::Failed {})?;
    if !output.status.success() {
        let state = Process::new("/usr/bin/systemctl")
            .args(["show", "--property=LoadState", "--value", &unit])
            .output()
            .map_err(|_| Error::Failed {})?;
        if String::from_utf8_lossy(&state.stdout).trim() != "not-found" {
            return Err(Error::Failed {});
        }
    }
    match fs::read_to_string(package_cgroup(job).join("cgroup.events")) {
        Ok(events) if events.lines().any(|line| line == "populated 0") => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        _ => Err(Error::Failed {}),
    }
}
struct Unit(String);
impl Drop for Unit {
    fn drop(&mut self) {
        // Error paths retain no running package code; success paths additionally
        // verify quiescence before any privileged export or workspace mutation.
        let _ = Process::new("/usr/bin/systemctl")
            .args(["stop", &self.0])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
    }
}

const EXPO_CATALOG: &str = r#"import json,pathlib,re,subprocess,sys,tarfile,tempfile
with tempfile.TemporaryDirectory(prefix='expo-',dir='/tmp') as tmp:
 r=subprocess.run(['/opt/package-runtime/node','/opt/package-runtime/npm/bin/npm-cli.js','pack','expo@'+sys.argv[1],'--ignore-scripts','--json','--pack-destination',tmp],capture_output=True,text=True)
 if r.returncode: sys.exit(r.returncode)
 name=json.loads(r.stdout)[0]['filename']
 if not re.fullmatch(r'expo-[a-zA-Z0-9.-]+\.tgz',name): sys.exit(78)
 with tarfile.open(pathlib.Path(tmp)/name,'r:gz') as archive:
  member=archive.getmember('package/bundledNativeModules.json')
  if not member.isfile() or member.size>3145728: sys.exit(78)
  value=archive.extractfile(member).read(3145729)
  if len(value)>3145728: sys.exit(78)
  print(json.dumps(json.loads(value)))
"#;
fn execute(
    root: &Path,
    a: &Account,
    tool: &Tool,
    cwd: &str,
    argv: &[String],
    deadline: Instant,
    job: &str,
) -> Result<(i32, String, String), Error> {
    if !relative(cwd, true)
        || argv.len() > 256
        || argv
            .iter()
            .any(|arg| arg.len() > 4096 || arg.contains('\0'))
    {
        return Err(Error::Invalid {});
    }
    let parent = parent_unit()?;
    let unit = Unit(package_unit(job));
    let seconds = deadline
        .saturating_duration_since(Instant::now())
        .as_secs()
        .saturating_sub(2);
    if seconds == 0 {
        return Err(Error::Unavailable {});
    }
    let mut args = arguments(root, a, &unit.0, &parent, seconds, cwd);
    let gate = root.parent().ok_or(Error::Failed {})?.join("network-ready");
    fs::write(&gate, b"").map_err(|_| Error::Failed {})?;
    fs::set_permissions(&gate, fs::Permissions::from_mode(0o644)).map_err(|_| Error::Failed {})?;
    args.push(format!(
        "--property=BindReadOnlyPaths={}:/run/network-ready",
        gate.display()
    ));
    args.extend([
        "--".into(),
        "/usr/bin/python3".into(),
        "-I".into(),
        "-c".into(),
        network::GUARD.into(),
        tool.executable(cwd),
    ]);
    if matches!(tool, Tool::Npm) {
        args.push("/opt/package-runtime/npm/bin/npm-cli.js".into());
    }
    if matches!(tool, Tool::ExpoCatalog) {
        if argv.len() != 1
            || argv[0].is_empty()
            || argv[0].len() > 128
            || !argv[0]
                .bytes()
                .all(|c| c.is_ascii_alphanumeric() || b".-+".contains(&c))
        {
            return Err(Error::Invalid {});
        }
        args.extend(["-I".into(), "-c".into(), EXPO_CATALOG.into()]);
    }
    args.extend_from_slice(argv);
    let mut child = Process::new("/usr/bin/systemd-run")
        .args(args)
        .env_clear()
        .env("PATH", "/usr/bin:/bin")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|_| Error::Unavailable {})?;
    if let Err(error) = network::release(root, &package_cgroup(job), deadline) {
        let stopped = quiesce(job);
        let _ = child.wait();
        stopped?;
        return Err(error);
    }
    let out = child.stdout.take().ok_or(Error::Failed {})?;
    let err = child.stderr.take().ok_or(Error::Failed {})?;
    let read = |pipe: Box<dyn Read + Send>| {
        std::thread::spawn(move || {
            let mut value = Vec::new();
            pipe.take(MAX_OUTPUT + 1)
                .read_to_end(&mut value)
                .map(|_| value)
        })
    };
    let out = read(Box::new(out));
    let err = read(Box::new(err));
    let status = child.wait().map_err(|_| Error::Failed {})?;
    let stdout = out
        .join()
        .map_err(|_| Error::Failed {})?
        .map_err(|_| Error::Failed {})?;
    let stderr = err
        .join()
        .map_err(|_| Error::Failed {})?
        .map_err(|_| Error::Failed {})?;
    if stdout.len() as u64 > MAX_OUTPUT || stderr.len() as u64 > MAX_OUTPUT {
        return Err(Error::Failed {});
    }
    Ok((
        status.code().unwrap_or(128),
        String::from_utf8_lossy(&stdout).into(),
        String::from_utf8_lossy(&stderr).into(),
    ))
}
fn export(root: &Path, names: &[String]) -> Result<BTreeMap<String, String>, Error> {
    let mut files = BTreeMap::new();
    let mut bytes = 0;
    for name in names {
        if !manifest(name) {
            return Err(Error::Invalid {});
        }
        let Some(value) = self::files::read(root, name)? else {
            continue;
        };
        bytes += value.len();
        if bytes > MAX_BYTES {
            return Err(Error::Failed {});
        }
        files.insert(name.clone(), value);
    }
    Ok(files)
}
pub struct Session;
impl Command for Session {
    const NAME: &'static str = "package.session";
    const SUMMARY: &'static str = "Run package tools in a private UID, filesystem and cgroup.";
    const MUTATES: bool = true;
    const KEYED_EFFECT: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(180);
    const INPUT_MAX_BYTES: u64 = 256 * 1024 * 1024;
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::super::linux_only(p, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        match input {
            Input::Open { job, .. } | Input::Run { job, .. } | Input::Close { job } => {
                Some(format!("package-job:{job}"))
            }
            _ => None,
        }
    }
    fn run(input: Input, ctx: &Context) -> Result<Output, Error> {
        let a = account()?;
        let jobs = Path::new(STATE).join("jobs");
        directory(&jobs, 0, true).map_err(|_| Error::Unavailable {})?;
        match input {
            Input::Open { job: id } => {
                let files: BTreeMap<String, String> =
                    serde_json::from_reader(ctx.take_binary_input().ok_or(Error::Invalid {})?)
                        .map_err(|_| Error::Invalid {})?;
                if !valid_id(&id) {
                    return Err(Error::Invalid {});
                }
                let job = jobs.join(&id);
                mkdir(&job, None, 0o700)?;
                let root = job.join("root");
                mkdir(&root, None, 0o755)?;
                let result = (|| {
                    stage(&root, &files, &a)?;
                    fs::write(
                        job.join("files.json"),
                        serde_json::to_vec(&files.keys().collect::<Vec<_>>())
                            .map_err(|_| Error::Invalid {})?,
                    )
                    .map_err(|_| Error::Failed {})?;
                    Ok(Output::Opened { job: id })
                })();
                if result.is_err() {
                    let _ = fs::remove_dir_all(job);
                }
                result
            }
            Input::Run {
                job,
                tool,
                cwd,
                args,
            } => {
                let files: BTreeMap<String, String> =
                    serde_json::from_reader(ctx.take_binary_input().ok_or(Error::Invalid {})?)
                        .map_err(|_| Error::Invalid {})?;
                let path = job_path(&jobs, &job)?;
                let root = path.join("root");
                directory(&root, 0, false).map_err(|_| Error::Unavailable {})?;
                quiesce(&job)?;
                update_files(&root, &files, &a)?;
                let mut names: Vec<String> = files.keys().cloned().collect();
                for name in files.keys() {
                    let dir = Path::new(name).parent().ok_or(Error::Invalid {})?;
                    let locks: &[&str] = match Path::new(name).file_name().and_then(|p| p.to_str())
                    {
                        Some("package.json") => {
                            &["package-lock.json", "npm-shrinkwrap.json", "bun.lock"]
                        }
                        Some("composer.json") => &["composer.lock"],
                        _ => &[],
                    };
                    names.extend(
                        locks
                            .iter()
                            .map(|file| dir.join(file).to_string_lossy().into_owned()),
                    );
                }
                names.sort();
                names.dedup();
                let parent = parent_unit()?;
                fs::write(path.join("parent"), &parent).map_err(|_| Error::Failed {})?;
                if path.join("cancelled").exists() {
                    return Err(Error::Failed {});
                }
                let (code, stdout, stderr) =
                    execute(&root, &a, &tool, &cwd, &args, ctx.deadline(), &job)?;
                quiesce(&job)?;
                let _ = fs::remove_file(path.join("parent"));
                Ok(Output::Executed {
                    code,
                    stdout,
                    stderr,
                    files: export(&root, &names)?,
                })
            }
            Input::Cancel { job } => {
                let path = job_path(&jobs, &job)?;
                fs::write(path.join("cancelled"), b"cancelled\n").map_err(|_| Error::Failed {})?;
                if let Ok(parent) = fs::read_to_string(path.join("parent")) {
                    if !parent.starts_with("orbit-command-package-session-")
                        || !parent.ends_with(".service")
                        || !parent
                            .bytes()
                            .all(|b| b.is_ascii_alphanumeric() || b".-".contains(&b))
                    {
                        return Err(Error::Failed {});
                    }
                    let status = Process::new("/usr/bin/systemctl")
                        .args(["stop", &parent])
                        .stdin(Stdio::null())
                        .stdout(Stdio::null())
                        .stderr(Stdio::null())
                        .status()
                        .map_err(|_| Error::Failed {})?;
                    if !status.success() {
                        let state = Process::new("/usr/bin/systemctl")
                            .args(["show", "--property=ActiveState", "--value", &parent])
                            .output()
                            .map_err(|_| Error::Failed {})?;
                        if !["inactive", "failed"]
                            .contains(&String::from_utf8_lossy(&state.stdout).trim())
                        {
                            return Err(Error::Failed {});
                        }
                    }
                }
                quiesce(&job)?;
                Ok(Output::Cancelled {})
            }
            Input::Close { job } => {
                let path = job_path(&jobs, &job)?;
                quiesce(&job)?;
                fs::remove_dir_all(path).map_err(|_| Error::Failed {})?;
                Ok(Output::Closed {})
            }
        }
    }
}
#[cfg(test)]
mod tests;
