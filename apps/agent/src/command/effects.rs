//! Durable target-local evidence for commands that must never repeat an uncertain effect.
use super::{Command, Context, Outcome, Platform, Privilege};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};
use std::{
    fs::{self, File, OpenOptions},
    io::{Read, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt},
    path::PathBuf,
    time::Duration,
};

const ROOT: &str = "/var/lib/orbit-agent/effects";
const RETENTION: Duration = Duration::from_secs(30 * 86400);
const PRUNE_INTERVAL: Duration = Duration::from_secs(86400);

const MAX_RECEIPT: u64 = 16 * 1024 * 1024;

fn refused(code: &str) -> Outcome {
    Outcome::refused(
        code,
        "The keyed effect receipt cannot authorize another execution.",
        json!({}),
    )
}
pub fn valid_key(key: &str) -> bool {
    let Some((operation, step)) = key.strip_prefix("op-").and_then(|key| key.split_once('.'))
    else {
        return false;
    };
    !operation.starts_with('0')
        && !operation.is_empty()
        && operation.bytes().all(|c| c.is_ascii_digit())
        && !step.is_empty()
        && key.len() <= 192
        && step
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || b"_.:-".contains(&c))
}
pub fn split<C: Command>(mut input: Value) -> Result<(Value, Option<String>), Outcome> {
    if !C::KEYED_EFFECT {
        return Ok((input, None));
    }
    let key = input
        .as_object_mut()
        .and_then(|value| value.remove("effect_key"));
    match key {
        Some(Value::String(key)) if valid_key(&key) => Ok((input, Some(key))),
        None => Ok((input, None)),
        _ => Err(refused("agent.effect_key_invalid")),
    }
}
pub fn fingerprint(command: &str, input: &Value) -> String {
    // serde_json preserves object insertion order; normalize objects, preserve array order.
    fn ordered(value: &Value) -> Value {
        match value {
            Value::Object(values) => {
                let mut fields: Vec<_> = values.iter().collect();
                fields.sort_by_key(|(key, _)| *key);
                Value::Object(
                    fields
                        .into_iter()
                        .map(|(key, value)| (key.clone(), ordered(value)))
                        .collect(),
                )
            }
            Value::Array(values) => Value::Array(values.iter().map(ordered).collect()),
            _ => value.clone(),
        }
    }
    digest(&serde_json::to_vec(&(command, ordered(input))).expect("JSON serializes"))
}
fn digest(bytes: &[u8]) -> String {
    super::run::hex(&Sha256::digest(bytes))
}

#[derive(Serialize, Deserialize)]
struct Stored {
    effect_key: String,
    command: String,
    fingerprint: String,
    response: Option<Outcome>,
    result_digest: Option<String>,
    finished_at: Option<String>,
    running_until: Option<i64>,
    #[serde(default)]
    sanitized: bool,
    #[serde(default)]
    effect_nonce: Option<String>,
    #[serde(default)]
    effect_created_at: Option<i64>,
}
/// A reusable store. Tests and host-specific composition can supply a private directory.
pub struct Store {
    directory: PathBuf,
}
impl Store {
    pub fn at(directory: PathBuf) -> Self {
        Self { directory }
    }
    fn system() -> Result<Self, Outcome> {
        let uid = super::identity::effective_uid();
        if uid == 0 {
            return Ok(Self::at(PathBuf::from(ROOT)));
        }
        let user = super::identity::user_name(uid)
            .and_then(|user| super::identity::passwd(&user))
            .ok_or_else(|| refused("agent.effect_receipt_unavailable"))?;
        Ok(Self::at(PathBuf::from(user.home).join(".orbit/effects")))
    }
    fn path(&self, key: &str) -> PathBuf {
        self.directory
            .join(format!("{}.json", digest(key.as_bytes())))
    }
    fn private(&self, create: bool) -> Result<bool, Outcome> {
        if create {
            fs::DirBuilder::new()
                .recursive(true)
                .mode(0o700)
                .create(&self.directory)
                .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        }
        match fs::symlink_metadata(&self.directory) {
            Err(error) if error.kind() == std::io::ErrorKind::NotFound && !create => Ok(false),
            Ok(metadata)
                if metadata.is_dir()
                    && metadata.uid() == super::identity::effective_uid()
                    && metadata.mode() & 0o777 == 0o700 =>
            {
                Ok(true)
            }
            _ => Err(refused("agent.effect_receipt_unavailable")),
        }
    }
    fn load(&self, key: &str) -> Result<Option<Stored>, Outcome> {
        let file = match OpenOptions::new()
            .read(true)
            .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
            .open(self.path(key))
        {
            Ok(file) => file,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(None),
            Err(_) => return Err(refused("agent.effect_receipt_unavailable")),
        };
        let metadata = file
            .metadata()
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        if !metadata.is_file()
            || metadata.uid() != super::identity::effective_uid()
            || metadata.mode() & 0o777 != 0o600
            || metadata.nlink() != 1
            || metadata.len() > MAX_RECEIPT
        {
            return Err(refused("agent.effect_receipt_unavailable"));
        }
        let receipt: Stored = serde_json::from_reader(file.take(MAX_RECEIPT))
            .map_err(|_| refused("agent.effect_receipt_invalid"))?;
        if receipt.effect_key != key
            || receipt.response.as_ref().map(|outcome| {
                digest(&serde_json::to_vec(&outcome.to_json()).expect("JSON serializes"))
            }) != receipt.result_digest
        {
            return Err(refused("agent.effect_receipt_invalid"));
        }
        Ok(Some(receipt))
    }
    fn save(&self, stored: &Stored) -> Result<(), Outcome> {
        let temporary = self
            .directory
            .join(format!(".receipt-{:016x}", rand::random::<u64>()));
        let result = (|| -> std::io::Result<()> {
            let mut file = OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
                .open(&temporary)?;
            file.write_all(&serde_json::to_vec(stored)?)?;
            file.sync_all()?;
            fs::rename(&temporary, self.path(&stored.effect_key))?;
            File::open(&self.directory)?.sync_all()
        })();
        if result.is_err() {
            let _ = fs::remove_file(temporary);
        }
        result.map_err(|_| refused("agent.effect_receipt_unavailable"))
    }
    fn namespace(&self, create: bool, exclusive: bool) -> Result<Option<File>, Outcome> {
        let guard = match OpenOptions::new()
            .read(true)
            .write(create)
            .create(create)
            .truncate(false)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
            .open(self.directory.join(".store.lock"))
        {
            Ok(file) => file,
            Err(error) if !create && error.kind() == std::io::ErrorKind::NotFound => {
                return Ok(None)
            }
            Err(_) => return Err(refused("agent.effect_receipt_unavailable")),
        };
        let metadata = guard
            .metadata()
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        if !metadata.is_file()
            || metadata.uid() != super::identity::effective_uid()
            || metadata.mode() & 0o777 != 0o600
            || metadata.nlink() != 1
        {
            return Err(refused("agent.effect_receipt_unavailable"));
        }
        match rustix::fs::flock(
            &guard,
            if exclusive {
                rustix::fs::FlockOperation::NonBlockingLockExclusive
            } else {
                rustix::fs::FlockOperation::LockShared
            },
        ) {
            Ok(()) => Ok(Some(guard)),
            Err(rustix::io::Errno::WOULDBLOCK) if exclusive => Ok(None),
            Err(_) => Err(refused("agent.effect_receipt_unavailable")),
        }
    }
    /// Daily bounded pruning under an exclusive namespace guard. Receipt reads never prune.
    fn prune(&self) -> Result<(), Outcome> {
        let Some(guard) = self.namespace(true, true)? else {
            return Ok(());
        };
        if guard
            .metadata()
            .and_then(|metadata| metadata.modified())
            .ok()
            .and_then(|modified| modified.elapsed().ok())
            .is_none_or(|age| age < PRUNE_INTERVAL)
        {
            return Ok(());
        }
        let mut removed = 0;
        for entry in fs::read_dir(&self.directory)
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?
        {
            let entry = entry.map_err(|_| refused("agent.effect_receipt_unavailable"))?;
            let name = entry.file_name();
            let Some(name) = name.to_str() else {
                continue;
            };
            let Some((stem, extension)) = name.rsplit_once('.') else {
                continue;
            };
            if stem.len() != 64
                || !stem.bytes().all(|c| c.is_ascii_hexdigit())
                || !matches!(extension, "json" | "lock")
            {
                continue;
            }
            let metadata = fs::symlink_metadata(entry.path())
                .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
            if !metadata.is_file()
                || metadata.uid() != super::identity::effective_uid()
                || metadata.mode() & 0o777 != 0o600
                || metadata.nlink() != 1
            {
                continue;
            }
            if metadata
                .modified()
                .ok()
                .and_then(|modified| modified.elapsed().ok())
                .is_some_and(|age| age >= RETENTION)
            {
                fs::remove_file(entry.path())
                    .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
                removed += 1;
                if removed >= 256 {
                    break;
                }
            }
        }
        if removed < 256 {
            guard
                .set_modified(std::time::SystemTime::now())
                .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        }
        File::open(&self.directory)
            .and_then(|directory| directory.sync_all())
            .map_err(|_| refused("agent.effect_receipt_unavailable"))
    }
    fn locked<T>(
        &self,
        key: &str,
        context: &Context,
        action: impl FnOnce() -> Result<T, Outcome>,
    ) -> Result<T, Outcome> {
        if !valid_key(key) {
            return Err(refused("agent.effect_key_invalid"));
        }
        self.private(true)?;
        self.prune()?;
        let _namespace = self.namespace(true, false)?;
        let lock_path = self
            .directory
            .join(format!("{}.lock", digest(key.as_bytes())));
        let lock = OpenOptions::new()
            .read(true)
            .write(true)
            .create(true)
            .truncate(false)
            .mode(0o600)
            .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
            .open(lock_path)
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        let metadata = lock
            .metadata()
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        if !metadata.is_file()
            || metadata.uid() != super::identity::effective_uid()
            || metadata.mode() & 0o777 != 0o600
            || metadata.nlink() != 1
        {
            return Err(refused("agent.effect_receipt_unavailable"));
        }
        loop {
            match rustix::fs::flock(&lock, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
                Ok(()) => break,
                Err(rustix::io::Errno::WOULDBLOCK) if !context.remaining().is_zero() => {
                    std::thread::sleep(context.remaining().min(Duration::from_millis(25)))
                }
                _ => return Err(refused("agent.effect_unknown")),
            }
        }
        action()
    }
    fn sanitize(outcome: Outcome, context: &Context) -> Result<Outcome, Outcome> {
        let mut masks = context
            .effect_masks()
            .ok_or_else(|| refused("agent.effect_sanitization_required"))?
            .to_vec();
        let mut booleans = context.effect_boolean_masks().to_vec();
        fn collect(value: &Value, masks: &mut Vec<String>, booleans: &mut Vec<bool>) {
            match value {
                Value::String(text) => masks.push(text.clone()),
                Value::Number(number) => masks.push(number.to_string()),
                Value::Bool(boolean) => booleans.push(*boolean),
                Value::Array(items) => {
                    for item in items {
                        collect(item, masks, booleans);
                    }
                }
                Value::Object(fields) => {
                    for value in fields.values() {
                        collect(value, masks, booleans);
                    }
                }
                _ => {}
            }
        }
        let original = outcome.clone();
        let mut outcome = outcome;
        let mut present = Vec::new();
        if let Outcome::Ok(result) = &mut outcome {
            for path in context.effect_secret_outputs() {
                let pointer = format!("/{}", path.replace('.', "/"));
                if let Some(value) = result.pointer_mut(&pointer) {
                    if !value.is_null() {
                        collect(value, &mut masks, &mut booleans);
                        *value = json!({"$secret_output":true});
                    }
                    present.push((path.clone(), value.clone()));
                }
            }
        }
        let masks = masks.as_slice();
        fn text(mut value: String, masks: &[String]) -> String {
            let mut masks: Vec<_> = masks.iter().filter(|value| !value.is_empty()).collect();
            masks.sort_by_key(|value| std::cmp::Reverse(value.len()));
            for mask in masks {
                value = value.replace(mask.as_str(), "[REDACTED]");
            }
            value
        }
        fn value(input: Value, masks: &[String], booleans: &[bool]) -> Value {
            match input {
                Value::String(string) => Value::String(text(string, masks)),
                Value::Array(items) => Value::Array(
                    items
                        .into_iter()
                        .map(|item| value(item, masks, booleans))
                        .collect(),
                ),
                Value::Object(fields) => Value::Object(
                    fields
                        .into_iter()
                        .map(|(key, item)| (text(key, masks), value(item, masks, booleans)))
                        .collect(),
                ),
                Value::Bool(boolean) if booleans.contains(&boolean) => {
                    Value::String("[REDACTED]".into())
                }
                Value::Number(number)
                    if masks.contains(&number.to_string())
                        || masks.iter().any(|mask| {
                            mask.parse::<f64>()
                                .ok()
                                .is_some_and(|value| Some(value) == number.as_f64())
                        }) =>
                {
                    Value::String("[REDACTED]".into())
                }
                other => other,
            }
        }
        Ok(match outcome {
            Outcome::Ok(result) => {
                let mut result = value(result, masks, &booleans);
                if let Outcome::Ok(original) = &original {
                    for (path, replacement) in present {
                        restore_output(&mut result, original, &path, replacement);
                    }
                }
                Outcome::Ok(result)
            }
            Outcome::Refused {
                code,
                message,
                details,
            } => Outcome::Refused {
                code: text(code, masks),
                message: text(message, masks),
                details: value(details, masks, &booleans),
            },
        })
    }
    fn identity(context: &Context) -> Result<(&str, i64), Outcome> {
        let (nonce, created_at) = context
            .effect_identity()
            .ok_or_else(|| refused("agent.effect_sanitization_required"))?;
        let now = time::OffsetDateTime::now_utc().unix_timestamp();
        if nonce.len() != 36
            || !nonce.bytes().all(|c| c.is_ascii_hexdigit() || c == b'-')
            || created_at <= 0
            || created_at > now + 300
        {
            return Err(refused("agent.effect_key_invalid"));
        }
        if now - created_at >= RETENTION.as_secs() as i64 {
            return Err(refused("agent.effect_expired"));
        }
        Ok((nonce, created_at))
    }
    pub fn execute(
        &self,
        command: &str,
        key: &str,
        fingerprint: &str,
        context: &Context,
        effect: impl FnOnce() -> Outcome,
    ) -> Outcome {
        let result = Self::identity(context).and_then(|(nonce, created_at)| {
            self.locked(key, context, || {
                if let Some(stored) = self.load(key)? {
                    if stored.command != command
                        || stored.fingerprint != fingerprint
                        || stored.effect_nonce.as_deref() != Some(nonce)
                        || stored.effect_created_at != Some(created_at)
                    {
                        return Err(refused("agent.effect_conflict"));
                    }
                    if stored.response.is_some() && !stored.sanitized {
                        return Err(refused("agent.effect_unsanitized"));
                    }
                    return Ok(stored
                        .response
                        .unwrap_or_else(|| refused("agent.effect_unknown")));
                }
                if context.effect_masks().is_none() {
                    return Err(refused("agent.effect_sanitization_required"));
                }
                let mut stored = Stored {
                    effect_key: key.into(),
                    command: command.into(),
                    fingerprint: fingerprint.into(),
                    response: None,
                    result_digest: None,
                    finished_at: None,
                    running_until: None,
                    sanitized: false,
                    effect_nonce: Some(nonce.into()),
                    effect_created_at: Some(created_at),
                };
                self.save(&stored)?;
                let outcome = effect();
                if let Outcome::Refused { code, .. } = &outcome {
                    if super::NOT_STARTED_ERRORS.contains(&code.as_str())
                        || super::PREFLIGHT_ERRORS.contains(&code.as_str())
                    {
                        fs::remove_file(self.path(key))
                            .and_then(|()| File::open(&self.directory)?.sync_all())
                            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
                        return Self::sanitize(outcome, context);
                    }
                    if super::COMMON_ERRORS.contains(&code.as_str()) {
                        return Self::sanitize(outcome, context);
                    }
                }
                let live = outcome.clone();
                let outcome = Self::sanitize(outcome, context)?;
                stored.sanitized = true;
                stored.result_digest = Some(digest(
                    &serde_json::to_vec(&outcome.to_json()).expect("JSON serializes"),
                ));
                stored.finished_at = Some(
                    time::OffsetDateTime::now_utc()
                        .format(&time::format_description::well_known::Rfc3339)
                        .expect("UTC formats"),
                );
                stored.response = Some(outcome.clone());
                self.save(&stored)?;
                let mut outcome = outcome;
                if let (Outcome::Ok(original), Outcome::Ok(result)) = (live, &mut outcome) {
                    for path in context.effect_secret_outputs() {
                        let pointer = format!("/{}", path.replace('.', "/"));
                        if let Some(secret) = original.pointer(&pointer) {
                            restore_output(result, &original, path, secret.clone());
                        }
                    }
                }
                Ok(outcome)
            })
        });
        result.unwrap_or_else(|error| error)
    }
    /// Reserve an external named program. Losing the reservation reply leaves an unknown intent.
    pub fn reserve(
        &self,
        command: &str,
        key: &str,
        fingerprint: &str,
        timeout: Duration,
        context: &Context,
    ) -> Result<Reservation, Outcome> {
        let (nonce, created_at) = Self::identity(context)?;
        self.locked(key, context, || {
            if let Some(stored) = self.load(key)? {
                if stored.command != command
                    || stored.fingerprint != fingerprint
                    || stored.effect_nonce.as_deref() != Some(nonce)
                    || stored.effect_created_at != Some(created_at)
                {
                    return Err(refused("agent.effect_conflict"));
                }
                return Ok(Reservation {
                    reserved: false,
                    receipt: self.receipt(command, key)?,
                });
            }
            self.save(&Stored {
                effect_key: key.into(),
                command: command.into(),
                fingerprint: fingerprint.into(),
                response: None,
                result_digest: None,
                finished_at: None,
                sanitized: false,
                effect_nonce: Some(nonce.into()),
                effect_created_at: Some(created_at),
                running_until: Some(
                    time::OffsetDateTime::now_utc().unix_timestamp()
                        + timeout.as_secs().min(86400) as i64,
                ),
            })?;
            Ok(Reservation {
                reserved: true,
                receipt: self.receipt(command, key)?,
            })
        })
    }
    pub fn complete(
        &self,
        command: &str,
        key: &str,
        fingerprint: &str,
        result: Value,
        context: &Context,
    ) -> Result<Receipt, Outcome> {
        let (nonce, created_at) = Self::identity(context)?;
        self.locked(key, context, || {
            let mut stored = self
                .load(key)?
                .ok_or_else(|| refused("agent.effect_unknown"))?;
            let outcome = Self::sanitize(Outcome::Ok(result), context)?;
            if stored.command != command
                || stored.effect_nonce.as_deref() != Some(nonce)
                || stored.effect_created_at != Some(created_at)
                || stored.fingerprint != fingerprint
                || stored.response.as_ref().is_some_and(|old| old != &outcome)
            {
                return Err(refused("agent.effect_conflict"));
            }
            if stored.response.is_none() {
                stored.result_digest = Some(digest(
                    &serde_json::to_vec(&outcome.to_json()).expect("JSON serializes"),
                ));
                stored.finished_at = Some(
                    time::OffsetDateTime::now_utc()
                        .format(&time::format_description::well_known::Rfc3339)
                        .expect("UTC formats"),
                );
                stored.response = Some(outcome);
                stored.sanitized = true;
                self.save(&stored)?;
            }
            self.receipt(command, key)
        })
    }
    fn running(&self, key: &str, running_until: Option<i64>) -> Result<bool, Outcome> {
        if let Some(until) = running_until {
            return Ok(time::OffsetDateTime::now_utc().unix_timestamp() < until);
        }
        let lock = OpenOptions::new()
            .read(true)
            .custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC)
            .open(
                self.directory
                    .join(format!("{}.lock", digest(key.as_bytes()))),
            )
            .map_err(|_| refused("agent.effect_receipt_unavailable"))?;
        match rustix::fs::flock(&lock, rustix::fs::FlockOperation::NonBlockingLockExclusive) {
            Ok(()) => Ok(false),
            Err(rustix::io::Errno::WOULDBLOCK) => Ok(true),
            Err(_) => Err(refused("agent.effect_receipt_unavailable")),
        }
    }
    pub fn receipt(&self, command: &str, key: &str) -> Result<Receipt, Outcome> {
        if !valid_key(key) {
            return Err(refused("agent.effect_key_invalid"));
        }
        let _namespace = if self.private(false)? {
            self.namespace(false, false)?
        } else {
            None
        };
        let stored = if self.private(false)? {
            self.load(key)?
        } else {
            None
        };
        if let Some(stored) = stored {
            if stored.response.is_some() && !stored.sanitized {
                return Err(refused("agent.effect_unsanitized"));
            }
            if stored.command != command {
                return Err(refused("agent.effect_conflict"));
            }
            let (state, exit_code) = match stored.response.as_ref() {
                Some(Outcome::Ok(value))
                    if command.starts_with("remote_program.")
                        && value.get("outcome").and_then(Value::as_str) == Some("failed") =>
                {
                    let code = value
                        .pointer("/data/exit_code")
                        .and_then(Value::as_i64)
                        .and_then(|code| i32::try_from(code).ok())
                        .unwrap_or(1);
                    ("failed", Some(code))
                }
                Some(Outcome::Ok(_)) => ("succeeded", Some(0)),
                Some(Outcome::Refused { .. }) => ("failed", Some(1)),
                None if self.running(key, stored.running_until)? => ("running", None),
                None => ("unknown", None),
            };
            return Ok(Receipt {
                effect_key: key.into(),
                command: command.into(),
                sanitized: stored.sanitized,
                effect_nonce: stored.effect_nonce,
                effect_created_at: stored.effect_created_at,
                result: stored.response.as_ref().map(Outcome::to_json),
                state: state.into(),
                exit_code,
                result_digest: stored.result_digest,
                finished_at: stored.finished_at,
            });
        }
        Ok(Receipt {
            effect_key: key.into(),
            command: command.into(),
            state: "unknown".into(),
            sanitized: false,
            effect_nonce: None,
            effect_created_at: None,
            exit_code: None,
            result: None,
            result_digest: None,
            finished_at: None,
        })
    }
}

// Restore public declaration paths using original structure, even if a mask also matched a JSON key.
fn restore_output(target: &mut Value, original: &Value, path: &str, value: Value) {
    fn set(target: &mut Value, original: &Value, parts: &[&str], value: Value) {
        let Some((part, rest)) = parts.split_first() else {
            *target = value;
            return;
        };
        match original {
            Value::Object(fields) => {
                if let Some(original) = fields.get(*part) {
                    if !target.is_object() {
                        *target = json!({});
                    }
                    let child = target
                        .as_object_mut()
                        .expect("object just created")
                        .entry((*part).to_string())
                        .or_insert(Value::Null);
                    set(child, original, rest, value);
                }
            }
            Value::Array(items) => {
                if !target.is_array() {
                    *target = Value::Array(vec![Value::Null; items.len()]);
                }
                if let Ok(index) = part.parse::<usize>() {
                    if let (Some(original), Some(child)) = (items.get(index), target.get_mut(index))
                    {
                        set(child, original, rest, value);
                    }
                }
            }
            _ => {}
        }
    }
    set(
        target,
        original,
        &path.split('.').collect::<Vec<_>>(),
        value,
    );
}

fn bound_fingerprint(command: &str, input: &Value, context: &Context) -> Result<String, Outcome> {
    use std::os::unix::fs::FileExt;
    let file = context
        .clone_binary_input()
        .map_err(|_| refused("agent.binary_input_invalid"))?;
    let mut bound = json!({"input":input,"identity":context.effect_identity()});
    if !context.effect_secret_outputs().is_empty() {
        bound["secret_outputs"] = json!(context.effect_secret_outputs());
    }
    let Some(file) = file else {
        return Ok(fingerprint(command, &bound));
    };
    let mut hash = Sha256::new();
    let mut buffer = [0; 65536];
    let mut offset = 0;
    loop {
        let count = file
            .read_at(&mut buffer, offset)
            .map_err(|_| refused("agent.binary_input_invalid"))?;
        if count == 0 {
            break;
        }
        hash.update(&buffer[..count]);
        offset += count as u64;
    }
    bound["binary_sha256"] = json!(super::run::hex(&hash.finalize()));
    Ok(fingerprint(command, &bound))
}

pub fn execute<C: Command>(
    key: Option<String>,
    input: Value,
    context: &Context,
    effect: impl FnOnce() -> Outcome,
) -> Outcome {
    match key {
        Some(key) => match Store::system() {
            Ok(store) => match bound_fingerprint(C::NAME, &input, context) {
                Ok(fingerprint) => store.execute(C::NAME, &key, &fingerprint, context, effect),
                Err(error) => error,
            },
            Err(error) => error,
        },
        None => effect(),
    }
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReceiptInput {
    pub command: String,
    pub effect_key: String,
    pub owner: Option<String>,
}
#[derive(schemars::JsonSchema, Serialize)]
pub struct Receipt {
    pub command: String,
    pub effect_key: String,
    pub state: String,
    pub sanitized: bool,
    pub effect_nonce: Option<String>,
    pub effect_created_at: Option<i64>,
    pub exit_code: Option<i32>,
    pub result: Option<Value>,
    pub result_digest: Option<String>,
    pub finished_at: Option<String>,
}
pub struct ReadReceipt;
pub struct ReceiptError(Outcome);
impl super::CommandError for ReceiptError {
    const CODES: &'static [&'static str] = &[];
    fn code(&self) -> &'static str {
        match &self.0 {
            Outcome::Refused { code, .. } if code == "agent.effect_unsanitized" => {
                "agent.effect_unsanitized"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_sanitization_required" => {
                "agent.effect_sanitization_required"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_expired" => {
                "agent.effect_expired"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_unknown" => {
                "agent.effect_unknown"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_conflict" => {
                "agent.effect_conflict"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_key_invalid" => {
                "agent.effect_key_invalid"
            }
            Outcome::Refused { code, .. } if code == "agent.effect_receipt_invalid" => {
                "agent.effect_receipt_invalid"
            }
            _ => "agent.effect_receipt_unavailable",
        }
    }
    fn message(&self) -> String {
        "The keyed effect receipt cannot be read.".into()
    }
}
impl Command for ReadReceipt {
    const NAME: &'static str = "agent.effect-receipt";
    const SUMMARY: &'static str = "Read a keyed effect receipt without executing the command.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = ReceiptInput;
    type Output = Receipt;
    type Error = ReceiptError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        Some(match platform {
            Platform::Linux => Privilege::Root,
            Platform::Macos => Privilege::Account,
        })
    }
    fn run(input: ReceiptInput, _: &Context) -> Result<Receipt, ReceiptError> {
        let read = move || Store::system()?.receipt(&input.command, &input.effect_key);
        match input.owner {
            Some(owner)
                if super::identity::user_name(super::identity::effective_uid()).as_deref()
                    != Some(owner.as_str()) =>
            {
                super::identity::on_account_thread(&owner, read)
                    .map_err(|_| ReceiptError(refused("agent.effect_receipt_unavailable")))?
                    .map_err(ReceiptError)
            }
            _ => read().map_err(ReceiptError),
        }
    }
}

#[cfg(test)]
mod tests;

#[derive(schemars::JsonSchema, Serialize)]
pub struct Reservation {
    pub reserved: bool,
    pub receipt: Receipt,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReserveInput {
    pub command: String,
    pub effect_key: String,
    pub fingerprint: String,
    pub timeout_seconds: u64,
}
#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CompleteInput {
    pub command: String,
    pub effect_key: String,
    pub fingerprint: String,
    pub result: Value,
}
fn external(command: &str, fingerprint: &str) -> Result<(), ReceiptError> {
    if !command.starts_with("remote_program.")
        || command.len() > 128
        || !command
            .bytes()
            .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b"._-".contains(&b))
        || fingerprint.len() != 64
        || !fingerprint.bytes().all(|b| b.is_ascii_hexdigit())
    {
        return Err(ReceiptError(refused("agent.effect_key_invalid")));
    }
    Ok(())
}
pub struct ReserveEffect;
impl Command for ReserveEffect {
    const NAME: &'static str = "agent.effect-reserve";
    const SUMMARY: &'static str = "Reserve one named remote program without executing it.";
    const MUTATES: bool = true;
    const KEYED_EFFECT: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = ReserveInput;
    type Output = Reservation;
    type Error = ReceiptError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: ReserveInput, context: &Context) -> Result<Reservation, ReceiptError> {
        external(&input.command, &input.fingerprint)?;
        Store::system()
            .and_then(|store| {
                store.reserve(
                    &input.command,
                    &input.effect_key,
                    &input.fingerprint,
                    Duration::from_secs(input.timeout_seconds),
                    context,
                )
            })
            .map_err(ReceiptError)
    }
}
pub struct CompleteEffect;
impl Command for CompleteEffect {
    const NAME: &'static str = "agent.effect-complete";
    const SUMMARY: &'static str = "Save the result of a reserved named remote program.";
    const MUTATES: bool = true;
    const KEYED_EFFECT: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(10);
    type Input = CompleteInput;
    type Output = Receipt;
    type Error = ReceiptError;
    fn privilege(platform: Platform) -> Option<Privilege> {
        crate::commands::linux_only(platform, Privilege::Root)
    }
    fn run(input: CompleteInput, context: &Context) -> Result<Receipt, ReceiptError> {
        external(&input.command, &input.fingerprint)?;
        Store::system()
            .and_then(|store| {
                store.complete(
                    &input.command,
                    &input.effect_key,
                    &input.fingerprint,
                    input.result,
                    context,
                )
            })
            .map_err(ReceiptError)
    }
}
