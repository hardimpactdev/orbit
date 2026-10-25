//! Observe the existing Mac identity without changing the account or network.
use crate::{
    command::{Command, Context, Platform, Privilege},
    command_errors,
};
use serde::{Deserialize, Serialize};
use std::{net::Ipv4Addr, time::Duration};

command_errors! {
    pub enum Error {
        Platform {} => "nodes.macos_platform_mismatch", "The enrolled host does not run macOS.";
        Architecture {} => "nodes.macos_architecture_unavailable", "The machine architecture could not be observed.";
        Address {} => "nodes.macos_wireguard_required", "The enrolled WireGuard address is not present on the host.";
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    address: Ipv4Addr,
}

#[derive(Debug, Serialize, PartialEq)]
pub struct Output {
    architecture: String,
}

fn inspect(
    platform: std::io::Result<(String, String)>,
    addresses: impl FnOnce() -> std::io::Result<Vec<Ipv4Addr>>,
    address: Ipv4Addr,
) -> Result<Output, Error> {
    let (platform, architecture) = platform.map_err(|_| Error::Architecture {})?;
    if platform != "darwin" {
        return Err(Error::Platform {});
    }
    if architecture.is_empty()
        || architecture.len() > 64
        || !architecture
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"_.-".contains(&b))
    {
        return Err(Error::Architecture {});
    }
    if !addresses()
        .map_err(|_| Error::Address {})?
        .contains(&address)
    {
        return Err(Error::Address {});
    }
    Ok(Output { architecture })
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "nodes.inspect-macos-enrollment";
    const SUMMARY: &'static str =
        "Verify the existing Mac architecture and enrolled WireGuard address.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = Input;
    type Output = Output;
    type Error = Error;

    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Macos).then_some(Privilege::Account)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        inspect(
            super::node_identity::platform(),
            super::node_identity::addresses,
            input.address,
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    fn identity(platform: &str, architecture: &str) -> std::io::Result<(String, String)> {
        Ok((platform.into(), architecture.into()))
    }
    #[test]
    fn preserves_native_architecture_on_repeated_enrollment() {
        for architecture in ["arm64", "x86_64"] {
            for _ in 0..2 {
                assert_eq!(
                    inspect(
                        identity("darwin", architecture),
                        || Ok(vec![Ipv4Addr::LOCALHOST]),
                        Ipv4Addr::LOCALHOST
                    ),
                    Ok(Output {
                        architecture: architecture.into()
                    })
                );
            }
        }
    }
    #[test]
    fn refuses_the_wrong_platform_before_reading_interfaces() {
        assert_eq!(
            inspect(
                identity("linux", "aarch64"),
                || panic!("must not read interfaces"),
                Ipv4Addr::LOCALHOST
            ),
            Err(Error::Platform {})
        );
        assert_eq!(Inspect::privilege(Platform::Linux), None);
    }
    #[test]
    fn refuses_missing_or_malformed_identity_before_reading_interfaces() {
        for architecture in ["", "arm64\nother", "bad value", &"x".repeat(65)] {
            assert_eq!(
                inspect(
                    identity("darwin", architecture),
                    || panic!("must not read interfaces"),
                    Ipv4Addr::LOCALHOST
                ),
                Err(Error::Architecture {})
            );
        }
        assert_eq!(
            inspect(
                Err(std::io::Error::other("uname")),
                || panic!("must not read interfaces"),
                Ipv4Addr::LOCALHOST
            ),
            Err(Error::Architecture {})
        );
    }
    #[test]
    fn refuses_absent_or_unreadable_addresses() {
        for addresses in [
            Ok(vec![Ipv4Addr::new(10, 44, 0, 2)]),
            Err(std::io::Error::other("interfaces")),
        ] {
            assert_eq!(
                inspect(
                    identity("darwin", "arm64"),
                    || addresses,
                    Ipv4Addr::LOCALHOST
                ),
                Err(Error::Address {})
            );
        }
    }
    #[test]
    fn rejects_non_address_and_unknown_input_fields() {
        for input in [
            r#"{"address":"127.0.0.1; id"}"#,
            r#"{"address":"127.0.0.1","script":"id"}"#,
        ] {
            assert!(serde_json::from_str::<Input>(input).is_err());
        }
    }
    #[test]
    fn native_observation_reads_the_kernel_and_loopback_address() {
        assert!(!super::super::node_identity::platform()
            .unwrap()
            .1
            .is_empty());
        assert!(super::super::node_identity::addresses()
            .unwrap()
            .contains(&Ipv4Addr::LOCALHOST));
    }
}
