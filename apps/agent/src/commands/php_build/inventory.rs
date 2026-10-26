use super::*;

pub fn installed(host: &mut impl RecipeHost) -> Result<Installed> {
    let result = invoke(
        host,
        &["dpkg-query", "-W", "-f=${Package}\t${Status}\t${Version}\n"],
        true,
    )?;
    let mut packages = Installed::new();
    for line in result.stdout.lines() {
        let fields: Vec<_> = line.split('\t').collect();
        if fields.len() != 3 {
            return Err(Failure::io("Malformed PHP package inventory"));
        }
        if fields[1] == "install ok installed" {
            packages.insert(fields[0].into(), fields[2].into());
        }
    }
    Ok(packages)
}
pub fn selected(input: &Input, architecture: &str) -> Result<Builds> {
    let mut versions = Builds::new();
    let mut shared: BTreeMap<&str, &Pin> = BTreeMap::new();
    for (version, build) in &input.versions {
        let pins: BTreeMap<_, _> = build
            .packages
            .iter()
            .filter(|p| p.architecture == architecture)
            .map(|p| (p.name.clone(), p.clone()))
            .collect();
        if let Some(missing) = build.packages.iter().find(|p| !pins.contains_key(&p.name)) {
            return Err(Failure::package(
                Kind::DownloadFailed,
                &missing.name,
                format!("PHP is not available for {architecture}"),
            ));
        }
        for pin in build
            .packages
            .iter()
            .filter(|p| p.architecture == architecture)
        {
            if let Some(prior) = shared.insert(&pin.name, pin) {
                if prior.version != pin.version
                    || prior.sha256 != pin.sha256
                    || prior.url != pin.url
                {
                    return Err(Failure::package(
                        Kind::InputInvalid,
                        &pin.name,
                        "Conflicting shared PHP pin",
                    ));
                }
            }
        }
        versions.insert(
            version.clone(),
            Selected {
                pins,
                extensions: build.extensions.clone(),
            },
        );
    }
    Ok(versions)
}
pub fn sources(files: &Files) -> Result<Vec<PathBuf>> {
    let pattern = regex::Regex::new(
        r"(?i)ppa\.launchpadcontent\.net/ondrej/php|packages\.sury\.org/php|ondrej/php",
    )
    .unwrap();
    let mut found = vec![];
    let mut candidates = vec![files.path("/etc/apt/sources.list")];
    candidates.extend(paths(&files.path("/etc/apt/sources.list.d"))?);
    for path in candidates {
        if path == files.path(RETIRED_SOURCE) || !path.is_file() {
            continue;
        }
        if path
            .extension()
            .is_some_and(|e| e != "list" && e != "sources")
        {
            continue;
        }
        if pattern.is_match(&String::from_utf8_lossy(
            &fs::read(&path).map_err(Failure::io)?,
        )) {
            found.push(path);
        }
    }
    Ok(found)
}
pub fn extras(versions: &Builds, packages: &Installed) -> Vec<String> {
    let mut extras = BTreeSet::new();
    for (version, build) in versions {
        let prefix = format!("php{version}-");
        extras.extend(
            packages
                .keys()
                .filter(|name| {
                    name.starts_with(&prefix)
                        && !build.pins.contains_key(*name)
                        && **name != format!("{prefix}opentelemetry")
                })
                .cloned(),
        );
    }
    extras.into_iter().collect()
}
pub fn damaged(host: &mut impl RecipeHost, names: &[String]) -> Result<Vec<String>> {
    let mut found = vec![];
    for name in names {
        let result = invoke(host, &["dpkg", "--verify", "--", name], false)?;
        for line in result.stdout.lines() {
            let fields: Vec<_> = line.split_whitespace().collect();
            if fields.len() < 2 || (fields.len() == 3 && fields[1] == "c") {
                continue;
            }
            if fields[0] == "missing" || fields[0].as_bytes().get(2) == Some(&b'5') {
                found.push(name.clone());
                break;
            }
        }
        if ![0, 1].contains(&result.code) {
            return Err(Failure::io(
                "dpkg could not verify the installed PHP package",
            ));
        }
    }
    Ok(found)
}
pub fn owned(host: &mut impl RecipeHost, paths: &[PathBuf]) -> Result<BTreeSet<PathBuf>> {
    if paths.is_empty() {
        return Ok(BTreeSet::new());
    }
    let wanted: BTreeSet<_> = paths.iter().cloned().collect();
    let mut args = vec!["dpkg".to_string(), "-S".into(), "--".into()];
    args.extend(paths.iter().map(|p| p.display().to_string()));
    let result = invoke(
        host,
        &args.iter().map(String::as_str).collect::<Vec<_>>(),
        false,
    )?;
    if ![0, 1].contains(&result.code) {
        return Err(Failure::io(format!("dpkg -S exited {}", result.code)));
    }
    Ok(result
        .stdout
        .lines()
        .filter_map(|line| line.split_once(": ").map(|(_, p)| PathBuf::from(p)))
        .filter(|p| wanted.contains(p))
        .collect())
}
#[derive(Debug)]
pub struct Unowned {
    pub module: String,
    pub files: Vec<PathBuf>,
}
pub fn unowned(
    host: &mut impl RecipeHost,
    files: &Files,
    version: &str,
    extensions: &BTreeSet<String>,
) -> Result<Vec<Unowned>> {
    let pattern = regex::Regex::new(r#"(?m)^\s*(?:zend_)?extension\s*=\s*"?([^"\s;]+)"#).unwrap();
    let mut loading = BTreeMap::new();
    let mut all = vec![];
    for ini in paths(&files.path(&format!("/etc/php/{version}/mods-available")))? {
        if ini.extension().is_none_or(|e| e != "ini") {
            continue;
        }
        let content = fs::read(&ini).map_err(Failure::io)?;
        let mut libraries = vec![];
        for entry in pattern.captures_iter(&String::from_utf8_lossy(&content)) {
            let library = PathBuf::from(&entry[1]);
            let stem = library
                .file_stem()
                .and_then(|s| s.to_str())
                .unwrap_or_default()
                .to_lowercase();
            let module = if stem == "opcache" {
                "zend opcache".into()
            } else {
                stem.clone()
            };
            let candidates = if library.is_absolute() {
                vec![files.path(&library.display().to_string())]
            } else {
                glob(&files.path(&format!("/usr/lib/php/*/{stem}.so")))?
            };
            let present: Vec<_> = candidates.into_iter().filter(|p| p.is_file()).collect();
            all.extend(present.iter().cloned());
            libraries.push((module, present));
        }
        if !libraries.is_empty() {
            all.push(ini.clone());
            loading.insert(ini, libraries);
        }
    }
    let known = owned(host, &all)?;
    let mut found = vec![];
    for (ini, libraries) in loading {
        if known.contains(&ini) {
            continue;
        }
        let mut modules = BTreeSet::new();
        let mut files = vec![ini];
        for (module, present) in libraries {
            let unowned: Vec<_> = present.into_iter().filter(|p| !known.contains(p)).collect();
            if !extensions.contains(&module) || !unowned.is_empty() {
                modules.insert(module);
            }
            files.extend(unowned);
        }
        if !modules.is_empty() {
            found.push(Unowned {
                module: modules.into_iter().collect::<Vec<_>>().join("+"),
                files,
            });
        }
    }
    Ok(found)
}
