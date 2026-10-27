//! The recipe's one PHP build installer: exact artifacts, rollback copies, modules, and pool health.
pub mod inventory;
pub mod operations;
pub mod packages;
pub mod runtime;
#[cfg(test)]
mod tests;

use super::recipe::{self, Files, Host as RecipeHost, Invocation, Output};
use crate::command::CommandError;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::{Path, PathBuf},
    time::Instant,
};

pub const CACHE: &str = "/var/cache/orbit/php-packages";
pub const ARCHIVES: &str = "/var/cache/apt/archives";
pub const BACKUP: &str = "/var/cache/orbit/php-unowned-extensions";
pub const RETIRED_SOURCE: &str = "/etc/apt/sources.list.d/orbit-php.sources";
pub const RETIRED_KEY: &str = "/usr/share/keyrings/orbit-sury-php.gpg";

#[derive(Debug, Clone, Copy, PartialEq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Kind {
    InputInvalid,
    PlatformUnsupported,
    ConflictingSource,
    ExtraPackages,
    UnownedExtensions,
    DownloadFailed,
    RollbackUnavailable,
    InstallFailed,
    ModulesMismatch,
    FpmInvalid,
    RolledBack,
    RollbackFailed,
    ExecutionFailed,
}
impl Kind {
    fn code(self) -> &'static str {
        match self {
            Self::InputInvalid => "php.build_input_invalid",
            Self::PlatformUnsupported => "php.build_platform_unsupported",
            Self::ConflictingSource => "php.build_conflicting_source",
            Self::ExtraPackages => "php.build_extra_packages",
            Self::UnownedExtensions => "php.build_unowned_extensions",
            Self::DownloadFailed => "php.build_download_failed",
            Self::RollbackUnavailable => "php.build_rollback_unavailable",
            Self::InstallFailed => "php.build_install_failed",
            Self::ModulesMismatch => "php.build_modules_mismatch",
            Self::FpmInvalid => "php.build_fpm_invalid",
            Self::RolledBack => "php.build_rolled_back",
            Self::RollbackFailed => "php.build_rollback_failed",
            Self::ExecutionFailed => "php.build_execution_failed",
        }
    }
}
#[derive(Debug, Default, Clone, Serialize)]
pub struct Details {
    #[serde(skip_serializing_if = "Option::is_none")]
    pub reason: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub package: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub packages: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub sources: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub extensions: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub missing: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub extra: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub unit: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub id: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub codename: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub restored: Option<bool>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub diagnostic: Option<recipe::Failure>,
}
#[derive(Debug, Clone)]
pub struct Failure {
    pub kind: Kind,
    pub data: Box<Details>,
}
impl Failure {
    pub fn new(kind: Kind, reason: impl ToString) -> Self {
        Self {
            kind,
            data: Box::new(Details {
                reason: Some(recipe::diagnostic(&reason.to_string())),
                ..Details::default()
            }),
        }
    }
    pub fn package(kind: Kind, name: &str, reason: impl ToString) -> Self {
        let mut result = Self::new(kind, reason);
        result.data.package = Some(name.into());
        result
    }
    pub fn io(error: impl ToString) -> Self {
        Self::new(Kind::ExecutionFailed, error)
    }
}
impl From<recipe::Failure> for Failure {
    fn from(value: recipe::Failure) -> Self {
        Self {
            kind: Kind::ExecutionFailed,
            data: Box::new(Details {
                diagnostic: Some(value),
                ..Details::default()
            }),
        }
    }
}
impl CommandError for Failure {
    const CODES: &'static [&'static str] = &[
        "php.build_input_invalid",
        "php.build_platform_unsupported",
        "php.build_conflicting_source",
        "php.build_extra_packages",
        "php.build_unowned_extensions",
        "php.build_download_failed",
        "php.build_rollback_unavailable",
        "php.build_install_failed",
        "php.build_modules_mismatch",
        "php.build_fpm_invalid",
        "php.build_rolled_back",
        "php.build_rollback_failed",
        "php.build_execution_failed",
    ];
    fn code(&self) -> &'static str {
        self.kind.code()
    }
    fn message(&self) -> String {
        "The PHP recipe build could not complete.".into()
    }
    fn details(&self) -> Value {
        serde_json::to_value(&self.data).expect("typed PHP failure")
    }
}
pub type Result<T> = std::result::Result<T, Failure>;
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Pin {
    pub name: String,
    pub version: String,
    pub architecture: String,
    pub url: String,
    pub sha256: String,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Build {
    pub packages: Vec<Pin>,
    pub extensions: BTreeSet<String>,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Repack {
    pub package: String,
    pub version: String,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Release {
    pub id: String,
    pub codenames: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Clone, Copy, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum Extras {
    Refuse,
    Keep,
    Purge,
}
#[derive(schemars::JsonSchema, Debug, Clone, Copy, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum Fpm {
    None,
    Shared,
    All,
    Disabled,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub versions: BTreeMap<String, Build>,
    #[serde(default)]
    pub mirror: String,
    pub pcov: bool,
    pub opentelemetry: bool,
    pub extras: Extras,
    pub fpm: Fpm,
    pub repack: Repack,
    pub release: Option<Release>,
}
impl Input {
    pub fn validate(&self) -> Result<()> {
        use recipe::types::{https, valid_digest, valid_package, valid_version};
        let bad = || Failure::new(Kind::InputInvalid, "Invalid PHP build pins or options");
        let version = regex::Regex::new(r"\A[0-9]+\.[0-9]+\z").unwrap();
        let module = regex::Regex::new(r"\A[a-z0-9_ ]+\z").unwrap();
        if self.versions.is_empty()
            || (!self.mirror.is_empty() && !https(&self.mirror))
            || self.repack.package != "dpkg-repack"
            || !valid_version(&self.repack.version)
        {
            return Err(bad());
        }
        for (name, build) in &self.versions {
            if !version.is_match(name)
                || build.packages.is_empty()
                || build.extensions.is_empty()
                || build.extensions.iter().any(|name| !module.is_match(name))
            {
                return Err(bad());
            }
            let mut identities = BTreeSet::new();
            for pin in &build.packages {
                if !valid_package(&pin.name)
                    || !valid_version(&pin.version)
                    || pin.architecture != "amd64"
                    || !https(&pin.url)
                    || !valid_digest(&pin.sha256, 64)
                    || !identities.insert((&pin.name, &pin.architecture))
                {
                    return Err(bad());
                }
            }
        }
        if let Some(release) = &self.release {
            if !valid_package(&release.id)
                || release.codenames.is_empty()
                || release.codenames.iter().any(|v| !valid_package(v))
            {
                return Err(bad());
            }
        }
        Ok(())
    }
}
#[derive(Debug, Clone)]
pub struct Selected {
    pub pins: BTreeMap<String, Pin>,
    pub extensions: BTreeSet<String>,
}
pub type Builds = BTreeMap<String, Selected>;
pub type Installed = BTreeMap<String, String>;

pub trait Host: RecipeHost {
    fn ping(&mut self, socket: &Path, deadline: Instant) -> bool;
}
pub fn invoke(host: &mut impl RecipeHost, args: &[&str], required: bool) -> Result<Output> {
    recipe::run(host, &Invocation::new(args).env("LC_ALL", "C"), required).map_err(Into::into)
}
pub fn remove(path: &Path) -> Result<()> {
    match fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(Failure::io(e)),
    }
}
pub fn directories(files: &Files, path: &Path) -> Result<()> {
    if !path.starts_with(&files.root) {
        return Err(Failure::io("Directory is outside the host root"));
    }
    let mut absent = vec![];
    for parent in path.ancestors().take_while(|p| *p != files.root) {
        if fs::symlink_metadata(parent).is_ok() {
            break;
        }
        absent.push(parent);
    }
    for parent in absent.iter().rev() {
        files.directory(parent, files.uid, files.gid)?;
    }
    files.directory(path, files.uid, files.gid)?;
    Ok(())
}
pub fn paths(directory: &Path) -> Result<Vec<PathBuf>> {
    match fs::read_dir(directory) {
        Ok(entries) => {
            let mut result = entries
                .map(|e| e.map(|e| e.path()))
                .collect::<std::io::Result<Vec<_>>>()
                .map_err(Failure::io)?;
            result.sort();
            Ok(result)
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(vec![]),
        Err(e) => Err(Failure::io(e)),
    }
}
pub fn glob(pattern: &Path) -> Result<Vec<PathBuf>> {
    use std::{
        ffi::{CStr, CString},
        os::unix::ffi::{OsStrExt, OsStringExt},
    };
    let pattern = CString::new(pattern.as_os_str().as_bytes()).map_err(Failure::io)?;
    // SAFETY: glob owns its initialized result until globfree; each returned path is NUL-terminated.
    unsafe {
        let mut result: libc::glob_t = std::mem::zeroed();
        let code = libc::glob(pattern.as_ptr(), 0, None, &mut result);
        let paths = if code == 0 {
            (0..result.gl_pathc)
                .map(|index| {
                    PathBuf::from(std::ffi::OsString::from_vec(
                        CStr::from_ptr(*result.gl_pathv.add(index))
                            .to_bytes()
                            .to_vec(),
                    ))
                })
                .collect()
        } else {
            vec![]
        };
        libc::globfree(&mut result);
        if code == 0 || code == libc::GLOB_NOMATCH {
            Ok(paths)
        } else {
            Err(Failure::io("PHP configuration include could not be read"))
        }
    }
}
