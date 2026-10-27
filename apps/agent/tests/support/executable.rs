use std::{fs, path::Path, process::Command};

/// Only the installer opens the executable inode for writing. Parallel test forks may
/// inherit a write descriptor for the source, but that inode is never executed.
/// Closing, syncing, or renaming a file written in this process cannot ensure that.
pub fn executable(path: impl AsRef<Path>, content: impl AsRef<[u8]>) {
    let path = path.as_ref();
    let source = path.with_extension(format!("fixture-source-{:016x}", rand::random::<u64>()));
    fs::write(&source, content).unwrap();
    let result = Command::new("install")
        .args(["-m", "0755"])
        .arg(&source)
        .arg(path)
        .output();
    fs::remove_file(&source).unwrap();
    let output = result.unwrap();
    assert!(
        output.status.success(),
        "install {}: {}",
        path.display(),
        String::from_utf8_lossy(&output.stderr)
    );
}
