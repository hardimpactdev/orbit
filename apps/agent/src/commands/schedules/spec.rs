//! One typed intent renders both the installed artifacts and Doctor's expectations.
use super::{failed, paths, InstallInput, ScheduleError};
use serde::{Deserialize, Serialize};

pub(super) const AGENT: &str = "/usr/local/bin/orbit-agent";
const CONTEXT: &str = "# X-Orbit-Execution-Context=";

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct ExecutionContext {
    pub user: String,
    pub group: String,
    pub home: String,
    pub working_directory: String,
    pub shell: String,
    pub login_shell: bool,
}

pub(super) struct Artifacts {
    pub script: String,
    pub service: String,
    pub timer: String,
}

pub(super) fn valid_id(id: &str) -> bool {
    let bytes = id.as_bytes();
    bytes.len() == 36
        && bytes.iter().enumerate().all(|(i, byte)| {
            if [8, 13, 18, 23].contains(&i) {
                *byte == b'-'
            } else {
                byte.is_ascii_digit() || (b'a'..=b'f').contains(byte)
            }
        })
        && (b'1'..=b'5').contains(&bytes[14])
        && b"89ab".contains(&bytes[19])
}

fn account(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 256
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"_-".contains(&b))
}

fn absolute(value: &str) -> bool {
    value.starts_with('/') && !value.chars().any(char::is_control)
}

impl ExecutionContext {
    pub(super) fn validate(&self) -> Result<(), ScheduleError> {
        if !account(&self.user)
            || !account(&self.group)
            || ![&self.home, &self.working_directory, &self.shell]
                .into_iter()
                .all(|s| absolute(s))
        {
            return Err(failed("execution-context"));
        }
        Ok(())
    }
}

/// Quote systemd values, including its own specifier expansion. No shell interprets these values.
fn directive(value: &str) -> String {
    format!(
        "\"{}\"",
        value
            .replace('\\', "\\\\")
            .replace('"', "\\\"")
            .replace('%', "%%")
    )
}

pub(super) fn render(input: &InstallInput) -> Result<Artifacts, ScheduleError> {
    let paths = paths(&input.id)?;
    let context = ExecutionContext {
        user: input.user.clone(),
        group: input.group.clone(),
        home: input.home.clone(),
        working_directory: input
            .working_directory
            .to_str()
            .ok_or_else(|| failed("execution-context"))?
            .into(),
        shell: input.shell.clone(),
        login_shell: input.login_shell,
    };
    context.validate()?;
    if !matches!(input.desired.as_str(), "enabled" | "disabled")
        || !(1..=86400).contains(&input.timeout_seconds)
        || input.command.is_empty()
        || input.command.len() > 4096
        || input.command.contains(['\0', '\r', '\n'])
    {
        return Err(failed("specification"));
    }
    if input.calendar.is_empty()
        || input.calendar.len() > 255
        || !input.calendar.bytes().all(|b| (32..=126).contains(&b))
    {
        return Err(ScheduleError::CalendarInvalid {});
    }
    let script = format!(
        "# X-Orbit-Schedule-ID={}\n{CONTEXT}{}\n{}\n",
        input.id,
        serde_json::to_string(&context).map_err(|_| failed("execution-context"))?,
        input.command
    );
    let service = format!("[Unit]\nDescription=Orbit Schedule {id}\nX-Orbit-Schedule-ID={id}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser={user}\nGroup={group}\nWorkingDirectory={directory}\nEnvironment={home}\nEnvironment={shell}\nExecStart={AGENT} service schedules-run {id}\nExecStopPost=+{AGENT} service schedules-complete {id}\nTimeoutStartSec={timeout}\n",
        id=input.id, user=input.user, group=input.group,
        directory=directive(&context.working_directory), home=directive(&format!("HOME={}", input.home)),
        shell=directive(&format!("SHELL={}", input.shell)), timeout=input.timeout_seconds);
    let timer = format!("[Unit]\nDescription=Orbit Schedule timer {id}\nX-Orbit-Schedule-ID={id}\n\n[Timer]\nOnCalendar={calendar}\nPersistent=true\nUnit={service}\n\n[Install]\nWantedBy=timers.target\n",
        id=input.id, calendar=input.calendar.replace('%', "%%"), service=paths.service_unit);
    Ok(Artifacts {
        script,
        service,
        timer,
    })
}

pub(super) fn execution(
    script: &str,
    id: &str,
) -> Result<(ExecutionContext, String), ScheduleError> {
    let mut lines = script.lines();
    if lines.next() != Some(format!("# X-Orbit-Schedule-ID={id}").as_str()) {
        return Err(ScheduleError::ArtifactConflict {});
    }
    let context: ExecutionContext = serde_json::from_str(
        lines
            .next()
            .and_then(|line| line.strip_prefix(CONTEXT))
            .ok_or_else(|| failed("execution-context"))?,
    )
    .map_err(|_| failed("execution-context"))?;
    context.validate()?;
    let command = lines.next().ok_or_else(|| failed("specification"))?;
    if command.is_empty()
        || command.len() > 4096
        || command.contains('\0')
        || lines.next().is_some()
    {
        return Err(failed("specification"));
    }
    Ok((context, command.into()))
}
