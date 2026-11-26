//! `instance.work_read`: the read-only Git state of a copy's workspace beyond its recorded baseline
//! (docs/reference/development-containers.md#workspace-work-state).
//!
//! It is the fixed reader of the Gateway's `workspace-git-read.py` `read` mode, built here as argv: every Git call runs inside
//! the container as `agent`, with no hooks, no fsmonitor, no optional locks and no write; only validated hex object ids join
//! the arguments. A copy that does not run is never woken: the Node's asleep marker tells `asleep` from `stopped`. The answer
//! is the reader's JSON, which the Gateway shapes exactly as it shapes the SSH read.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use serde::Deserialize;
use serde_json::{json, Value};

use crate::{BlobRef, Failure, System, INCUS};

const NAMES: usize = 5;
const NAME_LENGTH: usize = 200;
const WORKSPACE: &str = "/srv/workspace";
const HELD: usize = 5000;
/// Orbit writes these two files at the workspace root; an untracked copy there is not work.
const ORBIT_FILES: [&str; 2] = [".env", ".env.testing"];
/// Where the power family marks an Instance asleep (`app-instance-<id>.asleep`), as Caddy reads it.
const MARKERS: &str = "/data/caddy/orbit/hibernation";
const PROJECTS: [&str; 2] = ["orbit-instances", "orbit-instances-nested"];
const GIT: [&str; 12] = [
    "/usr/bin/git",
    "-c",
    "safe.directory=/srv/workspace",
    "-c",
    "core.fsmonitor=false",
    "-c",
    "core.hooksPath=/dev/null",
    "-c",
    "core.untrackedCache=false",
    "--no-optional-locks",
    "-C",
    WORKSPACE,
];

/// What `instance.work_read` reads.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct WorkInput {
    project: String,
    name: String,
    /// The Project default's container on the same Node: the fall-back baseline of a copy that recorded none.
    #[serde(default)]
    default: Option<Container>,
    /// The read's budget on the Node, 1 ms to 10 s.
    budget_ms: u64,
    /// `{"baseline": …, "published": […]}` as a blob: a baseline may name up to 2,000 untracked files.
    #[serde(default)]
    state: Option<BlobRef>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Container {
    project: String,
    name: String,
}

#[derive(Debug, Default, Deserialize)]
struct State {
    #[serde(default)]
    baseline: Option<Baseline>,
    #[serde(default)]
    published: Vec<Value>,
}

#[derive(Debug, Default, Clone, Deserialize)]
struct Baseline {
    #[serde(default)]
    heads: Vec<Value>,
    #[serde(default)]
    stashes: Vec<Value>,
    /// Null when the create found more than 2,000 untracked files: then none is known.
    #[serde(default)]
    untracked: Option<Vec<Value>>,
}

/// Why the read gives `unknown`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Unknown {
    Stopped,
    Asleep,
    Timeout,
    NotARepository,
    Unreachable,
}

impl Unknown {
    fn reason(self) -> &'static str {
        match self {
            Self::Stopped => "stopped",
            Self::Asleep => "asleep",
            Self::Timeout => "timeout",
            Self::NotARepository => "not_a_repository",
            Self::Unreachable => "unreachable",
        }
    }
}

/// Time the reply needs to reach the Gateway after the read ends.
const REPLY_MS: u64 = 500;

/// Reads the copy `target`'s workspace. Answers the reader's JSON: `clean` or `dirty` with its counts, names and head, or
/// `unknown` with its reason. It changes nothing, so a repeat is a fresh read. The read ends by `budget_ms` from now and by
/// the order's expiry less the reply's time (`expires_ms`, Unix milliseconds), whichever comes first: the Gateway accepts no
/// later reply.
///
/// # Errors
///
/// `instance.work_read_input_invalid` for an input that is not a copy's container, or a state blob the executor does not hold.
pub fn work_read(
    system: &impl System,
    target: &str,
    input: &Value,
    expires_ms: u64,
) -> Result<String, Failure> {
    let invalid = |detail: String| Failure::new("instance.work_read_input_invalid", detail);
    let input: WorkInput =
        serde_json::from_value(input.clone()).map_err(|error| invalid(error.to_string()))?;
    let copy = container(&input.project, &input.name)
        .ok_or_else(|| invalid("the copy is not an Instance container".to_owned()))?;
    if input.name != format!("instance-{target}") {
        return Err(invalid("the copy is not the order's target".to_owned()));
    }
    let default = match &input.default {
        Some(found) => Some(
            container(&found.project, &found.name)
                .ok_or_else(|| invalid("the default is not an Instance container".to_owned()))?,
        ),
        None => None,
    };
    if !(1..=10_000).contains(&input.budget_ms) {
        return Err(invalid("budget_ms is not 1 to 10000".to_owned()));
    }
    let state: State = match &input.state {
        Some(reference) => {
            let bytes = system.blob(&reference.blob.sha256).map_err(invalid)?;
            serde_json::from_slice(&bytes).map_err(|error| invalid(format!("state: {error}")))?
        }
        None => State::default(),
    };
    let now_ms = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |since| {
            u64::try_from(since.as_millis()).unwrap_or(u64::MAX)
        });
    let left = input
        .budget_ms
        .min(expires_ms.saturating_sub(now_ms).saturating_sub(REPLY_MS));
    let reader = Reader {
        system,
        deadline: Instant::now() + Duration::from_millis(left),
        owned: RefCell::new(BTreeMap::new()),
    };
    let marker = format!("{MARKERS}/app-instance-{target}.asleep");
    let answer = reader
        .read(&copy, &marker, default.as_ref(), &state)
        .unwrap_or_else(|unknown| json!({"state": "unknown", "reason": unknown.reason()}));
    Ok(answer.to_string())
}

/// A container of an Instance project, `instance-<id>`.
fn container(project: &str, name: &str) -> Option<(String, String)> {
    let id = name.strip_prefix("instance-")?;
    let numbered = !id.is_empty()
        && !id.starts_with('0')
        && id.len() <= 19
        && id.bytes().all(|byte| byte.is_ascii_digit());
    (PROJECTS.contains(&project) && numbered).then(|| (project.to_owned(), name.to_owned()))
}

fn oid(value: &str) -> bool {
    matches!(value.len(), 40 | 64)
        && value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

fn oids(values: &[Value]) -> Vec<String> {
    values
        .iter()
        .filter_map(Value::as_str)
        .filter(|value| oid(value))
        .map(str::to_owned)
        .collect()
}

/// A name as the answer carries it: at most 200 characters, the last one an ellipsis when it was cut.
fn text(raw: &str) -> String {
    if raw.chars().count() <= NAME_LENGTH {
        raw.to_owned()
    } else {
        let mut cut: String = raw.chars().take(NAME_LENGTH - 1).collect();
        cut.push('…');
        cut
    }
}

/// Python's `bytes.strip()`: ASCII whitespace only, so a branch named by a non-ASCII space keeps its name.
fn strip(raw: &str) -> &str {
    raw.trim_matches(ascii_space)
}

/// Python's `bytes.split()`: fields between runs of ASCII whitespace.
fn fields(raw: &str) -> impl Iterator<Item = &str> {
    raw.split(ascii_space).filter(|field| !field.is_empty())
}

fn ascii_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0b' | '\x0c')
}

fn listed(values: &[String]) -> Value {
    json!({"count": values.len(), "names": values.iter().take(NAMES).collect::<Vec<_>>()})
}

type Target = (String, String);

struct Reader<'a, S: System> {
    system: &'a S,
    deadline: Instant,
    /// Each object id's own count within this read, as the reader's `own` keeps it.
    owned: RefCell<BTreeMap<String, usize>>,
}

impl<S: System> Reader<'_, S> {
    /// What is left of the budget; a budget that ran out is `timeout`.
    fn left(&self) -> Result<Duration, Unknown> {
        let left = self.deadline.saturating_duration_since(Instant::now());
        if left.is_zero() {
            return Err(Unknown::Timeout);
        }
        Ok(left)
    }

    /// Whole seconds left for the reader's own bound inside the container, at least one, as the SSH reader gives it.
    fn seconds(&self) -> Result<u32, Unknown> {
        Ok(u32::try_from(self.left()?.as_millis().div_ceil(1000))
            .unwrap_or(u32::MAX)
            .max(1))
    }

    /// Runs one command within the budget: the Node stops it at the deadline to the millisecond, and a command that ends
    /// after it is `timeout`.
    fn run(&self, argv: &[String]) -> Result<Result<String, String>, Unknown> {
        let left = self.left()?;
        let result = self.system.run_for(left, argv, None);
        if Instant::now() >= self.deadline {
            return Err(Unknown::Timeout);
        }
        Ok(result)
    }

    fn git(&self, target: &Target, arguments: &[&str]) -> Result<Result<String, String>, Unknown> {
        let seconds = self.seconds()?.to_string();
        let mut argv: Vec<String> = [
            INCUS,
            "exec",
            "--project",
            &target.0,
            "--mode=non-interactive",
            &target.1,
            "--",
            "/usr/bin/timeout",
            &seconds,
            "/usr/sbin/runuser",
            "-u",
            "agent",
            "--",
            "/usr/bin/env",
            "-i",
            "HOME=/home/agent",
            "GIT_CONFIG_NOSYSTEM=1",
            "GIT_TERMINAL_PROMPT=0",
            "GIT_OPTIONAL_LOCKS=0",
        ]
        .iter()
        .map(|part| (*part).to_owned())
        .collect();
        argv.extend(GIT.iter().map(|part| (*part).to_owned()));
        argv.extend(arguments.iter().map(|part| (*part).to_owned()));
        self.run(&argv)
    }

    fn running(&self, target: &Target) -> Result<bool, Unknown> {
        let url = format!("/1.0/instances/{}/state?project={}", target.1, target.0);
        let state = self
            .run(&[INCUS.to_owned(), "query".to_owned(), url])?
            .map_err(|_| Unknown::Unreachable)?;
        let state: Value = serde_json::from_str(&state).map_err(|_| Unknown::Unreachable)?;
        Ok(state.get("status").and_then(Value::as_str) == Some("Running"))
    }

    /// Local branch heads (name, oid) and the stash entries (oid, label).
    #[expect(
        clippy::type_complexity,
        reason = "two plain lists of pairs, as the reader answers them"
    )]
    fn refs(
        &self,
        target: &Target,
    ) -> Result<(Vec<(String, String)>, Vec<(String, String)>), Unknown> {
        let listed = self
            .git(
                target,
                &[
                    "for-each-ref",
                    "--format=%(objectname) %(refname)",
                    "refs/heads",
                    "refs/stash",
                ],
            )?
            .map_err(|_| Unknown::NotARepository)?;
        let (mut heads, mut stash) = (Vec::new(), false);
        for line in listed.split('\n') {
            let (object, reference) = line.split_once(' ').unwrap_or((line, ""));
            if reference == "refs/stash" {
                stash = true;
            } else if let Some(name) = reference.strip_prefix("refs/heads/") {
                if oid(object) {
                    heads.push((text(name), object.to_owned()));
                }
            }
        }
        let mut stashes = Vec::new();
        if stash {
            let log = self
                .git(
                    target,
                    &["log", "-g", "-z", "--format=%H %gd: %gs", "refs/stash"],
                )?
                .map_err(|_| Unknown::NotARepository)?;
            for entry in log.split('\0').filter(|entry| !entry.is_empty()) {
                let (object, label) = entry.split_once(' ').unwrap_or((entry, ""));
                if oid(object) {
                    stashes.push((object.to_owned(), text(label)));
                }
            }
        }
        Ok((heads, stashes))
    }

    /// Tracked changes and untracked files that are not ignored, file by file, with the SSH reader's exact `status`: its
    /// configuration, attributes and submodule handling decide, so both readers give one answer.
    fn status(&self, target: &Target) -> Result<(Vec<String>, Vec<String>), Unknown> {
        let read = self
            .git(
                target,
                &[
                    "status",
                    "--porcelain=v1",
                    "-z",
                    "--untracked-files=all",
                    "--no-renames",
                ],
            )?
            .map_err(|_| Unknown::NotARepository)?;
        let (mut modified, mut untracked) = (Vec::new(), Vec::new());
        for entry in read.split('\0').filter(|entry| !entry.is_empty()) {
            let path = entry.get(3..).unwrap_or_default().to_owned();
            if entry.get(..2) != Some("??") {
                modified.push(path);
            } else if !ORBIT_FILES.contains(&path.as_str()) {
                untracked.push(path);
            }
        }
        Ok((modified, untracked))
    }

    fn head(&self, target: &Target) -> Result<Option<String>, Unknown> {
        Ok(
            match self.git(target, &["symbolic-ref", "-q", "--short", "HEAD"])? {
                Ok(out) if !strip(&out).is_empty() => Some(text(out.trim_end_matches('\n'))),
                _ => None,
            },
        )
    }

    /// Commits reachable from `positive` and not from any remote ref or `negative`; all are hex object ids.
    fn unreachable(
        &self,
        target: &Target,
        positive: &[String],
        negative: &[String],
    ) -> Result<BTreeSet<String>, Unknown> {
        let mut arguments = vec!["rev-list", "--ignore-missing"];
        arguments.extend(positive.iter().map(String::as_str));
        arguments.extend(["--not", "--remotes"]);
        arguments.extend(negative.iter().map(String::as_str));
        let read = self
            .git(target, &arguments)?
            .map_err(|_| Unknown::NotARepository)?;
        Ok(fields(&read).map(str::to_owned).collect())
    }

    /// The commits only `value` reaches, less the inherited ones: counted once per object id within this read.
    fn own(
        &self,
        target: &Target,
        value: &str,
        negative: &[String],
        inherited: &BTreeSet<String>,
    ) -> Result<usize, Unknown> {
        if let Some(count) = self.owned.borrow().get(value) {
            return Ok(*count);
        }
        let count = self
            .unreachable(target, &[value.to_owned()], negative)?
            .difference(inherited)
            .count();
        self.owned.borrow_mut().insert(value.to_owned(), count);
        Ok(count)
    }

    /// The commits the default holds and reaches from its branches now: a default whose branch moved on since the copy still
    /// owns the commits a clone inherited. Above 5,000 commits the answer stays plain.
    fn held(
        &self,
        default: &Target,
        commits: &[String],
        heads: &[String],
    ) -> Result<BTreeSet<String>, Unknown> {
        if heads.is_empty() || commits.len() > HELD {
            return Ok(BTreeSet::new());
        }
        let mut arguments = vec!["rev-list", "--no-walk", "--ignore-missing"];
        arguments.extend(commits.iter().map(String::as_str));
        let Ok(present) = self.git(default, &arguments)? else {
            return Ok(BTreeSet::new());
        };
        let found: BTreeSet<String> = fields(&present)
            .filter(|value| oid(value))
            .map(str::to_owned)
            .collect();
        if found.is_empty() {
            return Ok(BTreeSet::new());
        }
        let mut arguments = vec!["rev-list", "--ignore-missing"];
        arguments.extend(found.iter().map(String::as_str));
        arguments.push("--not");
        arguments.extend(heads.iter().map(String::as_str));
        let Ok(beyond) = self.git(default, &arguments)? else {
            return Ok(BTreeSet::new());
        };
        let beyond: BTreeSet<&str> = fields(&beyond).collect();
        Ok(found
            .into_iter()
            .filter(|value| !beyond.contains(value.as_str()))
            .collect())
    }

    /// The default's branch heads and stashes now, as the baseline of a copy that recorded none; none when the default does
    /// not run or cannot be read, unless the budget ran out.
    fn default_baseline(
        &self,
        default: &Target,
    ) -> Result<Option<(Vec<String>, Baseline)>, Unknown> {
        let found = self.running(default).and_then(|running| {
            if running {
                self.refs(default).map(Some)
            } else {
                Ok(None)
            }
        });
        match found {
            Ok(Some((heads, stashes))) => {
                let heads: Vec<String> = heads
                    .iter()
                    .map(|(_, value)| value.clone())
                    .collect::<BTreeSet<_>>()
                    .into_iter()
                    .collect();
                let baseline = Baseline {
                    heads: heads
                        .iter()
                        .map(|value| Value::from(value.as_str()))
                        .collect(),
                    stashes: stashes
                        .iter()
                        .map(|(value, _)| Value::from(value.as_str()))
                        .collect(),
                    untracked: None,
                };
                Ok(Some((heads, baseline)))
            }
            Err(Unknown::Timeout) => Err(Unknown::Timeout),
            Ok(None) | Err(_) => Ok(None),
        }
    }

    #[expect(
        clippy::too_many_lines,
        reason = "the reader's one read, step by step as workspace-git-read.py has it"
    )]
    fn read(
        &self,
        target: &Target,
        marker: &str,
        default: Option<&Target>,
        state: &State,
    ) -> Result<Value, Unknown> {
        if !self.running(target)? {
            return Err(if self.system.path(marker).exists() {
                Unknown::Asleep
            } else {
                Unknown::Stopped
            });
        }
        let inside = self
            .git(target, &["rev-parse", "--is-inside-work-tree"])?
            .unwrap_or_default();
        if strip(&inside) != "true" {
            return Err(Unknown::NotARepository);
        }
        let (mut baseline, mut kind) = (
            state.baseline.clone(),
            if state.baseline.is_some() {
                "recorded"
            } else {
                "none"
            },
        );
        let mut default_heads: Vec<String> = Vec::new();
        if let (None, Some(default)) = (&baseline, default) {
            if let Some((heads, found)) = self.default_baseline(default)? {
                (default_heads, baseline, kind) = (heads, Some(found), "default_now");
            }
        }
        let baseline = baseline.unwrap_or_default();
        let (heads, stashes) = self.refs(target)?;
        let (modified, mut untracked) = self.status(target)?;
        if let Some(known) = &baseline.untracked {
            let known: BTreeSet<&str> = known.iter().filter_map(Value::as_str).collect();
            untracked.retain(|entry| !known.contains(entry.as_str()));
        }
        let seen: BTreeSet<String> = oids(&baseline.stashes).into_iter().collect();
        let stashes: Vec<String> = stashes
            .into_iter()
            .filter(|(value, _)| !seen.contains(value))
            .map(|(_, label)| label)
            .collect();
        // A commit that a publish pushed is on origin, and so is its history: a copy never fetches, so no remote ref names it.
        let negative: Vec<String> = oids(&baseline.heads)
            .into_iter()
            .chain(oids(&state.published))
            .collect::<BTreeSet<_>>()
            .into_iter()
            .collect();
        let tips: Vec<String> = heads
            .iter()
            .map(|(_, value)| value.clone())
            .collect::<BTreeSet<_>>()
            .into_iter()
            .collect();
        let mut lost = if tips.is_empty() {
            BTreeSet::new()
        } else {
            self.unreachable(target, &tips, &negative)?
        };
        let inherited = match default {
            Some(default) if kind == "default_now" && !lost.is_empty() => self.held(
                default,
                &lost.iter().cloned().collect::<Vec<_>>(),
                &default_heads,
            )?,
            _ => BTreeSet::new(),
        };
        lost.retain(|value| !inherited.contains(value));
        let mut branches: Vec<(String, String)> = heads
            .iter()
            .filter(|(_, value)| lost.contains(value))
            .cloned()
            .collect();
        branches.sort();
        let own = |value: &String| self.own(target, value, &negative, &inherited);
        let mut counted = Vec::new();
        for (name, value) in branches.iter().take(NAMES) {
            counted.push(json!({"name": name, "count": own(value)?}));
        }
        let modified: Vec<String> = modified.iter().map(|entry| text(entry)).collect();
        let untracked: Vec<String> = untracked.iter().map(|entry| text(entry)).collect();
        let dirty = !modified.is_empty()
            || !untracked.is_empty()
            || !lost.is_empty()
            || !stashes.is_empty();
        let branch = self.head(target)?;
        // The commit of the checked-out branch, from the heads already read; none on a detached HEAD.
        let current = branch
            .as_ref()
            .and_then(|branch| heads.iter().find(|(name, _)| name == branch))
            .map(|(_, value)| value.clone());
        // Of the counted commits, those the checked-out branch holds: only they can be published by content.
        let mine = match &current {
            Some(value) if lost.contains(value) => own(value)?,
            _ => 0,
        };
        Ok(json!({
            "state": if dirty { "dirty" } else { "clean" },
            "reason": null,
            "baseline": kind,
            "head": branch,
            "head_commit": current,
            "modified": listed(&modified),
            "untracked": listed(&untracked),
            "commits": {"count": lost.len(), "branch_count": branches.len(), "branches": counted, "head": mine},
            "stashes": listed(&stashes),
        }))
    }
}

#[cfg(test)]
mod tests;
