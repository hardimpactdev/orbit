//! Inspect existing macOS layouts without invoking a shell or changing Homebrew.
use super::*;

fn owned_directory(path: &Path, uid: u32) -> Result<bool> {
    Ok(metadata(path)?.is_some_and(|m| m.is_dir() && m.uid() == uid))
}

fn candidate(host: &mut impl Host, prefix: &Path, account: &Account) -> Result<bool> {
    let brew = prefix.join("bin/brew");
    let nested = prefix.join("Homebrew");
    // Ordinary /usr/local contents are not evidence of a Homebrew installation.
    if metadata(&brew)?.is_none()
        && metadata(&prefix.join(".git"))?.is_none()
        && metadata(&nested)?.is_none()
    {
        return Ok(false);
    }
    if !owned_directory(prefix, account.uid)? {
        return Err(Error::Conflict {});
    }
    let direct_repo = metadata(&prefix.join(".git"))?.is_some_and(|m| m.is_dir());
    let nested_repo = metadata(&nested)?.is_some_and(|m| m.is_dir())
        && metadata(&nested.join(".git"))?.is_some_and(|m| m.is_dir());
    if direct_repo == nested_repo {
        return Err(Error::Conflict {});
    }
    let brew_meta = metadata(&brew)?.ok_or(Error::Conflict {})?;
    let repo = if direct_repo {
        if !owned_directory(&prefix.join(".git"), account.uid)?
            || !brew_meta.is_file()
            || brew_meta.mode() & 0o111 == 0
            || brew_meta.uid() != account.uid
        {
            return Err(Error::Conflict {});
        }
        prefix
    } else {
        if !owned_directory(&nested, account.uid)?
            || !brew_meta.is_symlink()
            || brew_meta.uid() != account.uid
            || fs::read_link(&brew).ok().as_deref() != Some(Path::new("../Homebrew/bin/brew"))
        {
            return Err(Error::Conflict {});
        }
        &nested
    };
    let output = host
        .execute(
            &Invocation::new(&[
                "/usr/bin/git",
                "-C",
                repo.to_str().ok_or(Error::Invalid {})?,
                "config",
                "--get",
                "remote.origin.url",
            ])
            .env("HOME", account.home.to_str().ok_or(Error::Invalid {})?),
        )
        .map_err(|_| Error::Unreadable {})?;
    if output.code != 0
        || output.truncated
        || output.stdout.trim_end_matches(['\r', '\n']) != ORIGIN
    {
        return Err(Error::Conflict {});
    }
    Ok(true)
}

pub(super) fn inspect(host: &mut impl Host, files: &Files, account: &Account) -> Result<Output> {
    let home = account.home.to_str().ok_or(Error::Invalid {})?;
    if !home.starts_with('/')
        || home == "/"
        || home.ends_with('/')
        || home.contains("..")
        || !home
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b"/._-".contains(&b))
        || !account.home.is_dir()
    {
        return Err(Error::Unreadable {});
    }
    let mut found = None;
    for prefix in [
        files.path("/opt/homebrew"),
        files.path("/usr/local"),
        account.home.join("homebrew"),
        account.home.join(".homebrew"),
    ] {
        if candidate(host, &prefix, account)? {
            if found.is_some() {
                return Err(Error::Conflict {});
            }
            found = Some(prefix);
        }
    }
    Ok(Output {
        prefix: found.ok_or(Error::Absent {})?,
        home: account.home.clone(),
    })
}

#[cfg(test)]
mod tests;
