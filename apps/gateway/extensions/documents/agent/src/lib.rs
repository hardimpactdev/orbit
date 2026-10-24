//! Documents owns the local cleanup gate preparation; Artisan owns control-plane invalidation.
use orbit_agent_contract::{
    command_errors, identity, Command, Context, Platform, Privilege, Registrar, StartHook,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};

pub fn register(registry: &mut impl Registrar) {
    registry.start_hook::<PrepareCleanup>();
}

command_errors! {
    pub enum Error {
        Invalid {} => "documents.cleanup_input_invalid", "The cleanup startup paths must be absolute paths without traversal or control characters.";
        Unsafe {} => "documents.cleanup_state_unsafe", "The document cleanup runtime state is unsafe; stop its consumers before repairing it.";
        Failed { stage: String } => "documents.cleanup_start_failed", "Document cleanup startup invalidation failed.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub checkout: String,
    pub orbit_home: String,
}
#[derive(Serialize)]
pub struct Output {}
pub struct PrepareCleanup;
impl StartHook for PrepareCleanup {
    const FILE_NAME: &'static str = "project-document-cleanup-start";
    const DROP_IN: &'static str = "orbit-document-cleanup.conf";
    const DESCRIPTION: &'static str = "document cleanup startup gate";
    fn validate(input: &Input) -> Result<(), Error> {
        for path in [&input.checkout, &input.orbit_home] {
            if !path.starts_with('/')
                || path == "/"
                || path.bytes().any(|b| b < 32 || b == 127)
                || path.split('/').any(|part| part == "." || part == "..")
            {
                return Err(Error::Invalid {});
            }
        }
        Ok(())
    }
}
impl Command for PrepareCleanup {
    const NAME: &'static str = "documents.prepare-cleanup";
    const SUMMARY: &'static str =
        "Prepare and invalidate the local document cleanup gate before Gateway startup.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some(orbit_agent_contract::START_HOOK_TARGET.into())
    }
    fn run(input: Input, context: &Context) -> Result<Output, Error> {
        Self::validate(&input)?;
        let account = identity::passwd("orbit").ok_or_else(|| failed("account"))?;
        if account.uid == 0 {
            return Err(failed("account"));
        }
        prepare(Path::new("/run/orbit"), 0, 0, account.uid, account.gid)?;
        invalidate(&input, context)?;
        Ok(Output {})
    }
}
fn failed(stage: &str) -> Error {
    Error::Failed {
        stage: stage.into(),
    }
}
fn metadata(path: &Path) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta) => Ok(Some(meta)),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(Error::Unsafe {}),
    }
}
fn prepare(root: &Path, root_uid: u32, root_gid: u32, uid: u32, gid: u32) -> Result<(), Error> {
    let directory = root.join("project-documents");
    let lock = directory.join("execution.lock");
    if let Some(meta) = metadata(root)? {
        if !meta.is_dir()
            || meta.uid() != root_uid
            || meta.gid() != root_gid
            || meta.mode() & 0o022 != 0
        {
            return Err(Error::Unsafe {});
        }
    } else {
        fs::DirBuilder::new()
            .mode(0o755)
            .create(root)
            .map_err(|_| failed("runtime"))?;
        fs::set_permissions(root, fs::Permissions::from_mode(0o755))
            .map_err(|_| failed("runtime"))?;
    }
    if let Some(meta) = metadata(&directory)? {
        if !meta.is_dir() || meta.uid() != uid || meta.gid() != gid || meta.mode() & 0o7777 != 0o700
        {
            return Err(Error::Unsafe {});
        }
        let meta = metadata(&lock)?.ok_or(Error::Unsafe {})?;
        if !meta.is_file()
            || meta.uid() != uid
            || meta.gid() != gid
            || meta.mode() & 0o7777 != 0o600
            || meta.nlink() != 1
        {
            return Err(Error::Unsafe {});
        }
    } else {
        fs::DirBuilder::new()
            .mode(0o700)
            .create(&directory)
            .map_err(|_| failed("state"))?;
        fs::set_permissions(&directory, fs::Permissions::from_mode(0o700))
            .map_err(|_| failed("state"))?;
        let file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&lock)
            .map_err(|_| failed("lock"))?;
        file.set_permissions(fs::Permissions::from_mode(0o600))
            .map_err(|_| failed("lock"))?;
        // Publish the first lock before giving the account access. Existing state is never repaired.
        std::os::unix::fs::chown(&lock, Some(uid), Some(gid)).map_err(|_| failed("owner"))?;
        std::os::unix::fs::chown(&directory, Some(uid), Some(gid)).map_err(|_| failed("owner"))?;
    }
    Ok(())
}
fn artisan(input: &Input) -> Process {
    let mut command = Process::new("/usr/bin/php8.5");
    command
        .env_clear()
        .env("PATH", "/usr/local/bin:/usr/bin:/bin")
        .env("HOME", "/home/orbit")
        .env("ORBIT_HOME", &input.orbit_home)
        .arg(Path::new(&input.checkout).join("artisan"))
        .args(["project-documents:cleanup:invalidate", "--no-interaction"])
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null());
    command
}
fn invalidate(input: &Input, context: &Context) -> Result<(), Error> {
    let mut command = artisan(input);
    identity::drop_to(&mut command, "orbit").map_err(|_| failed("account"))?;
    execute(&mut command, context)
}
fn execute(command: &mut Process, context: &Context) -> Result<(), Error> {
    if context.remaining().is_zero() {
        return Err(failed("deadline"));
    }
    let mut child = command.spawn().map_err(|_| failed("invalidate"))?;
    loop {
        match child.try_wait() {
            Ok(Some(status)) => {
                return if status.success() {
                    Ok(())
                } else {
                    Err(failed("invalidate"))
                }
            }
            Ok(None) if !context.remaining().is_zero() => {
                std::thread::sleep(context.remaining().min(Duration::from_millis(20)))
            }
            _ => {
                let _ = child.kill();
                let _ = child.wait();
                return Err(failed("invalidate"));
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        os::unix::fs::symlink,
        sync::atomic::{AtomicU64, Ordering},
    };
    struct Fixture(std::path::PathBuf);
    impl Fixture {
        fn new() -> Self {
            static NEXT: AtomicU64 = AtomicU64::new(0);
            let root = std::env::temp_dir().join(format!(
                "orbit-documents-{}-{}",
                std::process::id(),
                NEXT.fetch_add(1, Ordering::Relaxed)
            ));
            fs::create_dir(&root).unwrap();
            Self(root)
        }
        fn prepare(&self) -> Result<(), Error> {
            let meta = fs::metadata(&self.0).unwrap();
            prepare(
                &self.0.join("run"),
                meta.uid(),
                meta.gid(),
                meta.uid(),
                meta.gid(),
            )
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.0);
        }
    }
    #[test]
    fn fixed_artisan_argv_keeps_paths_as_data_and_clears_ambient_environment() {
        let input = Input {
            checkout: "/home/orbit/a checkout;false".into(),
            orbit_home: "/home/orbit/.orbit".into(),
        };
        let command = artisan(&input);
        assert_eq!(command.get_program(), "/usr/bin/php8.5");
        assert_eq!(
            command.get_args().collect::<Vec<_>>(),
            [
                "/home/orbit/a checkout;false/artisan",
                "project-documents:cleanup:invalidate",
                "--no-interaction"
            ]
        );
        assert_eq!(command.get_envs().count(), 3);
    }
    #[test]
    fn successful_control_plane_exit_failure_missing_executable_and_deadline_are_bounded() {
        let context = Context::new(
            Platform::Linux,
            std::time::Instant::now() + Duration::from_secs(2),
        );
        assert!(execute(&mut Process::new("/bin/true"), &context).is_ok());
        assert_eq!(
            execute(&mut Process::new("/bin/false"), &context),
            Err(failed("invalidate"))
        );
        assert_eq!(
            execute(&mut Process::new("/nonexistent/orbit-test"), &context),
            Err(failed("invalidate"))
        );
        let expired = Context::new(Platform::Linux, std::time::Instant::now());
        assert_eq!(
            execute(&mut Process::new("/bin/true"), &expired),
            Err(failed("deadline"))
        );
        let short = Context::new(
            Platform::Linux,
            std::time::Instant::now() + Duration::from_millis(30),
        );
        assert_eq!(
            execute(Process::new("/bin/sleep").arg("5"), &short),
            Err(failed("invalidate"))
        );
    }
    #[test]
    fn creates_private_state_and_preserves_the_lock_and_generation_on_repeat() {
        let f = Fixture::new();
        f.prepare().unwrap();
        let directory = f.0.join("run/project-documents");
        let lock = directory.join("execution.lock");
        fs::write(directory.join("generation.json"), "existing generation").unwrap();
        let before = fs::metadata(&lock).unwrap();
        f.prepare().unwrap();
        let after = fs::metadata(&lock).unwrap();
        assert_eq!((before.ino(), before.mtime()), (after.ino(), after.mtime()));
        assert_eq!(after.mode() & 0o7777, 0o600);
        assert_eq!(fs::metadata(&directory).unwrap().mode() & 0o7777, 0o700);
        assert_eq!(
            fs::read_to_string(directory.join("generation.json")).unwrap(),
            "existing generation"
        );
    }
    #[test]
    fn missing_lock_is_not_recreated() {
        let f = Fixture::new();
        f.prepare().unwrap();
        let lock = f.0.join("run/project-documents/execution.lock");
        fs::remove_file(&lock).unwrap();
        assert_eq!(f.prepare(), Err(Error::Unsafe {}));
        assert!(!lock.exists());
    }
    #[test]
    fn unsafe_runtime_paths_and_modes_are_never_repaired() {
        for which in [
            "run",
            "run/project-documents",
            "run/project-documents/execution.lock",
        ] {
            let f = Fixture::new();
            f.prepare().unwrap();
            let path = f.0.join(which);
            fs::rename(&path, f.0.join("kept")).unwrap();
            symlink(f.0.join("kept"), &path).unwrap();
            assert_eq!(f.prepare(), Err(Error::Unsafe {}));
            assert!(fs::symlink_metadata(path).unwrap().is_symlink());
        }
        let f = Fixture::new();
        f.prepare().unwrap();
        let lock = f.0.join("run/project-documents/execution.lock");
        fs::set_permissions(&lock, fs::Permissions::from_mode(0o644)).unwrap();
        assert_eq!(f.prepare(), Err(Error::Unsafe {}));
        assert_eq!(fs::metadata(lock).unwrap().mode() & 0o7777, 0o644);
    }
    #[test]
    fn rejects_invalid_input_before_creating_state() {
        for value in [
            "relative",
            "/",
            "/home/../etc",
            "/home/./orbit",
            "/home/orbit\n",
        ] {
            let input = Input {
                checkout: value.into(),
                orbit_home: "/home/orbit/.orbit".into(),
            };
            assert_eq!(PrepareCleanup::validate(&input), Err(Error::Invalid {}));
        }
        assert!(PrepareCleanup::validate(&Input {
            checkout: "/home/orbit/a checkout".into(),
            orbit_home: "/home/orbit/.orbit".into()
        })
        .is_ok());
    }
}
