#[path = "src/updater_pubkey.rs"]
mod updater_pubkey;

fn main() {
    emit_orbit_version();
    emit_updater_pubkey();
    tauri_build::build();
}

fn emit_updater_pubkey() {
    let config_path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tauri.conf.json");
    println!("cargo:rerun-if-changed={}", config_path.display());
    println!("cargo:rerun-if-env-changed=TAURI_CONFIG");

    let base = std::fs::read_to_string(&config_path).expect("tauri.conf.json must be readable");
    let overlay = std::env::var("TAURI_CONFIG").ok();
    let pubkey = updater_pubkey::resolve_tauri_updater_pubkey(&base, overlay.as_deref())
        .filter(|key| !key.is_empty())
        .expect("Tauri updater pubkey must be present in tauri.conf.json or TAURI_CONFIG");

    println!("cargo:rustc-env=ORBIT_UPDATER_PUBKEY={pubkey}");
}

fn emit_orbit_version() {
    let version_path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../VERSION");
    println!("cargo:rerun-if-changed={}", version_path.display());

    let version = std::fs::read_to_string(&version_path)
        .expect("root VERSION must be readable")
        .trim()
        .to_string();

    if version.is_empty() {
        panic!("root VERSION is empty");
    }

    println!("cargo:rustc-env=ORBIT_VERSION={version}");

    if std::env::var("ORBIT_NATIVE_RELEASE_BUILD").ok().as_deref() == Some("1")
        && version != env!("CARGO_PKG_VERSION")
    {
        panic!(
            "native release build requires Cargo.toml version {version}, found {}",
            env!("CARGO_PKG_VERSION")
        );
    }
}
