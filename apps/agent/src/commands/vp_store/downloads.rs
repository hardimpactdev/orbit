use sha2::{Digest, Sha224, Sha256, Sha384, Sha512};

fn digest(algorithm: &str, bytes: &[u8]) -> Option<Vec<u8>> {
    Some(match algorithm {
        "sha224" => Sha224::digest(bytes).to_vec(),
        "sha256" => Sha256::digest(bytes).to_vec(),
        "sha384" => Sha384::digest(bytes).to_vec(),
        "sha512" => Sha512::digest(bytes).to_vec(),
        _ => return None,
    })
}
pub(super) fn valid_pin(pin: &str) -> bool {
    pin.split_once('.').is_some_and(|(algorithm, value)| {
        digest(&algorithm.to_ascii_lowercase(), b"").is_some_and(|digest| {
            value.len() == digest.len() * 2 && value.bytes().all(|b| b.is_ascii_hexdigit())
        })
    })
}
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_project_pin_is_an_exact_supported_digest() {
        assert!(valid_pin(&format!("sha224.{}", "a".repeat(56))));
        assert!(!valid_pin("sha256.aa"));
        assert!(!valid_pin(&format!("sha256.{}", "z".repeat(64))));
    }
}
