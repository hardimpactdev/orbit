//! First admission: base packages, narrowly suspended owned DNS, and the managed SSH account.
use super::recipe::{self, Files, Host, Invocation, Native, WorkDir};
use crate::command::{lock, Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{self, Write},
    net::IpAddr,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

command_errors! {
    pub enum Error {
        Invalid {} => "nodes.bootstrap_invalid", "The bootstrap request is invalid.";
        Unsupported { id: String, codename: String } => "nodes.bootstrap_os_unsupported", "The Node operating system is not supported.";
        Unsafe { path: String } => "nodes.bootstrap_unsafe", "A bootstrap path is not a real directory or regular file.";
        Dns { reason: String } => "nodes.bootstrap_dns_refused", "Package-source DNS is unavailable; retained DNS was preserved.";
        Failed { stage: String } => "nodes.bootstrap_failed", "Node bootstrap failed.";
        Recovery {} => "nodes.bootstrap_dns_recovery_failed", "Could not restore Orbit bootstrap DNS.";
    }
}
#[derive(Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum DnsPolicy {
    Managed,
    Preserve,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub user: String,
    pub public_key: String,
    pub releases: Vec<String>,
    pub packages: Vec<String>,
    pub dns_policy: DnsPolicy,
}
#[derive(Debug, Serialize)]
pub struct Output {}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn dns(reason: &str) -> Error {
    Error::Dns {
        reason: reason.into(),
    }
}
fn unsafe_path(path: &Path) -> Error {
    Error::Unsafe {
        path: path.display().to_string(),
    }
}
fn invocation(args: &[&str], seconds: u64) -> Invocation {
    let mut call = Invocation::new(args)
        .env("LC_ALL", "C")
        .env("DEBIAN_FRONTEND", "noninteractive");
    call.timeout = Duration::from_secs(seconds);
    call
}
fn run(
    host: &mut impl Host,
    args: &[&str],
    seconds: u64,
    stage: &str,
    required: bool,
) -> Result<recipe::Output, Error> {
    recipe::run(host, &invocation(args, seconds), required).map_err(|_| failed(stage))
}
fn valid(input: &Input) -> bool {
    let user = regex::Regex::new(r"\A[a-z_][a-z0-9_-]{0,31}\z").unwrap();
    let package = regex::Regex::new(r"\A[a-z0-9][a-z0-9+.-]*\z").unwrap();
    user.is_match(&input.user)
        && input.user != "root"
        && !input.public_key.is_empty()
        && input.public_key.len() <= 16384
        && !input.public_key.chars().any(char::is_control)
        && !input.releases.is_empty()
        && input.releases.iter().all(|s| package.is_match(s))
        && !input.packages.is_empty()
        && input.packages.iter().all(|s| package.is_match(s))
}
fn platform(files: &Files, input: &Input) -> Result<(), Error> {
    let release = fs::read_to_string(files.path("/etc/os-release"))
        .ok()
        .and_then(|s| super::os_release::parse(&s).ok());
    match release {
        Some(r) if r.id == "ubuntu" && input.releases.contains(&r.codename) => Ok(()),
        Some(r) => Err(Error::Unsupported {
            id: r.id,
            codename: r.codename,
        }),
        None => Err(Error::Unsupported {
            id: "unknown".into(),
            codename: "unknown".into(),
        }),
    }
}

fn source_hosts(text: &str) -> Result<Vec<String>, Error> {
    let mut hosts = vec![];
    for line in text.lines().filter_map(|s| s.strip_prefix('\'')) {
        let uri = line.split('\'').next().unwrap_or_default();
        if ["file:", "copy:", "cdrom:"]
            .iter()
            .any(|s| uri.starts_with(s))
        {
            continue;
        }
        let authority = ["http://", "https://", "ftp://"]
            .iter()
            .find_map(|s| uri.strip_prefix(s))
            .ok_or_else(|| dns("Unsupported package-source transport"))?
            .split('/')
            .next()
            .unwrap_or_default()
            .rsplit('@')
            .next()
            .unwrap_or_default();
        let host = if let Some(ip) = authority.strip_prefix('[') {
            ip.split_once(']')
                .ok_or_else(|| dns("Malformed package-source host"))?
                .0
        } else {
            authority.split(':').next().unwrap_or_default()
        };
        if !host
            .as_bytes()
            .first()
            .is_some_and(u8::is_ascii_alphanumeric)
            || !host
                .bytes()
                .all(|c| c.is_ascii_alphanumeric() || b".:-".contains(&c))
        {
            return Err(dns("Malformed package-source host"));
        }
        if !hosts.iter().any(|s| s == host) {
            hosts.push(host.to_owned());
        }
    }
    Ok(hosts)
}
fn resolves(host: &mut impl Host, hosts: &[String]) -> Result<bool, Error> {
    for name in hosts {
        if run(
            host,
            &["/usr/bin/getent", "ahosts", name],
            5,
            "dns-probe",
            false,
        )?
        .code
            != 0
        {
            return Ok(false);
        }
    }
    Ok(true)
}
fn owned_dns(files: &Files, host: &mut impl Host) -> Result<String, Error> {
    let directory = files.path("/etc/wireguard");
    let marker = directory.join("orbit.dns-link");
    if !fs::symlink_metadata(&directory).is_ok_and(|m| m.is_dir())
        || !fs::symlink_metadata(&marker)
            .is_ok_and(|m| m.is_file() && m.uid() == files.uid && m.mode() & 0o7777 == 0o600)
    {
        return Err(dns("No trusted Orbit DNS ownership record"));
    }
    let text = fs::read_to_string(marker).map_err(|_| dns("Unreadable DNS ownership record"))?;
    let lines: Vec<_> = text.lines().collect();
    if lines.len() != 3
        || lines[0] != "orbit"
        || lines[2] != "."
        || lines[1].parse::<IpAddr>().is_err()
    {
        return Err(dns("Retained DNS is not Orbit route-all DNS"));
    }
    let server = lines[1];
    let prefix = regex::Regex::new(r"\ALink [0-9]+ \(orbit\): ").unwrap();
    for (property, expected) in [("dns", server), ("domain", "~.")] {
        let live = run(
            host,
            &["/usr/bin/resolvectl", property, "orbit"],
            10,
            "dns-inspect",
            true,
        )?;
        let live = live.stdout.trim_end_matches('\n');
        let matched = prefix.find(live).map(|m| &live[m.end()..]);
        if matched != Some(expected) {
            return Err(dns("Live resolver differs from Orbit ownership"));
        }
    }
    Ok(server.into())
}
const APT_OPTIONS: &[&str] = &[
    "-o",
    "Acquire::Retries=0",
    "-o",
    "Acquire::http::Timeout=15",
    "-o",
    "Acquire::https::Timeout=15",
];
fn update(host: &mut impl Host, seconds: u64) -> Result<bool, Error> {
    let args = [
        &["/usr/bin/apt-get", "-o", "APT::Update::Error-Mode=any"][..],
        APT_OPTIONS,
        &["update"],
    ]
    .concat();
    Ok(run(host, &args, seconds, "package-update", false)?.code == 0)
}
/// The caller holds the actual WireGuard peer lock until both restoration calls finish.
fn packages(
    files: &Files,
    host: &mut impl Host,
    recovery: &mut impl Host,
    input: &Input,
) -> Result<(), Error> {
    let mut suspended = None;
    let result = (|| {
        if !update(host, 30)? {
            let uris = run(
                host,
                &["/usr/bin/apt-get", "--print-uris", "update"],
                20,
                "package-sources",
                true,
            )?;
            let hosts = source_hosts(&uris.stdout)?;
            if !resolves(host, &hosts)? {
                if matches!(input.dns_policy, DnsPolicy::Preserve) {
                    return Err(dns("Operator DNS is preserved"));
                }
                suspended = Some(owned_dns(files, host)?);
                for property in ["dns", "domain"] {
                    run(
                        host,
                        &["/usr/bin/resolvectl", property, "orbit", ""],
                        10,
                        "dns-suspend",
                        true,
                    )?;
                }
                if !resolves(host, &hosts)? {
                    return Err(dns(
                        "Existing network resolver cannot resolve package sources",
                    ));
                }
            }
            if !update(host, 180)? {
                return Err(failed("package-update"));
            }
        }
        let names: Vec<_> = input.packages.iter().map(String::as_str).collect();
        let args = [
            &["/usr/bin/apt-get"][..],
            APT_OPTIONS,
            &["install", "--yes", "--no-install-recommends", "--"],
            &names,
        ]
        .concat();
        run(host, &args, 300, "package-install", true)?;
        Ok(())
    })();
    if let Some(server) = suspended {
        let mut restored = true;
        for (property, value) in [("dns", server.as_str()), ("domain", "~.")] {
            // Do not consult the interruption flag: restoration must run after a signal too.
            restored &= recovery
                .execute(&invocation(
                    &["/usr/bin/resolvectl", property, "orbit", value],
                    10,
                ))
                .is_ok_and(|o| o.code == 0 && !o.truncated);
        }
        if !restored {
            return Err(Error::Recovery {});
        }
    }
    result
}
struct Account {
    uid: u32,
    gid: u32,
    home: PathBuf,
}
fn account(host: &mut impl Host, name: &str) -> Result<Option<Account>, Error> {
    let output = run(
        host,
        &["/usr/bin/getent", "passwd", name],
        10,
        "account",
        false,
    )?;
    if output.code == 2 {
        return Ok(None);
    }
    let parts: Vec<_> = output.stdout.trim_end_matches('\n').split(':').collect();
    if output.code != 0 || parts.len() != 7 || parts[0] != name {
        return Err(failed("account"));
    }
    let uid: u32 = parts[2].parse().map_err(|_| failed("account"))?;
    let gid: u32 = parts[3].parse().map_err(|_| failed("account"))?;
    let home = PathBuf::from(parts[5]);
    if uid == 0 || !home.is_absolute() || home == Path::new("/") {
        return Err(failed("account"));
    }
    Ok(Some(Account { uid, gid, home }))
}
fn real_path(path: &Path, directory: bool, required: bool) -> Result<(), Error> {
    match fs::symlink_metadata(path) {
        Ok(m) if (directory && m.is_dir()) || (!directory && m.is_file()) => Ok(()),
        Err(e) if e.kind() == io::ErrorKind::NotFound && !required => Ok(()),
        _ => Err(unsafe_path(path)),
    }
}
fn check_account(account: &Account) -> Result<(), Error> {
    real_path(&account.home, true, true)?;
    for child in [".ssh", ".orbit"] {
        real_path(&account.home.join(child), true, false)?;
    }
    real_path(&account.home.join(".ssh/authorized_keys"), false, false)
}
fn prepare_account(
    files: &Files,
    host: &mut impl Host,
    input: &Input,
    prior: Option<Account>,
) -> Result<(), Error> {
    let account = match prior {
        Some(a) => a,
        None => {
            run(
                host,
                &[
                    "/usr/sbin/useradd",
                    "--create-home",
                    "--shell",
                    "/bin/bash",
                    "--",
                    &input.user,
                ],
                30,
                "account-create",
                true,
            )?;
            account(host, &input.user)?.ok_or_else(|| failed("account-create"))?
        }
    };
    check_account(&account)?;
    let sudoers = files.path("/etc/sudoers.d");
    real_path(&sudoers, true, true)?;
    let rule = sudoers.join(&input.user);
    real_path(&rule, false, false)?;
    let sudo = run(
        host,
        &[
            "/usr/bin/sudo",
            "-n",
            "-u",
            &input.user,
            "--",
            "/usr/bin/sudo",
            "-n",
            "true",
        ],
        10,
        "sudo-check",
        false,
    )?
    .code
        == 0;
    let work = WorkDir::new().map_err(|_| failed("sudo-stage"))?;
    let candidate = work.0.join("sudoers");
    let content = format!("{} ALL=(ALL) NOPASSWD:ALL\n", input.user);
    if !sudo {
        fs::write(&candidate, &content).map_err(|_| failed("sudo-stage"))?;
        fs::set_permissions(&candidate, fs::Permissions::from_mode(0o440))
            .map_err(|_| failed("sudo-stage"))?;
        run(
            host,
            &["/usr/sbin/visudo", "-cf", &candidate.to_string_lossy()],
            10,
            "sudo-validate",
            true,
        )?;
    }
    for child in [".ssh", ".orbit"] {
        let path = account.home.join(child);
        if !path.exists() {
            fs::DirBuilder::new()
                .mode(0o700)
                .create(&path)
                .map_err(|_| failed("account-directory"))?;
            super::caddy::chown(&path, account.uid, account.gid)
                .map_err(|_| failed("account-directory"))?;
        }
    }
    let path = account.home.join(".ssh/authorized_keys");
    let mut keys = fs::OpenOptions::new()
        .read(true)
        .append(true)
        .create(true)
        .mode(0o600)
        .custom_flags(libc::O_NOFOLLOW)
        .open(&path)
        .map_err(|_| failed("authorized-keys"))?;
    rustix::fs::fchown(
        &keys,
        Some(rustix::fs::Uid::from_raw(account.uid)),
        Some(rustix::fs::Gid::from_raw(account.gid)),
    )
    .map_err(|_| failed("authorized-keys"))?;
    keys.set_permissions(fs::Permissions::from_mode(0o600))
        .map_err(|_| failed("authorized-keys"))?;
    let bytes = fs::read(&path).map_err(|_| failed("authorized-keys"))?;
    if !bytes
        .split(|c| *c == b'\n')
        .any(|line| line == input.public_key.as_bytes())
    {
        if !bytes.is_empty() && !bytes.ends_with(b"\n") {
            keys.write_all(b"\n")
                .map_err(|_| failed("authorized-keys"))?;
        }
        writeln!(keys, "{}", input.public_key).map_err(|_| failed("authorized-keys"))?;
    }
    if !sudo {
        files
            .publish(
                &rule,
                content.as_bytes(),
                0o440,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed("sudo-publish"))?;
    }
    Ok(())
}
fn bootstrap(
    files: &Files,
    host: &mut impl Host,
    recovery: &mut impl Host,
    input: &Input,
    deadline: Instant,
) -> Result<Output, Error> {
    if !valid(input) {
        return Err(Error::Invalid {});
    }
    platform(files, input)?;
    let prior = account(host, &input.user)?;
    if let Some(a) = &prior {
        check_account(a)?;
    }
    {
        let _lock = lock::acquire(
            &files.path("/run/lock/orbit-wireguard-peer.lock"),
            deadline.min(Instant::now() + Duration::from_secs(30)),
        )
        .map_err(|_| failed("peer-lock"))?;
        packages(files, host, recovery, input)?;
    }
    prepare_account(files, host, input, prior)?;
    Ok(Output {})
}
pub struct Bootstrap;
impl Command for Bootstrap {
    const NAME: &'static str = "nodes.bootstrap";
    const SUMMARY: &'static str =
        "Install base packages and prepare managed SSH access, restoring retained Orbit DNS.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(660);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("node-bootstrap".into())
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        let _signals = recipe::interruption::Guard::install().map_err(|_| failed("signals"))?;
        let deadline = context
            .deadline()
            .checked_sub(Duration::from_secs(25))
            .filter(|d| *d > Instant::now())
            .ok_or_else(|| failed("deadline"))?;
        let work = Context::new(context.platform, deadline);
        bootstrap(
            &Files::system(),
            &mut Native(&work),
            &mut Native(context),
            &input,
            deadline,
        )
    }
}
#[cfg(test)]
mod tests;
