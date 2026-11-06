//! Ownership of recipe packages and provider files.
use super::{
    apt,
    sources::{self, Change},
    types::Input,
    Failure, Files, Host, Invocation,
};
use std::{
    fs,
    path::{Component, Path, PathBuf},
};

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
    let changes = sources::provider_changes(files)?;
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
    sources::retire(files, &plan.changes)?;
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
