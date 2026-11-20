//! A clone's `.env`: the default's file from the seed, merged with the Gateway's render (instance_clone_guest.py).

use std::collections::{BTreeSet, HashMap};

use serde::Deserialize;

/// A credential at least this long is also removed where it appears inside a longer value.
const CONTAINED_CREDENTIAL: usize = 8;

/// The merge names: the keys the clone owns, the keys it drops, and the default's connection keys whose values are
/// credentials that must not reach the clone.
#[derive(Debug, Default, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct Merge {
    own: Vec<String>,
    drop: Vec<String>,
    guard: Vec<String>,
    #[serde(default)]
    hosts: Option<Hosts>,
    #[serde(default)]
    shared: Option<Vec<String>>,
}

/// The default's own hosts, and the clone's host that replaces them in the copied values.
#[derive(Debug, Default, Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct Hosts {
    #[serde(default)]
    from: Vec<String>,
    #[serde(default)]
    to: Option<String>,
}

/// The key names a merge reports; never a value.
#[derive(Debug, Default, PartialEq, Eq, serde::Serialize)]
pub(crate) struct Keys {
    copied: Vec<String>,
    dropped: Vec<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    rewritten: Option<Vec<String>>,
    #[serde(skip_serializing_if = "Option::is_none")]
    shared: Option<Vec<String>>,
}

/// One block of a `.env`: a key with its raw text and value, or a line that is no assignment.
#[derive(Debug, Clone)]
struct Block {
    key: Option<String>,
    raw: String,
    value: Option<String>,
}

/// The key and the text after `=` of an assignment line: `[export ]KEY=…`, with optional spaces.
fn assignment(line: &str) -> Option<(String, String)> {
    let trimmed = line.trim_start();
    let rest = trimmed.strip_prefix("export").filter(|rest| rest.starts_with(char::is_whitespace)).map_or(trimmed, str::trim_start);
    let end = rest.find(|c: char| !(c.is_ascii_alphanumeric() || c == '_' || c == '.')).unwrap_or(rest.len());
    let (key, after) = rest.split_at(end);
    let value = after.trim_start().strip_prefix('=')?;
    (!key.is_empty()).then(|| (key.to_owned(), value.to_owned()))
}

/// The index of the quote that closes a value, or None. In double quotes a backslash escapes the next character.
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

/// The value before a ` #` comment, trimmed.
fn bare(rest: &str) -> String {
    let mut end = rest.len();
    let bytes = rest.as_bytes();
    for (index, byte) in bytes.iter().enumerate() {
        if *byte == b'#' && index > 0 && bytes[index - 1].is_ascii_whitespace() {
            end = index;
            break;
        }
    }
    rest[..end].trim().to_owned()
}

/// Splits a `.env` into blocks; a quoted value may span lines. Raw text stays as written.
fn blocks(text: &str) -> Vec<Block> {
    let lines: Vec<&str> = text.split_inclusive('\n').collect();
    let mut out = Vec::new();
    let mut i = 0;
    while i < lines.len() {
        let Some((key, after)) = assignment(lines[i].trim_end_matches(['\r', '\n'])) else {
            out.push(Block { key: None, raw: lines[i].to_owned(), value: None });
            i += 1;
            continue;
        };
        let mut raw = lines[i].to_owned();
        let rest = after.trim_start();
        let quote = rest.chars().next().filter(|c| *c == '"' || *c == '\'');
        let mut value = bare(rest);
        if let Some(quote) = quote {
            let mut body = rest[1..].to_owned();
            let mut end = i;
            let mut closing = closing_quote(&body, quote);
            while closing.is_none() && end + 1 < lines.len() {
                end += 1;
                body.push('\n');
                body.push_str(lines[end].trim_end_matches(['\r', '\n']));
                closing = closing_quote(&body, quote);
            }
            if let Some(closing) = closing {
                raw = lines[i..=end].concat();
                i = end;
                value = body[..closing].to_owned();
                if quote == '"' {
                    value = unescape(&value);
                }
            }
        }
        out.push(Block { key: Some(key), raw, value: Some(value) });
        i += 1;
    }
    out
}

/// `\"` and `\\` in a double-quoted value.
fn unescape(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    let mut chars = value.chars().peekable();
    while let Some(c) = chars.next() {
        if c == '\\' && matches!(chars.peek(), Some('"' | '\\')) {
            out.extend(chars.next());
        } else {
            out.push(c);
        }
    }
    out
}

fn line(raw: &str) -> String {
    if raw.ends_with('\n') { raw.to_owned() } else { format!("{raw}\n") }
}

/// The clone's `.env`: the base with the keys the clone owns taken from the render, the dropped keys and every key whose
/// value is one of the default's connection credentials removed, and the render's other keys added. Without a base or
/// without merge names the render is the file. Answers the file and the key names.
pub(crate) fn merge(base: &str, rendered: &str, merge: Option<&Merge>) -> (String, Keys) {
    let Some(names) = merge.filter(|_| !base.trim().is_empty()) else {
        return (rendered.to_owned(), Keys::default());
    };
    let own: BTreeSet<&str> = names.own.iter().map(String::as_str).collect();
    let drop: BTreeSet<&str> = names.drop.iter().map(String::as_str).filter(|key| !own.contains(key)).collect();
    let guard: BTreeSet<&str> = names.guard.iter().map(String::as_str).collect();
    let base_blocks = blocks(base);
    let rendered_blocks: Vec<Block> = blocks(rendered).into_iter().filter(|block| block.key.is_some()).collect();
    let credentials: BTreeSet<String> = base_blocks
        .iter()
        .filter(|block| block.key.as_deref().is_some_and(|key| guard.contains(key)))
        .filter_map(|block| block.value.clone().filter(|value| !value.is_empty()))
        .collect();
    let contains = |text: &str| credentials.iter().any(|secret| secret.len() >= CONTAINED_CREDENTIAL && text.contains(secret.as_str()));
    let credential = |value: &str| credentials.contains(value) || contains(value);
    let mut owned: HashMap<&str, &str> = HashMap::new();
    for block in &rendered_blocks {
        if let Some(key) = block.key.as_deref().filter(|key| own.contains(key)) {
            owned.insert(key, &block.raw);
        }
    }
    let (mut out, mut kept, mut copied, mut dropped) = (Vec::new(), BTreeSet::new(), BTreeSet::new(), BTreeSet::new());
    for block in &base_blocks {
        match block.key.as_deref() {
            None => {
                if !contains(&block.raw) {
                    out.push(block.raw.clone());
                }
            }
            Some(key) if own.contains(key) => {
                if let Some(raw) = owned.get(key).filter(|_| !kept.contains(key)) {
                    out.push(line(raw));
                    kept.insert(key.to_owned());
                }
            }
            Some(key) if drop.contains(key) || credential(block.value.as_deref().unwrap_or_default()) => {
                dropped.insert(key.to_owned());
            }
            Some(key) => {
                out.push(block.raw.clone());
                kept.insert(key.to_owned());
                copied.insert(key.to_owned());
            }
        }
    }
    if let Some(last) = out.last_mut().filter(|last| !last.ends_with('\n')) {
        last.push('\n');
    }
    for block in &rendered_blocks {
        let key = block.key.clone().unwrap_or_default();
        if !kept.contains(&key) {
            out.push(line(&block.raw));
            kept.insert(key);
        }
    }
    let mut text = out.concat();
    let mut keys = Keys {
        copied: copied.iter().cloned().collect(),
        dropped: dropped.difference(&copied).cloned().collect(),
        rewritten: None,
        shared: None,
    };
    if let Some(hosts) = &names.hosts {
        let (rewritten, names) = rewrite_hosts(&text, &copied, hosts);
        text = rewritten;
        keys.rewritten = Some(names);
    }
    if let Some(shared) = &names.shared {
        let written: BTreeSet<String> = blocks(&text).into_iter().filter_map(|block| block.key).collect();
        keys.shared = Some(shared.iter().filter(|key| written.contains(*key)).cloned().collect::<BTreeSet<_>>().into_iter().collect());
    }
    (text, keys)
}

/// The start and end of a block's value inside its raw text: inside the quotes when they close, else the bare value.
fn value_span(raw: &str) -> Option<(usize, usize)> {
    let first = raw.split('\n').next().unwrap_or_default().trim_end_matches('\r');
    let (_, after) = assignment(first)?;
    let start = first.len() - after.len() + (after.len() - after.trim_start().len());
    let rest = &raw[start..];
    if let Some(quote) = rest.chars().next().filter(|c| *c == '"' || *c == '\'') {
        if let Some(closing) = closing_quote(&rest[1..], quote) {
            return Some((start + 1, start + 1 + closing));
        }
    }
    Some((start, start + bare(&first[start..]).len()))
}

/// In the values of the named keys, an item that is one of the default's hosts becomes the clone's host. An item is a
/// whole value or one part of a list split at commas, semicolons or whitespace, before any `?` or `#`: the host alone or
/// with a leading `.` or `*.`, a scheme or `//`, a port and a path, query or fragment. Answers the file and the rewritten
/// key names.
fn rewrite_hosts(text: &str, keys: &BTreeSet<String>, hosts: &Hosts) -> (String, Vec<String>) {
    let mut sources: Vec<String> = hosts.from.iter().filter(|host| !host.is_empty()).map(|host| host.to_ascii_lowercase()).collect();
    sources.sort_by_key(|host| std::cmp::Reverse(host.len()));
    sources.dedup();
    let Some(target) = hosts.to.as_deref().filter(|to| !to.is_empty() && !sources.is_empty()) else {
        return (text.to_owned(), Vec::new());
    };
    let mut out = String::new();
    let mut rewritten = BTreeSet::new();
    for block in blocks(text) {
        let mut raw = block.raw.clone();
        if let (Some(key), Some((start, end))) = (block.key.as_deref().filter(|key| keys.contains(*key)), value_span(&block.raw)) {
            let changed = format!("{}{}{}", &raw[..start], rewrite(&raw[start..end], &sources, target), &raw[end..]);
            if changed != raw {
                rewritten.insert(key.to_owned());
                raw = changed;
            }
        }
        out.push_str(&raw);
    }
    (out, rewritten.into_iter().collect())
}

fn rewrite(value: &str, sources: &[String], target: &str) -> String {
    let mut out = String::new();
    let mut last = 0;
    let mut index = 0;
    let bytes = value.as_bytes();
    while index < bytes.len() {
        if bytes[index].is_ascii_whitespace() || bytes[index] == b',' || bytes[index] == b';' {
            index += 1;
            continue;
        }
        let start = index;
        while index < bytes.len() && !(bytes[index].is_ascii_whitespace() || bytes[index] == b',' || bytes[index] == b';') {
            index += 1;
        }
        if value[..start].contains(['?', '#']) {
            break;
        }
        if let Some(replaced) = item(&value[start..index], sources, target) {
            out.push_str(&value[last..start]);
            out.push_str(&replaced);
            last = index;
        }
    }
    out.push_str(&value[last..]);
    out
}

/// One list item with its host replaced, or None when it is no host item or already the target.
fn item(part: &str, sources: &[String], target: &str) -> Option<String> {
    let (prefix, rest) = scheme_prefix(part);
    let lower = rest.to_ascii_lowercase();
    let source = sources.iter().find(|source| {
        lower.starts_with(source.as_str()) && {
            let tail = &rest[source.len()..];
            tail.is_empty() || tail.starts_with(['/', '?', '#']) || port_then(tail)
        }
    })?;
    if source.eq_ignore_ascii_case(target) {
        return None;
    }
    Some(format!("{prefix}{target}{}", &rest[source.len()..]))
}

/// A `:<digits>` port followed by the end or a path, query or fragment.
fn port_then(tail: &str) -> bool {
    let Some(digits) = tail.strip_prefix(':') else { return false };
    let end = digits.find(|c: char| !c.is_ascii_digit()).unwrap_or(digits.len());
    end > 0 && (end == digits.len() || digits[end..].starts_with(['/', '?', '#']))
}

/// A leading scheme with `//`, a bare `//`, `*.` or `.`, and the rest.
fn scheme_prefix(part: &str) -> (&str, &str) {
    if let Some(at) = part.find("//") {
        let scheme = &part[..at];
        let valid = scheme.is_empty()
            || (scheme.ends_with(':')
                && scheme[..scheme.len() - 1].chars().next().is_some_and(|c| c.is_ascii_alphabetic())
                && scheme[..scheme.len() - 1].chars().all(|c| c.is_ascii_alphanumeric() || "+.-".contains(c)));
        if valid {
            return part.split_at(at + 2);
        }
    }
    for prefix in ["*.", "."] {
        if let Some(rest) = part.strip_prefix(prefix) {
            return (prefix, rest);
        }
    }
    ("", part)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(own: &[&str], drop: &[&str], guard: &[&str]) -> Merge {
        let list = |values: &[&str]| values.iter().map(|value| (*value).to_owned()).collect();
        Merge { own: list(own), drop: list(drop), guard: list(guard), hosts: None, shared: None }
    }

    #[test]
    fn the_render_is_the_file_without_a_base_or_names() {
        assert_eq!(merge("", "A=1\n", Some(&names(&[], &[], &[]))).0, "A=1\n");
        assert_eq!(merge("B=2\n", "A=1\n", None).0, "A=1\n");
    }

    #[test]
    fn owned_keys_come_from_the_render_dropped_and_credential_keys_go_and_others_stay() {
        let base = "# app\nAPP_URL=https://default.test\nDB_PASSWORD=supersecret1\nCACHE=redis\nMIRROR=x-supersecret1-y\nOLD=1\n";
        let rendered = "APP_URL=https://clone.test\nNEW=2\n";
        let (text, keys) = merge(base, rendered, Some(&names(&["APP_URL"], &["OLD"], &["DB_PASSWORD"])));

        assert_eq!(text, "# app\nAPP_URL=https://clone.test\nCACHE=redis\nNEW=2\n");
        assert_eq!(keys.copied, vec!["CACHE".to_owned()]);
        assert_eq!(keys.dropped, vec!["DB_PASSWORD".to_owned(), "MIRROR".to_owned(), "OLD".to_owned()]);
    }

    #[test]
    fn a_quoted_value_spans_lines_and_unescapes() {
        let parsed = blocks("A=\"one\ntwo \\\"q\\\"\"\nB='x' # note\nexport C = 3\n");
        let values: Vec<(Option<String>, Option<String>)> = parsed.into_iter().map(|block| (block.key, block.value)).collect();
        assert_eq!(
            values,
            vec![
                (Some("A".to_owned()), Some("one\ntwo \"q\"".to_owned())),
                (Some("B".to_owned()), Some("x".to_owned())),
                (Some("C".to_owned()), Some("3".to_owned())),
            ]
        );
    }

    #[test]
    fn the_defaults_host_items_in_copied_values_become_the_clones() {
        let mut spec = names(&[], &[], &[]);
        spec.hosts = Some(Hosts { from: vec!["app.test".to_owned()], to: Some("clone.app.test".to_owned()) });
        let base = "SANCTUM=app.test,localhost:3000\nCORS=https://app.test:8443/x\nCOOKIE=.app.test\nKEEP=myapp.test\nQ=x?u=app.test\n";

        let (text, keys) = merge(base, "", Some(&spec));

        assert_eq!(text, "SANCTUM=clone.app.test,localhost:3000\nCORS=https://clone.app.test:8443/x\nCOOKIE=.clone.app.test\nKEEP=myapp.test\nQ=x?u=app.test\n");
        assert_eq!(keys.rewritten, Some(vec!["COOKIE".to_owned(), "CORS".to_owned(), "SANCTUM".to_owned()]));
    }
}
