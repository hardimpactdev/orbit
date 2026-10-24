//! Publish the shared FPM runtime module after installing the recipe build.
use super::{php_build as build, recipe};
use crate::command::{Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{collections::BTreeMap, fs, os::unix::fs::symlink, path::PathBuf, time::Duration};

crate::command_errors! {
    pub enum Error {
        InputInvalid {} => "php.runtime_input_invalid", "The PHP runtime settings are invalid.";
        PlatformUnsupported { id: String, codename: String } => "php.runtime_platform_unsupported", "The operating system does not support this PHP runtime.";
        Failed { reason: String, restored: bool } => "php.runtime_configuration_failed", "The PHP runtime module could not be configured.";
    }
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub version: String,
    pub runtime_ini: String,
    pub release: build::Release,
}
#[derive(Debug, Serialize)]
pub struct Configured {
    pub changed: bool,
    pub reloaded: bool,
}
fn settings(input: &Input) -> Result<BTreeMap<String, String>, Error> {
    let bad = || Error::InputInvalid {};
    if !regex::Regex::new(r"\A[0-9]+\.[0-9]+\z")
        .unwrap()
        .is_match(&input.version)
        || input.runtime_ini.len() > 8192
        || !recipe::types::valid_package(&input.release.id)
        || input.release.codenames.is_empty()
        || input
            .release
            .codenames
            .iter()
            .any(|v| !recipe::types::valid_package(v))
    {
        return Err(bad());
    }
    let mut settings = BTreeMap::new();
    for line in input
        .runtime_ini
        .lines()
        .map(str::trim)
        .filter(|l| !l.is_empty() && !l.starts_with(';'))
    {
        let (key, value) = line.split_once('=').ok_or_else(bad)?;
        let (key, value) = (key.trim(), value.trim());
        let valid = match key {
            "opcache.enable" => value == "On",
            "opcache.jit" => value == "disable",
            "opcache.jit_buffer_size" => value == "0",
            "opcache.memory_consumption"
            | "opcache.interned_strings_buffer"
            | "opcache.max_accelerated_files" => value.parse::<u32>().is_ok_and(|v| v > 0),
            _ => false,
        };
        if !valid || settings.insert(key.into(), value.into()).is_some() {
            return Err(bad());
        }
    }
    if settings.len() != 6 {
        return Err(bad());
    }
    Ok(settings)
}
struct Before {
    file: Option<Vec<u8>>,
    link: Option<PathBuf>,
}
pub fn configure(
    host: &mut impl recipe::Host,
    files: &recipe::Files,
    input: &Input,
) -> Result<Configured, Error> {
    let expected = settings(input)?;
    let release = fs::read_to_string(files.path("/etc/os-release"))
        .ok()
        .and_then(|s| super::os_release::parse(&s).ok());
    let (id, codename) = release
        .map(|r| (r.id, r.codename))
        .unwrap_or(("unknown".into(), "unknown".into()));
    if id != input.release.id || !input.release.codenames.contains(&codename) {
        return Err(Error::PlatformUnsupported { id, codename });
    }
    let available = files.path(&format!(
        "/etc/php/{}/mods-available/orbit-runtime.ini",
        input.version
    ));
    let enabled = files.path(&format!(
        "/etc/php/{}/fpm/conf.d/99-orbit-runtime.ini",
        input.version
    ));
    let unit = format!("php{}-fpm.service", input.version);
    let binary = files
        .path(&format!("/usr/sbin/php-fpm{}", input.version))
        .display()
        .to_string();
    let capture = || -> build::Result<Before> {
        let file = match fs::read(&available) {
            Ok(b) => Some(b),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
            Err(e) => return Err(build::Failure::io(e)),
        };
        let link = match fs::symlink_metadata(&enabled) {
            Ok(m) if m.is_symlink() => Some(fs::read_link(&enabled).map_err(build::Failure::io)?),
            Ok(_) => {
                return Err(build::Failure::io(
                    "The PHP runtime link is occupied by a regular file",
                ))
            }
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
            Err(e) => return Err(build::Failure::io(e)),
        };
        Ok(Before { file, link })
    };
    let failed = |error: build::Failure, restored| Error::Failed {
        reason: recipe::diagnostic(&serde_json::to_string(&error.data).expect("typed error")),
        restored,
    };
    let before = capture().map_err(|e| failed(e, true))?;
    let correct_link = fs::canonicalize(&enabled).ok().as_ref() == Some(&available);
    let changed = before.file.as_deref() != Some(input.runtime_ini.as_bytes()) || !correct_link;
    let mut touched = false;
    let action = (|| -> build::Result<Configured> {
        if changed {
            touched = true;
            build::runtime::write_ini(files, &available, input.runtime_ini.as_bytes())?;
            if !correct_link {
                build::remove(&enabled)?;
                symlink("../../mods-available/orbit-runtime.ini", &enabled)
                    .map_err(build::Failure::io)?;
            }
        }
        let info = build::invoke(host, &[&binary, "-i"], true)?.stdout;
        if !info.contains(&enabled.display().to_string())
            || expected.iter().any(|(key, value)| {
                !info
                    .lines()
                    .any(|l| l.trim() == format!("{key} => {value} => {value}"))
            })
        {
            return Err(build::Failure::io(
                "An unmanaged INI overrides the Orbit PHP runtime module",
            ));
        }
        let reloaded = changed
            && build::invoke(host, &["systemctl", "is-active", "--quiet", &unit], false)?.code == 0
            && build::invoke(host, &[&binary, "-t"], false)?.code == 0;
        if reloaded {
            build::invoke(host, &["systemctl", "reload-or-restart", &unit], true)?;
        }
        build::invoke(host, &["systemctl", "enable", &unit], true)?;
        build::invoke(
            host,
            &[
                &files
                    .path(&format!("/usr/bin/php{}", input.version))
                    .display()
                    .to_string(),
                "-v",
            ],
            true,
        )?;
        build::invoke(host, &[&binary, "-v"], true)?;
        if build::runtime::modules(host, &PathBuf::from(&binary))?.contains("pcov") {
            return Err(build::Failure::io("PHP-FPM loads PCOV"));
        }
        build::invoke(host, &["systemctl", "is-enabled", "--quiet", &unit], true)?;
        Ok(Configured { changed, reloaded })
    })();
    action.map_err(|error| {
        let mut restored = true;
        if touched {
            restored &= match &before.file {
                Some(b) => build::runtime::write_ini(files, &available, b),
                None => build::remove(&available),
            }
            .is_ok();
            restored &= build::remove(&enabled).is_ok();
            if let Some(link) = &before.link {
                restored &= symlink(link, &enabled).is_ok();
            }
            if build::invoke(host, &["systemctl", "is-active", "--quiet", &unit], false)
                .is_ok_and(|r| r.code == 0)
                && build::invoke(host, &[&binary, "-t"], false).is_ok_and(|r| r.code == 0)
            {
                restored &= build::invoke(host, &["systemctl", "reload-or-restart", &unit], false)
                    .is_ok_and(|r| r.code == 0);
            }
        }
        failed(error, restored)
    })
}
pub struct Configure;
impl Command for Configure {
    const NAME: &'static str = "php.configure-runtime";
    const SUMMARY: &'static str = "Publish and verify the shared PHP-FPM runtime module.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    const RESOURCES: &'static [&'static str] = &["packages"];
    type Input = Input;
    type Output = Configured;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("machine-php-runtime-{}", input.version))
    }
    fn run(input: Input, context: &Context) -> Result<Configured, Error> {
        configure(
            &mut recipe::Native(context),
            &recipe::Files::system(),
            &input,
        )
    }
}
