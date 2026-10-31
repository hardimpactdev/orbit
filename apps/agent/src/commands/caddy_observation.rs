//! Read-only Caddy observations. Swamp owns configuration and service changes.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{
    ffi::OsStr,
    fs,
    io::{self, Read},
    net::Ipv4Addr,
    path::Path,
    process::{Command as Process, Stdio},
    time::Duration,
};
const LIMIT: u64 = 4_194_304;
command_errors! {
    pub enum PublicationError {
        Failed { stage: String, message: String } => "caddy.publication_failed", "The Caddy publication failed.";
    }
}
fn failed(stage: &str, message: impl ToString) -> PublicationError {
    PublicationError::Failed {
        stage: stage.into(),
        message: message.to_string().chars().take(2000).collect(),
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct AddressesInput {
    listen_addresses: Vec<Ipv4Addr>,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Checked {}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReadInput {}
#[derive(schemars::JsonSchema, Debug, Serialize, PartialEq)]
pub struct Live {
    content: String,
}
fn output(program: &str, args: &[&OsStr]) -> Option<String> {
    let result = Process::new(program)
        .args(args)
        .stdin(Stdio::null())
        .output()
        .ok()?;
    result
        .status
        .success()
        .then(|| String::from_utf8_lossy(&result.stdout).into_owned())
}
fn addresses() -> Result<Vec<Ipv4Addr>, String> {
    let text = output(
        "/usr/sbin/ip",
        &[
            "-o".as_ref(),
            "-4".as_ref(),
            "addr".as_ref(),
            "show".as_ref(),
        ],
    )
    .ok_or("Node addresses could not be read.")?;
    Ok(text
        .lines()
        .filter_map(|line| {
            line.split_whitespace()
                .nth(3)?
                .split('/')
                .next()?
                .parse()
                .ok()
        })
        .collect())
}
fn check_addresses(
    addresses: &[Ipv4Addr],
    read: impl FnOnce() -> Result<Vec<Ipv4Addr>, String>,
) -> Result<(), PublicationError> {
    if addresses.is_empty() {
        return Ok(());
    }
    let present = read().map_err(|message| failed("addresses", message))?;
    for address in addresses {
        if !present.contains(address) {
            return Err(failed("addresses", format!("The build binds {address}, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then build again.")));
        }
    }
    Ok(())
}
fn read_live(path: &Path) -> Result<Live, PublicationError> {
    let file = match fs::File::open(path) {
        Ok(file) => file,
        Err(error) if error.kind() == io::ErrorKind::NotFound => {
            return Ok(Live {
                content: String::new(),
            })
        }
        Err(error) => return Err(failed("read-live", error)),
    };
    let mut content = String::new();
    file.take(LIMIT + 1)
        .read_to_string(&mut content)
        .map_err(|error| failed("read-live", error))?;
    if content.len() as u64 > LIMIT {
        return Err(failed(
            "read-live",
            "The live Caddyfile exceeds the read limit.",
        ));
    }
    Ok(Live { content })
}
pub struct CheckAddresses;
impl Command for CheckAddresses {
    const NAME: &'static str = "caddy.check-addresses";
    const SUMMARY: &'static str = "Check that every Caddy listen address exists on the Node.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = AddressesInput;
    type Output = Checked;
    type Error = PublicationError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        check_addresses(&input.listen_addresses, addresses)?;
        Ok(Checked {})
    }
}
pub struct ReadLive;
impl Command for ReadLive {
    const NAME: &'static str = "caddy.read-live";
    const SUMMARY: &'static str = "Read the live Caddyfile without expanding imports.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(60);
    type Input = ReadInput;
    type Output = Live;
    type Error = PublicationError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(_: Self::Input, _: &Context) -> Result<Self::Output, Self::Error> {
        read_live(Path::new("/etc/caddy/Caddyfile"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn address_observation_refuses_missing_bindings_without_mutation() {
        let address = "10.44.0.9".parse().unwrap();
        assert!(check_addresses(&[], || panic!("Empty bindings need no lookup")).is_ok());
        assert!(check_addresses(&[address], || Ok(vec![address])).is_ok());
        assert!(
            matches!(check_addresses(&[address], || Ok(vec![])), Err(PublicationError::Failed {stage,..}) if stage == "addresses")
        );
        assert!(check_addresses(&[address], || Err("unavailable".into())).is_err());
    }
    #[test]
    fn read_live_retains_imports_and_refuses_oversized_or_non_file_content() {
        let root = std::env::temp_dir().join(format!(
            "orbit-caddy-observation-{:016x}",
            rand::random::<u64>()
        ));
        fs::create_dir(&root).unwrap();
        let live = root.join("Caddyfile");
        let target = root.join("target");
        fs::write(&target, "import other/*\n").unwrap();
        std::os::unix::fs::symlink(&target, &live).unwrap();
        assert_eq!(read_live(&live).unwrap().content, "import other/*\n");
        fs::remove_file(&live).unwrap();
        assert_eq!(read_live(&live).unwrap().content, "");
        fs::write(&live, "x".repeat(LIMIT as usize + 1)).unwrap();
        assert!(read_live(&live).is_err());
        fs::remove_file(&live).unwrap();
        fs::create_dir(&live).unwrap();
        assert!(read_live(&live).is_err());
        fs::remove_dir_all(root).unwrap();
    }
}
