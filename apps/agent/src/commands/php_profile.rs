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
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub version: String,
    pub runtime_ini: String,
    pub release: build::Release,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Configured {
    pub changed: bool,
    pub reloaded: bool,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub deferred: Vec<String>,
}
fn size_bytes(value: &str) -> Option<u64> {
    let (number, multiplier) = match value.as_bytes().last() {
        Some(b'K') => (&value[..value.len() - 1], 1024_u64),
        Some(b'M') => (&value[..value.len() - 1], 1024_u64.pow(2)),
        Some(b'G') => (&value[..value.len() - 1], 1024_u64.pow(3)),
        _ => (value, 1),
    };
    if number.is_empty() || !number.bytes().all(|b| b.is_ascii_digit()) {
        return None;
    }
    number
        .parse::<u64>()
        .ok()
        .and_then(|n| n.checked_mul(multiplier))
        .filter(|bytes| *bytes > 0 && *bytes <= 1024_u64.pow(3))
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
    let mut settings = BTreeMap::<String, String>::new();
    for line in input
        .runtime_ini
        .lines()
        .map(str::trim)
        .filter(|l| !l.is_empty() && !l.starts_with(';'))
    {
        let (key, value) = line.split_once('=').ok_or_else(bad)?;
        let (key, value) = (key.trim(), value.trim());
        let valid = match key {
            "post_max_size" | "upload_max_filesize" => size_bytes(value).is_some(),
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
    if settings.len() != 8 {
        return Err(bad());
    }
    if size_bytes(&settings["upload_max_filesize"]).ok_or_else(bad)?
        > size_bytes(&settings["post_max_size"]).ok_or_else(bad)?
    {
        return Err(bad());
    }
    Ok(settings)
}
struct Before {
    file: Option<Vec<u8>>,
    link: Option<PathBuf>,
    shared: String,
}

fn fpm_configuration(text: &str) -> String {
    let mut global = true;
    let has_global = text.lines().any(|line| line.trim() == "[global]");
    let mut emitted = !has_global;
    let mut lines = if has_global {
        String::new()
    } else {
        String::from("[global]\nprocess_control_timeout = 30s\n")
    };
    for line in text.split_inclusive('\n') {
        let trimmed = line.trim();
        if trimmed.starts_with('[') {
            global = trimmed == "[global]";
        }
        if global && trimmed == "[global]" && !emitted {
            lines.push_str(line);
            lines.push_str("process_control_timeout = 30s\n");
            emitted = true;
            continue;
        }
        if global
            && trimmed
                .split_once('=')
                .is_some_and(|(key, _)| key.trim() == "process_control_timeout")
        {
            continue;
        }
        lines.push_str(line);
    }
    lines
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
    let binary_path = files.path(&format!("/usr/sbin/php-fpm{}", input.version));
    if !binary_path.try_exists().map_err(|error| Error::Failed {
        reason: error.to_string(),
        restored: true,
    })? {
        return Ok(Configured {
            changed: false,
            reloaded: false,
            deferred: Vec::new(),
        });
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
    let shared = files.path(&format!("/etc/php/{}/fpm/php-fpm.conf", input.version));
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
        let shared = fs::read_to_string(&shared).map_err(build::Failure::io)?;
        Ok(Before { file, link, shared })
    };
    let failed = |error: build::Failure, restored| Error::Failed {
        reason: recipe::diagnostic(&serde_json::to_string(&error.data).expect("typed error")),
        restored,
    };
    let before = capture().map_err(|e| failed(e, true))?;
    let correct_link = fs::canonicalize(&enabled)
        .ok()
        .zip(fs::canonicalize(&available).ok())
        .is_some_and(|(enabled, available)| enabled == available);
    let configuration = fpm_configuration(&before.shared);
    let changed = before.file.as_deref() != Some(input.runtime_ini.as_bytes())
        || !correct_link
        || before.shared != configuration;
    let mut touched = false;
    let mut reloaded_units = Vec::new();
    let mut deferred = Vec::new();
    let mut master_before = BTreeMap::new();
    let action = (|| -> build::Result<Configured> {
        if changed {
            touched = true;
            build::runtime::write_ini(files, &available, input.runtime_ini.as_bytes())?;
            build::runtime::write_ini(files, &shared, configuration.as_bytes())?;
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
        if changed {
            let units = build::runtime::fpm_units(host, files, &input.version, build::Fpm::All)?;
            deferred.extend(units.skipped);
            for (name, configuration) in units.active {
                if name != unit {
                    let previous =
                        fs::read_to_string(&configuration).map_err(build::Failure::io)?;
                    let next = fpm_configuration(&previous);
                    if next != previous {
                        master_before.insert(configuration.clone(), previous);
                        build::runtime::write_ini(files, &configuration, next.as_bytes())?;
                    }
                }
                let mut args = vec![binary.as_str(), "-t"];
                let path = configuration.display().to_string();
                if name != unit {
                    args.extend(["--fpm-config", &path]);
                }
                if build::invoke(host, &args, false)?.code != 0 {
                    deferred.push(name);
                    continue;
                }
                reloaded_units.push((
                    name.clone(),
                    args.iter().map(|s| (*s).to_owned()).collect::<Vec<_>>(),
                ));
                build::invoke(host, &["systemctl", "reload", &name], true)?;
            }
        }
        let reloaded = !reloaded_units.is_empty();
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
        Ok(Configured {
            changed,
            reloaded,
            deferred,
        })
    })();
    action.map_err(|error| {
        let mut restored = true;
        if touched {
            restored &= build::runtime::write_ini(files, &shared, before.shared.as_bytes()).is_ok();
            for (path, previous) in &master_before {
                restored &= build::runtime::write_ini(files, path, previous.as_bytes()).is_ok();
            }
            restored &= match &before.file {
                Some(b) => build::runtime::write_ini(files, &available, b),
                None => build::remove(&available),
            }
            .is_ok();
            restored &= build::remove(&enabled).is_ok();
            if let Some(link) = &before.link {
                restored &= symlink(link, &enabled).is_ok();
            }
            for (name, args) in &reloaded_units {
                let args: Vec<_> = args.iter().map(String::as_str).collect();
                let valid = build::invoke(host, &args, false).is_ok_and(|r| r.code == 0);
                restored &= valid
                    && build::invoke(host, &["systemctl", "reload", name], false)
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

#[cfg(test)]
mod tests {
    use super::*;

    const DEVELOPMENT: &str =
        include_str!("../../../gateway/tests/Fixtures/PhpRuntimeIni/app-dev.ini");
    const PRODUCTION: &str =
        include_str!("../../../gateway/tests/Fixtures/PhpRuntimeIni/app-prod.ini");

    fn input(ini: &str) -> Input {
        Input {
            version: "8.5".into(),
            runtime_ini: ini.into(),
            release: build::Release {
                id: "ubuntu".into(),
                codenames: vec!["resolute".into()],
            },
        }
    }

    #[test]
    fn accepts_the_actual_gateway_ini_fixtures_for_both_profiles() {
        for ini in [DEVELOPMENT, PRODUCTION] {
            let accepted = settings(&input(ini)).unwrap();
            assert_eq!(accepted.len(), 8);
            assert_eq!(accepted["post_max_size"], "64M");
            assert_eq!(accepted["upload_max_filesize"], "64M");
        }
    }

    #[test]
    fn sizes_stop_at_one_gib_and_upload_cannot_exceed_post_in_any_unit() {
        for (post, upload, valid) in [
            ("1G", "1024M", true),
            ("1048576K", "1073741824", true),
            ("64M", "65536K", true),
            ("1024", "1K", true),
            ("1G", "1073741825", false),
            ("1025M", "64M", false),
            ("2G", "64M", false),
            ("64M", "65537K", false),
            ("1K", "1025", false),
            ("1M", "1G", false),
        ] {
            let ini = DEVELOPMENT
                .replace("post_max_size = 64M", &format!("post_max_size = {post}"))
                .replace(
                    "upload_max_filesize = 64M",
                    &format!("upload_max_filesize = {upload}"),
                );
            assert_eq!(
                settings(&input(&ini)).is_ok(),
                valid,
                "post={post}, upload={upload}"
            );
        }
    }
}
