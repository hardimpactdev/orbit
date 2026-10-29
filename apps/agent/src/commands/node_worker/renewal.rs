//! Short root service: only custody/renewal, never family execution.
use super::{issuer::Pair, *};
const BUNDLE: &str = "/var/lib/orbit-node-worker/credentials/pair.json";
fn existing(files: &Files) -> Result<Option<Pair>, Error> {
    if !files.path(BUNDLE).exists() {
        return Ok(None);
    }
    owned_file(files, BUNDLE, 0o600)?;
    let bytes = fs::read(files.path(BUNDLE)).map_err(|_| invalid())?;
    if bytes.len() > 16384 {
        return Err(invalid());
    }
    serde_json::from_slice(&bytes)
        .map(Some)
        .map_err(|_| invalid())
}
async fn fetch() -> Result<Pair, Error> {
    let config = crate::Config::load().map_err(|_| failed())?;
    if !config.swamp_worker {
        return Err(invalid());
    }
    let secret = crate::AgentSecret::load(crate::SECRET_PATH).map_err(|_| failed())?;
    let client = crate::gateway_client(config.gateway_address, &secret).map_err(|_| failed())?;
    let mut response = client
        .post(format!(
            "{}/api/v1/agent/swamp-worker",
            config.gateway_url.trim_end_matches('/')
        ))
        .timeout(Duration::from_secs(150))
        .send()
        .await
        .map_err(|_| failed())?;
    if !response.status().is_success() {
        return Err(failed());
    }
    let mut bytes = Vec::new();
    while let Some(chunk) = response.chunk().await.map_err(|_| failed())? {
        if bytes.len() + chunk.len() > 16384 {
            return Err(failed());
        }
        bytes.extend_from_slice(&chunk);
    }
    let pair: Pair = serde_json::from_slice(&bytes).map_err(|_| failed())?;
    pair.validate(time::OffsetDateTime::now_utc().unix_timestamp())?;
    Ok(pair)
}
fn activate(files: &Files, host: &mut impl Host, pair: &Pair) -> Result<(), Error> {
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
    call(host, &["systemctl", "reset-failed", UNIT])?;
    call(host, &["systemctl", "start", UNIT])?;
    Ok(())
}
pub(super) fn rotate(
    files: &Files,
    host: &mut impl Host,
    clock: impl Fn() -> i64,
    obtain: impl FnOnce() -> Result<Pair, Error>,
) -> Result<(), Error> {
    let now = clock();
    let pair = existing(files)?;
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
    let fresh = pair
        .as_ref()
        .is_some_and(|p| p.expires().is_ok_and(|expiry| expiry - now > 7200));
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
        "active" | "activating" if fresh && !crash_loop => return Ok(()),
        "active" | "activating" => {
            // Native Swamp drains on its first SIGTERM. Never force-kill a step.
            call(host, &["systemctl", "stop", "--no-block", UNIT])?;
            if crash_loop {
                fs::remove_file(files.path(BUNDLE)).map_err(|_| failed())?;
            }
            return Ok(());
        }
        "deactivating" => return Ok(()),
        "inactive" | "failed" => (),
        _ => return Err(failed()),
    }
    let pair = if fresh && state.trim() == "inactive" {
        pair.ok_or_else(invalid)?
    } else {
        let next = obtain()?;
        next.validate(clock())?;
        files
            .publish(
                &files.path(BUNDLE),
                &serde_json::to_vec(&next).map_err(|_| failed())?,
                0o600,
                files.uid,
                files.gid,
                false,
            )
            .map_err(|_| invalid())?;
        next
    };
    activate(files, host, &pair)
}
pub struct Service;
impl orbit_agent_contract::Service for Service {
    const NAME: &'static str = "swamp-node-worker-renew";
    fn supported(platform: Platform) -> bool {
        platform == Platform::Linux
    }
    fn run(args: &[String]) -> i32 {
        if !args.is_empty() || rustix::process::geteuid().as_raw() != 0 {
            return 1;
        }
        let context = Context::new(
            Platform::Linux,
            std::time::Instant::now() + Duration::from_secs(180),
        );
        let Ok(_lock) = lifecycle_lock(&context) else {
            return 1;
        };
        let files = Files::system();
        if !pinned(&files) {
            return 1;
        }
        let result = rotate(
            &files,
            &mut recipe::Native(&context),
            || time::OffsetDateTime::now_utc().unix_timestamp(),
            || {
                tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .map_err(|_| failed())?
                    .block_on(fetch())
            },
        );
        i32::from(result.is_err())
    }
}
