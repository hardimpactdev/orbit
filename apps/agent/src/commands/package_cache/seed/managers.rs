//! Real package managers consume the native seed and keep all subsequent writes private.
use super::{tests::Fixture, *};
use serde_json::{json, Value};
use std::process::{Command as Process, Stdio};

fn json_file(path: &Path, value: Value) {
    fs::write(path, serde_json::to_vec(&value).unwrap()).unwrap();
}

fn run(program: &str, arguments: &[&str], checkout: &Path, home: &Path) {
    fs::create_dir_all(home).unwrap();
    let stdout = fs::File::create(checkout.join("stdout")).unwrap();
    let stderr = fs::File::create(checkout.join("stderr")).unwrap();
    let mut child = Process::new(program)
        .args(arguments)
        .current_dir(checkout)
        .env("HOME", home)
        .env("npm_config_cache", home.join(".npm"))
        .env("BUN_INSTALL_CACHE_DIR", home.join(".bun/install/cache"))
        .env("COMPOSER_CACHE_DIR", home.join(".cache/composer"))
        .env("COMPOSER_HOME", home.join(".composer"))
        .env("COMPOSER_DISABLE_NETWORK", "1")
        .env("HTTP_PROXY", "http://127.0.0.1:1")
        .env("HTTPS_PROXY", "http://127.0.0.1:1")
        .env("NO_PROXY", "127.0.0.1,localhost")
        .stdin(Stdio::null())
        .stdout(stdout)
        .stderr(stderr)
        .spawn()
        .unwrap_or_else(|e| panic!("{program} must be installed for package cache tests: {e}"));
    let deadline = std::time::Instant::now() + Duration::from_secs(30);
    let status = loop {
        if let Some(status) = child.try_wait().unwrap() {
            break status;
        }
        if std::time::Instant::now() >= deadline {
            child.kill().unwrap();
            child.wait().unwrap();
            panic!("{program} timed out");
        }
        std::thread::sleep(Duration::from_millis(10));
    };
    assert!(
        status.success(),
        "{program}: {}\n{}",
        fs::read_to_string(checkout.join("stdout")).unwrap(),
        fs::read_to_string(checkout.join("stderr")).unwrap()
    );
}

fn package(name: &str, root: &str) -> Vec<u8> {
    let encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::fast());
    let mut tar = tar::Builder::new(encoder);
    for (name, bytes) in [
        (
            format!("{root}/package.json"),
            serde_json::to_vec(
                &json!({"name":name,"version":"1.2.3","scripts":{"postinstall":"exit 33"}}),
            )
            .unwrap(),
        ),
        (format!("{root}/proof.txt"), b"installed".to_vec()),
    ] {
        let mut header = tar::Header::new_gnu();
        header.set_mode(0o644);
        header.set_size(bytes.len() as u64);
        header.set_cksum();
        tar.append_data(&mut header, name, bytes.as_slice())
            .unwrap();
    }
    tar.into_inner().unwrap().finish().unwrap()
}

fn artifact(name: &str, bytes: &[u8]) -> Artifact {
    let url = format!(
        "https://registry.npmjs.org/{name}/-/{}-1.2.3.tgz",
        name.rsplit('/').next().unwrap()
    );
    let integrity = format!("sha512-{}", STANDARD.encode(sha2::Sha512::digest(bytes)));
    Artifact {
        manager: "npm".into(),
        package: name.into(),
        version: "1.2.3".into(),
        key: format!(
            "npm/{}.tgz",
            hex(&Sha256::digest(format!("{url} {integrity}")))
        ),
        url,
        integrity: Some(integrity),
    }
}

struct Bytes<'a>(&'a [u8]);
impl Fetch for Bytes<'_> {
    fn download(&mut self, _: &str, target: &Path, _: u64) -> Result<u64, String> {
        fs::write(target, self.0).unwrap();
        Ok(self.0.len() as u64)
    }
}

fn javascript(manager: &str, hit: bool, registry: Option<&str>) {
    let f = Fixture::new();
    let name = if hit { "@acme/demo" } else { "demo" };
    let bytes = package(name, "package");
    let a = artifact(name, &bytes);
    if hit {
        assert!(fill(
            &f.shared(),
            std::slice::from_ref(&a),
            100_000,
            &mut Bytes(&bytes)
        )
        .unwrap()
        .failures
        .is_empty());
    } else {
        initialize(&f.shared()).unwrap();
    }
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    let before = inventory(&f.shared()).unwrap();
    let checkout = f.0.join("checkout");
    fs::create_dir(&checkout).unwrap();
    let manifest = json!({"name":"test","version":"1.0.0","dependencies":{name:"1.2.3"}});
    json_file(&checkout.join("package.json"), manifest.clone());
    // Bun includes registry identity in cache keys. Keep its public identity on a hit;
    // the child has an unreachable external proxy so it cannot download missing bytes.
    if let Some(registry) = registry {
        fs::write(checkout.join(".npmrc"), format!("registry={registry}/\n")).unwrap();
    }
    let url = if hit {
        a.url.clone()
    } else {
        format!("{}/demo/-/demo-1.2.3.tgz", registry.unwrap())
    };
    let arguments = if manager == "npm" {
        json_file(
            &checkout.join("package-lock.json"),
            json!({"name":"test","version":"1.0.0","lockfileVersion":3,"packages":{"":manifest,format!("node_modules/{name}"):{"version":"1.2.3","resolved":url,"integrity":a.integrity}}}),
        );
        if hit {
            vec![
                "ci",
                "--offline",
                "--ignore-scripts",
                "--no-audit",
                "--no-fund",
            ]
        } else {
            vec!["ci", "--ignore-scripts", "--no-audit", "--no-fund"]
        }
    } else {
        if hit {
            json_file(
                &checkout.join("bun.lock"),
                json!({"lockfileVersion":1,"configVersion":1,"workspaces":{"":{"name":"test","dependencies":{name:"1.2.3"}}},"packages":{name:[format!("{name}@1.2.3"),"",{},a.integrity]}}),
            );
            vec!["install", "--frozen-lockfile", "--ignore-scripts"]
        } else {
            vec!["install", "--ignore-scripts"]
        }
    };
    run(manager, &arguments, &checkout, &f.home());
    let installed = checkout.join(format!("node_modules/{name}/proof.txt"));
    assert_eq!(fs::read(&installed).unwrap(), b"installed");
    fs::write(installed, "private change").unwrap();
    assert_eq!(inventory(&f.shared()).unwrap(), before);
    if hit {
        assert_eq!(fs::read(f.shared().join(&a.key)).unwrap(), bytes);
    } else {
        assert!(!f.shared().join(&a.key).exists());
    }
}

#[test]
fn npm_consumes_seeded_archives_offline_without_shared_writes() {
    javascript("npm", true, None);
}

#[test]
fn bun_consumes_seeded_archives_offline_without_shared_writes() {
    javascript("bun", true, None);
}

#[test]
fn named_root_scoped_archives_seed_bun() {
    let f = Fixture::new();
    let bytes = package("@types/demo", "demo");
    let a = artifact("@types/demo", &bytes);
    fill(
        &f.shared(),
        std::slice::from_ref(&a),
        100_000,
        &mut Bytes(&bytes),
    )
    .unwrap();
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    assert_eq!(
        fs::read(
            f.home()
                .join(".bun/install/cache/@types/demo@1.2.3@@@1/proof.txt")
        )
        .unwrap(),
        b"installed"
    );
    assert_eq!(fs::read(f.shared().join(&a.key)).unwrap(), bytes);
}

async fn registry_miss(manager: &'static str) {
    use axum::{routing::get, Router};
    use std::sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    };
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let base = format!("http://{}", listener.local_addr().unwrap());
    let bytes = package("demo", "package");
    let a = artifact("demo", &bytes);
    let metadata = json!({"name":"demo","dist-tags":{"latest":"1.2.3"},"versions":{"1.2.3":{"name":"demo","version":"1.2.3","dist":{"tarball":format!("{base}/demo/-/demo-1.2.3.tgz"),"integrity":a.integrity}}}});
    let requests = Arc::new(AtomicUsize::new(0));
    let count = requests.clone();
    let router = Router::new()
        .route("/demo", get(move || async move { axum::Json(metadata) }))
        .route(
            "/demo/-/demo-1.2.3.tgz",
            get(move || async move {
                count.fetch_add(1, Ordering::SeqCst);
                bytes
            }),
        );
    let server = tokio::spawn(async move {
        axum::serve(listener, router).await.unwrap();
    });
    let result = tokio::task::spawn_blocking(move || javascript(manager, false, Some(&base))).await;
    server.abort();
    result.unwrap();
    assert!(requests.load(Ordering::SeqCst) > 0);
}

#[tokio::test]
async fn npm_downloads_misses_without_shared_writes() {
    registry_miss("npm").await;
}

#[tokio::test]
async fn bun_downloads_misses_without_shared_writes() {
    registry_miss("bun").await;
}

#[test]
fn composer_consumes_seeded_archives_and_downloads_misses_without_shared_writes() {
    let f = Fixture::new();
    let bytes = include_bytes!("composer.zip");
    let a = super::super::tests::composer(bytes);
    fill(
        &f.shared(),
        std::slice::from_ref(&a),
        100_000,
        &mut Bytes(bytes),
    )
    .unwrap();
    seed(&f.shared(), &f.home(), std::slice::from_ref(&a)).unwrap();
    let archive = f.0.join("demo.zip");
    fs::write(&archive, bytes).unwrap();
    for case in ["hit", "miss"] {
        let checkout = f.0.join(case);
        fs::create_dir(&checkout).unwrap();
        let url = if case == "hit" {
            a.url.clone()
        } else {
            format!("file://{}", archive.display())
        };
        json_file(
            &checkout.join("composer.json"),
            json!({"require":{"acme/demo":"1.0.0"}}),
        );
        json_file(
            &checkout.join("composer.lock"),
            json!({"content-hash":"fixture","packages":[{"name":"acme/demo","version":"1.0.0","type":"library","dist":{"type":"zip","url":url,"shasum":hex(&sha1::Sha1::digest(bytes))}}],"packages-dev":[]}),
        );
        run(
            "composer",
            &[
                "install",
                "--no-interaction",
                "--no-plugins",
                "--no-scripts",
                "--no-progress",
            ],
            &checkout,
            &f.home(),
        );
        let installed = checkout.join("vendor/acme/demo/proof.txt");
        assert_eq!(fs::read(&installed).unwrap(), b"installed");
        fs::write(installed, "private change").unwrap();
    }
    assert_eq!(fs::read(f.shared().join(&a.key)).unwrap(), bytes);
}
