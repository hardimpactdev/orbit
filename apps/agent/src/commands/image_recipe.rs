//! Native image phases share the recipe's installers, with isolated snapshot APT configuration.
#[cfg(all(test, target_os = "linux"))]
mod apt_tests;
pub mod lifecycle;
pub mod packages;
pub mod rust;
pub mod verify;
pub mod worker;

use super::{
    php_build,
    recipe::{
        self, apt, artifacts, assessment, javascript, ownership, sources,
        store::Account,
        types::{Input as Recipe, Source, Tools},
        Failure, Files, Host, Invocation, WorkDir,
    },
};
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeMap,
    fs,
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

pub const APT: &str = "/var/tmp/orbit-image-apt";
pub const RUST: [&str; 5] = ["rust", "cargo", "rust-std", "clippy", "rustfmt"];

#[derive(schemars::JsonSchema, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub identity: String,
    pub recipe: Recipe,
    pub rust: Tools,
    pub php: Option<php_build::Input>,
    pub agent_sha256: String,
    pub pi_sha256: Option<String>,
}
impl Input {
    pub fn validate(&self) -> Result<(), Failure> {
        self.recipe.validate()?;
        if self.recipe.user != "orbit"
            || !self
                .identity
                .strip_prefix("sha256:")
                .is_some_and(|v| recipe::types::valid_digest(v, 64))
            || !recipe::types::valid_digest(&self.agent_sha256, 64)
            || self
                .pi_sha256
                .as_deref()
                .is_some_and(|v| !recipe::types::valid_digest(v, 64))
            || self
                .rust
                .iter()
                .any(|(k, p)| !RUST.contains(&k.as_str()) || p.source != Source::Artifact)
            || self
                .recipe
                .tools
                .keys()
                .any(|k| RUST.contains(&k.as_str()) || ["pi", "orbit-agent"].contains(&k.as_str()))
        {
            return Err(Failure::new(
                "input",
                "Invalid image recipe identity or tool set",
            ));
        }
        let mut rust = self.recipe.clone();
        rust.tools = self.rust.clone();
        rust.validate()?;
        if let Some(php) = &self.php {
            php.validate()
                .map_err(|e| Failure::new("input", format!("Invalid PHP build: {:?}", e.kind)))?;
            if php.fpm != php_build::Fpm::None || php.extras != php_build::Extras::Refuse {
                return Err(Failure::new(
                    "input",
                    "Images do not run FPM or purge extra PHP packages",
                ));
            }
        }
        Ok(())
    }
}

pub fn directories(files: &Files, path: &Path) -> Result<(), Failure> {
    php_build::directories(files, path).map_err(|e| Failure::new("files", format!("{:?}", e.data)))
}
pub fn apt_configuration(directory: &Path) -> String {
    format!("Dir::Etc::parts \"\";\nDir::Etc::main \"\";\nDir::Etc::sourcelist \"{}/ubuntu.sources\";\nDir::Etc::sourceparts \"\";\nAPT::Get::Always-Include-Phased-Updates \"true\";\nAPT::Get::List-Cleanup \"false\";\nAPT::Update::Error-Mode \"any\";\nDPkg::Lock::Timeout \"300\";\n", directory.display())
}
pub fn apt_setup(files: &Files, input: &Input, directory: &Path) -> Result<(), Failure> {
    directories(files, directory)?;
    files.publish_root(
        &directory.join("ubuntu.sources"),
        sources::snapshot_source(&input.recipe.snapshot).as_bytes(),
    )?;
    files.publish_root(
        &directory.join("apt.conf"),
        apt_configuration(directory).as_bytes(),
    )
}
pub fn apt_check(input: &Input, directory: &Path) -> Result<PathBuf, Failure> {
    let config = directory.join("apt.conf");
    if fs::read_to_string(&config).ok() != Some(apt_configuration(directory))
        || fs::read_to_string(directory.join("ubuntu.sources")).ok()
            != Some(sources::snapshot_source(&input.recipe.snapshot))
    {
        return Err(Failure::new(
            "apt-source",
            "Snapshot configuration is missing or mismatched; apt-source must run first",
        ));
    }
    Ok(config)
}
pub struct AptHost<'a, H> {
    pub host: &'a mut H,
    pub config: PathBuf,
}
impl<H: Host> Host for AptHost<'_, H> {
    fn execute(&mut self, call: &Invocation) -> Result<recipe::Output, Failure> {
        let mut call = call.clone();
        call.environment.retain(|(k, _)| k != "APT_CONFIG");
        call.environment
            .push(("APT_CONFIG".into(), self.config.display().to_string()));
        self.host.execute(&call)
    }
    fn wait(&mut self, delay: Duration) -> Result<(), Failure> {
        self.host.wait(delay)
    }
}
impl<H: php_build::Host> php_build::Host for AptHost<'_, H> {
    fn ping(&mut self, socket: &Path, deadline: Instant) -> bool {
        self.host.ping(socket, deadline)
    }
}
pub fn apt_source(host: &mut impl Host, files: &Files, input: &Input) -> Result<(), Failure> {
    let directory = files.path(APT);
    apt_setup(files, input, &directory)?;
    let mut host = AptHost {
        host,
        config: apt_check(input, &directory)?,
    };
    let mut call = Invocation::new(&["apt-get", "update"]);
    call.argv.extend(apt::fetch_options());
    apt::fetch(&mut host, &call)?;
    Ok(())
}
pub fn javascript(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: &Account,
    deadline: Instant,
) -> Result<(), Failure> {
    let mut selected = input.recipe.clone();
    selected
        .tools
        .retain(|name, _| javascript::NAMES.contains(&name.as_str()));
    javascript::converge(host, files, &selected, account, deadline)?;
    Ok(())
}

#[cfg(test)]
mod packages_tests;
