//! `route.publish_site`: one Node's part of a Route, in the order of the former site phase (docs/cli/route/create.mmd).

use std::fs::{self, File};
use std::io::{Read as _, Write as _};
use std::os::unix::fs::{fchown, MetadataExt as _, OpenOptionsExt as _, PermissionsExt as _};
use std::path::{Path, PathBuf};
use std::sync::LazyLock;

use regex::Regex;
use serde::Deserialize;

use crate::{strings, BlobRef, Failure, System};

const CERTIFICATES: &str = "/etc/caddy/orbit-certificates";
const TRUST: &str = "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt";
const CADDYFILE: &str = "/etc/caddy/Caddyfile";

static NAME: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[a-z_][a-z0-9_-]{0,31}$").expect("a valid pattern"));
static VERSION: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9]\.[0-9]{1,2}$").expect("a valid pattern"));
static SCOPE: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[A-Za-z0-9][A-Za-z0-9._-]*$").expect("a valid pattern"));
static CERTIFICATE_VERSION: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^[0-9a-f]{16}$").expect("a valid pattern"));

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Input {
    account: String,
    #[serde(default)]
    acl: Vec<Acl>,
    #[serde(default)]
    url_lines: Vec<UrlLine>,
    #[serde(default)]
    links: Vec<Link>,
    #[serde(default)]
    php: Vec<Pool>,
    #[serde(default)]
    leaves: Vec<Leaf>,
    root_certificate: Option<BlobRef>,
    caddy: Option<BlobRef>,
}

/// One Caddy access change. Each kind is one command over all its paths, as `RouteWebRootCommands` renders it.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Acl {
    kind: AclKind,
    user: Option<String>,
    paths: Vec<String>,
    #[serde(default)]
    optional_paths: Vec<String>,
}

#[derive(Debug, Clone, Copy, Deserialize)]
#[serde(rename_all = "snake_case")]
enum AclKind {
    /// Caddy gets no default access to new directories of a checkout.
    DenyDefaults,
    /// Caddy is denied the whole checkout.
    Deny,
    /// Caddy may pass through the directories above the served roots.
    Passage,
    /// New directories of the served roots are readable to Caddy.
    GrantDefaults,
    /// Caddy may read the served roots.
    Grant,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct UrlLine {
    path: String,
    url: String,
    owner: String,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Link {
    path: String,
    target: String,
    owner: String,
}

/// A PHP-FPM pool: the shared development pool of a version, or a production account's own.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Pool {
    version: String,
    user: Option<String>,
    content: BlobRef,
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Leaf {
    scope: String,
    version: String,
    certificate: BlobRef,
}

/// Places the Route's files on this Node and returns how many changed. Commands come from the typed input only.
///
/// Order: Caddy access, `APP_URL`, links, PHP-FPM pools (write, test, then reload a changed pool or start a stopped one),
/// leaves and their account copies, the Orbit root's trust, and the Caddyfile: written as a candidate, validated as the
/// `caddy` user, moved in place only when it differs, then Caddy reloads when anything it serves changed. A failed
/// validation leaves the live Caddyfile as it was. There is no rollback.
///
/// # Errors
///
/// `route.input_invalid` for an input outside the admitted shape, `route.content_missing` for a blob the executor did not
/// read, and `route.publish_failed` for any failed change.
pub fn publish_site(system: &impl System, input: &serde_json::Value) -> Result<u32, Failure> {
    let input: Input = serde_json::from_value(input.clone()).map_err(|error| Failure::new("route.input_invalid", error.to_string()))?;
    validate(&input)?;
    let failed = |detail: String| Failure::new("route.publish_failed", detail);
    let account = system.account(&input.account).map_err(failed)?;
    let mut site = Site { system, changes: 0 };
    let root = system.superuser();

    for acl in &input.acl {
        site.access(acl)?;
    }
    for line in &input.url_lines {
        site.url_line(line)?;
    }
    for link in &input.links {
        let owner = system.account(&link.owner).map_err(failed)?;
        site.link(&link.path, &link.target, owner.uid)?;
    }
    let mut pools = Vec::new();
    for pool in &input.php {
        let content = pool.content.text(system)?.replace("__ORBIT_NODE_GROUP__", &account.gid.to_string()).replace("/__ORBIT_NODE_HOME__", &account.home);
        site.file(&pool_path(pool), content.as_bytes(), root, root, 0o644)?;
        pools.push(pool);
    }
    for pool in &pools {
        system.run(30, &pool_test(pool)).map_err(failed)?;
    }
    for pool in &pools {
        site.php_unit(&pool_unit(pool))?;
    }

    let caddy = system.group("caddy").map_err(failed)?;
    for leaf in &input.leaves {
        site.leaf(leaf, &input.account, &account, caddy)?;
    }
    if let Some(root_certificate) = &input.root_certificate {
        if site.file(TRUST, root_certificate.text(system)?.as_bytes(), root, root, 0o644)? {
            system.run(60, &strings(&["/usr/sbin/update-ca-certificates"])).map_err(failed)?;
        }
    }
    // Caddy reloads on every publication, as the Swamp site phase did: a repeat after a crash between the move and the
    // reload must still make the files live.
    if let Some(caddyfile) = &input.caddy {
        site.caddyfile(caddyfile.text(system)?.as_bytes(), caddy)?;
        system.run(60, &strings(&["/usr/bin/systemctl", "reload", "caddy"])).map_err(failed)?;
    }
    Ok(site.changes)
}

fn validate(input: &Input) -> Result<(), Failure> {
    let path = |value: &String| value.starts_with('/') && !value.contains("/../") && !value.ends_with("/..") && !value.contains(['\n', '\0']);
    let valid = NAME.is_match(&input.account)
        && input.acl.iter().all(|acl| {
            acl.user.as_ref().map_or(matches!(acl.kind, AclKind::Passage), |user| NAME.is_match(user) && !matches!(acl.kind, AclKind::Passage))
                && !acl.paths.is_empty()
                && acl.paths.iter().all(path)
                && acl.optional_paths.iter().all(|optional| acl.paths.contains(optional))
        })
        && input.url_lines.iter().all(|line| {
            path(&line.path) && line.path.ends_with("/.env") && NAME.is_match(&line.owner) && !line.url.contains(['\n', '\0'])
        })
        && input.links.iter().all(|link| path(&link.path) && NAME.is_match(&link.owner) && !link.target.is_empty() && !link.target.contains(['\n', '\0']))
        && input.php.iter().all(|pool| VERSION.is_match(&pool.version) && pool.user.as_ref().is_none_or(|user| NAME.is_match(user)))
        && input.leaves.iter().all(|leaf| SCOPE.is_match(&leaf.scope) && CERTIFICATE_VERSION.is_match(&leaf.version));
    if valid {
        Ok(())
    } else {
        Err(Failure::new("route.input_invalid", "field outside its admitted shape"))
    }
}

fn pool_path(pool: &Pool) -> String {
    match &pool.user {
        Some(user) => format!("/etc/orbit/php-fpm/{user}/generated/pool.conf"),
        None => format!("/etc/php/{}/fpm/pool.d/orbit-scopes.conf", pool.version),
    }
}

fn pool_test(pool: &Pool) -> Vec<String> {
    let version = &pool.version;
    match &pool.user {
        Some(user) => vec![
            "/usr/bin/env".to_owned(),
            format!("PHP_INI_SCAN_DIR=/etc/php/{version}/fpm/conf.d:/etc/orbit/php-fpm/{user}/generated"),
            format!("/usr/sbin/php-fpm{version}"),
            "-t".to_owned(),
            "--fpm-config".to_owned(),
            format!("/etc/orbit/php-fpm/{user}/generated/php-fpm.conf"),
        ],
        None => vec![format!("/usr/sbin/php-fpm{version}"), "-t".to_owned()],
    }
}

fn pool_unit(pool: &Pool) -> String {
    match &pool.user {
        Some(user) => format!("orbit-{user}-php{}-fpm.service", pool.version),
        None => format!("php{}-fpm.service", pool.version),
    }
}

/// The access command of one kind, as `RouteWebRootCommands` renders it.
fn access_argv(kind: AclKind, user: Option<&str>, paths: &[String]) -> Vec<String> {
    let mut argv = user.map_or_else(Vec::new, |user| strings(&["/usr/sbin/runuser", "-u", user, "--", "/usr/bin/env", "-C", "/"]));
    let (find, acl) = match kind {
        AclKind::DenyDefaults => (true, "d:u:caddy:---"),
        AclKind::Deny => (false, "u:caddy:---"),
        AclKind::Passage => (false, "u:caddy:--x"),
        AclKind::GrantDefaults => (true, "d:u:caddy:r-x"),
        AclKind::Grant => (false, "u:caddy:r-X"),
    };
    if find {
        argv.extend(strings(&["/usr/bin/find", "-P"]));
        argv.extend(paths.iter().cloned());
        argv.extend(strings(&["-type", "d", "-exec", "/usr/bin/setfacl", "-m", acl, "--", "{}", "+"]));
    } else if matches!(kind, AclKind::Passage) {
        argv.extend(strings(&["/usr/bin/setfacl", "-m", acl, "--"]));
        argv.extend(paths.iter().cloned());
    } else {
        argv.extend(strings(&["/usr/bin/setfacl", "-P", "-R", "-n", "-m", acl, "--"]));
        argv.extend(paths.iter().cloned());
    }
    argv
}

struct Site<'a, S: System> {
    system: &'a S,
    changes: u32,
}

impl<S: System> Site<'_, S> {
    #[expect(clippy::needless_pass_by_value, reason = "map_err hands each error over by value")]
    fn failed(detail: impl ToString) -> Failure {
        Failure::new("route.publish_failed", detail.to_string())
    }

    /// One access command. A missing optional path (a Laravel storage folder that does not exist yet) is left out, never
    /// made; a command left with no path runs nothing. ACL changes are not counted: setfacl does not say whether it changed.
    fn access(&mut self, acl: &Acl) -> Result<(), Failure> {
        let paths: Vec<String> =
            acl.paths.iter().filter(|path| !acl.optional_paths.contains(path) || self.system.path(path).exists()).cloned().collect();
        if paths.is_empty() {
            return Ok(());
        }
        self.system.run(120, &access_argv(acl.kind, acl.user.as_deref(), &paths)).map(|_| ()).map_err(Self::failed)
    }

    /// `APP_URL=` in an existing `.env`: the last such line is replaced, or the line is appended. The file keeps its owner
    /// and mode, and a missing file fails, as the former `lineinfile` did. The `.env` may be a link, but the file it reaches
    /// must belong to the Route's account: a link to a file of another user, such as root's, is refused.
    fn url_line(&mut self, line: &UrlLine) -> Result<(), Failure> {
        let real = fs::canonicalize(self.system.path(&line.path)).map_err(Self::failed)?;
        let owner = self.system.account(&line.owner).map_err(Self::failed)?;
        if fs::metadata(&real).map_err(Self::failed)?.uid() != owner.uid {
            return Err(Self::failed(format!("{} is not the account's own file", line.path)));
        }
        let text = fs::read_to_string(&real).map_err(Self::failed)?;
        let wanted = format!("APP_URL={}", line.url);
        let mut lines: Vec<&str> = text.lines().collect();
        match lines.iter().rposition(|existing| existing.starts_with("APP_URL=")) {
            Some(index) if lines[index] == wanted => return Ok(()),
            Some(index) => lines[index] = &wanted,
            None => lines.push(&wanted),
        }
        let metadata = fs::metadata(&real).map_err(Self::failed)?;
        let mut content = lines.join("\n");
        content.push('\n');
        replace(&real, content.as_bytes(), metadata.uid(), metadata.gid(), metadata.mode() & 0o7777).map_err(Self::failed)?;
        self.changes += 1;
        Ok(())
    }

    /// A link to `target`, replaced atomically when it differs; an empty directory in its place is removed, a full one fails.
    fn link(&mut self, value: &str, target: &str, uid: u32) -> Result<(), Failure> {
        let dest = self.system.path(value);
        let current = fs::symlink_metadata(&dest).ok();
        let points = current.as_ref().is_some_and(|meta| meta.file_type().is_symlink()) && fs::read_link(&dest).is_ok_and(|link| link == Path::new(target));
        if !points {
            if current.as_ref().is_some_and(fs::Metadata::is_dir) {
                fs::remove_dir(&dest).map_err(Self::failed)?;
            }
            let parent = dest.parent().ok_or_else(|| Self::failed("no parent"))?;
            let temporary = parent.join(format!(".orbit-route-link-{}", std::process::id()));
            let _ = fs::remove_file(&temporary);
            std::os::unix::fs::symlink(target, &temporary).map_err(Self::failed)?;
            fs::rename(&temporary, &dest).map_err(Self::failed)?;
            self.changes += 1;
        }
        if fs::symlink_metadata(&dest).map_err(Self::failed)?.uid() != uid {
            self.system.lchown(&dest, uid, None).map_err(Self::failed)?;
            self.changes += 1;
        }
        Ok(())
    }

    /// Writes a file only when its bytes differ, and repairs owner and mode on an unchanged one. Answers whether it changed.
    fn file(&mut self, value: &str, content: &[u8], uid: u32, gid: u32, mode: u32) -> Result<bool, Failure> {
        let dest = self.system.path(value);
        if let Ok(meta) = fs::symlink_metadata(&dest) {
            if meta.file_type().is_file() {
                let mut stream = fs::OpenOptions::new().read(true).custom_flags(libc_nofollow()).open(&dest).map_err(Self::failed)?;
                let mut current = Vec::new();
                stream.read_to_end(&mut current).map_err(Self::failed)?;
                if current == content {
                    let drift = (meta.uid(), meta.gid(), meta.mode() & 0o7777) != (uid, gid, mode);
                    if drift {
                        fchown(&stream, Some(uid), Some(gid)).map_err(Self::failed)?;
                        stream.set_permissions(fs::Permissions::from_mode(mode)).map_err(Self::failed)?;
                        self.changes += 1;
                    }
                    return Ok(drift);
                }
            }
        }
        replace(&dest, content, uid, gid, mode).map_err(Self::failed)?;
        self.changes += 1;
        Ok(true)
    }

    /// Enables the unit, starts a stopped one and reloads a running one, every time, as the Swamp site phase did: a repeat
    /// after a crash between the write and the reload must still load the pool. A reload keeps open requests.
    fn php_unit(&mut self, unit: &str) -> Result<(), Failure> {
        let systemctl = |args: &[&str]| self.system.run(30, &[vec!["/usr/bin/systemctl".to_owned()], strings(args)].concat());
        if systemctl(&["is-enabled", "--quiet", unit]).is_err() {
            systemctl(&["enable", unit]).map_err(Self::failed)?;
            self.changes += 1;
        }
        if systemctl(&["is-active", "--quiet", unit]).is_err() {
            systemctl(&["start", unit]).map_err(Self::failed)?;
            self.changes += 1;
        } else {
            systemctl(&["reload", unit]).map_err(Self::failed)?;
        }
        Ok(())
    }

    /// The signed leaf beside its key, the version's `current` link, and the account's own copy of key and certificate.
    fn leaf(&mut self, leaf: &Leaf, user: &str, account: &crate::Account, caddy: u32) -> Result<(), Failure> {
        let scope = format!("{CERTIFICATES}/{}", leaf.scope);
        let directory = format!("{scope}/versions/{}", leaf.version);
        let target = format!("versions/{}", leaf.version);
        let certificate = leaf.certificate.text(self.system)?;
        let root = self.system.superuser();
        self.file(&format!("{directory}/cert.pem"), certificate.as_bytes(), root, caddy, 0o644)?;
        self.link(&format!("{scope}/current"), &target, root)?;
        let copy = format!("{}/.orbit/certificates/{}", account.home, leaf.scope);
        // The key is copied on the Node; its bytes never enter the reply or the log.
        let mut argv = strings(&["/usr/bin/install", "-D", "-o", user, "-g", &account.gid.to_string(), "-m", "0600", "-t"]);
        argv.push(format!("{copy}/{target}"));
        argv.push(format!("{directory}/key.pem"));
        argv.push(format!("{directory}/cert.pem"));
        self.system.run(30, &argv).map_err(|_| Self::failed("account copy"))?;
        self.link(&format!("{copy}/current"), &target, account.uid)
    }

    /// Validates the candidate as `caddy` and moves it over the live file only when content or metadata differ.
    fn caddyfile(&mut self, content: &[u8], caddy: u32) -> Result<(), Failure> {
        let live = self.system.path(CADDYFILE);
        let candidate = format!("/etc/caddy/.orbit-candidate-{}", std::process::id());
        let path = self.system.path(&candidate);
        let root = self.system.superuser();
        let result = (|| {
            replace(&path, content, root, caddy, 0o640).map_err(Self::failed)?;
            let mut argv = strings(&["/usr/sbin/runuser", "-u", "caddy", "--", "/usr/bin/caddy", "validate", "--config"]);
            argv.push(candidate.clone());
            argv.extend(strings(&["--adapter", "caddyfile"]));
            self.system.run(60, &argv).map_err(|_| Self::failed("caddy validation"))?;
            let same = fs::symlink_metadata(&live).is_ok_and(|meta| {
                meta.file_type().is_file() && (meta.uid(), meta.gid(), meta.mode() & 0o7777) == (root, caddy, 0o640)
            }) && fs::read(&live).is_ok_and(|current| current == content);
            if !same {
                fs::rename(&path, &live).map_err(Self::failed)?;
                self.changes += 1;
            }
            Ok(())
        })();
        let _ = fs::remove_file(&path);
        result
    }
}

/// Writes `content` with its owner and mode to a new file beside `dest`, then moves it in place.
fn replace(dest: &Path, content: &[u8], uid: u32, gid: u32, mode: u32) -> std::io::Result<()> {
    let parent = dest.parent().unwrap_or_else(|| Path::new("/"));
    let temporary: PathBuf = parent.join(format!(".orbit-route-{}-{}", std::process::id(), dest.file_name().map_or_else(String::new, |name| name.to_string_lossy().into_owned())));
    // A temporary file a stopped run left behind under the same name goes first.
    let _ = fs::remove_file(&temporary);
    let result = (|| {
        let mut stream = File::options().write(true).create_new(true).mode(0o600).open(&temporary)?;
        fchown(&stream, Some(uid), Some(gid))?;
        stream.set_permissions(fs::Permissions::from_mode(mode))?;
        stream.write_all(content)?;
        stream.sync_all()?;
        fs::rename(&temporary, dest)
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

/// `O_NOFOLLOW`: a link in a file's place is replaced, never followed.
const fn libc_nofollow() -> i32 {
    0o400_000
}
