use super::*;
use recipe::sources::{self, Provider};
use std::collections::BTreeSet;
const STEPS: &[&str] = &[
    "inventory and storage driver", "check archive and commit space plus 2 GiB apt margin", "disable restart policies", "stop all containers",
    "archive used local images and commit changed non-Orbit layers once after stop (running only on stop failure)",
    "back up offline Docker and containerd metadata databases", "retire Docker apt sources with backups in /etc/apt",
    "swap CE packages for recipe docker.io, containerd, docker-buildx, docker-compose-v2",
    "record swapping; repair interrupted dpkg and dependencies; install with recipe snapshot sources",
    "verify every image, container layer and volume; start and stop one container before restoring policies",
    "if unreadable: refuse unless --fallback; mask system containerd, isolate fresh engine, load archives, preserve volume paths and recreate",
    "reconcile Orbit Processes from stored specs", "report every container state",
];
pub(super) fn report(host: &mut impl DockerHost, state: &State) -> Value {
    let mut containers = vec![];
    for old in &state.containers {
        let (current, status) = match inspect(host, text(&old["Name"]), true) {
            Ok(c) if !c.is_null() => {
                let status = text(&c["State"]["Status"]).to_string();
                (c, status)
            }
            Ok(_) => (Value::Null, "missing".into()),
            Err(_) => (Value::Null, "unreadable".into()),
        };
        containers.push(json!({"name":text(&old["Name"]).trim_start_matches('/'),"state":status,"health":current["State"]["Health"]["Status"]}));
    }
    let ids: BTreeSet<_> = state
        .containers
        .iter()
        .filter(|c| managed(c))
        .map(|c| text(&c["Config"]["Labels"]["orbit.process.id"]))
        .collect();
    json!({"fallback":state.fallback,"containers":containers,"process_ids":ids})
}
fn final_report(host: &mut impl DockerHost, state: &State, input: &Input) -> Result<Value> {
    let mut result = report(host, state);
    for _ in 0..60 {
        if !array(&result["containers"])
            .iter()
            .any(|c| c["health"] == "starting")
        {
            break;
        }
        host.wait(Duration::from_secs(2))
            .map_err(|_| fail("report"))?;
        result = report(host, state);
    }
    result["healthy"] = json!(state
        .containers
        .iter()
        .zip(array(&result["containers"]))
        .all(|(old, current)| {
            let process_id = text(&old["Config"]["Labels"]["orbit.process.id"]);
            let expected = if process_id.is_empty() {
                truth(&old["State"]["Running"])
            } else {
                input
                    .process_states
                    .get(process_id)
                    .is_some_and(|s| s == "running")
            };
            !["missing", "unreadable"].contains(&text(&current["state"]))
                && expected == (current["state"] == "running")
                && !(expected && ["starting", "unhealthy"].contains(&text(&current["health"])))
        }));
    Ok(result)
}
fn plan(
    host: &mut impl DockerHost,
    files: &Files,
    input: &Input,
    state: &State,
    changes: &[sources::Change],
    resuming: bool,
) -> Result<Value> {
    let mut changed_layers = vec![];
    for c in &state.containers {
        if !managed(c)
            && truth(&get(
                host,
                &format!("/containers/{}/changes", quote(text(&c["Id"]))),
            )?)
        {
            changed_layers.push(text(&c["Name"]).trim_start_matches('/'));
        }
    }
    let backups: Vec<_> = changes
        .iter()
        .map(|c| {
            files.apt().join(format!(
                "{}.orbit-docker-backup",
                c.path.file_name().unwrap().to_string_lossy()
            ))
        })
        .collect();
    Ok(
        json!({"steps":STEPS,"containers":state.containers.iter().map(|c|text(&c["Name"]).trim_start_matches('/')).collect::<Vec<_>>(),
        "recreate":state.containers.iter().map(|c|json!({"name":text(&c["Name"]).trim_start_matches('/'),"image":c["Config"]["Image"],"pull":state.image_refs.get(text(&c["Id"])),"runtime":c["HostConfig"]["Runtime"].as_str().unwrap_or("runc"),"source":if managed(c){"stored Process spec"}else{"inventory"},"restart":c["State"]["Running"]})).collect::<Vec<_>>(),
        "fallback_data_root":files.path(JOURNAL).join("engine"),"fallback_systemd_dropin":files.path(DROPIN),
        "volume_backings":state.volumes.iter().map(|v|json!({"name":v["Name"],"path":v["Mountpoint"]})).collect::<Vec<_>>(),
        "packages":packages::arguments(input,&packages::installed(host)?),"source_backups":backups,"resuming":resuming,"daemon_json":"unchanged","volume_data":"never deleted","storage_driver":state.driver,"fallback_authorized":input.fallback,"metadata_roots":state.metadata_roots,
        "space":engine::space(host,files,state)?,"local_images":engine::archive_images(state).iter().map(|i|&i["Id"]).collect::<Vec<_>>(),"changed_layers":changed_layers}),
    )
}
fn prepare(
    host: &mut impl DockerHost,
    files: &Files,
    input: &Input,
    state: &mut State,
    sources: &[sources::Change],
) -> Result<Value> {
    if state.prepared {
        return Ok(report(host, state));
    }
    let current = match get(host, "/containers/json?all=1") {
        Ok(value) => value,
        Err(_) if state.stopped => json!([]),
        Err(_) => {
            command(host, &["systemctl", "start", "docker"], true)?;
            get(host, "/containers/json?all=1")?
        }
    };
    if !state.fallback
        && array(&current)
            .iter()
            .any(|c| !state.containers.iter().any(|old| old["Id"] == c["Id"]))
    {
        return Err(fail("inventory-drift"));
    }
    if engine::space(host, files, state)?["sufficient"] != true {
        return Err(fail("insufficient-space"));
    }
    if state.stopped {
        for c in array(&current) {
            host.api(
                "POST",
                &format!("/containers/{}/update", quote(text(&c["Id"]))),
                Some(&json!({"RestartPolicy":{"Name":"no"}})),
                false,
            )?;
            command(
                host,
                &["docker", "stop", "--time", "60", text(&c["Id"])],
                true,
            )?;
        }
    } else {
        state.policies_disabled = true;
        stage(files, state, "disable-policies")?;
        if let Err(error) = engine::policies(host, state, false) {
            engine::restart_original(host, files, state)?;
            state.policies_disabled = false;
            stage(files, state, "post-stop-refusal")?;
            return Err(error);
        }
        stage(files, state, "stop-containers")?;
        for c in array(&current) {
            if let Err(error) = command(
                host,
                &["docker", "stop", "--time", "60", text(&c["Id"])],
                true,
            ) {
                let preserved = engine::preserve(host, files, state, false);
                engine::restart_original(host, files, state)?;
                state.policies_disabled = false;
                stage(files, state, "post-stop-refusal")?;
                preserved?;
                return Err(error);
            }
        }
        state.stopped = true;
        stage(files, state, "stopped")?;
    }
    if !state.images_preserved {
        stage(files, state, "preserve-shutdown-layers")?;
        engine::preserve(host, files, state, true)?;
        state.images_preserved = true;
        record(files, "inventory.json", state)?;
    }
    stage(files, state, "stop-services")?;
    engine::stop_loaded(host)?;
    engine::backup(files, state)?;
    stage(files, state, "retire-sources")?;
    sources::retire(files, sources, "docker").map_err(|_| fail("apt-source"))?;
    if !state.swapped {
        packages::swap(host, files, input, state)?;
    }
    stage(files, state, "verify-engine")?;
    if !state.fallback && !engine::readable(host, state) {
        if !input.fallback {
            return Err(fail("fallback-required"));
        }
        state.fallback = true;
        stage(files, state, "fallback")?;
    }
    if state.fallback {
        stage(files, state, "fresh-engine")?;
        recovery::fresh(host, files, state)?;
        stage(files, state, "load-images")?;
        for archive in state.archives.values() {
            command(
                host,
                &["docker", "load", "--input", &archive.to_string_lossy()],
                true,
            )?;
        }
        stage(files, state, "restore-image-tags")?;
        for image in &state.images {
            if state.archives.contains_key(text(&image["Id"])) {
                for tag in array(&image["RepoTags"]) {
                    command(
                        host,
                        &["docker", "tag", text(&image["Id"]), text(tag)],
                        true,
                    )?;
                }
            }
        }
        stage(files, state, "recreate")?;
        for old in &state.containers {
            if managed(old) {
                continue;
            }
            let mut c = old.clone();
            let id = text(&c["Id"]);
            let committed = state
                .committed
                .get(&format!("{id}:shutdown"))
                .or_else(|| state.committed.get(&format!("{id}:running")));
            let reference = if committed.is_some() {
                None
            } else {
                state.image_refs.get(id).and_then(Option::as_deref)
            };
            if let Some(image) = committed {
                c["RecoveryImage"] = json!(image);
            }
            recovery::recreate(host, &c, reference)?;
        }
    }
    stage(files, state, "restore-policies")?;
    engine::policies(host, state, true)?;
    stage(files, state, "restart-containers")?;
    for c in &state.containers {
        if !managed(c) && truth(&c["State"]["Running"]) {
            command(
                host,
                &["docker", "start", text(&c["Name"]).trim_start_matches('/')],
                true,
            )?;
        }
    }
    state.prepared = true;
    stage(files, state, "prepared")?;
    Ok(report(host, state))
}
pub(super) fn run(
    host: &mut impl DockerHost,
    files: &Files,
    input: &Input,
    mode: Mode,
) -> Result<Value> {
    input.validate()?;
    let path = files.path(JOURNAL).join("inventory.json");
    let mut state: State = if path.exists() {
        serde_json::from_slice(&fs::read(&path).map_err(|_| fail("journal"))?)
            .map_err(|_| fail("journal"))?
    } else {
        if mode == Mode::Restore || mode == Mode::Report {
            return Err(fail("missing-inventory"));
        }
        if !packages::installed(host)?.contains_key("docker-ce") {
            return Err(fail("not-docker-ce"));
        }
        inventory::collect(host, files, input)?
    };
    let result = (|| {
        if state.recipe != input.recipe {
            return Err(fail("recipe-mismatch"));
        }
        for c in &state.containers {
            inventory::process(c, input)?;
        }
        if mode == Mode::Restore {
            return recovery::restore(host, files, &mut state);
        }
        if ["recover-policies", "restored"].contains(&state.stage.as_str()) && mode != Mode::Report
        {
            return Err(fail("already-restored"));
        }
        let sources =
            sources::provider_changes(files, Provider::Docker).map_err(|_| fail("apt-source"))?;
        if mode == Mode::Plan {
            return plan(host, files, input, &state, &sources, path.exists());
        }
        if !daemon_unchanged(files, &state)? {
            return Err(fail("daemon-changed"));
        }
        if mode == Mode::Report {
            return final_report(host, &state, input);
        }
        if !path.exists() {
            record(files, "inventory.json", &state)?;
        }
        let prepared = prepare(host, files, input, &mut state, &sources);
        if prepared.is_err()
            && state.stopped
            && !state.swapping
            && !state.swapped
            && state.stage != "preserve-shutdown-layers"
        {
            engine::restart_original(host, files, &mut state)?;
            state.stopped = false;
            stage(files, &mut state, "post-stop-refusal")?;
        }
        prepared
    })();
    result.map_err(|mut e| {
        if e.stage == "remote" {
            e.stage = match state.stage.as_str() {
                "apt-indexes" => "apt-indexes",
                "repair-dpkg" => "repair-dpkg",
                "repair-dependencies" => "repair-dependencies",
                "apt-simulation" => "apt-simulation",
                "swapping" => "swapping",
                "apt-check" => "apt-check",
                "stop-services" => "stop-services",
                "preserve-shutdown-layers" => "preserve-shutdown-layers",
                "post-stop-refusal" => "post-stop-refusal",
                "fresh-engine" => "fresh-engine",
                "load-images" => "load-images",
                "restore-image-tags" => "restore-image-tags",
                "recreate" => "recreate",
                "recover-policies" => "recover-policies",
                "restart-containers" => "restart-containers",
                _ => "remote",
            };
        }
        e.report = Some(report(host, &state));
        e
    })
}
