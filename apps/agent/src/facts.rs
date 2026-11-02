//! Facts the agent observes and reports to `POST /api/v1/agent/facts`, one kind per request. The agent only
//! observes: it reads files, statvfs, systemd and Docker, and starts no program. It sends a fact when it changed, and
//! again every [`RESYNC_INTERVAL`] so the Gateway recovers its state after a restore.

use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::time::{Duration, Instant};

/// How often the agent reads its units and sends them when they changed.
pub const UNITS_INTERVAL: Duration = Duration::from_secs(10);
/// How often the agent reads its host facts.
pub const HOST_INTERVAL: Duration = Duration::from_secs(60);
/// An unchanged fact is sent again after this long.
pub const RESYNC_INTERVAL: Duration = Duration::from_secs(600);
/// Filesystems that hold data; pseudo, network and snap mounts are left out.
const DATA_FILESYSTEMS: [&str; 7] = ["ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "f2fs"];
const MAX_FILESYSTEMS: usize = 64;

/// The mount points of data filesystems in `/proc/self/mounts`, in order, each once.
pub fn data_mounts(mounts: &str) -> Vec<String> {
    let mut seen = BTreeSet::new();
    mounts
        .lines()
        .filter_map(|line| {
            let mut fields = line.split_whitespace();
            let (_device, mount, kind) = (fields.next()?, fields.next()?, fields.next()?);
            let mount = unescape_mount(mount);
            (DATA_FILESYSTEMS.contains(&kind)
                && mount.starts_with('/')
                && !mount.starts_with("/snap/"))
            .then_some(mount)
        })
        .filter(|mount| seen.insert(mount.clone()))
        .take(MAX_FILESYSTEMS)
        .collect()
}

/// `/proc/self/mounts` writes a space, tab, newline or backslash in a path as an octal escape.
fn unescape_mount(field: &str) -> String {
    field
        .replace("\\040", " ")
        .replace("\\011", "\t")
        .replace("\\012", "\n")
        .replace("\\134", "\\")
}

/// One filesystem: its mount point and its size and free bytes for unprivileged users.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Filesystem {
    pub mount: String,
    pub size: u64,
    pub free: u64,
}

pub fn host_data(os: &str, arch: &str, filesystems: &[Filesystem]) -> Value {
    json!({
        "os": os,
        "arch": arch,
        "filesystems": filesystems
            .iter()
            .map(|fs| json!({"mount": fs.mount, "size": fs.size, "free": fs.free}))
            .collect::<Vec<_>>(),
    })
}

/// The units the agent watches and their state, sorted by runtime and name so an unchanged set gives the same
/// digest. `docker` is `available` or `absent`.
pub fn units_data(units: &BTreeMap<(String, String), String>, docker_available: bool) -> Value {
    json!({
        "docker": if docker_available { "available" } else { "absent" },
        "units": units
            .iter()
            .map(|((runtime, name), state)| json!({"name": name, "runtime": runtime, "state": state}))
            .collect::<Vec<_>>(),
    })
}

/// A role service name from the watch list, such as `caddy.service`, only when it is a plain unit name.
pub fn valid_service(name: &str) -> bool {
    name.len() <= 255
        && name.ends_with(".service")
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"@._:-".contains(&byte))
}

/// Decides which facts to send: a fact whose digest changed, or any fact not sent for [`RESYNC_INTERVAL`].
#[derive(Default)]
pub struct FactSender {
    sent: BTreeMap<String, (String, Instant)>,
}

impl FactSender {
    /// The request body when the fact must be sent, or None.
    pub fn body(&self, kind: &str, data: &Value, observed_at: &str, now: Instant) -> Option<Value> {
        let digest = digest(kind, data);
        match self.sent.get(kind) {
            Some((last, at)) if *last == digest && now.duration_since(*at) < RESYNC_INTERVAL => {
                None
            }
            _ => Some(json!({"kind": kind, "observed_at": observed_at, "data": data})),
        }
    }

    /// Records a fact the Gateway accepted.
    pub fn sent(&mut self, kind: &str, data: &Value, now: Instant) {
        self.sent.insert(kind.to_owned(), (digest(kind, data), now));
    }
}

fn digest(kind: &str, data: &Value) -> String {
    let mut hash = Sha256::new();
    hash.update(kind.as_bytes());
    hash.update([0]);
    hash.update(data.to_string().as_bytes());
    format!("{:x}", hash.finalize())
}

/// The agent's time for `observed_at`, to the second, as the Gateway accepts it.
pub fn observed_at() -> String {
    time::OffsetDateTime::now_utc()
        .format(time::macros::format_description!(
            "[year]-[month]-[day]T[hour]:[minute]:[second]Z"
        ))
        .unwrap_or_else(|_| "1970-01-01T00:00:00Z".into())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn data_mounts_keep_data_filesystems_once_and_skip_pseudo_and_snap_mounts() {
        let mounts = "sysfs /sys sysfs rw 0 0\n\
            /dev/vda1 / ext4 rw 0 0\n\
            tmpfs /run tmpfs rw 0 0\n\
            /dev/vda15 /boot/efi vfat rw 0 0\n\
            /dev/loop0 /snap/core/1 squashfs ro 0 0\n\
            /dev/vdb /var/lib/my\\040data xfs rw 0 0\n\
            /dev/vda1 / ext4 rw 0 0\n";

        assert_eq!(
            data_mounts(mounts),
            vec!["/".to_owned(), "/var/lib/my data".to_owned()]
        );
    }

    #[test]
    fn units_data_is_sorted_so_the_same_units_give_the_same_body() {
        let mut units = BTreeMap::new();
        units.insert(
            ("systemd".to_owned(), "orbit-process-4-web".to_owned()),
            "active".to_owned(),
        );
        units.insert(
            ("docker".to_owned(), "orbit-process-9-db".to_owned()),
            "running".to_owned(),
        );
        units.insert(
            ("systemd".to_owned(), "caddy.service".to_owned()),
            "failed".to_owned(),
        );

        assert_eq!(
            units_data(&units, true),
            json!({"docker": "available", "units": [
                {"name": "orbit-process-9-db", "runtime": "docker", "state": "running"},
                {"name": "caddy.service", "runtime": "systemd", "state": "failed"},
                {"name": "orbit-process-4-web", "runtime": "systemd", "state": "active"},
            ]})
        );
    }

    #[test]
    fn host_data_lists_each_filesystem() {
        let fs = [Filesystem {
            mount: "/".into(),
            size: 100,
            free: 40,
        }];

        assert_eq!(
            host_data("linux", "x86_64", &fs),
            json!({"os": "linux", "arch": "x86_64", "filesystems": [{"mount": "/", "size": 100, "free": 40}]})
        );
    }

    #[test]
    fn a_fact_is_sent_on_change_and_again_after_the_resync_interval() {
        let mut sender = FactSender::default();
        let start = Instant::now();
        let data = json!({"os": "linux"});

        let body = sender
            .body("host", &data, "2026-11-02T18:00:00Z", start)
            .unwrap();
        assert_eq!(
            body,
            json!({"kind": "host", "observed_at": "2026-11-02T18:00:00Z", "data": {"os": "linux"}})
        );
        sender.sent("host", &data, start);

        assert!(sender
            .body("host", &data, "t", start + Duration::from_secs(60))
            .is_none());
        assert!(sender
            .body(
                "host",
                &json!({"os": "darwin"}),
                "t",
                start + Duration::from_secs(60)
            )
            .is_some());
        assert!(sender
            .body("host", &data, "t", start + RESYNC_INTERVAL)
            .is_some());
    }

    #[test]
    fn a_fact_the_gateway_did_not_accept_is_sent_again() {
        let sender = FactSender::default();
        let data = json!({});

        assert!(sender.body("units", &data, "t", Instant::now()).is_some());
        assert!(sender.body("units", &data, "t", Instant::now()).is_some());
    }

    #[test]
    fn service_names_from_the_watch_list_are_plain_unit_names() {
        assert!(valid_service("caddy.service"));
        assert!(valid_service("wg-quick@orbit.service"));
        assert!(!valid_service("caddy"));
        assert!(!valid_service("../etc/passwd.service"));
        assert!(!valid_service("a b.service"));
    }

    #[test]
    fn observed_at_is_to_the_second_in_utc() {
        let at = observed_at();
        assert_eq!(at.len(), 20);
        assert!(at.ends_with('Z'));
    }
}
