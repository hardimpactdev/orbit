//! Materialize the Linux Vite+ scope and atomically publish its managed launchers.
use super::recipe::javascript::launcher;
use super::{
    recipe::{
        self, javascript,
        store::{self, Account},
        types::Input,
        Files, Host,
    },
    vp_scope,
};
use crate::command::{Command, Context, Platform, Privilege};
use serde::Serialize;
use std::{
    collections::BTreeMap,
    fs, io,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::{Duration, Instant},
};
crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.vp_materialize_invalid", "The Vite+ recipe or account is invalid.";
        Conflict {} => "tools.vp_materialize_conflict", "The Vite+ scope or a runtime launcher has conflicting ownership or content.";
        Failed {} => "tools.vp_materialize_failed", "The Vite+ manager could not be materialized.";
        Restore {} => "tools.vp_materialize_restore_failed", "The Vite+ runtime check failed and its previous launchers could not be restored.";
    }
}
type Result<T> = std::result::Result<T, Error>;
const BINARIES: [&str; 5] = ["vp", "node", "pnpm", "npm", "npx"];
fn failed(_: impl std::fmt::Debug) -> Error {
    Error::Failed {}
}
#[derive(Serialize)]
pub struct Output {
    pub binary: PathBuf,
    pub changed: bool,
}
struct Kept {
    bytes: Vec<u8>,
    accessed: std::time::SystemTime,
    modified: std::time::SystemTime,
}
fn snapshot(files: &Files, scope: &Path) -> Result<BTreeMap<PathBuf, Option<Kept>>> {
    let mut kept = BTreeMap::new();
    for binary in BINARIES {
        let path = files.path("/usr/local/bin").join(binary);
        let meta = match fs::symlink_metadata(&path) {
            Ok(meta) => meta,
            Err(error) if error.kind() == io::ErrorKind::NotFound => {
                kept.insert(path, None);
                continue;
            }
            Err(_) => return Err(Error::Conflict {}),
        };
        if !meta.is_file()
            || meta.uid() != files.uid
            || meta.gid() != files.gid
            || meta.mode() & 0o7777 != 0o755
        {
            return Err(Error::Conflict {});
        }
        let bytes = fs::read(&path).map_err(failed)?;
        let export = if scope == files.path("/opt/orbit/vite-plus") {
            format!("export VP_HOME={}\n", scope.display())
        } else {
            String::new()
        };
        let former = format!(
            "#!/bin/sh\n{export}exec \"{}/bin/{binary}\" \"$@\"\n",
            scope.display()
        );
        if bytes != launcher(scope, binary) && bytes != former.as_bytes() {
            return Err(Error::Conflict {});
        }
        kept.insert(
            path,
            Some(Kept {
                bytes,
                accessed: meta.accessed().map_err(failed)?,
                modified: meta.modified().map_err(failed)?,
            }),
        );
    }
    Ok(kept)
}
fn restore(files: &Files, kept: &BTreeMap<PathBuf, Option<Kept>>) -> Result<()> {
    for (path, previous) in kept {
        match previous {
            Some(previous) => {
                if fs::read(path).ok().as_deref() == Some(previous.bytes.as_slice()) {
                    continue;
                }
                files
                    .publish(path, &previous.bytes, 0o755, files.uid, files.gid, false)
                    .map_err(|_| Error::Restore {})?;
                fs::File::open(path)
                    .and_then(|file| {
                        file.set_times(
                            fs::FileTimes::new()
                                .set_accessed(previous.accessed)
                                .set_modified(previous.modified),
                        )
                    })
                    .map_err(|_| Error::Restore {})?;
            }
            None => match fs::remove_file(path) {
                Ok(()) => {}
                Err(error) if error.kind() == io::ErrorKind::NotFound => {}
                Err(_) => return Err(Error::Restore {}),
            },
        }
    }
    Ok(())
}
fn materialize(
    host: &mut impl Host,
    files: &Files,
    account: &Account,
    input: &Input,
    deadline: Instant,
) -> Result<Output> {
    input.validate().map_err(|_| Error::Invalid {})?;
    let architecture = if cfg!(target_arch = "aarch64") {
        "arm64"
    } else {
        "amd64"
    };
    if input.architecture.name() != architecture {
        return Err(Error::Invalid {});
    }
    if input.tools.len() != 4
        || ["vp", "vp-cli", "node", "pnpm"]
            .iter()
            .any(|name| !input.tools.contains_key(*name))
    {
        return Err(Error::Invalid {});
    }
    let scope = match vp_scope::inspect(
        Platform::Linux,
        &account.home,
        account.uid,
        account.gid,
        &files.path("/opt/orbit"),
        files.uid,
        files.gid,
    ) {
        Ok(observed) => observed
            .binary
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .to_path_buf(),
        Err(vp_scope::Error::Absent {}) => {
            store::scope(files, account).map_err(|_| Error::Conflict {})?
        }
        Err(_) => return Err(Error::Conflict {}),
    };
    let missing = !scope.join("bin/vp").exists();
    let kept = snapshot(files, &scope)?;
    let result = (|| {
        if missing {
            javascript::converge(host, files, input, account, deadline).map_err(failed)?;
        }
        for binary in BINARIES {
            if !fs::metadata(scope.join("bin").join(binary))
                .is_ok_and(|meta| meta.mode() & 0o111 != 0)
            {
                return Err(Error::Failed {});
            }
        }
        let mut changed = missing;
        for binary in BINARIES {
            let path = files.path("/usr/local/bin").join(binary);
            let bytes = launcher(&scope, binary);
            if fs::read(&path).ok().as_deref() != Some(bytes.as_slice()) {
                files
                    .publish(&path, &bytes, 0o755, files.uid, files.gid, false)
                    .map_err(failed)?;
                changed = true;
            }
        }
        for binary in BINARIES {
            recipe::run(
                host,
                &account.invocation(
                    &scope,
                    &[
                        files
                            .path("/usr/local/bin")
                            .join(binary)
                            .to_str()
                            .ok_or(Error::Invalid {})?,
                        "--version",
                    ],
                ),
                true,
            )
            .map_err(failed)?;
        }
        Ok(Output {
            binary: scope.join("bin/vp"),
            changed,
        })
    })();
    if result.is_err() {
        restore(files, &kept)?;
    }
    result
}
pub struct Materialize;
impl Command for Materialize {
    const NAME: &'static str = "tools.materialize-vp";
    const SUMMARY: &'static str = "Prepare the Linux Vite+ scope and verify its managed launchers.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("machine-recipe".into())
    }
    fn run(input: Input, context: &Context) -> Result<Output> {
        let account = Account::named(&input.user).map_err(|_| Error::Invalid {})?;
        materialize(
            &mut recipe::Native(context),
            &Files::system(),
            &account,
            &input,
            context.deadline(),
        )
    }
}
#[cfg(test)]
mod tests;
