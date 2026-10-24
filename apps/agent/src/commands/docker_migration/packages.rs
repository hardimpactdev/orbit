use super::*;
use recipe::{apt, sources};
pub(super) fn installed(host: &mut impl Host) -> Result<BTreeMap<String, String>> {
    apt::packages(host).map_err(|_| fail("packages"))
}
pub(super) fn arguments(input: &Input, installed: &BTreeMap<String, String>) -> Vec<String> {
    let mut args = vec![
        format!("docker.io={}", input.docker_version),
        format!("containerd={}", input.containerd_version),
        "docker-buildx".into(),
        "docker-compose-v2".into(),
    ];
    args.extend(
        CE.iter()
            .filter(|n| installed.contains_key(**n))
            .map(|n| format!("{n}-")),
    );
    args
}
pub(super) fn guard(host: &mut impl Host, simulation: &str) -> Result<()> {
    let pattern = regex::Regex::new(r"^Inst (\S+)(?: \[([^]]+)\])? \((\S+)").unwrap();
    for line in simulation.lines() {
        if line.starts_with("Remv ") {
            let name = line
                .split_whitespace()
                .nth(1)
                .ok_or_else(|| fail("apt-plan"))?
                .split(':')
                .next()
                .unwrap();
            if !CE.contains(&name) {
                return Err(fail("unrelated-package-removal"));
            }
        }
        if line.starts_with("Inst ") {
            let entry = pattern.captures(line).ok_or_else(|| fail("apt-plan"))?;
            let name = entry[1].split(':').next().unwrap();
            if let Some(old) = entry.get(2) {
                if !CE.contains(&name)
                    && !["docker.io", "containerd"].contains(&name)
                    && command(
                        host,
                        &["dpkg", "--compare-versions", &entry[3], "lt", old.as_str()],
                        false,
                    )?
                    .code
                        != 1
                {
                    return Err(fail("unrelated-package-downgrade"));
                }
            }
        }
    }
    Ok(())
}
fn apt_run(
    host: &mut impl Host,
    args: &[String],
    options: &[String],
    extra: &[&str],
    required: bool,
) -> Result<recipe::Output> {
    let mut invocation = Invocation::new(&[])
        .env("LC_ALL", "C")
        .env("DEBIAN_FRONTEND", "noninteractive")
        .env("NEEDRESTART_SUSPEND", "1");
    invocation.argv.extend_from_slice(args);
    invocation.argv.extend_from_slice(options);
    invocation.argv.extend(extra.iter().map(|s| s.to_string()));
    invocation.timeout = Duration::from_secs(3600);
    recipe::run(host, &invocation, required).map_err(|_| fail("remote"))
}
pub(super) fn swap(
    host: &mut impl DockerHost,
    files: &Files,
    input: &Input,
    state: &mut State,
) -> Result<()> {
    let marker = files.path(JOURNAL).join("restart-policy.json");
    let policy = files.path("/usr/sbin/policy-rc.d");
    let bytes = b"#!/bin/sh\nexit 101\n";
    if marker.exists() {
        if policy.is_symlink()
            || (policy.exists() && fs::read(&policy).map_err(|_| fail("restart-policy"))? != bytes)
        {
            return Err(fail("restart-policy"));
        }
        if policy.exists() {
            fs::remove_file(&policy).map_err(|_| fail("restart-policy"))?;
        }
        fs::remove_file(&marker).map_err(|_| fail("restart-policy"))?;
    }
    if fs::symlink_metadata(&policy).is_ok() {
        return Err(fail("restart-policy"));
    }
    let work = recipe::WorkDir::new().map_err(|_| fail("apt-indexes"))?;
    let source = work.0.join("ubuntu.sources");
    fs::write(&source, sources::snapshot_source(&input.snapshot))
        .map_err(|_| fail("apt-indexes"))?;
    let options = apt::source_options(&source);
    stage(files, state, "apt-indexes")?;
    apt_run(
        host,
        &["apt-get".into(), "update".into()],
        &options,
        &[],
        true,
    )?;
    let args = arguments(input, &installed(host)?);
    let base: Vec<String> = [
        "apt-get",
        "install",
        "--no-install-recommends",
        "--allow-downgrades",
        "-o",
        "Dpkg::Options::=--force-confold",
    ]
    .map(str::to_string)
    .into();
    record(files, "restart-policy.json", &json!({"owned":true}))?;
    files
        .publish(&policy, bytes, 0o755, files.uid, files.gid, false)
        .map_err(|_| fail("restart-policy"))?;
    let result = (|| {
        if state.swapping {
            stage(files, state, "repair-dpkg")?;
            apt_run(
                host,
                &["dpkg".into(), "--configure".into(), "-a".into()],
                &[],
                &[],
                false,
            )?;
            let repair = ["apt-get".into(), "-f".into(), "install".into()];
            let simulation = apt_run(host, &repair, &options, &["--simulate"], true)?.stdout;
            guard(host, &simulation)?;
            stage(files, state, "repair-dependencies")?;
            apt_run(host, &repair, &options, &["--yes"], true)?;
            apt_run(
                host,
                &["dpkg".into(), "--configure".into(), "-a".into()],
                &[],
                &[],
                true,
            )?;
        }
        stage(files, state, "apt-simulation")?;
        let mut extra = vec!["--simulate", "--"];
        extra.extend(args.iter().map(String::as_str));
        let simulation = apt_run(host, &base, &options, &extra, true)?.stdout;
        guard(host, &simulation)?;
        state.swapping = true;
        stage(files, state, "swapping")?;
        extra[0] = "--yes";
        apt_run(host, &base, &options, &extra, true)?;
        stage(files, state, "apt-check")?;
        apt_run(
            host,
            &["apt-get".into(), "check".into()],
            &options,
            &[],
            true,
        )?;
        Ok(())
    })();
    fs::remove_file(&policy).map_err(|_| fail("restart-policy"))?;
    fs::remove_file(marker).map_err(|_| fail("restart-policy"))?;
    result?;
    let after = installed(host)?;
    if after.get("docker.io") != Some(&input.docker_version)
        || after.get("containerd") != Some(&input.containerd_version)
        || CE.iter().any(|n| after.contains_key(*n))
        || ["docker-buildx", "docker-compose-v2"]
            .iter()
            .any(|n| !after.contains_key(*n))
    {
        return Err(fail("package-verification"));
    }
    state.swapped = true;
    state.swapping = false;
    stage(files, state, "swapped")
}
