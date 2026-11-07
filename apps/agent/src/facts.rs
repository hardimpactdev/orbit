//! Facts the agent observes and reports to `POST /api/v1/agent/facts`, one kind per request. The agent only
//! observes: it reads files, statvfs, systemd and Docker, and starts no program. It sends a fact when it changed, and
//! again every [`RESYNC_INTERVAL`] so the Gateway recovers its state after a restore. A report the Gateway does not
//! accept is sent again after a short, bounded delay ([`RetryTimer`]), so a Node that boots before the Gateway answers
//! is reported within seconds.

use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::time::{Duration, Instant};

/// How often the agent reads its units and sends them when they changed.
pub const UNITS_INTERVAL: Duration = Duration::from_secs(10);
/// How often the agent reads its host facts.
pub const HOST_INTERVAL: Duration = Duration::from_secs(60);
/// How often a task VM host's agent reads the egress sources.
pub const EGRESS_INTERVAL: Duration = Duration::from_secs(5);
/// A VM's `egress` fact is handed to the reporter at most once in this time.
pub const EGRESS_DEBOUNCE: Duration = Duration::from_secs(15);
/// An unchanged fact is sent again after this long.
pub const RESYNC_INTERVAL: Duration = Duration::from_secs(600);
/// The first wait before a failed report is sent again; each further wait doubles up to [`RETRY_MAX`].
pub const RETRY_FIRST: Duration = Duration::from_secs(1);
/// The longest wait between two tries of a failed report.
pub const RETRY_MAX: Duration = Duration::from_secs(4);
/// The files an `accounts` fact is read from.
pub const PASSWD: &str = "/etc/passwd";
/// The group names of the accounts, by gid.
pub const GROUP: &str = "/etc/group";
const MAX_ACCOUNTS: usize = 512;
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

/// The awake markers live here; the agent checks only watch-list paths below it.
pub const AWAKE_MARKER_DIRECTORY: &str = "/dev/shm/orbit/hibernation/";
/// The UFW configuration and the rules files UFW writes, IPv4 then IPv6.
pub const UFW_CONF: &str = "/etc/ufw/ufw.conf";
pub const UFW_RULES: [(&str, &str); 2] = [
    ("/etc/ufw/user.rules", "v4"),
    ("/etc/ufw/user6.rules", "v6"),
];
const MAX_UFW_RULES: usize = 1024;

/// One unit's state. `state` is the active state, or `not-found` when systemd has no such unit; `load` and `sub` are
/// systemd's load and sub state, absent for a Docker container.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct UnitState {
    pub state: String,
    pub load: Option<String>,
    pub sub: Option<String>,
}

impl UnitState {
    /// A Docker container, which has only its state.
    pub fn container(state: String) -> Self {
        Self {
            state,
            load: None,
            sub: None,
        }
    }

    /// A systemd unit: a unit systemd cannot find reads as `not-found`, so Doctor can name it.
    pub fn systemd(load: String, active: String, sub: String) -> Self {
        let state = if load == "not-found" {
            load.clone()
        } else {
            active
        };
        Self {
            state,
            load: Some(load),
            sub: Some(sub),
        }
    }
}

/// The units the agent watches and their state, sorted by runtime and name so an unchanged set gives the same
/// digest. `docker` is `available` or `absent`. `awake` lists the watched awake markers that exist.
pub fn units_data(
    units: &BTreeMap<(String, String), UnitState>,
    docker_available: bool,
    awake: &[String],
) -> Value {
    json!({
        "docker": if docker_available { "available" } else { "absent" },
        "units": units
            .iter()
            .map(|((runtime, name), unit)| {
                let mut row = json!({"name": name, "runtime": runtime, "state": unit.state});
                if let (Some(load), Some(sub)) = (&unit.load, &unit.sub) {
                    row["load"] = json!(load);
                    row["sub"] = json!(sub);
                }
                row
            })
            .collect::<Vec<_>>(),
        "awake": awake,
    })
}

/// An awake marker path from the watch list, only when it is a plain file name below [`AWAKE_MARKER_DIRECTORY`].
pub fn valid_awake_marker(path: &str) -> bool {
    path.strip_prefix(AWAKE_MARKER_DIRECTORY)
        .is_some_and(|name| {
            !name.is_empty()
                && name != "."
                && name != ".."
                && name
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte))
        })
}

/// The UFW fact from its configuration and rules files, read as text: whether it is enabled, and each rule from the
/// `### tuple ###` lines UFW writes, in file order. Without `ufw.conf` UFW is not installed. A tuple the agent cannot
/// read is reported as `{"unreadable": true}`, so the Gateway never mistakes it for an absent rule.
pub fn ufw_data(conf: Option<&str>, rules: &[(Option<String>, &str)]) -> Value {
    let Some(conf) = conf else {
        return json!({"installed": false, "enabled": false, "rules": []});
    };
    let enabled = conf.lines().any(|line| {
        line.trim()
            .strip_prefix("ENABLED=")
            .is_some_and(|value| value.trim_matches(['"', '\'']) == "yes")
    });
    let rules: Vec<Value> = rules
        .iter()
        .filter_map(|(text, family)| text.as_deref().map(|text| (text, *family)))
        .flat_map(|(text, family)| {
            text.lines()
                .filter_map(|line| line.strip_prefix("### tuple ### "))
                .map(move |tuple| {
                    ufw_rule(tuple, family).unwrap_or_else(|| json!({"unreadable": true}))
                })
        })
        .take(MAX_UFW_RULES)
        .collect();
    json!({"installed": true, "enabled": enabled, "rules": rules})
}

/// One UFW tuple: `[route:]action[_log] protocol dport dst sport src [dapp sapp] direction[_iface][!out_iface] [comment=hex]`.
fn ufw_rule(tuple: &str, family: &str) -> Option<Value> {
    let mut fields: Vec<&str> = tuple.split_whitespace().collect();
    let comment = match fields.last() {
        Some(field) if field.starts_with("comment=") => {
            let comment = hex_text(&field["comment=".len()..])?;
            fields.pop();
            comment
        }
        _ => String::new(),
    };
    let (route, action) = match fields.first()?.strip_prefix("route:") {
        Some(action) => (true, action),
        None => (false, *fields.first()?),
    };
    let action = action.split('_').next()?;
    let (app, direction) = match fields.len() {
        7 => (false, fields[6]),
        9 => (true, fields[8]),
        _ => return None,
    };
    let (mut inward, mut outward) = (None, None);
    let mut way = None;
    for part in direction.split('!') {
        let (side, interface) = part
            .split_once('_')
            .map_or((part, None), |(side, name)| (side, Some(name)));
        match side {
            "in" => inward = interface,
            "out" => outward = interface,
            _ => return None,
        }
        way.get_or_insert(side);
    }
    let direction = if route { "fwd" } else { way? };
    Some(json!({
        "action": action, "protocol": fields[1], "port": fields[2], "destination": fields[3],
        "source_port": fields[4], "source": fields[5], "direction": direction,
        "in_interface": inward, "out_interface": outward, "family": family, "comment": comment, "app": app,
    }))
}

/// A UFW comment, which UFW stores hex-encoded.
fn hex_text(hex: &str) -> Option<String> {
    if !hex.len().is_multiple_of(2) {
        return None;
    }
    let bytes = (0..hex.len())
        .step_by(2)
        .map(|at| u8::from_str_radix(hex.get(at..at + 2)?, 16).ok())
        .collect::<Option<Vec<u8>>>()?;
    String::from_utf8(bytes).ok()
}

/// The local accounts of `passwd`, each with the name of its primary group from `group`, in uid order. An entry that is
/// malformed, has no group, or holds a name or a path the Gateway would refuse is left out.
pub fn accounts_data(passwd: &str, group: &str) -> Value {
    let mut groups: BTreeMap<u32, &str> = BTreeMap::new();
    for line in group.lines() {
        let mut fields = line.split(':');
        if let (Some(name), Some(_), Some(gid)) = (fields.next(), fields.next(), fields.next()) {
            if let Ok(gid) = gid.parse() {
                groups.entry(gid).or_insert(name);
            }
        }
    }
    let mut accounts: Vec<(u32, &str, Value)> = passwd
        .lines()
        .filter_map(|line| {
            let fields: Vec<&str> = line.split(':').collect();
            let [user, _, uid, gid, _, home, shell] = fields[..] else {
                return None;
            };
            let (uid, gid) = (uid.parse::<u32>().ok()?, gid.parse::<u32>().ok()?);
            let group = *groups.get(&gid)?;
            (account_name(user)
                && account_name(group)
                && absolute_path(home)
                && absolute_path(shell))
            .then(|| {
                (
                    uid,
                    user,
                    json!({"user": user, "uid": uid, "group": group, "home": home, "shell": shell}),
                )
            })
        })
        .collect();
    accounts.sort_by(|(left_uid, left, _), (right_uid, right, _)| {
        left_uid.cmp(right_uid).then_with(|| left.cmp(right))
    });
    json!({"accounts": accounts.into_iter().map(|(_, _, account)| account).take(MAX_ACCOUNTS).collect::<Vec<_>>()})
}

/// A Linux account or group name: lower case letters, digits, `_` and `-`, not starting with a digit or `-`, and at
/// most 32 characters, with an optional trailing `$`.
fn account_name(name: &str) -> bool {
    let name = name.strip_suffix('$').unwrap_or(name);
    (1..=32).contains(&name.len())
        && name.bytes().enumerate().all(|(index, byte)| match byte {
            b'a'..=b'z' | b'_' => true,
            b'0'..=b'9' | b'-' => index > 0,
            _ => false,
        })
}

fn absolute_path(path: &str) -> bool {
    path.starts_with('/')
        && path.len() <= 4096
        && !path.bytes().any(|byte| byte < 32 || byte == 127)
}

/// A role service name from the watch list, such as `caddy.service`, only when it is a plain unit name.
pub fn valid_service(name: &str) -> bool {
    name.len() <= 255
        && name.ends_with(".service")
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"@._:-".contains(&byte))
}

/// Decides which facts to send: a fact whose digest changed, or any fact not sent for [`RESYNC_INTERVAL`]. A kind
/// with many items, such as `checkout`, names each in `subject`.
#[derive(Default)]
pub struct FactSender {
    sent: BTreeMap<(String, String), (String, Instant)>,
}

impl FactSender {
    /// The request body when the fact must be sent, or None.
    pub fn body(
        &self,
        kind: &str,
        subject: Option<&str>,
        data: &Value,
        observed_at: &str,
        now: Instant,
    ) -> Option<Value> {
        let digest = digest(kind, data);
        match self.sent.get(&key(kind, subject)) {
            Some((last, at)) if *last == digest && now.duration_since(*at) < RESYNC_INTERVAL => {
                None
            }
            _ => Some(match subject {
                Some(subject) => {
                    json!({"kind": kind, "subject": subject, "observed_at": observed_at, "data": data})
                }
                None => json!({"kind": kind, "observed_at": observed_at, "data": data}),
            }),
        }
    }

    /// Records a fact the Gateway accepted.
    pub fn sent(&mut self, kind: &str, subject: Option<&str>, data: &Value, now: Instant) {
        self.sent
            .insert(key(kind, subject), (digest(kind, data), now));
    }

    /// Forgets the items of a kind that `keep` rejects, so an item that comes back is sent again.
    pub fn retain(&mut self, kind: &str, keep: impl Fn(&str) -> bool) {
        self.sent
            .retain(|(sent_kind, subject), _| sent_kind != kind || keep(subject));
    }
}

fn key(kind: &str, subject: Option<&str>) -> (String, String) {
    (kind.to_owned(), subject.unwrap_or_default().to_owned())
}

/// Sends facts and remembers the ones the Gateway did not accept, so [`Reporter::retry`] sends exactly those again
/// with the data they had, without reading the Node again.
pub struct Reporter {
    client: reqwest::Client,
    url: String,
    sender: FactSender,
    failed: BTreeMap<(String, String), (Option<String>, Value)>,
    last_error: Option<String>,
}

impl Reporter {
    pub fn new(client: reqwest::Client, gateway: &str) -> Self {
        Self {
            client,
            url: format!("{}/api/v1/agent/facts", gateway.trim_end_matches('/')),
            sender: FactSender::default(),
            failed: BTreeMap::new(),
            last_error: None,
        }
    }

    /// Sends the fact when it changed or is due. A failure is kept for [`Reporter::retry`] and logged once until the
    /// next success.
    pub async fn report(&mut self, kind: &str, subject: Option<&str>, data: &Value) {
        let now = Instant::now();
        let Some(body) = self.sender.body(kind, subject, data, &observed_at(), now) else {
            self.failed.remove(&key(kind, subject));
            return;
        };
        match post(&self.client, &self.url, &body).await {
            Ok(()) => {
                self.sender.sent(kind, subject, data, now);
                self.failed.remove(&key(kind, subject));
                self.last_error = None;
            }
            Err(error) => {
                if error.retry {
                    self.failed.insert(
                        key(kind, subject),
                        (subject.map(str::to_owned), data.clone()),
                    );
                } else {
                    self.failed.remove(&key(kind, subject));
                }
                if self.last_error.as_ref() != Some(&error.message) {
                    let what = subject
                        .map_or_else(|| kind.to_owned(), |subject| format!("{kind} {subject}"));
                    eprintln!("orbit-agent: {what} fact report failed: {}", error.message);
                    self.last_error = Some(error.message);
                }
            }
        }
    }

    /// Keeps a fact for [`Reporter::retry`] without sending it now, for a caller that stops sending after a failure.
    pub fn defer(&mut self, kind: &str, subject: Option<&str>, data: Value) {
        self.failed
            .insert(key(kind, subject), (subject.map(str::to_owned), data));
    }

    /// Whether a report is waiting to be sent again.
    pub fn has_failed(&self) -> bool {
        !self.failed.is_empty()
    }

    /// Sends the facts that failed again.
    pub async fn retry(&mut self) {
        for ((kind, _), (subject, data)) in std::mem::take(&mut self.failed) {
            self.report(&kind, subject.as_deref(), &data).await;
        }
    }

    /// Stops retrying the failed reports of a kind, for a source that is gone.
    pub fn drop_failed(&mut self, kind: &str) {
        self.failed
            .retain(|(failed_kind, _), _| failed_kind != kind);
    }

    /// Forgets the items of a kind that `keep` rejects, so an item that comes back is sent again.
    pub fn retain(&mut self, kind: &str, keep: impl Fn(&str) -> bool) {
        self.sender.retain(kind, &keep);
        self.failed
            .retain(|(failed_kind, subject), _| failed_kind != kind || keep(subject));
    }
}

/// When to try a failed call to the Gateway again: after [`RETRY_FIRST`], then twice as long each time up to
/// [`RETRY_MAX`], and from the start again once nothing is outstanding. The deadline stays until it fires, so other
/// events in the same loop cannot postpone it.
#[derive(Default)]
pub struct RetryTimer {
    failures: u32,
    at: Option<tokio::time::Instant>,
}

impl RetryTimer {
    /// Call after each pass: arms the timer when a call is outstanding and none is armed, and disarms it when none is.
    pub fn update(&mut self, outstanding: bool) {
        if !outstanding {
            self.failures = 0;
            self.at = None;
        } else if self.at.is_none() {
            let delay = (RETRY_FIRST * 2u32.pow(self.failures)).min(RETRY_MAX);
            self.failures = (self.failures + 1).min(3);
            self.at = Some(tokio::time::Instant::now() + delay);
        }
    }

    /// Completes when the armed deadline passes, and disarms the timer; never completes when none is armed. A caller
    /// that drops the future early keeps the deadline.
    pub async fn due(&mut self) {
        match self.at {
            Some(at) => tokio::time::sleep_until(at).await,
            None => std::future::pending().await,
        }
        self.at = None;
    }
}

/// Sends one fact body to `POST /api/v1/agent/facts`.
pub async fn post(client: &reqwest::Client, url: &str, body: &Value) -> Result<(), PostError> {
    client
        .post(url)
        .json(body)
        .timeout(std::time::Duration::from_secs(30))
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map(|_| ())
        .map_err(|error| PostError {
            // A 4xx other than a timeout or a rate limit means the Gateway refuses this exact report, such as a kind an
            // older Gateway does not know; sending it again every few seconds cannot change that.
            retry: error.status().is_none_or(|status| {
                !status.is_client_error() || matches!(status.as_u16(), 408 | 429)
            }),
            message: error.to_string(),
        })
}

/// Why the Gateway did not accept a report, and whether sending it again soon can help.
#[derive(Debug)]
pub struct PostError {
    pub message: String,
    pub retry: bool,
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

    #[tokio::test(start_paused = true)]
    async fn the_retry_timer_waits_1_2_4_then_4_seconds_and_a_success_starts_it_again() {
        let mut timer = RetryTimer::default();
        for expected in [1, 2, 4, 4, 4] {
            timer.update(true);
            let armed = tokio::time::Instant::now();
            timer.due().await;
            assert_eq!(armed.elapsed(), Duration::from_secs(expected));
        }
        timer.update(false);
        timer.update(true);
        let armed = tokio::time::Instant::now();
        timer.due().await;
        assert_eq!(armed.elapsed(), RETRY_FIRST);
    }

    #[tokio::test(start_paused = true)]
    async fn an_armed_retry_deadline_is_not_postponed_by_later_passes() {
        let mut timer = RetryTimer::default();
        timer.update(true);
        tokio::time::advance(Duration::from_millis(600)).await;
        timer.update(true);
        let armed = tokio::time::Instant::now();
        timer.due().await;
        assert_eq!(armed.elapsed(), Duration::from_millis(400));
    }

    /// A client that ignores any proxy in the environment, so the test reaches its local Gateway.
    fn client() -> reqwest::Client {
        reqwest::Client::builder().no_proxy().build().unwrap()
    }

    #[tokio::test(start_paused = true)]
    async fn an_unarmed_retry_timer_never_completes() {
        let mut timer = RetryTimer::default();
        assert!(tokio::time::timeout(Duration::from_secs(3600), timer.due())
            .await
            .is_err());
    }

    /// A Gateway that refuses the first requests and accepts the rest, and counts what it saw.
    async fn gateway(refusals: usize) -> (String, std::sync::Arc<std::sync::atomic::AtomicUsize>) {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let seen = std::sync::Arc::new(AtomicUsize::new(0));
        let counter = seen.clone();
        let app = axum::Router::new().route(
            "/api/v1/agent/facts",
            axum::routing::post(move || {
                let count = counter.fetch_add(1, Ordering::SeqCst);
                async move {
                    if count < refusals {
                        axum::http::StatusCode::SERVICE_UNAVAILABLE
                    } else {
                        axum::http::StatusCode::NO_CONTENT
                    }
                }
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        (url, seen)
    }

    #[tokio::test]
    async fn a_refused_report_is_kept_and_sent_again_until_the_gateway_accepts_it() {
        use std::sync::atomic::Ordering;
        let (url, seen) = gateway(2).await;
        let mut reporter = Reporter::new(client(), &url);
        let data = json!({"os": "linux"});

        reporter.report("host", None, &data).await;
        reporter
            .report("checkout", Some("/apps/a/main"), &data)
            .await;
        assert!(reporter.has_failed());
        assert_eq!(seen.load(Ordering::SeqCst), 2);

        reporter.retry().await;
        assert!(!reporter.has_failed());
        assert_eq!(seen.load(Ordering::SeqCst), 4);

        // The accepted facts are not sent again until they change or the resync is due.
        reporter.report("host", None, &data).await;
        reporter.retry().await;
        assert_eq!(seen.load(Ordering::SeqCst), 4);
    }

    #[tokio::test]
    async fn a_newer_accepted_report_replaces_an_older_refused_one() {
        use std::sync::atomic::Ordering;
        let (url, seen) = gateway(1).await;
        let mut reporter = Reporter::new(client(), &url);

        reporter.report("host", None, &json!({"free": 1})).await;
        assert!(reporter.has_failed());
        reporter.report("host", None, &json!({"free": 2})).await;

        assert!(!reporter.has_failed());
        reporter.retry().await;
        assert_eq!(seen.load(Ordering::SeqCst), 2);
    }

    /// A Gateway that answers every report with `status`, and counts them.
    async fn gateway_answering(
        status: u16,
    ) -> (String, std::sync::Arc<std::sync::atomic::AtomicUsize>) {
        use std::sync::atomic::{AtomicUsize, Ordering};
        let seen = std::sync::Arc::new(AtomicUsize::new(0));
        let counter = seen.clone();
        let app = axum::Router::new().route(
            "/api/v1/agent/facts",
            axum::routing::post(move || {
                counter.fetch_add(1, Ordering::SeqCst);
                async move { axum::http::StatusCode::from_u16(status).unwrap() }
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let url = format!("http://{}", listener.local_addr().unwrap());
        tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        (url, seen)
    }

    #[tokio::test]
    async fn a_report_the_gateway_refuses_for_good_is_not_retried_but_a_busy_gateway_is() {
        use std::sync::atomic::Ordering;
        for (status, retried) in [
            (422, false),
            (401, false),
            (404, false),
            (429, true),
            (408, true),
            (502, true),
            (500, true),
        ] {
            let (url, seen) = gateway_answering(status).await;
            let mut reporter = Reporter::new(client(), &url);

            reporter
                .report("accounts", None, &json!({"accounts": []}))
                .await;

            assert_eq!(reporter.has_failed(), retried, "status {status}");
            reporter.retry().await;
            assert_eq!(
                seen.load(Ordering::SeqCst),
                if retried { 2 } else { 1 },
                "status {status}"
            );
        }
    }

    #[tokio::test]
    async fn a_refused_item_that_leaves_the_set_is_not_sent_again() {
        use std::sync::atomic::Ordering;
        let (url, seen) = gateway(1).await;
        let mut reporter = Reporter::new(client(), &url);
        reporter
            .report("checkout", Some("/apps/gone/main"), &json!({}))
            .await;
        assert!(reporter.has_failed());

        reporter.retain("checkout", |subject| subject != "/apps/gone/main");
        reporter.retry().await;

        assert!(!reporter.has_failed());
        assert_eq!(seen.load(Ordering::SeqCst), 1);
    }

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
    fn accounts_data_lists_accounts_by_uid_with_their_group_and_leaves_out_unusable_entries() {
        let passwd = "root:x:0:0:root:/root:/bin/bash\n\
            orbit:x:1000:1000:Orbit:/home/orbit:/bin/bash\n\
            www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n\
            ghost:x:1500:1500::/home/ghost:/bin/bash\n\
            Bad Name:x:1600:1000::/home/bad:/bin/bash\n\
            relative:x:1700:1000::home/relative:/bin/bash\n\
            short:x:1800:1000\n\
            nologin:x:abc:1000::/home/n:/bin/bash\n\
            dollar$:x:1900:1000::/home/dollar:/bin/bash\n";
        let group = "root:x:0:\norbit:x:1000:\nwww-data:x:33:\ndup:x:1000:\n";

        let data = accounts_data(passwd, group);
        let users: Vec<&str> = data["accounts"]
            .as_array()
            .unwrap()
            .iter()
            .map(|account| account["user"].as_str().unwrap())
            .collect();

        assert_eq!(users, ["root", "www-data", "orbit", "dollar$"]);
        assert_eq!(
            data["accounts"][2],
            json!({"user": "orbit", "uid": 1000, "group": "orbit", "home": "/home/orbit", "shell": "/bin/bash"})
        );
    }

    #[test]
    fn accounts_data_lists_at_most_512_accounts() {
        let passwd: String = (1000..1600)
            .map(|uid| format!("u{uid}:x:{uid}:1000::/home/u{uid}:/bin/sh\n"))
            .collect();

        let data = accounts_data(&passwd, "g:x:1000:\n");

        assert_eq!(data["accounts"].as_array().unwrap().len(), 512);
        assert_eq!(data["accounts"][511]["uid"], 1511);
    }

    #[test]
    fn units_data_is_sorted_so_the_same_units_give_the_same_body() {
        let mut units = BTreeMap::new();
        units.insert(
            ("systemd".to_owned(), "orbit-process-4-web".to_owned()),
            UnitState::systemd("loaded".into(), "activating".into(), "auto-restart".into()),
        );
        units.insert(
            ("docker".to_owned(), "orbit-process-9-db".to_owned()),
            UnitState::container("running".into()),
        );
        units.insert(
            ("systemd".to_owned(), "caddy.service".to_owned()),
            UnitState::systemd("not-found".into(), "inactive".into(), "dead".into()),
        );

        assert_eq!(
            units_data(
                &units,
                true,
                &["/dev/shm/orbit/hibernation/instance-4".to_owned()]
            ),
            json!({"docker": "available", "units": [
                {"name": "orbit-process-9-db", "runtime": "docker", "state": "running"},
                {"name": "caddy.service", "runtime": "systemd", "state": "not-found", "load": "not-found", "sub": "dead"},
                {"name": "orbit-process-4-web", "runtime": "systemd", "state": "activating", "load": "loaded", "sub": "auto-restart"},
            ], "awake": ["/dev/shm/orbit/hibernation/instance-4"]})
        );
    }

    #[test]
    fn only_plain_marker_names_below_the_hibernation_directory_are_watched() {
        assert!(valid_awake_marker("/dev/shm/orbit/hibernation/instance-4"));
        assert!(!valid_awake_marker("/dev/shm/orbit/hibernation/"));
        assert!(!valid_awake_marker("/dev/shm/orbit/hibernation/../secret"));
        assert!(!valid_awake_marker("/dev/shm/orbit/hibernation/a/b"));
        assert!(!valid_awake_marker("/etc/shadow"));
    }

    #[test]
    fn ufw_data_reads_each_tuple_with_its_interfaces_and_hex_comment() {
        let v4 = "*filter\n### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 in comment=6f72626974\n-A ufw-user-input -p tcp --dport 22 -j ACCEPT\n### tuple ### deny_log any 9000:9100 0.0.0.0/0 any 10.44.0.0/24 in_wg0\n### tuple ### route:allow any any 0.0.0.0/0 any 0.0.0.0/0 in_wg0!out_eth0\n### tuple ### allow tcp 22 0.0.0.0/0 any 0.0.0.0/0 OpenSSH - in\n### tuple ### nonsense\n";
        let data = ufw_data(
            Some("ENABLED=yes\nLOGLEVEL=low\n"),
            &[(Some(v4.to_owned()), "v4"), (None, "v6")],
        );

        assert_eq!(data["installed"], json!(true));
        assert_eq!(data["enabled"], json!(true));
        let rules = data["rules"].as_array().unwrap();
        assert_eq!(rules.len(), 5);
        assert_eq!(
            rules[0],
            json!({"action": "allow", "protocol": "tcp", "port": "22", "destination": "0.0.0.0/0",
            "source_port": "any", "source": "0.0.0.0/0", "direction": "in", "in_interface": null, "out_interface": null,
            "family": "v4", "comment": "orbit", "app": false})
        );
        assert_eq!(
            (
                rules[1]["action"].clone(),
                rules[1]["in_interface"].clone(),
                rules[1]["port"].clone()
            ),
            (json!("deny"), json!("wg0"), json!("9000:9100"))
        );
        assert_eq!(
            (
                rules[2]["direction"].clone(),
                rules[2]["out_interface"].clone()
            ),
            (json!("fwd"), json!("eth0"))
        );
        assert_eq!(rules[3]["app"], json!(true));
        assert_eq!(rules[4], json!({"unreadable": true}));
    }

    #[test]
    fn ufw_data_without_its_configuration_is_not_installed_and_disabled_without_enabled_yes() {
        assert_eq!(
            ufw_data(None, &[]),
            json!({"installed": false, "enabled": false, "rules": []})
        );
        assert_eq!(ufw_data(Some("ENABLED=no\n"), &[])["enabled"], json!(false));
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
            .body("host", None, &data, "2026-11-02T18:00:00Z", start)
            .unwrap();
        assert_eq!(
            body,
            json!({"kind": "host", "observed_at": "2026-11-02T18:00:00Z", "data": {"os": "linux"}})
        );
        sender.sent("host", None, &data, start);

        assert!(sender
            .body("host", None, &data, "t", start + Duration::from_secs(60))
            .is_none());
        assert!(sender
            .body(
                "host",
                None,
                &json!({"os": "darwin"}),
                "t",
                start + Duration::from_secs(60)
            )
            .is_some());
        assert!(sender
            .body("host", None, &data, "t", start + RESYNC_INTERVAL)
            .is_some());
    }

    #[test]
    fn a_fact_the_gateway_did_not_accept_is_sent_again() {
        let sender = FactSender::default();
        let data = json!({});

        assert!(sender
            .body("units", None, &data, "t", Instant::now())
            .is_some());
        assert!(sender
            .body("units", None, &data, "t", Instant::now())
            .is_some());
    }

    #[test]
    fn a_checkout_is_keyed_by_its_subject_and_forgotten_when_it_goes() {
        let mut sender = FactSender::default();
        let now = Instant::now();
        let data = json!({"branch": "main"});
        sender.sent("checkout", Some("/apps/a/default"), &data, now);

        assert!(sender
            .body("checkout", Some("/apps/a/default"), &data, "t", now)
            .is_none());
        assert_eq!(
            sender.body("checkout", Some("/apps/a/feature"), &data, "t", now),
            Some(
                json!({"kind": "checkout", "subject": "/apps/a/feature", "observed_at": "t", "data": data})
            )
        );
        sender.retain("checkout", |subject| subject != "/apps/a/default");
        assert!(sender
            .body("checkout", Some("/apps/a/default"), &data, "t", now)
            .is_some());
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
