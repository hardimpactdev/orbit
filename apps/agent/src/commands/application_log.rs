//! `instances.read-application-log`: the newest lines of an Instance's Laravel log. The caller never names a file:
//! the command reads `storage/logs/laravel.log`, or else the newest `laravel-*.log` beside it, and only a regular file
//! that sits directly in a real `storage/logs`, so a link in the checkout cannot point the read elsewhere.

use super::source::{is_real_directory, is_regular_file};
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    fs,
    io::{Read, Seek, SeekFrom},
    path::{Path, PathBuf},
    time::Duration,
};

command_errors! {
    pub enum LogError {
        Unreadable {} => "instances.log_unreadable", "The application log cannot be read.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LogInput {
    /// The application directory, which holds `storage/logs`.
    pub directory: PathBuf,
    pub lines: usize,
    /// The most bytes the answer holds, from the end of the log.
    pub max_bytes: u64,
}

#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Log {
    pub text: String,
}

/// The log to read: `laravel.log`, or the newest daily `laravel-*.log`, or none.
fn log_file(directory: &Path) -> Option<PathBuf> {
    let logs = directory.join("storage/logs");
    if !is_real_directory(&logs) {
        return None;
    }
    let main = logs.join("laravel.log");
    if is_regular_file(&main) {
        return Some(main);
    }
    fs::read_dir(&logs)
        .ok()?
        .flatten()
        .filter(|entry| {
            let name = entry.file_name().to_string_lossy().into_owned();
            name.starts_with("laravel-") && name.ends_with(".log") && is_regular_file(&entry.path())
        })
        .filter_map(|entry| Some((entry.metadata().ok()?.modified().ok()?, entry.path())))
        .max_by_key(|(modified, _)| *modified)
        .map(|(_, path)| path)
}

pub fn read(input: &LogInput) -> Result<Log, LogError> {
    let Some(path) = log_file(&input.directory) else {
        return Ok(Log {
            text: String::new(),
        });
    };
    let mut file = fs::File::open(&path).map_err(|_| LogError::Unreadable {})?;
    let size = file.metadata().map_err(|_| LogError::Unreadable {})?.len();
    file.seek(SeekFrom::Start(size.saturating_sub(input.max_bytes)))
        .map_err(|_| LogError::Unreadable {})?;
    let mut data = Vec::new();
    file.read_to_end(&mut data)
        .map_err(|_| LogError::Unreadable {})?;
    let text = String::from_utf8_lossy(&data);
    let body = text.strip_suffix('\n').unwrap_or(&text);
    let lines: Vec<&str> = body.split('\n').collect();
    let kept = &lines[lines.len().saturating_sub(input.lines)..];
    let mut text = kept.join("\n");
    if !text.is_empty() {
        text.push('\n');
    }
    Ok(Log { text })
}

pub struct ReadApplicationLog;

impl Command for ReadApplicationLog {
    const NAME: &'static str = "instances.read-application-log";
    const SUMMARY: &'static str = "Read the newest lines of an Instance's Laravel log.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = LogInput;
    type Output = Log;
    type Error = LogError;

    /// Root reads a production home that only its user may enter; no project code runs.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }

    fn run(input: LogInput, _: &Context) -> Result<Log, LogError> {
        read(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::symlink;

    fn sandbox() -> PathBuf {
        let path = fs::canonicalize(std::env::temp_dir())
            .unwrap()
            .join(format!(
                "orbit-application-log-{:016x}",
                rand::random::<u64>()
            ));
        fs::create_dir_all(path.join("storage/logs")).unwrap();
        path
    }

    fn input(directory: &Path, lines: usize, max_bytes: u64) -> LogInput {
        LogInput {
            directory: directory.into(),
            lines,
            max_bytes,
        }
    }

    #[test]
    fn reads_the_newest_lines_of_the_main_log_within_the_byte_bound() {
        let directory = sandbox();
        fs::write(
            directory.join("storage/logs/laravel.log"),
            "one\ntwo\nthree\n",
        )
        .unwrap();

        assert_eq!(
            read(&input(&directory, 2, 1024)),
            Ok(Log {
                text: "two\nthree\n".into()
            })
        );
        assert_eq!(
            read(&input(&directory, 10, 8)),
            Ok(Log {
                text: "o
three
"
                .into()
            }),
            "the Gateway drops the partial line"
        );
        fs::remove_dir_all(&directory).unwrap();
    }

    #[test]
    fn falls_back_to_the_newest_daily_log_and_never_follows_a_link() {
        let directory = sandbox();
        let logs = directory.join("storage/logs");
        fs::write(logs.join("laravel-2026-01-01.log"), "old\n").unwrap();
        std::thread::sleep(Duration::from_millis(20));
        fs::write(logs.join("laravel-2026-01-02.log"), "new\n").unwrap();
        symlink("/etc/hostname", logs.join("laravel.log")).unwrap();

        assert_eq!(
            read(&input(&directory, 5, 1024)),
            Ok(Log {
                text: "new\n".into()
            })
        );

        fs::remove_dir_all(&logs).unwrap();
        symlink("/var/log", &logs).unwrap();
        assert_eq!(
            read(&input(&directory, 5, 1024)),
            Ok(Log {
                text: String::new()
            })
        );
        fs::remove_dir_all(&directory).unwrap();
    }
}
