//! Authenticated APT observations and bounded fetches. Installed-only versions need historical proof.
use super::{run, sources, Failure, Files, Host, Invocation, Output, WorkDir};
use regex::Regex;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    io::{self, BufRead, BufReader},
    path::{Path, PathBuf},
    time::Duration,
};

fn expression(pattern: &str) -> Regex {
    Regex::new(pattern).expect("fixed apt expression")
}
pub fn fetch_options() -> Vec<String> {
    [
        "-o",
        "Acquire::Retries=0",
        "-o",
        "Acquire::http::Timeout=15",
        "-o",
        "Acquire::https::Timeout=15",
    ]
    .map(str::to_owned)
    .into()
}
pub fn source_options(path: &Path) -> Vec<String> {
    let mut options = fetch_options();
    for value in [
        format!("Dir::Etc::sourcelist={}", path.display()),
        "Dir::Etc::sourceparts=-".into(),
        "APT::Get::List-Cleanup=false".into(),
        "APT::Update::Error-Mode=any".into(),
        "Acquire::Languages=none".into(),
        "Acquire::IndexTargets::deb::DEP-11::DefaultEnabled=false".into(),
        "Acquire::IndexTargets::deb::CNF::DefaultEnabled=false".into(),
    ] {
        options.extend(["-o".into(), value]);
    }
    options
}
fn apt_invocation(argv: &[&str], options: &[String]) -> Invocation {
    let mut invocation = Invocation::new(argv).env("LC_ALL", "C");
    invocation.argv.extend_from_slice(options);
    if let Some(config) = options.windows(2).find(|p| p[0] == "-c") {
        invocation = invocation.env("APT_CONFIG", &config[1]);
    }
    invocation
}
/// Signature, digest, authentication, and mixed failures never qualify for transport retries.
pub fn retryable(stdout: &str, stderr: &str) -> bool {
    let output = format!("{stdout}\n{stderr}");
    if expression(r"(?i)hash sum mismatch|unexpected size|signature|NO_PUBKEY").is_match(&output) {
        return false;
    }
    let failure_pattern = expression(r"(?m)^[EW]: Failed to fetch (\S+)[ \t]+([^\n]+)");
    let failures: Vec<_> = failure_pattern.captures_iter(&output).collect();
    if failures.is_empty() {
        return false;
    }
    let unsigned_pattern =
        expression(r"(?m)^E: The repository '(\S+) (\S+) (?:InRelease|Release)' is not signed\.$");
    let unsigned: Vec<_> = unsigned_pattern.captures_iter(&output).collect();
    let derived = !unsigned.is_empty()
        && unsigned.iter().all(|entry| {
            failures.iter().any(|failure| {
                ["InRelease", "Release"].iter().any(|kind| {
                    failure[1]
                        == format!(
                            "{}/dists/{}/{kind}",
                            entry[1].trim_end_matches('/'),
                            &entry[2]
                        )
                })
            })
        });
    if output.contains("not signed") && !derived {
        return false;
    }
    failures.iter().all(|failure| {
        expression(r"\A5\d\d\b").is_match(&failure[2])
            || ["Connection reset by peer", "Connection failed"]
                .iter()
                .any(|reason| failure[2].contains(reason))
    })
}
pub fn fetch(host: &mut impl Host, invocation: &Invocation) -> Result<Output, Failure> {
    let mut invocation = invocation.clone();
    invocation.environment.retain(|(key, _)| key != "LC_ALL");
    invocation.environment.push(("LC_ALL".into(), "C".into()));
    for delay in [Some(10), Some(20), Some(30), None] {
        let output = run(host, &invocation, false)?;
        if output.code == 0 {
            return Ok(output);
        }
        let Some(delay) = delay.filter(|_| retryable(&output.stdout, &output.stderr)) else {
            return Err(Failure::command(&invocation, &output));
        };
        host.wait(Duration::from_secs(delay))?;
    }
    unreachable!("the fourth failed attempt returns")
}
pub fn package_command(
    host: &mut impl Host,
    invocation: &Invocation,
    packages: &[String],
) -> Result<Output, Failure> {
    let retry = invocation.argv.first().is_some_and(|v| v == "apt-get")
        && invocation
            .argv
            .get(1)
            .is_some_and(|v| v == "update" || v == "install");
    let result = if retry {
        fetch(host, invocation)
    } else {
        run(host, invocation, true)
    };
    result.map_err(|mut error| {
        error.packages = packages.to_vec();
        error
    })
}

pub struct Indexes {
    work: WorkDir,
    pub options: Vec<String>,
}
impl Indexes {
    pub fn path(&self) -> &Path {
        &self.work.0
    }
    pub fn temporary(host: &mut impl Host, source: &str) -> Result<Self, Failure> {
        let work = WorkDir::new()?;
        let source_path = work.0.join("ubuntu.sources");
        let config = work.0.join("apt.conf");
        let prepare = || -> io::Result<()> {
            fs::write(&source_path, source)?;
            for name in ["lists", "cache", "log"] {
                fs::create_dir(work.0.join(name))?;
            }
            // APT_CONFIG loads before apt.conf.d. No host hooks, cache, lists, or lock writes.
            fs::write(&config, "Dir::Etc::parts \"-\";\nDir::Etc::main \"-\";\n")
        };
        prepare().map_err(|e| Failure::io("apt-source", &work.0, e))?;
        let mut options = vec!["-c".into(), config.display().to_string()];
        for option in [
            format!("Dir::Etc::sourcelist={}", source_path.display()),
            "Dir::Etc::sourceparts=-".into(),
            format!("Dir::State::lists={}", work.0.join("lists").display()),
            format!("Dir::Cache={}", work.0.join("cache").display()),
            format!("Dir::Log={}", work.0.join("log").display()),
            "Debug::NoLocking=true".into(),
            "APT::Sandbox::User=".into(),
            "Acquire::Languages=none".into(),
            "APT::Update::Error-Mode=any".into(),
        ] {
            options.extend(["-o".into(), option]);
        }
        options.extend(fetch_options());
        let mut invocation = apt_invocation(&["apt-get", "update"], &options);
        invocation.timeout = Duration::from_secs(120);
        fetch(host, &invocation).map_err(|e| e.at("apt-source"))?;
        Ok(Self { work, options })
    }
    /// Applying refreshes only Ubuntu indexes, preserving cached snapshot evidence for origin checks.
    pub fn refresh(host: &mut impl Host, snapshot: &str) -> Result<Self, Failure> {
        let work = WorkDir::new()?;
        let source_path = work.0.join("ubuntu.sources");
        let rolling = sources::rolling_source();
        fs::write(&source_path, &rolling)
            .map_err(|e| Failure::io("apt-source", &source_path, e))?;
        let options = source_options(&source_path);
        package_command(host, &apt_invocation(&["apt-get", "update"], &options), &[])
            .map_err(|e| e.at("apt-source"))?;
        fs::write(
            &source_path,
            format!("{rolling}\n{}", sources::snapshot_source(snapshot)),
        )
        .map_err(|e| Failure::io("apt-source", &source_path, e))?;
        Ok(Self { work, options })
    }
}

#[derive(Debug, Default, PartialEq)]
pub struct Origin {
    pub trusted: bool,
    pub untrusted: bool,
    pub installed: bool,
}
pub fn ubuntu_index(index: &str, signed: &sources::SignedSources) -> bool {
    let fields: Vec<_> = index.split_whitespace().collect();
    if fields.len() != 4 {
        return false;
    }
    let Ok(url) = reqwest::Url::parse(fields[0]) else {
        return false;
    };
    let host = url.host_str().unwrap_or_default();
    ["http", "https"].contains(&url.scheme())
        && ([
            "archive.ubuntu.com",
            "security.ubuntu.com",
            "snapshot.ubuntu.com",
        ]
        .contains(&host)
            || host.ends_with(".archive.ubuntu.com")
            || signed.contains(&(fields[0].trim_end_matches('/').into(), fields[1].into())))
}
fn normalize(text: &str) -> String {
    text.split_whitespace().collect::<Vec<_>>().join(" ")
}
pub fn parse_origin(
    global: &str,
    package: &str,
    version: &str,
    signed: &sources::SignedSources,
) -> Origin {
    let mut trusted = BTreeSet::new();
    let mut index = None;
    let index_pattern = expression(r"\A\s*-?\d+\s+(.+ Packages)\s*\z");
    for line in global.lines() {
        if let Some(entry) = index_pattern.captures(line) {
            index = Some(normalize(&entry[1]));
        } else if let Some(release) = line.trim().strip_prefix("release ") {
            let fields: BTreeMap<_, _> = release
                .split(',')
                .filter_map(|v| v.split_once('='))
                .collect();
            if let Some(index) = index.take().filter(|i| ubuntu_index(i, signed)) {
                if fields.get("o") == Some(&"Ubuntu")
                    && fields.get("n") == Some(&"resolute")
                    && fields.get("a").is_some_and(|suite| {
                        ["resolute", "resolute-updates", "resolute-security"].contains(suite)
                    })
                {
                    trusted.insert(index);
                }
            }
        }
    }
    let version_pattern = expression(r"\A\s*(?:\*\*\*\s+)?(\S+)\s+-?\d+(?:\s+\([^)]*\))?\s*\z");
    let origin_pattern = expression(r"\A\s*-?\d+\s+(.+?)\s*\z");
    let mut selected = false;
    let mut result = Origin::default();
    for line in package.lines() {
        if let Some(entry) = version_pattern.captures(line) {
            selected = &entry[1] == version;
            continue;
        }
        if let Some(entry) = origin_pattern.captures(line) {
            if !selected {
                continue;
            }
            let index = normalize(&entry[1]);
            if index == "/var/lib/dpkg/status" {
                result.installed = true;
            } else if trusted.contains(&index) {
                result.trusted = true;
            } else {
                result.untrusted = true;
            }
        } else {
            selected = false;
        }
    }
    result
}
pub fn architecture(host: &mut impl Host) -> Result<String, Failure> {
    let arch = run(
        host,
        &Invocation::new(&["dpkg", "--print-architecture"]),
        true,
    )?
    .stdout
    .trim()
    .to_string();
    if arch != "amd64" {
        return Err(Failure::new(
            "architecture",
            "Unsupported package architecture",
        ));
    }
    Ok(arch)
}
pub fn packages(host: &mut impl Host) -> Result<BTreeMap<String, String>, Failure> {
    let arch = architecture(host)?;
    let output = run(
        host,
        &Invocation::new(&[
            "dpkg-query",
            "-W",
            "-f=${Package}\t${Architecture}\t${Version}\t${Status}\n",
        ]),
        true,
    )?;
    let mut installed = BTreeMap::new();
    for line in output.stdout.lines() {
        let parts: Vec<_> = line.splitn(4, '\t').collect();
        if parts.len() != 4 {
            return Err(Failure::new(
                "inventory",
                "Malformed dpkg package inventory",
            ));
        }
        if parts[3] == "install ok installed" && [arch.as_str(), "all"].contains(&parts[1]) {
            installed.insert(parts[0].into(), parts[2].into());
        }
    }
    Ok(installed)
}
pub fn policy_origin(
    host: &mut impl Host,
    files: &Files,
    package: &str,
    version: &str,
    options: &[String],
) -> Result<Origin, Failure> {
    let global = run(
        host,
        &apt_invocation(&["apt-cache", "policy"], options),
        true,
    )?;
    let source = options
        .iter()
        .find_map(|v| v.strip_prefix("Dir::Etc::sourcelist="))
        .map(Path::new);
    let signed = sources::signed_sources(files, source)?;
    let arch = architecture(host)?;
    let package = run(
        host,
        &apt_invocation(
            &["apt-cache", "policy", &format!("{package}:{arch}")],
            options,
        ),
        true,
    )?;
    Ok(parse_origin(
        &global.stdout,
        &package.stdout,
        version,
        &signed,
    ))
}

/// Cache failures too, so one run cannot repeat a failed lookup or exceed three timestamps.
#[derive(Default)]
pub struct Snapshots {
    lookups: BTreeMap<String, Result<Indexes, Failure>>,
    retained_only: bool,
}
impl Snapshots {
    /// Images prove origin from their selected snapshot, never another historical snapshot.
    pub fn retained_only() -> Self {
        Self {
            lookups: BTreeMap::new(),
            retained_only: true,
        }
    }
    pub fn options(&mut self, host: &mut impl Host, snapshot: &str) -> Result<&[String], Failure> {
        if self.retained_only {
            return Err(Failure::new(
                "snapshot-unproved",
                "The retained image indexes do not prove package origin",
            ));
        }
        if !self.lookups.contains_key(snapshot) {
            if self.lookups.len() >= 3 {
                return Err(Failure::new(
                    "snapshot-lookup-limit",
                    "More than three historical snapshots were required",
                ));
            }
            let indexes =
                Indexes::temporary(host, &sources::snapshot_source(snapshot)).map_err(|_| {
                    Failure::new(
                        "snapshot-unavailable",
                        "Historical authenticated indexes are unavailable",
                    )
                });
            self.lookups.insert(snapshot.into(), indexes);
        }
        match &self.lookups[snapshot] {
            Ok(indexes) => Ok(&indexes.options),
            Err(e) => Err(e.clone()),
        }
    }
}
fn log_timestamp(date: &str, clock: &str) -> Option<String> {
    // dpkg logs use the host's local time, including its DST rules. Convert that instant to UTC.
    let format = time::macros::format_description!("[year]-[month]-[day] [hour]:[minute]:[second]");
    let date = time::PrimitiveDateTime::parse(&format!("{date} {clock}"), format).ok()?;
    let mut local: libc::tm = unsafe { std::mem::zeroed() };
    local.tm_year = date.year() - 1900;
    local.tm_mon = i32::from(u8::from(date.month())) - 1;
    local.tm_mday = date.day().into();
    local.tm_hour = date.hour().into();
    local.tm_min = date.minute().into();
    local.tm_sec = date.second().into();
    local.tm_isdst = -1;
    let stamp = unsafe { libc::mktime(&mut local) };
    if stamp == -1 {
        return None;
    }
    time::OffsetDateTime::from_unix_timestamp(stamp)
        .ok()?
        .format(time::macros::format_description!(
            "[year][month][day]T[hour][minute][second]Z"
        ))
        .ok()
}
pub fn install_snapshot(log: &Path, package: &str, version: &str, arch: &str) -> Option<String> {
    let prefix = log.file_name()?.to_str()?;
    let mut action: Option<String> = None;
    let mut status: Option<String> = None;
    let entries = fs::read_dir(log.parent()?).ok()?;
    for entry in entries {
        let path = entry.ok()?.path();
        if !path.file_name()?.to_str()?.starts_with(prefix) {
            continue;
        }
        let file = fs::File::open(&path).ok()?;
        let reader: Box<dyn BufRead> = if path.extension().is_some_and(|ext| ext == "gz") {
            Box::new(BufReader::new(flate2::read::GzDecoder::new(file)))
        } else {
            Box::new(BufReader::new(file))
        };
        for line in reader.split(b'\n') {
            let bytes = line.ok()?;
            let line = String::from_utf8_lossy(&bytes);
            let fields: Vec<_> = line.split_whitespace().collect();
            if fields.len() != 6 || fields[5] != version {
                continue;
            }
            let is_action = ["install", "upgrade"].contains(&fields[2]);
            let name = if is_action { fields[3] } else { fields[4] };
            if (!is_action && fields[2..4] != ["status", "installed"])
                || ![
                    package,
                    &format!("{package}:{arch}"),
                    &format!("{package}:all"),
                ]
                .contains(&name)
            {
                continue;
            }
            let timestamp = log_timestamp(fields[0], fields[1])?;
            let slot = if is_action { &mut action } else { &mut status };
            if slot.as_ref().is_none_or(|previous| timestamp < *previous) {
                *slot = Some(timestamp);
            }
        }
    }
    action.or(status)
}
pub fn ubuntu_origin(
    host: &mut impl Host,
    files: &Files,
    package: &str,
    version: &str,
    options: Option<&[String]>,
    snapshots: &mut Snapshots,
) -> Result<bool, Failure> {
    let mut origins = vec![policy_origin(host, files, package, version, &[])?];
    if let Some(options) = options {
        origins.push(policy_origin(host, files, package, version, options)?);
    }
    if origins.iter().any(|v| v.untrusted) {
        return Ok(false);
    }
    if origins.iter().any(|v| v.trusted) {
        return Ok(true);
    }
    if !origins.iter().any(|v| v.installed) {
        return Ok(false);
    }
    let arch = architecture(host)?;
    let snapshot = install_snapshot(&files.path("/var/log/dpkg.log"), package, version, &arch)
        .ok_or_else(|| {
            Failure::new(
                "dpkg-log-missing",
                "The installation timestamp is unavailable",
            )
        })?;
    let options = snapshots.options(host, &snapshot)?;
    let origin = policy_origin(host, files, package, version, options)
        .map_err(|e| e.at("snapshot-unavailable"))?;
    if !origin.trusted || origin.untrusted {
        return Err(Failure::new(
            "snapshot-unproved",
            "The snapshot does not prove Ubuntu ownership",
        ));
    }
    Ok(true)
}
pub fn audit(host: &mut impl Host, environment: &[(String, String)]) -> Result<(), Failure> {
    let mut invocation = Invocation::new(&["dpkg", "--audit"]);
    invocation.environment = environment.to_vec();
    let result = run(host, &invocation, true).map_err(|e| e.at("packages"))?;
    if !result.stdout.trim().is_empty() || !result.stderr.trim().is_empty() {
        return Err(Failure::command(&invocation, &result).at("packages"));
    }
    Ok(())
}
/// Permit exact artifact downgrades only; a dependency downgrade is never part of the intent.
pub fn install_exact_debs(
    host: &mut impl Host,
    debs: &[PathBuf],
    retained: &BTreeMap<String, String>,
    installed: &BTreeMap<String, String>,
    options: &[String],
    environment: &[(String, String)],
) -> Result<(), Failure> {
    let artifacts: BTreeSet<String> = debs
        .iter()
        .filter_map(|path| path.file_stem()?.to_str().map(str::to_string))
        .collect();
    if artifacts.len() != debs.len() {
        return Err(Failure::new("packages", "Invalid artifact filenames"));
    }
    let names: Vec<_> = artifacts.iter().cloned().collect();
    let mut invocation = Invocation::new(&[
        "apt-get",
        "install",
        "--yes",
        "--no-install-recommends",
        "--no-remove",
        "--allow-downgrades",
        "-o",
        "Dpkg::Options::=--force-confold",
    ]);
    invocation.environment = environment
        .iter()
        .filter(|(key, _)| key != "LC_ALL")
        .cloned()
        .collect();
    invocation.environment.push(("LC_ALL".into(), "C".into()));
    invocation.argv.extend_from_slice(options);
    invocation.argv.push("--".into());
    invocation
        .argv
        .extend(debs.iter().map(|path| path.display().to_string()));
    invocation.argv.extend(
        retained
            .iter()
            .map(|(name, version)| format!("{name}={version}")),
    );
    let mut simulation = invocation.clone();
    simulation.argv.insert(2, "--simulate".into());
    let plan = package_command(host, &simulation, &names)?;
    let pattern = expression(r"\AInst (\S+)(?: \[([^]]+)\])? \((\S+)");
    for line in plan.stdout.lines().filter(|line| line.starts_with("Inst ")) {
        let entry = pattern
            .captures(line)
            .ok_or_else(|| Failure::new("packages", "Malformed APT installation plan"))?;
        let name = entry[1].split(':').next().unwrap();
        let old = entry
            .get(2)
            .map(|v| v.as_str())
            .or_else(|| installed.get(name).map(String::as_str));
        if let Some(old) = old.filter(|_| !artifacts.contains(name)) {
            let comparison = run(
                host,
                &Invocation::new(&["dpkg", "--compare-versions", &entry[3], "lt", old]),
                false,
            )?;
            if comparison.code != 1 {
                let mut failure = Failure::new(
                    "packages",
                    "Refusing a dependency downgrade or failed version comparison",
                );
                failure.packages = vec![name.into()];
                return Err(failure);
            }
        }
    }
    package_command(host, &invocation, &names)?;
    Ok(())
}

#[cfg(test)]
mod tests;
