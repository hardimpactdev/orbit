//! Removal of retired collector source. Callers select only these two code-owned locations.
use orbit_agent_contract::{command_errors, Command, Context, Platform, Privilege};
use serde::{Deserialize, Serialize};
use std::{io, path::Path, time::Duration};

pub struct RemoveSource;
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub retired: bool,
}
#[derive(Serialize, Debug, PartialEq)]
pub struct Output {
    pub removed: bool,
}
command_errors! {
    pub enum Error {
        Unsafe {} => "ai-router.source_unsafe", "The collector source directory has an unexpected type.";
        Failed {} => "ai-router.source_remove_failed", "The retired collector source could not be removed.";
    }
}
impl Command for RemoveSource {
    const NAME: &'static str = "ai-router.remove-source";
    const SUMMARY: &'static str = "Remove the code-owned retired collector source directory.";
    const MUTATES: bool = true;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        (platform == Platform::Linux).then_some(Privilege::Root)
    }
    fn target(_: &Input) -> Option<String> {
        Some("ai-router-source".into())
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        remove(Path::new("/var/lib/orbit"), input.retired)
    }
}
fn remove(root: &Path, retired: bool) -> Result<Output, Error> {
    for parent in root.ancestors() {
        match std::fs::symlink_metadata(parent) {
            Ok(metadata) if !metadata.is_dir() => return Err(Error::Unsafe {}),
            Ok(_) => {}
            Err(error) if error.kind() == io::ErrorKind::NotFound => {}
            Err(_) => return Err(Error::Failed {}),
        }
    }
    let path = root.join(if retired { "proxycli" } else { "ai-router" });
    match std::fs::symlink_metadata(&path) {
        Ok(metadata) if !metadata.is_dir() => Err(Error::Unsafe {}),
        Ok(_) => {
            std::fs::remove_dir_all(path).map_err(|_| Error::Failed {})?;
            Ok(Output { removed: true })
        }
        Err(error) if error.kind() == io::ErrorKind::NotFound => Ok(Output { removed: false }),
        Err(_) => Err(Error::Failed {}),
    }
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn removes_only_the_selected_source_and_repeats() {
        let root =
            std::env::temp_dir().join(format!("ai-router-source-{:x}", rand::random::<u128>()));
        for name in ["ai-router", "proxycli", "unrelated"] {
            std::fs::create_dir_all(root.join(name)).unwrap();
            std::fs::write(root.join(name).join("collector.py"), "old source").unwrap();
        }
        assert_eq!(remove(&root, false).unwrap(), Output { removed: true });
        assert_eq!(remove(&root, false).unwrap(), Output { removed: false });
        assert!(root.join("proxycli/collector.py").exists());
        assert_eq!(remove(&root, true).unwrap(), Output { removed: true });
        assert!(root.join("unrelated/collector.py").exists());
        std::os::unix::fs::symlink(root.join("unrelated"), root.join("ai-router")).unwrap();
        assert_eq!(remove(&root, false), Err(Error::Unsafe {}));
        assert!(root.join("unrelated/collector.py").exists());
        std::fs::remove_file(root.join("ai-router")).unwrap();
        std::fs::write(root.join("ai-router"), "unexpected").unwrap();
        assert_eq!(remove(&root, false), Err(Error::Unsafe {}));
        std::fs::remove_dir_all(root).unwrap();
    }
}
