use std::io::{ErrorKind, Read};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use tokio::sync::Notify;

pub const DESKTOP_LIFETIME_ENV: &str = "ORBIT_DESKTOP_LIFETIME";

pub struct LifetimeShutdown {
    requested: AtomicBool,
    notify: Notify,
}

impl Default for LifetimeShutdown {
    fn default() -> Self {
        Self::new()
    }
}

impl LifetimeShutdown {
    pub fn new() -> Self {
        Self {
            requested: AtomicBool::new(false),
            notify: Notify::new(),
        }
    }

    pub fn request(&self) {
        self.requested.store(true, Ordering::SeqCst);
        self.notify.notify_waiters();
    }

    pub fn is_requested(&self) -> bool {
        self.requested.load(Ordering::SeqCst)
    }

    pub async fn cancelled(&self) {
        loop {
            let notified = self.notify.notified();

            if self.is_requested() {
                return;
            }

            notified.await;
        }
    }
}

pub fn desktop_lifetime_enabled() -> bool {
    desktop_lifetime_enabled_from(std::env::var(DESKTOP_LIFETIME_ENV).ok().as_deref())
}

pub fn desktop_lifetime_enabled_from(value: Option<&str>) -> bool {
    matches!(value.map(str::trim), Some("1"))
}

pub fn watch_reader_until_eof<R: Read>(mut reader: R) {
    let mut buffer = [0_u8; 64];

    loop {
        match reader.read(&mut buffer) {
            Ok(0) => return,
            Ok(_) => {}
            Err(error) if error.kind() == ErrorKind::Interrupted => {}
            Err(_) => return,
        }
    }
}

pub fn spawn_reader_lifetime_watch<R>(reader: R, shutdown: Arc<LifetimeShutdown>)
where
    R: Read + Send + 'static,
{
    tokio::spawn(async move {
        let _ = tokio::task::spawn_blocking(move || {
            watch_reader_until_eof(reader);
        })
        .await;
        shutdown.request();
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{self, Cursor};

    struct FailingReader;

    impl Read for FailingReader {
        fn read(&mut self, _: &mut [u8]) -> io::Result<usize> {
            Err(io::Error::other("lifetime channel closed"))
        }
    }

    #[test]
    fn desktop_lifetime_requires_explicit_marker() {
        assert!(!desktop_lifetime_enabled_from(None));
        assert!(!desktop_lifetime_enabled_from(Some("")));
        assert!(!desktop_lifetime_enabled_from(Some("0")));
        assert!(!desktop_lifetime_enabled_from(Some("true")));
        assert!(!desktop_lifetime_enabled_from(Some("TRUE")));
        assert!(!desktop_lifetime_enabled_from(Some("yes")));
        assert!(desktop_lifetime_enabled_from(Some("1")));
        assert!(desktop_lifetime_enabled_from(Some(" 1 ")));
    }

    #[test]
    fn watch_reader_returns_when_stdin_reaches_eof() {
        watch_reader_until_eof(Cursor::new(b"lifetime-payload"));
    }

    #[test]
    fn watch_reader_returns_on_empty_stdin() {
        watch_reader_until_eof(Cursor::new(b""));
    }

    #[test]
    fn watch_reader_returns_on_non_interrupt_error() {
        watch_reader_until_eof(FailingReader);
    }

    #[tokio::test]
    async fn cancelled_returns_when_request_happens_before_wait() {
        let shutdown = LifetimeShutdown::new();
        shutdown.request();
        shutdown.cancelled().await;
        assert!(shutdown.is_requested());
    }

    #[tokio::test]
    async fn cancelled_unblocks_after_request() {
        let shutdown = Arc::new(LifetimeShutdown::new());
        let waiter = shutdown.clone();
        let wait = tokio::spawn(async move {
            waiter.cancelled().await;
        });

        shutdown.request();
        wait.await.expect("shutdown waiter should finish");
        assert!(shutdown.is_requested());
    }

    #[tokio::test]
    async fn eof_on_lifetime_reader_requests_shutdown() {
        let shutdown = Arc::new(LifetimeShutdown::new());
        spawn_reader_lifetime_watch(Cursor::new(b""), shutdown.clone());
        shutdown.cancelled().await;
        assert!(shutdown.is_requested());
    }

    #[tokio::test]
    async fn reader_error_fails_closed_and_requests_shutdown() {
        let shutdown = Arc::new(LifetimeShutdown::new());
        spawn_reader_lifetime_watch(FailingReader, shutdown.clone());
        shutdown.cancelled().await;
        assert!(shutdown.is_requested());
    }
}
