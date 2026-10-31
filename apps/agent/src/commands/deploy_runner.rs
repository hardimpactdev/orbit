//! Read-only absence proof for an interrupted native Project-step supervisor.
use crate::command::{Command, Context, Platform, Privilege};
use crate::command_errors;
use serde::{Deserialize, Serialize};
use std::{fs, io::Read, path::Path, time::Duration};

command_errors! {
    pub enum Error {
        Unconfirmed {} => "deployment.runner_probe_unconfirmed", "The deploy runner's absence could not be proven.";
    }
}

#[derive(schemars::JsonSchema, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Input {
    pub runner: String,
}
#[derive(schemars::JsonSchema, Debug, Serialize)]
pub struct Output {
    pub runner: String,
    pub gone: bool,
}
fn unconfirmed() -> Error {
    Error::Unconfirmed {}
}
fn bounded(path: &Path) -> Result<Vec<u8>, Error> {
    let mut bytes = Vec::new();
    fs::File::open(path)
        .and_then(|file| file.take(2 * 1024 * 1024 + 1).read_to_end(&mut bytes))
        .map_err(|_| unconfirmed())?;
    if bytes.len() > 2 * 1024 * 1024 {
        return Err(unconfirmed());
    }
    Ok(bytes)
}
fn inspect(proc: &Path, input: Input) -> Result<Output, Error> {
    // Match one complete argv/environment entry, never a process-name substring or a reusable PID.
    let pattern = regex::Regex::new(r"\Aorbit-deploy-step:[1-9][0-9]{0,15}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:(?:setup|teardown|before_activation|after_activation)\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z").map_err(|_| unconfirmed())?;
    if !pattern.is_match(&input.runner) {
        return Err(unconfirmed());
    }
    let mounts = bounded(&proc.join("mounts"))?;
    let mounts = std::str::from_utf8(&mounts).map_err(|_| unconfirmed())?;
    let options = mounts
        .lines()
        .find_map(|line| {
            let fields: Vec<_> = line.split_whitespace().collect();
            (fields.len() >= 4 && fields[1] == "/proc" && fields[2] == "proc").then(|| fields[3])
        })
        .ok_or_else(unconfirmed)?;
    if options
        .split(',')
        .any(|option| option.strip_prefix("hidepid=").is_some_and(|v| v != "0"))
    {
        return Err(unconfirmed());
    }
    let marker = input.runner.as_bytes();
    let inherited = format!("ORBIT_DEPLOY_STEP_RUNNER={}", input.runner);
    let mut count = 0;
    for entry in fs::read_dir(proc).map_err(|_| unconfirmed())? {
        let entry = entry.map_err(|_| unconfirmed())?;
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            return Err(unconfirmed());
        };
        if name.is_empty() || !name.bytes().all(|b| b.is_ascii_digit()) {
            continue;
        }
        count += 1;
        if count > 32768 {
            return Err(unconfirmed());
        }
        let path = entry.path();
        for (file, expected) in [("cmdline", marker), ("environ", inherited.as_bytes())] {
            let bytes = match bounded(&path.join(file)) {
                Ok(bytes) => bytes,
                // A racing exit is absent only if the whole process directory is gone.
                Err(_) if path.try_exists().is_ok_and(|exists| !exists) => break,
                Err(error) => return Err(error),
            };
            if !bytes.is_empty() && bytes.last() != Some(&0) {
                return Err(unconfirmed());
            }
            if bytes.split(|byte| *byte == 0).any(|arg| arg == expected) {
                return Ok(Output {
                    runner: input.runner.clone(),
                    gone: false,
                });
            }
        }
    }
    Ok(Output {
        runner: input.runner.clone(),
        gone: true,
    })
}

pub struct Inspect;
impl Command for Inspect {
    const NAME: &'static str = "instance.inspect-deploy-step";
    const SUMMARY: &'static str = "Prove whether an Operation/run-bound deploy step runner is gone without changing the Node.";
    const MUTATES: bool = false;
    const TIMEOUT: Duration = Duration::from_secs(30);
    type Input = Input;
    type Output = Output;
    type Error = Error;
    fn privilege(platform: Platform) -> Option<Privilege> {
        super::linux_only(platform, Privilege::Root)
    }
    fn run(input: Input, _: &Context) -> Result<Output, Error> {
        inspect(Path::new("/proc"), input)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    const RUNNER: &str =
        "orbit-deploy-step:100:ed7cfb7a-64f3-496f-8722-e3fd1c06947d:before_activation.proof";
    fn input() -> Input {
        Input {
            runner: RUNNER.into(),
        }
    }
    fn fixture() -> crate::commands::recipe::WorkDir {
        let dir = crate::commands::recipe::WorkDir::new().unwrap();
        fs::write(
            dir.0.as_path().join("mounts"),
            "proc /proc proc rw,hidepid=0 0 0\n",
        )
        .unwrap();
        dir
    }
    fn process(root: &Path, argv: &[u8], env: &[u8]) {
        fs::create_dir(root.join("123")).unwrap();
        fs::write(root.join("123/cmdline"), argv).unwrap();
        fs::write(root.join("123/environ"), env).unwrap();
    }
    #[test]
    fn exact_argv_and_inherited_marker_hold_but_absence_and_other_runs_do_not() {
        for (argv, env, gone) in [
            (
                format!("python3\0-I\0-c\0trusted runtime\0{RUNNER}\0"),
                String::new(),
                false,
            ),
            (
                "bash\0build\0".into(),
                format!("ORBIT_DEPLOY_STEP_RUNNER={RUNNER}\0"),
                false,
            ),
            (
                format!("prefix{RUNNER}\0"),
                format!("OTHER={RUNNER}\0"),
                true,
            ),
            (
                format!("{}\0", RUNNER.replace(":100:", ":101:")),
                String::new(),
                true,
            ),
        ] {
            let dir = fixture();
            process(dir.0.as_path(), argv.as_bytes(), env.as_bytes());
            assert_eq!(inspect(dir.0.as_path(), input()).unwrap().gone, gone);
        }
        assert!(inspect(fixture().0.as_path(), input()).unwrap().gone);
    }
    #[test]
    fn hidden_or_incomplete_reads_never_prove_absence() {
        for mount in ["proc /proc proc rw,hidepid=2 0 0", "invalid"] {
            let dir = fixture();
            fs::write(dir.0.as_path().join("mounts"), mount).unwrap();
            assert!(inspect(dir.0.as_path(), input()).is_err());
        }
        for data in [b"unterminated".to_vec(), vec![b'x'; 2 * 1024 * 1024 + 1]] {
            let dir = fixture();
            process(dir.0.as_path(), &data, b"");
            assert!(inspect(dir.0.as_path(), input()).is_err());
        }
        let dir = fixture();
        process(dir.0.as_path(), b"python3\0", b"");
        fs::remove_file(dir.0.as_path().join("123/environ")).unwrap();
        assert!(inspect(dir.0.as_path(), input()).is_err());
        assert!(inspect(
            dir.0.as_path(),
            Input {
                runner: "python".into()
            }
        )
        .is_err());
    }
    #[test]
    fn real_runner_is_seen_then_gone_without_signalling_it() {
        let mut child = std::process::Command::new("/usr/bin/python3")
            .args(["-I", "-c", "import sys; sys.stdin.buffer.read()", RUNNER])
            .stdin(std::process::Stdio::piped())
            .spawn()
            .unwrap();
        let dir = fixture();
        std::os::unix::fs::symlink(
            format!("/proc/{}", child.id()),
            dir.0.as_path().join(child.id().to_string()),
        )
        .unwrap();
        assert!(!inspect(dir.0.as_path(), input()).unwrap().gone);
        drop(child.stdin.take());
        assert!(child.wait().unwrap().success());
        assert!(inspect(dir.0.as_path(), input()).unwrap().gone);
    }
}
