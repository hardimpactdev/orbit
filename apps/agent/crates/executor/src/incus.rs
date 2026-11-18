//! SPIKE: Incus primitives. Each acts on one Incus resource (a network ACL, an instance, a device, a file inside an
//! instance) and is idempotent: a repeat finds the state already right and answers `applied 0`. The Gateway composes them
//! into a clone; no Orbit concept lives here. Instances are held to Orbit's project and naming, so an order can only touch
//! Instance containers.

use std::collections::BTreeMap;
use std::io::Write as _;
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

use orbit_executor_core::journal::Outcome;
use serde::Deserialize;

const PROJECT: &str = "orbit-instances";

fn instance_ok(name: &str) -> bool {
    name.strip_prefix("instance-").is_some_and(|id| !id.is_empty() && id.bytes().all(|b| b.is_ascii_digit()))
}

fn applied(changes: u32) -> Outcome {
    Outcome::Applied { changes }
}

fn failed(code: &str) -> Outcome {
    Outcome::Failed { code: code.to_owned() }
}

fn input<T: for<'de> Deserialize<'de>>(value: &serde_json::Value) -> Result<T, Outcome> {
    serde_json::from_value(value.clone()).map_err(|_| failed("incus.input_invalid"))
}

/// Runs `incus` with optional stdin; returns stdout on success.
fn incus(args: &[&str], stdin: Option<&[u8]>) -> Result<Vec<u8>, String> {
    let mut child = Command::new("incus")
        .args(args)
        .stdin(if stdin.is_some() { Stdio::piped() } else { Stdio::null() })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|error| error.to_string())?;
    if let (Some(bytes), Some(mut pipe)) = (stdin, child.stdin.take()) {
        pipe.write_all(bytes).map_err(|error| error.to_string())?;
    }
    let out = child.wait_with_output().map_err(|error| error.to_string())?;
    if out.status.success() {
        Ok(out.stdout)
    } else {
        Err(String::from_utf8_lossy(&out.stderr).trim().to_owned())
    }
}

fn query(path: &str) -> Option<serde_json::Value> {
    incus(&["query", path], None).ok().and_then(|out| serde_json::from_slice(&out).ok())
}

fn instance(name: &str) -> Option<serde_json::Value> {
    query(&format!("/1.0/instances/{name}?project={PROJECT}"))
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Acl {
    name: String,
    rules: serde_json::Value,
}

/// A network ACL with exactly these rules (`description`, `egress`, `ingress`).
pub(crate) fn acl_ensure(value: &serde_json::Value) -> Outcome {
    let acl: Acl = match input(value) {
        Ok(acl) => acl,
        Err(outcome) => return outcome,
    };
    if !instance_ok(&acl.name) {
        return failed("incus.acl_name_refused");
    }
    let mut changes = 0;
    let current = query(&format!("/1.0/network-acls/{}?project=default", acl.name));
    if current.is_none() {
        if incus(&["network", "acl", "create", &acl.name, "--project", "default"], None).is_err() {
            return failed("incus.acl_create_failed");
        }
        changes += 1;
    }
    let same = current.as_ref().is_some_and(|found| {
        ["description", "egress", "ingress"].iter().all(|key| found.get(*key) == acl.rules.get(*key))
    });
    if !same {
        let body = acl.rules.to_string();
        if incus(&["network", "acl", "edit", &acl.name, "--project", "default"], Some(body.as_bytes())).is_err() {
            return failed("incus.acl_edit_failed");
        }
        changes += 1;
    }
    applied(changes)
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Copy {
    source: String,
    snapshot: String,
    name: String,
    config: BTreeMap<String, String>,
    devices: BTreeMap<String, BTreeMap<String, String>>,
}

/// A copy of `source/snapshot` named `name`, with this config and these device keys. An existing instance is left as it
/// is. Answers the instance's `volatile.uuid`, which the Gateway turns into its machine id.
pub(crate) fn copy(value: &serde_json::Value) -> Outcome {
    let copy: Copy = match input(value) {
        Ok(copy) => copy,
        Err(outcome) => return outcome,
    };
    if !instance_ok(&copy.name) || !instance_ok(&copy.source) || !copy.snapshot.starts_with("seed-") {
        return failed("incus.copy_refused");
    }
    if instance(&copy.name).is_none() {
        let mut args: Vec<String> = ["--project", PROJECT, "copy"].map(str::to_owned).to_vec();
        args.push(format!("{}/{}", copy.source, copy.snapshot));
        args.push(copy.name.clone());
        for (key, value) in &copy.config {
            args.extend(["--config".to_owned(), format!("{key}={value}")]);
        }
        for (device, keys) in &copy.devices {
            for (key, value) in keys {
                args.extend(["--device".to_owned(), format!("{device},{key}={value}")]);
            }
        }
        let argv: Vec<&str> = args.iter().map(String::as_str).collect();
        if let Err(error) = incus(&argv, None) {
            eprintln!("incus.copy: {error}");
            return failed("incus.copy_failed");
        }
    }
    match instance(&copy.name).and_then(|read| read["config"]["volatile.uuid"].as_str().map(str::to_owned)) {
        Some(uuid) => Outcome::Output { stdout: uuid, encoding: "text".to_owned() },
        None => failed("incus.copy_unreadable"),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Device {
    instance: String,
    device: String,
    config: BTreeMap<String, String>,
}

/// A device on an instance; an existing device of that name is left as it is.
pub(crate) fn device_ensure(value: &serde_json::Value) -> Outcome {
    let device: Device = match input(value) {
        Ok(device) => device,
        Err(outcome) => return outcome,
    };
    let Some(read) = instance(&device.instance).filter(|_| instance_ok(&device.instance)) else {
        return failed("incus.instance_missing");
    };
    if read["devices"].get(&device.device).is_some() {
        return applied(0);
    }
    let Some(kind) = device.config.get("type") else { return failed("incus.input_invalid") };
    let mut args = vec!["--project".to_owned(), PROJECT.to_owned(), "config".into(), "device".into(), "add".into(),
        device.instance.clone(), device.device.clone(), kind.clone()];
    args.extend(device.config.iter().filter(|(key, _)| key.as_str() != "type").map(|(key, value)| format!("{key}={value}")));
    let argv: Vec<&str> = args.iter().map(String::as_str).collect();
    match incus(&argv, None) {
        Ok(_) => applied(1),
        Err(_) => failed("incus.device_add_failed"),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Blob {
    sha256: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct FilePut {
    instance: String,
    path: String,
    blob: Blob,
    uid: u32,
    gid: u32,
    mode: String,
}

/// A file inside an instance, running or stopped, with these bytes, owner and mode. Its content comes from the bus's
/// checked cache by hash.
pub(crate) fn file_put(value: &serde_json::Value) -> Outcome {
    let put: FilePut = match input(value) {
        Ok(put) => put,
        Err(outcome) => return outcome,
    };
    if !instance_ok(&put.instance) || !put.path.starts_with('/') || put.path.contains("..")
        || put.mode.len() != 4 || !put.mode.bytes().all(|b| (b'0'..=b'7').contains(&b))
    {
        return failed("incus.input_invalid");
    }
    if !crate::prims::valid_sha(&put.blob.sha256) {
        return failed("incus.input_invalid");
    }
    let Ok(bytes) = std::fs::read(std::path::Path::new(crate::prims::BLOBS).join(&put.blob.sha256)) else {
        return failed("blob.missing");
    };
    if crate::prims::sha_hex(&bytes) != put.blob.sha256 {
        return failed("blob.hash_mismatch");
    }
    let target = format!("{}{}", put.instance, put.path);
    let (uid, gid) = (put.uid.to_string(), put.gid.to_string());
    match incus(&["--project", PROJECT, "file", "push", "--create-dirs", "--uid", &uid, "--gid", &gid, "--mode", &put.mode, "-", &target],
        Some(&bytes))
    {
        Ok(_) => applied(1),
        Err(_) => failed("incus.file_push_failed"),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct FileLink {
    instance: String,
    path: String,
    target: String,
}

/// A symbolic link inside an instance.
pub(crate) fn file_link(value: &serde_json::Value) -> Outcome {
    let link: FileLink = match input(value) {
        Ok(link) => link,
        Err(outcome) => return outcome,
    };
    if !instance_ok(&link.instance) || !link.path.starts_with('/') || link.path.contains("..") {
        return failed("incus.input_invalid");
    }
    let path = format!("{}{}", link.instance, link.path);
    match incus(&["--project", PROJECT, "file", "create", "--type=symlink", "--force", &path, &link.target], None) {
        Ok(_) => applied(1),
        Err(_) => failed("incus.file_link_failed"),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Named {
    instance: String,
}

/// A running instance.
pub(crate) fn start(value: &serde_json::Value) -> Outcome {
    let named: Named = match input(value) {
        Ok(named) => named,
        Err(outcome) => return outcome,
    };
    let Some(read) = instance(&named.instance).filter(|_| instance_ok(&named.instance)) else {
        return failed("incus.instance_missing");
    };
    if read["status"] == "Running" {
        return applied(0);
    }
    match incus(&["--project", PROJECT, "start", &named.instance], None) {
        Ok(_) => applied(1),
        Err(error) => {
            eprintln!("incus.start: {error}");
            failed("incus.start_failed")
        }
    }
}

/// A stopped copy on a `dir` pool whose copy lost its on-disk ID map: its files go back to unshifted IDs, so its first start
/// applies its own map. A source that uses idmapped mounts (every ZFS Node) needs nothing: `applied 0`.
pub(crate) fn rootfs_unshift(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Unshift {
        instance: String,
        source: String,
    }
    let unshift: Unshift = match input(value) {
        Ok(unshift) => unshift,
        Err(outcome) => return outcome,
    };
    if !instance_ok(&unshift.instance) || !instance_ok(&unshift.source) {
        return failed("incus.input_invalid");
    }
    let Some(read) = instance(&unshift.instance) else { return failed("incus.instance_missing") };
    if read["status"] != "Stopped" {
        return applied(0);
    }
    let map = |value: &serde_json::Value| -> Vec<serde_json::Value> {
        value["config"]["volatile.last_state.idmap"].as_str().and_then(|text| serde_json::from_str(text).ok()).unwrap_or_default()
    };
    if !map(&read).is_empty() {
        return applied(0);
    }
    let Some(source) = instance(&unshift.source) else { return failed("incus.instance_missing") };
    let ranges = map(&source);
    if ranges.is_empty() {
        return applied(0);
    }
    let pool_name = read["expanded_devices"]["root"]["pool"].as_str().unwrap_or("");
    let Some(pool) = query(&format!("/1.0/storage-pools/{pool_name}")) else { return failed("incus.pool_unreadable") };
    if pool["driver"] != "dir" {
        return failed("incus.unshift_unsupported");
    }
    let root = std::path::Path::new(pool["config"]["source"].as_str().unwrap_or("/nonexistent"))
        .join("containers")
        .join(format!("{PROJECT}_{}", unshift.instance))
        .join("rootfs");
    let pick = |kind: &str| -> Vec<(u32, u32, u32)> {
        ranges
            .iter()
            .filter(|range| range[kind] == true)
            .filter_map(|range| Some((range["Hostid"].as_u64()? as u32, range["Nsid"].as_u64()? as u32, range["Maprange"].as_u64()? as u32)))
            .collect()
    };
    match crate::unshift::walk(&root, &pick("Isuid"), &pick("Isgid")) {
        Ok(changed) => applied(u32::from(changed)),
        Err(error) => {
            eprintln!("incus.rootfs_unshift: {error}");
            failed("incus.unshift_failed")
        }
    }
}

/// `ufw route <rule> in on <in> [out on <out>] from <from> to <to> port <port> proto <proto> comment <comment>`. ufw itself
/// skips a rule that exists.
pub(crate) fn ensure_route(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Route {
        rule: String,
        interface_in: String,
        interface_out: Option<String>,
        from_ip: String,
        to_ip: String,
        to_port: String,
        proto: String,
        comment: String,
    }
    let route: Route = match input(value) {
        Ok(route) => route,
        Err(outcome) => return outcome,
    };
    let word = |text: &str| !text.is_empty() && text.bytes().all(|b| b.is_ascii_alphanumeric() || b".:/-_".contains(&b));
    if !["allow", "deny"].contains(&route.rule.as_str()) || !route.comment.starts_with("orbit:")
        || ![&route.interface_in, &route.from_ip, &route.to_ip, &route.to_port, &route.proto, &route.comment].iter().all(|t| word(t))
        || route.interface_out.as_deref().is_some_and(|out| !word(out))
    {
        return failed("ufw.input_invalid");
    }
    let mut args = vec!["route", route.rule.as_str(), "in", "on", route.interface_in.as_str()];
    if let Some(out) = route.interface_out.as_deref() {
        args.extend(["out", "on", out]);
    }
    args.extend(["from", &route.from_ip, "to", &route.to_ip, "port", &route.to_port, "proto", &route.proto, "comment", &route.comment]);
    match Command::new("ufw").args(&args).output() {
        Ok(out) if out.status.success() => {
            let text = String::from_utf8_lossy(&out.stdout);
            applied(u32::from(text.contains("Rule added") || text.contains("Rules updated")))
        }
        _ => failed("ufw.route_failed"),
    }
}

/// Waits until a URL answers with a 2xx status, up to `seconds`: a server that is still starting may answer 503 first.
pub(crate) fn http_wait(value: &serde_json::Value) -> Outcome {
    #[derive(Deserialize)]
    #[serde(deny_unknown_fields)]
    struct Wait {
        url: String,
        seconds: u64,
    }
    let wait: Wait = match input(value) {
        Ok(wait) => wait,
        Err(outcome) => return outcome,
    };
    if !wait.url.starts_with("http://") || wait.seconds > 120 {
        return failed("http.input_invalid");
    }
    let until = Instant::now() + Duration::from_secs(wait.seconds);
    loop {
        let answered = Command::new("curl")
            .args(["-s", "-o", "/dev/null", "-w", "%{http_code}", "--max-time", "2", "--noproxy", "*", &wait.url])
            .output()
            .is_ok_and(|out| out.stdout.first() == Some(&b'2'));
        if answered {
            return applied(0);
        }
        if Instant::now() >= until {
            return failed("http.wait_timeout");
        }
        std::thread::sleep(Duration::from_millis(200));
    }
}
