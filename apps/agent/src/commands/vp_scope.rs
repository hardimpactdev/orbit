//! Read-only Vite+ scope discovery. Linux chooses the first installed scope; macOS requires one.
use crate::command::{identity, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    time::Duration,
};
crate::command_errors! {
    pub enum Error {
        Absent {} => "tools.vp_scope_absent", "The Vite+ global scope is absent for the enrolled account.";
        Conflict {} => "tools.vp_scope_conflict", "The Vite+ global scope conflicts with the enrolled account.";
        Unreadable {} => "tools.vp_scope_unreadable", "The Vite+ global scope cannot be inspected.";
    }
}
type Result<T> = std::result::Result<T, Error>;
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Output {
    pub binary: PathBuf,
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>> {
    super::recipe::metadata(path).map_err(|_| Error::Unreadable {})
}
fn owned(meta: &fs::Metadata, uid: u32, gid: u32, platform: Platform) -> bool {
    meta.uid() == uid && (platform == Platform::Macos || meta.gid() == gid)
}
pub(crate) fn inspect(
    platform: Platform,
    home: &Path,
    uid: u32,
    gid: u32,
    opt: &Path,
    root_uid: u32,
    root_gid: u32,
) -> Result<Output> {
    let home_text = home.to_str().ok_or(Error::Unreadable {})?;
    if !home.is_absolute()
        || !home.is_dir()
        || home_text.contains("..")
        || !home_text
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"/._-".contains(&b))
    {
        return Err(Error::Unreadable {});
    }
    let mut candidates = vec![];
    if platform == Platform::Linux {
        if let Some(meta) = metadata(opt)? {
            if !meta.is_dir() || meta.uid() != root_uid || meta.gid() != root_gid {
                return Err(Error::Conflict {});
            }
        }
        candidates.push(opt.join("vite-plus"));
    }
    candidates.extend([home.join(".vite-plus"), home.join(".local/share/vite-plus")]);
    let mut found = None;
    for scope in candidates {
        let Some(meta) = metadata(&scope)? else {
            continue;
        };
        if !meta.is_dir() || !owned(&meta, uid, gid, platform) {
            return Err(Error::Conflict {});
        }
        let binary = scope.join("bin/vp");
        let Some(meta) = metadata(&binary)? else {
            continue;
        };
        if !owned(&meta, uid, gid, platform)
            || !fs::metadata(&binary).is_ok_and(|meta| meta.mode() & 0o111 != 0)
        {
            return Err(Error::Conflict {});
        }
        if found.is_some() {
            return Err(Error::Conflict {});
        }
        found = Some(Output { binary });
        if platform == Platform::Linux {
            break;
        }
    }
    found.ok_or(Error::Absent {})
}
pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tools.inspect-vp-scope";
    const SUMMARY: &'static str =
        "Find the enrolled account's existing Vite+ scope without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(_: Input, context: &Context) -> Result<Output> {
        let uid = identity::effective_uid();
        let account = identity::user_name(uid)
            .and_then(|name| identity::passwd(&name))
            .ok_or(Error::Unreadable {})?;
        inspect(
            context.platform,
            Path::new(&account.home),
            uid,
            account.gid,
            Path::new("/opt/orbit"),
            0,
            0,
        )
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    use std::os::unix::fs::{symlink, PermissionsExt};
    struct Fixture {
        root: PathBuf,
        uid: u32,
        gid: u32,
    }
    impl Fixture {
        fn new() -> Self {
            let root =
                std::env::temp_dir().join(format!("orbit-vp-scope-{:016x}", rand::random::<u64>()));
            fs::create_dir_all(root.join("home")).unwrap();
            fs::create_dir(root.join("opt")).unwrap();
            Self {
                root,
                uid: identity::effective_uid(),
                gid: rustix::process::getegid().as_raw(),
            }
        }
        fn run(&self, platform: Platform) -> Result<Output> {
            inspect(
                platform,
                &self.root.join("home"),
                self.uid,
                self.gid,
                &self.root.join("opt"),
                self.uid,
                self.gid,
            )
        }
        fn scope(&self, name: &str) -> PathBuf {
            self.root.join(name)
        }
        fn install(&self, name: &str) -> PathBuf {
            let binary = self.scope(name).join("bin/vp");
            fs::create_dir_all(binary.parent().unwrap()).unwrap();
            fs::write(&binary, "not executed").unwrap();
            fs::set_permissions(&binary, fs::Permissions::from_mode(0o755)).unwrap();
            binary
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            fs::remove_dir_all(&self.root).unwrap();
        }
    }
    #[test]
    fn skips_cache_only_scopes_and_repeats_without_running_the_binary() {
        for platform in [Platform::Linux, Platform::Macos] {
            let f = Fixture::new();
            fs::create_dir_all(f.scope("home/.vite-plus/package_manager/npm/cache")).unwrap();
            let binary = f.install("home/.local/share/vite-plus");
            let before = fs::metadata(&binary).unwrap().modified().unwrap();
            for _ in 0..2 {
                assert_eq!(
                    f.run(platform),
                    Ok(Output {
                        binary: binary.clone()
                    })
                );
            }
            assert_eq!(fs::metadata(binary).unwrap().modified().unwrap(), before);
        }
    }
    #[test]
    fn linux_first_installed_store_wins_and_macos_refuses_multiple_stores() {
        let f = Fixture::new();
        let opt = f.install("opt/vite-plus");
        let home = f.install("home/.vite-plus");
        assert_eq!(
            f.run(Platform::Linux),
            Ok(Output {
                binary: opt.clone()
            })
        );
        assert_eq!(f.run(Platform::Macos), Ok(Output { binary: home }));
        f.install("home/.local/share/vite-plus");
        assert_eq!(f.run(Platform::Linux), Ok(Output { binary: opt }));
        assert_eq!(f.run(Platform::Macos), Err(Error::Conflict {}));
    }
    #[test]
    fn absent_and_cache_only_homes_are_absent() {
        let f = Fixture::new();
        for platform in [Platform::Linux, Platform::Macos] {
            assert_eq!(f.run(platform), Err(Error::Absent {}));
        }
        fs::create_dir_all(f.scope("home/.vite-plus/cache")).unwrap();
        for platform in [Platform::Linux, Platform::Macos] {
            assert_eq!(f.run(platform), Err(Error::Absent {}));
        }
    }
    #[test]
    #[ignore = "Uses sudo on SPIKE to create a foreign-owned fixture binary"]
    fn foreign_binary_owner_refuses_and_only_linux_requires_the_primary_group() {
        let f = Fixture::new();
        let binary = f.install("home/.vite-plus");
        let change = |owner: &str, path: &Path| {
            assert!(std::process::Command::new("sudo")
                .args(["-n", "chown", owner])
                .arg(path)
                .status()
                .unwrap()
                .success());
        };
        change("0:0", &binary);
        for platform in [Platform::Linux, Platform::Macos] {
            assert_eq!(f.run(platform), Err(Error::Conflict {}));
        }
        change(&format!("{}:0", f.uid), &binary);
        assert_eq!(f.run(Platform::Linux), Err(Error::Conflict {}));
        assert_eq!(
            f.run(Platform::Macos),
            Ok(Output {
                binary: binary.clone()
            })
        );
        change(&format!("{}:{}", f.uid, f.gid), &binary);
        change(&format!("{}:0", f.uid), &f.scope("home/.vite-plus"));
        assert_eq!(f.run(Platform::Linux), Err(Error::Conflict {}));
        assert_eq!(f.run(Platform::Macos), Ok(Output { binary }));
    }
    #[test]
    fn refuses_symlinked_or_nondirectory_scopes_and_nonexecutable_or_dangling_binaries() {
        for platform in [Platform::Linux, Platform::Macos] {
            for kind in ["symlink", "file", "nonexecutable", "dangling"] {
                let f = Fixture::new();
                let scope = f.scope("home/.vite-plus");
                match kind {
                    "symlink" => symlink(f.scope("opt"), scope).unwrap(),
                    "file" => fs::write(scope, "conflict").unwrap(),
                    "nonexecutable" => {
                        let binary = f.install("home/.vite-plus");
                        fs::set_permissions(binary, fs::Permissions::from_mode(0o644)).unwrap();
                    }
                    _ => {
                        fs::create_dir_all(scope.join("bin")).unwrap();
                        symlink("missing", scope.join("bin/vp")).unwrap();
                    }
                }
                assert_eq!(
                    f.run(platform),
                    Err(Error::Conflict {}),
                    "{platform:?} {kind}"
                );
            }
        }
    }
    #[test]
    fn refuses_foreign_scope_ownership_and_invalid_home_without_mutation() {
        let f = Fixture::new();
        f.install("home/.vite-plus");
        for platform in [Platform::Linux, Platform::Macos] {
            assert_eq!(
                inspect(
                    platform,
                    &f.root.join("home"),
                    f.uid + 1,
                    f.gid,
                    &f.root.join("opt"),
                    f.uid,
                    f.gid
                ),
                Err(Error::Conflict {})
            );
            assert_eq!(
                inspect(
                    platform,
                    Path::new("relative"),
                    f.uid,
                    f.gid,
                    &f.root.join("opt"),
                    f.uid,
                    f.gid
                ),
                Err(Error::Unreadable {})
            );
        }
        assert_eq!(
            inspect(
                Platform::Linux,
                &f.root.join("home"),
                f.uid,
                f.gid,
                &f.root.join("opt"),
                f.uid + 1,
                f.gid
            ),
            Err(Error::Conflict {})
        );
    }
}
