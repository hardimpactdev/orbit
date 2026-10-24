//! Owner-only immutable deployment runtimes, sharing recipe publication rules and its store lock.
mod downloads;
#[cfg(test)]
mod tests;

use super::recipe::{artifacts, store, store::Account, Failure, Files};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::{Component, Path, PathBuf},
    time::{Duration, Instant},
};

crate::command_errors! {
    pub enum Error {
        Invalid {} => "tools.store_invalid", "The runtime requirements or store ownership are invalid.";
        Failed {} => "tools.store_failed", "The runtime store operation failed; check ownership, registry access, and checksums.";
        Busy {} => "tools.store_lock_timeout", "The runtime store lock timed out.";
    }
}
type Result<T> = std::result::Result<T, Error>;
impl From<io::Error> for Error {
    fn from(_: io::Error) -> Self {
        Self::Failed {}
    }
}
impl From<serde_json::Error> for Error {
    fn from(_: serde_json::Error) -> Self {
        Self::Failed {}
    }
}
impl From<Failure> for Error {
    fn from(error: Failure) -> Self {
        if error.stage == "store_lock_timeout" {
            Self::Busy {}
        } else {
            Self::Failed {}
        }
    }
}

#[derive(Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Environment {
    pub store: PathBuf,
    pub node_version: String,
    pub package_manager: String,
    pub package_manager_version: String,
    #[serde(default)]
    pub package_manager_hash: Option<String>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InspectInput {
    pub environments: Vec<Environment>,
}
#[derive(Serialize)]
pub struct Inspected {
    pub installed: Vec<bool>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub environment: Environment,
    #[serde(default)]
    pub npmrc: String,
}
#[derive(Serialize)]
pub struct Installed {
    pub verified: BTreeMap<String, String>,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PruneInput {
    pub store: PathBuf,
    pub references: Vec<Environment>,
}
#[derive(Serialize)]
pub struct Pruned {
    pub removed: Vec<String>,
}

fn account() -> Result<Account> {
    let uid = rustix::process::geteuid().as_raw();
    if uid == 0 {
        return Err(Error::Invalid {});
    }
    Ok(Account {
        name: crate::command::identity::user_name(uid).ok_or(Error::Invalid {})?,
        uid,
        gid: rustix::process::getegid().as_raw(),
        home: PathBuf::new(),
    })
}
fn home(path: &Path, account: &Account) -> Result<()> {
    let text = path.to_str().ok_or(Error::Invalid {})?;
    if !path.is_absolute()
        || text.contains('\0')
        || text.contains("//")
        || text.split('/').any(|part| part == "." || part == "..")
    {
        return Err(Error::Invalid {});
    }
    store::check_home(path, account, false).map_err(|_| Error::Invalid {})
}
pub(super) struct Deployment {
    pub store: PathBuf,
    pub arguments: Vec<std::ffi::OsString>,
    owner: u32,
}

impl Deployment {
    /// Private account homes need passage for the production Instance, not a broader home mode.
    pub(super) fn allow_instance(&self, user: &str) -> Result<()> {
        use std::process::{Command as Process, Stdio};
        let (uid, _, _) = crate::command::identity::account(user).map_err(|_| Error::Invalid {})?;
        if uid == 0 {
            return Err(Error::Invalid {});
        }
        let can_execute = || -> Result<bool> {
            let mut command = Process::new("/usr/bin/test");
            command
                .arg("-x")
                .arg(self.store.join("bin/vp"))
                .current_dir("/")
                .stdin(Stdio::null());
            crate::command::identity::drop_to(&mut command, user).map_err(|_| Error::Invalid {})?;
            Ok(command.status()?.success())
        };
        if can_execute()? {
            return Ok(());
        }
        let mut grant = vec![];
        for path in self.store.ancestors().skip(1) {
            let meta = fs::symlink_metadata(path).map_err(|_| Error::Invalid {})?;
            if !meta.is_dir() {
                return Err(Error::Invalid {});
            }
            if meta.mode() & 0o001 == 0 {
                if meta.uid() != self.owner {
                    return Err(Error::Invalid {});
                }
                let output = Process::new("getfacl")
                    .args(["-cpn", "--"])
                    .arg(path)
                    .stdin(Stdio::null())
                    .stderr(Stdio::null())
                    .output()?;
                if !output.status.success() {
                    return Err(Error::Failed {});
                }
                let acl = String::from_utf8(output.stdout).map_err(|_| Error::Invalid {})?;
                grant.push((
                    path,
                    super::caddy_access::passage_grant(&acl, &uid.to_string()),
                ));
            }
        }
        for (path, acl) in grant.into_iter().rev() {
            let status = Process::new("setfacl")
                .args(["-n", "-m", &acl, "--"])
                .arg(path)
                .stdin(Stdio::null())
                .stdout(Stdio::null())
                .stderr(Stdio::null())
                .status()?;
            if !status.success() {
                return Err(Error::Failed {});
            }
        }
        if !can_execute()? {
            return Err(Error::Invalid {});
        }
        Ok(())
    }
}

impl Environment {
    /// Builds fixed arguments for a deploy. The shared store belongs to the enrolled account,
    /// while the caller drops to the Instance account before starting its executable.
    pub(super) fn deployment(&self) -> Result<Deployment> {
        let meta = fs::symlink_metadata(&self.store).map_err(|_| Error::Invalid {})?;
        let owner = crate::command::identity::user_name(meta.uid()).ok_or(Error::Invalid {})?;
        let account = Account::named(&owner).map_err(|_| Error::Invalid {})?;
        if !installed(self, &account)? || !executable(&self.store.join("bin/vp")) {
            return Err(Error::Invalid {});
        }
        let selector = format!(
            "{}@{}{}",
            self.package_manager,
            self.package_manager_version,
            self.package_manager_hash
                .as_ref()
                .map(|hash| format!("+{hash}"))
                .unwrap_or_default()
        );
        Ok(Deployment {
            store: self.store.clone(),
            owner: account.uid,
            arguments: vec![
                self.store.join("bin/vp").into_os_string(),
                "env".into(),
                "exec".into(),
                "--node".into(),
                self.node_version.clone().into(),
                "--package-manager".into(),
                selector.into(),
            ],
        })
    }

    fn paths(&self, account: &Account) -> Result<(PathBuf, PathBuf)> {
        home(&self.store, account)?;
        if !store::version(&self.node_version)
            || !store::version(&self.package_manager_version)
            || !matches!(self.package_manager.as_str(), "npm" | "pnpm")
            || self
                .package_manager_hash
                .as_ref()
                .is_some_and(|pin| !downloads::valid_pin(pin))
        {
            return Err(Error::Invalid {});
        }
        let node = self.store.join("js_runtime/node").join(&self.node_version);
        let manager = self
            .store
            .join("package_manager")
            .join(&self.package_manager)
            .join(&self.package_manager_version);
        for path in [&node, &manager] {
            store::check_path(&self.store, path, account).map_err(|_| Error::Invalid {})?;
        }
        Ok((node, manager))
    }
}
fn executable(path: &Path) -> bool {
    fs::metadata(path).is_ok_and(|meta| meta.is_file() && meta.mode() & 0o111 != 0)
}
fn installed(input: &Environment, account: &Account) -> Result<bool> {
    let (node, manager) = input.paths(account)?;
    if !executable(&node.join("bin/node")) {
        return Ok(false);
    }
    let package = manager.join(&input.package_manager);
    let manifest = match fs::read(package.join("package.json")) {
        Ok(bytes) => serde_json::from_slice::<Value>(&bytes).ok(),
        Err(e) if e.kind() == io::ErrorKind::NotFound => None,
        Err(e) => return Err(e.into()),
    };
    Ok(manifest.is_some_and(|manifest| {
        manifest["name"] == input.package_manager
            && manifest["version"] == input.package_manager_version
            && executable(&package.join("bin").join(&input.package_manager))
    }))
}
fn inspect(input: &InspectInput, account: &Account) -> Result<Inspected> {
    Ok(Inspected {
        installed: input
            .environments
            .iter()
            .map(|env| installed(env, account))
            .collect::<Result<_>>()?,
    })
}
fn ledger(home: &Path, account: &Account) -> Result<BTreeMap<String, String>> {
    let path = home.join(".orbit-runtimes.json");
    store::check_path(home, &path, account).map_err(|_| Error::Invalid {})?;
    match fs::read(path) {
        Ok(bytes) => Ok(serde_json::from_slice(&bytes)?),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(BTreeMap::new()),
        Err(e) => Err(e.into()),
    }
}
fn write_ledger(home: &Path, ledger: &BTreeMap<String, String>, account: &Account) -> Result<()> {
    Files::system().publish(
        &home.join(".orbit-runtimes.json"),
        &serde_json::to_vec(ledger)?,
        0o644,
        account.uid,
        account.gid,
        false,
    )?;
    Ok(())
}
fn directories(home: &Path, path: &Path, account: &Account) -> Result<()> {
    store::check_path(home, path, account).map_err(|_| Error::Invalid {})?;
    let mut current = home.to_path_buf();
    for part in path
        .strip_prefix(home)
        .map_err(|_| Error::Invalid {})?
        .components()
    {
        if !matches!(part, Component::Normal(_)) {
            return Err(Error::Invalid {});
        }
        current.push(part);
        if !current.exists() {
            fs::DirBuilder::new().mode(0o755).create(&current)?;
        }
        if !current.is_dir() {
            return Err(Error::Invalid {});
        }
    }
    Ok(())
}
struct Stage(PathBuf);
impl Stage {
    fn new(parent: &Path) -> Result<Self> {
        let path = parent.join(format!(".orbit-stage-{:032x}", rand::random::<u128>()));
        fs::DirBuilder::new().mode(0o700).create(&path)?;
        Ok(Self(path))
    }
    fn extract(&self, data: &[u8]) -> Result<PathBuf> {
        let archive = self.0.join("archive");
        fs::write(&archive, data)?;
        let content = self.0.join("content");
        artifacts::extract(&archive, &content)?;
        Ok(content)
    }
}
impl Drop for Stage {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
fn absent(path: &Path) -> Result<()> {
    match fs::symlink_metadata(path) {
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        _ => Err(Error::Invalid {}),
    }
}
fn install(
    input: &InstallInput,
    account: &Account,
    downloads: &mut impl downloads::Download,
    deadline: Instant,
) -> Result<Installed> {
    let env = &input.environment;
    let (node, manager) = env.paths(account)?;
    if installed(env, account)? {
        return Ok(Installed {
            verified: BTreeMap::new(),
        });
    }
    let _lock = store::lock(&env.store, account, deadline)?;
    if installed(env, account)? {
        return Ok(Installed {
            verified: BTreeMap::new(),
        });
    }
    let mut receipts = ledger(&env.store, account)?;
    if !node.join("bin/node").exists() {
        absent(&node)?;
        let (data, source, checksum) = downloads::node(downloads, &env.node_version)?;
        directories(&env.store, node.parent().unwrap(), account)?;
        let stage = Stage::new(node.parent().unwrap())?;
        let content = stage.extract(&data)?.join(source);
        if content.is_symlink() || !content.is_dir() || !executable(&content.join("bin/node")) {
            return Err(Error::Failed {});
        }
        fs::rename(content, &node)?;
        receipts.insert(format!("js_runtime/node/{}", env.node_version), checksum);
        write_ledger(&env.store, &receipts, account)?;
    }
    if !manager
        .join(&env.package_manager)
        .join("package.json")
        .exists()
    {
        absent(&manager)?;
        let (data, mut checksum) = downloads::manager(downloads, env, &input.npmrc)?;
        directories(&env.store, manager.parent().unwrap(), account)?;
        let stage = Stage::new(manager.parent().unwrap())?;
        let content = stage.extract(&data)?;
        let package = content.join("package");
        let manifest: Value = serde_json::from_slice(&fs::read(package.join("package.json"))?)?;
        let native = downloads::native_pnpm(downloads, &manifest, env, &input.npmrc)?;
        if let Some((data, name, integrity)) = &native {
            let native_stage = Stage::new(&stage.0)?;
            let unpacked = native_stage.extract(data)?.join("package");
            let metadata: Value =
                serde_json::from_slice(&fs::read(unpacked.join("package.json"))?)?;
            if metadata["name"] != *name || metadata["version"] != env.package_manager_version {
                return Err(Error::Failed {});
            }
            let binary = unpacked.join("pnpm");
            if binary.is_symlink() || !binary.is_file() {
                return Err(Error::Invalid {});
            }
            let bytes = fs::read(binary)?;
            if !native_executable(&bytes) {
                return Err(Error::Failed {});
            }
            // Replace only the unpublished placeholder; no upstream install hook runs.
            let executable = package.join("pnpm");
            if executable.is_symlink() {
                return Err(Error::Invalid {});
            }
            fs::write(&executable, bytes)?;
            fs::set_permissions(&executable, fs::Permissions::from_mode(0o755))?;
            checksum = format!(
                "{checksum} {name}@{}={integrity}",
                env.package_manager_version
            );
        }
        launchers(&package, env, native.is_some())?;
        fs::rename(&package, content.join(&env.package_manager))?;
        fs::set_permissions(&content, fs::Permissions::from_mode(0o755))?;
        fs::rename(content, &manager)?;
        receipts.insert(
            format!(
                "package_manager/{}/{}",
                env.package_manager, env.package_manager_version
            ),
            checksum,
        );
        write_ledger(&env.store, &receipts, account)?;
    }
    if !installed(env, account)? {
        return Err(Error::Failed {});
    }
    Ok(Installed { verified: receipts })
}
fn native_executable(bytes: &[u8]) -> bool {
    bytes.starts_with(b"\x7fELF")
        || bytes.starts_with(&[0xcf, 0xfa, 0xed, 0xfe])
        || bytes.starts_with(&[0xfe, 0xed, 0xfa, 0xcf])
        || bytes.starts_with(&[0xca, 0xfe, 0xba, 0xbe])
        || bytes.starts_with(&[0xbe, 0xba, 0xfe, 0xca])
}
fn launchers(package: &Path, env: &Environment, native: bool) -> Result<()> {
    if package.is_symlink() || !package.is_dir() {
        return Err(Error::Invalid {});
    }
    let manifest: Value = serde_json::from_slice(&fs::read(package.join("package.json"))?)?;
    if manifest["name"] != env.package_manager || manifest["version"] != env.package_manager_version
    {
        return Err(Error::Failed {});
    }
    let entries = manifest["bin"].as_object().ok_or(Error::Failed {})?;
    let root = package.canonicalize()?;
    let bin = package.join("bin");
    if bin.is_symlink() {
        return Err(Error::Invalid {});
    }
    if !bin.exists() {
        fs::create_dir(&bin)?;
    }
    for (name, script) in entries {
        if !matches!(name.as_str(), "npm" | "npx" | "pnpm" | "pnpx") {
            continue;
        }
        let script = if native {
            "pnpm"
        } else {
            script.as_str().ok_or(Error::Failed {})?
        };
        let target = package.join(script).canonicalize()?;
        if !target.starts_with(&root) || !target.is_file() {
            return Err(Error::Invalid {});
        }
        // JavaScript entries need Node; native pnpm aliases preserve their argv[0].
        if !native && !fs::read(&target)?.starts_with(b"#!/usr/bin/env node") {
            return Err(Error::Failed {});
        }
        fs::set_permissions(&target, fs::Permissions::from_mode(0o755))?;
        let launcher = bin.join(name);
        if launcher == target {
            continue;
        }
        let relative =
            Path::new("..").join(target.strip_prefix(&root).map_err(|_| Error::Invalid {})?);
        match fs::remove_file(&launcher) {
            Ok(()) => {}
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(e.into()),
        }
        std::os::unix::fs::symlink(relative, launcher)?;
    }
    if !executable(&bin.join(&env.package_manager)) {
        return Err(Error::Failed {});
    }
    Ok(())
}
fn collectible(name: &str) -> bool {
    let parts: Vec<_> = name.split('/').collect();
    matches!(parts.as_slice(), ["js_runtime", "node", version] | ["package_manager", "npm" | "pnpm", version] if store::version(version))
}
fn prune(input: &PruneInput, account: &Account, deadline: Instant) -> Result<Pruned> {
    home(&input.store, account)?;
    let mut keep = BTreeSet::new();
    for env in &input.references {
        if env.store != input.store {
            return Err(Error::Invalid {});
        }
        let (node, manager) = env.paths(account)?;
        for path in [node, manager] {
            keep.insert(
                path.strip_prefix(&input.store)
                    .unwrap()
                    .to_string_lossy()
                    .into_owned(),
            );
        }
    }
    let _lock = store::lock(&input.store, account, deadline)?;
    let mut receipts = ledger(&input.store, account)?;
    let reference = input.store.join(store::REFERENCE);
    store::check_path(&input.store, &reference, account).map_err(|_| Error::Invalid {})?;
    match fs::read(reference) {
        Ok(bytes) => {
            let value: Value = serde_json::from_slice(&bytes)?;
            let version = value["version"]
                .as_str()
                .filter(|v| store::version(v))
                .ok_or(Error::Invalid {})?;
            keep.insert(format!("js_runtime/node/{version}"));
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => {}
        Err(e) => return Err(e.into()),
    }
    let removed: Vec<_> = receipts
        .keys()
        .filter(|name| !keep.contains(*name) && collectible(name))
        .cloned()
        .collect();
    for name in &removed {
        let path = input.store.join(name);
        store::check_path(&input.store, &path, account).map_err(|_| Error::Invalid {})?;
        if path.exists() && !path.is_dir() {
            return Err(Error::Invalid {});
        }
    }
    for name in &removed {
        match fs::remove_dir_all(input.store.join(name)) {
            Ok(()) => {}
            Err(e) if e.kind() == io::ErrorKind::NotFound => {}
            Err(e) => return Err(e.into()),
        }
        receipts.remove(name);
    }
    write_ledger(&input.store, &receipts, account)?;
    Ok(Pruned { removed })
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "tools.inspect-vp-store";
    const SUMMARY: &'static str =
        "Inspect exact immutable deployment runtimes without locks or network access.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = InspectInput;
    type Output = Inspected;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn run(input: InspectInput, _: &Context) -> Result<Inspected> {
        inspect(&input, &account()?)
    }
}
pub struct Install;
impl Command for Install {
    const NAME: &'static str = "tools.install-vp-environment";
    const SUMMARY: &'static str =
        "Verify and publish missing Node and package-manager runtimes as the store owner.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(600);
    type Input = InstallInput;
    type Output = Installed;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn target(input: &InstallInput) -> Option<String> {
        Some(format!("vp-store:{}", input.environment.store.display()))
    }
    fn run(input: InstallInput, context: &Context) -> Result<Installed> {
        install(
            &input,
            &account()?,
            &mut downloads::Native(context.deadline()),
            context.deadline(),
        )
    }
}
pub struct Prune;
impl Command for Prune {
    const NAME: &'static str = "tools.prune-vp-store";
    const SUMMARY: &'static str =
        "Collect only unreferenced runtimes with an Orbit deployment receipt.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(120);
    type Input = PruneInput;
    type Output = Pruned;
    type Error = Error;
    fn privilege(_: Platform) -> Option<Privilege> {
        Some(Privilege::Account)
    }
    fn target(input: &PruneInput) -> Option<String> {
        Some(format!("vp-store:{}", input.store.display()))
    }
    fn run(input: PruneInput, context: &Context) -> Result<Pruned> {
        prune(&input, &account()?, context.deadline())
    }
}
