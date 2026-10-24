use super::*;
use std::{
    io::Write,
    os::unix::fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
    time::Duration,
};

fn digest(path: &Path) -> Result<String> {
    crate::command::run::file_sha256(path).map_err(Failure::io)
}
fn fields(host: &mut impl RecipeHost, path: &Path) -> Result<BTreeMap<String, String>> {
    Ok(invoke(
        host,
        &[
            "dpkg-deb",
            "--field",
            &path.display().to_string(),
            "Package",
            "Version",
            "Architecture",
        ],
        true,
    )?
    .stdout
    .lines()
    .filter_map(|line| line.split_once(": ").map(|(k, v)| (k.into(), v.into())))
    .collect())
}
fn previous_metadata(
    host: &mut impl RecipeHost,
    path: &Path,
    name: &str,
    version: &str,
    arch: &str,
) -> Result<bool> {
    let fields = fields(host, path)?;
    Ok(fields.get("Package").map(String::as_str) == Some(name)
        && fields.get("Version").map(String::as_str) == Some(version)
        && fields
            .get("Architecture")
            .is_some_and(|v| v == arch || v == "all"))
}
pub fn keep(files: &Files, source: &Path, name: &str) -> Result<PathBuf> {
    let hash = digest(source)?;
    let directory = files.path(CACHE);
    directories(files, &directory)?;
    let target = directory.join(format!("{name}_{hash}.deb"));
    if let Ok(meta) = fs::symlink_metadata(&target) {
        if !meta.is_file() || meta.uid() != files.uid {
            return Err(Failure::io("PHP package cache target is not an owned file"));
        }
        if digest(&target)? == hash {
            return Ok(target);
        }
    }
    let candidate = directory.join(format!(".orbit-php-{:032x}", rand::random::<u128>()));
    let result = (|| -> Result<()> {
        let mut output = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&candidate)
            .map_err(Failure::io)?;
        let mut input = fs::File::open(source).map_err(Failure::io)?;
        std::io::copy(&mut input, &mut output).map_err(Failure::io)?;
        output.flush().map_err(Failure::io)?;
        output
            .set_permissions(fs::Permissions::from_mode(0o644))
            .map_err(Failure::io)?;
        rustix::fs::chown(
            &candidate,
            Some(rustix::fs::Uid::from_raw(files.uid)),
            Some(rustix::fs::Gid::from_raw(files.gid)),
        )
        .map_err(Failure::io)?;
        output.sync_all().map_err(Failure::io)?;
        if digest(&candidate)? != hash {
            return Err(Failure::package(
                Kind::DownloadFailed,
                name,
                "cache copy digest mismatch",
            ));
        }
        fs::rename(&candidate, &target).map_err(Failure::io)
    })();
    let _ = fs::remove_file(&candidate);
    result?;
    Ok(target)
}
pub fn download(
    host: &mut impl RecipeHost,
    files: &Files,
    pins: &[Pin],
    mirror: &str,
    work: &Path,
    architecture: &str,
) -> Result<Vec<PathBuf>> {
    let mut found = BTreeMap::new();
    let mut remaining = BTreeMap::new();
    let by_name: BTreeMap<_, _> = pins.iter().map(|p| (p.name.clone(), p)).collect();
    for pin in pins {
        let cached = files
            .path(CACHE)
            .join(format!("{}_{}.deb", pin.name, pin.sha256));
        if cached.is_file() && digest(&cached)? == pin.sha256 {
            found.insert(pin.name.clone(), cached);
        } else {
            let mut urls = vec![];
            if !mirror.is_empty() {
                urls.push(format!("{mirror}/{}_{}.deb", pin.name, pin.sha256));
            }
            if !urls.contains(&pin.url) {
                urls.push(pin.url.clone());
            }
            remaining.insert(pin.name.clone(), std::collections::VecDeque::from(urls));
        }
    }
    while !remaining.is_empty() {
        let mut argv: Vec<String> = [
            "curl",
            "--parallel",
            "--parallel-immediate",
            "--parallel-max",
            "4",
        ]
        .map(str::to_string)
        .into();
        let limit = (300.0 / remaining.len().div_ceil(4) as f64).to_string();
        let mut candidates = BTreeMap::new();
        for (name, urls) in &mut remaining {
            let url = urls.pop_front().ok_or_else(|| {
                Failure::package(
                    Kind::DownloadFailed,
                    name,
                    "no source served the pinned file; Sury may have a newer build",
                )
            })?;
            let candidate = work.join(format!("{name}.deb"));
            remove(&candidate)?;
            if !candidates.is_empty() {
                argv.push("--next".into());
            }
            argv.extend(
                [
                    "--fail",
                    "--silent",
                    "--show-error",
                    "--location",
                    "--proto",
                    "=https",
                    "--proto-redir",
                    "=https",
                    "--tlsv1.2",
                    "--connect-timeout",
                    "15",
                    "--max-time",
                    &limit,
                    "--max-filesize",
                    "536870912",
                    "--write-out",
                    "%{json}\n",
                    "--output",
                    &candidate.display().to_string(),
                    "--url",
                    &url,
                ]
                .map(str::to_string),
            );
            candidates.insert(name.clone(), candidate);
        }
        let mut call = Invocation::new(&argv.iter().map(String::as_str).collect::<Vec<_>>())
            .env("LC_ALL", "C");
        call.timeout = Duration::from_secs(300);
        let completed = recipe::run(host, &call, false)?;
        #[derive(Deserialize)]
        struct Transfer {
            filename_effective: PathBuf,
            exitcode: i32,
        }
        let reports = completed
            .stdout
            .lines()
            .map(serde_json::from_str::<Transfer>)
            .collect::<std::result::Result<Vec<_>, _>>()
            .map_err(|_| {
                Failure::new(Kind::DownloadFailed, "curl did not report every transfer")
            })?;
        let count = reports.len();
        let results: BTreeMap<_, _> = reports
            .into_iter()
            .map(|r| (r.filename_effective, r.exitcode))
            .collect();
        if count != candidates.len()
            || results.keys().cloned().collect::<BTreeSet<_>>()
                != candidates.values().cloned().collect()
        {
            return Err(Failure::new(
                Kind::DownloadFailed,
                "curl did not report every transfer",
            ));
        }
        for (name, path) in candidates {
            if results[&path] != 0 {
                continue;
            }
            let pin = by_name[&name];
            if digest(&path)? != pin.sha256 {
                return Err(Failure::package(
                    Kind::DownloadFailed,
                    &name,
                    "artifact digest mismatch",
                ));
            }
            if !previous_metadata(host, &path, &name, &pin.version, architecture)? {
                return Err(Failure::package(
                    Kind::DownloadFailed,
                    &name,
                    "artifact metadata mismatch",
                ));
            }
            found.insert(name.clone(), keep(files, &path, &name)?);
            remaining.remove(&name);
        }
    }
    Ok(pins.iter().map(|pin| found[&pin.name].clone()).collect())
}
fn matches_installed(
    host: &mut impl RecipeHost,
    files: &Files,
    path: &Path,
    name: &str,
    work: &Path,
) -> Result<bool> {
    let info = files.path("/var/lib/dpkg/info");
    let mut possible = vec![info.join(format!("{name}.md5sums"))];
    possible.extend(glob(&info.join(format!("{name}:*.md5sums")))?);
    let Some(sums) = possible.into_iter().find(|p| p.is_file()) else {
        return Ok(false);
    };
    let directory = work.join(format!("extract-{:016x}", rand::random::<u64>()));
    fs::create_dir(&directory).map_err(Failure::io)?;
    if invoke(
        host,
        &[
            "dpkg-deb",
            "--extract",
            "--",
            &path.display().to_string(),
            &directory.display().to_string(),
        ],
        false,
    )?
    .code
        != 0
    {
        return Ok(false);
    }
    for line in fs::read_to_string(sums).map_err(Failure::io)?.lines() {
        let Some((expected, relative)) = line.split_once("  ") else {
            return Ok(false);
        };
        let relative = Path::new(relative.trim());
        if relative.is_absolute()
            || relative
                .components()
                .any(|c| matches!(c, std::path::Component::ParentDir))
        {
            return Ok(false);
        }
        let member = directory.join(relative);
        if !member.is_file() {
            return Ok(false);
        }
        let sum = invoke(host, &["md5sum", "--", &member.display().to_string()], true)?;
        if sum.stdout.split_whitespace().next() != Some(expected.trim()) {
            return Ok(false);
        }
    }
    Ok(true)
}
fn repack(
    host: &mut impl RecipeHost,
    files: &Files,
    name: &str,
    packages: &mut Installed,
    pin: &Repack,
    work: &Path,
    arch: &str,
) -> Result<PathBuf> {
    let version = packages[name].clone();
    if !packages.contains_key("dpkg-repack") {
        let mut call = Invocation::new(&[
            "apt-get",
            "-o",
            "DPkg::Lock::Timeout=300",
            "install",
            "--yes",
            "--no-install-recommends",
            "--no-remove",
            &format!("{}={}", pin.package, pin.version),
        ])
        .env("DEBIAN_FRONTEND", "noninteractive");
        call.timeout = Duration::from_secs(300);
        if recipe::run(host, &call, false)?.code != 0 {
            return Err(Failure::package(
                Kind::RollbackUnavailable,
                name,
                "dpkg-repack could not be installed",
            ));
        }
        packages.insert("dpkg-repack".into(), pin.version.clone());
    }
    let directory = work.join(format!("repack-{:016x}", rand::random::<u64>()));
    fs::create_dir(&directory).map_err(Failure::io)?;
    invoke(
        host,
        &[
            "env",
            "-C",
            &directory.display().to_string(),
            "dpkg-repack",
            "--generate",
            "--",
            name,
        ],
        false,
    )?;
    let trees: Vec<_> = paths(&directory)?
        .into_iter()
        .filter(|p| {
            p.is_dir()
                && p.file_name().is_some_and(|n| {
                    n.to_string_lossy()
                        .starts_with(&format!("dpkg-repack.{name}."))
                })
        })
        .collect();
    let [tree] = trees.as_slice() else {
        return Err(Failure::package(
            Kind::RollbackUnavailable,
            name,
            "dpkg-repack generated no build tree",
        ));
    };
    let conffiles = tree.join("DEBIAN/conffiles");
    let listed = match fs::read_to_string(&conffiles) {
        Ok(v) => v,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => String::new(),
        Err(e) => return Err(Failure::io(e)),
    };
    let kept: Vec<_> = listed
        .lines()
        .filter(|line| {
            !line.trim().is_empty() && tree.join(line.trim().trim_start_matches('/')).exists()
        })
        .collect();
    if kept.len() != listed.lines().filter(|l| !l.trim().is_empty()).count() {
        if kept.is_empty() {
            remove(&conffiles)?;
        } else {
            files.publish_root(&conffiles, format!("{}\n", kept.join("\n")).as_bytes())?;
        }
    }
    let output = directory.join(format!("{name}_repacked.deb"));
    invoke(
        host,
        &[
            "dpkg-deb",
            "--build",
            "--",
            &tree.display().to_string(),
            &output.display().to_string(),
        ],
        false,
    )?;
    if !output.is_file() || !previous_metadata(host, &output, name, &version, arch)? {
        return Err(Failure::package(
            Kind::RollbackUnavailable,
            name,
            "dpkg-deb wrote no file of the installed version",
        ));
    }
    Ok(output)
}
pub fn previous(
    host: &mut impl RecipeHost,
    files: &Files,
    names: &[String],
    packages: &mut Installed,
    repack_pin: &Repack,
    work: &Path,
    architecture: &str,
) -> Result<BTreeMap<String, PathBuf>> {
    let mut found = BTreeMap::new();
    for name in names {
        let version = packages[name].clone();
        for cached in glob(&files.path(CACHE).join(format!("{name}_*.deb")))? {
            if cached.file_stem().unwrap().to_string_lossy()
                == format!("{name}_{}", digest(&cached)?)
                && previous_metadata(host, &cached, name, &version, architecture)?
            {
                found.insert(name.clone(), cached);
                break;
            }
        }
        if found.contains_key(name) {
            continue;
        }
        for archived in glob(&files.path(ARCHIVES).join(format!("{name}_*.deb")))? {
            if previous_metadata(host, &archived, name, &version, architecture)?
                && matches_installed(host, files, &archived, name, work)?
            {
                found.insert(name.clone(), keep(files, &archived, name)?);
                break;
            }
        }
        if !found.contains_key(name) {
            let path = repack(host, files, name, packages, repack_pin, work, architecture)?;
            found.insert(name.clone(), keep(files, &path, name)?);
        }
    }
    Ok(found)
}
pub fn install(
    host: &mut impl RecipeHost,
    files: &Files,
    paths: &[PathBuf],
    reinstall: bool,
) -> Result<bool> {
    let policy = files.path("/usr/sbin/policy-rc.d");
    let owned = fs::symlink_metadata(&policy).is_err();
    let guard = if owned {
        Some(recipe::operations::Policy::install(files)?)
    } else {
        None
    };
    let mut call = Invocation::new(&[
        "apt-get",
        "-o",
        "DPkg::Lock::Timeout=300",
        "-o",
        "Dpkg::Options::=--force-confold",
        "install",
        "--yes",
        "--no-install-recommends",
        "--no-remove",
        "--allow-downgrades",
    ])
    .env("DEBIAN_FRONTEND", "noninteractive")
    .env("NEEDRESTART_SUSPEND", "1");
    if reinstall {
        call.argv.push("--reinstall".into());
    }
    call.argv.push("--".into());
    call.argv
        .extend(paths.iter().map(|p| p.display().to_string()));
    let result = recipe::run(host, &call, false)
        .map(|r| r.code == 0)
        .map_err(Into::into);
    drop(guard);
    result
}
