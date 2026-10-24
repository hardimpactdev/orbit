//! Fixed systemd entry point for one persisted image phase, independent of the SSH command lifetime.
use super::{
    lifecycle::{self, Phase, Start, PHASE_SECONDS},
    *,
};
use crate::command::{lock, run::file_sha256, Context, Platform};
use std::{io::Read, os::unix::fs::MetadataExt};

fn failure(error: impl ToString) -> Failure {
    Failure::new("service", error)
}
fn valid_unit(unit: &str) -> bool {
    unit.strip_prefix("orbit-recipe-image-")
        .and_then(|v| v.strip_suffix(".service"))
        .is_some_and(|v| recipe::types::valid_digest(v, 32))
}
fn load(files: &Files, unit: &str) -> Result<Start, Failure> {
    if !valid_unit(unit) {
        return Err(failure("Invalid image service unit"));
    }
    let path = files
        .path("/run/orbit-recipe-images")
        .join(format!("{unit}.json"));
    let meta = fs::symlink_metadata(&path).map_err(failure)?;
    if !meta.is_file() || meta.uid() != files.uid || meta.mode() & 0o077 != 0 {
        return Err(failure("Image phase input must be a private owned file"));
    }
    let mut bytes = vec![];
    fs::File::open(path)
        .map_err(failure)?
        .take(crate::command::MAX_ENVELOPE_BYTES + 1)
        .read_to_end(&mut bytes)
        .map_err(failure)?;
    if bytes.len() as u64 > crate::command::MAX_ENVELOPE_BYTES {
        return Err(failure("Image phase input exceeds its bound"));
    }
    let input: Start = serde_json::from_slice(&bytes).map_err(failure)?;
    input.validate().map_err(|e| failure(format!("{e:?}")))?;
    if input.key.unit() != unit {
        return Err(failure("Image service and input identities differ"));
    }
    lifecycle::boot(files, Some(&input.boot_id)).map_err(|e| failure(format!("{e:?}")))?;
    Ok(input)
}
pub fn agent(files: &Files, input: &Input, deadline: Instant) -> Result<(), Failure> {
    let _lock = lock::acquire(
        &files.path(lock::UPDATE_LOCK_LINUX),
        deadline.min(Instant::now() + Duration::from_secs(120)),
    )
    .map_err(|e| Failure::new("agent", format!("{e:?}")))?;
    let observed = file_sha256(&files.path("/usr/local/bin/orbit-agent"))
        .map_err(|e| Failure::new("agent", e))?;
    if observed != input.agent_sha256 {
        return Err(Failure::new(
            "agent",
            "The installed agent differs from the Gateway delivery pin",
        ));
    }
    Ok(())
}
pub fn execute(
    host: &mut impl php_build::Host,
    files: &Files,
    input: &Start,
    account: &Account,
    deadline: Instant,
) -> Result<(), Failure> {
    input.validate().map_err(|e| failure(format!("{e:?}")))?;
    assessment::platform(host, files, &input.recipe.recipe)?;
    match input.key.phase {
        Phase::AptSource => apt_source(host, files, &input.recipe),
        Phase::Packages => packages::install(host, files, &input.recipe, deadline),
        Phase::Javascript => javascript(host, files, &input.recipe, account, deadline),
        Phase::Rust => rust::install(host, files, &input.recipe, account),
        Phase::Agent => agent(files, &input.recipe, deadline),
        Phase::Pi => {
            if let Some(sha256) = &input.recipe.pi_sha256 {
                super::super::pi_artifact::install(files, account, sha256)
                    .map_err(|e| Failure::new("pi", format!("{e:?}")))?;
            }
            Ok(())
        }
    }
}
fn run(unit: &str) -> Result<(), (i32, Failure)> {
    let fail = |e| (1, failure(e));
    if !cfg!(target_os = "linux") || rustix::process::geteuid().as_raw() != 0 {
        return Err(fail("Image phase services require Linux root".to_string()));
    }
    if !valid_unit(unit) {
        return Err(fail("Invalid image service unit".into()));
    }
    let cgroup = fs::read_to_string("/proc/self/cgroup").map_err(|e| fail(e.to_string()))?;
    if !cgroup
        .lines()
        .any(|line| line.ends_with(&format!("/{unit}")))
    {
        return Err(fail(
            "Image phase service must run in its own systemd unit".into(),
        ));
    }
    let files = Files::system();
    let input = load(&files, unit).map_err(|e| (1, e))?;
    if file_sha256(Path::new("/proc/self/exe")).map_err(|e| fail(e.to_string()))?
        != input.recipe.agent_sha256
    {
        return Err(fail(
            "The service binary differs from the requested delivery pin".into(),
        ));
    }
    let _signals = recipe::interruption::Guard::install().map_err(|e| (1, e))?;
    let deadline = Instant::now() + Duration::from_secs(PHASE_SECONDS - 10);
    let context = Context::new(Platform::Linux, deadline);
    let _guest =
        lock::acquire(Path::new("/run/orbit-recipe-image.lock"), Instant::now()).map_err(|e| {
            (
                if matches!(e, lock::LockError::Busy(_)) {
                    75
                } else {
                    1
                },
                failure(format!("{e:?}")),
            )
        })?;
    let directory =
        lock::prepare(Path::new(lock::LOCK_ROOT_LINUX), 0, 0).map_err(|e| fail(e.to_string()))?;
    let _resources = lock::acquire_all(
        &directory,
        "machine-image-recipe",
        &["packages", "pi-runtime"],
        deadline,
    )
    .map_err(|e| fail(format!("{e:?}")))?;
    let account = Account::named("orbit").map_err(|e| (1, e))?;
    execute(
        &mut recipe::Native(&context),
        &files,
        &input,
        &account,
        deadline,
    )
    .map_err(|e| (1, e))
}
pub fn service(arguments: &[String]) -> i32 {
    let [unit] = arguments else {
        eprintln!("orbit-agent: image-phase requires one service identity");
        return 64;
    };
    match run(unit) {
        Ok(()) => {
            println!("{{\"ok\":true}}");
            0
        }
        Err((code, error)) => {
            eprintln!("{}", serde_json::to_string(&error).unwrap());
            code
        }
    }
}
#[cfg(test)]
mod tests;
