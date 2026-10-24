//! Text and JSON bounds shared by the native Tool manager readers.
pub(super) fn safe_text(value: &str) -> bool {
    !value.is_empty() && value.len() <= 255 && !value.bytes().any(|b| b < 32 || b == 127)
}
pub(super) fn lines(text: &str) -> impl Iterator<Item = &str> {
    text.split([
        '\n', '\r', '\u{000b}', '\u{000c}', '\u{0085}', '\u{2028}', '\u{2029}',
    ])
}
// Count containers without recursing or allocating ignored package metadata. The JSON
// decoder below checks syntax; this pass preserves the existing inventory depth bounds.
pub(super) fn json_depth(data: &[u8]) -> usize {
    let (mut current, mut maximum, mut quoted, mut escaped) = (0usize, 0usize, false, false);
    for byte in data {
        if quoted {
            if escaped {
                escaped = false;
            } else if *byte == b'\\' {
                escaped = true;
            } else if *byte == b'"' {
                quoted = false;
            }
        } else {
            match byte {
                b'"' => quoted = true,
                b'[' | b'{' => {
                    current += 1;
                    maximum = maximum.max(current);
                }
                b']' | b'}' => current = current.saturating_sub(1),
                _ => {}
            }
        }
    }
    maximum
}
