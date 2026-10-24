//! Fleet self-update uses one fixed CLI entry point. The CLI owns each binary's update lock.
use super::{
    cli,
    recipe::{self, Host, Invocation},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::{path::Path, time::Duration};

command_errors! {
    pub enum Error {
        Invalid {} => "cli.self_update_invalid", "The managed account or exact downgrade version is invalid.";
        Unavailable { state: cli::State } => "cli.self_update_unavailable", "The Node has no trusted CLI with self-update support.";
        Failed {} => "cli.self_update_failed", "The CLI self-update returned no bounded execution result.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    user: String,
    downgrade_to: Option<String>,
}
#[derive(Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Outcome {
    Updated,
    Unchanged,
    Pending,
    Incomplete,
    Failed,
}
#[derive(Debug, Serialize)]
pub struct Output {
    outcome: Outcome,
    report: Option<Map<String, Value>>,
    cli_version: Option<String>,
    error_code: Option<String>,
    exit_code: i32,
    stdout: String,
    stderr: String,
}
fn validate(input: &Input) -> Result<(), Error> {
    if input.user.is_empty()
        || input.user == "root"
        || !input
            .user
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'-'))
        || input
            .downgrade_to
            .as_ref()
            .is_some_and(|v| !cli::release_name(&format!("orbit-{v}")))
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn invocation(input: &Input) -> Invocation {
    // Do not inherit the Gateway account's ORBIT_HOME or an interactive release mirror override.
    let mut call = Invocation::new(&[
        "/usr/bin/env",
        "-i",
        "HOME=/root",
        "ORBIT_HOME=/root/.orbit",
        "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
        "LANG=C.UTF-8",
        "/usr/local/bin/orbit",
        "self-update",
        "--json",
    ]);
    if let Some(version) = &input.downgrade_to {
        call.argv.push(format!("--allow-downgrade-to={version}"));
    }
    call
}
fn tail(value: &str) -> String {
    let value = recipe::diagnostic(value);
    let mut start = value.len().saturating_sub(4000);
    while !value.is_char_boundary(start) {
        start += 1;
    }
    value[start..].into()
}
fn report(output: recipe::Output) -> Output {
    let report = serde_json::from_str::<Map<String, Value>>(output.stdout.trim()).ok();
    let steps = report
        .as_ref()
        .and_then(|r| r.get("steps"))
        .and_then(Value::as_array);
    let declared = report
        .as_ref()
        .and_then(|r| r.get("outcome"))
        .and_then(Value::as_str);
    let outcome = match (output.code, declared) {
        (0, Some("updated")) => Outcome::Updated,
        (0, Some("unchanged")) => Outcome::Unchanged,
        (0, Some("pending")) => Outcome::Pending,
        (0, Some("incomplete")) => Outcome::Incomplete,
        _ => Outcome::Failed,
    };
    let error_code = if outcome == Outcome::Failed {
        Some(
            steps
                .into_iter()
                .flatten()
                .find_map(|step| {
                    (step.get("outcome")?.as_str()? == "failed").then_some(())?;
                    step.get("error")?.get("code")?.as_str()
                })
                .or_else(|| report.as_ref()?.get("error")?.get("code")?.as_str())
                .or_else(|| report.as_ref()?.get("error_code")?.as_str())
                .unwrap_or("fleet.self_update_failed")
                .to_owned(),
        )
    } else {
        None
    };
    let cli_version = steps.into_iter().flatten().find_map(|step| {
        (step.get("step")?.as_str()? == "cli").then_some(())?;
        step.get("after")?
            .get("version")?
            .as_str()
            .map(str::to_owned)
    });
    Output {
        outcome,
        report,
        cli_version,
        error_code,
        exit_code: output.code,
        stdout: tail(&output.stdout),
        stderr: tail(&output.stderr),
    }
}
fn execute(
    input: &Input,
    host: &mut impl Host,
    inspect: impl FnOnce(&str) -> cli::State,
) -> Result<Output, Error> {
    validate(input)?;
    let state = inspect(&input.user);
    if state != cli::State::Present {
        return Err(Error::Unavailable { state });
    }
    let output = recipe::run(host, &invocation(input), false).map_err(|_| Error::Failed {})?;
    Ok(report(output))
}
pub struct SelfUpdate;
impl Command for SelfUpdate {
    const NAME: &'static str = "cli.self-update";
    const SUMMARY: &'static str =
        "Run the trusted CLI self-update with root's managed Node profile.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("cli-self-update".into())
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        execute(&input, &mut recipe::Native(context), |user| {
            cli::inspect(Path::new("/usr/local/bin/orbit"), user)
        })
    }
}
#[cfg(test)]
mod tests;
