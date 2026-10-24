//! Read-only Process evidence, gathered within one command deadline.
use super::*;

command_errors! {
    pub enum Error {
        Invalid {} => "doctor.process_invalid", "The Process inspection input is invalid.";
        Unavailable {} => "doctor.process_unavailable", "The owned Process runtime could not be inspected.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    process_id: u64,
    runtime: Runtime,
    name: String,
    desired: Desired,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Observation {
    active_state: String,
    sub_state: String,
    restarts: Option<u32>,
}
#[derive(Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Status {
    Active,
    Inactive,
    Running,
    Created,
    Exited,
    Other,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Observed {
    present: bool,
    status: Option<Status>,
    systemd_observations: Vec<Observation>,
}

impl Observed {
    fn absent() -> Self {
        Self {
            present: false,
            status: None,
            systemd_observations: vec![],
        }
    }
}
impl Observation {
    fn auto_restart(&self) -> bool {
        self.active_state == "activating" && self.sub_state == "auto-restart"
    }
    fn status(&self) -> Status {
        match self.active_state.as_str() {
            "active" => Status::Active,
            "inactive" => Status::Inactive,
            _ => Status::Other,
        }
    }
}

fn observation(output: Output) -> Result<Observation, Error> {
    if !output.ok() || !output.stderr.is_empty() || !output.stdout.ends_with('\n') {
        return Err(Error::Unavailable {});
    }
    let mut properties = BTreeMap::new();
    for line in output.stdout.strip_suffix('\n').unwrap().split('\n') {
        let (key, value) = line.split_once('=').ok_or(Error::Unavailable {})?;
        if !["ActiveState", "SubState", "NRestarts"].contains(&key)
            || properties.insert(key, value).is_some()
        {
            return Err(Error::Unavailable {});
        }
    }
    let active_state = *properties.get("ActiveState").ok_or(Error::Unavailable {})?;
    let sub_state = *properties.get("SubState").ok_or(Error::Unavailable {})?;
    if ![
        "active",
        "reloading",
        "inactive",
        "failed",
        "activating",
        "deactivating",
        "maintenance",
        "unknown",
    ]
    .contains(&active_state)
        || ![
            "dead",
            "condition",
            "start-pre",
            "start",
            "start-post",
            "running",
            "exited",
            "reload",
            "reload-signal",
            "reload-notify",
            "stop",
            "stop-watchdog",
            "stop-sigterm",
            "stop-sigkill",
            "stop-post",
            "final-watchdog",
            "final-sigterm",
            "final-sigkill",
            "failed",
            "dead-before-auto-restart",
            "failed-before-auto-restart",
            "dead-resources-pinned",
            "auto-restart",
            "auto-restart-queued",
            "cleaning",
            "mounting",
        ]
        .contains(&sub_state)
    {
        return Err(Error::Unavailable {});
    }
    let restarts = match properties.get("NRestarts").copied().unwrap_or("") {
        "" => None,
        value
            if value.bytes().all(|b| b.is_ascii_digit())
                && (value == "0" || !value.starts_with('0')) =>
        {
            Some(value.parse().map_err(|_| Error::Unavailable {})?)
        }
        _ => return Err(Error::Unavailable {}),
    };
    Ok(Observation {
        active_state: active_state.into(),
        sub_state: sub_state.into(),
        restarts,
    })
}

const DOCKER_FORMAT: &str = r#"{{ index .Config.Labels "orbit.managed" }}{{ printf "\n" }}{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\n" }}{{ index .Config.Labels "orbit.process.id" }}{{ printf "\n" }}{{ .State.Status }}"#;

fn inspect(
    input: &Input,
    units: &Path,
    programs: &mut impl Programs,
    context: &Context,
    wait: &mut impl FnMut(Duration),
) -> Result<Observed, Error> {
    check_name(input.runtime, input.process_id, &input.name).map_err(|_| Error::Invalid {})?;
    if input.runtime == Runtime::Docker {
        let result = programs.run(
            "/usr/bin/docker",
            &[
                "container",
                "inspect",
                "--format",
                DOCKER_FORMAT,
                &input.name,
            ],
        );
        if docker_not_found(&result) && result.stdout.is_empty() {
            return Ok(Observed::absent());
        }
        if !result.ok() || !result.stderr.is_empty() {
            return Err(Error::Unavailable {});
        }
        let fields: Vec<_> = result
            .stdout
            .strip_suffix('\n')
            .ok_or(Error::Unavailable {})?
            .split('\n')
            .collect();
        let [managed, kind, owner, state] = fields.as_slice() else {
            return Err(Error::Unavailable {});
        };
        if *managed != "true" || *kind != "process" || *owner != input.process_id.to_string() {
            return Err(Error::Unavailable {});
        }
        let status = match *state {
            "running" => Status::Running,
            "created" => Status::Created,
            "exited" => Status::Exited,
            "paused" | "restarting" | "removing" | "dead" => Status::Other,
            _ => return Err(Error::Unavailable {}),
        };
        return Ok(Observed {
            present: true,
            status: Some(status),
            systemd_observations: vec![],
        });
    }
    let content = match fs::read_to_string(units.join(&input.name)) {
        Ok(content) => content,
        Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(Observed::absent()),
        Err(_) => return Err(Error::Unavailable {}),
    };
    if !marked(&content, input.process_id) {
        return Err(Error::Unavailable {});
    }
    let mut sample = || {
        observation(programs.run(
            "/usr/bin/systemctl",
            &[
                "show",
                "--property=ActiveState,SubState,NRestarts",
                "--",
                &input.name,
            ],
        ))
    };
    let before = sample()?;
    let observe_again = input.desired == Desired::Running && !before.auto_restart();
    let mut observations = vec![before];
    if observe_again {
        let delay = Duration::from_secs(2);
        if context.remaining() <= delay {
            return Err(Error::Unavailable {});
        }
        wait(delay);
        if context.remaining().is_zero() {
            return Err(Error::Unavailable {});
        }
        observations.push(sample()?);
    }
    Ok(Observed {
        present: true,
        status: Some(observations.last().unwrap().status()),
        systemd_observations: observations,
    })
}

struct Native;
impl Programs for Native {
    fn run(&mut self, program: &str, arguments: &[&str]) -> Output {
        match Process::new(program)
            .args(arguments)
            .stdin(Stdio::null())
            .output()
        {
            Ok(output)
                if output.stdout.len() <= MAX_STREAM_BYTES
                    && output.stderr.len() <= MAX_STREAM_BYTES =>
            {
                Output {
                    exit_code: output.status.code().unwrap_or(-1),
                    stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
                    stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
                }
            }
            _ => Output {
                exit_code: -1,
                ..Output::default()
            },
        }
    }
}

pub struct InspectProcess;
impl Command for InspectProcess {
    const NAME: &'static str = "doctor.inspect-process";
    const SUMMARY: &'static str = "Inspect an owned Process and gather bounded restart evidence.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = Input;
    type Output = Observed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Observed, Error> {
        inspect(
            &input,
            Path::new("/etc/systemd/system"),
            &mut Native,
            context,
            &mut std::thread::sleep,
        )
    }
}

#[cfg(test)]
#[path = "process_doctor_tests.rs"]
mod tests;
