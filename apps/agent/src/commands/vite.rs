//! The Node half of a development Instance's Vite server: choosing a free port, checking that the Instance's own
//! systemd unit owns the listener and serves the Vite client, the hibernation awake marker that lets traffic through,
//! and the root-owned environment file that hands the unit its port.

use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::BTreeSet,
    fs,
    io::{self, Read, Write},
    net::{TcpListener, TcpStream},
    os::unix::fs::{DirBuilderExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    process::{Command as Process, Stdio},
    time::Duration,
};

command_errors! {
    pub enum ViteError {
        Exhausted {} => "vite.ports_exhausted", "No available Vite port remains on this Node.";
        Failed { step: String } => "vite.node_failed", "A Vite step failed on the Node.";
    }
}

fn failed(step: &str) -> ViteError {
    ViteError::Failed { step: step.into() }
}

/// Ports that a TCP socket holds, as `/proc/net/tcp` lists them.
fn ports_in_use() -> BTreeSet<u16> {
    let mut ports = BTreeSet::new();
    for table in ["/proc/net/tcp", "/proc/net/tcp6"] {
        let Ok(text) = fs::read_to_string(table) else {
            continue;
        };
        for row in text.lines().skip(1) {
            let fields: Vec<&str> = row.split_whitespace().collect();
            // State 06 is TIME_WAIT, which does not hold the port for a new listener.
            if fields.get(3) == Some(&"06") {
                continue;
            }
            if let Some(port) = fields
                .get(1)
                .and_then(|local| local.rsplit(':').next())
                .and_then(|hex| u16::from_str_radix(hex, 16).ok())
            {
                ports.insert(port);
            }
        }
    }
    ports
}

/// Whether a listener can take `port` on every address. A dual-stack IPv6 bind covers IPv4 too; without IPv6 the
/// IPv4 bind decides.
fn bindable(port: u16) -> io::Result<bool> {
    let attempt = |address: &str| match TcpListener::bind((address, port)) {
        Ok(_) => Ok(true),
        Err(error) if error.kind() == io::ErrorKind::AddrInUse => Ok(false),
        Err(error) => Err(error),
    };
    match attempt("::") {
        Err(error)
            if matches!(
                error.raw_os_error(),
                Some(libc::EAFNOSUPPORT) | Some(libc::EADDRNOTAVAIL)
            ) =>
        {
            attempt("0.0.0.0")
        }
        other => other,
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PortInput {
    pub preferred: u16,
    pub excluded: Vec<u16>,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Port {
    pub port: u16,
}

pub fn select_port(input: &PortInput) -> Result<Port, ViteError> {
    let mut excluded: BTreeSet<u16> = input.excluded.iter().copied().collect();
    excluded.extend(ports_in_use());
    for port in input.preferred..=u16::MAX {
        if excluded.contains(&port) {
            continue;
        }
        if bindable(port).map_err(|_| failed("vite-port-check"))? {
            return Ok(Port { port });
        }
    }
    Err(ViteError::Exhausted {})
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ListenerInput {
    pub unit: String,
    pub port: u16,
    pub process_id: u64,
    /// Also ask the listener for the Vite client, and check ownership again after the answer.
    pub probe: bool,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Listener {
    pub owned: bool,
    pub ready: bool,
}

fn output(program: &str, arguments: &[&str]) -> Option<String> {
    let output = Process::new(program)
        .args(arguments)
        .stdin(Stdio::null())
        .stderr(Stdio::null())
        .output()
        .ok()?;
    output
        .status
        .success()
        .then(|| String::from_utf8_lossy(&output.stdout).into_owned())
}

/// Whether every process listening on `port` belongs to the unit that Orbit wrote for this Process.
fn owned(input: &ListenerInput) -> Result<bool, ViteError> {
    let step = "vite-listener-owner";
    if input.unit.contains('/') {
        return Err(failed(step));
    }
    let Ok(text) = fs::read_to_string(Path::new("/etc/systemd/system").join(&input.unit)) else {
        return Ok(false);
    };
    let marker = format!("X-Orbit-Process-ID={}", input.process_id);
    if text.lines().filter(|line| *line == marker).count() != 1 {
        return Ok(false);
    }
    let group = output(
        "systemctl",
        &["show", "--property=ControlGroup", "--value", &input.unit],
    )
    .ok_or_else(|| failed(step))?
    .trim()
    .to_string();
    let filter = format!("sport = :{}", input.port);
    let rows = output("ss", &["-H", "-ltnp", &filter]).ok_or_else(|| failed(step))?;
    let rows: Vec<&str> = rows.lines().collect();
    if group.is_empty() || rows.is_empty() {
        return Ok(false);
    }
    let pid = regex::Regex::new(r"pid=(\d+)").expect("the pattern compiles");
    for row in rows {
        let pids: Vec<&str> = pid
            .captures_iter(row)
            .map(|capture| capture.get(1).map_or("", |m| m.as_str()))
            .collect();
        if pids.is_empty() {
            return Ok(false);
        }
        for pid in pids {
            let Ok(cgroups) = fs::read_to_string(format!("/proc/{pid}/cgroup")) else {
                return Ok(false);
            };
            let inside = cgroups.lines().any(|line| {
                line.splitn(3, ':')
                    .nth(2)
                    .is_some_and(|value| value == group || value.starts_with(&format!("{group}/")))
            });
            if !inside {
                return Ok(false);
            }
        }
    }
    Ok(true)
}

/// Whether the listener answers the Vite client with 200, without a proxy.
fn serves_client(port: u16) -> bool {
    let answer = (|| -> io::Result<String> {
        let mut stream =
            TcpStream::connect_timeout(&([127, 0, 0, 1], port).into(), Duration::from_secs(1))?;
        stream.set_read_timeout(Some(Duration::from_secs(1)))?;
        stream.set_write_timeout(Some(Duration::from_secs(1)))?;
        let request =
            format!("GET /__orbit/vite/@vite/client HTTP/1.0\r\nHost: 127.0.0.1:{port}\r\n\r\n");
        stream.write_all(request.as_bytes())?;
        // The status line, which may arrive in pieces.
        let mut head = Vec::new();
        let mut byte = [0; 1];
        while !head.ends_with(b"\r\n") && head.len() < 64 && stream.read(&mut byte)? == 1 {
            head.push(byte[0]);
        }
        Ok(String::from_utf8_lossy(&head).into_owned())
    })();
    answer.is_ok_and(|head| {
        head.split_whitespace().nth(1) == Some("200") && head.starts_with("HTTP/")
    })
}

pub fn check_listener(input: &ListenerInput) -> Result<Listener, ViteError> {
    if !owned(input)? {
        return Ok(Listener {
            owned: false,
            ready: false,
        });
    }
    let ready = input.probe && serves_client(input.port) && owned(input)?;
    Ok(Listener { owned: true, ready })
}

#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum MarkerInput {
    /// Removes the awake marker, so the Instance's traffic waits.
    Suspend { marker: PathBuf },
    /// Creates the awake marker, readable by Caddy, in its root-owned directory.
    Awake { marker: PathBuf },
    /// Writes the environment file with the Vite port, after checks of the application, and suspends traffic.
    Environment {
        application: PathBuf,
        path: PathBuf,
        instance_id: u64,
        marker: PathBuf,
        content: String,
    },
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Done {}

fn group_id(name: &str) -> Option<u32> {
    let name = std::ffi::CString::new(name).ok()?;
    // SAFETY: getgrnam reads a NUL-terminated name and returns null or a pointer valid until the next call.
    let group = unsafe { libc::getgrnam(name.as_ptr()) };
    // SAFETY: a non-null result points at a group entry.
    (!group.is_null()).then(|| unsafe { (*group).gr_gid })
}

fn remove(path: &Path) -> io::Result<()> {
    match fs::remove_file(path) {
        Err(error) if error.kind() != io::ErrorKind::NotFound => Err(error),
        _ => Ok(()),
    }
}

fn awake(marker: &Path) -> io::Result<()> {
    let directory = marker.parent().ok_or(io::ErrorKind::InvalidInput)?;
    if !directory.is_dir() {
        fs::DirBuilder::new().mode(0o755).create(directory)?;
    }
    let caddy = group_id("caddy").ok_or(io::ErrorKind::NotFound)?;
    rustix::fs::chown(
        directory,
        Some(rustix::fs::Uid::ROOT),
        Some(rustix::fs::Gid::from_raw(caddy)),
    )?;
    fs::set_permissions(directory, fs::Permissions::from_mode(0o755))?;
    fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(marker)?;
    fs::set_permissions(marker, fs::Permissions::from_mode(0o644))
}

fn environment(
    application: &Path,
    path: &Path,
    instance_id: u64,
    marker: &Path,
    content: &str,
) -> Result<(), ViteError> {
    let step = "vite-environment";
    let executable = fs::metadata("/usr/local/bin/vp")
        .is_ok_and(|metadata| metadata.permissions().mode() & 0o111 != 0);
    if !executable
        || !application.join("package.json").is_file()
        || !application.join("node_modules").is_dir()
    {
        return Err(failed(step));
    }
    let directory = path.parent().ok_or_else(|| failed(step))?;
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o755)
        .create(directory)
        .map_err(|_| failed(step))?;
    match fs::symlink_metadata(path) {
        Ok(metadata) if metadata.file_type().is_symlink() => return Err(failed(step)),
        Ok(_) => {
            let owned = fs::read_to_string(path).is_ok_and(|text| {
                text.lines()
                    .any(|line| line == format!("# Orbit Instance {instance_id}"))
            });
            if !owned {
                return Err(failed(step));
            }
        }
        Err(_) => {}
    }
    remove(marker).map_err(|_| failed(step))?;
    let mut pending = path.as_os_str().to_owned();
    pending.push(".pending");
    let pending = PathBuf::from(pending);
    let _ = fs::remove_file(&pending);
    let written = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&pending)
        .and_then(|mut file| {
            file.write_all(content.as_bytes())
                .and_then(|()| file.sync_all())
        })
        .and_then(|()| fs::rename(&pending, path));
    if written.is_err() {
        let _ = fs::remove_file(&pending);
        return Err(failed(step));
    }
    Ok(())
}

pub fn marker(input: &MarkerInput) -> Result<Done, ViteError> {
    match input {
        MarkerInput::Suspend { marker } => {
            remove(marker).map_err(|_| failed("vite-suspend-traffic"))?
        }
        MarkerInput::Awake { marker } => awake(marker).map_err(|_| failed("vite-mark-awake"))?,
        MarkerInput::Environment {
            application,
            path,
            instance_id,
            marker,
            content,
        } => environment(application, path, *instance_id, marker, content)?,
    }
    Ok(Done {})
}

pub struct SelectVitePort;

impl Command for SelectVitePort {
    const NAME: &'static str = "vite.select-port";
    const SUMMARY: &'static str =
        "Choose the first free TCP port from a preferred one, skipping excluded ports.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = PortInput;
    type Output = Port;
    type Error = ViteError;

    /// Binding an unprivileged port needs no other identity.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }

    fn run(input: PortInput, _: &Context) -> Result<Port, ViteError> {
        select_port(&input)
    }
}

pub struct CheckViteListener;

impl Command for CheckViteListener {
    const NAME: &'static str = "vite.check-listener";
    const SUMMARY: &'static str =
        "Check that a Process's unit owns its Vite listener, and that it serves the Vite client.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = ListenerInput;
    type Output = Listener;
    type Error = ViteError;

    /// Root reads the listeners' processes and their control groups.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: ListenerInput, _: &Context) -> Result<Listener, ViteError> {
        check_listener(&input)
    }
}

pub struct SetViteTraffic;

impl Command for SetViteTraffic {
    const NAME: &'static str = "vite.set-traffic";
    const SUMMARY: &'static str =
        "Suspend or allow a Vite Instance's traffic, or write its port environment.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(15);
    type Input = MarkerInput;
    type Output = Done;
    type Error = ViteError;

    /// The marker directory and the environment file belong to root.
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }

    fn run(input: MarkerInput, _: &Context) -> Result<Done, ViteError> {
        marker(&input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_held_port_is_skipped_and_excluded_ports_are_never_chosen() {
        let held = TcpListener::bind(("::", 0))
            .or_else(|_| TcpListener::bind(("0.0.0.0", 0)))
            .unwrap();
        let port = held.local_addr().unwrap().port();
        let chosen = select_port(&PortInput {
            preferred: port,
            excluded: vec![port + 1],
        })
        .unwrap()
        .port;
        assert!(chosen > port + 1);
    }

    #[test]
    fn the_client_probe_needs_a_200_answer() {
        let server = TcpListener::bind(("127.0.0.1", 0)).unwrap();
        let port = server.local_addr().unwrap().port();
        let answer = |status: &'static str| {
            let server = server.try_clone().unwrap();
            std::thread::spawn(move || {
                let (mut stream, _) = server.accept().unwrap();
                let mut request = Vec::new();
                let mut byte = [0; 1];
                while !request.ends_with(b"\r\n\r\n") && stream.read(&mut byte).unwrap_or(0) == 1 {
                    request.push(byte[0]);
                }
                let _ = write!(stream, "HTTP/1.1 {status}\r\nContent-Length: 0\r\n\r\n");
            })
        };
        let serving = answer("200 OK");
        assert!(serves_client(port));
        serving.join().unwrap();
        let missing = answer("404 Not Found");
        assert!(!serves_client(port));
        missing.join().unwrap();
    }

    #[test]
    fn the_environment_file_needs_a_vite_application_and_keeps_another_instance_s_file() {
        let root = std::env::temp_dir().join(format!("orbit-vite-{:016x}", rand::random::<u64>()));
        fs::create_dir_all(root.join("app")).unwrap();
        let path = root.join("vite/7.env");
        let marker = root.join("7.awake");
        let result = environment(&root.join("app"), &path, 7, &marker, "PORT\n");
        assert_eq!(result, Err(failed("vite-environment")));
        fs::create_dir_all(root.join("vite")).unwrap();
        fs::write(&path, "# Orbit Instance 8\n").unwrap();
        let other = environment(&root.join("app"), &path, 7, &marker, "PORT\n");
        assert_eq!(other, Err(failed("vite-environment")));
        assert_eq!(fs::read_to_string(&path).unwrap(), "# Orbit Instance 8\n");
        let _ = fs::remove_dir_all(&root);
    }
}
