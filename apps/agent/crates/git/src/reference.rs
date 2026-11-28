//! `git.ref.ensure`: one ref, compared with an expected old value and then set, or deleted.

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::{
    commit, full_ref, invalid, parse, path, require_repository, resolve, Failure, NodeSystem,
    LOCAL_SECONDS,
};

const CODE: &str = "git.ref_failed";

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields, rename_all = "snake_case")]
enum State {
    Present,
    Absent,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct RefInput {
    repository: String,
    #[serde(rename = "ref")]
    reference: String,
    state: State,
    /// The object the ref must name; only with `present`.
    #[serde(default)]
    commit: Option<String>,
    /// The object the ref must name before the change, `null` for a ref that must not exist yet. Required.
    expect: Option<String>,
}

/// What a ref ensure answers: the object the ref names afterwards (`None` when absent) and the changes made.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct RefEnsured {
    /// The object the ref names after the order.
    pub commit: Option<String>,
    /// 1 when the ref moved, was made or was deleted; 0 when it already held the desired state.
    pub changes: u32,
}

/// Brings one ref to its desired state. A ref that already holds it answers 0 changes. Otherwise the ref must hold `expect`,
/// and Git's own compare-and-swap (`update-ref <ref> <new> <old>`, or `-d <ref> <old>`) makes the change, so a ref another
/// writer moved in between refuses too.
///
/// # Errors
///
/// `git.input_invalid`, `git.ref_conflict` when the ref holds neither the desired nor the expected value, or `git.ref_failed`.
pub fn ref_ensure(system: &NodeSystem, input: &Value) -> Result<RefEnsured, Failure> {
    if input.get("expect").is_none() {
        return Err(invalid(
            "expect is required: an object id, or null for a ref that must not exist",
        ));
    }
    let input: RefInput = parse(input)?;
    let repository = path(&input.repository, "repository")?;
    let reference = full_ref(&input.reference)?;
    let expect = input.expect.as_deref().map(commit).transpose()?;
    let desired = match (&input.state, input.commit.as_deref()) {
        (State::Present, Some(value)) => Some(commit(value)?),
        (State::Present, None) => return Err(invalid("present needs a commit")),
        (State::Absent, None) => None,
        (State::Absent, Some(_)) => return Err(invalid("absent takes no commit")),
    };
    require_repository(system, CODE, &repository, crate::Kind::Repository)?;
    let current = resolve(system, CODE, &repository, reference)?;
    if current.as_deref() == desired {
        return Ok(RefEnsured {
            commit: current,
            changes: 0,
        });
    }
    if current.as_deref() != expect {
        return Err(Failure::new(
            "git.ref_conflict",
            format!("{reference} holds {current:?}, not the expected {expect:?}"),
        ));
    }
    let zero = "0".repeat(desired.or(expect).map_or(40, str::len));
    let old = expect.unwrap_or(&zero);
    let ran = match desired {
        Some(new) => system.git(
            LOCAL_SECONDS,
            &repository,
            &[],
            &[
                "update-ref",
                "--no-deref",
                "--end-of-options",
                reference,
                new,
                old,
            ],
        )?,
        None => system.git(
            LOCAL_SECONDS,
            &repository,
            &[],
            &[
                "update-ref",
                "--no-deref",
                "-d",
                "--end-of-options",
                reference,
                old,
            ],
        )?,
    };
    if ran.code != Some(0) {
        // Git compares the old value under the ref's lock: a mismatch there is a conflict, anything else a failure.
        let code = if ran.stderr.contains("but expected")
            || ran.stderr.contains("unable to resolve")
            || ran.stderr.contains("already exists")
        {
            "git.ref_conflict"
        } else {
            CODE
        };
        return Err(Failure::new(
            code,
            format!("update-ref: {}", ran.stderr.trim()),
        ));
    }
    let after = resolve(system, CODE, &repository, reference)?;
    if after.as_deref() != desired {
        return Err(Failure::new(CODE, "the ref does not read back as asked"));
    }
    Ok(RefEnsured {
        commit: after,
        changes: 1,
    })
}
