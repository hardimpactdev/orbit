//! SPIKE: a clone's `.env` as a desired-state primitive on one dotenv file. A port of merge_environment and
//! rewrite_default_hosts in instance_clone_guest.py: the default's file (already in the copy) keeps every key except the
//! clone's own (taken from the Gateway's render), the dropped ones and any key whose value is one of the default's
//! connection credentials. Only key names leave, never a value.

use std::collections::{BTreeSet, HashMap, HashSet};
use std::sync::LazyLock;

use regex::Regex;

static ENV_LINE: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^\s*(?:export\s+)?([A-Za-z0-9_.]+)\s*=(.*)$").expect("regex"));
static COMMENT: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"\s+#").expect("regex"));
const CONTAINED_CREDENTIAL: usize = 8;

type Block = (Option<String>, String, Option<String>);

/// (key, raw text, value) blocks; a quoted value may span lines.
pub(crate) fn blocks(text: &str) -> Vec<Block> {
    let lines: Vec<&str> = text.split_inclusive('\n').collect();
    let mut out = Vec::new();
    let mut i = 0;
    while i < lines.len() {
        let line = lines[i].trim_end_matches(['\r', '\n']);
        let Some(found) = ENV_LINE.captures(line) else {
            out.push((None, lines[i].to_owned(), None));
            i += 1;
            continue;
        };
        let mut raw = lines[i].to_owned();
        let rest = found[2].trim_start().to_owned();
        let quote = rest.chars().next().filter(|c| *c == '"' || *c == '\'');
        let mut value = COMMENT.splitn(&rest, 2).next().unwrap_or("").trim().to_owned();
        if let Some(q) = quote {
            let mut body = rest[1..].to_owned();
            let mut end = i;
            let mut closing = closing_quote(&body, q);
            while closing.is_none() && end + 1 < lines.len() {
                end += 1;
                body.push('\n');
                body.push_str(lines[end].trim_end_matches(['\r', '\n']));
                closing = closing_quote(&body, q);
            }
            if let Some(at) = closing {
                raw = lines[i..=end].concat();
                i = end;
                value = body[..at].to_owned();
                if q == '"' {
                    value = value.replace("\\\"", "\"").replace("\\\\", "\\");
                }
            }
        }
        out.push((Some(found[1].to_owned()), raw, Some(value)));
        i += 1;
    }
    out
}

fn closing_quote(body: &str, quote: char) -> Option<usize> {
    let mut escaped = false;
    for (index, c) in body.char_indices() {
        if quote == '"' && !escaped && c == '\\' {
            escaped = true;
            continue;
        }
        if c == quote && !escaped {
            return Some(index);
        }
        escaped = false;
    }
    None
}

pub(crate) struct Merged {
    pub text: String,
    pub copied: Vec<String>,
    pub dropped: Vec<String>,
}

pub(crate) fn merge(base: &str, rendered: &str, own: &[String], drop: &[String], guard: &[String]) -> Merged {
    if base.trim().is_empty() {
        return Merged { text: rendered.to_owned(), copied: Vec::new(), dropped: Vec::new() };
    }
    let own: HashSet<&str> = own.iter().map(String::as_str).collect();
    let drop: HashSet<&str> = drop.iter().map(String::as_str).filter(|k| !own.contains(k)).collect();
    let guard: HashSet<&str> = guard.iter().map(String::as_str).collect();
    let base_blocks = blocks(base);
    let rendered_blocks: Vec<Block> = blocks(rendered).into_iter().filter(|b| b.0.is_some()).collect();
    let credentials: HashSet<String> = base_blocks
        .iter()
        .filter(|(k, _, v)| k.as_deref().is_some_and(|k| guard.contains(k)) && v.as_deref().is_some_and(|v| !v.is_empty()))
        .filter_map(|(_, _, v)| v.clone())
        .collect();
    let contains = |text: &str| credentials.iter().any(|secret| secret.len() >= CONTAINED_CREDENTIAL && text.contains(secret.as_str()));
    let owned: HashMap<&str, &str> =
        rendered_blocks.iter().filter_map(|(k, raw, _)| k.as_deref().filter(|k| own.contains(k)).map(|k| (k, raw.as_str()))).collect();
    let mut out: Vec<String> = Vec::new();
    let mut kept: HashSet<String> = HashSet::new();
    let (mut copied, mut dropped) = (BTreeSet::new(), BTreeSet::new());
    let line = |raw: &str| if raw.ends_with('\n') { raw.to_owned() } else { format!("{raw}\n") };
    for (key, raw, value) in &base_blocks {
        match key {
            None => {
                if !contains(raw) {
                    out.push(raw.clone());
                }
            }
            Some(key) if own.contains(key.as_str()) => {
                if let Some(owned) = owned.get(key.as_str()).filter(|_| !kept.contains(key)) {
                    out.push(line(owned));
                    kept.insert(key.clone());
                }
            }
            Some(key) => {
                let value = value.as_deref().unwrap_or("");
                if drop.contains(key.as_str()) || credentials.contains(value) || contains(value) {
                    dropped.insert(key.clone());
                } else {
                    out.push(raw.clone());
                    kept.insert(key.clone());
                    copied.insert(key.clone());
                }
            }
        }
    }
    if let Some(last) = out.last_mut().filter(|last| !last.ends_with('\n')) {
        last.push('\n');
    }
    for (key, raw, _) in &rendered_blocks {
        let key = key.clone().unwrap_or_default();
        if !kept.contains(&key) {
            out.push(line(raw));
            kept.insert(key);
        }
    }
    let dropped: Vec<String> = dropped.difference(&copied).cloned().collect();
    Merged { text: out.concat(), copied: copied.into_iter().collect(), dropped }
}

/// In the values of the named keys, an item that is the default's host becomes the clone's host.
pub(crate) fn rewrite_hosts(text: &str, keys: &HashSet<String>, from: &[String], to: &str) -> (String, Vec<String>) {
    let mut sources: Vec<String> =
        from.iter().filter(|h| !h.is_empty()).map(|h| h.to_lowercase()).collect::<BTreeSet<_>>().into_iter().collect();
    sources.sort_by_key(|h| std::cmp::Reverse(h.len()));
    if to.is_empty() || sources.is_empty() {
        return (text.to_owned(), Vec::new());
    }
    let alternatives = sources.iter().map(|s| regex::escape(s)).collect::<Vec<_>>().join("|");
    let Ok(item) = Regex::new(&format!(r"(?is)^((?:[A-Za-z][A-Za-z0-9+.-]*:)?//|\*?\.)?({alternatives})((?::[0-9]+)?(?:[/?#].*)?)$")) else {
        return (text.to_owned(), Vec::new());
    };
    let part = Regex::new(r"[^\s,;]+").expect("regex");
    let rewrite = |value: &str| -> String {
        let (mut out, mut last) = (String::new(), 0);
        for found in part.find_iter(value) {
            if value[..found.start()].contains('?') || value[..found.start()].contains('#') {
                break;
            }
            if let Some(caps) = item.captures(found.as_str()) {
                if caps[2].to_lowercase() != to.to_lowercase() {
                    out.push_str(&value[last..found.start()]);
                    out.push_str(caps.get(1).map_or("", |m| m.as_str()));
                    out.push_str(to);
                    out.push_str(caps.get(3).map_or("", |m| m.as_str()));
                    last = found.end();
                }
            }
        }
        out + &value[last..]
    };
    let (mut out, mut rewritten) = (String::new(), BTreeSet::new());
    for (key, raw, _) in blocks(text) {
        let mut raw = raw;
        if let Some(key) = key.filter(|k| keys.contains(k)) {
            let (start, end) = value_span(&raw);
            let changed = format!("{}{}{}", &raw[..start], rewrite(&raw[start..end]), &raw[end..]);
            if changed != raw {
                rewritten.insert(key);
                raw = changed;
            }
        }
        out.push_str(&raw);
    }
    (out, rewritten.into_iter().collect())
}

fn value_span(raw: &str) -> (usize, usize) {
    let first = raw.lines().next().unwrap_or("");
    let Some(found) = ENV_LINE.captures(first) else { return (0, 0) };
    let Some(group) = found.get(2) else { return (0, 0) };
    let start = group.start() + group.as_str().len() - group.as_str().trim_start().len();
    if let Some(q) = raw[start..].chars().next().filter(|c| *c == '"' || *c == '\'') {
        if let Some(closing) = closing_quote(&raw[start + 1..], q) {
            return (start + 1, start + 1 + closing);
        }
    }
    let bare = COMMENT.splitn(&first[start..], 2).next().unwrap_or("").trim();
    (start, start + bare.len())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn keeps_defaults_drops_credentials_and_takes_own_keys() {
        let base = "APP_NAME=Laravel\nAPP_KEY=base64:old\nDB_PASSWORD=supersecret1\n# pw supersecret1\nOTHER=x-supersecret1\nAPP_URL=https://default.test\n";
        let render = "APP_KEY=base64:new\nAPP_URL=https://clone.test\nNEW=1\n";
        let own = vec!["APP_KEY".to_owned(), "APP_URL".to_owned()];
        let merged = merge(base, render, &own, &[], &["DB_PASSWORD".to_owned()]);
        assert_eq!(merged.text, "APP_NAME=Laravel\nAPP_KEY=base64:new\nAPP_URL=https://clone.test\nNEW=1\n");
        assert_eq!(merged.copied, vec!["APP_NAME".to_owned()]);
        assert_eq!(merged.dropped, vec!["DB_PASSWORD".to_owned(), "OTHER".to_owned()]);
    }

    #[test]
    fn rewrites_the_default_host_in_named_keys() {
        let keys: HashSet<String> = ["SANCTUM".to_owned()].into();
        let (text, names) =
            rewrite_hosts("SANCTUM=default.test,localhost:3000\nX=default.test\n", &keys, &["default.test".to_owned()], "clone.test");
        assert_eq!(text, "SANCTUM=clone.test,localhost:3000\nX=default.test\n");
        assert_eq!(names, vec!["SANCTUM".to_owned()]);
    }
}
