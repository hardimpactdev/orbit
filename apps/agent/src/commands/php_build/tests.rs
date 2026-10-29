use super::*;
use sha2::{Digest, Sha256};
use std::{os::unix::fs::symlink, time::Duration};

mod downloads;
mod image;
mod installation;
mod inventory;
mod profile;
mod runtime;

const MODULES: &str = "bcmath\ncurl\ngd\nimagick\nintl\nmbstring\nmysqli\npdo_mysql\npdo_pgsql\npdo_sqlite\nredis\nsimplexml\nxml\nzip";
const UNIT: &str = "php8.5-fpm.service";
const DEDICATED: &str = "orbit-example-php8.5-fpm.service";

fn bytes(name: &str, version: &str, arch: &str) -> Vec<u8> {
    format!("Package: {name}\nVersion: {version}\nArchitecture: {arch}\n").into_bytes()
}
fn pin(name: &str, version: &str) -> Pin {
    Pin {
        name: name.into(),
        version: version.into(),
        architecture: "amd64".into(),
        url: format!("https://packages.example/{name}_{version}.deb"),
        sha256: format!("{:x}", Sha256::digest(bytes(name, version, "amd64"))),
    }
}
fn write(files: &Files, path: &str, content: impl AsRef<[u8]>) -> PathBuf {
    let path = files.path(path);
    fs::create_dir_all(path.parent().unwrap()).unwrap();
    fs::write(&path, content).unwrap();
    path
}
fn module_link(files: &Files, sapi: &str, module: &str) -> PathBuf {
    let path = files.path(&format!("/etc/php/8.5/{sapi}/conf.d/20-{module}.ini"));
    fs::create_dir_all(path.parent().unwrap()).unwrap();
    if !path.is_symlink() {
        symlink(format!("../../mods-available/{module}.ini"), &path).unwrap();
    }
    path
}

struct Fixture {
    _work: recipe::WorkDir,
    files: Files,
    input: Input,
    host: Fake,
}
impl Fixture {
    fn new() -> Self {
        let work = recipe::WorkDir::new().unwrap();
        let files = || Files {
            root: work.0.clone(),
            uid: rustix::process::getuid().as_raw(),
            gid: rustix::process::getgid().as_raw(),
        };
        let pins = vec![pin("php8.5-cli", "2.0"), pin("php8.5-fpm", "2.0")];
        let input = Input {
            versions: BTreeMap::from([(
                "8.5".into(),
                Build {
                    packages: pins.clone(),
                    extensions: MODULES.lines().map(str::to_owned).collect(),
                },
            )]),
            mirror: "https://mirror.example".into(),
            pcov: false,
            opentelemetry: false,
            extras: Extras::Refuse,
            fpm: Fpm::All,
            repack: Repack {
                package: "dpkg-repack".into(),
                version: "1.0".into(),
            },
            release: Some(Release {
                id: "ubuntu".into(),
                codenames: vec!["resolute".into()],
            }),
        };
        let host = Fake {
            files: files(),
            packages: Installed::new(),
            calls: vec![],
            artifacts: pins
                .iter()
                .flat_map(|p| {
                    let data = bytes(&p.name, &p.version, "amd64");
                    [
                        (p.url.clone(), data.clone()),
                        (
                            format!("https://mirror.example/{}_{}.deb", p.name, p.sha256),
                            data,
                        ),
                    ]
                })
                .collect(),
            arch: "amd64".into(),
            owned: BTreeSet::new(),
            damaged: BTreeMap::new(),
            active: BTreeSet::new(),
            enabled: BTreeMap::new(),
            invalid: BTreeSet::new(),
            fail: BTreeMap::new(),
            cli_extra: String::new(),
            fpm_extra: String::new(),
            ini_overrides: BTreeMap::new(),
            ping: true,
            pings: vec![],
            revive: false,
            empty_repack: false,
        };
        let files = files();
        for path in [
            "/usr/sbin",
            "/etc/apt/sources.list.d",
            "/etc/php/8.5/cli/conf.d",
            "/etc/php/8.5/fpm/conf.d",
            "/etc/php/8.5/mods-available",
        ] {
            fs::create_dir_all(files.path(path)).unwrap();
        }
        write(
            &files,
            "/etc/os-release",
            "ID=ubuntu\nVERSION_CODENAME=resolute\n",
        );
        write(
            &files,
            "/etc/php/8.5/fpm/php-fpm.conf",
            "include=/etc/php/8.5/fpm/pool.d/*.conf\n",
        );
        Self {
            _work: work,
            files,
            input,
            host,
        }
    }
    fn current(&mut self) {
        self.host.packages = self.input.versions["8.5"]
            .packages
            .iter()
            .map(|p| (p.name.clone(), p.version.clone()))
            .collect();
    }
    fn old(&mut self) {
        for name in ["php8.5-cli", "php8.5-fpm"] {
            self.host.packages.insert(name.into(), "1.0".into());
            let source = write(
                &self.files,
                &format!("/old/{name}.deb"),
                bytes(name, "1.0", "amd64"),
            );
            packages::keep(&self.files, &source, name).unwrap();
        }
    }
    fn apply(&mut self) -> Result<operations::Report> {
        operations::apply(
            &mut self.host,
            &self.files,
            &self.input,
            Instant::now() + Duration::from_secs(30),
        )
    }
    fn inspect(&mut self) -> Result<operations::Preparation> {
        operations::prepare(&mut self.host, &self.files, &self.input)
    }
    fn fail(&mut self, prefix: &str, times: usize) {
        self.host.fail.insert(prefix.into(), times);
    }
    fn calls(&self, program: &str) -> Vec<&Invocation> {
        self.host
            .calls
            .iter()
            .filter(|c| c.argv[0] == program)
            .collect()
    }
}

struct Fake {
    files: Files,
    packages: Installed,
    calls: Vec<Invocation>,
    artifacts: BTreeMap<String, Vec<u8>>,
    arch: String,
    owned: BTreeSet<PathBuf>,
    damaged: BTreeMap<String, String>,
    active: BTreeSet<String>,
    enabled: BTreeMap<String, String>,
    invalid: BTreeSet<String>,
    fail: BTreeMap<String, usize>,
    cli_extra: String,
    fpm_extra: String,
    ini_overrides: BTreeMap<String, String>,
    ping: bool,
    pings: Vec<PathBuf>,
    revive: bool,
    empty_repack: bool,
}
impl RecipeHost for Fake {
    fn execute(&mut self, call: &Invocation) -> std::result::Result<Output, recipe::Failure> {
        self.calls.push(call.clone());
        let a = &call.argv;
        let mut out = Output::default();
        let failure = self.fail.iter_mut().any(|(prefix, left)| {
            if *left > 0 && a.join(" ").starts_with(prefix.as_str()) {
                *left -= 1;
                true
            } else {
                false
            }
        });
        match a[0].as_str() {
            "dpkg" if a[1] == "--print-architecture" => out.stdout = self.arch.clone(),
            "dpkg-query" => {
                out.stdout = self
                    .packages
                    .iter()
                    .map(|(n, v)| format!("{n}\tinstall ok installed\t{v}\n"))
                    .collect()
            }
            "dpkg" if a[1] == "--verify" => {
                out.stdout = self.damaged.get(&a[3]).cloned().unwrap_or_default()
            }
            "dpkg" if a[1] == "-S" => {
                out.stdout = a[3..]
                    .iter()
                    .filter(|p| self.owned.contains(Path::new(p)))
                    .map(|p| format!("php8.5-common: {p}\n"))
                    .collect();
                out.code = 1;
            }
            "dpkg" if a[1] == "--remove" || a[1] == "--purge" => {
                if !failure {
                    for name in &a[3..] {
                        self.packages.remove(name);
                    }
                }
            }
            "curl" => {
                for args in a[6..].split(|s| s == "--next") {
                    let path = &args[args.iter().position(|s| s == "--output").unwrap() + 1];
                    let url = &args[args.iter().position(|s| s == "--url").unwrap() + 1];
                    let code = if let Some(bytes) = self.artifacts.get(url) {
                        fs::write(path, bytes).unwrap();
                        0
                    } else {
                        22
                    };
                    out.stdout.push_str(
                        &serde_json::json!({"filename_effective":path,"exitcode":code}).to_string(),
                    );
                    out.stdout.push('\n');
                    out.code = out.code.max(code);
                }
            }
            "dpkg-deb" if a[1] == "--field" => out.stdout = fs::read_to_string(&a[2]).unwrap(),
            "dpkg-deb" if a[1] == "--build" => {
                fs::copy(Path::new(&a[3]).join("DEBIAN/control"), &a[4]).unwrap();
            }
            "env" if a[3] == "dpkg-repack" => {
                if !self.empty_repack {
                    let name = a.last().unwrap();
                    let tree = Path::new(&a[2]).join(format!("dpkg-repack.{name}.fixture"));
                    fs::create_dir_all(tree.join("DEBIAN")).unwrap();
                    fs::write(
                        tree.join("DEBIAN/control"),
                        bytes(name, &self.packages[name], "amd64"),
                    )
                    .unwrap();
                    fs::write(
                        tree.join("DEBIAN/conffiles"),
                        "/etc/php/8.5/fpm/pool.d/www.conf\n",
                    )
                    .unwrap();
                }
            }
            "apt-get" => {
                if let Some(index) = a.iter().position(|s| s == "--") {
                    assert!(self.files.path("/usr/sbin/policy-rc.d").exists());
                    assert!(a.contains(&"--no-remove".into()));
                    for path in &a[index + 1..] {
                        let fields: BTreeMap<_, _> = fs::read_to_string(path)
                            .unwrap()
                            .lines()
                            .map(|s| {
                                let (k, v) = s.split_once(": ").unwrap();
                                (k.to_owned(), v.to_owned())
                            })
                            .collect();
                        self.packages
                            .insert(fields["Package"].clone(), fields["Version"].clone());
                        if fields["Package"].ends_with("-fpm") {
                            write(
                                &self.files,
                                "/etc/php/8.5/fpm/pool.d/www.conf",
                                "listen=/run/php/php8.5-fpm.sock\n",
                            );
                        }
                        if self.revive && fields["Version"] == "1.0" {
                            self.packages.insert("php8.5-added".into(), "2.0".into());
                        }
                    }
                } else {
                    assert!(a.last().unwrap().starts_with("dpkg-repack="));
                    if !failure {
                        self.packages.insert("dpkg-repack".into(), "1.0".into());
                    }
                }
            }
            "phpenmod" | "phpdismod" => {
                let path = self.files.path(&format!(
                    "/etc/php/{}/{}/conf.d/20-{}.ini",
                    a[2], a[4], a[5]
                ));
                if a[0] == "phpenmod" {
                    module_link(&self.files, &a[4], &a[5]);
                } else {
                    remove(&path).unwrap();
                }
            }
            "systemctl" => {
                let unit = a.last().unwrap();
                match a[1].as_str() {
                    "is-active" => out.code = if self.active.contains(unit) { 0 } else { 3 },
                    "is-enabled" => out.stdout = self.enabled.get(unit).cloned().unwrap_or_else(|| "disabled".into()),
                    "list-units" => out.stdout = self.active.iter().filter(|u| u.starts_with("orbit-"))
                        .map(|u| format!("{u} loaded active running\n")).collect(),
                    "show" => out.stdout = "{ path=/usr/sbin/php-fpm8.5 ; argv[]=/usr/sbin/php-fpm8.5 --fpm-config /etc/orbit/example.conf ; }".into(),
                    "restart" | "reload" | "reload-or-restart" => {},
                    "enable" => { self.enabled.insert(unit.clone(), "enabled".into()); },
                    "disable" | "stop" if !failure => {
                        self.active.remove(unit);
                        if a[1] == "disable" { self.enabled.insert(unit.clone(), "disabled".into()); }
                    }
                    "disable" | "stop" => {},
                    _ => panic!("Unexpected systemctl {a:?}"),
                }
            }
            executable
                if executable.ends_with("/php8.5") || executable.ends_with("/php-fpm8.5") =>
            {
                if a[1] == "-t" {
                    let unit = if a.len() > 2 { DEDICATED } else { UNIT };
                    if self.invalid.contains(unit) {
                        out.code = 78;
                    }
                } else if a[1] == "-v" {
                    out.stdout = "PHP 8.5".into();
                } else if a[1] == "-i" {
                    out.stdout = format!("{}\npost_max_size => 64M => 64M\nupload_max_filesize => 64M => 64M\nopcache.enable => On => On\nopcache.memory_consumption => 512 => 512\nopcache.interned_strings_buffer => 64 => 64\nopcache.max_accelerated_files => 65407 => 65407\nopcache.jit => disable => disable\nopcache.jit_buffer_size => 0 => 0\n", self.files.path("/etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini").display());
                    for (key, value) in &self.ini_overrides {
                        out.stdout = out.stdout.replace(
                            &format!("{key} => 64M => 64M"),
                            &format!("{key} => {value} => {value}"),
                        );
                    }
                } else {
                    assert_eq!(a[1], "-m");
                    let sapi = if executable.ends_with("/php8.5") {
                        "cli"
                    } else {
                        "fpm"
                    };
                    out.stdout = MODULES.into();
                    for name in ["pcov", "opentelemetry"] {
                        if self
                            .files
                            .path(&format!("/etc/php/8.5/{sapi}/conf.d/20-{name}.ini"))
                            .is_symlink()
                        {
                            out.stdout.push_str(&format!("\n{name}"));
                        }
                    }
                    out.stdout.push_str(&format!(
                        "\n{}",
                        if sapi == "cli" {
                            &self.cli_extra
                        } else {
                            &self.fpm_extra
                        }
                    ));
                }
            }
            _ => panic!("Unexpected call {a:?}"),
        }
        if failure {
            out.code = 2;
        }
        Ok(out)
    }
    fn wait(&mut self, _: Duration) -> std::result::Result<(), recipe::Failure> {
        panic!("No retry waits expected")
    }
}
impl Host for Fake {
    fn ping(&mut self, socket: &Path, _: Instant) -> bool {
        self.pings.push(socket.into());
        self.ping
    }
}
