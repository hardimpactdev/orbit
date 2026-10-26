use super::*;
pub(super) fn network_request(network: &Value) -> Result<Value> {
    let mut body = json!({});
    for key in [
        "Name",
        "Driver",
        "Internal",
        "Attachable",
        "EnableIPv4",
        "EnableIPv6",
        "IPAM",
        "Options",
        "Labels",
    ] {
        if let Some(value) = network.get(key) {
            body[key] = value.clone();
        }
    }
    let mut options = network["Options"].as_object().cloned().unwrap_or_default();
    let bridge = match options
        .get("com.docker.network.bridge.name")
        .filter(|v| truth(v))
    {
        Some(name) => text(name).to_string(),
        None => {
            let id = text(&network["Id"]);
            if !recipe::types::valid_digest(id, 64) {
                return Err(fail("unsupported-network"));
            }
            format!("br-{}", &id[..12])
        }
    };
    if !regex::Regex::new(r"\A[A-Za-z0-9_.-]{1,15}\z")
        .unwrap()
        .is_match(&bridge)
        || [".", ".."].contains(&bridge.as_str())
    {
        return Err(fail("unsupported-network"));
    }
    options.insert("com.docker.network.bridge.name".into(), json!(bridge));
    body["Options"] = json!(options);
    Ok(body)
}
pub(super) fn fresh(host: &mut impl DockerHost, files: &Files, state: &State) -> Result<()> {
    engine::stop_loaded(host)?;
    command(host, &["systemctl", "mask", "containerd.service"], true)?;
    let mut config: Value = state
        .daemon
        .as_ref()
        .map(|s| unhex(s))
        .transpose()?
        .map(|b| serde_json::from_slice(&b))
        .transpose()
        .map_err(|_| fail("daemon-config"))?
        .unwrap_or_else(|| json!({}));
    config
        .as_object_mut()
        .ok_or_else(|| fail("daemon-config"))?
        .remove("data-root");
    record(files, "daemon.json", &config)?;
    let content = format!("[Service]\nExecStart=\nExecStart=/usr/bin/dockerd --host=fd:// --data-root={} --config-file={}\n", files.path(JOURNAL).join("engine").display(), files.path(JOURNAL).join("daemon.json").display());
    let dropin = files.path(DROPIN);
    if dropin.exists()
        && fs::read(&dropin).map_err(|_| fail("custom-daemon"))? != content.as_bytes()
    {
        return Err(fail("custom-daemon"));
    }
    fs::create_dir_all(dropin.parent().unwrap()).map_err(|_| fail("custom-daemon"))?;
    files
        .publish_root(&dropin, content.as_bytes())
        .map_err(|_| fail("custom-daemon"))?;
    command(host, &["systemctl", "daemon-reload"], true)?;
    engine::start(host)?;
    get(host, "/info")?;
    for v in &state.volumes {
        let expected = json!({"type":"none","o":"bind","device":v["Mountpoint"]});
        let existing = host.api(
            "GET",
            &format!("/volumes/{}", quote(text(&v["Name"]))),
            None,
            true,
        )?;
        if existing.is_null() {
            host.api("POST", "/volumes/create", Some(&json!({"Name":v["Name"],"Driver":"local","DriverOpts":expected,"Labels":v["Labels"]})), false)?;
        } else if existing["Options"] != expected {
            return Err(fail("volume-conflict"));
        }
    }
    for n in &state.networks {
        if ["bridge", "host", "none"].contains(&text(&n["Name"])) {
            continue;
        }
        let body = network_request(n)?;
        let existing = host.api(
            "GET",
            &format!("/networks/{}", quote(text(&n["Name"]))),
            None,
            true,
        )?;
        if existing.is_null() {
            host.api("POST", "/networks/create", Some(&body), false)?;
        } else if body
            .as_object()
            .unwrap()
            .iter()
            .any(|(key, value)| &existing[key] != value)
        {
            return Err(fail("network-conflict"));
        }
    }
    Ok(())
}
pub(super) fn effective_host(host: &Value, info: &Value) -> Value {
    let mut result = json!(host
        .as_object()
        .map(|m| m
            .iter()
            .filter(|(_, v)| truth(v))
            .map(|(k, v)| (k.clone(), v.clone()))
            .collect::<serde_json::Map<_, _>>())
        .unwrap_or_default());
    let default = |value: &Value, fallback: &Value, final_value: &str| {
        if truth(value) {
            value.clone()
        } else if truth(fallback) {
            fallback.clone()
        } else {
            json!(final_value)
        }
    };
    result["Runtime"] = default(&host["Runtime"], &info["DefaultRuntime"], "runc");
    result["LogConfig"] = json!({"Type":default(&host["LogConfig"]["Type"], &info["LoggingDriver"], "json-file"),"Config":host["LogConfig"]["Config"].as_object().cloned().unwrap_or_default()});
    let mut policy = json!({"Name":"no","MaximumRetryCount":0});
    if let Some(fields) = host["RestartPolicy"].as_object() {
        policy.as_object_mut().unwrap().extend(fields.clone());
    }
    result["RestartPolicy"] = policy;
    result["NetworkMode"] = default(&host["NetworkMode"], &Value::Null, "default");
    result
}
pub(super) fn recreate(
    host: &mut impl DockerHost,
    c: &Value,
    image_ref: Option<&str>,
) -> Result<()> {
    let name = text(&c["Name"]).trim_start_matches('/');
    let mut body = c["Config"].clone();
    body["HostConfig"] = c["HostConfig"].clone();
    body["HostConfig"]["RestartPolicy"] = json!({"Name":"no"});
    if !body["Labels"].is_object() {
        body["Labels"] = json!({});
    }
    body["Labels"]["orbit.docker.migration"] = c["Id"].clone();
    let mut binds = array(&body["HostConfig"]["Binds"]).to_vec();
    let mut mounts = array(&body["HostConfig"]["Mounts"]).to_vec();
    for mount in &mut mounts {
        if mount["Type"] == "volume" && !truth(&mount["Source"]) {
            mount["Source"] = array(&c["Mounts"])
                .iter()
                .find(|m| m["Destination"] == mount["Target"])
                .ok_or_else(|| fail("missing-mount"))?["Name"]
                .clone();
        }
    }
    if !mounts.is_empty() {
        body["HostConfig"]["Mounts"] = json!(mounts);
    }
    for mount in array(&c["Mounts"]) {
        if mount["Type"] == "volume"
            && !binds
                .iter()
                .any(|b| text(b).split(':').nth(1) == mount["Destination"].as_str())
            && !mounts.iter().any(|m| m["Target"] == mount["Destination"])
        {
            binds.push(json!(format!(
                "{}:{}:{}",
                text(&mount["Name"]),
                text(&mount["Destination"]),
                if truth(&mount["RW"]) { "rw" } else { "ro" }
            )));
        }
    }
    body["HostConfig"]["Binds"] = json!(binds);
    let mut endpoints = json!({});
    if let Some(networks) = c["NetworkSettings"]["Networks"].as_object() {
        for (name, endpoint) in networks {
            let mut fields = json!({});
            for key in [
                "IPAMConfig",
                "Aliases",
                "DriverOpts",
                "MacAddress",
                "GwPriority",
            ] {
                if !endpoint[key].is_null() {
                    fields[key] = endpoint[key].clone();
                }
            }
            endpoints[name] = fields;
        }
    }
    body["NetworkingConfig"] = json!({"EndpointsConfig":endpoints});
    let recovery = c.get("RecoveryImage").unwrap_or(&c["Image"]);
    if image_ref.is_none() {
        body["Image"] = recovery.clone();
    }
    let existing = inspect(host, name, true)?;
    if !existing.is_null() {
        let info = get(host, "/info")?;
        if existing["Image"] != *recovery
            || body
                .as_object()
                .ok_or_else(|| fail("container-conflict"))?
                .iter()
                .any(|(k, v)| {
                    !["HostConfig", "NetworkingConfig"].contains(&k.as_str())
                        && !v.is_null()
                        && v != ""
                        && v != &json!([])
                        && v != &json!({})
                        && &existing["Config"][k] != v
                })
            || effective_host(&existing["HostConfig"], &info)
                != effective_host(&body["HostConfig"], &info)
        {
            return Err(fail("container-conflict"));
        }
        return Ok(());
    }
    if let Some(reference) = image_ref {
        command(host, &["docker", "pull", reference], true)?;
        if get(host, &format!("/images/{}/json", quote(reference)))?["Id"] != c["Image"] {
            return Err(fail("image-version"));
        }
        if !text(&c["Config"]["Image"]).contains('@') {
            command(
                host,
                &["docker", "tag", reference, text(&c["Config"]["Image"])],
                true,
            )?;
        }
    }
    host.api(
        "POST",
        &format!("/containers/create?name={}", quote(name)),
        Some(&body),
        false,
    )?;
    Ok(())
}
pub(super) fn restore(
    host: &mut impl DockerHost,
    files: &Files,
    state: &mut State,
) -> Result<Value> {
    if !packages::installed(host)?.contains_key("docker-ce")
        || files.path(DROPIN).exists()
        || !daemon_unchanged(files, state)?
        || !inventory::same_root(&get(host, "/info")?, state, "restore-engine")?
    {
        return Err(fail("restore-engine"));
    }
    let mut current = vec![];
    for old in &state.containers {
        let actual = inspect(host, text(&old["Id"]), true)?;
        if actual.is_null() || actual["Name"] != old["Name"] {
            return Err(fail("restore-container"));
        }
        current.push(actual);
    }
    stage(files, state, "recover-policies")?;
    let mut original = state.clone();
    original.fallback = false;
    engine::policies(host, &original, true)?;
    for (old, actual) in state.containers.iter().zip(current) {
        if managed(old) {
            continue;
        }
        if truth(&old["State"]["Running"]) {
            command(host, &["docker", "start", text(&old["Id"])], true)?;
        } else if truth(&actual["State"]["Running"]) {
            command(
                host,
                &["docker", "stop", "--time", "60", text(&old["Id"])],
                true,
            )?;
        }
    }
    state.fallback = false;
    state.prepared = true;
    stage(files, state, "restored")?;
    Ok(transaction::report(host, state))
}
