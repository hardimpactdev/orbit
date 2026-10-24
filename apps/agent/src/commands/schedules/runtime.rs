//! Fixed systemd entry points. Project execution and root completion never share a code path.
use super::{failed, identity, owned, spec, Host, Process, ScheduleError};
use std::{fs, os::unix::process::CommandExt, time::Duration};

pub fn service(arguments: &[String]) -> i32 {
    let [name, id] = arguments else {
        return 64;
    };
    if !cfg!(target_os = "linux") || !spec::valid_id(id) {
        return 64;
    }
    match name.as_str() {
        "schedules-run" => match project_command(&Host::system(), id) {
            Ok(mut command) => {
                let _ = command.exec();
                1
            }
            Err(_) => 1,
        },
        "schedules-complete" if identity::effective_uid() == 0 => {
            // A lost report must not change the run result. No project file or agent secret is read here.
            if let Ok(ca) = fs::read(crate::CA_PATH) {
                if let Ok(runtime) = tokio::runtime::Builder::new_current_thread()
                    .enable_all()
                    .build()
                {
                    let url = format!("https://gateway.orbit/api/v1/schedules/{id}/complete");
                    let success = std::env::var("SERVICE_RESULT").as_deref() == Ok("success");
                    let _ = runtime.block_on(report(&url, &ca, success));
                }
            }
            0
        }
        _ => 64,
    }
}

pub(super) fn project_command(host: &Host, id: &str) -> Result<Process, ScheduleError> {
    if identity::effective_uid() == 0 {
        return Err(failed("execution-identity"));
    }
    let paths = host.paths(id)?;
    let script = fs::read_to_string(&paths.script).map_err(|_| failed("read-command"))?;
    let (context, source) = spec::execution(&script, id)?;
    let account = identity::passwd(&context.user).ok_or_else(|| failed("execution-identity"))?;
    let group = identity::group_id(&context.group).ok_or_else(|| failed("execution-identity"))?;
    if account.uid != identity::effective_uid() || group != rustix::process::getegid().as_raw() {
        return Err(failed("execution-identity"));
    }
    if !owned(
        host,
        &paths.script,
        group,
        0o750,
        &format!("# X-Orbit-Schedule-ID={id}"),
    )? {
        return Err(ScheduleError::ArtifactConflict {});
    }
    let mut command = Process::new(&context.shell);
    command
        .arg(if context.login_shell { "-lc" } else { "-c" })
        .arg(source)
        .current_dir(&context.working_directory)
        .env_clear()
        .env(
            "PATH",
            "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
        )
        .env("HOME", context.home)
        .env("SHELL", context.shell)
        .env("USER", &context.user)
        .env("LOGNAME", &context.user);
    Ok(command)
}

pub(super) async fn report(url: &str, ca: &[u8], success: bool) -> Result<(), reqwest::Error> {
    reqwest::Client::builder()
        .tls_built_in_root_certs(false)
        .add_root_certificate(reqwest::Certificate::from_pem(ca)?)
        .https_only(true)
        .no_proxy()
        .redirect(reqwest::redirect::Policy::none())
        .timeout(Duration::from_secs(10))
        .build()?
        .post(url)
        .json(&serde_json::json!({ "status": if success { "success" } else { "error" } }))
        .send()
        .await?
        .error_for_status()?;
    Ok(())
}
