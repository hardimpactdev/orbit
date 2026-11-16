//! The append-only journal: one record before an action and one after it.
//!
//! No record is synced on the order's path. A background thread syncs the file once a second, because the
//! page cache survives an executor crash and a Node reboot expires every order (ADR 0266).

use std::fs::{self, File, OpenOptions};
use std::io::{self, BufRead as _, BufReader, Write as _};
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

use serde::{Deserialize, Serialize};

use crate::action::Action;
use crate::ids::{Nonce, OperationId, TargetId};

/// How often the background thread syncs the journal.
pub const SYNC_INTERVAL: Duration = Duration::from_secs(1);

/// How long a done record is kept, in milliseconds.
pub const DONE_RETENTION_MS: u64 = 24 * 60 * 60 * 1000;

/// The key of a done record: a repeat returns the first result only when all three are equal (ADR 0267).
#[derive(Debug, Clone, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub struct DoneKey {
    /// The Operation.
    pub operation: OperationId,
    /// The action.
    pub action: Action,
    /// The target.
    pub target: TargetId,
}

/// How an action ended.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "status", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Outcome {
    /// The action finished.
    Done,
    /// The action failed with a stable code.
    Failed {
        /// The failure's code.
        code: String,
    },
}

/// One journal line.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(tag = "record", rename_all = "snake_case")]
#[non_exhaustive]
pub enum Record {
    /// Written before the action runs.
    Open {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// The order's expiry, in Unix milliseconds.
        expires_ms: u64,
    },
    /// Written for a nonce whose order did not run, because its key already had a result.
    Seen {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// The order's expiry, in Unix milliseconds.
        expires_ms: u64,
    },
    /// Written after the action ran.
    Done {
        /// The order's nonce.
        nonce: Nonce,
        /// The done record's key.
        #[serde(flatten)]
        key: DoneKey,
        /// How the action ended.
        outcome: Outcome,
        /// When it ended, in Unix milliseconds.
        at_ms: u64,
    },
}

/// The journal file. Each record is one `write` with `O_APPEND`, so threads append without a lock.
#[derive(Debug, Clone)]
pub struct Journal {
    file: Arc<File>,
    path: PathBuf,
}

/// Why the journal cannot be read or written.
#[derive(Debug, thiserror::Error)]
#[non_exhaustive]
pub enum JournalError {
    /// A file operation failed.
    #[error("journal {action} {path}")]
    Io {
        /// What was being done.
        action: &'static str,
        /// The file.
        path: PathBuf,
        /// The cause.
        #[source]
        source: io::Error,
    },
    /// A record could not be encoded.
    #[error("journal record does not encode")]
    Encode(#[source] serde_json::Error),
}

fn io_error<'path>(
    action: &'static str,
    path: &'path Path,
) -> impl FnOnce(io::Error) -> JournalError + 'path {
    move |source| JournalError::Io {
        action,
        path: path.to_owned(),
        source,
    }
}

impl Journal {
    /// Reads every complete record of the journal at `path`. A missing file holds no record. A torn or
    /// unreadable line, such as the last line of a crash, is skipped.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError::Io`] when the file exists but cannot be read.
    pub fn read(path: &Path) -> Result<Vec<Record>, JournalError> {
        let file = match File::open(path) {
            Ok(file) => file,
            Err(error) if error.kind() == io::ErrorKind::NotFound => return Ok(Vec::new()),
            Err(error) => return Err(io_error("open", path)(error)),
        };
        let mut records = Vec::new();
        for line in BufReader::new(file).split(b'\n') {
            let line = line.map_err(io_error("read", path))?;
            if let Ok(record) = serde_json::from_slice(&line) {
                records.push(record);
            }
        }
        Ok(records)
    }

    /// Replaces the journal at `path` with `records`, synced, then opens it for appending.
    /// The executor calls this once at start-up to compact the journal.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the new file cannot be written, synced or renamed into place.
    pub fn rewrite(path: &Path, records: &[Record]) -> Result<Self, JournalError> {
        let temporary = path.with_extension("compact");
        let mut text = Vec::new();
        for record in records {
            serde_json::to_writer(&mut text, record).map_err(JournalError::Encode)?;
            text.push(b'\n');
        }
        let mut file = File::create(&temporary).map_err(io_error("create", &temporary))?;
        file.write_all(&text)
            .map_err(io_error("write", &temporary))?;
        file.sync_all().map_err(io_error("sync", &temporary))?;
        fs::rename(&temporary, path).map_err(io_error("rename", path))?;
        if let Some(parent) = path.parent() {
            File::open(parent)
                .and_then(|dir| dir.sync_all())
                .map_err(io_error("sync", parent))?;
        }
        let file = OpenOptions::new()
            .append(true)
            .open(path)
            .map_err(io_error("open", path))?;
        Ok(Self {
            file: Arc::new(file),
            path: path.to_owned(),
        })
    }

    /// Appends one record with a single `write`. It does not sync. Each record starts and ends with a newline, so a
    /// fragment that a failed earlier write left behind never joins it on one unreadable line.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError`] when the record cannot be encoded or written.
    pub fn append(&self, record: &Record) -> Result<(), JournalError> {
        let mut line = vec![b'\n'];
        serde_json::to_writer(&mut line, record).map_err(JournalError::Encode)?;
        line.push(b'\n');
        (&*self.file)
            .write_all(&line)
            .map_err(io_error("append", &self.path))
    }

    /// Starts the thread that syncs the journal once a second for the life of the process.
    ///
    /// # Errors
    ///
    /// Returns [`JournalError::Io`] when the thread cannot be started.
    pub fn spawn_syncer(&self, on_error: fn(&io::Error)) -> Result<(), JournalError> {
        let file = Arc::clone(&self.file);
        thread::Builder::new()
            .name("journal-sync".to_owned())
            .spawn(move || loop {
                thread::sleep(SYNC_INTERVAL);
                if let Err(error) = file.sync_data() {
                    on_error(&error);
                }
            })
            .map(drop)
            .map_err(io_error("start the sync thread for", &self.path))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn key() -> DoneKey {
        DoneKey {
            operation: "op-1".parse().expect("id"),
            action: Action::ProofNoop,
            target: "7".parse().expect("id"),
        }
    }

    #[test]
    fn records_round_trip_and_a_torn_line_is_skipped() {
        let dir = tempfile::tempdir().expect("tempdir");
        let path = dir.path().join("journal");
        let nonce: Nonce = "00112233445566778899aabbccddeeff".parse().expect("nonce");
        let open = Record::Open {
            nonce,
            key: key(),
            expires_ms: 5,
        };
        let journal = Journal::rewrite(&path, &[]).expect("create");
        journal.append(&open).expect("append");
        (&*journal.file)
            .write_all(b"{\"record\":\"done\",\"non")
            .expect("torn write");
        assert_eq!(Journal::read(&path).expect("read"), vec![open]);
    }

    #[test]
    fn a_missing_journal_holds_no_record() {
        let dir = tempfile::tempdir().expect("tempdir");
        assert_eq!(
            Journal::read(&dir.path().join("none")).expect("read"),
            vec![]
        );
    }
}
