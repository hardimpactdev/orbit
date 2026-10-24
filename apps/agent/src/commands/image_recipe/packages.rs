use super::*;

pub fn install(
    host: &mut impl php_build::Host,
    files: &Files,
    input: &Input,
    deadline: Instant,
) -> Result<(), Failure> {
    let config = apt_check(input, &files.path(APT))?;
    let mut host = AptHost { host, config };
    let recipe = &input.recipe;
    let policy = recipe::operations::Policy::install(files)?;
    let environment = vec![
        ("DEBIAN_FRONTEND".into(), "noninteractive".into()),
        ("NEEDRESTART_SUSPEND".into(), "1".into()),
    ];
    let mut system = recipe.clone();
    system
        .tools
        .retain(|_, p| p.source == Source::Ubuntu || p.deb());
    let assess = |host: &mut AptHost<'_, _>| -> Result<assessment::Report, Failure> {
        let installed = apt::packages(host)?;
        let versions = system
            .tools
            .iter()
            .map(|(name, pin)| {
                (
                    name.clone(),
                    installed
                        .get(pin.package.as_deref().unwrap_or(name))
                        .cloned(),
                )
            })
            .collect();
        assessment::assess(
            host,
            files,
            &system,
            &versions,
            None,
            &mut apt::Snapshots::retained_only(),
        )
    };
    ownership::check_git_siblings(&mut host, recipe)?;
    assessment::supported(&assess(&mut host)?)?;
    ownership::configure_git(files, recipe, true)?;
    ownership::configure_incus(&mut host, files, recipe, true)?;
    let mut upgrade = Invocation::new(&["apt-get", "full-upgrade", "--yes"]);
    upgrade.environment = environment.clone();
    recipe::run(&mut host, &upgrade, true)?;
    let assessed = assess(&mut host)?;
    assessment::supported(&assessed)?;
    let ubuntu: Vec<_> = system
        .tools
        .iter()
        .filter(|(n, p)| p.source == Source::Ubuntu && assessed[*n].changed)
        .map(|(_, p)| format!("{}={}", p.package.as_deref().unwrap(), p.version))
        .collect();
    if !ubuntu.is_empty() {
        let mut call = Invocation::new(&[
            "apt-get",
            "install",
            "--yes",
            "--no-install-recommends",
            "--no-remove",
            "--",
        ]);
        call.environment = environment.clone();
        call.argv.extend(ubuntu.clone());
        apt::package_command(&mut host, &call, &ubuntu)?;
    }
    let installed = apt::packages(&mut host)?;
    let work = WorkDir::new()?;
    let mut debs = vec![];
    for (name, pin) in system
        .tools
        .iter()
        .filter(|(n, p)| p.deb() && installed.get(*n) != Some(&p.version))
    {
        let path = work.0.join(format!("{name}.deb"));
        artifacts::download(&mut host, name, pin, recipe, &path)?;
        artifacts::verify_deb(&mut host, &path, name, pin, recipe)?;
        debs.push(path);
    }
    if !debs.is_empty() {
        let retained = system
            .tools
            .iter()
            .filter(|(_, p)| p.source == Source::Ubuntu)
            .filter_map(|(_, p)| {
                installed
                    .get(p.package.as_ref()?)
                    .map(|v| (p.package.clone().unwrap(), v.clone()))
            })
            .collect();
        apt::install_exact_debs(&mut host, &debs, &retained, &installed, &[], &environment)?;
    }
    let mut check = Invocation::new(&["apt-get", "check"]);
    check.environment = environment.clone();
    recipe::run(&mut host, &check, true)?;
    apt::audit(&mut host, &environment)?;
    let versions = apt::packages(&mut host)?
        .into_iter()
        .map(|(n, v)| (n, Some(v)))
        .collect();
    ownership::record_receipt(files, &recipe.git(), &versions, "git")?;
    ownership::record_receipt(files, &recipe.incus(), &versions, "incus")?;
    ownership::pin(files, &recipe.git(), "git")?;
    ownership::pin(files, &recipe.incus(), "incus")?;
    drop(policy);
    if let Some(php) = &input.php {
        php_build::operations::apply(&mut host, files, php, deadline)
            .map_err(|e| Failure::new("php", serde_json::to_string(&e.data).unwrap()))?;
    }
    for unit in ["ssh", "docker", "containerd"] {
        let call = Invocation::new(&["systemctl", "show", "-p", "LoadState", "--value", unit]);
        let result = recipe::run(&mut host, &call, false)?;
        let state = result.stdout.trim();
        if (result.code != 0 && !(result.code == 4 && state == "not-found"))
            || !["loaded", "not-found", "masked"].contains(&state)
        {
            return Err(Failure::command(&call, &result).at("services"));
        }
        if state == "loaded" {
            recipe::run(
                &mut host,
                &Invocation::new(&["systemctl", "enable", unit]),
                true,
            )?;
        }
    }
    if recipe.tools.contains_key("incus") {
        recipe::run(
            &mut host,
            &Invocation::new(&["usermod", "--append", "--groups", "incus-admin", "orbit"]),
            true,
        )?;
    }
    Ok(())
}
