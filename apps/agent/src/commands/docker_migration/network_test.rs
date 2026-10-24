//! Opt-in real-engine test in private network, mount and PID namespaces.
use super::*;
use std::{
    os::unix::process::CommandExt,
    process::{Child, Command as Process, Stdio},
    time::Instant,
};

struct Engine<'a> {
    native: Native<'a>,
    files: &'a Files,
    daemon: Option<Child>,
}
impl Engine<'_> {
    fn stop(&mut self) {
        if let Some(mut child) = self.daemon.take() {
            // Each disposable daemon owns a process group inside the private PID namespace.
            unsafe {
                libc::kill(-(child.id() as i32), libc::SIGTERM);
            }
            let until = Instant::now() + Duration::from_secs(15);
            while Instant::now() < until {
                if child.try_wait().unwrap().is_some() {
                    return;
                }
                std::thread::sleep(Duration::from_millis(100));
            }
            unsafe {
                libc::kill(-(child.id() as i32), libc::SIGKILL);
            }
            child.wait().unwrap();
        }
    }
    fn start_at(&mut self, fresh: bool) {
        self.stop();
        let journal = self.files.path(JOURNAL);
        let data = journal.join(if fresh { "engine" } else { "original" });
        let config = journal.join(if fresh {
            "daemon.json"
        } else {
            "original-daemon.json"
        });
        if !fresh {
            fs::write(
                &config,
                serde_json::to_vec(&json!({"data-root":data,"log-driver":"json-file"})).unwrap(),
            )
            .unwrap();
        }
        let log = fs::File::create(journal.join(if fresh { "fresh.log" } else { "original.log" }))
            .unwrap();
        let mut process = Process::new("dockerd");
        process.args([
            format!("--config-file={}", config.display()),
            format!("--host=unix://{}", journal.join("docker.sock").display()),
            format!("--exec-root={}", journal.join("exec").display()),
            format!("--pidfile={}", journal.join("docker.pid").display()),
        ]);
        process.args([
            "--bridge=none",
            "--iptables=false",
            "--ip6tables=false",
            "--ip-forward=false",
            "--ip-masq=false",
            "--storage-driver=vfs",
        ]);
        if fresh {
            process.arg(format!("--data-root={}", data.display()));
        }
        self.daemon = Some(
            process
                .process_group(0)
                .stdout(log.try_clone().unwrap())
                .stderr(log)
                .spawn()
                .unwrap(),
        );
        let until = Instant::now() + Duration::from_secs(25);
        while Instant::now() < until {
            if get(&mut self.native, "/info").is_ok() {
                return;
            }
            assert!(
                self.daemon.as_mut().unwrap().try_wait().unwrap().is_none(),
                "Disposable Docker daemon exited"
            );
            std::thread::sleep(Duration::from_millis(100));
        }
        panic!("Disposable Docker daemon did not become ready");
    }
}
impl Drop for Engine<'_> {
    fn drop(&mut self) {
        self.stop();
    }
}
impl Host for Engine<'_> {
    fn execute(
        &mut self,
        invocation: &Invocation,
    ) -> std::result::Result<recipe::Output, recipe::Failure> {
        let args = &invocation.argv;
        assert_eq!(
            args[0], "systemctl",
            "Recovery may only use its service boundary in this sandbox"
        );
        match args[1].as_str() {
            "show" => {
                return Ok(recipe::Output {
                    stdout: "loaded".into(),
                    ..Default::default()
                })
            }
            "stop" => self.stop(),
            "start" => self.start_at(true),
            "mask" | "daemon-reload" => {}
            _ => panic!("Unexpected service operation {args:?}"),
        }
        Ok(recipe::Output::default())
    }
    fn wait(&mut self, delay: Duration) -> std::result::Result<(), recipe::Failure> {
        self.native.wait(delay)
    }
}
impl DockerHost for Engine<'_> {
    fn api(
        &mut self,
        method: &str,
        path: &str,
        body: Option<&Value>,
        missing: bool,
    ) -> Result<Value> {
        self.native.api(method, path, body, missing)
    }
    fn free_space(&mut self, path: &Path) -> Result<u64> {
        self.native.free_space(path)
    }
}
struct Peer(Child);
impl Drop for Peer {
    fn drop(&mut self) {
        let _ = self.0.kill();
        let _ = self.0.wait();
    }
}
#[test]
#[ignore = "requires root and disposable Docker namespace support; run only on SPIKE-1"]
fn bridge_remains_reachable_after_fresh_root_and_resume() {
    const TEST:&str="commands::docker_migration::network_test::bridge_remains_reachable_after_fresh_root_and_resume";
    assert_eq!(
        rustix::process::geteuid().as_raw(),
        0,
        "Run this opt-in test as root on SPIKE-1"
    );
    let context = Context::new(Platform::Linux, Instant::now() + Duration::from_secs(180));
    if std::env::var("ORBIT_DOCKER_NETWORK_SANDBOX").as_deref() != Ok("1") {
        let mut invocation = Invocation::new(&[
            "unshare",
            "--net",
            "--mount",
            "--pid",
            "--fork",
            "--kill-child",
            "--mount-proc",
            "--propagation",
            "private",
            &std::env::current_exe().unwrap().to_string_lossy(),
            "--exact",
            TEST,
            "--ignored",
            "--nocapture",
        ]);
        invocation.environment = vec![("ORBIT_DOCKER_NETWORK_SANDBOX".into(), "1".into())];
        for (key, path) in [("NET", "/proc/self/ns/net"), ("MNT", "/proc/self/ns/mnt")] {
            invocation.environment.push((
                format!("ORBIT_DOCKER_NETWORK_PARENT_{key}"),
                fs::read_link(path).unwrap().to_string_lossy().into(),
            ));
        }
        let result = recipe::run(&mut recipe::Native(&context), &invocation, true).unwrap();
        println!("{}", result.stdout);
        return;
    }
    for (key, path) in [("NET", "/proc/self/ns/net"), ("MNT", "/proc/self/ns/mnt")] {
        assert_ne!(
            fs::read_link(path).unwrap().to_string_lossy(),
            std::env::var(format!("ORBIT_DOCKER_NETWORK_PARENT_{key}")).unwrap()
        );
    }
    let run = |args: &[&str]| {
        let mut invocation = Invocation::new(args);
        invocation.timeout = Duration::from_secs(15);
        recipe::run(&mut recipe::Native(&context), &invocation, true)
            .unwrap()
            .stdout
    };
    run(&["mount", "-t", "tmpfs", "-o", "mode=755", "tmpfs", "/run"]);
    run(&["ip", "link", "set", "lo", "up"]);
    let work = recipe::WorkDir::new().unwrap();
    let files = Files {
        root: work.0.clone(),
        uid: 0,
        gid: 0,
    };
    private_journal(&files, true).unwrap();
    let journal = files.path(JOURNAL);
    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .unwrap();
    let client = {
        let _entered = runtime.enter();
        reqwest::Client::builder()
            .unix_socket(journal.join("docker.sock"))
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .unwrap()
    };
    let mut engine = Engine {
        native: Native {
            commands: recipe::Native(&context),
            context: &context,
            runtime,
            client,
        },
        files: &files,
        daemon: None,
    };
    engine.start_at(false);
    let created=engine.api("POST","/networks/create",Some(&json!({"Name":"app-network","Driver":"bridge","IPAM":{"Config":[{"Subnet":"172.30.0.0/24","Gateway":"172.30.0.1"}]}})),false).unwrap();
    let network = get(&mut engine, &format!("/networks/{}", text(&created["Id"]))).unwrap();
    let bridge = format!("br-{}", &text(&network["Id"])[..12]);
    let links: Value = serde_json::from_str(&run(&["ip", "-j", "link", "show", &bridge])).unwrap();
    let index = links[0]["ifindex"].clone();
    let state:State=serde_json::from_value(json!({"recipe":"test","containers":[],"volumes":[],"networks":[network],"images":[],"image_refs":{},"driver":null,"metadata_roots":{},"defaults":{},"archives":{},"committed":{},"stage":"fallback","daemon":hex(&fs::read(journal.join("original-daemon.json")).unwrap()),"fallback":true,"swapped":true,"prepared":false,"stopped":true})).unwrap();
    engine.stop();
    assert!(run(&["ip", "route", "show", "172.30.0.0/24"]).contains(&bridge));
    recovery::fresh(&mut engine, &files, &state).unwrap();
    let fresh = get(&mut engine, "/networks/app-network").unwrap();
    let fresh_bridge = fresh["Options"]["com.docker.network.bridge.name"]
        .as_str()
        .map(str::to_string)
        .unwrap_or_else(|| format!("br-{}", &text(&fresh["Id"])[..12]));
    let peer = Peer(
        Process::new("unshare")
            .args(["--net", "sleep", "120"])
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
            .unwrap(),
    );
    let pid = peer.0.id().to_string();
    let until = Instant::now() + Duration::from_secs(5);
    while fs::read_link(format!("/proc/{pid}/ns/net")).unwrap()
        == fs::read_link("/proc/self/ns/net").unwrap()
    {
        assert!(Instant::now() < until);
        std::thread::sleep(Duration::from_millis(10));
    }
    run(&[
        "ip",
        "link",
        "add",
        "app-port",
        "type",
        "veth",
        "peer",
        "name",
        "app-endpoint",
    ]);
    run(&["ip", "link", "set", "app-port", "master", &fresh_bridge]);
    run(&["ip", "link", "set", "app-port", "up"]);
    run(&["ip", "link", "set", "app-endpoint", "netns", &pid]);
    run(&[
        "nsenter",
        "-t",
        &pid,
        "-n",
        "ip",
        "addr",
        "add",
        "172.30.0.2/24",
        "dev",
        "app-endpoint",
    ]);
    run(&[
        "nsenter",
        "-t",
        &pid,
        "-n",
        "ip",
        "link",
        "set",
        "app-endpoint",
        "up",
    ]);
    for resumed in [false, true] {
        if resumed {
            recovery::fresh(&mut engine, &files, &state).unwrap();
        }
        run(&["ping", "-n", "-c", "1", "-W", "2", "172.30.0.2"]);
        let route: Value =
            serde_json::from_str(&run(&["ip", "-j", "route", "get", "172.30.0.2"])).unwrap();
        assert_eq!(route[0]["dev"], bridge);
        let links: Value =
            serde_json::from_str(&run(&["ip", "-j", "link", "show", &bridge])).unwrap();
        assert_eq!(links[0]["ifindex"], index);
        let routes: Value =
            serde_json::from_str(&run(&["ip", "-j", "route", "show", "172.30.0.0/24"])).unwrap();
        assert_eq!(array(&routes).len(), 1);
        assert_eq!(routes[0]["dev"], bridge);
        println!("resumed={resumed}: ping succeeded through original {bridge}, interface {index}");
    }
    assert_eq!(
        get(&mut engine, "/info").unwrap()["DockerRootDir"],
        json!(journal.join("engine"))
    );
    engine.start_at(false);
    assert_eq!(
        get(&mut engine, "/info").unwrap()["DockerRootDir"],
        json!(journal.join("original"))
    );
    assert_eq!(
        get(&mut engine, "/networks/app-network").unwrap()["Id"],
        state.networks[0]["Id"]
    );
}
