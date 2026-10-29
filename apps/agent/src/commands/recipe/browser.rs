//! Root-owned Chrome and its single exact-executable AppArmor namespace exception.
use super::{
    artifacts, run,
    types::{Input, Tool},
    Failure, Files, Host, Invocation, WorkDir,
};
use std::{
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
};

pub const NAME: &str = "chrome-headless-shell";
const BASE: &str = "/opt/orbit/chrome-headless-shell";
const EXECUTABLE: &str = "chrome-headless-shell-linux64/chrome-headless-shell";
const POLICY: &str = "/etc/apparmor.d/orbit-chrome-headless-shell";
const PARSER: &str = "/usr/sbin/apparmor_parser";

fn bad(reason: impl ToString) -> Failure {
    Failure::new("browser", reason)
}
pub fn valid(pin: &Tool, architecture: &str) -> bool {
    let Some(artifact) = pin.artifacts.get("amd64") else {
        return false;
    };
    architecture == "amd64" && pin.source == super::types::Source::Artifact
        && regex::Regex::new(r"\A[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\z").unwrap().is_match(&pin.version)
        && pin.artifacts.len() == 1 && artifact.version.is_none()
        && artifact.sha256.as_deref().is_some_and(|s| super::types::valid_digest(s, 64))
        && artifact.url.as_deref() == Some(&format!("https://storage.googleapis.com/chrome-for-testing-public/{}/linux64/chrome-headless-shell-linux64.zip", pin.version))
}
fn directory(files: &Files, pin: &Tool) -> PathBuf {
    files.path(&format!("{BASE}/{}", pin.version))
}
fn policy(version: &str) -> String {
    format!("# Managed by Orbit machine recipe.\nabi <abi/5.0>,\ninclude <tunables/global>\nprofile orbit-chrome-headless-shell {BASE}/{version}/{EXECUTABLE} flags=(unconfined) {{\n  userns,\n  @{{exec_path}} mr,\n}}\n")
}
/// Check every ancestor without following links, including /opt and /etc on a real Node.
fn trusted(files: &Files, path: &Path) -> Result<(), Failure> {
    for part in path.ancestors().take_while(|p| p.starts_with(&files.root)) {
        match fs::symlink_metadata(part) {
            Ok(m) if m.is_symlink() || m.uid() != files.uid || m.mode() & 0o022 != 0 => {
                return Err(bad(format!(
                    "Browser path is not root-owned and immutable: {}",
                    part.display()
                )))
            }
            Ok(_) => {}
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
            Err(e) => return Err(Failure::io("browser", part, e)),
        }
    }
    Ok(())
}
fn tree(files: &Files, path: &Path) -> Result<(), Failure> {
    trusted(files, path)?;
    let m = fs::symlink_metadata(path).map_err(bad)?;
    if m.is_dir() {
        for child in fs::read_dir(path).map_err(bad)? {
            tree(files, &child.map_err(bad)?.path())?;
        }
    } else if !m.is_file() {
        return Err(bad(
            "Browser resources must be regular files or directories",
        ));
    }
    Ok(())
}
fn hash(path: &Path) -> Result<String, Failure> {
    crate::command::run::file_sha256(path).map_err(bad)
}
fn receipt(files: &Files, pin: &Tool) -> Result<String, Failure> {
    Ok(format!(
        "{}\n{}\n",
        pin.artifacts["amd64"]
            .sha256
            .as_deref()
            .ok_or_else(|| bad("Missing browser digest"))?,
        hash(&directory(files, pin).join(EXECUTABLE))?
    ))
}
fn runtime(files: &Files, pin: &Tool) -> Result<bool, Failure> {
    let root = directory(files, pin);
    trusted(files, &root)?;
    if !root.exists() {
        return Ok(false);
    }
    tree(files, &root)?;
    let binary = root.join(EXECUTABLE);
    if !fs::metadata(&binary).is_ok_and(|m| m.is_file() && m.mode() & 0o111 != 0)
        || fs::read_to_string(root.join(".orbit-recipe"))
            .ok()
            .as_deref()
            != Some(&receipt(files, pin)?)
    {
        return Err(bad(
            "Existing Chrome runtime differs from its verified recipe receipt",
        ));
    }
    Ok(true)
}
fn previous_policy(files: &Files) -> Result<Option<String>, Failure> {
    let path = files.path(POLICY);
    trusted(files, &path)?;
    let previous = match fs::read_to_string(&path) {
        Ok(s) => s,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(None),
        Err(e) => return Err(Failure::io("browser", &path, e)),
    };
    let pattern =
        regex::Regex::new(r"/opt/orbit/chrome-headless-shell/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/")
            .unwrap();
    if !pattern
        .captures(&previous)
        .is_some_and(|m| previous == policy(&m[1]))
    {
        return Err(bad("Refusing to replace an unrelated AppArmor policy"));
    }
    Ok(Some(previous))
}
pub fn installed(files: &Files, pin: &Tool) -> Result<Option<String>, Failure> {
    if !valid(pin, "amd64") {
        return Err(bad("Invalid browser pin"));
    }
    if !runtime(files, pin)? {
        return Ok(None);
    }
    Ok(
        (previous_policy(files)?.as_deref() == Some(&policy(&pin.version)))
            .then(|| pin.version.clone()),
    )
}
fn activate(host: &mut impl Host, files: &Files, pin: &Tool) -> Result<(), Failure> {
    let previous = previous_policy(files)?;
    let text = policy(&pin.version);
    let parent = files.path("/etc/apparmor.d");
    let work = WorkDir::within(&parent)?;
    let candidate = work.0.join("policy");
    files.publish_root(&candidate, text.as_bytes())?;
    let parse = |host: &mut _, flag: &str, path: &Path| {
        run(
            host,
            &Invocation::new(&[PARSER, flag, "--skip-cache", &path.display().to_string()]),
            true,
        )
    };
    parse(host, "--skip-kernel-load", &candidate)?;
    let path = files.path(POLICY);
    files.publish_root(&path, text.as_bytes())?;
    if let Err(error) = parse(host, "--replace", &path) {
        match previous {
            Some(old) => {
                files.publish_root(&path, old.as_bytes())?;
                parse(host, "--replace", &path)?;
            }
            None => {
                // Remove only our candidate profile; do not restart or reload AppArmor globally.
                let _ = parse(host, "--remove", &path);
                fs::remove_file(&path).map_err(bad)?;
            }
        }
        return Err(error.at("browser-policy"));
    }
    Ok(())
}
pub fn install(
    host: &mut impl Host,
    files: &Files,
    input: &Input,
    archive: Option<&Path>,
) -> Result<(), Failure> {
    let Some(pin) = input.tools.get(NAME) else {
        return Ok(());
    };
    if !valid(pin, input.architecture.name()) {
        return Err(bad("Unsupported Chrome version, artifact or architecture"));
    }
    previous_policy(files)?;
    if !runtime(files, pin)? {
        let archive = archive.ok_or_else(|| bad("Missing verified Chrome archive"))?;
        artifacts::verify(archive, &pin.artifacts["amd64"])?;
        for path in ["/opt", "/opt/orbit", BASE] {
            let path = files.path(path);
            trusted(files, &path)?;
            files.directory(&path, files.uid, files.gid)?;
        }
        let work = WorkDir::within(&files.path(BASE))?;
        let unpacked = work.0.join("runtime");
        artifacts::extract(archive, &unpacked)?;
        artifacts::own_tree(&unpacked, files.uid, files.gid)?;
        tree(files, &unpacked)?;
        let binary = unpacked.join(EXECUTABLE);
        if !fs::metadata(&binary).is_ok_and(|m| m.is_file() && m.mode() & 0o111 != 0) {
            return Err(bad("Chrome archive lacks its executable"));
        }
        let content = format!(
            "{}\n{}\n",
            pin.artifacts["amd64"].sha256.as_ref().unwrap(),
            hash(&binary)?
        );
        files.publish_root(&unpacked.join(".orbit-recipe"), content.as_bytes())?;
        fs::rename(&unpacked, directory(files, pin)).map_err(bad)?;
    }
    runtime(files, pin)?;
    activate(host, files, pin)
}
pub fn converge(host: &mut impl Host, files: &Files, input: &Input) -> Result<(), Failure> {
    let Some(pin) = input.tools.get(NAME) else {
        return Ok(());
    };
    if !valid(pin, input.architecture.name()) {
        return Err(bad("Invalid browser pin"));
    }
    let work = WorkDir::new()?;
    let archive = work.0.join("chrome.zip");
    if !runtime(files, pin)? {
        artifacts::download(host, NAME, pin, input, &archive)?;
    }
    install(host, files, input, Some(&archive))
}

#[cfg(test)]
mod tests;
