//! Continuous Metrics health observation. All runtime mutations live in Swamp.
use crate::command::{Command, CommandError, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::{
    process::{Command as Process, Stdio},
    time::Duration,
};

#[derive(Clone, Copy, Debug)]
pub struct Error;
impl CommandError for Error {
    const CODES: &'static [&'static str] = &["metrics.container_inspection_failed"];
    fn code(&self) -> &'static str {
        Self::CODES[0]
    }
    fn message(&self) -> String {
        "Metrics health could not be observed.".into()
    }
    fn details(&self) -> Value {
        json!({"status":502})
    }
}
#[derive(schemars::JsonSchema, Clone, Copy, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Service {
    Prometheus,
    Grafana,
}
impl Service {
    fn value(self) -> &'static str {
        match self {
            Self::Prometheus => "prometheus",
            Self::Grafana => "grafana",
        }
    }
    fn name(self) -> String {
        format!("orbit-metrics-{}", self.value())
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    service: Service,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Output {
    healthy: bool,
}
fn owned(service: Service, raw: &str) -> bool {
    serde_json::from_str::<Value>(raw)
        .ok()
        .is_some_and(|value| {
            value.get("com.orbit.managed").and_then(Value::as_str) == Some("metrics")
                && value
                    .get("com.orbit.metrics.service")
                    .and_then(Value::as_str)
                    == Some(service.value())
        })
}
fn inspect(service: Service, format: &str) -> Option<String> {
    let result = Process::new("/usr/bin/timeout")
        .args([
            "--kill-after=5s",
            "20s",
            "/usr/bin/docker",
            "container",
            "inspect",
            format,
            "--",
            &service.name(),
        ])
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    result
        .status
        .success()
        .then(|| String::from_utf8_lossy(&result.stdout).into_owned())
}
pub struct MetricsHealth;
impl Command for MetricsHealth {
    const NAME: &'static str = "metrics.runtime-health";
    const SUMMARY: &'static str = "Observe the health of an owned Metrics container.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        let healthy = inspect(input.service, "--format={{json .Config.Labels}}")
            .is_some_and(|raw| owned(input.service, &raw))
            && inspect(
                input.service,
                "--format={{.State.Status}} {{.State.Health.Status}}",
            )
            .is_some_and(|raw| raw.trim() == "running healthy");
        Ok(Output { healthy })
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn health_requires_owned_labels_and_fixed_service_identity() {
        assert!(owned(
            Service::Grafana,
            r#"{"com.orbit.managed":"metrics","com.orbit.metrics.service":"grafana"}"#
        ));
        for value in [
            "null",
            "{}",
            "not json",
            r#"{"com.orbit.managed":"foreign","com.orbit.metrics.service":"grafana"}"#,
            r#"{"com.orbit.managed":"metrics","com.orbit.metrics.service":"prometheus"}"#,
        ] {
            assert!(!owned(Service::Grafana, value));
        }
    }
}
