//! Registered SQLite inspection. Open the existing file only as the selected non-root owner.
use crate::{
    command::{identity, Command, Context, Platform, Privilege},
    command_errors,
};
use rusqlite::{types::ValueRef, Connection, OpenFlags};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::{
    fs,
    path::Path,
    time::{Duration, Instant},
};

const ROW_LIMIT: usize = 500;
command_errors! {
    pub enum Error {
        Unreadable {} => "database.sqlite_unreadable", "The SQLite file must exist and be accessible to the inspection user.";
        Failed {} => "database.query_failed", "Database query failed.";
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    owner: String,
    path: String,
    sql: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    columns: Vec<String>,
    rows: Vec<Map<String, Value>>,
    row_count: usize,
    truncated: bool,
}
fn validate(input: &Input) -> Result<(), Error> {
    if !input.path.starts_with('/')
        || input.path.len() > 1024
        || input.path.contains('\0')
        || input.sql.trim().is_empty()
        || input.sql.trim().len() > 16384
        || input.sql.contains('\0')
    {
        return Err(Error::Failed {});
    }
    Ok(())
}
fn database_error(error: rusqlite::Error, write: bool) -> Error {
    match error.sqlite_error_code() {
        Some(rusqlite::ErrorCode::PermissionDenied | rusqlite::ErrorCode::CannotOpen) => {
            Error::Unreadable {}
        }
        Some(rusqlite::ErrorCode::ReadOnly) if write => Error::Unreadable {},
        _ => Error::Failed {},
    }
}
fn query(input: &Input, write: bool, deadline: Instant) -> Result<Output, Error> {
    validate(input)?;
    if !fs::metadata(&input.path).is_ok_and(|meta| meta.is_file()) {
        return Err(Error::Unreadable {});
    }
    // Opening with the requested OS access preserves the former preflight for SELECT in write mode.
    fs::OpenOptions::new()
        .read(true)
        .write(write)
        .open(&input.path)
        .map_err(|_| Error::Unreadable {})?;
    let flags = if write {
        OpenFlags::SQLITE_OPEN_READ_WRITE
    } else {
        OpenFlags::SQLITE_OPEN_READ_ONLY
    };
    let db = Connection::open_with_flags(Path::new(&input.path), flags)
        .map_err(|error| database_error(error, write))?;
    db.busy_timeout(
        Duration::from_secs(10).min(deadline.saturating_duration_since(Instant::now())),
    )
    .map_err(|_| Error::Failed {})?;
    db.progress_handler(1000, Some(move || Instant::now() >= deadline))
        .map_err(|_| Error::Failed {})?;
    let mut statement = db
        .prepare(input.sql.trim())
        .map_err(|error| database_error(error, write))?;
    let columns = statement
        .column_names()
        .into_iter()
        .map(String::from)
        .collect::<Vec<_>>();
    let mut cursor = statement
        .query([])
        .map_err(|error| database_error(error, write))?;
    let mut rows = vec![];
    let mut truncated = false;
    while let Some(row) = cursor
        .next()
        .map_err(|error| database_error(error, write))?
    {
        if rows.len() == ROW_LIMIT {
            truncated = true;
            break;
        }
        let mut values = Map::new();
        for (index, name) in columns.iter().enumerate() {
            let value = match row.get_ref(index).map_err(|_| Error::Failed {})? {
                ValueRef::Null => Value::Null,
                ValueRef::Integer(value) => value.into(),
                ValueRef::Real(value) => serde_json::Number::from_f64(value)
                    .map(Value::Number)
                    .ok_or(Error::Failed {})?,
                ValueRef::Text(value) | ValueRef::Blob(value) => std::str::from_utf8(value)
                    .map_err(|_| Error::Failed {})?
                    .into(),
            };
            values.insert(name.clone(), value);
        }
        rows.push(values);
    }
    Ok(Output {
        columns,
        row_count: if write {
            db.changes() as usize
        } else {
            rows.len()
        },
        rows,
        truncated,
    })
}

fn execute(input: Input, write: bool, context: &Context) -> Result<Output, Error> {
    validate(&input)?;
    let (uid, _, _) = identity::account(&input.owner).map_err(|_| Error::Unreadable {})?;
    if uid == 0 {
        return Err(Error::Unreadable {});
    }
    let deadline = context.deadline();
    if identity::effective_uid() == uid {
        return query(&input, write, deadline);
    }
    let owner = input.owner.clone();
    identity::on_account_thread(&owner, move || query(&input, write, deadline)).map_err(
        |error| match error {
            identity::AccountThreadError::Identity => Error::Unreadable {},
            identity::AccountThreadError::Ended => Error::Failed {},
        },
    )?
}

pub struct Query;
pub struct Write;
macro_rules! command {
    ($type:ty, $name:literal, $write:literal, $summary:literal) => {
        impl Command for $type {
            const NAME: &'static str = $name;
            const SUMMARY: &'static str = $summary;
            const MUTATES: bool = $write;
            const TIMEOUT: Duration = Duration::from_secs(30);
            type Input = Input;
            type Output = Output;
            type Error = Error;
            fn privilege(_: Platform) -> Option<Privilege> {
                Some(Privilege::Owner)
            }
            fn target(input: &Input) -> Option<String> {
                Some(format!(
                    "sqlite:{}",
                    fs::canonicalize(&input.path)
                        .unwrap_or_else(|_| Path::new(&input.path).to_path_buf())
                        .display()
                ))
            }
            fn run(input: Input, context: &Context) -> Result<Output, Error> {
                execute(input, $write, context)
            }
        }
    };
}
command!(
    Query,
    "database.query-sqlite",
    false,
    "Query an existing SQLite database read-only as its selected owner."
);
command!(
    Write,
    "database.write-sqlite",
    true,
    "Run an authorized write against an existing SQLite database as its selected owner."
);

#[cfg(test)]
#[path = "sqlite_query_tests.rs"]
mod tests;
