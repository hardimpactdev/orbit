fn main() {
    ensure_tauri_context_icon();
    tauri_build::build();
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
