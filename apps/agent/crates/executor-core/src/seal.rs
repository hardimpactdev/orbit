//! Secret output for the Gateway alone (ADR 0271): a libsodium sealed box to the X25519 key the Gateway names in the
//! signed order (`seal_to`). The receipt, the journal and the Gateway's step record hold only the sealed bytes; only the
//! Gateway's executor signing key opens them.

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use crypto_box::aead::OsRng;
use crypto_box::PublicKey;

/// The recipient or the plaintext could not be sealed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("seal.recipient_invalid")]
pub struct SealError;

/// Whether `recipient` is a base64 X25519 key that contributes to a key agreement: a fresh secret's shared secret with it is
/// not all zeros. A low-order point, such as all zeros, gives every secret the same all-zero shared secret, so a box sealed
/// to it would open with a key anyone knows.
pub fn contributory(recipient: &str) -> bool {
    let Ok(Ok(bytes)) = STANDARD.decode(recipient).map(<[u8; 32]>::try_from) else {
        return false;
    };
    let secret = crypto_box::SecretKey::generate(&mut OsRng);
    let shared = curve25519_dalek::MontgomeryPoint(bytes).mul_clamped(secret.to_bytes());
    shared.to_bytes() != [0; 32]
}

/// Seals `plaintext` to `recipient`, a base64 X25519 public key, and answers the sealed box in base64.
///
/// # Errors
///
/// [`SealError`] when the recipient is not 32 bytes of base64, or the box cannot be made.
pub fn seal(recipient: &str, plaintext: &[u8]) -> Result<String, SealError> {
    let bytes: [u8; 32] = STANDARD.decode(recipient).map_err(|_| SealError)?.try_into().map_err(|_| SealError)?;
    let sealed = PublicKey::from(bytes).seal(&mut OsRng, plaintext).map_err(|_| SealError)?;
    Ok(STANDARD.encode(sealed))
}

#[cfg(test)]
mod tests {
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine as _;
    use crypto_box::SecretKey;

    use super::{seal, SealError};

    /// The contract fixture: a box the Gateway's PHP test opens with the same fixed key (contract/fixtures/sealed-output.json).
    const FIXTURE: &str = include_str!("../../../contract/fixtures/sealed-output.json");

    fn fixture() -> serde_json::Value {
        serde_json::from_str(FIXTURE).expect("the fixture is JSON")
    }

    fn secret() -> SecretKey {
        let bytes: [u8; 32] = STANDARD.decode(fixture()["recipient_secret"].as_str().expect("a secret")).expect("base64").try_into().expect("32 bytes");
        SecretKey::from(bytes)
    }

    #[test]
    fn a_sealed_box_opens_only_with_the_recipients_key() {
        let recipient = STANDARD.encode(secret().public_key().as_bytes());
        let sealed = STANDARD.decode(seal(&recipient, b"session-token").expect("sealed")).expect("base64");

        assert_eq!(secret().unseal(&sealed).expect("opens"), b"session-token");
        assert!(SecretKey::from([7; 32]).unseal(&sealed).is_err(), "another key cannot open it");
    }

    #[test]
    fn the_shared_fixture_opens_with_the_fixture_key() {
        let sealed = STANDARD.decode(fixture()["sealed"].as_str().expect("a box")).expect("base64");
        assert_eq!(secret().unseal(&sealed).expect("opens"), fixture()["plaintext"].as_str().expect("text").as_bytes());
    }

    #[test]
    fn only_a_contributory_recipient_is_accepted() {
        assert!(super::contributory(&STANDARD.encode(secret().public_key().as_bytes())), "a real key");
        let low_order: [[u8; 32]; 4] = [
            [0; 32],
            {
                let mut one = [0; 32];
                one[0] = 1;
                one
            },
            // The two points of order 8 (RFC 7748's small-subgroup inputs).
            hex("e0eb7a7c3b41b8ae1656e3faf19fc46ada098deb9c32b1fd866205165f49b800"),
            hex("5f9c95bca3508c24b1d0b1559c83ef5b04445cc4581c8e86d8224eddd09f1157"),
        ];
        for point in low_order {
            assert!(!super::contributory(&STANDARD.encode(point)), "{point:?}");
        }
        assert!(!super::contributory("short"), "not 32 bytes");
        assert!(!super::contributory(&STANDARD.encode([1_u8; 31])), "31 bytes");
    }

    fn hex(text: &str) -> [u8; 32] {
        let bytes: Vec<u8> = (0..text.len()).step_by(2).map(|i| u8::from_str_radix(&text[i..i + 2], 16).expect("hex")).collect();
        bytes.try_into().expect("32 bytes")
    }

    #[test]
    fn a_recipient_that_is_not_a_key_is_refused() {
        assert_eq!(seal("short", b"x"), Err(SealError));
    }
}
