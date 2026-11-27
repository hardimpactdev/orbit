//! `dotenv.ensure`: keys of one dotenv file. Lifted from `Site::url_line` of the route family, for any key.

use std::fs as stdfs;
use std::os::unix::fs::MetadataExt as _;
use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::fs;
use crate::{absolute, invalid, parse, Failure, Ids, System, NAME};

static KEY: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z_][A-Za-z0-9_]{0,127}$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    path: String,
    /// The user the file must belong to: a link to another user's file is refused.
    user: String,
    /// Each key's desired value, in order.
    values: Vec<Pair>,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Pair {
    key: String,
    value: String,
}

/// Sets each key in an existing file: the last `KEY=` line is replaced, or the line is appended. The file keeps its owner
/// and mode, and a missing file fails. Answers `1` when the file changed and `0` on a repeat.
///
/// # Errors
///
/// `host.input_invalid` for an input outside the admitted shape, and `dotenv.ensure_failed` for a missing file, a file of
/// another user, or a failed write.
pub fn dotenv_ensure(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = parse(input)?;
    if !absolute(&input.path)
        || !NAME.is_match(&input.user)
        || input.values.is_empty()
        || !input.values.iter().all(|pair| KEY.is_match(&pair.key) && !pair.value.contains(['\n', '\r', '\0']))
    {
        return Err(invalid("dotenv"));
    }
    let failed = |detail: String| Failure::new("dotenv.ensure_failed", detail);
    let real = stdfs::canonicalize(system.path(&input.path)).map_err(|error| failed(error.to_string()))?;
    let uid = system.user(&input.user).map_err(failed)?;
    let meta = stdfs::metadata(&real).map_err(|error| failed(error.to_string()))?;
    if meta.uid() != uid {
        return Err(failed(format!("{} is not the user's own file", input.path)));
    }
    let text = stdfs::read_to_string(&real).map_err(|error| failed(error.to_string()))?;
    let mut lines: Vec<String> = text.lines().map(str::to_owned).collect();
    let mut changed = false;
    for pair in &input.values {
        let wanted = format!("{}={}", pair.key, pair.value);
        let prefix = format!("{}=", pair.key);
        match lines.iter().rposition(|line| line.starts_with(&prefix)) {
            Some(index) if lines[index] == wanted => {}
            Some(index) => {
                lines[index] = wanted;
                changed = true;
            }
            None => {
                lines.push(wanted);
                changed = true;
            }
        }
    }
    if !changed {
        return Ok(0);
    }
    let mut content = lines.join("\n");
    content.push('\n');
    fs::replace(&real, content.as_bytes(), Ids { uid: meta.uid(), gid: meta.gid(), mode: meta.mode() & 0o7777 }).map_err(|error| failed(error.to_string()))?;
    Ok(1)
}
