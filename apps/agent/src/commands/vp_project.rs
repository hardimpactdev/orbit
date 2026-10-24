//! Project requirements are data. A fixed child drops identity before reading any Project file.
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::{
    collections::BTreeMap,
    fs::{self, OpenOptions},
    io::{self, Read},
    os::unix::fs::OpenOptionsExt,
    path::{Component, Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.project_invalid", "The Project requirements cannot be read.";
    }
}
type Result<T> = std::result::Result<T, Error>;
fn invalid(_: impl std::fmt::Display) -> Error {
    Error::Invalid {}
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    path: PathBuf,
    user: String,
}
#[derive(Debug, Deserialize, Serialize, PartialEq)]
pub struct Output {
    package: Map<String, Value>,
    #[serde(rename = ".node-version")]
    node_version: Option<String>,
    #[serde(rename = ".nvmrc")]
    nvmrc: Option<String>,
    package_manager_hint: Option<String>,
    locks: BTreeMap<String, String>,
    workspaces: BTreeMap<String, Value>,
    npmrc: String,
}
fn file(root: &Path, name: &str, limit: u64) -> Result<Option<String>> {
    let file = match OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(root.join(name))
    {
        Ok(file) => file,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(None),
        Err(error) => return Err(invalid(error)),
    };
    let info = file.metadata().map_err(invalid)?;
    if !info.is_file() || info.len() > limit {
        return Err(Error::Invalid {});
    }
    let mut bytes = Vec::new();
    file.take(limit + 1)
        .read_to_end(&mut bytes)
        .map_err(invalid)?;
    if bytes.len() as u64 > limit {
        return Err(Error::Invalid {});
    }
    String::from_utf8(bytes).map(Some).map_err(invalid)
}
fn small(root: &Path, name: &str) -> Result<Option<String>> {
    file(root, name, 1_048_576)
}
fn read(root: &Path, home: &Path) -> Result<Output> {
    if !root.is_absolute() || root.components().any(|p| matches!(p, Component::ParentDir)) {
        return Err(Error::Invalid {});
    }
    let package: Map<String, Value> =
        serde_json::from_str(&small(root, "package.json")?.unwrap_or_else(|| "{}".into()))
            .map_err(invalid)?;
    let uses_vp = ["dependencies", "devDependencies"].iter().any(|key| {
        package
            .get(*key)
            .and_then(Value::as_object)
            .is_some_and(|deps| {
                deps.iter().any(|(name, spec)| {
                    name == "vite-plus"
                        || spec
                            .as_str()
                            .is_some_and(|s| s.starts_with("npm:vite-plus@"))
                })
            })
    });
    let hint = [
        ("pnpm", "pnpm-workspace.yaml"),
        ("pnpm", "pnpm-lock.yaml"),
        ("yarn", "yarn.lock"),
        ("yarn", ".yarnrc.yml"),
        ("npm", "package-lock.json"),
        ("bun", "bun.lock"),
        ("bun", "bun.lockb"),
    ]
    .into_iter()
    .find(|(_, name)| root.join(name).is_file())
    .map(|(family, _)| family.into());
    let mut output = Output {
        package,
        node_version: None,
        nvmrc: None,
        package_manager_hint: hint,
        locks: BTreeMap::new(),
        workspaces: BTreeMap::new(),
        npmrc: String::new(),
    };
    if !uses_vp {
        return Ok(output);
    }
    for name in ["pnpm-lock.yaml", "package-lock.json", "yarn.lock"] {
        if let Some(text) = file(root, name, 16_777_216)? {
            output.locks.insert(name.into(), text);
        }
    }
    let links = regex::Regex::new(r#"version: [\"']?link:([^\s\"']+)"#)
        .expect("fixed workspace link pattern");
    for text in output.locks.values() {
        for link in links.captures_iter(text) {
            let directory = fs::canonicalize(root.join(&link[1])).map_err(invalid)?;
            if !directory.starts_with(fs::canonicalize(root).map_err(invalid)?) {
                return Err(Error::Invalid {});
            }
            let workspace: Map<String, Value> = serde_json::from_str(
                &small(&directory, "package.json")?
                    .filter(|text| !text.is_empty())
                    .unwrap_or_else(|| "{}".into()),
            )
            .map_err(invalid)?;
            if workspace.get("name").and_then(Value::as_str) == Some("vite-plus") {
                output.workspaces.insert(
                    format!("link:{}", &link[1]),
                    workspace.get("version").cloned().unwrap_or(Value::Null),
                );
            }
        }
    }
    output.node_version = small(root, ".node-version")?;
    output.nvmrc = small(root, ".nvmrc")?;
    output.npmrc = format!(
        "{}\n{}",
        small(home, ".npmrc")?.unwrap_or_default(),
        small(root, ".npmrc")?.unwrap_or_default()
    );
    Ok(output)
}

/// Internal reader: no secret, observer, shell, or Project code. The parent already dropped identity.
pub fn service(arguments: &[String]) -> i32 {
    let [path] = arguments else {
        return 64;
    };
    if identity::effective_uid() == 0 {
        return 77;
    }
    let result = identity::user_name(identity::effective_uid())
        .and_then(|user| identity::passwd(&user))
        .ok_or(Error::Invalid {})
        .and_then(|account| read(Path::new(path), Path::new(&account.home)));
    match result {
        Ok(output) => {
            println!("{}", serde_json::to_string(&output).expect("typed result"));
            0
        }
        Err(_) => 65,
    }
}
fn execute(input: Input, context: &Context, executable: &Path) -> Result<Output> {
    if input.user.is_empty()
        || input.user.len() > 32
        || !(input.user.as_bytes()[0].is_ascii_lowercase() || input.user.starts_with('_'))
        || !input
            .user
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-' || b == b'_')
        || !input.path.is_absolute()
        || input
            .path
            .components()
            .any(|p| matches!(p, Component::ParentDir))
    {
        return Err(Error::Invalid {});
    }
    let mut command = Process::new(executable);
    command
        .args(["service", "vp-project"])
        .arg(&input.path)
        .env_clear()
        .env("PATH", "/usr/bin:/bin")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null());
    identity::drop_to(&mut command, &input.user).map_err(invalid)?;
    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .map_err(invalid)?;
    runtime.block_on(async {
        let mut command = tokio::process::Command::from(command);
        command.kill_on_drop(true);
        let output = tokio::time::timeout(context.remaining(), command.output())
            .await
            .map_err(invalid)?
            .map_err(invalid)?;
        if !output.status.success() {
            return Err(Error::Invalid {});
        }
        serde_json::from_slice(&output.stdout).map_err(invalid)
    })
}
pub struct ReadProject;
impl Command for ReadProject {
    const NAME: &'static str = "tools.read-vp-project";
    const SUMMARY: &'static str =
        "Read Project runtime requirements as its account without executing hooks.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Owner)
    }
    fn run(input: Input, context: &Context) -> Result<Output> {
        execute(input, context, &std::env::current_exe().map_err(invalid)?)
    }
}
#[cfg(test)]
mod tests;
