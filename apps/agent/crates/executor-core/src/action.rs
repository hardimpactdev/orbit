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
    /// SPIKE primitive: read `ufw status numbered`.
    UfwRead,
    /// SPIKE primitive: add, insert or delete one typed ufw rule, then read `ufw status numbered`.
    UfwEnsure,
    /// SPIKE desired-state primitive: converge one rule by Orbit's order and answer in one line.
    UfwEnsureRule,
    /// SPIKE: a long action that only waits `{seconds}`, standing in for a clone or a playbook.
    SpikeWait,
    /// SPIKE generic primitive `tls.csr`.
    TlsCsr,
    /// SPIKE generic primitive `file.put`.
    FilePut,
    /// SPIKE generic primitive `file.link`.
    FileLink,
    /// SPIKE generic primitive `file.install`.
    FileInstall,
    /// SPIKE generic primitive `ca.trust`.
    CaTrust,
    /// SPIKE generic primitive `caddy.config`.
    CaddyConfig,
    /// SPIKE generic primitive `systemd.restart`.
    SystemdRestart,
    IncusAclEnsure,
    IncusCopy,
    IncusDeviceEnsure,
    IncusFilePut,
    IncusFileLink,
    IncusRootfsUnshift,
    IncusStart,
    UfwEnsureRoute,
    HttpWait,
    ContainerGitCheckout,
    DotenvMerge,
    ContainerGitConfig,
    ContainerJsonMerge,
    ContainerDir,
    ContainerDirSync,
    ContainerUnit,
    T3SessionIssue,
    T3ProjectAdd,
    FileRemoveTree,
}

/// How the executor proves that an action's target exists on this Node.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[non_exhaustive]
pub enum TargetKind {
    /// The target is this Node: its id must equal the Node's id.
    Node,
    /// An Instance in the local Incus.
    Instance,
    /// SPIKE: a Gateway-chosen step key; it only scopes idempotency, so any valid id passes.
    Step,
}

impl Action {
    /// The family whose crate holds this action, and which a role enables.
    pub fn family(self) -> Family {
        match self {
            Self::ProofNoop | Self::SpikeWait => Family::Proof,
            Self::Sleep | Self::Wake | Self::Discard => Family::Power,
            Self::UfwRead | Self::UfwEnsure | Self::UfwEnsureRule => Family::Ufw,
            Self::TlsCsr | Self::FilePut | Self::FileLink | Self::FileInstall | Self::CaTrust | Self::CaddyConfig | Self::SystemdRestart
            | Self::IncusAclEnsure | Self::IncusCopy | Self::IncusDeviceEnsure | Self::IncusFilePut | Self::IncusFileLink | Self::IncusRootfsUnshift | Self::IncusStart | Self::UfwEnsureRoute | Self::HttpWait
            | Self::ContainerGitCheckout | Self::DotenvMerge | Self::ContainerGitConfig | Self::ContainerJsonMerge | Self::ContainerDir | Self::ContainerDirSync | Self::ContainerUnit | Self::T3SessionIssue | Self::T3ProjectAdd | Self::FileRemoveTree => Family::Node,
        }
    }

    /// What the action's target names.
    pub fn target_kind(self) -> TargetKind {
        match self {
            Self::ProofNoop => TargetKind::Node,
            Self::Sleep | Self::Wake | Self::Discard => TargetKind::Instance,
            Self::UfwRead | Self::UfwEnsure | Self::UfwEnsureRule | Self::SpikeWait => TargetKind::Step,
            Self::TlsCsr | Self::FilePut | Self::FileLink | Self::FileInstall | Self::CaTrust | Self::CaddyConfig | Self::SystemdRestart
            | Self::IncusAclEnsure | Self::IncusCopy | Self::IncusDeviceEnsure | Self::IncusFilePut | Self::IncusFileLink | Self::IncusRootfsUnshift | Self::IncusStart | Self::UfwEnsureRoute | Self::HttpWait
            | Self::ContainerGitCheckout | Self::DotenvMerge | Self::ContainerGitConfig | Self::ContainerJsonMerge | Self::ContainerDir | Self::ContainerDirSync | Self::ContainerUnit | Self::T3SessionIssue | Self::T3ProjectAdd | Self::FileRemoveTree => TargetKind::Step,
        }
    }

    /// The action's name on the wire.
    pub fn name(self) -> &'static str {
        match self {
            Self::ProofNoop => "proof.noop",
            Self::Sleep => "sleep",
            Self::Wake => "wake",
            Self::Discard => "discard",
            Self::UfwRead => "ufw.read",
            Self::UfwEnsure => "ufw.ensure",
            Self::UfwEnsureRule => "ufw.ensure_rule",
            Self::SpikeWait => "spike.wait",
            Self::TlsCsr => "tls.csr",
            Self::FilePut => "file.put",
            Self::FileLink => "file.link",
            Self::FileInstall => "file.install",
            Self::CaTrust => "ca.trust",
            Self::CaddyConfig => "caddy.config",
            Self::SystemdRestart => "systemd.restart",
            Self::IncusAclEnsure => "incus.acl_ensure",
            Self::IncusCopy => "incus.copy",
            Self::IncusDeviceEnsure => "incus.device_ensure",
            Self::IncusFilePut => "incus.file_put",
            Self::IncusFileLink => "incus.file_link",
            Self::IncusRootfsUnshift => "incus.rootfs_unshift",
            Self::IncusStart => "incus.start",
            Self::UfwEnsureRoute => "ufw.ensure_route",
            Self::HttpWait => "http.wait",
            Self::ContainerGitCheckout => "container.git_checkout",
            Self::DotenvMerge => "dotenv.merge",
            Self::ContainerGitConfig => "container.git_config",
            Self::ContainerJsonMerge => "container.json_merge",
            Self::ContainerDir => "container.dir",
            Self::ContainerDirSync => "container.dir_sync",
            Self::ContainerUnit => "container.unit",
            Self::T3SessionIssue => "t3.session_issue",
            Self::T3ProjectAdd => "t3.project_add",
            Self::FileRemoveTree => "file.remove_tree",
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
            "ufw.read" => Ok(Self::UfwRead),
            "ufw.ensure" => Ok(Self::UfwEnsure),
            "ufw.ensure_rule" => Ok(Self::UfwEnsureRule),
            "spike.wait" => Ok(Self::SpikeWait),
            "tls.csr" => Ok(Self::TlsCsr),
            "file.put" => Ok(Self::FilePut),
            "file.link" => Ok(Self::FileLink),
            "file.install" => Ok(Self::FileInstall),
            "ca.trust" => Ok(Self::CaTrust),
            "caddy.config" => Ok(Self::CaddyConfig),
            "systemd.restart" => Ok(Self::SystemdRestart),
            "incus.acl_ensure" => Ok(Self::IncusAclEnsure),
            "incus.copy" => Ok(Self::IncusCopy),
            "incus.device_ensure" => Ok(Self::IncusDeviceEnsure),
            "incus.file_put" => Ok(Self::IncusFilePut),
            "incus.file_link" => Ok(Self::IncusFileLink),
            "incus.rootfs_unshift" => Ok(Self::IncusRootfsUnshift),
            "incus.start" => Ok(Self::IncusStart),
            "ufw.ensure_route" => Ok(Self::UfwEnsureRoute),
            "http.wait" => Ok(Self::HttpWait),
            "container.git_checkout" => Ok(Self::ContainerGitCheckout),
            "dotenv.merge" => Ok(Self::DotenvMerge),
            "container.git_config" => Ok(Self::ContainerGitConfig),
            "container.json_merge" => Ok(Self::ContainerJsonMerge),
            "container.dir" => Ok(Self::ContainerDir),
            "container.dir_sync" => Ok(Self::ContainerDirSync),
            "container.unit" => Ok(Self::ContainerUnit),
            "t3.session_issue" => Ok(Self::T3SessionIssue),
            "t3.project_add" => Ok(Self::T3ProjectAdd),
            "file.remove_tree" => Ok(Self::FileRemoveTree),
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
    /// SPIKE: typed ufw primitives.
    Ufw,
    /// SPIKE: generic Node primitives (files, links, trust, Caddy, units, CSR).
    Node,
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
            "ufw" => Some(Self::Ufw),
            "node" => Some(Self::Node),
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
