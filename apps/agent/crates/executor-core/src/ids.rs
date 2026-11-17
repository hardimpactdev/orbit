//! Newtypes for the identifiers an order carries.

use std::fmt;
use std::str::FromStr;

use serde::{Deserialize, Serialize};

/// The longest identifier an order may carry.
const MAX_ID_LEN: usize = 128;

/// Why an identifier is not well formed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[non_exhaustive]
pub enum ParseIdError {
    /// The identifier is empty.
    #[error("identifier is empty")]
    Empty,
    /// The identifier is longer than 128 bytes.
    #[error("identifier is longer than {MAX_ID_LEN} bytes")]
    TooLong,
    /// The identifier holds a byte outside `[A-Za-z0-9._:-]`.
    #[error("identifier holds a character outside [A-Za-z0-9._:-]")]
    BadCharacter,
    /// The nonce is not 32 lowercase hex digits.
    #[error("nonce is not 32 lowercase hex digits")]
    BadNonce,
}

fn check_id(raw: &str) -> Result<(), ParseIdError> {
    if raw.is_empty() {
        return Err(ParseIdError::Empty);
    }
    if raw.len() > MAX_ID_LEN {
        return Err(ParseIdError::TooLong);
    }
    if !raw
        .bytes()
        .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b':' | b'-'))
    {
        return Err(ParseIdError::BadCharacter);
    }
    Ok(())
}

macro_rules! id_type {
    ($(#[$doc:meta])* $name:ident) => {
        $(#[$doc])*
        #[derive(Debug, Clone, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
        #[serde(try_from = "String", into = "String")]
        pub struct $name(String);

        impl $name {
            /// The identifier as text.
            pub fn as_str(&self) -> &str {
                &self.0
            }
        }

        impl FromStr for $name {
            type Err = ParseIdError;

            fn from_str(raw: &str) -> Result<Self, Self::Err> {
                check_id(raw)?;
                Ok(Self(raw.to_owned()))
            }
        }

        impl TryFrom<String> for $name {
            type Error = ParseIdError;

            fn try_from(raw: String) -> Result<Self, Self::Error> {
                check_id(&raw)?;
                Ok(Self(raw))
            }
        }

        impl From<$name> for String {
            fn from(id: $name) -> Self {
                id.0
            }
        }

        impl fmt::Display for $name {
            fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
                f.write_str(&self.0)
            }
        }
    };
}

id_type!(
    /// The id of a Node, as the Gateway records it.
    NodeId
);
id_type!(
    /// The id of an order's target, such as an Instance.
    TargetId
);
id_type!(
    /// The id of the Orbit Operation an order belongs to.
    OperationId
);

/// 128 random bits that make each signed order unique.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(try_from = "String", into = "String")]
pub struct Nonce([u8; 16]);

impl FromStr for Nonce {
    type Err = ParseIdError;

    fn from_str(raw: &str) -> Result<Self, Self::Err> {
        let bytes = raw.as_bytes();
        if bytes.len() != 32 {
            return Err(ParseIdError::BadNonce);
        }
        let mut out = [0_u8; 16];
        for (slot, pair) in out.iter_mut().zip(bytes.chunks_exact(2)) {
            *slot = (hex_digit(pair[0])? << 4) | hex_digit(pair[1])?;
        }
        Ok(Self(out))
    }
}

fn hex_digit(byte: u8) -> Result<u8, ParseIdError> {
    match byte {
        b'0'..=b'9' => Ok(byte - b'0'),
        b'a'..=b'f' => Ok(byte - b'a' + 10),
        _ => Err(ParseIdError::BadNonce),
    }
}

impl TryFrom<String> for Nonce {
    type Error = ParseIdError;

    fn try_from(raw: String) -> Result<Self, Self::Error> {
        raw.parse()
    }
}

impl From<Nonce> for String {
    fn from(nonce: Nonce) -> Self {
        nonce.to_string()
    }
}

impl fmt::Display for Nonce {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.0.iter().try_for_each(|b| write!(f, "{b:02x}"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ids_refuse_empty_long_and_odd_characters() {
        assert_eq!("".parse::<NodeId>(), Err(ParseIdError::Empty));
        assert_eq!(
            "a".repeat(129).parse::<TargetId>(),
            Err(ParseIdError::TooLong)
        );
        assert_eq!(
            "a/b".parse::<OperationId>(),
            Err(ParseIdError::BadCharacter)
        );
        assert_eq!(
            "op-1:x_y.z".parse::<OperationId>().map(|id| id.to_string()),
            Ok("op-1:x_y.z".to_owned())
        );
    }

    #[test]
    fn nonce_round_trips_and_refuses_bad_hex() {
        let hex = "00112233445566778899aabbccddeeff";
        assert_eq!(
            hex.parse::<Nonce>().map(|n| n.to_string()),
            Ok(hex.to_owned())
        );
        assert_eq!(
            "00112233445566778899AABBCCDDEEFF".parse::<Nonce>(),
            Err(ParseIdError::BadNonce)
        );
        assert_eq!("0011".parse::<Nonce>(), Err(ParseIdError::BadNonce));
    }
}
