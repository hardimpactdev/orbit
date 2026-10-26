use super::*;
use crate::command::CommandError;
use serde_json::json;

#[derive(Default)]
struct Fake {
    calls: Vec<Invocation>,
    output: recipe::Output,
    fail: bool,
}
impl Host for Fake {
    fn execute(&mut self, invocation: &Invocation) -> Result<recipe::Output, recipe::Failure> {
        self.calls.push(invocation.clone());
        if self.fail {
            Err(recipe::Failure::new("execute", "unavailable"))
        } else {
            Ok(self.output.clone())
        }
    }
    fn wait(&mut self, _: Duration) -> Result<(), recipe::Failure> {
        panic!("unexpected wait")
    }
}
fn input() -> Input {
    Input {
        user: "orbit".into(),
        downgrade_to: None,
    }
}
fn raw(code: i32, value: Value) -> recipe::Output {
    recipe::Output {
        code,
        stdout: value.to_string(),
        ..Default::default()
    }
}
#[test]
fn fixed_root_profile_exact_downgrade_and_repeated_invocations() {
    let mut input = input();
    let mut host = Fake {
        output: raw(
            0,
            json!({"outcome":"unchanged","steps":[{"step":"cli","after":{"version":"0.4681.0"}}]}),
        ),
        ..Default::default()
    };
    for _ in 0..2 {
        let result = execute(&input, &mut host, |user| {
            assert_eq!(user, "orbit");
            cli::State::Present
        })
        .unwrap();
        assert_eq!(result.outcome, Outcome::Unchanged);
        assert_eq!(result.cli_version.as_deref(), Some("0.4681.0"));
        assert!(result.error_code.is_none());
    }
    assert_eq!(
        host.calls[0].argv,
        [
            "/usr/bin/env",
            "-i",
            "HOME=/root",
            "ORBIT_HOME=/root/.orbit",
            "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
            "LANG=C.UTF-8",
            "/usr/local/bin/orbit",
            "self-update",
            "--json"
        ]
    );
    input.downgrade_to = Some("0.4681.0".into());
    execute(&input, &mut host, |_| cli::State::Present).unwrap();
    assert_eq!(
        host.calls[2].argv.last().unwrap(),
        "--allow-downgrade-to=0.4681.0"
    );
    assert_eq!(host.calls[2].timeout, SelfUpdate::TIMEOUT);
}
#[test]
fn invalid_input_and_untrusted_cli_refuse_before_execution() {
    for user in ["", "root", "orbit\n", "user;false", "../orbit"] {
        let input = Input {
            user: user.into(),
            downgrade_to: None,
        };
        assert_eq!(
            execute(&input, &mut Fake::default(), |_| panic!(
                "inspected invalid user"
            ))
            .unwrap_err()
            .code(),
            "cli.self_update_invalid"
        );
    }
    for version in [
        "",
        "0.01.0",
        "0.1.1",
        "../binary",
        "0.1.0\n",
        "0.1.0 --allow-downgrade",
    ] {
        let input = Input {
            downgrade_to: Some(version.into()),
            ..input()
        };
        assert_eq!(
            execute(&input, &mut Fake::default(), |_| panic!(
                "inspected invalid version"
            ))
            .unwrap_err()
            .code(),
            "cli.self_update_invalid"
        );
    }
    for state in [cli::State::Missing, cli::State::Foreign, cli::State::Stale] {
        let mut host = Fake::default();
        let error = execute(&input(), &mut host, |_| state).unwrap_err();
        assert_eq!(error, Error::Unavailable { state });
        assert!(host.calls.is_empty());
    }
    assert!(
        serde_json::from_value::<Input>(json!({"user":"orbit","executable":"/tmp/other"})).is_err()
    );
}
#[test]
fn parses_every_success_outcome_and_preserves_the_report() {
    for (name, expected) in [
        ("updated", Outcome::Updated),
        ("unchanged", Outcome::Unchanged),
        ("pending", Outcome::Pending),
        ("incomplete", Outcome::Incomplete),
    ] {
        let value = json!({"outcome":name,"commit":"a".repeat(40),"steps":[]});
        let result = report(raw(0, value.clone()));
        assert_eq!(result.outcome, expected);
        assert_eq!(Value::Object(result.report.unwrap()), value);
        assert!(result.cli_version.is_none());
        assert!(result.error_code.is_none());
    }
}
#[test]
fn failed_step_and_envelope_codes_are_preserved_and_unknown_success_is_not_converged() {
    for value in [
        json!({"outcome":"failed","steps":[{"outcome":"unchanged","error":{"code":"wrong"}}, {"outcome":"failed","error":{"code":"self_update.downgrade_refused"}}],"error":{"code":"lower-priority"}}),
        json!({"error":{"code":"self_update.downgrade_refused"}}),
        json!({"error_code":"self_update.downgrade_refused"}),
    ] {
        let result = report(raw(1, value));
        assert_eq!(result.outcome, Outcome::Failed);
        assert_eq!(
            result.error_code.as_deref(),
            Some("self_update.downgrade_refused")
        );
        assert!(result.report.is_some());
    }
    for (code, value) in [
        (1, json!({"outcome":"updated"})),
        (0, json!({"outcome":"failed"})),
        (0, json!({"outcome":"future"})),
        (0, json!({})),
        (0, json!([])),
        (0, json!("text")),
    ] {
        let result = report(raw(code, value));
        assert_eq!(result.outcome, Outcome::Failed);
        assert_eq!(
            result.error_code.as_deref(),
            Some("fleet.self_update_failed")
        );
    }
    let result = report(recipe::Output {
        code: -1,
        stdout: "not JSON".into(),
        ..Default::default()
    });
    assert!(result.report.is_none());
    assert_eq!(result.exit_code, -1);
}
#[test]
fn unavailable_and_truncated_execution_refuse_and_diagnostics_are_bounded_and_redacted() {
    for fail in [false, true] {
        let mut host = Fake {
            fail,
            output: recipe::Output {
                truncated: !fail,
                ..Default::default()
            },
            ..Default::default()
        };
        assert_eq!(
            execute(&input(), &mut host, |_| cli::State::Present).unwrap_err(),
            Error::Failed {}
        );
        assert_eq!(host.calls.len(), 1);
    }
    let result = report(recipe::Output {
        code: 1,
        stdout: "é".repeat(3000),
        stderr: format!("{} password=hunter2", "é".repeat(3000)),
        ..Default::default()
    });
    assert!(result.stdout.len() <= 4000 && result.stderr.len() <= 4000);
    assert!(!result.stderr.contains("hunter2"));
    assert!(result.stderr.ends_with("password=[REDACTED]"));
}
