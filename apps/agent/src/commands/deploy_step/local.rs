//! Private material stays in the root handler. Only the admitted app program and environment cross the UID boundary.
use super::super::production::{Layout, ReleaseHome};
use crate::{command::identity, logs::redact::Redactor, AgentSecret, Config, SECRET_PATH};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    ffi::CString,
    fs::{File, OpenOptions},
    io::Write,
    os::{
        fd::{AsRawFd, FromRawFd},
        unix::{
            fs::{MetadataExt, OpenOptionsExt},
            process::CommandExt,
        },
    },
    process::{Command, Stdio},
    time::{Duration, Instant},
};
use tokio::io::AsyncReadExt;

const MATERIAL_LIMIT: usize = 2 * 1024 * 1024;
const OUTPUT_LIMIT: usize = 2048;
const LINE_LIMIT: usize = 4096;
const CAPTURE_LIMIT: usize = 1024 * 1024;
const SECRET_LIMIT: usize = 4096;
const REDACTION_WORK_LIMIT: usize = 16 * 1024 * 1024;
const REDACTED_LINE_LIMIT: usize = 8192;
const ACTION_LIMIT: u64 = 300;
// Shared service/client are 310s. Reserve 5s for cleanup and the bounded reply.
const HANDLER_LIMIT: u64 = 305;
type Result<T> = std::result::Result<T, &'static str>;

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct Input {
    operation: u64,
    step: String,
    digest: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Envelope {
    data: Material,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Material {
    operation: u64,
    step: String,
    digest: String,
    instance: u64,
    project: u64,
    repository: String,
    relative_root: String,
    release: String,
    timeout_seconds: u64,
    script: String,
    environment: BTreeMap<String, String>,
}
#[derive(Serialize, Debug)]
pub(crate) struct Output {
    exit_code: i32,
    timed_out: bool,
    output: String,
    truncated: bool,
}
fn digest(value: &str) -> String {
    format!("{:x}", Sha256::digest(value.as_bytes()))
}
fn hex(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
fn name(value: &str, limit: usize) -> bool {
    !value.is_empty()
        && value.len() <= limit
        && value.as_bytes()[0].is_ascii_alphanumeric()
        && value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b))
}
impl Input {
    fn validate(&self) -> Result<()> {
        let Some((phase, step)) = self.step.split_once('.') else {
            return Err("agent.invalid_input");
        };
        if self.operation == 0
            || !hex(&self.digest)
            || !name(step, 80)
            || !matches!(phase, "before_activation" | "after_activation" | "setup")
            || step.contains('.')
        {
            return Err("agent.invalid_input");
        }
        Ok(())
    }
}
impl Material {
    fn validate(&self, input: &Input, deadline: Instant) -> Result<()> {
        if self.operation != input.operation
            || self.step != input.step
            || self.digest != input.digest
            || digest(&self.script) != input.digest
            || self.script.is_empty()
            || self.script.len() > 16 * 1024
            || self.script.contains('\0')
            || self.instance == 0
            || self.project == 0
            || !name(&self.release, 128)
            || self.release == "."
            || self.release == ".."
            || self.environment.len() > 1024
            || self.repository.is_empty()
            || self.repository.len() > 4096
        {
            return Err("instance.step_not_admitted");
        }
        self.deadline(deadline)?;
        let mut bytes = 0;
        for (key, value) in &self.environment {
            let first = key.as_bytes().first().copied().unwrap_or(0);
            if key.len() > 255
                || !(first.is_ascii_alphabetic() || first == b'_')
                || !key.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
                || key.starts_with("LD_")
                || key.starts_with("BASH_")
                || value.len() > 65_536
                || value.contains('\0')
            {
                return Err("instance.step_not_admitted");
            }
            bytes += key.len() + value.len() + 2;
        }
        if bytes > 1024 * 1024 {
            return Err("instance.step_not_admitted");
        }
        Ok(())
    }
    fn deadline(&self, deadline: Instant) -> Result<()> {
        if !(1..=ACTION_LIMIT).contains(&self.timeout_seconds)
            || deadline.saturating_duration_since(Instant::now())
                < Duration::from_secs(self.timeout_seconds)
        {
            return Err("instance.step_deadline_unsupported");
        }
        Ok(())
    }
    fn home(&self, layout: &Layout) -> ReleaseHome {
        let user = format!("orbit-app-{}", self.project);
        ReleaseHome {
            repository: self.repository.clone(),
            home: layout.home_root.join(&user),
            user,
            instance: self.instance,
            relative_root: self.relative_root.clone(),
            application_suffix: String::new(),
            served: Vec::new(),
        }
    }
}
async fn material(input: &Input) -> Result<Material> {
    let config = Config::load().map_err(|_| "instance.step_material_unavailable")?;
    let secret =
        AgentSecret::load(SECRET_PATH).map_err(|_| "instance.step_material_unavailable")?;
    let client = crate::gateway_client(config.gateway_address, &secret)
        .map_err(|_| "instance.step_material_unavailable")?;
    let response = client
        .get(format!(
            "https://gateway.orbit/api/v1/agent/operations/{}/step",
            input.operation
        ))
        .query(&[("step", &input.step), ("digest", &input.digest)])
        .send()
        .await
        .map_err(|_| "instance.step_material_unavailable")?;
    read_material(response).await
}
async fn read_material(mut response: reqwest::Response) -> Result<Material> {
    if !response.status().is_success()
        || response
            .content_length()
            .is_some_and(|n| n > MATERIAL_LIMIT as u64)
        || !response
            .headers()
            .get(reqwest::header::CACHE_CONTROL)
            .and_then(|v| v.to_str().ok())
            .is_some_and(|v| v.split(',').any(|item| item.trim() == "no-store"))
    {
        return Err("instance.step_material_unavailable");
    }
    let mut body = Vec::new();
    while let Some(chunk) = response
        .chunk()
        .await
        .map_err(|_| "instance.step_material_unavailable")?
    {
        if body.len() + chunk.len() > MATERIAL_LIMIT {
            return Err("instance.step_material_unavailable");
        }
        body.extend_from_slice(&chunk);
    }
    serde_json::from_slice::<Envelope>(&body)
        .map(|v| v.data)
        .map_err(|_| "instance.step_material_unavailable")
}

// Each component is opened relative to its already-open parent, with no symlink traversal.
fn directory(parent: &File, component: &str, uid: u32, gid: u32) -> Result<File> {
    let name = CString::new(component).map_err(|_| "instance.step_layout_invalid")?;
    // SAFETY: parent is live; the name is NUL terminated, flags require a real directory.
    let fd = unsafe {
        libc::openat(
            parent.as_raw_fd(),
            name.as_ptr(),
            libc::O_RDONLY | libc::O_DIRECTORY | libc::O_NOFOLLOW | libc::O_CLOEXEC,
        )
    };
    if fd < 0 {
        return Err("instance.step_layout_invalid");
    }
    // SAFETY: openat returned a new owned descriptor.
    let file = unsafe { File::from_raw_fd(fd) };
    let meta = file
        .metadata()
        .map_err(|_| "instance.step_layout_invalid")?;
    if meta.uid() != uid || meta.gid() != gid || meta.mode() & 0o022 != 0 {
        return Err("instance.step_layout_invalid");
    }
    Ok(file)
}
fn release(material: &Material, layout: &Layout, uid: u32, gid: u32) -> Result<File> {
    let home = material.home(layout);
    home.check(layout, false)
        .map_err(|_| "instance.step_layout_invalid")?;
    let root = OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_DIRECTORY | libc::O_NOFOLLOW | libc::O_CLOEXEC)
        .open(&layout.home_root)
        .map_err(|_| "instance.step_layout_invalid")?;
    let metadata = root
        .metadata()
        .map_err(|_| "instance.step_layout_invalid")?;
    if metadata.uid() != layout.root_uid || metadata.mode() & 0o022 != 0 {
        return Err("instance.step_layout_invalid");
    }
    let home = directory(&root, &home.user, uid, gid)?;
    let releases = directory(&home, "releases", uid, gid)?;
    directory(&releases, &material.release, uid, gid)
}
fn program(script: &str, uid: u32, gid: u32) -> Result<File> {
    let label = c"orbit-admitted-step";
    // SAFETY: fixed NUL terminated label and valid memfd flags.
    let fd =
        unsafe { libc::memfd_create(label.as_ptr(), libc::MFD_CLOEXEC | libc::MFD_ALLOW_SEALING) };
    if fd < 0 {
        return Err("instance.step_start_failed");
    }
    // SAFETY: memfd_create returned a new owned descriptor.
    let mut file = unsafe { File::from_raw_fd(fd) };
    file.write_all(script.as_bytes())
        .map_err(|_| "instance.step_start_failed")?;
    // SAFETY: valid owned descriptor; the admitted non-root account alone may read it.
    let valid = unsafe {
        libc::fchown(fd, uid, gid) == 0
            && libc::fchmod(fd, 0o400) == 0
            && libc::fcntl(
                fd,
                libc::F_ADD_SEALS,
                libc::F_SEAL_WRITE | libc::F_SEAL_GROW | libc::F_SEAL_SHRINK | libc::F_SEAL_SEAL,
            ) == 0
    };
    if !valid {
        return Err("instance.step_start_failed");
    }
    Ok(file)
}
fn admitted_account(account: identity::Passwd, home: &ReleaseHome) -> Result<identity::Passwd> {
    if account.uid == 0 || account.gid == 0 || account.home != home.home.to_string_lossy() {
        return Err("instance.step_layout_invalid");
    }
    Ok(account)
}
fn assert_app_identity(uid: u32, gid: u32) -> std::io::Result<()> {
    let (mut real_uid, mut effective_uid, mut saved_uid) = (0, 0, 0);
    let (mut real_gid, mut effective_gid, mut saved_gid) = (0, 0, 0);
    // SAFETY: all six outputs point at live scalar storage.
    let read = unsafe {
        libc::getresuid(&mut real_uid, &mut effective_uid, &mut saved_uid) == 0
            && libc::getresgid(&mut real_gid, &mut effective_gid, &mut saved_gid) == 0
    };
    if !read
        || uid == 0
        || gid == 0
        || [real_uid, effective_uid, saved_uid] != [uid; 3]
        || [real_gid, effective_gid, saved_gid] != [gid; 3]
    {
        return Err(std::io::Error::from_raw_os_error(libc::EPERM));
    }
    Ok(())
}
fn drop_app_identity(uid: u32, gid: u32) -> std::io::Result<()> {
    // SAFETY: no pointers except an empty groups array; the child permanently drops every ID.
    let dropped = unsafe {
        libc::setgroups(0, std::ptr::null()) == 0
            && libc::setresgid(gid, gid, gid) == 0
            && libc::setresuid(uid, uid, uid) == 0
            && libc::getgroups(0, std::ptr::null_mut()) == 0
    };
    if !dropped {
        return Err(std::io::Error::last_os_error());
    }
    Ok(())
}
struct Group(i32);
impl Drop for Group {
    fn drop(&mut self) {
        // SAFETY: the child created this fresh session/process group; never signal our own group.
        unsafe {
            libc::kill(-self.0, libc::SIGKILL);
        }
    }
}
#[derive(Default)]
struct Lines {
    pending: Vec<u8>,
    dropped: bool,
    redactor: Redactor,
}
struct Capture {
    output: String,
    truncated: bool,
    remaining: usize,
    secrets: Vec<String>,
    work_remaining: usize,
    deadline: Instant,
}
impl Capture {
    fn new(environment: &BTreeMap<String, String>) -> Self {
        let mut secrets = Vec::new();
        let mut disabled = false;
        'values: for value in environment.values() {
            for line in value.lines().filter(|line| !line.is_empty()) {
                if secrets.len() == SECRET_LIMIT {
                    disabled = true;
                    break 'values;
                }
                secrets.push(line.to_owned());
            }
        }
        secrets.sort_by_key(|value| std::cmp::Reverse(value.len()));
        secrets.dedup();
        Self {
            output: String::new(),
            truncated: disabled,
            remaining: if disabled { 0 } else { CAPTURE_LIMIT },
            secrets,
            work_remaining: REDACTION_WORK_LIMIT,
            deadline: Instant::now(),
        }
    }
    fn stop_capture(&mut self) {
        self.truncated = true;
        self.remaining = 0;
    }
    fn charge(&mut self, work: usize) -> bool {
        if Instant::now() >= self.deadline || work > self.work_remaining {
            self.stop_capture();
            return false;
        }
        self.work_remaining -= work;
        true
    }
    fn line(&mut self, lines: &mut Lines) {
        if self.remaining == 0 || !self.charge(lines.pending.len().saturating_mul(32)) {
            self.truncated = true;
            lines.pending.clear();
            return;
        }
        if lines.dropped {
            self.truncated = true;
            self.remaining = 0;
            lines.dropped = false;
            lines.pending.clear();
            return;
        }
        let Ok(text) = std::str::from_utf8(&lines.pending) else {
            self.truncated = true;
            self.remaining = 0;
            lines.pending.clear();
            return;
        };
        if let Some(mut text) = lines.redactor.line(text) {
            for index in 0..self.secrets.len() {
                if text.len() > REDACTED_LINE_LIMIT
                    || !self.charge(text.len().saturating_mul(2) + self.secrets[index].len())
                {
                    self.stop_capture();
                    lines.pending.clear();
                    return;
                }
                let secret = &self.secrets[index];
                let growth = "[REDACTED]".len().saturating_sub(secret.len());
                if growth > 0
                    && text.len() + text.matches(secret).count() * growth > REDACTED_LINE_LIMIT
                {
                    self.stop_capture();
                    lines.pending.clear();
                    return;
                }
                text = text.replace(secret, "[REDACTED]");
            }
            if !self.charge(text.len()) {
                lines.pending.clear();
                return;
            }
            self.output.push_str(&text);
            self.output.push('\n');
            if self.output.len() > OUTPUT_LIMIT {
                let mut start = self.output.len() - OUTPUT_LIMIT;
                while !self.output.is_char_boundary(start) {
                    start += 1
                }
                self.output.drain(..start);
                self.truncated = true;
            }
        }
        lines.pending.clear();
    }
    fn bytes(&mut self, lines: &mut Lines, bytes: &[u8]) {
        for &byte in bytes {
            if self.remaining == 0 || Instant::now() >= self.deadline {
                self.stop_capture();
                lines.pending.clear();
                return;
            }
            self.remaining -= 1;
            if byte == b'\n' {
                self.line(lines);
            } else if lines.pending.len() < LINE_LIMIT && !lines.dropped {
                lines.pending.push(byte);
            } else {
                lines.pending.clear();
                lines.dropped = true;
            }
        }
    }
}
async fn execute(
    material: &Material,
    uid: u32,
    gid: u32,
    cwd: File,
    deadline: Instant,
    #[cfg(test)] skip_identity_drop: bool,
) -> Result<Output> {
    // This check precedes even the private memfd creation. No timeout is ever shortened.
    material.deadline(deadline)?;
    if uid == 0 || gid == 0 {
        return Err("instance.step_layout_invalid");
    }
    let mut capture = Capture::new(&material.environment);
    material.deadline(deadline)?;
    let script = program(&material.script, uid, gid)?;
    let home = format!("/home/orbit-app-{}", material.project);
    let user = format!("orbit-app-{}", material.project);
    let mut process = Command::new("/bin/bash");
    process
        .args(["--noprofile", "--norc", "-p", "-eu", "/proc/self/fd/3"])
        .env_clear()
        .envs(&material.environment)
        .env("HOME", home)
        .env("USER", &user)
        .env("LOGNAME", &user)
        .env("PATH", "/usr/local/bin:/usr/bin:/bin")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    let directory_fd = cwd.as_raw_fd();
    let script_fd = script.as_raw_fd();
    // SAFETY: the closure calls only async-signal-safe libc functions and uses live descriptors/scalars.
    unsafe {
        process.pre_exec(move || {
            let fail = || std::io::Error::last_os_error();
            if libc::setsid() < 0 {
                return Err(fail());
            }
            #[cfg(test)]
            if !skip_identity_drop {
                drop_app_identity(uid, gid)?;
            }
            #[cfg(not(test))]
            drop_app_identity(uid, gid)?;
            assert_app_identity(uid, gid)?;
            if libc::prctl(libc::PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0
                || libc::fchdir(directory_fd) != 0
            {
                return Err(fail());
            }
            if script_fd != 3 && libc::dup2(script_fd, 3) < 0 {
                return Err(fail());
            }
            if libc::fcntl(3, libc::F_SETFD, 0) < 0 {
                return Err(fail());
            }
            // Keep std's exec-error pipe until exec, but never inherit unrelated descriptors, including the socket.
            if libc::syscall(
                libc::SYS_close_range,
                4u32,
                u32::MAX,
                libc::CLOSE_RANGE_CLOEXEC,
            ) != 0
            {
                return Err(fail());
            }
            libc::umask(0o077);
            Ok(())
        });
    }
    let mut command = tokio::process::Command::from(process);
    command.kill_on_drop(true);
    let step_deadline = Instant::now() + Duration::from_secs(material.timeout_seconds);
    capture.deadline = step_deadline;
    let mut child = command.spawn().map_err(|_| "instance.step_start_failed")?;
    let group = Group(child.id().ok_or("instance.step_start_failed")? as i32);
    drop(script);
    drop(cwd);
    let mut stdout = child.stdout.take().ok_or("instance.step_start_failed")?;
    let mut stderr = child.stderr.take().ok_or("instance.step_start_failed")?;
    let (mut out_lines, mut err_lines) = (Lines::default(), Lines::default());
    let (mut out_buffer, mut err_buffer) = ([0u8; 4096], [0u8; 4096]);
    let (mut out_open, mut err_open) = (true, true);
    let stop = tokio::time::sleep_until(tokio::time::Instant::from_std(step_deadline));
    tokio::pin!(stop);
    let (status, timed_out) = loop {
        if Instant::now() >= step_deadline {
            drop(group);
            break (
                child
                    .wait()
                    .await
                    .map_err(|_| "instance.step_start_failed")?,
                true,
            );
        }
        tokio::select! {
            biased;
            () = &mut stop => { drop(group); let status = child.wait().await.map_err(|_| "instance.step_start_failed")?; break (status, true) },
            status = child.wait() => { drop(group); break (status.map_err(|_| "instance.step_start_failed")?, false) },
            read = stdout.read(&mut out_buffer), if out_open => match read {
                Ok(0) => out_open = false, Ok(n) => capture.bytes(&mut out_lines, &out_buffer[..n]), Err(_) => out_open = false,
            },
            read = stderr.read(&mut err_buffer), if err_open => match read {
                Ok(0) => err_open = false, Ok(n) => capture.bytes(&mut err_lines, &err_buffer[..n]), Err(_) => err_open = false,
            },
        }
    };
    // A killed or escaped descendant may retain a pipe. Cleanup never waits on it without a bound.
    for (pipe, buffer, lines) in [
        (
            &mut stdout as &mut (dyn tokio::io::AsyncRead + Unpin),
            &mut out_buffer,
            &mut out_lines,
        ),
        (
            &mut stderr as &mut (dyn tokio::io::AsyncRead + Unpin),
            &mut err_buffer,
            &mut err_lines,
        ),
    ] {
        let drained = tokio::time::timeout(Duration::from_millis(50), async {
            while capture.remaining > 0 {
                match pipe.read(buffer).await {
                    Ok(0) => return true,
                    Ok(n) => capture.bytes(lines, &buffer[..n]),
                    Err(_) => return false,
                }
            }
            false
        })
        .await
        .unwrap_or(false);
        // Never publish a fragment: the rest of a secret may be unread or killed before its newline.
        if !drained || !lines.pending.is_empty() || lines.dropped {
            capture.truncated = true;
        }
        lines.pending.clear();
    }
    Ok(Output {
        exit_code: status.code().unwrap_or(128 + 9),
        timed_out,
        output: capture.output,
        truncated: capture.truncated,
    })
}

pub(crate) fn run(input: Input, started: Instant) -> Result<Output> {
    input.validate()?;
    let deadline = started + Duration::from_secs(HANDLER_LIMIT);
    let runtime =
        tokio::runtime::Runtime::new().map_err(|_| "instance.step_material_unavailable")?;
    runtime.block_on(async {
        let material = material(&input).await?;
        material.validate(&input, deadline)?;
        let layout = Layout::system();
        let home = material.home(&layout);
        let account = admitted_account(
            identity::passwd(&home.user).ok_or("instance.step_layout_invalid")?,
            &home,
        )?;
        let cwd = release(&material, &layout, account.uid, account.gid)?;
        execute(
            &material,
            account.uid,
            account.gid,
            cwd,
            deadline,
            #[cfg(test)]
            false,
        )
        .await
    })
}

#[cfg(test)]
mod tests;
