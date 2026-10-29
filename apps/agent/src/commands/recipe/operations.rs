use super::{
    apt, artifacts,
    assessment::{self, Report},
    interruption, javascript, ownership, services,
    store::Account,
    types::{Input, Source, Status},
    Failure, Files, Host, Invocation, Native, WorkDir,
};
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::Serialize;
use serde_json::{json, Value};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::PathBuf,
    time::{Duration, Instant},
};

impl CommandError for Failure {
    const CODES: &'static [&'static str] = &["recipe.failed"];
    fn code(&self) -> &'static str {
        "recipe.failed"
    }
    fn message(&self) -> String {
        "The machine recipe could not complete.".into()
    }
    fn details(&self) -> Value {
        let mut details = json!({"stage":format!("recipe-stage:{}",self.stage),"reason":self.reason,"packages":self.packages});
        if let Some(diagnostic) = &self.diagnostic {
            details["command"] = json!(diagnostic.command);
            details["exit_code"] = json!(diagnostic.exit_code);
            details["stdout"] = json!(diagnostic.stdout);
            details["stderr"] = json!(diagnostic.stderr);
        }
        details
    }
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Plan {
    #[serde(flatten)]
    pub tools: Report,
    #[serde(rename = "_actions", skip_serializing_if = "Vec::is_empty")]
    pub actions: Vec<String>,
}
pub fn plan(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: Option<&Account>,
) -> Result<Plan, Failure> {
    ownership::check_git_siblings(host, input)?;
    let mut actions = ownership::configure_git(files, input, false)?;
    actions.extend(ownership::configure_incus(host, files, input, false)?);
    actions.extend(ownership::node_plan(host, files, input)?.actions);
    let indexes = if super::sources::ubuntu_repair(files)?.is_some() {
        actions.insert(0, "repair_ubuntu_sources".into());
        Some(apt::Indexes::temporary(
            host,
            &super::sources::rolling_source(input.architecture.name()),
        )?)
    } else {
        None
    };
    let versions = javascript::inspect(host, files, &input.tools, account)?;
    let tools = assessment::assess(
        host,
        files,
        input,
        &versions,
        indexes.as_ref().map(|v| v.options.as_slice()),
        &mut apt::Snapshots::default(),
    )?;
    Ok(Plan { tools, actions })
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Applied {
    pub changed_packages: Vec<String>,
    pub restarted_services: Vec<String>,
    pub changed_tools: Vec<String>,
    pub newer_than_recipe: BTreeMap<String, BTreeMap<String, String>>,
    pub healthy: bool,
}
pub(crate) struct Policy(PathBuf);
impl Policy {
    pub(crate) fn install(files: &Files) -> Result<Self, Failure> {
        let path = files.path("/usr/sbin/policy-rc.d");
        if fs::symlink_metadata(&path).is_ok() {
            return Err(Failure::new(
                "preflight",
                "A package restart policy already exists",
            ));
        }
        files.publish(
            &path,
            b"#!/bin/sh\nexit 101\n",
            0o755,
            files.uid,
            files.gid,
            false,
        )?;
        Ok(Self(path))
    }
    fn remove(self) -> Result<(), Failure> {
        fs::remove_file(&self.0).map_err(|e| Failure::io("packages", &self.0, e))
    }
}
impl Drop for Policy {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.0);
    }
}

pub fn apply(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: &Account,
    deadline: Instant,
) -> Result<Applied, Failure> {
    let mut stage = "preflight";
    let result = (|| {
        ownership::incus_preflight(host, files, input)?;
        ownership::configure_git(files, input, false)?;
        ownership::configure_incus(host, files, input, false)?;
        if fs::symlink_metadata(files.path("/usr/sbin/policy-rc.d")).is_ok() {
            return Err(Failure::new(
                "preflight",
                "A package restart policy already exists",
            ));
        }
        stage = "apt-source";
        ownership::repair_ubuntu(host, files, input.architecture.name())?;
        let origins = apt::Indexes::refresh(host, input.architecture.name(), &input.snapshot)?;
        stage = "preflight";
        let before = apt::packages(host)?;
        let versions = javascript::inspect(host, files, &input.tools, Some(account))?;
        ownership::check_git_siblings(host, input)?;
        let mut snapshots = apt::Snapshots::default();
        let assessment = assessment::assess(
            host,
            files,
            input,
            &versions,
            Some(&origins.options),
            &mut snapshots,
        )?;
        assessment::supported(&assessment)?;
        let active = services::active(host)?;
        let recipe_packages: BTreeSet<_> = input
            .tools
            .iter()
            .filter_map(|(name, pin)| {
                if pin.source == Source::Ubuntu {
                    pin.package.clone()
                } else if pin.deb() {
                    Some(name.clone())
                } else {
                    None
                }
            })
            .collect();
        let health = services::for_packages(host, &recipe_packages, &active)?;
        let js = input
            .tools
            .keys()
            .any(|name| javascript::NAMES.contains(&name.as_str()));
        if js {
            super::store::scope(files, account)?;
        }
        let work = WorkDir::new()?;
        stage = "download";
        let mut downloads = BTreeMap::new();
        let mut debs = vec![];
        for (name, pin) in &input.tools {
            if name == "vp"
                || pin.source == Source::Ubuntu
                || versions.get(name).and_then(|v| v.as_ref()) == Some(&pin.version)
            {
                continue;
            }
            let path = work.0.join(format!(
                "{name}.{}",
                if pin.deb() { "deb" } else { "archive" }
            ));
            artifacts::download(host, name, pin, input, &path)?;
            if pin.deb() {
                artifacts::verify_deb(host, &path, name, pin, input)?;
                debs.push(path.clone());
            }
            downloads.insert(name.clone(), path);
        }
        stage = "apt-source";
        ownership::configure_git(files, input, true)?;
        ownership::configure_incus(host, files, input, true)?;
        let ubuntu: Vec<_> = input
            .tools
            .iter()
            .filter(|(name, pin)| pin.source == Source::Ubuntu && assessment[*name].changed)
            .map(|(_, pin)| format!("{}={}", pin.package.as_deref().unwrap(), pin.version))
            .collect();
        let options = if !ubuntu.is_empty() || !debs.is_empty() {
            let source = work.0.join("ubuntu.sources");
            files.publish_root(
                &source,
                super::sources::snapshot_source(&input.snapshot).as_bytes(),
            )?;
            Some(apt::source_options(&source))
        } else {
            None
        };
        stage = "packages";
        if let Some(options) = &options {
            let mut update = Invocation::new(&["apt-get", "update"]);
            update.argv.extend_from_slice(options);
            apt::package_command(host, &update, &[])?;
        }
        let environment: Vec<_> = [
            ("DEBIAN_FRONTEND".into(), "noninteractive".into()),
            ("NEEDRESTART_SUSPEND".into(), "1".into()),
        ]
        .into();
        ownership::incus_preflight(host, files, input)?;
        let policy = Policy::install(files)?;
        let packages =
            (|| {
                if !ubuntu.is_empty() {
                    let mut invocation = Invocation::new(&[
                        "apt-get",
                        "install",
                        "--yes",
                        "--no-install-recommends",
                        "--no-remove",
                        "-o",
                        "Dpkg::Options::=--force-confold",
                    ]);
                    invocation.environment = environment.clone();
                    invocation
                        .argv
                        .extend_from_slice(options.as_deref().unwrap_or_default());
                    invocation.argv.push("--".into());
                    invocation.argv.extend_from_slice(&ubuntu);
                    apt::package_command(host, &invocation, &ubuntu)?;
                }
                if !debs.is_empty() {
                    let installed = apt::packages(host)?;
                    let retained = input
                        .tools
                        .values()
                        .filter(|pin| pin.source == Source::Ubuntu)
                        .filter_map(|pin| {
                            let name = pin.package.as_ref()?;
                            installed
                                .get(name)
                                .map(|version| (name.clone(), version.clone()))
                        })
                        .collect();
                    apt::install_exact_debs(
                        host,
                        &debs,
                        &retained,
                        &installed,
                        options.as_deref().unwrap_or_default(),
                        &environment,
                    )?;
                }
                ownership::remove_incus_agent(host, input)?;
                ownership::incus_firmware(files, input)?;
                if !ubuntu.is_empty() || !debs.is_empty() {
                    let mut check = Invocation::new(&["apt-get", "check"]);
                    check.environment = environment.clone();
                    let mut packages = ubuntu.clone();
                    packages.extend(debs.iter().filter_map(|path| {
                        path.file_stem().map(|s| s.to_string_lossy().into_owned())
                    }));
                    apt::package_command(host, &check, &packages)?;
                    apt::audit(host, &environment)?;
                }
                Ok::<(), Failure>(())
            })();
        policy.remove()?;
        packages?;
        ownership::pin(files, &input.git(), "git")?;
        ownership::pin(files, &input.incus(), "incus")?;
        stage = "tools";
        super::browser::install(
            host,
            files,
            input,
            downloads.get(super::browser::NAME).map(PathBuf::as_path),
        )?;
        if js {
            javascript::install(host, files, input, &downloads, &work.0, account, deadline)?;
        }
        stage = "inventory";
        let after_versions = javascript::inspect(host, files, &input.tools, Some(account))?;
        let after_assessment = assessment::assess(
            host,
            files,
            input,
            &after_versions,
            Some(&origins.options),
            &mut snapshots,
        )?;
        assessment::supported(&after_assessment)?;
        if after_assessment
            .values()
            .any(|tool| !matches!(tool.status, Status::Current | Status::NewerThanRecipe))
        {
            return Err(Failure::new(
                "inventory",
                "Recipe tools did not reach their required versions",
            ));
        }
        stage = "node-ownership";
        ownership::consolidate_node(
            host,
            files,
            input,
            after_versions.get("node").and_then(|v| v.as_deref()),
        )?;
        let after = apt::packages(host)?;
        let changed: BTreeSet<_> = before
            .keys()
            .chain(after.keys())
            .filter(|name| before.get(*name) != after.get(*name))
            .cloned()
            .collect();
        stage = "restart";
        let restarted = services::restart_and_check(host, &changed, &active, &health)?;
        ownership::record_receipt(files, &input.git(), &after_versions, "git")?;
        ownership::record_receipt(files, &input.incus(), &after_versions, "incus")?;
        Ok(Applied {
            changed_packages: changed.into_iter().collect(),
            restarted_services: restarted.into_iter().collect(),
            changed_tools: input
                .tools
                .keys()
                .filter(|name| versions.get(*name) != after_versions.get(*name))
                .cloned()
                .collect(),
            newer_than_recipe: assessment::newer(&after_assessment),
            healthy: true,
        })
    })();
    result.map_err(|e: Failure| e.at(stage))
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "recipe.inspect";
    const SUMMARY: &'static str =
        "Inspect recipe versions, package origins, and planned ownership changes.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Plan;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<Plan, Failure> {
        let mut host = Native(context);
        let files = Files::system();
        assessment::platform(&mut host, &files, &input)?;
        let account = Account::named(&input.user)?;
        plan(&mut host, &files, &input, Some(&account)).map_err(|e| e.at("inventory"))
    }
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Ready {
    ready: bool,
}
pub struct IncusPreflight;
impl Command for IncusPreflight {
    const NAME: &'static str = "recipe.incus-preflight";
    const SUMMARY: &'static str =
        "Refuse an Incus recipe change unless every host instance is stopped.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Ready;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Ready, Failure> {
        let mut host = Native(context);
        let files = Files::system();
        assessment::platform(&mut host, &files, &input)?;
        ownership::incus_preflight(&mut host, &files, &input).map_err(|e| e.at("preflight"))?;
        Ok(Ready { ready: true })
    }
}
pub struct Apply;
impl Command for Apply {
    const NAME: &'static str = "recipe.apply";
    const SUMMARY: &'static str =
        "Converge pinned recipe packages and runtimes, then verify their services.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Applied;
    type Error = Failure;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("machine-recipe".into())
    }
    fn run(input: Input, context: &Context) -> Result<Applied, Failure> {
        let _signals = interruption::Guard::install()?;
        // Leave time to remove restart suppression before the outer command deadline exits the process.
        let deadline = context
            .deadline()
            .checked_sub(Duration::from_secs(10))
            .filter(|d| *d > Instant::now())
            .ok_or_else(|| Failure::new("deadline", "Insufficient time for recipe cleanup"))?;
        let context = Context::new(context.platform, deadline);
        let mut host = Native(&context);
        let files = Files::system();
        assessment::platform(&mut host, &files, &input)?;
        let account = Account::named(&input.user)?;
        apply(&mut host, &files, &input, &account, deadline)
    }
}

#[cfg(test)]
mod tests;
