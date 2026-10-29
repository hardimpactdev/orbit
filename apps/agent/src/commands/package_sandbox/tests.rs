use super::*;
use std::{
    cell::RefCell,
    os::unix::fs::{symlink, PermissionsExt},
    path::PathBuf,
};
fn package() -> Account {
    Account {
        uid: 998,
        gid: 998,
        home: "/nonexistent".into(),
        shell: "/usr/sbin/nologin".into(),
        group: USER.into(),
        groups: vec![998],
    }
}
struct AccountsFixture {
    package: Option<Account>,
    worker: Option<Account>,
    orbit: Option<Account>,
    created: bool,
    reads: RefCell<Vec<String>>,
}
impl Default for AccountsFixture {
    fn default() -> Self {
        Self {
            package: None,
            worker: Some(Account {
                uid: 1000,
                gid: 1000,
                home: "/worker".into(),
                shell: "/usr/sbin/nologin".into(),
                group: "worker".into(),
                groups: vec![1000],
            }),
            orbit: None,
            created: false,
            reads: RefCell::new(vec![]),
        }
    }
}
impl Accounts for AccountsFixture {
    fn lookup(&self, name: &str) -> Option<Account> {
        self.reads.borrow_mut().push(name.into());
        match name {
            USER => self.package.clone(),
            "worker" => self.worker.clone(),
            "orbit" => self.orbit.clone(),
            _ => None,
        }
    }
    fn create(&mut self) -> Result<(), Error> {
        assert!(!self.created);
        self.created = true;
        self.package = Some(package());
        Ok(())
    }
}
struct Tree(PathBuf);
impl Tree {
    fn new() -> Self {
        let root = std::env::temp_dir().join(format!(
            "orbit-package-account-{:016x}",
            rand::random::<u64>()
        ));
        fs::DirBuilder::new().mode(0o700).create(&root).unwrap();
        fs::create_dir(root.join("var")).unwrap();
        fs::create_dir(root.join("var/lib")).unwrap();
        Self(root)
    }
    fn state(&self) -> PathBuf {
        self.0.join(STATE.trim_start_matches('/'))
    }
    fn prepare(&self, accounts: &mut AccountsFixture) -> Result<Output, Error> {
        prepare(accounts, "worker", &self.0, identity::effective_uid())
    }
}
impl Drop for Tree {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}
#[test]
fn allocated_identity_and_root_custody_are_idempotent_without_touching_worker() {
    let tree = Tree::new();
    let mut a = AccountsFixture::default();
    let result = tree.prepare(&mut a).unwrap();
    assert_eq!(
        (result.uid, result.gid, result.account.as_str()),
        (998, 998, USER)
    );
    assert!(result.changed);
    for path in [tree.state(), tree.state().join("jobs")] {
        let m = fs::metadata(path).unwrap();
        assert_eq!(m.mode() & 0o7777, 0o700);
        assert_eq!(m.uid(), identity::effective_uid());
    }
    assert!(!tree.prepare(&mut a).unwrap().changed);
    assert_eq!(a.worker.as_ref().unwrap().uid, 1000);
    assert!(a
        .reads
        .borrow()
        .iter()
        .all(|name| [USER, "worker", "orbit"].contains(&name.as_str())));
}
#[test]
fn unsafe_identity_refuses_before_workspace_changes() {
    for mutation in 0..8 {
        let tree = Tree::new();
        let mut p = package();
        match mutation {
            0 => p.uid = 0,
            1 => p.uid = 1000,
            2 => p.gid = 1000,
            3 => p.home = "/home/orbit".into(),
            4 => p.shell = "/bin/sh".into(),
            5 => p.group = "sudo".into(),
            6 => p.groups.push(27),
            _ => p.groups.clear(),
        }
        let mut a = AccountsFixture {
            package: Some(p),
            ..Default::default()
        };
        assert_eq!(tree.prepare(&mut a), Err(Error::Account {}));
        assert!(!a.created);
        assert!(!tree.state().exists());
    }
}
#[test]
fn missing_or_root_worker_never_creates_a_package_account() {
    for uid in [None, Some(0)] {
        let tree = Tree::new();
        let mut a = AccountsFixture::default();
        if let Some(uid) = uid {
            a.worker.as_mut().unwrap().uid = uid;
        } else {
            a.worker = None;
        }
        assert_eq!(tree.prepare(&mut a), Err(Error::Worker {}));
        assert!(!a.created);
    }
    for name in [USER, "root;id", "/etc/passwd", "-worker", "worker\n"] {
        assert!(!valid_worker(name));
    }
}
#[test]
fn symlinks_and_writable_ancestors_refuse_before_account_creation() {
    let tree = Tree::new();
    let mut a = AccountsFixture::default();
    symlink("missing", tree.state()).unwrap();
    assert_eq!(tree.prepare(&mut a), Err(Error::Workspace {}));
    assert!(!a.created);
    fs::remove_file(tree.state()).unwrap();
    fs::set_permissions(tree.0.join("var"), fs::Permissions::from_mode(0o777)).unwrap();
    assert_eq!(tree.prepare(&mut a), Err(Error::Workspace {}));
    assert!(!a.created);
}
#[test]
fn existing_nonprivate_or_symlinked_jobs_are_not_repaired_or_reused() {
    let tree = Tree::new();
    let mut a = AccountsFixture::default();
    fs::DirBuilder::new()
        .mode(0o755)
        .create(tree.state())
        .unwrap();
    assert_eq!(tree.prepare(&mut a), Err(Error::Workspace {}));
    assert!(!a.created);
    fs::set_permissions(tree.state(), fs::Permissions::from_mode(0o700)).unwrap();
    symlink("/", tree.state().join("jobs")).unwrap();
    assert_eq!(tree.prepare(&mut a), Err(Error::Workspace {}));
    assert!(!a.created);
}

#[test]
fn package_uid_and_gid_must_both_differ_from_orbit() {
    for collision in ["uid", "gid"] {
        let tree = Tree::new();
        let mut orbit = package();
        if collision == "uid" {
            orbit.gid = 999;
        } else {
            orbit.uid = 999;
        }
        let mut accounts = AccountsFixture {
            package: Some(package()),
            orbit: Some(orbit),
            ..Default::default()
        };
        assert_eq!(tree.prepare(&mut accounts), Err(Error::Account {}));
        assert!(!accounts.created);
        assert!(!tree.state().exists());
    }
}
