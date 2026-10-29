//! Ownership of recipe packages, provider files, and the Incus drained-host preflight.
use super::{
    apt, run,
    sources::{self, Change, Provider},
    types::{Input, Tools},
    Failure, Files, Host, Invocation,
};
use std::{
    collections::BTreeMap,
    fs,
    os::unix::fs::symlink,
    path::{Component, Path, PathBuf},
};

pub fn preferences(tools: &Tools) -> Vec<u8> {
    tools.iter().map(|(name,pin)| format!("Package: {name}\nPin: version {}\nPin-Priority: 1001\n\nPackage: {name}\nPin: version *\nPin-Priority: -1\n\n", pin.version)).collect::<String>().into_bytes()
}
fn preference_changed(files: &Files, tools: &Tools, owner: &str) -> Result<bool, Failure> {
    let path = files.apt().join(format!("preferences.d/orbit-{owner}"));
    match fs::symlink_metadata(&path) {
        Ok(meta) if !meta.is_file() => Err(Failure::io(
            "apt-source",
            &path,
            "Preference is a symlink or not a regular file",
        )),
        Ok(_) => Ok(
            fs::read(&path).map_err(|e| Failure::io("apt-source", &path, e))? != preferences(tools),
        ),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(true),
        Err(e) => Err(Failure::io("apt-source", &path, e)),
    }
}
pub fn check_git_siblings(host: &mut impl Host, input: &Input) -> Result<(), Failure> {
    let tools = input.git();
    if tools.is_empty() {
        return Ok(());
    }
    let output = run(
        host,
        &Invocation::new(&[
            "dpkg-query",
            "-W",
            "-f=${Package}\t${source:Package}\t${db:Status-Status}\n",
        ]),
        true,
    )?;
    for line in output.stdout.lines() {
        let fields: Vec<_> = line.splitn(3, '\t').collect();
        if fields.len() != 3 {
            return Err(Failure::new(
                "inventory:git",
                "Invalid Git package inventory",
            ));
        }
        if fields[1] == "git" && fields[2] == "installed" && !tools.contains_key(fields[0]) {
            return Err(Failure::new(
                &format!("git-dependency:{}", fields[0]),
                "A Git sibling outside the recipe is installed",
            ));
        }
    }
    Ok(())
}
pub fn configure_git(files: &Files, input: &Input, apply: bool) -> Result<Vec<String>, Failure> {
    let tools = input.git();
    if tools.is_empty() {
        return Ok(vec![]);
    }
    let changes = sources::provider_changes(files, Provider::Git)?;
    let mut actions = vec![];
    if !changes.is_empty() {
        actions.push("retire_git_source".into());
    }
    if preference_changed(files, &tools, "git")? {
        actions.push("pin_git".into());
    }
    if apply {
        sources::retire(files, &changes, "git")?;
    }
    Ok(actions)
}
pub fn configure_incus(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    apply: bool,
) -> Result<Vec<String>, Failure> {
    let tools = input.incus();
    if tools.is_empty() {
        return Ok(vec![]);
    }
    if tools.len() != 3 {
        return Err(Failure::new(
            "incus:incomplete",
            "All three Incus packages must be selected",
        ));
    }
    let changes = sources::provider_changes(files, Provider::Incus)?;
    let pin_changed = preference_changed(files, &tools, "incus")?;
    let mut actions = vec![];
    if !changes.is_empty() {
        actions.push("retire_incus_source".into());
    }
    if apt::packages(host)?.contains_key("incus-agent") {
        actions.push("remove_incus_agent".into());
    }
    if pin_changed {
        actions.push("pin_incus".into());
    }
    if apply {
        sources::retire(files, &changes, "incus")?;
    }
    Ok(actions)
}
pub fn pin(files: &Files, tools: &Tools, owner: &str) -> Result<(), Failure> {
    if tools.is_empty() {
        return Ok(());
    }
    files.publish_root(
        &files.apt().join(format!("preferences.d/orbit-{owner}")),
        &preferences(tools),
    )
}
pub fn record_receipt(
    files: &Files,
    tools: &Tools,
    versions: &BTreeMap<String, Option<String>>,
    owner: &str,
) -> Result<(), Failure> {
    if tools.is_empty() {
        return Ok(());
    }
    if tools
        .iter()
        .any(|(name, pin)| versions.get(name).and_then(|v| v.as_ref()) != Some(&pin.version))
    {
        return Err(Failure::new(
            &format!("inventory:{owner}"),
            "Installed versions do not match the recipe",
        ));
    }
    let receipt: BTreeMap<_, _> = tools
        .iter()
        .map(|(name, pin)| (name, &pin.version))
        .collect();
    files.publish_root(
        &files.apt().join(format!("orbit-{owner}.json")),
        &serde_json::to_vec(&receipt).expect("string map"),
    )
}
pub fn repair_ubuntu(host: &mut impl Host, files: &Files) -> Result<(), Failure> {
    let Some(content) = sources::ubuntu_repair(files)? else {
        return Ok(());
    };
    let path = files.apt().join("sources.list.d/ubuntu.sources");
    let backup = sources::backup(files, &path, &content, "snapshot", false)?;
    files.publish_root(&path, sources::rolling_source().as_bytes())?;
    run(
        host,
        &Invocation::new(&[
            "logger",
            "--tag",
            "orbit-recipe",
            "--",
            &format!(
                "Restored generic Ubuntu sources in {}; backup: {}",
                path.display(),
                backup.display()
            ),
        ]),
        true,
    )?;
    Ok(())
}
pub fn incus_preflight(host: &mut impl Host, files: &Files, input: &Input) -> Result<(), Failure> {
    let tools = input.incus();
    if tools.is_empty() {
        return Ok(());
    }
    let installed = apt::packages(host)?;
    let mut change = tools
        .iter()
        .any(|(name, pin)| installed.get(name) != Some(&pin.version));
    if !change {
        change = !configure_incus(host, files, input, false)?.is_empty();
    }
    if !change {
        change = apt::packages(host)?.contains_key("incus-agent");
    }
    if !change || (!installed.contains_key("incus") && !installed.contains_key("incus-base")) {
        return Ok(());
    }
    let output = run(
        host,
        &Invocation::new(&["incus", "list", "--all-projects", "--format=json"]),
        true,
    )?;
    let inventory: serde_json::Value =
        serde_json::from_str(&output.stdout).map_err(|e| Failure::new("incus:inventory", e))?;
    let instances = inventory
        .as_array()
        .ok_or_else(|| Failure::new("incus:inventory", "Expected an instance list"))?;
    let mut active = vec![];
    for instance in instances {
        let fields = ["project", "name", "type", "status"].map(|key| {
            instance
                .get(key)
                .and_then(serde_json::Value::as_str)
                .filter(|s| !s.is_empty())
        });
        let [Some(project), Some(name), Some(kind), Some(status)] = fields else {
            return Err(Failure::new("incus:inventory", "Invalid instance"));
        };
        if status != "Stopped" {
            active.push(format!("{project}/{name} ({kind}, {status})"));
        }
    }
    active.sort();
    if !active.is_empty() {
        return Err(Failure::new(
            "incus:running",
            format!("Drain active instances: {}", active.join(", ")),
        ));
    }
    Ok(())
}
pub fn remove_incus_agent(host: &mut impl Host, input: &Input) -> Result<(), Failure> {
    if !input.incus().is_empty() && apt::packages(host)?.contains_key("incus-agent") {
        run(
            host,
            &Invocation::new(&["dpkg", "--remove", "incus-agent"]),
            true,
        )?;
    }
    Ok(())
}
pub fn incus_firmware(files: &Files, input: &Input) -> Result<(), Failure> {
    if input.incus().is_empty() {
        return Ok(());
    }
    let firmware = files.path("/opt/incus/share/qemu");
    let aliases = [
        ("OVMF_CODE_4M.ms.fd", "OVMF_CODE.4MB.fd"),
        ("OVMF_CODE_4M.secboot.fd", "OVMF_CODE.4MB.fd"),
        ("OVMF_CODE_4M.fd", "OVMF_CODE.4MB.fd"),
        ("OVMF_VARS_4M.ms.fd", "OVMF_VARS.4MB.ms.fd"),
        ("OVMF_VARS_4M.fd", "OVMF_VARS.4MB.fd"),
        ("OVMF_VARS_4M.secboot.fd", "OVMF_VARS.4MB.ms.fd"),
    ];
    let mut create = vec![];
    for (name, target) in aliases {
        let path = firmware.join(name);
        if fs::read_link(&path).is_ok_and(|v| v == Path::new(target)) {
            continue;
        }
        if fs::symlink_metadata(&path).is_ok() || !firmware.join(target).is_file() {
            return Err(Failure::new(
                "incus:firmware",
                "Firmware alias conflicts or bundled firmware is missing",
            ));
        }
        create.push((path, target));
    }
    for (path, target) in create {
        symlink(target, &path).map_err(|e| Failure::io("incus:firmware", &path, e))?;
    }
    Ok(())
}

#[derive(Debug)]
pub struct RetiredFile {
    path: PathBuf,
    content: Vec<u8>,
    owner: &'static str,
}
pub fn node_config(files: &Files, changes: &[Change]) -> Result<Vec<RetiredFile>, Failure> {
    let mut retired = vec![];
    let priority = regex::Regex::new(r"\A-?[0-9]+\z").unwrap();
    for name in ["nodejs", "nsolid"] {
        let path = files.apt().join("preferences.d").join(name);
        if !path.exists() && !path.is_symlink() {
            continue;
        }
        if path.is_symlink() || !path.is_file() {
            return Err(Failure::io(
                "node-ownership",
                &path,
                "Unexpected NodeSource preference",
            ));
        }
        let content = fs::read(&path).map_err(|e| Failure::io("node-ownership", &path, e))?;
        let text =
            std::str::from_utf8(&content).map_err(|e| Failure::io("node-ownership", &path, e))?;
        let fields = sources::deb822(text, &path)?;
        if fields.len() != 3
            || fields.get("package").map(String::as_str) != Some(name)
            || fields.get("pin").map(String::as_str) != Some("origin deb.nodesource.com")
            || !fields
                .get("pin-priority")
                .is_some_and(|s| priority.is_match(s))
        {
            return Err(Failure::io(
                "node-ownership",
                &path,
                "Mixed or unexpected NodeSource preference",
            ));
        }
        retired.push(RetiredFile {
            path,
            content,
            owner: "node-preference",
        });
    }
    let sources = sources::paths(files)?;
    for (path, owner) in [
        (
            files.path("/usr/share/keyrings/nodesource.gpg"),
            "node-usr-keyring",
        ),
        (
            files.apt().join("keyrings/nodesource.gpg"),
            "node-apt-keyring",
        ),
    ] {
        if !path.exists() && !path.is_symlink() {
            continue;
        }
        if path.is_symlink() || !path.is_file() {
            return Err(Failure::io(
                "node-ownership",
                &path,
                "Unexpected NodeSource keyring",
            ));
        }
        for source in &sources {
            if !source.is_file() {
                continue;
            }
            let remaining = match changes.iter().find(|change| change.path == *source) {
                Some(change) => String::from_utf8(change.after.clone())
                    .map_err(|e| Failure::io("node-ownership", source, e))?,
                None => sources::read(source)?,
            };
            if remaining
                .lines()
                .filter(|line| !line.trim_start().starts_with('#'))
                .any(|line| line.contains(path.to_str().unwrap_or_default()))
            {
                return Err(Failure::io(
                    "node-ownership",
                    &path,
                    format!("Keyring is still referenced by [{}]", source.display()),
                ));
            }
        }
        let content = fs::read(&path).map_err(|e| Failure::io("node-ownership", &path, e))?;
        retired.push(RetiredFile {
            path,
            content,
            owner,
        });
    }
    for file in &retired {
        sources::backup(files, &file.path, &file.content, file.owner, true)?;
    }
    Ok(retired)
}
fn normalized(path: &Path) -> PathBuf {
    let mut result = PathBuf::new();
    for part in path.components() {
        match part {
            Component::ParentDir => {
                result.pop();
            }
            Component::CurDir => {}
            _ => result.push(part.as_os_str()),
        }
    }
    result
}
pub fn dangling_corepack(files: &Files) -> Vec<PathBuf> {
    ["yarn", "yarnpkg", "pnpm", "pnpx"]
        .into_iter()
        .filter_map(|name| {
            let path = files.path("/usr/bin").join(name);
            let link = fs::read_link(&path).ok()?;
            let expected = files
                .path("/usr/lib/node_modules/corepack/dist")
                .join(format!("{name}.js"));
            (!path.exists() && normalized(&path.parent()?.join(link)) == expected).then_some(path)
        })
        .collect()
}
#[derive(Debug)]
pub struct NodePlan {
    pub actions: Vec<String>,
    changes: Vec<Change>,
    config: Vec<RetiredFile>,
    installed: bool,
}
pub fn node_plan(host: &mut impl Host, files: &Files, input: &Input) -> Result<NodePlan, Failure> {
    if !input.tools.contains_key("node") {
        return Ok(NodePlan {
            actions: vec![],
            changes: vec![],
            config: vec![],
            installed: false,
        });
    }
    let changes = sources::provider_changes(files, Provider::Node)?;
    let config = node_config(files, &changes)?;
    let installed = apt::packages(host)?.contains_key("nodejs");
    let mut actions = vec![];
    if installed {
        actions.push("remove_nodejs_package".into());
    }
    if !changes.is_empty() {
        actions.push("retire_node_source".into());
    }
    if !config.is_empty() {
        actions.push("retire_node_config".into());
    }
    if !dangling_corepack(files).is_empty() {
        actions.push("remove_corepack_links".into());
    }
    Ok(NodePlan {
        actions,
        changes,
        config,
        installed,
    })
}
/// Caller inspects the replacement through the native JavaScript inventory before passing its version.
pub fn consolidate_node(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    replacement: Option<&str>,
) -> Result<Vec<String>, Failure> {
    let Some(pin) = input.tools.get("node") else {
        return Ok(vec![]);
    };
    let plan = node_plan(host, files, input)?;
    if replacement != Some(pin.version.as_str()) {
        return Err(Failure::new(
            "node-ownership",
            "Verify the recipe Node runtime before removing the APT source",
        ));
    }
    if plan.installed {
        let simulation = Invocation::new(&["apt-get", "--simulate", "remove", "--", "nodejs"])
            .env("LC_ALL", "C")
            .env("DEBIAN_FRONTEND", "noninteractive")
            .env("NEEDRESTART_SUSPEND", "1");
        let result = apt::package_command(host, &simulation, &["nodejs".into()])?;
        let removed: Vec<_> = result
            .stdout
            .lines()
            .filter(|line| line.starts_with("Remv "))
            .filter_map(|line| line.split_whitespace().nth(1))
            .collect();
        if removed != ["nodejs"] {
            return Err(Failure::new(
                "node-ownership",
                "Node removal would affect other packages",
            ));
        }
        let mut removal = simulation;
        removal.argv = ["apt-get", "remove", "--yes", "--", "nodejs"]
            .map(str::to_string)
            .into();
        apt::package_command(host, &removal, &["nodejs".into()])?;
        if apt::packages(host)?.contains_key("nodejs") {
            return Err(Failure::new(
                "node-ownership",
                "The duplicate nodejs package is still installed",
            ));
        }
    }
    sources::retire(files, &plan.changes, "node")?;
    for file in plan.config {
        sources::backup(files, &file.path, &file.content, file.owner, false)?;
        fs::remove_file(&file.path).map_err(|e| Failure::io("node-ownership", &file.path, e))?;
    }
    for path in dangling_corepack(files) {
        fs::remove_file(&path).map_err(|e| Failure::io("node-ownership", &path, e))?;
    }
    Ok(plan.actions)
}

#[cfg(test)]
mod tests;
