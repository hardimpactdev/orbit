//! Shared effective-configuration validation for distribution PHP-FPM pools.
use super::recipe::{self, Files, Host, Invocation, WorkDir};
use std::{fs, io, os::unix::fs::MetadataExt, path::Path};
#[derive(Debug, PartialEq)]
pub enum Error {
    Invalid,
    Unsafe,
    Failed(&'static str),
}
fn failed(stage: &'static str) -> Error {
    Error::Failed(stage)
}
pub fn metadata(path: &Path, uid: u32, directory: bool) -> Result<Option<fs::Metadata>, Error> {
    match fs::symlink_metadata(path) {
        Ok(m) if m.uid() == uid && if directory { m.is_dir() } else { m.is_file() } => Ok(Some(m)),
        Ok(_) => Err(Error::Unsafe),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(None),
        Err(_) => Err(failed("inspect")),
    }
}
pub fn validate(
    files: &Files,
    host: &mut impl Host,
    version: &str,
    filename: &str,
    configuration: &str,
) -> Result<(), Error> {
    let work = WorkDir::new().map_err(|_| failed("stage"))?;
    let pools = work.0.join("pool.d");
    fs::create_dir(&pools).map_err(|_| failed("stage"))?;
    let live = files.path(&format!("/etc/php/{version}/fpm/pool.d/{filename}"));
    for entry in fs::read_dir(live.parent().unwrap()).map_err(|_| failed("read"))? {
        let entry = entry.map_err(|_| failed("read"))?;
        if entry.file_name() == filename || entry.path().extension().is_none_or(|s| s != "conf") {
            continue;
        }
        metadata(&entry.path(), files.uid, false)?.ok_or(Error::Unsafe)?;
        fs::copy(entry.path(), pools.join(entry.file_name())).map_err(|_| failed("stage"))?;
    }
    fs::write(pools.join(filename), configuration).map_err(|_| failed("stage"))?;
    let main = live
        .parent()
        .unwrap()
        .parent()
        .unwrap()
        .join("php-fpm.conf");
    metadata(&main, files.uid, false)?.ok_or_else(|| failed("main"))?;
    let text = fs::read_to_string(main).map_err(|_| failed("read"))?;
    let expected = format!("/etc/php/{version}/fpm/pool.d/*.conf");
    let mut count = 0;
    let rewritten = text
        .split_inclusive('\n')
        .map(|line| {
            if line
                .trim()
                .split_once('=')
                .is_some_and(|(key, value)| key.trim() == "include" && value.trim() == expected)
            {
                count += 1;
                format!("include = {}/*.conf\n", pools.display())
            } else {
                line.into()
            }
        })
        .collect::<String>();
    if count != 1 {
        return Err(Error::Invalid);
    }
    let candidate = work.0.join("php-fpm.conf");
    fs::write(&candidate, rewritten).map_err(|_| failed("stage"))?;
    recipe::run(
        host,
        &Invocation::new(&[
            &format!("/usr/sbin/php-fpm{version}"),
            "--test",
            "--fpm-config",
            candidate.to_str().ok_or(Error::Invalid)?,
        ]),
        true,
    )
    .map_err(|_| failed("validate"))?;
    Ok(())
}
