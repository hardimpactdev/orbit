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
async fn fetch(node: u64) -> Result<Pair, Error> {
    let config = crate::Config::load().map_err(|_| failed())?;
    if config.swamp_worker_node_id != Some(node) {
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
    pair.validate(node, time::OffsetDateTime::now_utc().unix_timestamp())?;
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
    obtain: impl FnOnce(u64) -> Result<Pair, Error>,
) -> Result<(), Error> {
    let now = clock();
    owned_file(files, &format!("{ROOT}/node-id"), 0o600)?;
    let node: u64 = fs::read_to_string(files.path(&format!("{ROOT}/node-id")))
        .map_err(|_| invalid())?
        .trim()
        .parse()
        .map_err(|_| invalid())?;
    if node == 0 {
        return Err(invalid());
    }
    let pair = existing(files)?;
    if pair.as_ref().is_some_and(|p| p.node_id != node) {
        return Err(invalid());
    }
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
    match state.trim() {
        "active" | "activating" if fresh => return Ok(()),
        "active" | "activating" => {
            // Native Swamp drains on its first SIGTERM. Never force-kill a step.
            call(host, &["systemctl", "stop", "--no-block", UNIT])?;
            return Ok(());
        }
        "deactivating" => return Ok(()),
        "inactive" | "failed" => (),
        _ => return Err(failed()),
    }
    let pair = if fresh {
        pair.ok_or_else(invalid)?
    } else {
        let next = obtain(node)?;
        next.validate(node, clock())?;
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
            |node| {
                tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                    .map_err(|_| failed())?
                    .block_on(fetch(node))
            },
        );
        i32::from(result.is_err())
    }
}
