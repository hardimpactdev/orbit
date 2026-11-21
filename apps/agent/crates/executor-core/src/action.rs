//! The allowlist: every action the executor knows, its family, and the families a Node enables.

use std::collections::BTreeSet;
use std::fmt;
use std::str::FromStr;

use serde::{Deserialize, Serialize};

/// Every action the executor can run. Nothing else is accepted.
///
/// Slice 1 of ADR 0266 has one no-op action, `proof.noop`, that proves the order path end to end.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(try_from = "String", into = "String")]
#[expect(
    clippy::exhaustive_enums,
    reason = "the binary's dispatcher must fail to build when an action is added"
)]
pub enum Action {
    /// Does nothing and reports `done`. Its target is the Node itself.
    ProofNoop,
    /// Hibernate the entire Instance container.
    Sleep,
    /// Restore and resume a sleeping Instance container.
    Wake,
    /// Drop a stopped or replaced Instance's prepared state.
    Discard,
    /// Converge one ufw rule to its desired state (ADR 0271). Its target is the Node itself, so the Node's firewall
    /// changes run one at a time.
    UfwEnsureRule,
    /// Make a Route leaf's key once and answer a certificate request for it (ADR 0271). Its target is the Node.
    TlsEnsureCsr,
    /// Place one Node's part of a Route: access, environment, PHP-FPM pools, certificates and the Caddyfile (ADR 0271).
    /// Its target is the Node, so the Node's Route publications run one at a time.
    RoutePublishSite,
    /// Place a VPN Node's private DNS records and catalog, then restart dnsmasq and the listener (ADR 0271). Its target
    /// is the Node, so the Node's DNS publications run one at a time.
    DnsPublishRecords,
    /// Make an Instance container from its default's seed: its ACL, the seed copy and the T3 proxy (ADR 0271). Its target
    /// is the Node, because the container does not exist yet.
    InstanceClone,
    /// Place a stopped copy's files before its first start (ADR 0271). Its target is the copy.
    InstancePrepare,
    /// Start a prepared copy and give it its names (ADR 0271). Its target is the copy.
    InstanceStart,
    /// Fetch an Instance's branch into the Node's mirror of its repository (ADR 0271). Its target is the Node.
    InstanceFetchBranch,
    /// Check the copy's workspace out on its branch and merge its `.env` (ADR 0271). Its target is the copy.
    InstanceCheckout,
    /// Start the copy's T3, issue its two sessions sealed to the Gateway, and add its workspace as a project (ADR 0271).
    /// Its target is the copy.
    InstanceT3,
    /// Write a nested-fleet copy's Club key into its `/run` and tell the nested Gateway's serve (ADR 0271). Its target is
    /// the Node's part for that copy, so it runs beside checkout and T3.
    InstanceClubKey,
    /// Copy a nested-fleet copy's own inner volume from its default's volume seed (ADR 0271). Its target is the Node.
    InstanceCopyVolume,
    /// Stop a copy whose create failed, and keep it (ADR 0271). Its target is the copy.
    InstanceStop,
    /// Make the one spare of a default's inner volume from its current seed, beside a create (ADR 0271). Its target is the
    /// Node's part for that default.
    InstanceSpareVolume,
    /// Wait until a nested-fleet copy's inner fleet runs and is ready, beside its checkout (ADR 0271). Its target is the
    /// Node's part for that copy.
    InstanceFleetReady,
    /// Give a reserved copy its final name and branch, and restart its T3 for its label (docs/reference/conn.md#reserve-a-clone).
    /// Its target is the copy.
    InstanceNames,
}

/// How the executor proves that an action's target exists on this Node.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[non_exhaustive]
pub enum TargetKind {
    /// The target is this Node: its id must equal the Node's id.
    Node,
    /// An Instance in the local Incus.
    Instance,
}

impl Action {
    /// Every action this executor knows, for the list a Node reports to the Gateway (ADR 0271).
    pub const ALL: [Self; 20] = [
        Self::ProofNoop,
        Self::Sleep,
        Self::Wake,
        Self::Discard,
        Self::UfwEnsureRule,
        Self::TlsEnsureCsr,
        Self::RoutePublishSite,
        Self::DnsPublishRecords,
        Self::InstanceClone,
        Self::InstancePrepare,
        Self::InstanceStart,
        Self::InstanceFetchBranch,
        Self::InstanceCheckout,
        Self::InstanceT3,
        Self::InstanceClubKey,
        Self::InstanceCopyVolume,
        Self::InstanceStop,
        Self::InstanceSpareVolume,
        Self::InstanceFleetReady,
        Self::InstanceNames,
    ];

    /// The family whose crate holds this action, and which a role enables.
    pub fn family(self) -> Family {
        match self {
            Self::ProofNoop => Family::Proof,
            Self::Sleep | Self::Wake | Self::Discard => Family::Power,
            Self::UfwEnsureRule => Family::Firewall,
            Self::TlsEnsureCsr | Self::RoutePublishSite => Family::Route,
            Self::DnsPublishRecords => Family::Dns,
            Self::InstanceClone
            | Self::InstancePrepare
            | Self::InstanceStart
            | Self::InstanceFetchBranch
            | Self::InstanceCheckout
            | Self::InstanceT3
            | Self::InstanceClubKey
            | Self::InstanceCopyVolume
            | Self::InstanceStop
            | Self::InstanceSpareVolume
            | Self::InstanceFleetReady
            | Self::InstanceNames => {
                Family::Instance
            }
        }
    }

    /// What the action's target names.
    pub fn target_kind(self) -> TargetKind {
        match self {
            Self::ProofNoop
            | Self::UfwEnsureRule
            | Self::TlsEnsureCsr
            | Self::RoutePublishSite
            | Self::DnsPublishRecords
            | Self::InstanceClone
            | Self::InstanceFetchBranch
            | Self::InstanceClubKey
            | Self::InstanceCopyVolume
            | Self::InstanceSpareVolume
            | Self::InstanceFleetReady => TargetKind::Node,
            Self::Sleep
            | Self::Wake
            | Self::Discard
            | Self::InstancePrepare
            | Self::InstanceStart
            | Self::InstanceCheckout
            | Self::InstanceT3
            | Self::InstanceStop
            | Self::InstanceNames => {
                TargetKind::Instance
            }
        }
    }

    /// The action's name on the wire.
    pub fn name(self) -> &'static str {
        match self {
            Self::ProofNoop => "proof.noop",
            Self::Sleep => "sleep",
            Self::Wake => "wake",
            Self::Discard => "discard",
            Self::UfwEnsureRule => "ufw.ensure_rule",
            Self::TlsEnsureCsr => "tls.ensure_csr",
            Self::RoutePublishSite => "route.publish_site",
            Self::DnsPublishRecords => "dns.publish_records",
            Self::InstanceClone => "instance.clone",
            Self::InstancePrepare => "instance.prepare",
            Self::InstanceStart => "instance.start",
            Self::InstanceFetchBranch => "instance.fetch_branch",
            Self::InstanceCheckout => "instance.checkout",
            Self::InstanceT3 => "instance.t3",
            Self::InstanceClubKey => "instance.club_key",
            Self::InstanceCopyVolume => "instance.copy_volume",
            Self::InstanceStop => "instance.stop",
            Self::InstanceSpareVolume => "instance.spare_volume",
            Self::InstanceFleetReady => "instance.fleet_ready",
            Self::InstanceNames => "instance.names",
        }
    }
}

/// The action name is not on the allowlist.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("unknown action")]
pub struct UnknownActionError;

impl FromStr for Action {
    type Err = UnknownActionError;

    fn from_str(raw: &str) -> Result<Self, Self::Err> {
        match raw {
            "proof.noop" => Ok(Self::ProofNoop),
            "sleep" => Ok(Self::Sleep),
            "wake" => Ok(Self::Wake),
            "discard" => Ok(Self::Discard),
            "ufw.ensure_rule" => Ok(Self::UfwEnsureRule),
            "tls.ensure_csr" => Ok(Self::TlsEnsureCsr),
            "route.publish_site" => Ok(Self::RoutePublishSite),
            "dns.publish_records" => Ok(Self::DnsPublishRecords),
            "instance.clone" => Ok(Self::InstanceClone),
            "instance.prepare" => Ok(Self::InstancePrepare),
            "instance.start" => Ok(Self::InstanceStart),
            "instance.fetch_branch" => Ok(Self::InstanceFetchBranch),
            "instance.checkout" => Ok(Self::InstanceCheckout),
            "instance.t3" => Ok(Self::InstanceT3),
            "instance.club_key" => Ok(Self::InstanceClubKey),
            "instance.copy_volume" => Ok(Self::InstanceCopyVolume),
            "instance.stop" => Ok(Self::InstanceStop),
            "instance.spare_volume" => Ok(Self::InstanceSpareVolume),
            "instance.fleet_ready" => Ok(Self::InstanceFleetReady),
            "instance.names" => Ok(Self::InstanceNames),
            _ => Err(UnknownActionError),
        }
    }
}

impl TryFrom<String> for Action {
    type Error = UnknownActionError;

    fn try_from(raw: String) -> Result<Self, Self::Error> {
        raw.parse()
    }
}

impl From<Action> for String {
    fn from(action: Action) -> Self {
        action.name().to_owned()
    }
}

impl fmt::Display for Action {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.name())
    }
}

/// A group of actions that one crate holds and that a role enables as a whole (ADR 0267 P10).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
#[non_exhaustive]
pub enum Family {
    /// The no-op proof action.
    Proof,
    /// Whole-container sleep, wake and discard.
    Power,
    /// Desired-state firewall rules (ADR 0271).
    Firewall,
    /// Route certificates and site publication (ADR 0271).
    Route,
    /// A VPN Node's private DNS records (ADR 0271).
    Dns,
    /// Instance containers made from a seed (ADR 0271).
    Instance,
}

/// The family name is unknown to this executor.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("unknown action family on line {line}")]
pub struct UnknownFamilyError {
    /// The 1-based line of the enabled file.
    pub line: usize,
}

impl Family {
    fn parse(raw: &str) -> Option<Self> {
        match raw {
            "proof" => Some(Self::Proof),
            "power" => Some(Self::Power),
            "firewall" => Some(Self::Firewall),
            "route" => Some(Self::Route),
            "dns" => Some(Self::Dns),
            "instance" => Some(Self::Instance),
            _ => None,
        }
    }
}

/// The families this Node's roles enable, read from `/etc/orbit/executor/enabled`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Enabled(BTreeSet<Family>);

impl Enabled {
    /// Parses the enabled file: one family per line. Blank lines and `#` comments are skipped.
    ///
    /// # Errors
    ///
    /// Returns [`UnknownFamilyError`] for a line that names no known family, so a role that enables a family
    /// this binary lacks fails the converge instead of being silently narrowed.
    pub fn parse(text: &str) -> Result<Self, UnknownFamilyError> {
        let mut families = BTreeSet::new();
        for (index, line) in text.lines().enumerate() {
            let line = line.trim();
            if line.is_empty() || line.starts_with('#') {
                continue;
            }
            let family = Family::parse(line).ok_or(UnknownFamilyError { line: index + 1 })?;
            families.insert(family);
        }
        Ok(Self(families))
    }

    /// Whether this Node enables the action's family.
    pub fn allows(&self, action: Action) -> bool {
        self.0.contains(&action.family())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_allowlisted_names_parse() {
        assert_eq!("proof.noop".parse(), Ok(Action::ProofNoop));
        assert_eq!("sleep".parse::<Action>(), Ok(Action::Sleep));
        assert_eq!("wake".parse::<Action>(), Ok(Action::Wake));
        assert_eq!(Action::Wake.family(), Family::Power);
        assert_eq!("dns.publish_records".parse(), Ok(Action::DnsPublishRecords));
        assert_eq!(Action::DnsPublishRecords.family(), Family::Dns);
        assert!(Enabled::parse("dns\n").expect("valid file").allows(Action::DnsPublishRecords));
        assert_eq!("instance.clone".parse(), Ok(Action::InstanceClone));
        assert!(Enabled::parse("instance\n").expect("valid file").allows(Action::InstanceClone));
        assert_eq!("PROOF.NOOP".parse::<Action>(), Err(UnknownActionError));
        // The proof's `unknown_action` order (tools/swamp-proof-guest/executor-order.php) relies on this name staying unknown.
        assert_eq!("proof.unknown".parse::<Action>(), Err(UnknownActionError));
    }

    #[test]
    fn enabled_file_enables_listed_families_only() {
        let enabled = Enabled::parse("# app-dev\n\nproof\n").expect("valid file");
        assert!(enabled.allows(Action::ProofNoop));
        assert!(!Enabled::default().allows(Action::ProofNoop));
    }

    #[test]
    fn enabled_file_refuses_an_unknown_family() {
        assert_eq!(
            Enabled::parse("proof\nunknown\n"),
            Err(UnknownFamilyError { line: 2 })
        );
    }
}
