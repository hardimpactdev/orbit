//! The one relay command: `orbit-executor relay`, run as `orbit-executor-relay` by the Gateway's SSH
//! fallback and by Swamp. It copies one signed order from stdin to the socket and the reply to stdout.

use std::io::{self, Read, Write};
use std::net::Shutdown;
use std::os::unix::net::UnixStream;
use std::path::Path;
use std::time::Duration;

use crate::order::MAX_ORDER_BYTES;

/// The largest reply the relay copies, in bytes.
pub const MAX_REPLY_BYTES: usize = 64 * 1024;

/// How long the relay waits for the executor's reply, including capture and a possible clean stop.
pub const REPLY_TIMEOUT: Duration = Duration::from_secs(120);

/// Why the relay could not hand over an order.
#[derive(Debug, thiserror::Error)]
#[non_exhaustive]
pub enum RelayError {
    /// The order on stdin is larger than 4 KB.
    #[error("order is larger than {MAX_ORDER_BYTES} bytes")]
    OrderTooLarge,
    /// Reading, connecting, writing or copying failed.
    #[error("relay i/o")]
    Io(#[from] io::Error),
}

/// Copies one order from `input` to the socket at `socket` and the reply to `output`.
///
/// # Errors
///
/// Returns [`RelayError::OrderTooLarge`] for an order over 4 KB, and [`RelayError::Io`] when the socket
/// refuses the connection or a copy fails.
///
/// # Panics
///
/// Never in practice: it converts the two fixed size bounds to `u64`, which always fits.
pub fn relay(socket: &Path, input: impl Read, mut output: impl Write) -> Result<(), RelayError> {
    let limit = u64::try_from(MAX_ORDER_BYTES + 1).expect("the order bound fits u64");
    let mut order = Vec::new();
    input.take(limit).read_to_end(&mut order)?;
    if order.len() > MAX_ORDER_BYTES {
        return Err(RelayError::OrderTooLarge);
    }
    let mut stream = UnixStream::connect(socket)?;
    stream.set_read_timeout(Some(REPLY_TIMEOUT))?;
    stream.write_all(order.trim_ascii_end())?;
    stream.write_all(b"\n")?;
    stream.shutdown(Shutdown::Write)?;
    let reply_limit = u64::try_from(MAX_REPLY_BYTES).expect("the reply bound fits u64");
    io::copy(&mut stream.take(reply_limit), &mut output)?;
    output.flush()?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn relay_can_wait_for_capture_and_a_clean_stop_in_one_sleep() {
        let reader_drain = Duration::from_secs(10);
        let capture = Duration::from_secs(30);
        let stop_wait = Duration::from_secs(40);
        assert!(REPLY_TIMEOUT > reader_drain + capture + stop_wait);
    }
}
