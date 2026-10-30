//! Native start-worker handles the Node profile. Gateway retries drains in minutes.
use super::*;
fn status(started: bool, drained: bool) -> worker::Output {
    worker::Output {
        started,
        drained: Some(drained),
    }
}
pub(crate) fn converge(
    files: &Files,
    host: &mut impl Host,
    input: &worker::Input,
    now: i64,
) -> Result<worker::Output, Error> {
    let present = files.path(worker::UNIT_PATH).exists();
    if present {
        owned_file(files, worker::UNIT_PATH, 0o644)?;
        if !fs::read_to_string(files.path(worker::UNIT_PATH))
            .map_err(|_| invalid())?
            .contains("User=orbit-swamp-worker\n")
        {
            return Err(invalid());
        }
    }
    let state = if present {
        call(
            host,
            &[
                "systemctl",
                "show",
                worker::UNIT,
                "--property=ActiveState",
                "--value",
            ],
        )?
    } else {
        "inactive".into()
    };
    let drained = matches!(state.trim(), "inactive" | "failed");
    if input.enrollment_token.is_some() {
        if !drained {
            return Err(invalid());
        }
        worker::validate(input).map_err(|_| invalid())?;
        let expiry = input.expires_at.as_deref().ok_or_else(invalid)?;
        let remaining =
            time::OffsetDateTime::parse(expiry, &time::format_description::well_known::Rfc3339)
                .map_err(|_| invalid())?
                .unix_timestamp()
                - now;
        if !(71700..=86700).contains(&remaining) {
            return Err(invalid());
        }
        worker::stop(files, host).map_err(|_| failed())?;
        let account = Account::named(USER).map_err(|_| invalid())?;
        if !worker::start(files, host, &account, input)
            .map_err(|_| failed())?
            .started
        {
            return Err(invalid());
        }
        files
            .publish(
                &files.path(EXPIRY),
                expiry.as_bytes(),
                0o600,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| failed())?;
        return Ok(status(true, false));
    }
    let fresh = if files.path(EXPIRY).exists() {
        owned_file(files, EXPIRY, 0o600)?;
        let expiry = fs::read_to_string(files.path(EXPIRY)).map_err(|_| invalid())?;
        time::OffsetDateTime::parse(&expiry, &time::format_description::well_known::Rfc3339)
            .map_err(|_| invalid())?
            .unix_timestamp()
            - now
            > 4 * 3600
    } else {
        false
    };
    match state.trim() {
        "active" | "activating" if fresh => Ok(status(true, false)),
        "inactive" if present && fresh => {
            call(host, &["systemctl", "start", worker::UNIT])?;
            Ok(status(true, false))
        }
        "inactive" | "failed" => {
            worker::stop(files, host).map_err(|_| failed())?;
            Ok(status(false, true))
        }
        "active" | "activating" => {
            call(host, &["systemctl", "stop", "--no-block", worker::UNIT])?;
            Ok(status(false, false))
        }
        "deactivating" => Ok(status(false, false)),
        _ => Err(failed()),
    }
}
