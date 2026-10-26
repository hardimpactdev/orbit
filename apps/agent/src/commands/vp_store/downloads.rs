use super::{Environment, Error, Result};
use base64::{engine::general_purpose::STANDARD, Engine};
use reqwest::{header, Url};
use sha2::{Digest, Sha224, Sha256, Sha384, Sha512};
use std::{
    collections::BTreeMap,
    time::{Duration, Instant},
};

const MAX_BYTES: usize = 256 * 1024 * 1024;
type Config = BTreeMap<String, String>;
pub(super) trait Download {
    fn get(&mut self, url: &str, config: &Config) -> Result<Vec<u8>>;
}
pub(super) struct Native(pub Instant);
fn url(value: &str) -> Result<Url> {
    let url = Url::parse(value).map_err(|_| Error::Invalid {})?;
    if url.scheme() != "https"
        || !url.username().is_empty()
        || url.password().is_some()
        || url.host_str().is_none()
    {
        return Err(Error::Invalid {});
    }
    Ok(url)
}
fn authorization(url: &Url, config: &Config) -> Option<String> {
    let mut authorization = None;
    for (key, value) in config {
        for (suffix, kind) in [(":_authToken", "Bearer"), (":_auth", "Basic")] {
            if !key.starts_with("//") {
                continue;
            }
            let Some(scope) = key
                .strip_suffix(suffix)
                .and_then(|s| Url::parse(&format!("https:{s}")).ok())
            else {
                continue;
            };
            let prefix = format!("{}/", scope.path().trim_end_matches('/'));
            if scope.origin() == url.origin() && url.path().starts_with(&prefix) {
                authorization = Some(format!("{kind} {value}"));
            }
        }
    }
    authorization
}
impl Download for Native {
    fn get(&mut self, value: &str, config: &Config) -> Result<Vec<u8>> {
        let initial = url(value)?;
        let remaining = self
            .0
            .saturating_duration_since(Instant::now())
            .min(Duration::from_secs(60));
        if remaining.is_zero() {
            return Err(Error::Failed {});
        }
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|_| Error::Failed {})?
            .block_on(async {
                let client = reqwest::Client::builder()
                    .redirect(reqwest::redirect::Policy::none())
                    .build()
                    .map_err(|_| Error::Failed {})?;
                tokio::time::timeout(remaining, async {
                    let mut current = initial.clone();
                    for _ in 0..10 {
                        let mut request = client.get(current.clone());
                        if let Some(value) = authorization(&current, config) {
                            request = request.header(header::AUTHORIZATION, value);
                        }
                        let mut response = request.send().await.map_err(|_| Error::Failed {})?;
                        if response.status().is_redirection() {
                            let next = response
                                .headers()
                                .get(header::LOCATION)
                                .and_then(|v| v.to_str().ok())
                                .and_then(|v| current.join(v).ok())
                                .ok_or(Error::Failed {})?;
                            let next = url(next.as_str())?;
                            if next.origin() != initial.origin() {
                                return Err(Error::Failed {});
                            }
                            current = next;
                            continue;
                        }
                        if !response.status().is_success()
                            || response
                                .content_length()
                                .is_some_and(|v| v > MAX_BYTES as u64)
                        {
                            return Err(Error::Failed {});
                        }
                        let mut bytes = vec![];
                        while let Some(chunk) =
                            response.chunk().await.map_err(|_| Error::Failed {})?
                        {
                            if bytes.len() + chunk.len() > MAX_BYTES {
                                return Err(Error::Failed {});
                            }
                            bytes.extend_from_slice(&chunk);
                        }
                        return Ok(bytes);
                    }
                    Err(Error::Failed {})
                })
                .await
                .map_err(|_| Error::Failed {})?
            })
    }
}
fn config(text: &str) -> Config {
    let variables = regex::Regex::new(r"\$\{([^}]+)\}").unwrap();
    text.lines()
        .map(str::trim)
        .filter(|line| !line.starts_with(['#', ';']))
        .filter_map(|line| line.split_once('='))
        .map(|(key, value)| {
            (
                key.trim().into(),
                variables
                    .replace_all(value.trim(), |captures: &regex::Captures<'_>| {
                        std::env::var(&captures[1]).unwrap_or_default()
                    })
                    .into_owned(),
            )
        })
        .collect()
}
fn digest(algorithm: &str, bytes: &[u8]) -> Option<Vec<u8>> {
    Some(match algorithm {
        "sha224" => Sha224::digest(bytes).to_vec(),
        "sha256" => Sha256::digest(bytes).to_vec(),
        "sha384" => Sha384::digest(bytes).to_vec(),
        "sha512" => Sha512::digest(bytes).to_vec(),
        _ => return None,
    })
}
fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}
pub(super) fn valid_pin(pin: &str) -> bool {
    pin.split_once('.').is_some_and(|(algorithm, value)| {
        digest(&algorithm.to_ascii_lowercase(), b"").is_some_and(|digest| {
            value.len() == digest.len() * 2 && value.bytes().all(|b| b.is_ascii_hexdigit())
        })
    })
}
fn integrity(bytes: &[u8], value: &str) -> bool {
    value.split_whitespace().any(|token| {
        token.split_once('-').is_some_and(|(algorithm, expected)| {
            algorithm != "sha224"
                && digest(algorithm, bytes)
                    .is_some_and(|digest| STANDARD.encode(digest) == expected)
        })
    })
}
pub(super) fn node(
    download: &mut impl Download,
    version: &str,
) -> Result<(Vec<u8>, String, String)> {
    let system = if cfg!(target_os = "macos") {
        "darwin"
    } else {
        "linux"
    };
    let arch = if cfg!(target_arch = "aarch64") {
        "arm64"
    } else {
        "x64"
    };
    let source = format!("node-v{version}-{system}-{arch}");
    let filename = format!("{source}.tar.gz");
    let base = format!("https://nodejs.org/dist/v{version}/");
    let sums = download.get(&format!("{base}SHASUMS256.txt"), &Config::new())?;
    let sums = std::str::from_utf8(&sums).map_err(|_| Error::Failed {})?;
    let expected = sums
        .lines()
        .find_map(|line| {
            let parts: Vec<_> = line.split_whitespace().collect();
            (parts.len() == 2
                && parts[1] == filename
                && parts[0].len() == 64
                && parts[0].bytes().all(|b| b.is_ascii_hexdigit()))
            .then(|| parts[0])
        })
        .ok_or(Error::Failed {})?;
    let bytes = download.get(&format!("{base}{filename}"), &Config::new())?;
    if hex(&Sha256::digest(&bytes)) != expected {
        return Err(Error::Failed {});
    }
    Ok((bytes, source, format!("sha256-{expected}")))
}
pub(super) fn manager(
    download: &mut impl Download,
    env: &Environment,
    npmrc: &str,
) -> Result<(Vec<u8>, String)> {
    let (bytes, checksum) = registry_archive(
        download,
        &env.package_manager,
        &env.package_manager_version,
        &config(npmrc),
    )?;
    if let Some(pin) = &env.package_manager_hash {
        let (algorithm, expected) = pin.split_once('.').ok_or(Error::Invalid {})?;
        if hex(&digest(&algorithm.to_ascii_lowercase(), &bytes).ok_or(Error::Invalid {})?)
            != expected.to_ascii_lowercase()
        {
            return Err(Error::Failed {});
        }
    }
    Ok((bytes, checksum))
}

pub(super) fn native_pnpm(
    download: &mut impl Download,
    manifest: &serde_json::Value,
    env: &Environment,
    npmrc: &str,
) -> Result<Option<(Vec<u8>, String, String)>> {
    if env.package_manager != "pnpm" {
        return Ok(None);
    }
    let system = if cfg!(target_os = "macos") {
        "darwin"
    } else {
        "linux"
    };
    let architecture = if cfg!(target_arch = "aarch64") {
        "arm64"
    } else {
        "x64"
    };
    let name = format!("@pnpm/exe.{system}-{architecture}");
    let Some(version) = manifest["optionalDependencies"].get(&name) else {
        return Ok(None);
    };
    if version.as_str() != Some(&env.package_manager_version) {
        return Err(Error::Invalid {});
    }
    let (bytes, checksum) = registry_archive(
        download,
        &name,
        &env.package_manager_version,
        &config(npmrc),
    )?;
    Ok(Some((bytes, name, checksum)))
}

fn registry_archive(
    download: &mut impl Download,
    name: &str,
    version: &str,
    config: &Config,
) -> Result<(Vec<u8>, String)> {
    let scoped = name
        .split_once('/')
        .filter(|(scope, _)| scope.starts_with('@'))
        .and_then(|(scope, _)| config.get(&format!("{scope}:registry")));
    let registry = scoped
        .or_else(|| config.get("registry"))
        .map(String::as_str)
        .unwrap_or("https://registry.npmjs.org/")
        .trim_end_matches('/');
    let metadata_url = format!("{registry}/{name}/{version}");
    url(&metadata_url)?;
    let metadata: serde_json::Value =
        serde_json::from_slice(&download.get(&metadata_url, config)?)?;
    if metadata["name"] != name || metadata["version"] != version {
        return Err(Error::Failed {});
    }
    let checksum = metadata["dist"]["integrity"]
        .as_str()
        .ok_or(Error::Failed {})?;
    let tarball = metadata["dist"]["tarball"]
        .as_str()
        .ok_or(Error::Failed {})?;
    url(tarball)?;
    let bytes = download.get(tarball, config)?;
    if !integrity(&bytes, checksum) {
        return Err(Error::Failed {});
    }
    Ok((bytes, checksum.into()))
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn registry_credentials_stay_within_their_origin_and_path() {
        let config = config("# comment\nregistry=https://example.test/private\n//example.test/private/:_authToken=secret\n//other.test/:_auth=dXNlcjpwYXNz\n");
        assert_eq!(
            authorization(&url("https://example.test/private/file").unwrap(), &config),
            Some("Bearer secret".into())
        );
        for path in [
            "https://example.test/private-other/file",
            "https://example.test/public/file",
            "https://example.test:444/private/file",
            "https://foreign.test/private/file",
        ] {
            assert_eq!(authorization(&url(path).unwrap(), &config), None);
        }
        assert_eq!(
            authorization(&url("https://other.test/file").unwrap(), &config),
            Some("Basic dXNlcjpwYXNz".into())
        );
        for path in [
            "http://example.test/",
            "https://user:secret@example.test/",
            "file:///tmp/archive",
        ] {
            assert!(url(path).is_err());
        }
    }
    #[test]
    fn integrity_requires_a_matching_supported_digest_and_project_pin_is_exact() {
        for algorithm in ["sha256", "sha384", "sha512"] {
            let value = format!(
                "{algorithm}-{}",
                STANDARD.encode(digest(algorithm, b"archive").unwrap())
            );
            assert!(integrity(b"archive", &value));
            assert!(!integrity(b"changed", &value));
        }
        assert!(!integrity(b"archive", "sha1-invalid sha512-invalid"));
        assert!(valid_pin(&format!("sha224.{}", "a".repeat(56))));
        assert!(!valid_pin("sha256.aa"));
        assert!(!valid_pin(&format!("sha256.{}", "z".repeat(64))));
    }
}
