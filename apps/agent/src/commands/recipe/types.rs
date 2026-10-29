use super::Failure;
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;

#[derive(schemars::JsonSchema, Debug, Clone, Copy, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum Architecture {
    Amd64,
    Arm64,
}
impl Architecture {
    pub fn name(self) -> &'static str {
        match self {
            Self::Amd64 => "amd64",
            Self::Arm64 => "arm64",
        }
    }
}
#[derive(schemars::JsonSchema, Debug, Clone, Copy, Deserialize, Serialize, PartialEq)]
#[serde(rename_all = "lowercase")]
pub enum Source {
    Ubuntu,
    Artifact,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Artifact {
    #[serde(default)]
    pub url: Option<String>,
    #[serde(default)]
    pub filename: Option<String>,
    #[serde(default)]
    pub version: Option<String>,
    #[serde(default)]
    pub sha256: Option<String>,
    #[serde(default)]
    pub sha512: Option<String>,
}
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Tool {
    pub source: Source,
    pub version: String,
    #[serde(default)]
    pub package: Option<String>,
    #[serde(default)]
    pub artifacts: BTreeMap<String, Artifact>,
    #[serde(default)]
    pub lock_sha256: Option<String>,
    #[serde(default)]
    pub source_commit: Option<String>,
}
pub type Tools = BTreeMap<String, Tool>;
#[derive(schemars::JsonSchema, Debug, Clone, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub user: String,
    pub snapshot: String,
    pub architecture: Architecture,
    #[serde(default)]
    pub git_packages: Vec<String>,
    #[serde(default)]
    pub artifact_mirror: String,
    pub tools: Tools,
    #[serde(default)]
    pub vp_lock: Option<String>,
}
pub fn valid_package(value: &str) -> bool {
    regex::Regex::new(r"\A[a-z0-9][a-z0-9+.-]*\z")
        .unwrap()
        .is_match(value)
}
pub fn valid_version(value: &str) -> bool {
    regex::Regex::new(r"\A[0-9][0-9A-Za-z.+:~_-]*\z")
        .unwrap()
        .is_match(value)
}
pub fn valid_digest(value: &str, length: usize) -> bool {
    value.len() == length
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}
pub fn https(value: &str) -> bool {
    reqwest::Url::parse(value).is_ok_and(|url| {
        url.scheme() == "https"
            && url.host_str().is_some()
            && url.username().is_empty()
            && url.password().is_none()
            && url.fragment().is_none()
    })
}
impl Input {
    pub fn validate(&self) -> Result<(), Failure> {
        let invalid = || Failure::new("input", "The recipe pin or architecture is invalid");
        if !regex::Regex::new(r"\A[a-z_][a-z0-9_-]*\z")
            .unwrap()
            .is_match(&self.user)
            || self.user == "root"
            || !regex::Regex::new(r"\A[0-9]{8}T[0-9]{6}Z\z")
                .unwrap()
                .is_match(&self.snapshot)
            || (!self.artifact_mirror.is_empty() && !https(&self.artifact_mirror))
            || self.git_packages.iter().any(|name| !valid_package(name))
        {
            return Err(invalid());
        }
        for (name, pin) in &self.tools {
            if name == super::browser::NAME && !super::browser::valid(pin, self.architecture.name())
            {
                return Err(invalid());
            }
            if !valid_package(name) || !valid_version(&pin.version) {
                return Err(invalid());
            }
            match pin.source {
                Source::Ubuntu => {
                    if pin.package.as_deref().is_none_or(|p| !valid_package(p))
                        || !pin.artifacts.is_empty()
                    {
                        return Err(invalid());
                    }
                }
                Source::Artifact => {
                    let artifact = pin
                        .artifacts
                        .get(self.architecture.name())
                        .ok_or_else(invalid)?;
                    if !artifact
                        .sha256
                        .as_deref()
                        .is_some_and(|v| valid_digest(v, 64))
                        && !artifact
                            .sha512
                            .as_deref()
                            .is_some_and(|v| valid_digest(v, 128))
                    {
                        return Err(invalid());
                    }
                    // A Pi artifact is release-local; its fixed delivery is handled by the image Pi phase.
                    if name != "pi" && artifact.url.as_deref().is_none_or(|url| !https(url)) {
                        return Err(invalid());
                    }
                    if artifact
                        .version
                        .as_deref()
                        .is_some_and(|v| !valid_version(v))
                    {
                        return Err(invalid());
                    }
                }
            }
            if name == "vp"
                && pin
                    .lock_sha256
                    .as_deref()
                    .is_none_or(|v| !valid_digest(v, 64))
            {
                return Err(invalid());
            }
        }
        Ok(())
    }
    pub fn git(&self) -> Tools {
        self.git_packages
            .iter()
            .filter_map(|name| self.tools.get(name).map(|pin| (name.clone(), pin.clone())))
            .collect()
    }
    pub fn incus(&self) -> Tools {
        ["incus", "incus-base", "incus-client"]
            .into_iter()
            .filter_map(|name| {
                self.tools
                    .get(name)
                    .filter(|p| p.source == Source::Artifact)
                    .map(|p| (name.into(), p.clone()))
            })
            .collect()
    }
}
impl Tool {
    pub fn deb(&self) -> bool {
        self.source == Source::Artifact
            && self
                .artifacts
                .values()
                .any(|a| a.url.as_deref().is_some_and(|u| u.ends_with(".deb")))
    }
}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
#[serde(rename_all = "snake_case")]
pub enum Status {
    Current,
    Missing,
    Different,
    OlderThanRecipe,
    NewerThanRecipe,
    UnsupportedNewer,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Assessment {
    pub source: Source,
    pub version: String,
    pub installed: Option<String>,
    pub changed: bool,
    pub status: Status,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub refusal: Option<String>,
}
