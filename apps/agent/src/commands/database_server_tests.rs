use super::*;
use crate::commands::recipe::WorkDir;
use std::{collections::VecDeque, fs, sync::mpsc};

/// Answers each client call from a queue, records the calls, and moves its clock only when the command sleeps.
struct Fake {
    answers: VecDeque<Answer>,
    calls: Vec<Request>,
    sleeps: Vec<Duration>,
    clock: Instant,
}

impl Fake {
    fn new(answers: impl IntoIterator<Item = Answer>) -> Self {
        Self {
            answers: answers.into_iter().collect(),
            calls: vec![],
            sleeps: vec![],
            clock: Instant::now(),
        }
    }
}

impl Host for Fake {
    fn exec(&mut self, request: &Request) -> Result<Answer, DatabaseServerError> {
        self.calls.push(request.clone());
        Ok(self.answers.pop_front().expect("unexpected client call"))
    }

    fn now(&mut self) -> Instant {
        self.clock
    }

    fn sleep(&mut self, delay: Duration) {
        self.sleeps.push(delay);
        self.clock += delay;
    }
}

fn answer(code: i32, stdout: &[u8], stderr: &[u8]) -> Answer {
    Answer {
        code: Some(code),
        stdout: stdout.to_vec(),
        stderr: stderr.to_vec(),
    }
}

fn arguments(container: &str, variable: &str, client: &[&str]) -> Vec<String> {
    [
        "container",
        "exec",
        "-i",
        "--env",
        variable,
        "--",
        container,
    ]
    .iter()
    .chain(client)
    .map(|s| s.to_string())
    .collect()
}

fn execute(variable: &str, client: &[&str], sql: &str) -> DatabaseServerInput {
    DatabaseServerInput::Execute {
        container: "orbit-db".into(),
        password: "s3cret".into(),
        password_variable: variable.into(),
        client: client.iter().map(|s| s.to_string()).collect(),
        sql: sql.into(),
    }
}

fn ready(timeout_seconds: u64) -> DatabaseServerInput {
    DatabaseServerInput::Ready {
        container: "orbit-db".into(),
        password: "s3cret".into(),
        password_variable: "MYSQL_PWD".into(),
        client: vec!["mysql".into(), "--user=root".into()],
        timeout_seconds,
    }
}

fn copy(source: &str, target: &str) -> DatabaseServerInput {
    DatabaseServerInput::Copy {
        container: "orbit-db".into(),
        password: "s3cret".into(),
        source: source.into(),
        target: target.into(),
    }
}

#[test]
fn mysql_runs_in_the_container_with_the_password_in_the_environment_and_sql_on_stdin() {
    let mut host = Fake::new([answer(0, b"1\n", b"")]);
    let input = execute(
        "MYSQL_PWD",
        &["mysql", "--user=root", "--batch"],
        "SELECT 1;",
    );
    assert_eq!(
        admin(&mut host, &input),
        Ok(DatabaseServerOutput::Rows {
            output: "1\n".into()
        })
    );
    assert_eq!(
        host.calls,
        vec![Request {
            arguments: arguments(
                "orbit-db",
                "MYSQL_PWD",
                &["mysql", "--user=root", "--batch"]
            ),
            variable: "MYSQL_PWD".into(),
            password: "s3cret".into(),
            input: "SELECT 1;".into(),
        }]
    );
    assert!(!host.calls[0].arguments.iter().any(|a| a.contains("s3cret")));
}

#[test]
fn postgresql_runs_psql_with_pgpassword() {
    let mut host = Fake::new([answer(0, b"t\n", b"")]);
    let input = execute(
        "PGPASSWORD",
        &["psql", "--username=postgres", "--tuples-only"],
        "SELECT true;",
    );
    assert_eq!(
        admin(&mut host, &input),
        Ok(DatabaseServerOutput::Rows {
            output: "t\n".into()
        })
    );
    assert_eq!(
        host.calls,
        vec![Request {
            arguments: arguments(
                "orbit-db",
                "PGPASSWORD",
                &["psql", "--username=postgres", "--tuples-only"]
            ),
            variable: "PGPASSWORD".into(),
            password: "s3cret".into(),
            input: "SELECT true;".into(),
        }]
    );
    assert!(!host.calls[0].arguments.iter().any(|a| a.contains("s3cret")));
}

#[test]
fn readiness_retries_every_two_seconds_until_the_client_answers() {
    let mut host = Fake::new([
        answer(1, b"", b"connection refused"),
        answer(1, b"", b"connection refused"),
        answer(0, b"1\n", b""),
    ]);
    assert_eq!(
        admin(&mut host, &ready(60)),
        Ok(DatabaseServerOutput::Done {})
    );
    assert_eq!(host.calls.len(), 3);
    for call in &host.calls {
        assert_eq!(call.input, "SELECT 1;\n");
        assert_eq!(
            call.arguments,
            arguments("orbit-db", "MYSQL_PWD", &["mysql", "--user=root"])
        );
    }
    assert_eq!(host.sleeps, vec![Duration::from_secs(2); 2]);
}

#[test]
fn readiness_refuses_as_not_ready_once_its_deadline_passes() {
    let mut host = Fake::new((0..4).map(|_| answer(1, b"", b"connection refused")));
    assert_eq!(
        admin(&mut host, &ready(5)),
        Err(DatabaseServerError::NotReady {})
    );
    // Attempts at 0, 2, 4, and 6 seconds; the last one is past the 5 second deadline.
    assert_eq!(host.calls.len(), 4);
    assert_eq!(host.sleeps, vec![Duration::from_secs(2); 3]);
}

#[test]
fn a_failed_client_answers_its_exit_code_and_the_last_thousand_characters_of_stderr() {
    let stderr = format!("{}{}", "a".repeat(500), "b".repeat(1000));
    let mut host = Fake::new([answer(2, b"partial", stderr.as_bytes())]);
    assert_eq!(
        admin(&mut host, &execute("MYSQL_PWD", &["mysql"], "BAD;")),
        Err(DatabaseServerError::Failed {
            exit_code: 2,
            stderr: "b".repeat(1000),
        })
    );
}

#[test]
fn a_client_killed_by_a_signal_fails_with_exit_code_one() {
    let mut host = Fake::new([Answer {
        code: None,
        stdout: vec![],
        stderr: b"killed".to_vec(),
    }]);
    assert_eq!(
        admin(&mut host, &execute("MYSQL_PWD", &["mysql"], "SELECT 1;")),
        Err(DatabaseServerError::Failed {
            exit_code: 1,
            stderr: "killed".into(),
        })
    );
}

#[test]
fn an_answer_larger_than_one_mib_is_refused() {
    let mut host = Fake::new([
        answer(0, &vec![b'x'; MAX_OUTPUT], b""),
        answer(0, &vec![b'x'; MAX_OUTPUT + 1], b""),
    ]);
    let input = execute("MYSQL_PWD", &["mysql"], "SELECT 1;");
    assert!(matches!(
        admin(&mut host, &input),
        Ok(DatabaseServerOutput::Rows { output }) if output.len() == MAX_OUTPUT
    ));
    assert_eq!(
        admin(&mut host, &input),
        Err(invalid("the answer fits in one MiB"))
    );
}

#[test]
fn copy_dumps_and_loads_inside_the_container_with_the_databases_as_arguments() {
    let mut host = Fake::new([answer(0, b"", b"")]);
    assert_eq!(
        admin(&mut host, &copy("app", "app_copy")),
        Ok(DatabaseServerOutput::Done {})
    );
    assert_eq!(host.calls.len(), 1);
    let call = &host.calls[0];
    assert_eq!(
        call.arguments[..7],
        arguments("orbit-db", "MYSQL_PWD", &[])[..]
    );
    assert_eq!(&call.arguments[7..11], &["bash", "-euo", "pipefail", "-c"]);
    assert!(call.arguments[11].starts_with(r#"mysqldump --user=root --single-transaction"#));
    assert!(call.arguments[11].ends_with(r#""$1" | mysql --user=root "$2""#));
    assert_eq!(
        &call.arguments[12..],
        &["orbit-database-copy", "app", "app_copy"]
    );
    assert_eq!(
        (
            call.variable.as_str(),
            call.password.as_str(),
            call.input.as_str()
        ),
        ("MYSQL_PWD", "s3cret", "")
    );
    assert!(!call.arguments.iter().any(|a| a.contains("s3cret")));
}

#[test]
fn a_failed_copy_answers_the_client_failure() {
    let mut host = Fake::new([answer(
        1,
        b"",
        b"mysqldump: Got error: 1049: Unknown database",
    )]);
    assert_eq!(
        admin(&mut host, &copy("app", "app_copy")),
        Err(DatabaseServerError::Failed {
            exit_code: 1,
            stderr: "mysqldump: Got error: 1049: Unknown database".into(),
        })
    );
}

#[test]
fn invalid_requests_refuse_before_any_client_call() {
    let refused = |input: DatabaseServerInput, check: &str| {
        let mut host = Fake::new([]);
        assert_eq!(admin(&mut host, &input), Err(invalid(check)));
        assert!(host.calls.is_empty());
    };
    let mut leading_dash = execute("MYSQL_PWD", &["mysql"], "");
    if let DatabaseServerInput::Execute { container, .. } = &mut leading_dash {
        *container = "--privileged".into();
    }
    refused(leading_dash, "the container is a name");
    let mut unnamed = copy("app", "copy");
    if let DatabaseServerInput::Copy { container, .. } = &mut unnamed {
        *container = String::new();
    }
    refused(unnamed, "the container is a name");
    refused(
        execute("PATH", &["mysql"], ""),
        "the password variable is one the clients read",
    );
    refused(
        execute("MYSQL_PWD", &["sh", "-c"], ""),
        "the client is mysql or psql",
    );
    refused(
        execute("PGPASSWORD", &[], ""),
        "the client is mysql or psql",
    );
    refused(copy("app;drop", "copy"), "both databases are identifiers");
    refused(copy("app", ""), "both databases are identifiers");
    refused(
        copy("app", &"a".repeat(65)),
        "both databases are identifiers",
    );
    let mut bash = ready(60);
    if let DatabaseServerInput::Ready { client, .. } = &mut bash {
        *client = vec!["bash".into()];
    }
    refused(bash, "the client is mysql or psql");
}

/// A stand-in for `docker` that runs `body` with the arguments the command passes. `install` writes the executable,
/// so this process never holds a writable descriptor that a parallel test's fork could keep open (`ETXTBSY`).
fn program(work: &WorkDir, body: &str) -> Native {
    let source = work.0.join("docker.sh");
    let path = work.0.join("docker");
    fs::write(&source, format!("#!/bin/sh\n{body}\n")).unwrap();
    let status = std::process::Command::new("install")
        .args(["-m", "0755"])
        .arg(&source)
        .arg(&path)
        .status()
        .unwrap();
    assert!(status.success());
    Native { program: path }
}

#[test]
fn native_passes_the_password_only_through_the_environment() {
    let work = WorkDir::new().unwrap();
    let log = work.0.join("arguments");
    let mut host = program(
        &work,
        &format!(
            r#"printf '%s\n' "$@" > '{}'; printf 'pwd=%s stdin=' "$MYSQL_PWD"; cat"#,
            log.display()
        ),
    );
    assert_eq!(
        admin(&mut host, &execute("MYSQL_PWD", &["mysql"], "SELECT 1;")),
        Ok(DatabaseServerOutput::Rows {
            output: "pwd=s3cret stdin=SELECT 1;".into()
        })
    );
    assert_eq!(
        fs::read_to_string(&log).unwrap(),
        "container\nexec\n-i\n--env\nMYSQL_PWD\n--\norbit-db\nmysql\n"
    );
}

#[test]
fn native_answers_the_exit_code_and_stderr_of_the_program() {
    let work = WorkDir::new().unwrap();
    let mut host = program(&work, "cat > /dev/null; echo 'ERROR 1064' >&2; exit 3");
    assert_eq!(
        admin(&mut host, &execute("MYSQL_PWD", &["mysql"], "BAD;")),
        Err(DatabaseServerError::Failed {
            exit_code: 3,
            stderr: "ERROR 1064\n".into(),
        })
    );
    let mut missing = Native {
        program: work.0.join("absent"),
    };
    assert!(matches!(
        admin(&mut missing, &execute("MYSQL_PWD", &["mysql"], "")),
        Err(DatabaseServerError::Failed { exit_code: 127, .. })
    ));
}

/// The SQL and the answer are each larger than a pipe buffer, and the client answers before it reads all of its
/// input, as `mysql` does with a long script. Standard input must be written while the output drains.
#[test]
fn native_streams_large_sql_and_large_answers_without_deadlock() {
    let work = WorkDir::new().unwrap();
    let mut host = program(&work, "yes x | head -c 262144; cat");
    let sql: String = (0..512 * 1024)
        .map(|i| char::from(b'a' + (i % 26) as u8))
        .collect();
    let input = execute("MYSQL_PWD", &["mysql"], &sql);
    let (sender, receiver) = mpsc::channel();
    std::thread::spawn(move || {
        let _ = sender.send(admin(&mut host, &input));
    });
    let answered = receiver
        .recv_timeout(Duration::from_secs(30))
        .expect("the client call deadlocked");
    assert_eq!(
        answered,
        Ok(DatabaseServerOutput::Rows {
            output: "x\n".repeat(131072) + &sql
        })
    );
}
