//! One socket-activated, typed root action. No observer or caller-selected executable.
#[cfg(any(target_os = "linux", test))]
use crate::command::{run, Command, CommandError, Context, Outcome, Platform, Privilege};
#[cfg(any(target_os = "linux", test))]
use serde::Deserialize;
#[cfg(any(target_os = "linux", test))]
use serde_json::{json, value::RawValue};
#[cfg(any(target_os = "linux", test))]
use std::io::{BufRead, BufReader, Read, Write};

pub const WORKER: &str = "orbit-swamp-worker";
pub const SOCKET_PATH: &str = "/run/orbit-agent/local.sock";
pub const SOCKET_UNIT: &str = "orbit-agent-local.socket";
pub const SERVICE_UNIT: &str = "orbit-agent-local@.service";
pub const REQUEST_LIMIT: u64 = 64 * 1024;
pub const REPLY_LIMIT: usize = 16 * 1024;
pub const SOCKET: &str = include_str!("../units/orbit-agent-local.socket");
pub const SERVICE: &str = include_str!("../units/orbit-agent-local@.service");

#[cfg(any(target_os = "linux", test))]
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {
    command: String,
    agent_sha256: String,
    input: Box<RawValue>,
}
#[cfg(any(target_os = "linux", test))]
fn refused(code: &str) -> Outcome {
    Outcome::refused(code, "Local agent action refused.", json!({}))
}
#[cfg(any(target_os = "linux", test))]
fn invoke<C: Command>(input: &RawValue) -> Outcome {
    if !matches!(
        C::privilege(Platform::Linux),
        Some(Privilege::Root | Privilege::Owner)
    ) {
        return refused("agent.local_action_forbidden");
    }
    let Ok(input) = serde_json::from_str::<C::Input>(input.get()) else {
        return refused("agent.invalid_input");
    };
    let context = Context::new(Platform::Linux, std::time::Instant::now() + C::TIMEOUT);
    match C::run(input, &context) {
        Ok(output) => serde_json::to_value(output)
            .map(Outcome::Ok)
            .unwrap_or_else(|_| refused("agent.local_reply_invalid")),
        Err(error) => refused(error.code()),
    }
}
#[cfg(any(target_os = "linux", test))]
fn dispatch(frame: &[u8], host: &run::Host, _started: std::time::Instant) -> Outcome {
    let Ok(request) = serde_json::from_slice::<Request>(frame) else {
        return refused("agent.envelope_invalid");
    };
    if request.agent_sha256.len() != 64
        || !request
            .agent_sha256
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
    {
        return refused("agent.envelope_invalid");
    }
    if host.executable_sha256.as_ref().ok() != Some(&request.agent_sha256) {
        return refused("agent.version_mismatch");
    }
    if host.platform != Platform::Linux || host.euid != 0 {
        return refused("agent.identity_mismatch");
    }
    match request.command.as_str() {
        "instance.prepare-served-web-roots" => {
            invoke::<crate::commands::production::PrepareServedWebRoots>(&request.input)
        }
        #[cfg(target_os = "linux")]
        "instance.run-step" => {
            let Ok(input) = serde_json::from_str(request.input.get()) else {
                return refused("agent.invalid_input");
            };
            match crate::commands::deploy_step::local::run(input, _started) {
                Ok(output) => serde_json::to_value(output)
                    .map(Outcome::Ok)
                    .unwrap_or_else(|_| refused("agent.local_reply_invalid")),
                Err(code) => refused(code),
            }
        }
        _ => refused("agent.local_action_forbidden"),
    }
}
#[cfg(any(target_os = "linux", test))]
fn frame(reader: impl Read) -> Result<Vec<u8>, ()> {
    let mut value = Vec::new();
    BufReader::new(reader)
        .take(REQUEST_LIMIT + 1)
        .read_until(b'\n', &mut value)
        .map_err(|_| ())?;
    if value.len() as u64 > REQUEST_LIMIT || value.last() != Some(&b'\n') {
        return Err(());
    }
    Ok(value)
}
#[cfg(any(target_os = "linux", test))]
fn reply(outcome: Outcome) -> Vec<u8> {
    let mut value = serde_json::to_vec(&outcome.to_json()).unwrap_or_default();
    if value.len() + 1 > REPLY_LIMIT || value.is_empty() {
        value = serde_json::to_vec(&refused("agent.local_reply_invalid").to_json()).unwrap();
    }
    value.push(b'\n');
    value
}

#[cfg(target_os = "linux")]
pub fn main() -> i32 {
    let started = std::time::Instant::now();
    use std::os::fd::{AsRawFd, FromRawFd};
    use std::os::unix::net::UnixStream;
    let Some(worker) = crate::command::identity::passwd(WORKER).filter(|p| p.uid != 0) else {
        return 77;
    };
    let mut peer = std::mem::MaybeUninit::<libc::ucred>::uninit();
    let mut length = std::mem::size_of::<libc::ucred>() as libc::socklen_t;
    // SAFETY: peer and length point at initialized storage of the requested size.
    let valid = unsafe {
        libc::getsockopt(
            0,
            libc::SOL_SOCKET,
            libc::SO_PEERCRED,
            peer.as_mut_ptr().cast(),
            &mut length,
        ) == 0
    };
    if !valid || length as usize != std::mem::size_of::<libc::ucred>() {
        return 77;
    }
    // SAFETY: successful getsockopt wrote the complete ucred above.
    if unsafe { peer.assume_init() }.uid != worker.uid {
        return 77;
    }
    // Keep the reply channel private even when a trusted primitive starts children.
    // SAFETY: fd0 is the authenticated connected socket; CLOEXEC prevents child access.
    let descriptor = unsafe { libc::fcntl(0, libc::F_DUPFD_CLOEXEC, 3) };
    if descriptor < 0 {
        return 74;
    }
    // SAFETY: fcntl returned a new owned descriptor above.
    let mut stream = unsafe { UnixStream::from_raw_fd(descriptor) };
    let Ok(null) = std::fs::OpenOptions::new()
        .read(true)
        .write(true)
        .open("/dev/null")
    else {
        return 74;
    };
    for descriptor in [0, 1, 2] {
        // SAFETY: both descriptors are valid; children inherit only /dev/null stdio.
        if unsafe { libc::dup2(null.as_raw_fd(), descriptor) } < 0 {
            return 74;
        }
    }
    let limit = Some(std::time::Duration::from_secs(5));
    if stream
        .set_read_timeout(limit)
        .and_then(|()| stream.set_write_timeout(limit))
        .is_err()
    {
        return 74;
    }
    let outcome = match frame(&stream) {
        Ok(value) => dispatch(&value, &run::Host::current(), started),
        Err(()) => refused("agent.envelope_invalid"),
    };
    if stream.write_all(&reply(outcome)).is_err() {
        return 74;
    }
    0
}
#[cfg(not(target_os = "linux"))]
pub fn main() -> i32 {
    69
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn one_bounded_newline_frame() {
        assert!(frame(&b"{}\nsecond\n"[..]).is_ok());
        assert!(frame(&b"{}"[..]).is_err());
        assert!(frame(vec![b'x'; REQUEST_LIMIT as usize + 1].as_slice()).is_err());
        assert!(reply(Outcome::Ok(json!({"large": "x".repeat(REPLY_LIMIT)}))).len() <= REPLY_LIMIT);
    }
    #[test]
    fn unknown_and_malformed_requests_never_dispatch() {
        let host = run::Host {
            platform: Platform::Linux,
            euid: 0,
            executable_sha256: Ok("a".repeat(64)),
            executable: "unused".into(),
            supervised: false,
            sudo_user: None,
        };
        for value in [
            json!({"command":"agent.install","agent_sha256":"a".repeat(64),"input":{}}),
            json!({"command":"instance.prepare-served-web-roots","agent_sha256":"a".repeat(64),"input":{},"executable":"/bin/sh"}),
        ] {
            assert!(matches!(
                dispatch(
                    &serde_json::to_vec(&value).unwrap(),
                    &host,
                    std::time::Instant::now()
                ),
                Outcome::Refused { .. }
            ));
        }
    }
}
