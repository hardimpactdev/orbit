//! The fixed service entry that runs one registered startup hook from its root-owned data.
use super::recipe::{self, Files};
use crate::command::{registry, Outcome, Platform};
use serde_json::{json, Value};
use std::{
    fs,
    io::Read,
    os::unix::fs::{MetadataExt, OpenOptionsExt},
    path::Path,
};
const DIRECTORY: &str = "/etc/orbit";
const MAX_FILE: u64 = 64 * 1024;

struct Unsafe;

fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Unsafe> {
    recipe::metadata(path).map_err(|_| Unsafe)
}
fn check_directory(files: &Files, path: &Path) -> Result<(), Unsafe> {
    for parent in path.ancestors() {
        if metadata(parent)?.is_some_and(|meta| {
            !meta.is_dir()
                || meta.uid() != files.uid
                || meta.gid() != files.gid
                || meta.mode() & 0o022 != 0
        }) {
            return Err(Unsafe);
        }
        if parent == files.root {
            break;
        }
    }
    Ok(())
}
fn read(files: &Files, path: &Path) -> Result<Option<(Vec<u8>, fs::Metadata)>, Unsafe> {
    let Some(meta) = metadata(path)? else {
        return Ok(None);
    };
    if !meta.is_file()
        || meta.uid() != files.uid
        || meta.gid() != files.gid
        || meta.mode() & 0o022 != 0
        || meta.len() > MAX_FILE
        || meta.nlink() != 1
    {
        return Err(Unsafe);
    }
    let mut file = fs::OpenOptions::new()
        .read(true)
        .custom_flags(libc::O_NOFOLLOW | libc::O_NONBLOCK)
        .open(path)
        .map_err(|_| Unsafe)?;
    let opened = file.metadata().map_err(|_| Unsafe)?;
    if opened.dev() != meta.dev() || opened.ino() != meta.ino() {
        return Err(Unsafe);
    }
    let mut bytes = Vec::new();
    (&mut file)
        .take(MAX_FILE + 1)
        .read_to_end(&mut bytes)
        .map_err(|_| Unsafe)?;
    if bytes.len() >= MAX_FILE as usize {
        return Err(Unsafe);
    }
    Ok(Some((bytes, meta)))
}
/// A fixed service name loads only its registered, root-owned data, then uses normal supervision.
pub fn service(arguments: &[String]) -> i32 {
    let [name] = arguments else {
        return 64;
    };
    let Some(hook) = registry::hooks().iter().find(|hook| hook.command == name) else {
        return 64;
    };
    if Platform::current() != Platform::Linux || crate::command::identity::effective_uid() != 0 {
        return 77;
    }
    let files = Files::system();
    let path = files.path(&format!("{DIRECTORY}/{}.json", hook.name));
    let input = (|| {
        check_directory(&files, path.parent().unwrap())?;
        let (bytes, meta) = read(&files, &path)?.ok_or(Unsafe)?;
        if meta.mode() & 0o7777 != 0o600 {
            return Err(Unsafe);
        }
        serde_json::from_slice::<Value>(&bytes).map_err(|_| Unsafe)
    })();
    let Ok(input) = input else {
        eprintln!("orbit-agent: startup configuration is unavailable");
        return 1;
    };
    let host = crate::command::run::Host::current();
    let Ok(digest) = &host.executable_sha256 else {
        return 1;
    };
    let envelope =
        serde_json::to_vec(&json!({"agent_sha256":digest,"deadline_ms":60000,"input":input}))
            .expect("service envelope serializes");
    match crate::command::run::execute(name, &envelope, &host) {
        Outcome::Ok(_) => 0,
        Outcome::Refused { code, .. } => {
            eprintln!("orbit-agent: startup hook refused: {code}");
            1
        }
    }
}
