use super::*;
const CONFIG: &str = "Hostname Domainname User AttachStdin AttachStdout AttachStderr ExposedPorts Tty OpenStdin StdinOnce Env Cmd Healthcheck ArgsEscaped Image Volumes WorkingDir Entrypoint NetworkDisabled OnBuild Labels StopSignal StopTimeout Shell";
const HOST: &str = "CpuShares Memory CgroupParent BlkioWeight BlkioWeightDevice BlkioDeviceReadBps BlkioDeviceWriteBps BlkioDeviceReadIOps BlkioDeviceWriteIOps CpuPeriod CpuQuota CpuRealtimePeriod CpuRealtimeRuntime CpusetCpus CpusetMems Devices DeviceCgroupRules DeviceRequests MemoryReservation MemorySwap MemorySwappiness NanoCpus OomKillDisable Init PidsLimit Ulimits CpuCount CpuPercent IOMaximumIOps IOMaximumBandwidth Binds ContainerIDFile LogConfig NetworkMode PortBindings RestartPolicy AutoRemove VolumeDriver VolumesFrom Mounts ConsoleSize Annotations CapAdd CapDrop CgroupnsMode Dns DnsOptions DnsSearch ExtraHosts GroupAdd IpcMode Cgroup Links OomScoreAdj PidMode Privileged PublishAllPorts ReadonlyRootfs SecurityOpt StorageOpt Tmpfs UTSMode UsernsMode ShmSize Sysctls Runtime Isolation MaskedPaths ReadonlyPaths";
fn supported(value: &Value, allowed: &str) -> Result<()> {
    if value.as_object().is_some_and(|fields| {
        fields
            .iter()
            .any(|(key, item)| truth(item) && !allowed.split_whitespace().any(|a| a == key))
    }) {
        return Err(fail("newer-engine-configuration"));
    }
    Ok(())
}
pub(super) fn process(c: &Value, input: &Input) -> Result<()> {
    if !managed(c) {
        return Ok(());
    }
    let labels = &c["Config"]["Labels"];
    let spec = input
        .process_specs
        .get(text(&labels["orbit.process.id"]))
        .ok_or_else(|| fail("orbit-spec-changed"))?;
    if labels["orbit.process.spec"] != spec.hash
        || text(&c["Name"]).trim_start_matches('/') != spec.name
    {
        return Err(fail("orbit-spec-changed"));
    }
    if !["", "default", "bridge"].contains(&text(&c["HostConfig"]["NetworkMode"]))
        || c["NetworkSettings"]["Networks"]
            .as_object()
            .is_some_and(|nets| nets.keys().any(|n| n != "bridge"))
    {
        return Err(fail("orbit-network-drift"));
    }
    for mount in array(&c["Mounts"]) {
        let source = if mount["Type"] == "volume" {
            &mount["Name"]
        } else {
            &mount["Source"]
        };
        if !spec
            .volumes
            .iter()
            .any(|v| &v["source"] == source && v["target"] == mount["Destination"])
        {
            return Err(fail("orbit-unrecorded-mount"));
        }
    }
    Ok(())
}
pub(super) fn container(c: &Value, input: &Input) -> Result<()> {
    let config = &c["Config"];
    let host = &c["HostConfig"];
    let labels = &config["Labels"];
    if text(&config["Image"]).is_empty() {
        return Err(fail("missing-image"));
    }
    supported(config, CONFIG)?;
    supported(host, HOST)?;
    supported(
        &config["Healthcheck"],
        "Test Interval Timeout Retries StartPeriod StartInterval",
    )?;
    if let Some(networks) = c["NetworkSettings"]["Networks"].as_object() {
        for endpoint in networks.values() {
            supported(endpoint, "IPAMConfig Links MacAddress Aliases DriverOpts GwPriority NetworkID EndpointID Gateway IPAddress IPPrefixLen IPv6Gateway GlobalIPv6Address GlobalIPv6PrefixLen DNSNames")?;
            supported(
                &endpoint["IPAMConfig"],
                "IPv4Address IPv6Address LinkLocalIPs",
            )?;
            if truth(&endpoint["Links"]) {
                return Err(fail("unsupported-container-links"));
            }
        }
    }
    for mount in array(&host["Mounts"]) {
        supported(mount, "Target Source Type ReadOnly Consistency BindOptions VolumeOptions ImageOptions TmpfsOptions")?;
        supported(
            &mount["BindOptions"],
            "Propagation NonRecursive CreateMountpoint ReadOnlyNonRecursive ReadOnlyForceRecursive",
        )?;
        supported(
            &mount["VolumeOptions"],
            "NoCopy Labels DriverConfig Subpath",
        )?;
        supported(&mount["VolumeOptions"]["DriverConfig"], "Name Options")?;
        supported(&mount["TmpfsOptions"], "SizeBytes Mode Options")?;
    }
    if ![
        "",
        "json-file",
        "local",
        "none",
        "journald",
        "syslog",
        "gelf",
        "fluentd",
        "awslogs",
        "splunk",
        "gcplogs",
    ]
    .contains(&text(&host["LogConfig"]["Type"]))
    {
        return Err(fail("unsupported-logging-plugin"));
    }
    if truth(&labels["com.docker.swarm.service.id"]) {
        return Err(fail("swarm"));
    }
    if managed(c)
        && (labels["orbit.container.kind"] != "process"
            || !input
                .process_ids
                .iter()
                .any(|id| labels["orbit.process.id"] == *id))
    {
        return Err(fail("orbit-spec-missing"));
    }
    if truth(&c["State"]["Paused"])
        || ["AutoRemove", "VolumesFrom", "Links"]
            .iter()
            .any(|k| truth(&host[k]))
        || ["NetworkMode", "PidMode", "IpcMode"]
            .iter()
            .any(|k| text(&host[k]).starts_with("container:"))
    {
        return Err(fail("unsupported-container"));
    }
    for mount in array(&c["Mounts"]) {
        let kind = text(&mount["Type"]);
        if !["bind", "volume", "tmpfs"].contains(&kind) {
            return Err(fail("unsupported-mount"));
        }
        if ["bind", "volume"].contains(&kind) && !Path::new(text(&mount["Source"])).exists() {
            return Err(fail("missing-mount"));
        }
    }
    Ok(())
}
pub(super) fn storage_root(value: &Value, failure: &'static str) -> Result<PathBuf> {
    let path = Path::new(text(value));
    if !path.is_absolute() {
        return Err(fail(failure));
    }
    let path = path.canonicalize().map_err(|_| fail(failure))?;
    if path == Path::new("/") || !path.is_dir() {
        return Err(fail(failure));
    }
    Ok(path)
}
pub(super) fn defaults(info: &Value) -> Value {
    json!({"DefaultRuntime":info["DefaultRuntime"],"LoggingDriver":info["LoggingDriver"],"CgroupDriver":info["CgroupDriver"]})
}
pub(super) fn same_root(info: &Value, state: &State, failure: &'static str) -> Result<bool> {
    Ok(state.metadata_roots.get("docker") == Some(&storage_root(&info["DockerRootDir"], failure)?))
}
pub(super) fn collect(host: &mut impl DockerHost, files: &Files, input: &Input) -> Result<State> {
    let info = get(host, "/info")?;
    let raw = daemon_bytes(files)?;
    let config: Value = raw
        .as_ref()
        .filter(|b| !b.is_empty())
        .map(|b| serde_json::from_slice(b))
        .transpose()
        .map_err(|_| fail("daemon-config"))?
        .unwrap_or_else(|| json!({}));
    if config.get("hosts").is_some()
        || config.get("containerd").is_some()
        || files.path(DROPIN).exists()
    {
        return Err(fail("custom-daemon"));
    }
    for unit in ["docker.service", "containerd.service"] {
        if !command(
            host,
            &[
                "systemctl",
                "show",
                "--property=DropInPaths",
                "--value",
                unit,
            ],
            true,
        )?
        .stdout
        .trim()
        .is_empty()
        {
            return Err(fail("custom-daemon"));
        }
        let fragment = command(
            host,
            &[
                "systemctl",
                "show",
                "--property=FragmentPath",
                "--value",
                unit,
            ],
            true,
        )?
        .stdout;
        if !fragment.trim().is_empty()
            && ![
                format!("/usr/lib/systemd/system/{unit}"),
                format!("/lib/systemd/system/{unit}"),
            ]
            .contains(&fragment.trim().into())
        {
            return Err(fail("custom-daemon"));
        }
    }
    let docker_root = storage_root(&info["DockerRootDir"], "storage-root")?;
    if config.get("data-root").is_some()
        && storage_root(&config["data-root"], "storage-root")? != docker_root
    {
        return Err(fail("storage-root"));
    }
    let dump = command(host, &["containerd", "config", "dump"], false)?;
    if dump.code != 0 {
        return Err(fail("containerd-root"));
    }
    let parsed: toml::Value = toml::from_str(&dump.stdout).map_err(|_| fail("containerd-root"))?;
    let containerd_root = storage_root(
        &json!(parsed.get("root").and_then(toml::Value::as_str)),
        "containerd-root",
    )?;
    let mut containers = vec![];
    for item in array(&get(host, "/containers/json?all=1")?) {
        containers.push(inspect(host, text(&item["Id"]), false)?);
    }
    let mut images = vec![];
    for item in array(&get(host, "/images/json?all=1")?) {
        images.push(get(
            host,
            &format!("/images/{}/json", quote(text(&item["Id"]))),
        )?);
    }
    let mut image_refs = BTreeMap::new();
    for c in &containers {
        container(c, input)?;
        process(c, input)?;
        if managed(c)
            && c["HostConfig"]["Runtime"].as_str().unwrap_or("runc")
                != info["DefaultRuntime"].as_str().unwrap_or("runc")
        {
            return Err(fail("orbit-runtime-drift"));
        }
        let image = images
            .iter()
            .find(|i| i["Id"] == c["Image"])
            .ok_or_else(|| fail("foreign-image-platform"))?;
        if image["Architecture"] != input.architecture.name() || image["Os"] != "linux" {
            return Err(fail("foreign-image-platform"));
        }
        let references = if text(&c["Config"]["Image"]).contains("@sha256:") {
            vec![c["Config"]["Image"].clone()]
        } else {
            array(&image["RepoDigests"]).to_vec()
        };
        let mut pinned = None;
        for reference in references {
            if command(
                host,
                &["docker", "manifest", "inspect", text(&reference)],
                false,
            )?
            .code
                == 0
            {
                pinned = Some(text(&reference).to_string());
                break;
            }
        }
        image_refs.insert(text(&c["Id"]).into(), pinned);
    }
    let volumes = array(&get(host, "/volumes")?["Volumes"]).to_vec();
    for v in &volumes {
        if v["Driver"] != "local"
            || truth(&v["Options"])
            || !Path::new(text(&v["Mountpoint"])).is_dir()
        {
            return Err(fail("unsupported-volume"));
        }
    }
    let mut networks = vec![];
    for item in array(&get(host, "/networks")?) {
        let n = get(host, &format!("/networks/{}", quote(text(&item["Id"]))))?;
        if !["bridge", "host", "none"].contains(&text(&n["Name"])) {
            if n["Driver"] != "bridge"
                || truth(&n["Ingress"])
                || n["Scope"] != "local"
                || truth(&n["ConfigOnly"])
                || truth(&n["ConfigFrom"]["Network"])
                || n["IPAM"]["Driver"].as_str().unwrap_or("default") != "default"
            {
                return Err(fail("unsupported-network"));
            }
            recovery::network_request(&n)?;
        }
        networks.push(n);
    }
    for c in &mut containers {
        if managed(c) {
            if let Some(config) = c["Config"].as_object_mut() {
                config.remove("Env");
            }
        }
    }
    Ok(State {
        recipe: input.recipe.clone(),
        containers,
        volumes,
        networks,
        images,
        image_refs,
        driver: info["Driver"].clone(),
        metadata_roots: [
            ("docker".into(), docker_root),
            ("containerd".into(), containerd_root),
        ]
        .into(),
        defaults: defaults(&info),
        archives: BTreeMap::new(),
        committed: BTreeMap::new(),
        stage: "inventory".into(),
        daemon: raw.map(|b| hex(&b)),
        fallback: false,
        swapped: false,
        prepared: false,
        stopped: false,
        swapping: false,
        images_preserved: false,
        metadata_backed_up: false,
        policies_disabled: false,
    })
}
