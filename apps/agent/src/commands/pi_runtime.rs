//! Private Pi provider settings and credentials for the managed account.
use super::recipe::{self, store::Account, Files};
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{
    fs, io,
    os::unix::fs::{DirBuilderExt, MetadataExt, PermissionsExt},
    path::Path,
    time::Duration,
};

command_errors! {
    pub enum Error {
        Invalid { reason: String } => "pi.runtime_invalid", "The Pi runtime input is invalid.";
        Failed { reason: String } => "pi.runtime_failed", "The Pi runtime files could not be prepared.";
    }
}
fn invalid(reason: &str) -> Error {
    Error::Invalid {
        reason: reason.into(),
    }
}
fn failed(reason: impl ToString) -> Error {
    Error::Failed {
        reason: recipe::diagnostic(&reason.to_string()),
    }
}
impl From<recipe::Failure> for Error {
    fn from(error: recipe::Failure) -> Self {
        failed(error.reason)
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub user: String,
    pub origin: String,
    pub model_key: String,
    pub token: String,
    pub models: Vec<String>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub changed: bool,
    pub binary: std::path::PathBuf,
    pub token_file: std::path::PathBuf,
}

fn validate(input: &Input) -> Result<(), Error> {
    let origin =
        reqwest::Url::parse(&input.origin).map_err(|_| invalid("Invalid model router origin"))?;
    if !matches!(origin.scheme(), "http" | "https")
        || origin.host_str().is_none()
        || !origin.username().is_empty()
        || origin.password().is_some()
        || origin.query().is_some()
        || origin.fragment().is_some()
        || origin.path() != "/"
    {
        return Err(invalid(
            "Expected an HTTP model router origin without credentials or a path",
        ));
    }
    for credential in [&input.model_key, &input.token] {
        if !(32..=4096).contains(&credential.len())
            || !credential.bytes().all(|b| b.is_ascii_graphic())
        {
            return Err(invalid(
                "Pi credentials must contain 32 to 4096 printable ASCII bytes",
            ));
        }
    }
    if input.models.is_empty()
        || input.models.len() > 256
        || input.models.iter().any(|model| {
            model.is_empty() || model.len() > 256 || model.chars().any(char::is_control)
        })
    {
        return Err(invalid("Expected a bounded nonempty list of model IDs"));
    }
    Ok(())
}

fn existing(
    path: &Path,
    account: &Account,
    directory: bool,
) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(meta)
            if meta.uid() == account.uid
                && if directory {
                    meta.is_dir()
                } else {
                    meta.is_file()
                } =>
        {
            Ok(Some(meta))
        }
        Ok(_) => Err(failed(
            "Pi paths must be real files or directories owned by the managed account",
        )),
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(error) => Err(failed(error)),
    }
}

pub fn configure(files: &Files, account: &Account, input: &Input) -> Result<Output, Error> {
    validate(input)?;
    recipe::store::check_home(&account.home, account, false)?;
    let parent = account.home.join(".pi");
    let directory = parent.join("agent");
    let models = directory.join("models.json");
    let token = directory.join("orbit-token");
    for path in [&parent, &directory] {
        existing(path, account, true)?;
    }
    for path in [&models, &token] {
        existing(path, account, false)?;
    }
    let contents = serde_json::to_vec_pretty(&serde_json::json!({"providers": {"orbit-task-vm": {
        "baseUrl": format!("{}/v1", input.origin.trim_end_matches('/')),
        "api": "openai-responses", "apiKey": input.model_key,
        "models": input.models.iter().map(|model| serde_json::json!({"id": model, "reasoning": true})).collect::<Vec<_>>()
    }}})).map_err(|_| failed("Pi provider configuration could not be encoded"))?;
    let mut changed = false;
    for path in [&parent, &directory] {
        match existing(path, account, true)? {
            Some(meta) if meta.mode() & 0o7777 == 0o700 && meta.gid() == account.gid => {}
            metadata => {
                if metadata.is_none() {
                    fs::DirBuilder::new()
                        .mode(0o700)
                        .create(path)
                        .map_err(failed)?;
                }
                rustix::fs::chown(
                    path,
                    Some(rustix::fs::Uid::from_raw(account.uid)),
                    Some(rustix::fs::Gid::from_raw(account.gid)),
                )
                .map_err(failed)?;
                fs::set_permissions(path, fs::Permissions::from_mode(0o700)).map_err(failed)?;
                changed = true;
            }
        }
    }
    for (path, content) in [
        (&models, contents.as_slice()),
        (&token, input.token.as_bytes()),
    ] {
        let same = existing(path, account, false)?.is_some_and(|meta| {
            meta.mode() & 0o7777 == 0o600
                && meta.gid() == account.gid
                && meta.len() == content.len() as u64
        });
        if same && fs::read(path).map_err(failed)? == content {
            continue;
        }
        files.publish(path, content, 0o600, account.uid, account.gid, false)?;
        changed = true;
    }
    Ok(Output {
        changed,
        binary: account.home.join(super::pi_artifact::BINARY),
        token_file: token,
    })
}

pub struct Configure;
impl Command for Configure {
    const NAME: &'static str = "pi.configure-runtime";
    const SUMMARY: &'static str =
        "Publish the managed account's private Pi provider settings and token.";
    const MUTATES: bool = true;
    const RESOURCES: &'static [&'static str] = &["pi-runtime"];
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Owner)
    }
    fn target(input: &Input) -> Option<String> {
        Some(format!("pi-runtime-{}", input.user))
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        validate(&input)?;
        configure(&Files::system(), &Account::named(&input.user)?, &input)
    }
}

#[cfg(test)]
mod tests;
