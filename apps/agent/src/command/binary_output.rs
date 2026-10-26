//! Bounded binary chunks followed by the ordinary command result. Requests and mismatch replies stay unchanged.
use std::{
    io::{self, Write},
    os::fd::RawFd,
    time::Instant,
};

pub const CHUNK_BYTES: usize = 65_536;

/// The consumer may have stopped reading in the middle of a frame. Bound the final write too.
pub fn finish(line: &str) -> i32 {
    let deadline = Instant::now() + std::time::Duration::from_secs(1);
    match DeadlineWriter::new(libc::STDOUT_FILENO, deadline)
        .and_then(|mut output| writeln!(output, "{line}"))
    {
        Ok(()) => 0,
        Err(_) => 74,
    }
}

pub struct Frames<W> {
    output: W,
    max: u64,
    bytes: u64,
}
impl<W: Write> Frames<W> {
    pub fn new(output: W, max: u64) -> Self {
        Self {
            output,
            max,
            bytes: 0,
        }
    }
    pub fn bytes(&self) -> u64 {
        self.bytes
    }
}
impl<W: Write> Write for Frames<W> {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if bytes.len() as u64 > self.max.saturating_sub(self.bytes) {
            return Err(io::Error::new(
                io::ErrorKind::FileTooLarge,
                "binary output exceeds its limit",
            ));
        }
        for chunk in bytes.chunks(CHUNK_BYTES) {
            writeln!(self.output, "{{\"stream_bytes\":{}}}", chunk.len())?;
            self.output.write_all(chunk)?;
            self.bytes += chunk.len() as u64;
        }
        self.output.flush()?;
        Ok(bytes.len())
    }
    fn flush(&mut self) -> io::Result<()> {
        self.output.flush()
    }
}

/// A slow consumer must not hold the command beyond its deadline. Owns no descriptor.
pub struct DeadlineWriter {
    fd: RawFd,
    flags: libc::c_int,
    deadline: Instant,
}
impl DeadlineWriter {
    pub fn new(fd: RawFd, deadline: Instant) -> io::Result<Self> {
        // SAFETY: fcntl operates on the borrowed descriptor; its owner outlives this guard.
        let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };
        if flags < 0 || unsafe { libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK) } < 0 {
            return Err(io::Error::last_os_error());
        }
        Ok(Self {
            fd,
            flags,
            deadline,
        })
    }
}
impl Write for DeadlineWriter {
    fn write(&mut self, bytes: &[u8]) -> io::Result<usize> {
        if bytes.is_empty() {
            return Ok(0);
        }
        loop {
            if Instant::now() >= self.deadline {
                return Err(io::Error::new(
                    io::ErrorKind::TimedOut,
                    "binary output deadline expired",
                ));
            }
            // SAFETY: bytes is valid for its length and fd is borrowed until Drop.
            let written = unsafe { libc::write(self.fd, bytes.as_ptr().cast(), bytes.len()) };
            if written >= 0 {
                return Ok(written as usize);
            }
            let error = io::Error::last_os_error();
            if error.kind() == io::ErrorKind::Interrupted {
                continue;
            }
            if error.kind() != io::ErrorKind::WouldBlock {
                return Err(error);
            }
            let mut poll = libc::pollfd {
                fd: self.fd,
                events: libc::POLLOUT,
                revents: 0,
            };
            let millis = self
                .deadline
                .saturating_duration_since(Instant::now())
                .as_millis()
                .min(100) as i32;
            // SAFETY: one initialized pollfd, with a bounded nonnegative wait.
            unsafe {
                libc::poll(&mut poll, 1, millis.max(1));
            }
        }
    }
    fn flush(&mut self) -> io::Result<()> {
        Ok(())
    }
}
impl Drop for DeadlineWriter {
    fn drop(&mut self) {
        // SAFETY: restore only the flags of the borrowed descriptor, without closing it.
        unsafe {
            libc::fcntl(self.fd, libc::F_SETFL, self.flags);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        fs::File,
        io::{BufRead, BufReader, Read},
        os::fd::{AsRawFd, FromRawFd},
        time::Duration,
    };

    #[test]
    fn bounds_chunks_and_preserves_arbitrary_bytes() {
        let bytes: Vec<_> = (0..CHUNK_BYTES * 2 + 19).map(|i| (i % 256) as u8).collect();
        let mut output = vec![];
        let mut frames = Frames::new(&mut output, bytes.len() as u64);
        frames.write_all(&bytes).unwrap();
        assert_eq!(frames.bytes(), bytes.len() as u64);
        assert_eq!(
            frames.write_all(b"extra").unwrap_err().kind(),
            io::ErrorKind::FileTooLarge
        );
        let mut input = BufReader::new(output.as_slice());
        let mut decoded = vec![];
        loop {
            let mut line = String::new();
            if input.read_line(&mut line).unwrap() == 0 {
                break;
            }
            let length = serde_json::from_str::<serde_json::Value>(&line).unwrap()["stream_bytes"]
                .as_u64()
                .unwrap();
            assert!((1..=CHUNK_BYTES as u64).contains(&length));
            input
                .by_ref()
                .take(length)
                .read_to_end(&mut decoded)
                .unwrap();
        }
        assert_eq!(decoded, bytes);
    }

    #[test]
    fn blocked_output_stops_at_deadline_and_restores_descriptor_flags() {
        let mut fds = [0; 2];
        // SAFETY: pipe initializes both descriptors, each then receives one File owner.
        assert_eq!(unsafe { libc::pipe(fds.as_mut_ptr()) }, 0);
        let _reader = unsafe { File::from_raw_fd(fds[0]) };
        let writer = unsafe { File::from_raw_fd(fds[1]) };
        let before = unsafe { libc::fcntl(writer.as_raw_fd(), libc::F_GETFL) };
        let start = Instant::now();
        {
            let mut output =
                DeadlineWriter::new(writer.as_raw_fd(), start + Duration::from_millis(50)).unwrap();
            assert_eq!(
                output
                    .write_all(&vec![0; 2 * 1024 * 1024])
                    .unwrap_err()
                    .kind(),
                io::ErrorKind::TimedOut
            );
        }
        assert!(start.elapsed() < Duration::from_secs(2));
        // Compare the status flags the writer sets; Darwin also reports a kernel flag once a descriptor was written.
        let status = libc::O_ACCMODE | libc::O_APPEND | libc::O_NONBLOCK;
        assert_eq!(
            unsafe { libc::fcntl(writer.as_raw_fd(), libc::F_GETFL) } & status,
            before & status
        );
    }
}
