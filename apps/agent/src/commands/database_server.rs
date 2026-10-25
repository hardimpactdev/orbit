//! `databases.server-admin`: runs the fixed MySQL or PostgreSQL admin client inside a Database server's container with
//! `docker container exec`. The root password reaches the client through one environment variable, never an argument
//! or a file; the SQL goes on its standard input. A copy dumps and loads inside the container, so data never leaves it.

use super::incus::{exchange, MAX_OUTPUT};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    path::PathBuf,
    process::{Command as Process, Stdio},
    time::{Duration, Instant},
};

command_errors! {
    pub enum DatabaseServerError {
        Invalid { check: String } => "databases.server_admin_invalid", "The admin request does not pass its checks.";
        Failed { exit_code: i32, stderr: String } => "databases.server_admin_failed", "The admin client failed.";
        NotReady {} => "databases.server_not_ready", "The server did not accept a query before the deadline.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum DatabaseServerInput {
    /// Repeats `SELECT 1` through the client until it answers or `timeout_seconds` pass.
    Ready {
        container: String,
        password: String,
        password_variable: String,
        client: Vec<String>,
        timeout_seconds: u64,
    },
    Execute {
        container: String,
        password: String,
        password_variable: String,
        client: Vec<String>,
        sql: String,
    },
    /// Copies one MySQL database into another inside the container.
    Copy {
        container: String,
        password: String,
        source: String,
        target: String,
    },
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(untagged)]
pub enum DatabaseServerOutput {
    Rows { output: String },
    Done {},
}

fn invalid(check: &str) -> DatabaseServerError {
    DatabaseServerError::Invalid {
        check: check.into(),
    }
}

fn identifier(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 64
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
}

fn checked(container: &str, variable: &str, client: &[String]) -> Result<(), DatabaseServerError> {
    if container.is_empty() || container.starts_with('-') {
        return Err(invalid("the container is a name"));
    }
    if !matches!(variable, "MYSQL_PWD" | "PGPASSWORD") {
        return Err(invalid("the password variable is one the clients read"));
    }
    if !matches!(client.first().map(String::as_str), Some("mysql" | "psql")) {
        return Err(invalid("the client is mysql or psql"));
    }
    Ok(())
}

/// One `docker container exec` call: the arguments after `docker`, the password variable, and standard input.
#[derive(Debug, Clone, PartialEq)]
struct Request {
    arguments: Vec<String>,
    variable: String,
    password: String,
    input: String,
}

/// What the client answered: its exit code and its output, which the reader bounds just past one MiB.
struct Answer {
    code: Option<i32>,
    stdout: Vec<u8>,
    stderr: Vec<u8>,
}

/// The process and clock seam: production runs Docker and sleeps, tests record calls and move a fake clock.
trait Host {
    fn exec(&mut self, request: &Request) -> Result<Answer, DatabaseServerError>;
    fn now(&mut self) -> Instant;
    fn sleep(&mut self, delay: Duration);
}

struct Native {
    program: PathBuf,
}

impl Host for Native {
    fn exec(&mut self, request: &Request) -> Result<Answer, DatabaseServerError> {
        let child = Process::new(&self.program)
            .args(&request.arguments)
            .env(&request.variable, &request.password)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| DatabaseServerError::Failed {
                exit_code: 127,
                stderr: error.to_string(),
            })?;
        let (status, stdout, stderr) =
            exchange(child, Some(request.input.as_bytes())).map_err(|error| {
                DatabaseServerError::Failed {
                    exit_code: 1,
                    stderr: error.to_string(),
                }
            })?;
        Ok(Answer {
            code: status.code(),
            stdout,
            stderr,
        })
    }

    fn now(&mut self) -> Instant {
        Instant::now()
    }

    fn sleep(&mut self, delay: Duration) {
        std::thread::sleep(delay);
    }
}

/// Runs the client with the password in `variable` and `input` on standard input.
fn exec(
    host: &mut impl Host,
    container: &str,
    password: &str,
    variable: &str,
    arguments: &[&str],
    input: &str,
) -> Result<String, DatabaseServerError> {
    let request = Request {
        arguments: [
            "container",
            "exec",
            "-i",
            "--env",
            variable,
            "--",
            container,
        ]
        .into_iter()
        .chain(arguments.iter().copied())
        .map(String::from)
        .collect(),
        variable: variable.into(),
        password: password.into(),
        input: input.into(),
    };
    let output = host.exec(&request)?;
    if output.code != Some(0) {
        let stderr = String::from_utf8_lossy(&output.stderr);
        return Err(DatabaseServerError::Failed {
            exit_code: output.code.unwrap_or(1),
            stderr: stderr
                .chars()
                .rev()
                .take(1000)
                .collect::<Vec<_>>()
                .into_iter()
                .rev()
                .collect(),
        });
    }
    if output.stdout.len() > MAX_OUTPUT {
        return Err(invalid("the answer fits in one MiB"));
    }
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

fn admin(
    host: &mut impl Host,
    input: &DatabaseServerInput,
) -> Result<DatabaseServerOutput, DatabaseServerError> {
    match input {
        DatabaseServerInput::Ready {
            container,
            password,
            password_variable,
            client,
            timeout_seconds,
        } => {
            checked(container, password_variable, client)?;
            let arguments: Vec<&str> = client.iter().map(String::as_str).collect();
            let deadline = host.now() + Duration::from_secs(*timeout_seconds);
            loop {
                if exec(
                    host,
                    container,
                    password,
                    password_variable,
                    &arguments,
                    "SELECT 1;\n",
                )
                .is_ok()
                {
                    return Ok(DatabaseServerOutput::Done {});
                }
                if host.now() >= deadline {
                    return Err(DatabaseServerError::NotReady {});
                }
                host.sleep(Duration::from_secs(2));
            }
        }
        DatabaseServerInput::Execute {
            container,
            password,
            password_variable,
            client,
            sql,
        } => {
            checked(container, password_variable, client)?;
            let arguments: Vec<&str> = client.iter().map(String::as_str).collect();
            exec(
                host,
                container,
                password,
                password_variable,
                &arguments,
                sql,
            )
            .map(|output| DatabaseServerOutput::Rows { output })
        }
        DatabaseServerInput::Copy {
            container,
            password,
            source,
            target,
        } => {
            if !identifier(source) || !identifier(target) {
                return Err(invalid("both databases are identifiers"));
            }
            checked(container, "MYSQL_PWD", &["mysql".into()])?;
            exec(
                host,
                container,
                password,
                "MYSQL_PWD",
                &[
                    "bash",
                    "-euo",
                    "pipefail",
                    "-c",
                    r#"mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$1" | mysql --user=root "$2""#,
                    "orbit-database-copy",
                    source,
                    target,
                ],
                "",
            )
            .map(|_| DatabaseServerOutput::Done {})
        }
    }
}

pub struct DatabaseServerAdmin;

impl Command for DatabaseServerAdmin {
    const NAME: &'static str = "databases.server-admin";
    const SUMMARY: &'static str =
        "Run the admin client of a Database server's container, or copy a database inside it.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = DatabaseServerInput;
    type Output = DatabaseServerOutput;
    type Error = DatabaseServerError;

    /// Docker answers only root; the client runs inside the container.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(
        input: DatabaseServerInput,
        _: &Context,
    ) -> Result<DatabaseServerOutput, DatabaseServerError> {
        admin(
            &mut Native {
                program: "docker".into(),
            },
            &input,
        )
    }
}

#[cfg(test)]
#[path = "database_server_tests.rs"]
mod tests;
