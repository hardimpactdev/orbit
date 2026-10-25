//! Export a thin Git pack from a checkout, without credentials, network access, or repository changes.
use crate::command::{
    binary_output::{DeadlineWriter, Frames, CHUNK_BYTES},
    Command, Context, Platform, Privilege,
};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    collections::HashSet,
    io::{self, Read, Write},
    os::{fd::AsRawFd, unix::process::CommandExt},
    path::PathBuf,
    process::{Child, Command as Process, Stdio},
    time::Duration,
};

const MAX_PACK_BYTES: u64 = 2 * 1024 * 1024 * 1024;
const MAX_HAVES: usize = 1000;
command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.git_export_invalid", "The checkout, approved commit, or known commits are invalid.";
        MissingCommit {} => "task_vm.git_commit_missing", "The checkout does not contain the approved commit.";
        Failed {} => "task_vm.git_export_failed", "The checkout's Git pack could not be exported.";
        TooLarge {} => "task_vm.git_pack_too_large", "The task VM pack exceeds 2 GiB.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    checkout: PathBuf,
    commit: String,
    haves: Vec<String>,
}
#[derive(Debug, PartialEq, Serialize)]
pub struct Output {
    bytes: u64,
}

fn git(input: &Input, context: &Context, arguments: &[&str]) -> Process {
    let mut command = Process::new("/usr/bin/timeout");
    command.args([
        "--signal=TERM",
        "--kill-after=1",
        &format!("{:.6}s", context.remaining().as_secs_f64().max(0.001)),
        "/usr/bin/git",
    ]);
    super::git_read::GitRead::default().configure(&mut command);
    command
        .args(["-c", "protocol.allow=never", "-C"])
        .arg(&input.checkout)
        .args(arguments)
        .env("GIT_NO_LAZY_FETCH", "1");
    command
}

fn revisions(input: &Input, context: &Context) -> Result<String, Error> {
    if !super::doctor_route::absolute(&input.checkout)
        || !super::task_git::object_id(&input.commit)
        || input.haves.len() > MAX_HAVES
        || input.haves.iter().any(|id| !super::task_git::object_id(id))
    {
        return Err(Error::Invalid {});
    }
    if context.remaining().is_zero() {
        return Err(Error::Failed {});
    }
    let mut seen = HashSet::new();
    let ids: Vec<_> = std::iter::once(&input.commit)
        .chain(&input.haves)
        .filter(|id| seen.insert((*id).clone()))
        .cloned()
        .collect();
    let output = super::incus::capture_input(
        &mut git(
            input,
            context,
            &["cat-file", "--batch-check=%(objectname) %(objecttype)"],
        ),
        Some(&(ids.join("\n") + "\n")),
    )
    .map_err(|_| Error::Failed {})?;
    if output.code != 0 || output.truncated {
        return Err(Error::Failed {});
    }
    let types: Vec<_> = output.stdout.lines().collect();
    if types.len() != ids.len() {
        return Err(Error::Failed {});
    }
    if types[0] != format!("{} commit", input.commit) {
        return Err(Error::MissingCommit {});
    }
    let mut revisions = format!("{}\n", input.commit);
    let types: std::collections::HashMap<_, _> = ids.iter().zip(types).collect();
    seen.clear();
    for id in &input.haves {
        if seen.insert(id.clone())
            && types
                .get(id)
                .is_some_and(|kind| *kind == format!("{id} commit"))
        {
            revisions.push_str(&format!("^{id}\n"));
        }
    }
    Ok(revisions)
}

struct PackChild {
    process: Child,
    waited: bool,
}
impl Drop for PackChild {
    fn drop(&mut self) {
        if !self.waited {
            // SAFETY: the child started its own process group. Stop timeout, Git and its children together.
            unsafe {
                libc::kill(-(self.process.id() as libc::pid_t), libc::SIGKILL);
            }
            let _ = self.process.wait();
        }
    }
}

fn emit(input: &Input, context: &Context, output: impl Write, max: u64) -> Result<Output, Error> {
    let revisions = revisions(input, context)?;
    let child = git(
        input,
        context,
        &[
            "pack-objects",
            "--revs",
            "--thin",
            "--stdout",
            "--delta-base-offset",
            "--quiet",
        ],
    )
    .stdin(Stdio::piped())
    .stdout(Stdio::piped())
    .stderr(Stdio::null())
    .process_group(0)
    .spawn()
    .map_err(|_| Error::Failed {})?;
    let mut child = PackChild {
        process: child,
        waited: false,
    };
    child
        .process
        .stdin
        .take()
        .ok_or(Error::Failed {})?
        .write_all(revisions.as_bytes())
        .map_err(|_| Error::Failed {})?;
    let mut stdout = child.process.stdout.take().ok_or(Error::Failed {})?;
    let fd = stdout.as_raw_fd();
    // SAFETY: stdout owns this descriptor until the read loop ends.
    let flags = unsafe { libc::fcntl(fd, libc::F_GETFL) };
    if flags < 0 || unsafe { libc::fcntl(fd, libc::F_SETFL, flags | libc::O_NONBLOCK) } < 0 {
        return Err(Error::Failed {});
    }
    let mut framed = Frames::new(output, max);
    let mut buffer = vec![0; CHUNK_BYTES];
    loop {
        if context.remaining().is_zero() {
            return Err(Error::Failed {});
        }
        match stdout.read(&mut buffer) {
            Ok(0) => break,
            Ok(count) => framed.write_all(&buffer[..count]).map_err(|error| {
                if error.kind() == io::ErrorKind::FileTooLarge {
                    Error::TooLarge {}
                } else {
                    Error::Failed {}
                }
            })?,
            Err(error) if error.kind() == io::ErrorKind::Interrupted => continue,
            Err(error) if error.kind() == io::ErrorKind::WouldBlock => {
                let mut poll = libc::pollfd {
                    fd,
                    events: libc::POLLIN,
                    revents: 0,
                };
                // SAFETY: initialized pollfd with a short, bounded wait; timeout also bounds Git itself.
                unsafe {
                    libc::poll(&mut poll, 1, 10);
                }
            }
            Err(_) => return Err(Error::Failed {}),
        }
    }
    let status = child.process.wait().map_err(|_| Error::Failed {})?;
    child.waited = true;
    if !status.success() {
        return Err(Error::Failed {});
    }
    Ok(Output {
        bytes: framed.bytes(),
    })
}

pub struct ExportPack;
impl Command for ExportPack {
    const NAME: &'static str = "task-vms.export-git-pack";
    const SUMMARY: &'static str =
        "Stream a thin pack of the approved checkout commit to the Gateway.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(1800);
    const OUTPUT_MAX_BYTES: u64 = MAX_PACK_BYTES;
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        let output = DeadlineWriter::new(libc::STDOUT_FILENO, context.deadline())
            .map_err(|_| Error::Failed {})?;
        emit(&input, context, output, Self::OUTPUT_MAX_BYTES)
    }
}

#[cfg(test)]
#[path = "task_git_export_tests.rs"]
mod tests;
