//! Private staged recovery artifacts. Record policy and the live restore transaction stay in PHP.
use super::gateway_adoption::{hex, owned};
use crate::{
    command::{run::file_sha256, Command, Context, Platform, Privilege},
    command_errors,
};
use rusqlite::{Connection, OpenFlags};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::{
    fs,
    io::{Read, Write},
    os::unix::fs::{DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt},
    path::{Path, PathBuf},
    time::{Duration, Instant},
};

const RECEIPT_BYTES: u64 = 1024 * 1024;
#[derive(Debug, Serialize, PartialEq)]
pub struct Violation {
    table: String,
    rowid: Option<i64>,
    parent: String,
    fkid: i64,
}
command_errors! {
    pub enum Error {
        Invalid {} => "gateway.release_recovery_invalid", "The original staged recovery copy is unavailable, unsafe or not a standalone SQLite snapshot. Keep the hold.";
        ForeignKeys { violations: Vec<Violation>, limit: usize } => "gateway.release_recovery_foreign_keys", "The original snapshot has foreign-key violations. Keep consumers held; repair the source data before a new stage or recover a verified complete recovery point. Never edit the protected snapshot.";
        Receipt {} => "gateway.release_restore_required", "The original private staged receipt is missing, unsafe or differs from the requested receipt. Keep the hold.";
    }
}
#[derive(Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Identity {
    orbit_home: PathBuf,
    record: u64,
    sha: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ProtectInput {
    identity: Identity,
    source: PathBuf,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct VerifyInput {
    identity: Identity,
    sha256: String,
}
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ReceiptInput {
    identity: Identity,
    attributes: Map<String, Value>,
}
#[derive(Debug, Serialize, PartialEq)]
pub struct Recovery {
    path: PathBuf,
    sha256: String,
}
#[derive(Debug, Serialize)]
pub struct Receipt {
    attributes: Map<String, Value>,
}
#[derive(Debug, Serialize)]
pub struct Changed {
    changed: bool,
}
#[derive(Debug, Serialize)]
pub struct Verified {
    verified: bool,
}
fn invalid<T>(_: T) -> Error {
    Error::Invalid {}
}
fn receipt<T>(_: T) -> Error {
    Error::Receipt {}
}
fn path(identity: &Identity, suffix: &str) -> Result<PathBuf, Error> {
    if identity.record == 0
        || identity.record > i64::MAX as u64
        || !hex(&identity.sha, 40)
        || !identity.orbit_home.is_absolute()
        || fs::canonicalize(&identity.orbit_home).ok().as_ref() != Some(&identity.orbit_home)
        || !owned(&identity.orbit_home)
            .map_err(invalid)?
            .is_some_and(|m| m.is_dir())
    {
        return Err(Error::Invalid {});
    }
    Ok(identity.orbit_home.join("recovery").join(format!(
        "stage-{}-{}.{}",
        identity.record, identity.sha, suffix
    )))
}
fn private(path: &Path, directory: bool) -> Result<(), Error> {
    let meta = owned(path).map_err(invalid)?.ok_or(Error::Invalid {})?;
    if meta.mode() & 0o077 != 0
        || if directory {
            !meta.is_dir()
        } else {
            !meta.is_file()
        }
    {
        return Err(Error::Invalid {});
    }
    Ok(())
}
fn standalone(path: &Path) -> Result<(), Error> {
    for suffix in ["-wal", "-journal"] {
        match fs::symlink_metadata(PathBuf::from(format!("{}{suffix}", path.display()))) {
            Ok(meta) if !meta.is_file() || meta.len() != 0 => return Err(Error::Invalid {}),
            Ok(_) => {}
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => {}
            Err(_) => return Err(Error::Invalid {}),
        }
    }
    Ok(())
}
fn check(path: &Path, hash: &str, deadline: Instant) -> Result<(), Error> {
    standalone(path)?;
    if file_sha256(path).map_err(invalid)? != hash {
        return Err(Error::Invalid {});
    }
    let mut uri = reqwest::Url::from_file_path(path).map_err(invalid)?;
    uri.set_query(Some("mode=ro&immutable=1"));
    let db = Connection::open_with_flags(
        uri.as_str(),
        OpenFlags::SQLITE_OPEN_READ_ONLY
            | OpenFlags::SQLITE_OPEN_URI
            | OpenFlags::SQLITE_OPEN_NO_MUTEX,
    )
    .map_err(invalid)?;
    db.progress_handler(1000, Some(move || Instant::now() >= deadline))
        .map_err(invalid)?;
    db.execute_batch("PRAGMA query_only=ON").map_err(invalid)?;
    let mut integrity = db.prepare("PRAGMA integrity_check").map_err(invalid)?;
    let mut rows = integrity.query([]).map_err(invalid)?;
    if rows
        .next()
        .map_err(invalid)?
        .ok_or(Error::Invalid {})?
        .get::<_, String>(0)
        .map_err(invalid)?
        != "ok"
        || rows.next().map_err(invalid)?.is_some()
    {
        return Err(Error::Invalid {});
    }
    let mut foreign = db.prepare("PRAGMA foreign_key_check").map_err(invalid)?;
    let mut rows = foreign.query([]).map_err(invalid)?;
    let mut violations = Vec::new();
    while violations.len() < 20 {
        let Some(row) = rows.next().map_err(invalid)? else {
            break;
        };
        violations.push(Violation {
            table: row.get(0).map_err(invalid)?,
            rowid: row.get(1).map_err(invalid)?,
            parent: row.get(2).map_err(invalid)?,
            fkid: row.get(3).map_err(invalid)?,
        });
    }
    if !violations.is_empty() {
        return Err(Error::ForeignKeys {
            violations,
            limit: 20,
        });
    }
    Ok(())
}
fn verify(input: &VerifyInput, deadline: Instant) -> Result<(), Error> {
    let target = path(&input.identity, "sqlite")?;
    if !hex(&input.sha256, 64) {
        return Err(Error::Invalid {});
    }
    private(target.parent().unwrap(), true)?;
    private(&target, false)?;
    check(&target, &input.sha256, deadline)
}
fn protect(input: &ProtectInput, deadline: Instant) -> Result<Recovery, Error> {
    let target = path(&input.identity, "sqlite")?;
    let directory = target.parent().unwrap();
    if owned(directory).map_err(invalid)?.is_some() {
        private(directory, true)?;
    }
    if !input.source.is_absolute()
        || fs::canonicalize(&input.source).ok().as_ref() != Some(&input.source)
        || !owned(&input.source)
            .map_err(invalid)?
            .is_some_and(|m| m.is_file())
    {
        return Err(Error::Invalid {});
    }
    let hash = file_sha256(&input.source).map_err(invalid)?;
    check(&input.source, &hash, deadline)?;
    if owned(&target).map_err(invalid)?.is_none() {
        if !directory.exists() {
            fs::DirBuilder::new()
                .mode(0o700)
                .create(directory)
                .map_err(invalid)?;
            fs::set_permissions(directory, fs::Permissions::from_mode(0o700)).map_err(invalid)?;
        }
        let partial =
            target.with_extension(format!("sqlite.{:016x}.partial", rand::random::<u64>()));
        let mut output = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&partial)
            .map_err(invalid)?;
        let result = (|| {
            output
                .set_permissions(fs::Permissions::from_mode(0o600))
                .map_err(invalid)?;
            let mut source = fs::File::open(&input.source).map_err(invalid)?;
            std::io::copy(&mut source, &mut output).map_err(invalid)?;
            output.sync_all().map_err(invalid)?;
            private(&partial, false)?;
            check(&partial, &hash, deadline)?;
            fs::hard_link(&partial, &target).map_err(invalid)?;
            fs::File::open(directory)
                .and_then(|f| f.sync_all())
                .map_err(invalid)
        })();
        let _ = fs::remove_file(&partial);
        result?;
    }
    verify(
        &VerifyInput {
            identity: input.identity.clone(),
            sha256: hash.clone(),
        },
        deadline,
    )?;
    Ok(Recovery {
        path: target,
        sha256: hash,
    })
}
fn read_receipt(identity: &Identity) -> Result<Receipt, Error> {
    let target = path(identity, "json").map_err(receipt)?;
    private(target.parent().unwrap(), true).map_err(receipt)?;
    private(&target, false).map_err(receipt)?;
    if fs::symlink_metadata(&target).map_err(receipt)?.mode() & 0o777 != 0o600 {
        return Err(Error::Receipt {});
    }
    let mut bytes = Vec::new();
    fs::File::open(&target)
        .map_err(receipt)?
        .take(RECEIPT_BYTES + 1)
        .read_to_end(&mut bytes)
        .map_err(receipt)?;
    if bytes.len() as u64 > RECEIPT_BYTES {
        return Err(Error::Receipt {});
    }
    let attributes = serde_json::from_slice(&bytes).map_err(receipt)?;
    Ok(Receipt { attributes })
}
fn write_receipt(input: &ReceiptInput) -> Result<Changed, Error> {
    let target = path(&input.identity, "json").map_err(receipt)?;
    private(target.parent().unwrap(), true).map_err(receipt)?;
    if input.attributes.get("id").and_then(Value::as_u64) != Some(input.identity.record)
        || input.attributes.get("sha").and_then(Value::as_str) != Some(input.identity.sha.as_str())
    {
        return Err(Error::Receipt {});
    }
    let bytes = serde_json::to_vec(&input.attributes).map_err(receipt)?;
    if bytes.len() as u64 > RECEIPT_BYTES {
        return Err(Error::Receipt {});
    }
    if owned(&target).map_err(receipt)?.is_some() {
        return if read_receipt(&input.identity)?.attributes == input.attributes {
            Ok(Changed { changed: false })
        } else {
            Err(Error::Receipt {})
        };
    }
    let partial = target.with_extension(format!("json.{:016x}.partial", rand::random::<u64>()));
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&partial)
        .map_err(receipt)?;
    let result = (|| {
        file.set_permissions(fs::Permissions::from_mode(0o600))
            .map_err(receipt)?;
        file.write_all(&bytes)
            .and_then(|_| file.sync_all())
            .map_err(receipt)?;
        fs::hard_link(&partial, &target).map_err(receipt)?;
        fs::File::open(target.parent().unwrap())
            .and_then(|f| f.sync_all())
            .map_err(receipt)?;
        Ok(Changed { changed: true })
    })();
    let _ = fs::remove_file(&partial);
    result
}

pub struct Protect;
impl Command for Protect {
    const NAME: &'static str = "gateway.protect-release-recovery";
    const SUMMARY: &'static str =
        "Preserve and verify a private standalone staged SQLite recovery copy.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = ProtectInput;
    type Output = Recovery;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &Self::Input) -> Option<String> {
        Some(format!(
            "gateway-release-recovery:{}:{}",
            input.identity.orbit_home.display(),
            input.identity.record
        ))
    }
    fn run(input: Self::Input, context: &Context) -> Result<Recovery, Error> {
        protect(&input, context.deadline())
    }
}
pub struct Verify;
impl Command for Verify {
    const NAME: &'static str = "gateway.verify-release-recovery";
    const SUMMARY: &'static str =
        "Verify the recorded private staged recovery copy without changing it.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(900);
    type Input = VerifyInput;
    type Output = Verified;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, context: &Context) -> Result<Verified, Error> {
        verify(&input, context.deadline())?;
        Ok(Verified { verified: true })
    }
}
pub struct WriteReceipt;
impl Command for WriteReceipt {
    const NAME: &'static str = "gateway.write-release-receipt";
    const SUMMARY: &'static str =
        "Publish one private staged record receipt without overwriting another identity.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = ReceiptInput;
    type Output = Changed;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn target(input: &Self::Input) -> Option<String> {
        Some(format!(
            "gateway-release-recovery:{}:{}",
            input.identity.orbit_home.display(),
            input.identity.record
        ))
    }
    fn run(input: Self::Input, _: &Context) -> Result<Changed, Error> {
        write_receipt(&input)
    }
}
pub struct ReadReceipt;
impl Command for ReadReceipt {
    const NAME: &'static str = "gateway.read-release-receipt";
    const SUMMARY: &'static str =
        "Read the private receipt for one staged record and exact target SHA.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Identity;
    type Output = Receipt;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Account)
    }
    fn run(input: Self::Input, _: &Context) -> Result<Receipt, Error> {
        read_receipt(&input)
    }
}
#[cfg(test)]
mod tests;
