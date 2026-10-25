//! Shared archives are written only by the host; guests seed private manager state.
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use base64::{engine::general_purpose::STANDARD, Engine};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    fs,
    io::{self, Read},
    os::unix::fs::{MetadataExt, PermissionsExt},
    path::Path,
    time::Duration,
};
pub(super) const HOST: &str = "/var/lib/orbit/package-cache";
pub(super) const GUEST: &str = "/opt/orbit/package-cache";
const MARKER: &str = "orbit-package-cache-v1\n";
const MAX_ARCHIVE: u64 = 512 * 1024 * 1024;
command_errors! {
    pub enum Error {
        Invalid {} => "task_vm.package_cache_invalid_limit", "The package cache byte budget must be positive.";
        Failed { operation: String, path: String, reason: String } => "task_vm.package_cache_failed", "The trusted package cache operation failed.";
    }
}
fn failed(operation: &str, path: &Path, reason: impl ToString) -> Error {
    Error::Failed {
        operation: operation.into(),
        path: path.display().to_string(),
        reason: reason.to_string().chars().take(1200).collect(),
    }
}
#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Artifact {
    manager: String,
    package: String,
    version: String,
    url: String,
    integrity: Option<String>,
    key: String,
}
#[derive(Deserialize)]
#[serde(tag = "operation", rename_all = "snake_case", deny_unknown_fields)]
pub enum Input {
    Initialize {},
    Fill {
        artifacts: Vec<Artifact>,
        max_bytes: u64,
        github_token: Option<String>,
    },
}
#[derive(Debug, Default, Serialize, PartialEq)]
pub struct Filled {
    hashes: BTreeMap<String, String>,
    failures: BTreeMap<String, String>,
}
fn matches(pattern: &str, value: &str) -> bool {
    regex::Regex::new(pattern).unwrap().is_match(value)
}
fn github(url: &str) -> bool {
    url.starts_with("https://api.github.com/") || url.starts_with("https://codeload.github.com/")
}
fn allowed(url: &str, redirect: bool) -> bool {
    matches(
        r"\Ahttps://registry\.npmjs\.org/(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*/-/[a-z0-9][a-z0-9_.-]*-[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\.tgz\z",
        url,
    ) || matches(
        if redirect {
            r"\Ahttps://api\.github\.com/repositories/[1-9][0-9]*/zipball/[a-f0-9]{40}\z"
        } else {
            r"\Ahttps://api\.github\.com/repos/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/zipball/[a-f0-9]{40}\z"
        },
        url,
    ) || matches(
        r"\Ahttps://codeload\.github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/(?:legacy\.zip|zip)/[a-f0-9]{40}\z",
        url,
    )
}
fn redirect(from: &str, to: &str) -> bool {
    allowed(to, true)
        && github(from) == github(to)
        && (!github(to) || from.rsplit('/').next() == to.rsplit('/').next())
}
fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}
fn checksum(algorithm: &str) -> Option<Box<dyn sha2::digest::DynDigest>> {
    match algorithm {
        "sha1" => Some(Box::new(sha1::Sha1::new())),
        "sha256" => Some(Box::new(Sha256::new())),
        "sha512" => Some(Box::new(sha2::Sha512::new())),
        _ => None,
    }
}
fn hash(path: &Path, algorithm: &str) -> io::Result<Vec<u8>> {
    let mut digest = checksum(algorithm).ok_or_else(|| io::Error::other("Unsupported checksum"))?;
    let mut file = fs::File::open(path)?;
    let mut bytes = [0; 65536];
    loop {
        let size = file.read(&mut bytes)?;
        if size == 0 {
            break;
        }
        digest.update(&bytes[..size]);
    }
    Ok(digest.finalize().to_vec())
}
struct Integrity {
    algorithm: String,
    wanted: Vec<u8>,
}
fn validate(a: &Artifact) -> Result<Option<Integrity>, String> {
    let invalid = |message: &str| Err(message.to_string());
    let integrity = match a.manager.as_str() {
        "composer" => {
            if !matches(
                r"\Acomposer/[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*/[a-f0-9]{40}\.zip\z",
                &a.key,
            ) {
                return invalid("Invalid Composer cache key");
            }
            if a.key
                != format!(
                    "composer/{}/{}.zip",
                    a.package,
                    hex(&sha1::Sha1::digest(a.url.as_bytes()))
                )
            {
                return invalid("Composer key does not match the archive");
            }
            match &a.integrity {
                None => None,
                Some(value) if matches(r"\Asha1-[a-f0-9]{40}\z", value) => Some(Integrity {
                    algorithm: "sha1".into(),
                    wanted: (5..value.len())
                        .step_by(2)
                        .map(|i| u8::from_str_radix(&value[i..i + 2], 16).unwrap())
                        .collect(),
                }),
                _ => return invalid("Invalid Composer integrity"),
            }
        }
        "npm" => {
            if !matches(
                r"\A(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*\z",
                &a.package,
            ) || !matches(
                r"\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\z",
                &a.version,
            ) {
                return invalid("Invalid npm identity");
            }
            if a.url
                != format!(
                    "https://registry.npmjs.org/{}/-/{}-{}.tgz",
                    a.package,
                    a.package.rsplit('/').next().unwrap(),
                    a.version
                )
            {
                return invalid("Invalid npm archive URL");
            }
            let expected = a.integrity.as_deref().ok_or("Invalid npm integrity")?;
            let (algorithm, encoded) = expected.split_once('-').ok_or("Invalid npm integrity")?;
            let digest = checksum(algorithm).ok_or("Invalid npm integrity")?;
            let wanted = STANDARD
                .decode(encoded)
                .map_err(|_| "Invalid npm integrity")?;
            if wanted.len() != digest.output_size() || STANDARD.encode(&wanted) != encoded {
                return invalid("Invalid npm integrity");
            }
            if a.key
                != format!(
                    "npm/{}.tgz",
                    hex(&Sha256::digest(format!("{} {expected}", a.url)))
                )
            {
                return invalid("npm key does not match the archive");
            }
            Some(Integrity {
                algorithm: algorithm.into(),
                wanted,
            })
        }
        _ => return invalid("Unknown package cache manager"),
    };
    if !allowed(&a.url, false) {
        return invalid("Invalid public archive URL");
    }
    Ok(integrity)
}
fn initialize(root: &Path) -> Result<(), Error> {
    let refuse = |reason: &str| failed("initialize", root, reason);
    if fs::symlink_metadata(root).is_ok_and(|m| m.file_type().is_symlink()) {
        return Err(refuse("Cache root must not be a symlink"));
    }
    fs::create_dir_all(root).map_err(|e| refuse(&e.to_string()))?;
    let meta = fs::metadata(root).map_err(|e| refuse(&e.to_string()))?;
    if meta.uid() != rustix::process::geteuid().as_raw() || meta.mode() & 0o022 != 0 {
        return Err(refuse("Cache root has unsafe ownership or permissions"));
    }
    let marker = root.join(".orbit-owned");
    if !marker.exists() {
        if fs::read_dir(root)
            .map_err(|e| refuse(&e.to_string()))?
            .next()
            .is_some()
        {
            return Err(refuse("Refusing an unowned cache directory"));
        }
        fs::write(&marker, MARKER).map_err(|e| refuse(&e.to_string()))?;
    }
    if fs::read_to_string(marker).map_err(|e| refuse(&e.to_string()))? != MARKER {
        return Err(refuse("Cache ownership marker is invalid"));
    }
    Ok(())
}
fn inventory(root: &Path) -> io::Result<BTreeMap<String, String>> {
    match fs::read(root.join(".inventory.json")) {
        Ok(bytes) => serde_json::from_slice(&bytes).map_err(io::Error::other),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(BTreeMap::new()),
        Err(e) => Err(e),
    }
}
fn atomic(path: &Path, bytes: &[u8], mode: u32) -> io::Result<()> {
    use std::io::Write;
    let temporary = path.with_extension(format!("tmp-{:016x}", rand::random::<u64>()));
    let result = (|| {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&temporary)?;
        file.write_all(bytes)?;
        file.sync_all()?;
        fs::set_permissions(&temporary, fs::Permissions::from_mode(mode))?;
        fs::rename(&temporary, path)
    })();
    let _ = fs::remove_file(temporary);
    result
}
fn used(root: &Path) -> io::Result<u64> {
    let mut size = 0;
    for entry in fs::read_dir(root)? {
        let entry = entry?;
        let meta = fs::symlink_metadata(entry.path())?;
        if meta.is_dir() {
            size += used(&entry.path())?;
        } else if meta.is_file() {
            size += meta.len();
        }
    }
    Ok(size)
}
trait Fetch {
    fn download(&mut self, url: &str, target: &Path, limit: u64) -> Result<u64, String>;
}
fn fill(
    root: &Path,
    artifacts: &[Artifact],
    max_bytes: u64,
    fetch: &mut impl Fetch,
) -> Result<Filled, Error> {
    if max_bytes == 0 {
        return Err(Error::Invalid {});
    }
    initialize(root)?;
    let mut inventory = inventory(root).map_err(|e| failed("fill", root, e))?;
    let mut used = used(root).map_err(|e| failed("fill", root, e))?;
    let mut result = Filled::default();
    for a in artifacts {
        let filled = (|| -> Result<String, String> {
            let integrity = validate(a)?;
            let target = root.join(&a.key);
            if target.is_file() {
                let digest = hex(&hash(&target, "sha256").map_err(|_| "Archive read failed")?);
                if inventory.get(&a.key) == Some(&digest) {
                    if let Some(i) = &integrity {
                        if hash(&target, &i.algorithm).map_err(|_| "Archive read failed")?
                            != i.wanted
                        {
                            return Err("Archive checksum mismatch".into());
                        }
                    }
                    return Ok(digest);
                }
                let quarantine = root.join("quarantine");
                fs::create_dir_all(&quarantine).map_err(|_| "Cache write failed")?;
                fs::rename(
                    &target,
                    quarantine.join(format!("{:032x}", rand::random::<u128>())),
                )
                .map_err(|_| "Cache write failed")?;
            }
            fs::create_dir_all(target.parent().unwrap()).map_err(|_| "Cache write failed")?;
            let temporary = root.join(format!(".download-{:016x}", rand::random::<u64>()));
            let downloaded = (|| {
                let size = fetch.download(&a.url, &temporary, max_bytes.saturating_sub(used))?;
                if let Some(i) = &integrity {
                    if hash(&temporary, &i.algorithm).map_err(|_| "Archive read failed")?
                        != i.wanted
                    {
                        return Err("Archive checksum mismatch".into());
                    }
                }
                let digest = hex(&hash(&temporary, "sha256").map_err(|_| "Archive read failed")?);
                fs::set_permissions(&temporary, fs::Permissions::from_mode(0o444))
                    .map_err(|_| "Cache write failed")?;
                fs::rename(&temporary, &target).map_err(|_| "Cache write failed")?;
                used += size;
                inventory.insert(a.key.clone(), digest.clone());
                atomic(
                    &root.join(".inventory.json"),
                    &serde_json::to_vec(&inventory).unwrap(),
                    0o644,
                )
                .map_err(|_| "Cache inventory write failed")?;
                Ok(digest)
            })();
            let _ = fs::remove_file(temporary);
            downloaded
        })();
        match filled {
            Ok(digest) => {
                result
                    .hashes
                    .insert(a.key.clone(), format!("sha256-{digest}"));
            }
            Err(reason) => {
                result.failures.insert(a.key.clone(), reason);
            }
        }
    }
    Ok(result)
}
struct Network<'a> {
    token: Option<&'a str>,
    context: &'a Context,
}
fn archive_request(
    client: &reqwest::Client,
    url: &str,
    token: Option<&str>,
) -> Result<reqwest::Request, String> {
    let mut request = client.get(url).header("User-Agent", "Orbit-package-cache");
    if github(url) {
        request = request.bearer_auth(
            token
                .filter(|s| !s.is_empty())
                .ok_or("GitHub installation token unavailable")?,
        );
    }
    request
        .build()
        .map_err(|_| "Download configuration failed".into())
}
impl Fetch for Network<'_> {
    fn download(&mut self, url: &str, target: &Path, limit: u64) -> Result<u64, String> {
        use std::io::Write;
        if !allowed(url, false) {
            return Err("Rejected archive URL".into());
        }
        let token = if github(url) {
            Some(
                self.token
                    .filter(|s| !s.is_empty())
                    .ok_or("GitHub installation token unavailable")?,
            )
        } else {
            None
        };
        let timeout = self.context.remaining().min(Duration::from_secs(60));
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|_| "Download runtime failed")?
            .block_on(async {
                let client = reqwest::Client::builder()
                    .no_proxy()
                    .redirect(reqwest::redirect::Policy::none())
                    .timeout(timeout)
                    .build()
                    .map_err(|_| "Download configuration failed")?;
                let mut current = url.to_string();
                for _ in 0..10 {
                    let request = archive_request(&client, &current, token)?;
                    let mut response = client
                        .execute(request)
                        .await
                        .map_err(|_| "Archive download failed")?;
                    if response.status().is_redirection() {
                        let next = response
                            .headers()
                            .get("location")
                            .and_then(|s| s.to_str().ok())
                            .and_then(|s| response.url().join(s).ok())
                            .ok_or("Rejected archive redirect")?
                            .to_string();
                        if !redirect(&current, &next) {
                            return Err("Rejected archive redirect".into());
                        }
                        current = next;
                        continue;
                    }
                    if !response.status().is_success() {
                        return Err(format!("HTTP {}", response.status().as_u16()));
                    }
                    let mut output = fs::OpenOptions::new()
                        .write(true)
                        .create_new(true)
                        .open(target)
                        .map_err(|_| "Cache write failed")?;
                    let mut size = 0;
                    while let Some(chunk) = response
                        .chunk()
                        .await
                        .map_err(|_| "Archive download failed")?
                    {
                        size += chunk.len() as u64;
                        if size > MAX_ARCHIVE {
                            return Err("Archive exceeds size limit".into());
                        }
                        if size > limit {
                            return Err("Cache full".into());
                        }
                        output.write_all(&chunk).map_err(|_| "Cache write failed")?;
                    }
                    output.sync_all().map_err(|_| "Cache write failed")?;
                    return Ok(size);
                }
                Err("Too many archive redirects".into())
            })
    }
}
pub struct HostCache;
impl Command for HostCache {
    const NAME: &'static str = "package-cache.host";
    const SUMMARY: &'static str =
        "Initialize or fill the owned host cache with verified public archives.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(1600);
    const RESOURCES: &'static [&'static str] = &["package-cache"];
    type Input = Input;
    type Output = Filled;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, context: &Context) -> Result<Filled, Error> {
        match input {
            Input::Initialize {} => {
                initialize(Path::new(HOST))?;
                Ok(Filled::default())
            }
            Input::Fill {
                artifacts,
                max_bytes,
                github_token,
            } => fill(
                Path::new(HOST),
                &artifacts,
                max_bytes,
                &mut Network {
                    token: github_token.as_deref(),
                    context,
                },
            ),
        }
    }
}
mod seed;
pub use seed::SeedCache;
#[cfg(test)]
mod tests;
