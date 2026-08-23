use std::io::{ErrorKind, Read};

pub const DESKTOP_LIFETIME_ENV: &str = "ORBIT_DESKTOP_LIFETIME";

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

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Cursor;

    #[test]
    fn desktop_lifetime_requires_explicit_marker() {
        assert!(!desktop_lifetime_enabled_from(None));
        assert!(!desktop_lifetime_enabled_from(Some("")));
        assert!(!desktop_lifetime_enabled_from(Some("true")));
        assert!(desktop_lifetime_enabled_from(Some("1")));
        assert!(desktop_lifetime_enabled_from(Some(" 1 ")));
    }

    #[test]
    fn watch_reader_returns_when_stdin_reaches_eof() {
        let reader = Cursor::new(b"lifetime-payload");

        watch_reader_until_eof(reader);
    }

    #[test]
    fn watch_reader_returns_on_empty_stdin() {
        watch_reader_until_eof(Cursor::new(b""));
    }
}
