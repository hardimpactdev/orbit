#[path = "src/updater_pubkey.rs"]
mod updater_pubkey;

fn main() {
    emit_orbit_version();
    emit_updater_pubkey();
    ensure_tauri_context_icon();
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

fn ensure_tauri_context_icon() {
    let icon_path = std::path::Path::new("icons").join("icon.png");

    if icon_path.exists() {
        return;
    }

    std::fs::create_dir_all("icons").expect("failed to create Orbit macOS icon directory");
    std::fs::write(icon_path, png_icon()).expect("failed to write Orbit macOS icon");
}

fn png_icon() -> Vec<u8> {
    const SIZE: u32 = 18;

    let mut rgba = Vec::with_capacity(((SIZE * 4 + 1) * SIZE) as usize);
    let center = (SIZE as f32 - 1.0) / 2.0;

    for y in 0..SIZE {
        rgba.push(0);

        for x in 0..SIZE {
            let dx = x as f32 - center;
            let dy = y as f32 - center;
            let distance = (dx * dx + dy * dy).sqrt();
            let alpha = if distance <= 5.0 || (6.5..=8.0).contains(&distance) {
                255
            } else {
                0
            };

            rgba.extend_from_slice(&[0, 0, 0, alpha]);
        }
    }

    let mut png = Vec::new();
    png.extend_from_slice(&[137, 80, 78, 71, 13, 10, 26, 10]);

    let mut ihdr = Vec::with_capacity(13);
    ihdr.extend_from_slice(&SIZE.to_be_bytes());
    ihdr.extend_from_slice(&SIZE.to_be_bytes());
    ihdr.extend_from_slice(&[8, 6, 0, 0, 0]);
    write_chunk(&mut png, b"IHDR", &ihdr);
    write_chunk(&mut png, b"IDAT", &zlib_stored_blocks(&rgba));
    write_chunk(&mut png, b"IEND", &[]);

    png
}

fn zlib_stored_blocks(bytes: &[u8]) -> Vec<u8> {
    let mut output = vec![0x78, 0x01];
    let mut remaining = bytes;

    while !remaining.is_empty() {
        let block_len = remaining.len().min(u16::MAX as usize);
        let is_final = block_len == remaining.len();
        let len = block_len as u16;
        let nlen = !len;

        output.push(if is_final { 1 } else { 0 });
        output.extend_from_slice(&len.to_le_bytes());
        output.extend_from_slice(&nlen.to_le_bytes());
        output.extend_from_slice(&remaining[..block_len]);

        remaining = &remaining[block_len..];
    }

    output.extend_from_slice(&adler32(bytes).to_be_bytes());

    output
}

fn write_chunk(png: &mut Vec<u8>, kind: &[u8; 4], data: &[u8]) {
    png.extend_from_slice(&(data.len() as u32).to_be_bytes());
    png.extend_from_slice(kind);
    png.extend_from_slice(data);

    let mut crc_input = Vec::with_capacity(kind.len() + data.len());
    crc_input.extend_from_slice(kind);
    crc_input.extend_from_slice(data);
    png.extend_from_slice(&crc32(&crc_input).to_be_bytes());
}

fn adler32(bytes: &[u8]) -> u32 {
    const MOD_ADLER: u32 = 65_521;

    let mut a = 1;
    let mut b = 0;

    for byte in bytes {
        a = (a + u32::from(*byte)) % MOD_ADLER;
        b = (b + a) % MOD_ADLER;
    }

    (b << 16) | a
}

fn crc32(bytes: &[u8]) -> u32 {
    let mut crc = 0xffff_ffff;

    for byte in bytes {
        crc ^= u32::from(*byte);

        for _ in 0..8 {
            let mask = 0u32.wrapping_sub(crc & 1);
            crc = (crc >> 1) ^ (0xedb8_8320 & mask);
        }
    }

    !crc
}
