//! `instance.inspect-laravel-routes`: the read of a production release's Laravel routes.

use super::source::*;
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    path::PathBuf,
    time::{Duration, Instant},
};

// ---------------------------------------------------------------------------------------------------------------

/// Reads whether a production release's Laravel routes claim the `app` or `apps` path that Reverb needs. It runs
/// `php artisan route:list` as the production user and changes nothing.
pub struct InspectLaravelRoutes;

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectLaravelRoutesInput {
    /// The production user that owns the release.
    pub user: String,
    /// The persistent production home with `current` and `releases`.
    pub home: PathBuf,
    /// The application directory inside the selected release.
    pub application: PathBuf,
    /// The selected PHP version, such as `8.5`. The command runs `php<version>`.
    pub php_version: String,
}

#[derive(schemars::JsonSchema, Serialize, Deserialize, Debug, PartialEq)]
pub struct LaravelRoutesOutput {
    pub collision: bool,
}

command_errors! {
    pub enum LaravelRoutesError {
        PathMismatch {} => "instance.path_mismatch", "The application is not inside the selected release.";
        RoutesUnreadable {} => "instance.laravel_routes_unreadable", "Laravel did not list its routes.";
    }
}

/// The pattern of a route's first segment: each parameter matches any text without a slash, and an optional
/// parameter may also drop the Symfony separator before it.
pub fn first_segment_pattern(segment: &str) -> String {
    let parameter = regex::Regex::new(r"\{[^{}]+\}").expect("a valid pattern");
    let mut pattern = String::new();
    let mut position = 0;
    for found in parameter.find_iter(segment) {
        let literal = &segment[position..found.start()];
        let separator = literal
            .chars()
            .last()
            .filter(|last| ",;.:-_~+*=@|".contains(*last));
        match separator {
            Some(last) if found.as_str().ends_with("?}") => {
                pattern += &regex::escape(&literal[..literal.len() - last.len_utf8()]);
                pattern += &format!("(?:{}[^/]*)?", regex::escape(&last.to_string()));
            }
            _ => {
                pattern += &regex::escape(literal);
                pattern += "[^/]*";
            }
        }
        position = found.end();
    }
    pattern + &regex::escape(&segment[position..])
}

/// Whether any listed route's first segment can match `app` or `apps`. The root fallback runs after explicit
/// routes, and parameter constraints are unavailable, so the match is conservative.
pub fn routes_collide(uris: &[String]) -> bool {
    uris.iter()
        .map(|uri| uri.trim_matches('/'))
        .filter(|uri| *uri != "{fallbackPlaceholder}")
        .filter_map(|uri| {
            regex::Regex::new(&format!(
                "^(?:{})$",
                first_segment_pattern(&uri.split('/').next().unwrap_or("").to_lowercase())
            ))
            .ok()
        })
        .any(|pattern| {
            ["app", "apps"]
                .iter()
                .any(|prefix| pattern.is_match(prefix))
        })
}

impl Command for InspectLaravelRoutes {
    const NAME: &'static str = "instance.inspect-laravel-routes";
    const SUMMARY: &'static str =
        "Read whether a production release's Laravel routes claim the Reverb paths.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InspectLaravelRoutesInput;
    type Output = LaravelRoutesOutput;
    type Error = LaravelRoutesError;

    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Owner)
    }

    fn run(
        input: InspectLaravelRoutesInput,
        context: &Context,
    ) -> Result<LaravelRoutesOutput, LaravelRoutesError> {
        let path = || LaravelRoutesError::PathMismatch {};
        let current = input.home.join("current");
        require(
            canonical(&input.home) && is_symlink(&current),
            path,
            "the production current link",
        )?;
        require(
            owner(&input.home).starts_with(&format!("{}:", input.user)),
            path,
            "the production user owns the home",
        )?;
        let release = fs::canonicalize(&current).map_err(|_| path())?;
        let application = fs::canonicalize(&input.application).map_err(|_| path())?;
        require(
            release.parent() == Some(input.home.join("releases").as_path())
                && application.starts_with(&release),
            path,
            "the application is inside the selected release",
        )?;
        let valid_version = input
            .php_version
            .split_once('.')
            .is_some_and(|(major, minor)| {
                !major.is_empty()
                    && !minor.is_empty()
                    && major
                        .bytes()
                        .chain(minor.bytes())
                        .all(|byte| byte.is_ascii_digit())
            });
        require(
            valid_version,
            || LaravelRoutesError::RoutesUnreadable {},
            "a PHP version",
        )?;
        let mut command = std::process::Command::new(format!("php{}", input.php_version));
        command
            .args(["artisan", "route:list", "--json", "--no-interaction"])
            .current_dir(&application)
            .stdin(std::process::Stdio::null())
            .stderr(std::process::Stdio::null());
        crate::command::identity::drop_to(&mut command, &input.user)
            .map_err(|_| LaravelRoutesError::RoutesUnreadable {})?;
        let wait = context.deadline().min(Instant::now() + ROUTE_LIST_LIMIT);
        let stdout = output_before(command, wait).ok_or(LaravelRoutesError::RoutesUnreadable {})?;
        let routes: Vec<serde_json::Value> =
            serde_json::from_slice(&stdout).map_err(|_| LaravelRoutesError::RoutesUnreadable {})?;
        let uris: Option<Vec<String>> = routes
            .iter()
            .map(|route| route.get("uri")?.as_str().map(str::to_string))
            .collect();
        let uris = uris.ok_or(LaravelRoutesError::RoutesUnreadable {})?;
        Ok(LaravelRoutesOutput {
            collision: routes_collide(&uris),
        })
    }
}

/// How long Laravel may take to list its routes; a release that hangs at boot fails the check.
const ROUTE_LIST_LIMIT: Duration = Duration::from_secs(45);

/// The standard output of a command that exits successfully before `deadline`, or `None`. A command still running
/// at the deadline is killed, so a read leaves no process behind.
fn output_before(mut command: std::process::Command, deadline: Instant) -> Option<Vec<u8>> {
    use std::io::Read;
    let mut child = command.stdout(std::process::Stdio::piped()).spawn().ok()?;
    let mut stdout = child.stdout.take()?;
    let reader = std::thread::spawn(move || {
        let mut bytes = Vec::new();
        stdout.read_to_end(&mut bytes).map(|_| bytes)
    });
    loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                return status.success().then(|| reader.join().ok()?.ok()).flatten()
            }
            Ok(None) if Instant::now() < deadline => std::thread::sleep(Duration::from_millis(50)),
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                return None;
            }
        }
    }
}

#[cfg(test)]
#[path = "instances_tests.rs"]
mod tests;
