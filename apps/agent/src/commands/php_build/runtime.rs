use super::*;
use std::{
    io::{Read, Write},
    os::unix::{fs::symlink, net::UnixStream},
    time::{Duration, Instant},
};

pub type Links = BTreeMap<String, BTreeMap<String, PathBuf>>;
pub fn links(files: &Files, version: &str) -> Result<Links> {
    let mut result = Links::new();
    for sapi in ["cli", "fpm"] {
        let mut found = BTreeMap::new();
        for path in paths(&files.path(&format!("/etc/php/{version}/{sapi}/conf.d")))? {
            if path.extension().is_some_and(|e| e == "ini") && path.is_symlink() {
                found.insert(
                    path.file_name().unwrap().to_string_lossy().into_owned(),
                    fs::read_link(path).map_err(Failure::io)?,
                );
            }
        }
        result.insert(sapi.into(), found);
    }
    Ok(result)
}
pub fn restore_links(files: &Files, version: &str, wanted: &Links) -> Result<()> {
    let current = links(files, version)?;
    for (sapi, links) in wanted {
        let directory = files.path(&format!("/etc/php/{version}/{sapi}/conf.d"));
        for (name, target) in &current[sapi] {
            if links.get(name) != Some(target) {
                remove(&directory.join(name))?;
            }
        }
        for (name, target) in links {
            if current[sapi].get(name) == Some(target) {
                continue;
            }
            let path = directory.join(name);
            if fs::symlink_metadata(&path).is_ok() {
                return Err(Failure::io(
                    "A PHP module file blocks restoration of its link",
                ));
            }
            symlink(target, &path).map_err(Failure::io)?;
        }
    }
    Ok(())
}
pub fn disabled_pools(files: &Files, versions: &Builds, packages: &Installed) -> Vec<PathBuf> {
    versions
        .keys()
        .filter(|v| packages.contains_key(&format!("php{v}-fpm")))
        .map(|v| files.path(&format!("/etc/php/{v}/fpm/pool.d/www.conf")))
        .filter(|p| fs::symlink_metadata(p).is_err())
        .collect()
}
pub fn keep_disabled(pools: &[PathBuf]) -> Result<()> {
    for path in pools {
        remove(path)?;
    }
    Ok(())
}
pub fn disable_unowned(
    files: &Files,
    version: &str,
    unowned: &[inventory::Unowned],
    backup: &Path,
    moved: &mut Vec<(PathBuf, PathBuf)>,
) -> Result<()> {
    for entry in unowned {
        let ini = &entry.files[0];
        for (sapi, links) in links(files, version)? {
            for (name, target) in links {
                if target.file_name() == ini.file_name() {
                    remove(&files.path(&format!("/etc/php/{version}/{sapi}/conf.d/{name}")))?;
                }
            }
        }
        for path in &entry.files {
            if moved.iter().any(|(p, _)| p == path) {
                continue;
            }
            let kept = backup
                .join(version)
                .join(path.strip_prefix(&files.root).map_err(Failure::io)?);
            directories(files, kept.parent().unwrap())?;
            fs::rename(path, &kept).map_err(Failure::io)?;
            moved.push((path.clone(), kept));
        }
    }
    Ok(())
}
pub fn place_modules(
    host: &mut impl RecipeHost,
    version: &str,
    pcov: bool,
    opentelemetry: bool,
) -> Result<()> {
    for (tool, sapi, module) in [
        (if pcov { "phpenmod" } else { "phpdismod" }, "cli", "pcov"),
        ("phpdismod", "fpm", "pcov"),
        ("phpdismod", "cli", "opentelemetry"),
        (
            if opentelemetry {
                "phpenmod"
            } else {
                "phpdismod"
            },
            "fpm",
            "opentelemetry",
        ),
    ] {
        invoke(host, &[tool, "-v", version, "-s", sapi, module], true)?;
    }
    Ok(())
}
pub fn write_ini(files: &Files, path: &Path, content: &[u8]) -> Result<()> {
    directories(files, path.parent().unwrap())?;
    files.publish_root(path, content)?;
    Ok(())
}
pub fn upload_progress(
    files: &Files,
    version: &str,
    previous: &mut BTreeMap<PathBuf, Option<Vec<u8>>>,
) -> Result<()> {
    let content = b"session.upload_progress.enabled = 0\n";
    for sapi in ["cli", "fpm"] {
        let path = files.path(&format!(
            "/etc/php/{version}/{sapi}/conf.d/99-cve-2026-109618-upload-progress.ini"
        ));
        let before = match fs::read(&path) {
            Ok(v) => Some(v),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
            Err(e) => return Err(Failure::io(e)),
        };
        if before.as_deref() != Some(content.as_slice()) {
            previous.insert(path.clone(), before);
            write_ini(files, &path, content)?;
        }
    }
    Ok(())
}
pub fn modules(host: &mut impl RecipeHost, binary: &Path) -> Result<BTreeSet<String>> {
    let result = invoke(host, &[&binary.display().to_string(), "-m"], true)?;
    Ok(result
        .stdout
        .lines()
        .map(str::trim)
        .filter(|l| !l.is_empty() && !l.starts_with('['))
        .map(str::to_lowercase)
        .collect())
}
pub fn check_cli(
    host: &mut impl RecipeHost,
    files: &Files,
    version: &str,
    build: &Selected,
    pcov: bool,
    extras: Extras,
) -> Result<()> {
    let expected: BTreeSet<_> = build
        .extensions
        .iter()
        .filter(|m| pcov || *m != "pcov")
        .cloned()
        .collect();
    let loaded = modules(host, &files.path(&format!("/usr/bin/php{version}")))?;
    let missing: Vec<_> = expected.difference(&loaded).cloned().collect();
    let extra: Vec<_> = if extras == Extras::Keep {
        vec![]
    } else {
        loaded.difference(&expected).cloned().collect()
    };
    if !missing.is_empty() || !extra.is_empty() {
        return Err(Failure {
            kind: Kind::ModulesMismatch,
            data: Box::new(Details {
                version: Some(version.into()),
                missing: Some(missing),
                extra: Some(extra),
                ..Details::default()
            }),
        });
    }
    Ok(())
}
pub fn sockets(files: &Files, configuration: &Path) -> Result<BTreeSet<PathBuf>> {
    let mut pending = vec![configuration.to_owned()];
    let mut seen = BTreeSet::new();
    let mut sockets = BTreeSet::new();
    while let Some(path) = pending.pop() {
        if !seen.insert(path.clone()) || !path.is_file() {
            continue;
        }
        for line in fs::read_to_string(&path).map_err(Failure::io)?.lines() {
            let Some((key, value)) = line.split_once('=') else {
                continue;
            };
            let value = value.split(';').next().unwrap_or_default().trim();
            match key.trim() {
                "include" => {
                    let pattern = if value.starts_with('/') {
                        files.path(value)
                    } else {
                        path.parent().unwrap().join(value)
                    };
                    pending.extend(glob(&pattern)?);
                }
                "listen" if value.starts_with('/') => {
                    sockets.insert(files.path(value));
                }
                _ => {}
            }
        }
    }
    Ok(sockets)
}
pub struct FpmUnits {
    pub active: Vec<(String, PathBuf)>,
    pub skipped: Vec<String>,
}
pub fn fpm_units(
    host: &mut impl RecipeHost,
    files: &Files,
    version: &str,
    scope: Fpm,
) -> Result<FpmUnits> {
    let mut units = vec![];
    let shared = format!("php{version}-fpm.service");
    if invoke(host, &["systemctl", "is-active", "--quiet", &shared], false)?.code == 0 {
        units.push((
            shared.clone(),
            files.path(&format!("/etc/php/{version}/fpm/php-fpm.conf")),
        ));
    }
    let listed = invoke(
        host,
        &[
            "systemctl",
            "list-units",
            "--type=service",
            "--state=active",
            "--no-legend",
            "--plain",
            &format!("orbit-*-php{version}-fpm.service"),
        ],
        false,
    )?;
    let pattern = regex::Regex::new(&format!(
        r"\Aorbit-[a-z0-9_-]+-php{}-fpm\.service\z",
        regex::escape(version)
    ))
    .unwrap();
    let config = regex::Regex::new(r"--fpm-config\s+(\S+)").unwrap();
    for line in listed.stdout.lines() {
        let Some(unit) = line
            .split_whitespace()
            .next()
            .filter(|unit| pattern.is_match(unit))
        else {
            continue;
        };
        let start = invoke(
            host,
            &["systemctl", "show", "-p", "ExecStart", "--value", unit],
            false,
        )?;
        let path = config
            .captures(&start.stdout)
            .map(|c| files.path(&c[1]))
            .unwrap_or_default();
        units.push((unit.into(), path));
    }
    let mut skipped = vec![];
    if scope == Fpm::Shared {
        units.retain(|(unit, _)| {
            if *unit == shared {
                true
            } else {
                skipped.push(unit.clone());
                false
            }
        });
    }
    Ok(FpmUnits {
        active: units,
        skipped,
    })
}
pub struct Refresh<'a> {
    pub version: &'a str,
    pub unit: &'a str,
    pub configuration: &'a Path,
    pub opentelemetry: bool,
    pub action: &'a str,
    pub deadline: Instant,
}
pub fn refresh(
    host: &mut impl Host,
    files: &Files,
    request: &Refresh<'_>,
    touched: &mut Vec<String>,
) -> Result<Option<String>> {
    let Refresh {
        version,
        unit,
        configuration,
        opentelemetry,
        action,
        deadline,
    } = *request;
    let binary = files.path(&format!("/usr/sbin/php-fpm{version}"));
    let mut args = vec![binary.display().to_string(), "-t".into()];
    if !unit.starts_with("php") {
        args.extend(["--fpm-config".into(), configuration.display().to_string()]);
    }
    if invoke(
        host,
        &args.iter().map(String::as_str).collect::<Vec<_>>(),
        false,
    )?
    .code
        != 0
    {
        return Ok(Some("fpm_invalid".into()));
    }
    touched.push(unit.into());
    if invoke(host, &["systemctl", action, unit], false)?.code != 0
        || invoke(host, &["systemctl", "is-active", "--quiet", unit], false)?.code != 0
    {
        return Ok(Some("the service did not start".into()));
    }
    let loaded = modules(host, &binary)?;
    if loaded.contains("pcov")
        || [
            "bcmath",
            "curl",
            "gd",
            "imagick",
            "intl",
            "mbstring",
            "mysqli",
            "pdo_mysql",
            "pdo_pgsql",
            "pdo_sqlite",
            "redis",
            "simplexml",
            "xml",
            "zip",
        ]
        .iter()
        .any(|m| !loaded.contains(*m))
    {
        return Ok(Some(
            "PHP-FPM loads PCOV or lacks a required extension".into(),
        ));
    }
    if loaded.contains("opentelemetry") != opentelemetry {
        return Ok(Some(
            "PHP-FPM OpenTelemetry module does not match the profile".into(),
        ));
    }
    for socket in sockets(files, configuration)? {
        if !host.ping(&socket, deadline) {
            return Ok(Some(format!(
                "the pool at {} does not answer FastCGI",
                socket.display()
            )));
        }
    }
    Ok(None)
}
pub fn disable_fpm(host: &mut impl RecipeHost, versions: &Builds) -> Result<bool> {
    let mut changed = false;
    for version in versions.keys() {
        let unit = format!("php{version}-fpm.service");
        let enabled = invoke(host, &["systemctl", "is-enabled", &unit], false)?
            .stdout
            .trim()
            .to_owned();
        let active = invoke(host, &["systemctl", "is-active", "--quiet", &unit], false)?.code == 0;
        let stopped = enabled != "masked"
            || !active
            || invoke(host, &["systemctl", "stop", &unit], false)?.code == 0;
        let disabled = enabled == "masked"
            || invoke(host, &["systemctl", "disable", "--now", &unit], false)?.code == 0;
        if !stopped
            || !disabled
            || !["disabled", "masked"].contains(
                &invoke(host, &["systemctl", "is-enabled", &unit], false)?
                    .stdout
                    .trim(),
            )
            || invoke(host, &["systemctl", "is-active", "--quiet", &unit], false)?.code == 0
        {
            let mut failure = Failure::new(
                Kind::FpmInvalid,
                "unused PHP-FPM must be disabled and inactive",
            );
            failure.data.unit = Some(unit);
            return Err(failure);
        }
        changed |= active || !["disabled", "masked"].contains(&enabled.as_str());
    }
    Ok(changed)
}
/// A non-existent script proves that each pool completes requests without running project PHP.
pub fn ping(socket: &Path, deadline: Instant) -> std::io::Result<()> {
    use super::super::php_runtime::{parameters, record};
    let deadline = deadline.min(Instant::now() + Duration::from_secs(5));
    let mut connection = UnixStream::connect(socket)?;
    let remaining = || {
        deadline
            .checked_duration_since(Instant::now())
            .ok_or_else(|| {
                std::io::Error::new(std::io::ErrorKind::TimedOut, "FastCGI pool timed out")
            })
    };
    let mut request = record(1, &[0, 1, 0, 0, 0, 0, 0, 0]);
    request.extend(record(
        4,
        &parameters(&[
            ("SCRIPT_FILENAME", "/orbit-health-check.php"),
            ("REQUEST_METHOD", "GET"),
        ]),
    ));
    request.extend(record(4, b""));
    request.extend(record(5, b""));
    connection.set_write_timeout(Some(remaining()?))?;
    connection.write_all(&request)?;
    let mut total = 0usize;
    loop {
        connection.set_read_timeout(Some(remaining()?))?;
        let mut header = [0; 8];
        connection.read_exact(&mut header)?;
        if header[0] != 1 || header[2..4] != [0, 1] {
            return Err(std::io::Error::other("Invalid FastCGI record"));
        }
        let length = usize::from(u16::from_be_bytes([header[4], header[5]]));
        total += 8 + length + usize::from(header[6]);
        if total > 1_048_576 {
            return Err(std::io::Error::other("FastCGI response exceeded its bound"));
        }
        let mut body = vec![0; length + usize::from(header[6])];
        connection.set_read_timeout(Some(remaining()?))?;
        connection.read_exact(&mut body)?;
        if header[1] == 3 {
            return if length == 8 {
                Ok(())
            } else {
                Err(std::io::Error::other("Invalid FastCGI end record"))
            };
        }
    }
}
impl Host for recipe::Native<'_> {
    fn ping(&mut self, socket: &Path, deadline: Instant) -> bool {
        ping(socket, deadline).is_ok()
    }
}
