//! A release's immutable agent delivery is checked against its own Git tree before publication.
use super::*;
use std::collections::BTreeMap;

const MANIFEST: &str = "apps/agent/delivery/manifest.json";
const PLATFORMS: [&str; 4] = [
    "linux-x86_64",
    "linux-aarch64",
    "macos-aarch64",
    "macos-x86_64",
];

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub checkout: String,
    pub sha: String,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct InstallInput {
    pub checkout: String,
    pub sha: String,
    pub step_lock: PathBuf,
    pub manifest: Manifest,
}
#[derive(schemars::JsonSchema, Debug, Deserialize, Serialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct Manifest {
    pub commit: String,
    pub version: String,
    pub artifacts: BTreeMap<String, Artifact>,
}
#[derive(schemars::JsonSchema, Debug, Deserialize, Serialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct Artifact {
    pub sha256: String,
    pub path: String,
    pub url: String,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Inspection {
    pub commit: String,
    pub version: String,
    pub installed: bool,
}
fn source(input: &Input, host: &mut impl Host) -> Result<(PathBuf, Inspection), Error> {
    if !hex(&input.sha, 40) {
        return Err(Failure::CommitInvalid {}.into());
    }
    let layout = layout(&input.checkout)?;
    let path = release(&layout, &input.sha[..12])?;
    if commit_at(&path, "HEAD").as_ref() != Some(&input.sha) {
        return Err(Failure::Conflict {}.into());
    }
    let paths: Vec<String> = serde_json::from_str(include_str!("../../../source-paths.json"))
        .expect("compiled agent source paths");
    let mut args = vec!["log", "-1", "--format=%H", "HEAD", "--"];
    args.extend(paths.iter().map(String::as_str));
    let commit = git(
        host,
        &path,
        &args,
        &GitRead::default(),
        Failure::Delivery {},
    )?
    .trim()
    .to_owned();
    let cargo: toml::Value = fs::read_to_string(path.join("apps/agent/Cargo.toml"))
        .ok()
        .and_then(|s| toml::from_str(&s).ok())
        .ok_or(Failure::Delivery {})?;
    let version = cargo
        .get("package")
        .and_then(|p| p.get("version"))
        .and_then(toml::Value::as_str)
        .filter(|s| !s.is_empty())
        .ok_or(Failure::Delivery {})?
        .to_owned();
    if !hex(&commit, 40) {
        return Err(Failure::Delivery {}.into());
    }
    Ok((
        path,
        Inspection {
            commit,
            version,
            installed: false,
        },
    ))
}
fn validate(manifest: &Manifest, source: &Inspection) -> Result<(), Error> {
    if manifest.commit != source.commit
        || manifest.version != source.version
        || manifest.artifacts.len() != PLATFORMS.len()
    {
        return Err(Failure::Delivery {}.into());
    }
    let mut repository = None;
    for platform in PLATFORMS {
        let artifact = manifest
            .artifacts
            .get(platform)
            .ok_or(Failure::Delivery {})?;
        let file = format!("orbit-agent-{platform}");
        let suffix = format!("/releases/download/agent-v{}/{}", manifest.commit, file);
        let repo = artifact
            .url
            .strip_prefix("https://github.com/")
            .and_then(|s| s.strip_suffix(&suffix))
            .ok_or(Failure::Delivery {})?;
        let parts: Vec<_> = repo.split('/').collect();
        if artifact.path != file
            || !hex(&artifact.sha256, 64)
            || parts.len() != 2
            || parts.iter().any(|s| {
                s.is_empty()
                    || *s == "."
                    || *s == ".."
                    || !s
                        .bytes()
                        .all(|b| b.is_ascii_alphanumeric() || b"._-".contains(&b))
            })
            || repository.is_some_and(|previous| previous != repo)
        {
            return Err(Failure::Delivery {}.into());
        }
        repository = Some(repo);
    }
    Ok(())
}
fn existing(path: &Path, source: &Inspection) -> Result<bool, Error> {
    let manifest = path.join(MANIFEST);
    let parent = manifest.parent().unwrap();
    app_directory(path, "apps/agent")?;
    if owned(parent)?.is_none() {
        return Ok(false);
    }
    directory(parent)?;
    let Some(metadata) = owned(&manifest)? else {
        return Ok(false);
    };
    if !metadata.is_file() || metadata.len() > 65536 {
        return Err(Failure::Delivery {}.into());
    }
    let value: Manifest =
        serde_json::from_slice(&fs::read(manifest).map_err(|_| Failure::Delivery {})?)
            .map_err(|_| Failure::Delivery {})?;
    validate(&value, source)?;
    Ok(true)
}
pub(super) fn inspect(input: &Input, host: &mut impl Host) -> Result<Inspection, Error> {
    let (path, mut source) = source(input, host)?;
    source.installed = existing(&path, &source)?;
    Ok(source)
}
pub(super) fn require(input: &Input, host: &mut impl Host) -> Result<(), Error> {
    if !inspect(input, host)?.installed {
        return Err(Failure::Delivery {}.into());
    }
    Ok(())
}
pub(super) fn install(
    input: &InstallInput,
    host: &mut impl Host,
    deadline: Instant,
) -> Result<Changed, Error> {
    let _step = hold_step(&input.step_lock, deadline)?;
    let (path, source) = source(
        &Input {
            checkout: input.checkout.clone(),
            sha: input.sha.clone(),
        },
        host,
    )?;
    validate(&input.manifest, &source)?;
    if existing(&path, &source)? {
        return Ok(Changed { changed: false });
    }
    let layout = layout(&input.checkout)?;
    if prepared(&layout, &input.sha[..12])?.is_some() {
        return Err(Failure::Delivery {}.into());
    }
    let parent = path.join("apps/agent/delivery");
    mkdir(&parent, 0o755, Failure::Delivery {})?;
    let candidate = parent.join(format!(".manifest-{:016x}", rand::random::<u64>()));
    let result = (|| -> io::Result<()> {
        let mut file = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o644)
            .open(&candidate)?;
        serde_json::to_writer(&mut file, &input.manifest)?;
        file.write_all(b"\n")?;
        file.sync_all()?;
        fs::rename(&candidate, path.join(MANIFEST))
    })();
    if result.is_err() {
        let _ = fs::remove_file(&candidate);
    }
    result.map_err(|_| Failure::Delivery {})?;
    Ok(Changed { changed: true })
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "gateway.inspect-release-agent-delivery";
    const SUMMARY: &'static str =
        "Resolve the prepared checkout's agent source identity and verify its delivery.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Inspection;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Input, context: &Context) -> Result<Inspection, Error> {
        inspect(&input, &mut Native(context))
    }
}
pub struct Install;
impl Command for Install {
    const NAME: &'static str = "gateway.install-release-agent-delivery";
    const SUMMARY: &'static str =
        "Validate and atomically install the immutable agent manifest in a release checkout.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = InstallInput;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &InstallInput) -> Option<String> {
        Some(format!("gateway-release:{}", input.checkout))
    }
    fn run(input: InstallInput, context: &Context) -> Result<Changed, Error> {
        install(&input, &mut Native(context), context.deadline())
    }
}
