//! Fixed Gateway control-plane services. PHP supplies identity and policy, never unit source.
use super::{
    gateway_units,
    recipe::{self, Files, Host, Invocation},
};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{fs, os::unix::fs::MetadataExt, time::Duration};

command_errors! {
    pub enum Error {
        Invalid {} => "gateway.service_units_invalid", "The Gateway service identity or sweep interval is invalid.";
        Fleet { stage: String } => "gateway.fleet_units_install_failed", "The fleet rollout units could not be converged.";
        View { stage: String } => "gateway.agent_view_install_failed", "The agent view subscriber could not be converged.";
        Hibernator { stage: String } => "gateway.hibernator_install_failed", "The runtime hibernator could not be converged.";
        Start {} => "gateway.fleet_start_failed", "The fleet rollout could not be started or scheduled.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    application: String,
    orbit_home: String,
    user: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct HibernatorInput {
    application: String,
    orbit_home: String,
    user: String,
    sweep_seconds: u64,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StartInput {
    deferred: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Changed {
    changed: bool,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Started {
    accepted: bool,
}
#[derive(Clone, Copy)]
enum Service {
    Fleet,
    View,
    Hibernator(u64),
}
impl Service {
    fn prefix(self) -> &'static str {
        match self {
            Self::Fleet => "fleet",
            Self::View => "agent-view",
            Self::Hibernator(_) => "hibernator",
        }
    }
    fn name(self) -> &'static str {
        match self {
            Self::Fleet => "orbit-fleet-converge",
            Self::View => "orbit-agent-view",
            Self::Hibernator(_) => "orbit-runtime-hibernator",
        }
    }
    fn description(self) -> &'static str {
        match self {
            Self::Fleet => "Orbit fleet rollout and catch-up",
            Self::View => "Orbit agent view subscriber",
            Self::Hibernator(_) => "Orbit runtime hibernator",
        }
    }
    fn artisan(self) -> &'static str {
        match self {
            Self::Fleet => "orbit:fleet-converge",
            Self::View => "orbit:agent-view",
            Self::Hibernator(_) => "orbit:runtime-hibernator",
        }
    }
    fn failed(self, action: &str) -> Error {
        let stage = format!("gateway-{}-{action}", self.prefix());
        match self {
            Self::Fleet => Error::Fleet { stage },
            Self::View => Error::View { stage },
            Self::Hibernator(_) => Error::Hibernator { stage },
        }
    }
    fn timer(self) -> Option<String> {
        let (description, timing) = match self {
            Self::Fleet => (
                "Orbit fleet catch-up timer",
                "OnBootSec=5min\nOnUnitInactiveSec=300s".into(),
            ),
            Self::Hibernator(seconds) => (
                "Orbit runtime hibernator timer",
                format!("OnBootSec=2min\nOnUnitActiveSec={seconds}s"),
            ),
            Self::View => return None,
        };
        Some(format!("[Unit]\nDescription={description}\n\n[Timer]\n{timing}\nPersistent=true\nUnit={}.service\n\n[Install]\nWantedBy=timers.target\n", self.name()))
    }
}
fn validate(input: &Input, service: Service) -> Result<(), Error> {
    if !gateway_units::path(&input.application)
        || !input.application.ends_with("/apps/gateway")
        || !gateway_units::path(&input.orbit_home)
        || input.user.is_empty()
        || input.user == "root"
        || !input
            .user
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'_' | b'-'))
        || matches!(service, Service::Hibernator(0))
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn render(input: &Input, service: Service) -> Vec<(String, String, &'static str)> {
    let kind = if matches!(service, Service::View) {
        "simple"
    } else {
        "oneshot"
    };
    let extra = if matches!(service, Service::View) {
        "Restart=always\nRestartSec=2\nMemoryMax=256M\n\n[Install]\nWantedBy=multi-user.target\n"
    } else {
        ""
    };
    let contents = format!("[Unit]\nDescription={}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType={kind}\nUser={}\nWorkingDirectory={}\nEnvironment=ORBIT_HOME={}\nExecStart=\"/usr/bin/php8.5\" {} \"{}\"\n{extra}", service.description(), input.user, gateway_units::directive(&input.application), gateway_units::directive(&input.orbit_home), gateway_units::argument(&format!("{}/artisan", input.application)), service.artisan());
    let mut files = vec![(
        format!("/etc/systemd/system/{}.service", service.name()),
        contents,
        "service",
    )];
    if let Some(timer) = service.timer() {
        files.push((
            format!("/etc/systemd/system/{}.timer", service.name()),
            timer,
            "timer",
        ));
    }
    files
}
fn run(host: &mut impl Host, argv: &[&str]) -> Result<(), ()> {
    let mut call = Invocation::new(argv);
    call.timeout = Duration::from_secs(30);
    recipe::run(host, &call, true).map(|_| ()).map_err(|_| ())
}
fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &Input,
    service: Service,
) -> Result<Changed, Error> {
    validate(input, service)?;
    let desired = render(input, service);
    for (path, _, action) in &desired {
        gateway_units::preflight(files, &files.path(path), action)
            .map_err(|_| service.failed(action))?;
    }
    let mut changed = false;
    for (name, contents, action) in desired {
        let path = files.path(&name);
        let mut parents: Vec<_> = path
            .parent()
            .unwrap()
            .ancestors()
            .take_while(|p| p.starts_with(&files.root))
            .collect();
        parents.reverse();
        for parent in parents {
            files
                .directory(parent, files.uid, files.gid)
                .map_err(|_| service.failed(action))?;
        }
        let same = fs::metadata(&path)
            .is_ok_and(|m| m.mode() & 0o7777 == 0o644 && m.gid() == files.gid)
            && fs::read(&path).ok().as_deref() == Some(contents.as_bytes());
        if !same {
            files
                .publish_root(&path, contents.as_bytes())
                .map_err(|_| service.failed(action))?;
            changed = true;
        }
    }
    run(host, &["/usr/bin/systemctl", "daemon-reload"]).map_err(|_| service.failed("reload"))?;
    if matches!(service, Service::View) {
        let name = format!("{}.service", service.name());
        run(host, &["/usr/bin/systemctl", "enable", &name])
            .map_err(|_| service.failed("enable"))?;
        // A release switch keeps the same stable path; every handoff must restart the subscriber.
        run(host, &["/usr/bin/systemctl", "restart", &name])
            .map_err(|_| service.failed("restart"))?;
        changed = true;
    } else {
        let name = format!("{}.timer", service.name());
        let enabled = gateway_units::state(host, "is-enabled", &name, "enable")
            .map_err(|_| service.failed("enable"))?;
        let active = gateway_units::state(host, "is-active", &name, "enable")
            .map_err(|_| service.failed("enable"))?;
        run(host, &["/usr/bin/systemctl", "enable", "--now", &name])
            .map_err(|_| service.failed("enable"))?;
        changed |= enabled != "enabled" || active != "active";
    }
    Ok(Changed { changed })
}
fn start(host: &mut impl Host, input: &StartInput) -> Result<Started, Error> {
    if input.deferred {
        run(
            host,
            &[
                "/usr/bin/systemd-run",
                "--quiet",
                "--collect",
                "--on-active=15",
                &format!(
                    "--unit=orbit-fleet-converge-restart-{:016x}",
                    rand::random::<u64>()
                ),
                "/usr/bin/systemctl",
                "start",
                "--no-block",
                "orbit-fleet-converge.service",
            ],
        )
    } else {
        run(
            host,
            &[
                "/usr/bin/systemctl",
                "start",
                "--no-block",
                "orbit-fleet-converge.service",
            ],
        )
    }
    .map_err(|_| Error::Start {})?;
    Ok(Started { accepted: true })
}
fn native(input: Input, service: Service, context: &Context) -> Result<Changed, Error> {
    validate(&input, service)?;
    let account = recipe::store::Account::named(&input.user).map_err(|_| Error::Invalid {})?;
    if account.uid == 0 {
        return Err(Error::Invalid {});
    }
    converge(
        &Files::system(),
        &mut recipe::Native(context),
        &input,
        service,
    )
}
macro_rules! service_command {
    ($type:ident, $name:literal, $target:literal, $input:ty, $output:ty, $body:expr) => {
        pub struct $type;
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str = "Manage one fixed Gateway control-plane service.";
            const MUTATES: bool = true;
            const TIMEOUT: Duration = Duration::from_secs(120);
            type Input = $input;
            type Output = $output;
            type Error = Error;
            fn privilege(platform: Platform) -> Option<Privilege> {
                super::linux_only(platform, Privilege::Root)
            }
            fn target(_: &Self::Input) -> Option<String> {
                Some($target.into())
            }
            fn run(input: Self::Input, context: &Context) -> Result<Self::Output, Error> {
                ($body)(input, context)
            }
        }
    };
}
service_command!(
    Fleet,
    "gateway.converge-fleet-units",
    "gateway-fleet-units",
    Input,
    Changed,
    |input, context| native(input, Service::Fleet, context)
);
service_command!(
    View,
    "gateway.converge-agent-view",
    "gateway-agent-view",
    Input,
    Changed,
    |input, context| native(input, Service::View, context)
);
service_command!(
    Hibernator,
    "gateway.converge-hibernator",
    "gateway-hibernator",
    HibernatorInput,
    Changed,
    |input: HibernatorInput, context| native(
        Input {
            application: input.application,
            orbit_home: input.orbit_home,
            user: input.user
        },
        Service::Hibernator(input.sweep_seconds),
        context
    )
);
service_command!(
    StartFleet,
    "gateway.start-fleet-run",
    "gateway-fleet-units",
    StartInput,
    Started,
    |input: StartInput, context| start(&mut recipe::Native(context), &input)
);
#[cfg(test)]
mod tests;
