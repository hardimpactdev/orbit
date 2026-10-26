//! Image verification uses the retained signed snapshot indexes and never installs packages.
use super::*;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde_json::json;
command_errors! {
    pub enum Error {
        Failed { stage: String, reason: String, exit_code: Option<i32>, stderr: String } => "task_vm.image_build_failed", "The image recipe verification failed.";
    }
}
impl From<Failure> for Error {
    fn from(error: Failure) -> Self {
        let exit_code = error.diagnostic.as_ref().map(|d| d.exit_code);
        let detail = error
            .diagnostic
            .as_ref()
            .map(|d| format!("{}\n{}", d.stdout, d.stderr))
            .unwrap_or_default();
        Self::Failed {
            stage: if error.stage.is_empty() {
                "verify".into()
            } else {
                error.stage
            },
            reason: recipe::diagnostic(&error.reason),
            exit_code,
            stderr: recipe::diagnostic(&detail),
        }
    }
}
fn bad(reason: impl ToString) -> Failure {
    Failure::new("verify", reason)
}
fn php_error(error: php_build::Failure) -> Failure {
    bad(serde_json::to_string(&error.data).unwrap())
}
fn guest(host: &mut impl Host, account: &Account, args: &[&str]) -> Result<(), Failure> {
    recipe::run(
        host,
        &account.invocation(&account.home.join(".local/share/vite-plus"), args),
        true,
    )?;
    Ok(())
}
fn binary(
    host: &mut impl Host,
    files: &Files,
    account: &Account,
    path: &str,
    digest: &str,
) -> Result<(), Failure> {
    let path = files.path(path);
    guest(host, account, &["test", "-x", &path.display().to_string()])?;
    guest(host, account, &["test", "-r", &path.display().to_string()])?;
    if crate::command::run::file_sha256(&path).map_err(bad)? != digest {
        return Err(bad("The image binary differs from its delivery pin"));
    }
    Ok(())
}
fn tool(
    host: &mut impl Host,
    account: &Account,
    path: &Path,
    expected: Option<&str>,
) -> Result<(), Failure> {
    let observed = javascript::binary_version(
        host,
        account,
        &account.home.join(".local/share/vite-plus"),
        path,
    )?;
    if observed.is_none() || expected.is_some_and(|v| observed.as_deref() != Some(v)) {
        return Err(bad(format!(
            "Guest tool version failed: {}",
            path.display()
        )));
    }
    Ok(())
}
fn system_tool(host: &mut impl Host, account: &Account, path: &Path) -> Result<(), Failure> {
    let path = path.display().to_string();
    let args = [path.as_str(), "--version"];
    let root = recipe::run(host, &Invocation::new(&args), true)?;
    let guest = recipe::run(
        host,
        &account.invocation(&account.home.join(".local/share/vite-plus"), &args),
        true,
    )?;
    if root.stdout.trim().is_empty() || root.stdout.trim() != guest.stdout.trim() {
        return Err(bad(format!("Root and guest tool versions differ: {path}")));
    }
    Ok(())
}
pub fn verify(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    account: &Account,
    deadline: Instant,
) -> Result<(), Failure> {
    input.validate()?;
    assessment::platform(host, files, &input.recipe)?;
    let marker: serde_json::Value = serde_json::from_slice(
        &fs::read(files.path("/etc/orbit-machine-recipe.json")).map_err(bad)?,
    )
    .map_err(bad)?;
    if marker != json!({"identity": input.identity, "profile":"base"}) {
        return Err(bad(
            "The image recipe marker differs from the requested identity",
        ));
    }
    let (work, config) = (|| -> Result<_, Failure> {
        let temporary = files.path("/var/tmp");
        directories(files, &temporary)?;
        let work = WorkDir::within(&temporary)?;
        apt_setup(files, input, &work.0)?;
        let config = apt_check(input, &work.0)?;
        Ok((work, config))
    })()
    .map_err(|e| {
        bad(format!(
            "Verification snapshot configuration failed: {}",
            e.reason
        ))
    })?;
    let _work = work;
    let mut host = AptHost {
        host,
        config,
        deadline,
    };
    let observed = javascript::inspect(&mut host, files, &input.recipe.tools, Some(account))?;
    let assessment = assessment::assess(
        &mut host,
        files,
        &input.recipe,
        &observed,
        None,
        &mut apt::Snapshots::retained_only(),
    )?;
    if assessment.values().any(|tool| {
        !matches!(
            tool.status,
            recipe::types::Status::Current | recipe::types::Status::NewerThanRecipe
        )
    }) {
        return Err(bad("An image tool does not satisfy the recipe"));
    }
    if input.recipe.tools.contains_key("incus")
        && !recipe::run(&mut host, &Invocation::new(&["id", "-nG", "orbit"]), true)?
            .stdout
            .split_whitespace()
            .any(|g| g == "incus-admin")
    {
        return Err(bad("The image account is not in incus-admin"));
    }
    binary(
        &mut host,
        files,
        account,
        "/usr/local/bin/orbit-agent",
        &input.agent_sha256,
    )?;
    if let Some(digest) = &input.pi_sha256 {
        binary(
            &mut host,
            files,
            account,
            "/home/orbit/.local/bin/pi-server",
            digest,
        )?;
    }
    if let Some(php) = &input.php {
        let prepared = php_build::operations::prepare(&mut host, files, php).map_err(php_error)?;
        if !prepared.plan.pending.is_empty()
            || !prepared.plan.repair.is_empty()
            || !prepared.plan.extras.is_empty()
            || !prepared.plan.unowned.is_empty()
        {
            return Err(bad("The image PHP build requires installation or repair"));
        }
        for (version, build) in &prepared.versions {
            php_build::runtime::check_cli(&mut host, files, version, build, php.pcov, php.extras)
                .map_err(php_error)?;
            tool(
                &mut host,
                account,
                &files.path(&format!("/usr/bin/php{version}")),
                None,
            )?;
        }
    }
    if !input.rust.is_empty()
        && recipe::store::json(&files.path("/opt/orbit/rust/.recipe-artifacts.json"))?
            != rust::pins(input)
    {
        return Err(bad("The image Rust artifact receipt differs from its pins"));
    }
    for (name, binary) in [("rust", "rustc"), ("cargo", "cargo")] {
        if let Some(pin) = input.rust.get(name) {
            tool(
                &mut host,
                account,
                &files.path(&format!("/opt/orbit/rust/bin/{binary}")),
                Some(&pin.version),
            )?;
            tool(
                &mut host,
                account,
                &account.home.join(".cargo/bin").join(binary),
                Some(&pin.version),
            )?;
        }
    }
    for (name, path) in [
        ("node", "/usr/local/bin/node"),
        ("bun", "/opt/orbit/bun/bin/bun"),
        ("pnpm", "/usr/local/bin/pnpm"),
        ("vp-cli", "/usr/local/bin/vp"),
    ] {
        if input.recipe.tools.contains_key(name) {
            let expected = if javascript::NAMES.contains(&name) {
                observed[name].as_deref()
            } else {
                None
            };
            tool(&mut host, account, &files.path(path), expected)?;
        }
    }
    for (name, path) in [("composer", "/usr/bin/composer"), ("git", "/usr/bin/git")] {
        if input.recipe.tools.contains_key(name) {
            system_tool(&mut host, account, &files.path(path))?;
        }
    }
    guest(
        &mut host,
        account,
        &[
            "ls",
            "-A",
            "--",
            &files.path("/opt/orbit/.").display().to_string(),
        ],
    )?;
    for path in [
        "/var/lib/orbit/package-cache",
        "/opt/orbit/package-cache",
        "/home/orbit/orbit",
        "/home/orbit/.orbit/gateway.sqlite",
    ] {
        if fs::symlink_metadata(files.path(path)).is_ok() {
            return Err(bad(format!(
                "The image contains workspace or cache state: {path}"
            )));
        }
    }
    // Finish under the same update lock as binary delivery, after all other verification.
    super::worker::agent(files, input, deadline)
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Ready {
    ready: bool,
}
pub struct Verify;
impl Command for Verify {
    const NAME: &'static str = "image.verify";
    const SUMMARY: &'static str = "Verify image pins and guest tool access without refreshing indexes or installing packages.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = Input;
    type Output = Ready;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Ready, Self::Error> {
        verify(
            &mut recipe::Native(context),
            &Files::system(),
            &input,
            &Account::named("orbit")?,
            context.deadline(),
        )?;
        Ok(Ready { ready: true })
    }
}
#[cfg(test)]
mod tests;
