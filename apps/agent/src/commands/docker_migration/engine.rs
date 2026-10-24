use super::*;
use std::{collections::BTreeSet, os::unix::fs::PermissionsExt};
pub(super) fn stop_loaded(host: &mut impl Host) -> Result<()> {
    let mut args = vec!["systemctl", "stop"];
    for unit in ["docker.service", "docker.socket", "containerd.service"] {
        if command(
            host,
            &["systemctl", "show", "--property=LoadState", "--value", unit],
            false,
        )?
        .stdout
        .trim()
            == "loaded"
        {
            args.push(unit);
        }
    }
    if args.len() > 2 {
        command(host, &args, true)?;
    }
    Ok(())
}
pub(super) fn start(host: &mut impl DockerHost) -> Result<()> {
    for attempt in 0..3 {
        let mut invocation = Invocation::new(&["systemctl", "start", "--no-block", "docker"]);
        invocation.timeout = Duration::from_secs(15);
        if recipe::run(host, &invocation, true).is_ok() {
            for _ in 0..10 {
                if get(host, "/info").is_ok() {
                    return Ok(());
                }
                host.wait(Duration::from_secs(2))
                    .map_err(|_| fail("daemon-start"))?;
            }
        }
        if attempt < 2 {
            command(host, &["systemctl", "reset-failed", "docker"], false)?;
        }
    }
    Err(fail("daemon-start"))
}
pub(super) fn archive_images(state: &State) -> Vec<&Value> {
    let used: BTreeSet<_> = state
        .containers
        .iter()
        .filter(|c| {
            state
                .image_refs
                .get(text(&c["Id"]))
                .is_none_or(Option::is_none)
        })
        .map(|c| text(&c["Image"]))
        .collect();
    state
        .images
        .iter()
        .filter(|image| used.contains(text(&image["Id"])))
        .collect()
}
pub(super) fn space(host: &mut impl DockerHost, files: &Files, state: &State) -> Result<Value> {
    let mut image_bytes = 0u64;
    let mut commit_bytes = 0u64;
    let add = |a: u64, b: u64| a.checked_add(b).ok_or_else(|| fail("image-size"));
    for image in archive_images(state) {
        let size = image["Size"].as_u64().ok_or_else(|| fail("image-size"))?;
        if !state.archives.contains_key(text(&image["Id"])) {
            image_bytes = add(image_bytes, size)?;
        }
    }
    if !state.images_preserved {
        for c in &state.containers {
            if managed(c) {
                continue;
            }
            let committed = state.committed.get(&format!("{}:shutdown", text(&c["Id"])));
            if committed.is_some_and(|id| state.archives.contains_key(id)) {
                continue;
            }
            let size = get(
                host,
                &format!("/containers/{}/json?size=1", quote(text(&c["Id"]))),
            )?;
            let root = size["SizeRootFs"]
                .as_u64()
                .ok_or_else(|| fail("image-size"))?;
            let rw = size["SizeRw"].as_u64().ok_or_else(|| fail("image-size"))?;
            commit_bytes = add(
                commit_bytes,
                add(
                    root,
                    if committed.is_some() {
                        rw
                    } else {
                        add(rw, rw)?
                    },
                )?,
            )?;
        }
    }
    let margin = 2 * 1024u64.pow(3);
    let required = add(add(image_bytes, commit_bytes)?, margin)?;
    let mut path = files.path(JOURNAL);
    while !path.exists() {
        if !path.pop() {
            return Err(fail("space"));
        }
    }
    let available = host.free_space(&path)?;
    Ok(
        json!({"image_bytes":image_bytes,"commit_bytes":commit_bytes,"apt_margin_bytes":margin,"required_bytes":required,"available_bytes":available,"sufficient":available>=required}),
    )
}
fn archive(
    host: &mut impl DockerHost,
    files: &Files,
    state: &mut State,
    image: &str,
) -> Result<()> {
    if state.archives.contains_key(image) {
        return Ok(());
    }
    if image.contains('/') || image.contains('\0') {
        return Err(fail("image-id"));
    }
    let destination = files
        .path(JOURNAL)
        .join(format!("image-{}.tar", image.replace(':', "-")));
    let temporary = destination.with_extension("partial");
    command(
        host,
        &[
            "docker",
            "save",
            "--output",
            &temporary.to_string_lossy(),
            image,
        ],
        true,
    )?;
    fs::set_permissions(&temporary, fs::Permissions::from_mode(0o600))
        .map_err(|_| fail("archive"))?;
    fs::File::open(&temporary)
        .and_then(|f| f.sync_all())
        .map_err(|_| fail("archive"))?;
    fs::rename(temporary, &destination).map_err(|_| fail("archive"))?;
    state.archives.insert(image.into(), destination);
    record(files, "inventory.json", state)
}
pub(super) fn preserve(
    host: &mut impl DockerHost,
    files: &Files,
    state: &mut State,
    shutdown: bool,
) -> Result<()> {
    let images: Vec<String> = archive_images(state)
        .iter()
        .map(|i| text(&i["Id"]).into())
        .collect();
    for image in images {
        archive(host, files, state, &image)?;
    }
    for c in state.containers.clone() {
        if managed(&c) {
            continue;
        }
        let id = text(&c["Id"]);
        let key = format!("{id}:{}", if shutdown { "shutdown" } else { "running" });
        if !state.committed.contains_key(&key)
            && truth(&get(host, &format!("/containers/{}/changes", quote(id)))?)
        {
            let image = command(host, &["docker", "commit", id], true)?
                .stdout
                .trim()
                .to_string();
            if !image
                .strip_prefix("sha256:")
                .is_some_and(|s| recipe::types::valid_digest(s, 64))
            {
                return Err(fail("commit-image"));
            }
            state.committed.insert(key.clone(), image);
            record(files, "inventory.json", state)?;
        }
        if let Some(image) = state.committed.get(&key).cloned() {
            archive(host, files, state, &image)?;
        }
    }
    Ok(())
}
pub(super) fn backup(files: &Files, state: &mut State) -> Result<()> {
    if state.metadata_backed_up {
        return Ok(());
    }
    stage(files, state, "metadata-backup")?;
    // Traverse only metadata trees. Never descend into overlay layers or volume payloads.
    fn copy(root: &Path, path: &Path, target: &Path) -> Result<()> {
        let meta = match fs::symlink_metadata(path) {
            Ok(meta) => meta,
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(()),
            Err(_) => return Err(fail("metadata-backup")),
        };
        if meta.is_symlink() {
            return Ok(());
        }
        let relative = path
            .strip_prefix(root)
            .map_err(|_| fail("metadata-backup"))?;
        if relative.components().any(|c| c.as_os_str() == "_data") {
            return Ok(());
        }
        if meta.is_dir() {
            for entry in fs::read_dir(path).map_err(|_| fail("metadata-backup"))? {
                copy(
                    root,
                    &entry.map_err(|_| fail("metadata-backup"))?.path(),
                    target,
                )?;
            }
        } else if meta.is_file() {
            let name = path.file_name().unwrap_or_default();
            let image = relative.starts_with("image")
                && relative
                    .components()
                    .any(|c| ["imagedb", "layerdb"].iter().any(|n| c.as_os_str() == *n));
            if !image
                && !["metadata.db", "meta.db", "local-kv.db"]
                    .iter()
                    .any(|n| name == *n)
                && !(relative.starts_with("containers")
                    && ["config.v2.json", "hostconfig.json"]
                        .iter()
                        .any(|n| name == *n))
            {
                return Ok(());
            }
            let dest = target.join(relative);
            fs::DirBuilder::new()
                .recursive(true)
                .mode(0o700)
                .create(dest.parent().unwrap())
                .map_err(|_| fail("metadata-backup"))?;
            let mut input = fs::File::open(path).map_err(|_| fail("metadata-backup"))?;
            use std::os::unix::fs::OpenOptionsExt;
            let mut output = fs::OpenOptions::new()
                .write(true)
                .create(true)
                .truncate(true)
                .mode(0o600)
                .open(dest)
                .map_err(|_| fail("metadata-backup"))?;
            std::io::copy(&mut input, &mut output)
                .and_then(|_| output.sync_all())
                .map_err(|_| fail("metadata-backup"))?;
        }
        Ok(())
    }
    for (name, root) in &state.metadata_roots {
        let target = files.path(JOURNAL).join("metadata").join(name);
        for relative in [
            "volumes/metadata.db",
            "network/files/local-kv.db",
            "io.containerd.metadata.v1.bolt/meta.db",
            "containerd/daemon/io.containerd.metadata.v1.bolt/meta.db",
            "image",
        ] {
            copy(root, &root.join(relative), &target)?;
        }
        let containers = root.join("containers");
        if containers.is_dir() && !containers.is_symlink() {
            for entry in fs::read_dir(&containers).map_err(|_| fail("metadata-backup"))? {
                let path = entry.map_err(|_| fail("metadata-backup"))?.path();
                if path.is_symlink() {
                    continue;
                }
                for name in ["config.v2.json", "hostconfig.json"] {
                    copy(root, &path.join(name), &target)?;
                }
            }
        }
    }
    state.metadata_backed_up = true;
    record(files, "inventory.json", state)
}
pub(super) fn policies(host: &mut impl DockerHost, state: &State, restore: bool) -> Result<()> {
    for c in &state.containers {
        if state.fallback && managed(c) {
            continue;
        }
        let reference =
            text(&c[if state.fallback { "Name" } else { "Id" }]).trim_start_matches('/');
        let policy = if restore {
            c["HostConfig"]
                .get("RestartPolicy")
                .cloned()
                .unwrap_or_else(|| json!({"Name":"no"}))
        } else {
            json!({"Name":"no"})
        };
        host.api(
            "POST",
            &format!("/containers/{}/update", quote(reference)),
            Some(&json!({"RestartPolicy":policy})),
            false,
        )?;
    }
    Ok(())
}
pub(super) fn restart_original(
    host: &mut impl DockerHost,
    files: &Files,
    state: &mut State,
) -> Result<()> {
    state.metadata_backed_up = false;
    for image in state.committed.values().collect::<BTreeSet<_>>() {
        if let Some(archive) = state.archives.remove(image) {
            match fs::remove_file(archive) {
                Ok(()) => (),
                Err(e) if e.kind() == std::io::ErrorKind::NotFound => (),
                Err(_) => return Err(fail("archive")),
            }
        }
    }
    state.committed.clear();
    state.images_preserved = false;
    record(files, "inventory.json", state)?;
    start(host)?;
    policies(host, state, true)?;
    for c in &state.containers {
        if truth(&c["State"]["Running"]) {
            command(host, &["docker", "start", text(&c["Id"])], false)?;
        }
    }
    Ok(())
}
pub(super) fn readable(host: &mut impl DockerHost, state: &State) -> bool {
    (|| -> Result<bool> {
        start(host)?;
        let info = get(host, "/info")?;
        if !inventory::same_root(&info, state, "storage-root")?
            || info["Driver"] != state.driver
            || inventory::defaults(&info) != state.defaults
        {
            return Ok(false);
        }
        for c in &state.containers {
            if inspect(host, text(&c["Id"]), true)?.is_null() {
                return Ok(false);
            }
        }
        for image in &state.images {
            if host
                .api(
                    "GET",
                    &format!("/images/{}/json", quote(text(&image["Id"]))),
                    None,
                    true,
                )?
                .is_null()
            {
                return Ok(false);
            }
        }
        for v in &state.volumes {
            if host
                .api(
                    "GET",
                    &format!("/volumes/{}", quote(text(&v["Name"]))),
                    None,
                    true,
                )?
                .is_null()
            {
                return Ok(false);
            }
        }
        for c in &state.containers {
            get(
                host,
                &format!("/containers/{}/changes", quote(text(&c["Id"]))),
            )?;
        }
        if let Some(c) = state
            .containers
            .iter()
            .find(|c| !managed(c) && truth(&c["State"]["Running"]))
        {
            command(host, &["docker", "start", text(&c["Id"])], true)?;
            command(
                host,
                &["docker", "stop", "--time", "60", text(&c["Id"])],
                true,
            )?;
        }
        Ok(true)
    })()
    .unwrap_or(false)
}
