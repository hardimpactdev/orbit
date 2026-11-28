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
    /// Withdraw one Node's part of a Route: pools and Caddyfile without it, its leaf folders removed, read back from Caddy
    /// (ADR 0271). Its target is the Node, so it runs one at a time with the Node's Route publications.
    RouteWithdrawSite,
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
    /// Force stops and removes a development clone and its admitted Node resources (ADR 0271).
    InstanceDestroy,
    /// Import the default head from the Node mirror into a task copy and move only its `refs/remotes/origin/<default>`
    /// (docs/reference/tasks.md#bring-main-into-a-copy). Its target is the copy.
    InstanceSyncBase,
    /// Remove what a task copy's sync left on the Node and in the copy. Its target is the copy.
    InstanceClearBase,
    /// Read a copy's workspace Git state beyond its baseline (docs/reference/development-containers.md#workspace-work-state).
    /// Read-only: no journal record and no target lock. Its target is the copy.
    InstanceWorkRead,
    /// Converge one host Process, a systemd unit or a Docker container, to running or stopped and
    /// its boot policy, and read it back (ADR 0271). Its target is the Node's part for that Process (`<node>:process-<id>`).
    ProcessEnsureState,
    /// Read the run a Process restart replaces: a unit's `InvocationID` or a container's ID and start time (ADR 0271).
    /// Its target is the Node's part for that Process (`<node>:process-<id>`).
    ProcessReadInstance,
    /// Restart one host Process at most once per order, only while it still runs the pinned run, and read a new run back
    /// (ADR 0271). Its target is the Node's part for that Process (`<node>:process-<id>`).
    ProcessRestart,
    /// Enable and start a Schedule's own Node timer and read it back (ADR 0271). Its target is the Node's part for that
    /// Schedule (`<node>:schedule-<uuid>`).
    ScheduleEnsureTimer,
    /// Converge one file, folder or link, or its absence (ADR 0271 N3). Its target is the Node.
    FileEnsure,
    /// Converge keys of one dotenv file (ADR 0271 N3). Its target is the Node.
    DotenvEnsure,
    /// Converge one entry of the access list of one path (ADR 0271 N3). Its target is the Node.
    PosixAclEnsure,
    /// Converge one certificate in the host's trust store (ADR 0271 N3). Its target is the Node.
    CaTrustEnsure,
    /// Converge one systemd unit to enabled or disabled, running or stopped (ADR 0271 N3). Its target is the Node.
    SystemdUnitEnsure,
    /// Apply one set of configuration files that share a lock: guard, write, validate, load, and roll back on failure
    /// (ADR 0271 N3). Its target is the Node, so the Node's sets apply one at a time.
    ConfigApply,
    /// Read one HTTP answer with a bounded wait and a typed expectation (ADR 0271 N3). Read-only. Its target is the Node.
    HttpProbe,
    /// Read the digest, type, owner and mode of one path without following a final link (ADR 0271 N3). Read-only. Its
    /// target is the Node.
    FileRead,
    /// Read the uid, primary group and home of one account through the host's NSS (ADR 0271 N3). Read-only. Its target is the Node.
    AccountRead,
    /// Converge one Incus instance: copied from a snapshot, running, stopped or absent, through the Incus API over its unix
    /// socket (ADR 0271 N3). Its target is the Node, because the instance may not exist yet.
    IncusInstanceEnsure,
    /// Issue one session of a Conn Server, its token sealed to the Gateway (ADR 0271 N3). Its target is the Node.
    ConnSessionIssue,
    /// Make one folder a project of a Conn Server (ADR 0271 N3). Its target is the Node.
    ConnProjectEnsure,
    /// Issue one pairing link of a Conn Server, its code sealed to the Gateway (ADR 0271 N3). Its target is the Node.
    ConnPairingIssue,
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
    pub const ALL: [Self; 42] = [
        Self::ProofNoop,
        Self::Sleep,
        Self::Wake,
        Self::Discard,
        Self::UfwEnsureRule,
        Self::TlsEnsureCsr,
        Self::RoutePublishSite,
        Self::RouteWithdrawSite,
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
        Self::InstanceDestroy,
        Self::InstanceSyncBase,
        Self::InstanceClearBase,
        Self::InstanceWorkRead,
        Self::ProcessEnsureState,
        Self::ProcessReadInstance,
        Self::ProcessRestart,
        Self::ScheduleEnsureTimer,
        Self::FileEnsure,
        Self::DotenvEnsure,
        Self::PosixAclEnsure,
        Self::CaTrustEnsure,
        Self::SystemdUnitEnsure,
        Self::ConfigApply,
        Self::HttpProbe,
        Self::FileRead,
        Self::AccountRead,
        Self::IncusInstanceEnsure,
        Self::ConnSessionIssue,
        Self::ConnProjectEnsure,
        Self::ConnPairingIssue,
    ];

    /// The family whose crate holds this action, and which a role enables.
    pub fn family(self) -> Family {
        match self {
            Self::ProofNoop => Family::Proof,
            Self::Sleep | Self::Wake | Self::Discard => Family::Power,
            Self::UfwEnsureRule => Family::Firewall,
            Self::TlsEnsureCsr | Self::RoutePublishSite | Self::RouteWithdrawSite => Family::Route,
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
            | Self::InstanceNames
            | Self::InstanceDestroy
            | Self::InstanceSyncBase
            | Self::InstanceClearBase
            | Self::InstanceWorkRead => Family::Instance,
            Self::ProcessEnsureState | Self::ProcessReadInstance | Self::ProcessRestart | Self::ScheduleEnsureTimer => Family::Process,
            Self::FileEnsure
            | Self::DotenvEnsure
            | Self::PosixAclEnsure
            | Self::CaTrustEnsure
            | Self::SystemdUnitEnsure
            | Self::ConfigApply
            | Self::HttpProbe
            | Self::FileRead
            | Self::AccountRead
            | Self::ConnSessionIssue
            | Self::ConnProjectEnsure
            | Self::ConnPairingIssue => Family::Host,
            Self::IncusInstanceEnsure => Family::Incus,
        }
    }

    /// Whether the action changes nothing: it runs outside the journal and the target lock, and a repeat is a fresh read.
    pub fn read_only(self) -> bool {
        matches!(self, Self::InstanceWorkRead | Self::HttpProbe | Self::FileRead | Self::AccountRead)
    }

    /// Whether the action makes one new item per order, such as a session: crash recovery never runs it again.
    pub fn issues_once(self) -> bool {
        matches!(self, Self::ConnSessionIssue | Self::ConnPairingIssue)
    }

    /// What the action's target names.
    pub fn target_kind(self) -> TargetKind {
        match self {
            Self::ProofNoop
            | Self::UfwEnsureRule
            | Self::TlsEnsureCsr
            | Self::RoutePublishSite
            | Self::RouteWithdrawSite
            | Self::DnsPublishRecords
            | Self::InstanceClone
            | Self::InstanceFetchBranch
            | Self::InstanceClubKey
            | Self::InstanceCopyVolume
            | Self::InstanceSpareVolume
            | Self::InstanceFleetReady
            | Self::ProcessEnsureState
            | Self::ProcessReadInstance
            | Self::ProcessRestart
            | Self::ScheduleEnsureTimer
            | Self::FileEnsure
            | Self::DotenvEnsure
            | Self::PosixAclEnsure
            | Self::CaTrustEnsure
            | Self::SystemdUnitEnsure
            | Self::ConfigApply
            | Self::HttpProbe
            | Self::FileRead
            | Self::AccountRead
            | Self::IncusInstanceEnsure
            | Self::ConnSessionIssue
            | Self::ConnProjectEnsure
            | Self::ConnPairingIssue => TargetKind::Node,
            Self::Sleep
            | Self::Wake
            | Self::Discard
            | Self::InstancePrepare
            | Self::InstanceStart
            | Self::InstanceCheckout
            | Self::InstanceT3
            | Self::InstanceStop
            | Self::InstanceNames
            | Self::InstanceDestroy
            | Self::InstanceSyncBase
            | Self::InstanceClearBase
            | Self::InstanceWorkRead => TargetKind::Instance,
        }
    }

    /// Whether a lost receipt keeps the Gateway's target fenced (ADR 0271): only a terminal result settles the step, so an
    /// expiry before the target lock is not journaled as one, and a result goes back only once its `done` record is durable.
    /// A Route withdrawal's DNS and site steps are fenced too: they run inside a receipt-bound removal.
    pub fn receipt_fenced(self) -> bool {
        // The Route actions are fenced for an engine-run Route update (route.update), whose target stays fenced until each receipt.
        // The host primitives that change a resource replace them in the Route flows (ADR 0271, Actions that predate the rule), so they are fenced too.
        matches!(
            self,
            Self::InstanceDestroy
                | Self::InstanceFetchBranch
                | Self::InstanceSyncBase
                | Self::InstanceClearBase
                | Self::TlsEnsureCsr
                | Self::RoutePublishSite
                | Self::DnsPublishRecords
                | Self::RouteWithdrawSite
                | Self::ProcessEnsureState
                | Self::ProcessReadInstance
                | Self::ProcessRestart
                | Self::ScheduleEnsureTimer
                | Self::FileEnsure
                | Self::DotenvEnsure
                | Self::PosixAclEnsure
                | Self::CaTrustEnsure
                | Self::SystemdUnitEnsure
                | Self::ConfigApply
                | Self::IncusInstanceEnsure
        )
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
            Self::RouteWithdrawSite => "route.withdraw_site",
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
            Self::InstanceDestroy => "instance.destroy",
            Self::InstanceSpareVolume => "instance.spare_volume",
            Self::InstanceFleetReady => "instance.fleet_ready",
            Self::InstanceNames => "instance.names",
            Self::InstanceSyncBase => "instance.sync_base",
            Self::InstanceClearBase => "instance.clear_base",
            Self::InstanceWorkRead => "instance.work_read",
            Self::ProcessEnsureState => "process.ensure_state",
            Self::ProcessReadInstance => "process.read_instance",
            Self::ProcessRestart => "process.restart",
            Self::ScheduleEnsureTimer => "schedule.ensure_timer",
            Self::FileEnsure => "file.ensure",
            Self::DotenvEnsure => "dotenv.ensure",
            Self::PosixAclEnsure => "posix.acl.ensure",
            Self::CaTrustEnsure => "ca.trust.ensure",
            Self::SystemdUnitEnsure => "systemd.unit.ensure",
            Self::ConfigApply => "config.apply",
            Self::HttpProbe => "http.probe",
            Self::FileRead => "file.read",
            Self::AccountRead => "account.read",
            Self::IncusInstanceEnsure => "incus.instance.ensure",
            Self::ConnSessionIssue => "conn.session.issue",
            Self::ConnProjectEnsure => "conn.project.ensure",
            Self::ConnPairingIssue => "conn.pairing.issue",
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
            "route.withdraw_site" => Ok(Self::RouteWithdrawSite),
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
            "instance.destroy" => Ok(Self::InstanceDestroy),
            "instance.spare_volume" => Ok(Self::InstanceSpareVolume),
            "instance.fleet_ready" => Ok(Self::InstanceFleetReady),
            "instance.names" => Ok(Self::InstanceNames),
            "instance.sync_base" => Ok(Self::InstanceSyncBase),
            "instance.clear_base" => Ok(Self::InstanceClearBase),
            "instance.work_read" => Ok(Self::InstanceWorkRead),
            "process.ensure_state" => Ok(Self::ProcessEnsureState),
            "process.read_instance" => Ok(Self::ProcessReadInstance),
            "process.restart" => Ok(Self::ProcessRestart),
            "schedule.ensure_timer" => Ok(Self::ScheduleEnsureTimer),
            "file.ensure" => Ok(Self::FileEnsure),
            "dotenv.ensure" => Ok(Self::DotenvEnsure),
            "posix.acl.ensure" => Ok(Self::PosixAclEnsure),
            "ca.trust.ensure" => Ok(Self::CaTrustEnsure),
            "systemd.unit.ensure" => Ok(Self::SystemdUnitEnsure),
            "config.apply" => Ok(Self::ConfigApply),
            "http.probe" => Ok(Self::HttpProbe),
            "file.read" => Ok(Self::FileRead),
            "account.read" => Ok(Self::AccountRead),
            "incus.instance.ensure" => Ok(Self::IncusInstanceEnsure),
            "conn.session.issue" => Ok(Self::ConnSessionIssue),
            "conn.project.ensure" => Ok(Self::ConnProjectEnsure),
            "conn.pairing.issue" => Ok(Self::ConnPairingIssue),
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
    /// A Process's run state and a Schedule's Node timer (ADR 0271).
    Process,
    /// Host-resource primitives: files, dotenv keys, access lists, trust, units, configuration sets, HTTP probes, file and account reads, Conn Server sessions, projects and pairing links (ADR 0271 N3).
    Host,
    /// Incus primitives through the Incus API over its unix socket: one instance (ADR 0271 N3).
    Incus,
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
            "process" => Some(Self::Process),
            "host" => Some(Self::Host),
            "incus" => Some(Self::Incus),
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
        assert_eq!("process.ensure_state".parse(), Ok(Action::ProcessEnsureState));
        assert_eq!("schedule.ensure_timer".parse(), Ok(Action::ScheduleEnsureTimer));
        assert_eq!("process.restart".parse(), Ok(Action::ProcessRestart));
        assert_eq!("process.read_instance".parse(), Ok(Action::ProcessReadInstance));
        assert!(Enabled::parse("process\n").expect("valid file").allows(Action::ProcessRestart));
        assert_eq!(Action::ScheduleEnsureTimer.family(), Family::Process);
        assert!(Enabled::parse("process\n").expect("valid file").allows(Action::ProcessEnsureState));
        assert!(!Enabled::parse("instance\n").expect("valid file").allows(Action::ProcessEnsureState));
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
