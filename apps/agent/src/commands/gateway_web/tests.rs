use super::*;
use crate::commands::recipe::WorkDir;
fn fixture() -> (WorkDir, Files, Input) {
    let work = WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: rustix::process::getuid().as_raw(),
        gid: rustix::process::getgid().as_raw(),
    };
    fs::create_dir_all(files.path("/home/orbit")).unwrap();
    (
        work,
        files,
        Input {
            path: "/home/orbit/web".into(),
        },
    )
}
#[test]
fn creates_owned_directories_repairs_modes_and_repeats_without_changing_releases() {
    let (_work, files, input) = fixture();
    let owner = || Ok((files.uid, files.gid));
    assert!(prepare(&files, &input, owner).unwrap().changed);
    let web = files.path(&input.path);
    let child = web.join("releases/retained");
    fs::write(&child, "keep").unwrap();
    let inode = fs::metadata(&web).unwrap().ino();
    assert!(!prepare(&files, &input, owner).unwrap().changed);
    assert_eq!(fs::metadata(&web).unwrap().ino(), inode);
    for path in [&web, &web.join("releases")] {
        let meta = fs::metadata(path).unwrap();
        assert_eq!(
            (meta.uid(), meta.gid(), meta.mode() & 0o7777),
            (files.uid, files.gid, 0o750)
        );
        fs::set_permissions(path, fs::Permissions::from_mode(0o777)).unwrap();
    }
    assert!(prepare(&files, &input, owner).unwrap().changed);
    assert!(!prepare(&files, &input, owner).unwrap().changed);
    assert_eq!(fs::read_to_string(child).unwrap(), "keep");
}
#[test]
fn refuses_invalid_paths_before_resolving_accounts_or_writing() {
    for path in [
        "/var/www",
        "/home/orbit",
        "/home/orbit/",
        "/home/orbit/..",
        "/home/orbit/a/b",
        "/home/orbit/.hidden",
        "/home/orbit/web\nx",
        "/home/orbit/web\n",
        "/home/orbit/a/../b",
        "/home/orbit/a\0b",
    ] {
        let (_work, files, _) = fixture();
        assert_eq!(
            prepare(&files, &Input { path: path.into() }, || panic!(
                "invalid input resolved accounts"
            ))
            .unwrap_err(),
            Error::Invalid {}
        );
        assert_eq!(fs::read_dir(files.path("/home/orbit")).unwrap().count(), 0);
    }
}
#[test]
fn refuses_link_or_file_before_changing_either_directory() {
    for relative in ["/home/orbit/web", "/home/orbit/web/releases"] {
        for link in [true, false] {
            let (_work, files, input) = fixture();
            let path = files.path(relative);
            fs::create_dir_all(path.parent().unwrap()).unwrap();
            let outside = files.path("/outside");
            fs::create_dir(&outside).unwrap();
            if link {
                std::os::unix::fs::symlink(&outside, &path).unwrap();
            } else {
                fs::write(&path, "keep").unwrap();
            }
            let before = fs::metadata(path.parent().unwrap()).unwrap().mode();
            assert_eq!(
                prepare(&files, &input, || Ok((files.uid, files.gid))).unwrap_err(),
                Error::Failed {}
            );
            assert_eq!(fs::metadata(path.parent().unwrap()).unwrap().mode(), before);
            assert_eq!(fs::read_dir(outside).unwrap().count(), 0);
            assert!(fs::symlink_metadata(path).is_ok());
        }
    }
}
#[test]
fn missing_account_or_group_and_io_failure_leave_no_directory() {
    let (_work, files, input) = fixture();
    assert_eq!(
        prepare(&files, &input, || Err(Error::Failed {})).unwrap_err(),
        Error::Failed {}
    );
    assert!(!files.path(&input.path).exists());
    let home = files.path("/home/orbit");
    fs::set_permissions(&home, fs::Permissions::from_mode(0o500)).unwrap();
    let result = prepare(&files, &input, || Ok((files.uid, files.gid)));
    fs::set_permissions(&home, fs::Permissions::from_mode(0o700)).unwrap();
    assert_eq!(result.unwrap_err(), Error::Failed {});
    assert!(!files.path(&input.path).exists());
}
