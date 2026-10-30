//! Gateway-driven lifecycle. No Node timer, issuer credential or HTTP callback.
use super::{issuer::Pair, *};
const EXPIRY: &str = "/var/lib/orbit-node-worker/credentials/expiry";

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    credentials: Option<Pair>,
}
#[derive(schemars::JsonSchema, Serialize, Debug)]
pub struct Status {
    pub(super) ready: bool,
    pub(super) drained: bool,
}
fn start(files: &Files, host: &mut impl Host) -> Result<Status, Error> {
    for name in ["enrollment", "server"] {
        owned_file(files, &format!("{ROOT}/credentials/{name}"), 0o600)?;
    }
    call(host, &["systemctl", "reset-failed", UNIT])?;
    call(host, &["systemctl", "enable", "--now", UNIT])?;
    Ok(Status {
        ready: true,
        drained: false,
    })
}
pub(super) fn converge(
    files: &Files,
    host: &mut impl Host,
    pair: Option<Pair>,
    now: i64,
) -> Result<Status, Error> {
    owned_file(files, &format!("/etc/systemd/system/{UNIT}"), 0o644)?;
    let state = call(
        host,
        &[
            "systemctl",
            "show",
            UNIT,
            "--property=ActiveState",
            "--value",
        ],
    )?;
    let drained = matches!(state.trim(), "inactive" | "failed");
    if let Some(pair) = pair {
        if !drained {
            return Err(invalid());
        }
        pair.validate(now)?;
        // The expiry is the commit marker. A killed publication cannot start a mixed pair.
        if files.path(EXPIRY).exists() {
            owned_file(files, EXPIRY, 0o600)?;
            fs::remove_file(files.path(EXPIRY)).map_err(|_| failed())?;
        }
        for (name, value) in [
            ("enrollment", &pair.enrollment_token),
            ("server", &pair.server_token),
        ] {
            files
                .publish(
                    &files.path(&format!("{ROOT}/credentials/{name}")),
                    value.as_bytes(),
                    0o600,
                    files.uid,
                    files.gid,
                    false,
                )
                .map_err(|_| invalid())?;
        }
        files
            .publish(
                &files.path(EXPIRY),
                pair.expires()?.to_string().as_bytes(),
                0o600,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| invalid())?;
        return start(files, host);
    }
    let fresh = if files.path(EXPIRY).exists() {
        owned_file(files, EXPIRY, 0o600)?;
        let expiry: i64 = fs::read_to_string(files.path(EXPIRY))
            .map_err(|_| invalid())?
            .trim()
            .parse()
            .map_err(|_| invalid())?;
        expiry - now > 4 * 3600
    } else {
        false
    };
    let crash_loop = fresh
        && matches!(state.trim(), "active" | "activating")
        && call(
            host,
            &["systemctl", "show", UNIT, "--property=NRestarts", "--value"],
        )?
        .trim()
        .parse::<u64>()
        .map_err(|_| failed())?
            >= 3;
    match state.trim() {
        "active" | "activating" if fresh && !crash_loop => Ok(Status {
            ready: true,
            drained: false,
        }),
        "inactive" if fresh => start(files, host),
        "inactive" | "failed" => {
            call(host, &["systemctl", "disable", UNIT])?;
            if files.path(EXPIRY).exists() {
                fs::remove_file(files.path(EXPIRY)).map_err(|_| failed())?;
            }
            Ok(Status {
                ready: false,
                drained: true,
            })
        }
        "active" | "activating" => {
            call(host, &["systemctl", "disable", "--now", "--no-block", UNIT])?;
            if crash_loop {
                fs::remove_file(files.path(EXPIRY)).map_err(|_| failed())?;
            }
            // Replace idle workers in this pass. Long-running steps keep draining.
            for attempt in 0..=10 {
                let state = call(
                    host,
                    &[
                        "systemctl",
                        "show",
                        UNIT,
                        "--property=ActiveState",
                        "--value",
                    ],
                )?;
                if matches!(state.trim(), "inactive" | "failed") {
                    if files.path(EXPIRY).exists() {
                        fs::remove_file(files.path(EXPIRY)).map_err(|_| failed())?;
                    }
                    return Ok(Status {
                        ready: false,
                        drained: true,
                    });
                }
                if attempt < 10 {
                    host.wait(Duration::from_secs(1)).map_err(|_| failed())?;
                }
            }
            Ok(Status {
                ready: false,
                drained: false,
            })
        }
        "deactivating" => Ok(Status {
            ready: false,
            drained: false,
        }),
        _ => Err(failed()),
    }
}
pub struct Converge;
impl Command for Converge {
    const NAME: &'static str = "swamp.converge-node-worker";
    const SUMMARY: &'static str =
        "Drain a due Node worker or start it with operator-issued credentials.";
    const MUTATES: bool = true;
    const KEYED_EFFECT: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(180);
    type Input = Input;
    type Output = Status;
    type Error = Error;
    fn privilege(p: Platform) -> Option<Privilege> {
        super::super::linux_only(p, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Status, Error> {
        let _lock = lifecycle_lock(context)?;
        let files = Files::system();
        if !pinned(&files) {
            return Err(invalid());
        }
        converge(
            &files,
            &mut recipe::Native(context),
            input.credentials,
            time::OffsetDateTime::now_utc().unix_timestamp(),
        )
    }
}
