//! Bounded input is private data, never source, diagnostics, or a caller-selected file.
use super::Outcome;
use serde_json::json;
use std::{
    fs::{self, File, OpenOptions},
    io::{self, Read, Seek},
    os::unix::fs::OpenOptionsExt,
};

pub type Stream = Box<dyn Read + Send>;

fn refused() -> Outcome {
    Outcome::refused(
        "agent.binary_input_invalid",
        "The binary input is incomplete, exceeds its declared size, or could not be read.",
        json!({}),
    )
}

pub fn receive(mut stream: Stream, bytes: Option<u64>) -> Result<Option<File>, Outcome> {
    let Some(bytes) = bytes else {
        let mut extra = [0];
        return match stream.read(&mut extra) {
            Ok(0) => Ok(None),
            _ => Err(refused()),
        };
    };
    let receive = || -> io::Result<File> {
        let directory = crate::commands::recipe::WorkDir::new()
            .map_err(|_| io::Error::other("private input file"))?;
        let path = directory.0.join("input");
        let mut file = OpenOptions::new()
            .read(true)
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&path)?;
        fs::remove_file(path)?;
        let count = io::copy(&mut stream.take(bytes.saturating_add(1)), &mut file)?;
        if count != bytes {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                "binary input size",
            ));
        }
        file.rewind()?;
        Ok(file)
    };
    receive().map(Some).map_err(|_| refused())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Cursor;
    #[test]
    fn preserves_every_byte_and_unlinks_the_private_file() {
        let bytes: Vec<_> = (0..256).map(|v| v as u8).cycle().take(200_000).collect();
        let mut file = receive(
            Box::new(Cursor::new(bytes.clone())),
            Some(bytes.len() as u64),
        )
        .unwrap()
        .unwrap();
        let mut actual = vec![];
        file.read_to_end(&mut actual).unwrap();
        assert_eq!(actual, bytes);
        use std::os::unix::fs::MetadataExt;
        assert_eq!(file.metadata().unwrap().nlink(), 0);
        assert_eq!(file.metadata().unwrap().mode() & 0o777, 0o600);
    }
    #[test]
    fn refuses_short_extra_or_unexpected_bytes() {
        for (data, size) in [(vec![0], Some(2)), (vec![0, 1], Some(1)), (vec![0], None)] {
            assert!(
                matches!(receive(Box::new(Cursor::new(data)), size), Err(Outcome::Refused { code, .. }) if code == "agent.binary_input_invalid")
            );
        }
        assert!(receive(Box::new(io::empty()), None).unwrap().is_none());
        assert_eq!(
            receive(Box::new(io::empty()), Some(0))
                .unwrap()
                .unwrap()
                .metadata()
                .unwrap()
                .len(),
            0
        );
    }
    #[test]
    fn refuses_read_errors_without_returning_binary_diagnostics() {
        struct Broken;
        impl Read for Broken {
            fn read(&mut self, _: &mut [u8]) -> io::Result<usize> {
                Err(io::Error::other("private input"))
            }
        }
        let result = receive(Box::new(Broken), Some(10))
            .err()
            .unwrap()
            .to_json()
            .to_string();
        assert!(result.contains("agent.binary_input_invalid"));
        assert!(!result.contains("private input"));
    }
}
