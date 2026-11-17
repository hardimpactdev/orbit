//! The signed order: its wire form, the pinned Gateway keys and the stateless checks.
//!
//! The wire form is `base64(payload).base64(signature)`, at most 4 KB. Only [`check`] makes a
//! [`VerifiedOrder`], and only a `VerifiedOrder` reaches the executor's state and actions.

use base64::engine::general_purpose::STANDARD;
use base64::Engine as _;
use ed25519_dalek::{Signature, VerifyingKey};
use serde::Deserialize;

use crate::action::{Action, Enabled, TargetKind};
use crate::ids::{NodeId, Nonce, OperationId, TargetId};

/// The largest order the executor reads, in bytes.
pub const MAX_ORDER_BYTES: usize = 4096;

/// How far ahead of the executor's clock an expiry may be, in milliseconds.
pub const MAX_EXPIRY_AHEAD_MS: u64 = 10_000;

/// The Gateway's public keys that this Node pins: one, or two during a rotation.
#[derive(Debug, Clone)]
pub struct PinnedKeys(Vec<VerifyingKey>);

/// Why the pinned key file is unusable.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[non_exhaustive]
pub enum PinnedKeysError {
    /// The file holds no key.
    #[error("no pinned Gateway key")]
    Empty,
    /// The file holds more than two keys.
    #[error("more than two pinned Gateway keys")]
    TooMany,
    /// A line is not a base64 Ed25519 public key.
    #[error("line {line} is not a base64 Ed25519 public key")]
    BadKey {
        /// The 1-based line.
        line: usize,
    },
}

impl PinnedKeys {
    /// Parses `/etc/orbit/executor/gateway.pub`: one base64 public key per line, at most two.
    ///
    /// # Errors
    ///
    /// Returns [`PinnedKeysError`] when the file holds no key, more than two, or a line that is not a valid
    /// Ed25519 public key.
    pub fn parse(text: &str) -> Result<Self, PinnedKeysError> {
        let mut keys = Vec::new();
        for (index, line) in text.lines().enumerate() {
            let line = line.trim();
            if line.is_empty() || line.starts_with('#') {
                continue;
            }
            let bad = || PinnedKeysError::BadKey { line: index + 1 };
            let bytes: [u8; 32] = STANDARD
                .decode(line)
                .map_err(|_| bad())?
                .try_into()
                .map_err(|_| bad())?;
            keys.push(VerifyingKey::from_bytes(&bytes).map_err(|_| bad())?);
        }
        match keys.len() {
            0 => Err(PinnedKeysError::Empty),
            1 | 2 => Ok(Self(keys)),
            _ => Err(PinnedKeysError::TooMany),
        }
    }

    fn verify(&self, payload: &[u8], signature: &Signature) -> bool {
        self.0
            .iter()
            .any(|key| key.verify_strict(payload, signature).is_ok())
    }
}

/// The signed payload. Unknown fields refuse.
#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct Payload {
    action: String,
    node: NodeId,
    target: TargetId,
    operation: OperationId,
    nonce: Nonce,
    expires_ms: u64,
}

/// An order that passed every stateless check. Only [`check`] makes one.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct VerifiedOrder {
    action: Action,
    target: TargetId,
    operation: OperationId,
    nonce: Nonce,
    expires_ms: u64,
}

impl VerifiedOrder {
    /// Rebuilds an order that the journal recorded as open, for crash recovery. Its checks passed before it
    /// was journaled.
    pub(crate) fn recovered(nonce: Nonce, key: &crate::journal::DoneKey) -> Self {
        Self {
            action: key.action,
            target: key.target.clone(),
            operation: key.operation.clone(),
            nonce,
            expires_ms: 0,
        }
    }

    /// The allowlisted action.
    pub fn action(&self) -> Action {
        self.action
    }

    /// The target the action acts on.
    pub fn target(&self) -> &TargetId {
        &self.target
    }

    /// The Operation the order belongs to.
    pub fn operation(&self) -> &OperationId {
        &self.operation
    }

    /// The order's nonce.
    pub fn nonce(&self) -> Nonce {
        self.nonce
    }

    /// When the order expires, in Unix milliseconds.
    pub fn expires_ms(&self) -> u64 {
        self.expires_ms
    }
}

/// Why an order is refused before it reaches the executor's state. Each reason has its own code.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[non_exhaustive]
pub enum OrderRefusal {
    /// The order is larger than 4 KB.
    #[error("order is larger than {MAX_ORDER_BYTES} bytes")]
    TooLarge,
    /// The order is not `base64(payload).base64(signature)`.
    #[error("order is not base64(payload).base64(signature)")]
    Malformed,
    /// No pinned key verifies the signature.
    #[error("bad signature")]
    BadSignature,
    /// The signed payload is not the order's JSON shape.
    #[error("payload does not match the order shape")]
    BadPayload,
    /// The action is not on the allowlist.
    #[error("unknown action")]
    UnknownAction,
    /// The action's family is not enabled on this Node.
    #[error("action not enabled on this Node")]
    ActionNotEnabled,
    /// The order is for another Node.
    #[error("order is for another Node")]
    WrongNode,
    /// The target does not exist on this Node.
    #[error("target not on this Node")]
    UnknownTarget,
    /// The order has expired.
    #[error("order expired")]
    Expired,
    /// The expiry is more than 10 s ahead of the Node's clock.
    #[error("expiry too far ahead")]
    ExpiryTooFar,
}

impl OrderRefusal {
    /// The stable code the Gateway logs and alerts on.
    pub fn code(&self) -> &'static str {
        match self {
            Self::TooLarge => "order_too_large",
            Self::Malformed => "order_malformed",
            Self::BadSignature => "bad_signature",
            Self::BadPayload => "bad_payload",
            Self::UnknownAction => "unknown_action",
            Self::ActionNotEnabled => "action_not_enabled",
            Self::WrongNode => "wrong_node",
            Self::UnknownTarget => "unknown_target",
            Self::Expired => "expired",
            Self::ExpiryTooFar => "expiry_too_far",
        }
    }
}

/// What the stateless checks compare an order against.
#[derive(Debug)]
pub struct Checks<'cfg> {
    /// The Gateway's pinned public keys.
    pub keys: &'cfg PinnedKeys,
    /// The families this Node's roles enable.
    pub enabled: &'cfg Enabled,
    /// This Node's id.
    pub node: &'cfg NodeId,
}

/// Checks an order, in this order: size, form, signature, payload, action, enabled, Node, target, expiry.
/// The nonce and the Operation are checked against the executor's state afterwards.
///
/// # Errors
///
/// Returns the first [`OrderRefusal`] that applies.
pub fn check(wire: &[u8], checks: &Checks<'_>, now_ms: u64) -> Result<VerifiedOrder, OrderRefusal> {
    check_with_target(wire, checks, now_ms, |_, _| false)
}

/// Checks an order using the binary's local target truth for Instance actions.
///
/// # Errors
/// Returns the first order refusal, including a target missing from local Incus.
pub fn check_with_target(
    wire: &[u8],
    checks: &Checks<'_>,
    now_ms: u64,
    target_exists: impl FnOnce(Action, &TargetId) -> bool,
) -> Result<VerifiedOrder, OrderRefusal> {
    if wire.len() > MAX_ORDER_BYTES {
        return Err(OrderRefusal::TooLarge);
    }
    let text = std::str::from_utf8(wire).map_err(|_| OrderRefusal::Malformed)?;
    let (payload_b64, signature_b64) = text
        .trim_end()
        .split_once('.')
        .ok_or(OrderRefusal::Malformed)?;
    let payload = STANDARD
        .decode(payload_b64)
        .map_err(|_| OrderRefusal::Malformed)?;
    let signature: [u8; 64] = STANDARD
        .decode(signature_b64)
        .map_err(|_| OrderRefusal::Malformed)?
        .try_into()
        .map_err(|_| OrderRefusal::Malformed)?;
    if !checks
        .keys
        .verify(&payload, &Signature::from_bytes(&signature))
    {
        return Err(OrderRefusal::BadSignature);
    }
    let payload: Payload =
        serde_json::from_slice(&payload).map_err(|_| OrderRefusal::BadPayload)?;
    let action: Action = payload
        .action
        .parse()
        .map_err(|_| OrderRefusal::UnknownAction)?;
    if !checks.enabled.allows(action) {
        return Err(OrderRefusal::ActionNotEnabled);
    }
    if payload.node != *checks.node {
        return Err(OrderRefusal::WrongNode);
    }
    let target_exists = match action.target_kind() {
        TargetKind::Node => payload.target.as_str() == checks.node.as_str(),
        TargetKind::Instance => target_exists(action, &payload.target),
    };
    if !target_exists {
        return Err(OrderRefusal::UnknownTarget);
    }
    if payload.expires_ms <= now_ms {
        return Err(OrderRefusal::Expired);
    }
    if payload.expires_ms - now_ms > MAX_EXPIRY_AHEAD_MS {
        return Err(OrderRefusal::ExpiryTooFar);
    }
    Ok(VerifiedOrder {
        action,
        target: payload.target,
        operation: payload.operation,
        nonce: payload.nonce,
        expires_ms: payload.expires_ms,
    })
}

#[cfg(test)]
pub(crate) mod tests {
    use ed25519_dalek::{Signer as _, SigningKey};

    use super::*;

    pub(crate) const NOW: u64 = 1_800_000_000_000;

    pub(crate) fn key(seed: u8) -> SigningKey {
        SigningKey::from_bytes(&[seed; 32])
    }

    pub(crate) fn pinned(seed: u8) -> PinnedKeys {
        PinnedKeys::parse(&STANDARD.encode(key(seed).verifying_key().as_bytes()))
            .expect("valid key")
    }

    pub(crate) fn sign(seed: u8, payload: &serde_json::Value) -> Vec<u8> {
        let bytes = serde_json::to_vec(payload).expect("json");
        let signature = key(seed).sign(&bytes);
        format!(
            "{}.{}",
            STANDARD.encode(&bytes),
            STANDARD.encode(signature.to_bytes())
        )
        .into_bytes()
    }

    pub(crate) fn payload() -> serde_json::Value {
        serde_json::json!({
            "action": "proof.noop",
            "node": "7",
            "target": "7",
            "operation": "op-1",
            "nonce": "00112233445566778899aabbccddeeff",
            "expires_ms": NOW + 5_000,
        })
    }

    fn run(wire: &[u8]) -> Result<VerifiedOrder, OrderRefusal> {
        let keys = pinned(1);
        let enabled = Enabled::parse("proof").expect("valid");
        let node: NodeId = "7".parse().expect("valid");
        check(
            wire,
            &Checks {
                keys: &keys,
                enabled: &enabled,
                node: &node,
            },
            NOW,
        )
    }

    fn with(field: &str, value: serde_json::Value) -> Vec<u8> {
        let mut p = payload();
        p[field] = value;
        sign(1, &p)
    }

    #[test]
    fn a_valid_order_verifies() {
        let order = run(&sign(1, &payload())).expect("valid order");
        assert_eq!(order.action(), Action::ProofNoop);
        assert_eq!(order.operation().as_str(), "op-1");
    }

    #[test]
    fn a_second_pinned_key_verifies_during_rotation() {
        let both = format!(
            "{}\n{}\n",
            STANDARD.encode(key(2).verifying_key().as_bytes()),
            STANDARD.encode(key(1).verifying_key().as_bytes())
        );
        let keys = PinnedKeys::parse(&both).expect("two keys");
        let enabled = Enabled::parse("proof").expect("valid");
        let node: NodeId = "7".parse().expect("valid");
        let checks = Checks {
            keys: &keys,
            enabled: &enabled,
            node: &node,
        };
        assert!(check(&sign(1, &payload()), &checks, NOW).is_ok());
    }

    #[test]
    fn pinned_keys_refuse_none_three_and_garbage() {
        let one = STANDARD.encode(key(1).verifying_key().as_bytes());
        assert_eq!(PinnedKeys::parse("").err(), Some(PinnedKeysError::Empty));
        assert_eq!(
            PinnedKeys::parse(&format!("{one}\n{one}\n{one}")).err(),
            Some(PinnedKeysError::TooMany)
        );
        assert_eq!(
            PinnedKeys::parse("not-a-key").err(),
            Some(PinnedKeysError::BadKey { line: 1 })
        );
    }

    #[test]
    fn oversize_and_malformed_orders_refuse() {
        assert_eq!(
            run(&vec![b'a'; MAX_ORDER_BYTES + 1]),
            Err(OrderRefusal::TooLarge)
        );
        assert_eq!(run(b"no-dot"), Err(OrderRefusal::Malformed));
        assert_eq!(run(b"!!.!!"), Err(OrderRefusal::Malformed));
    }

    #[test]
    fn a_wrong_key_refuses() {
        assert_eq!(run(&sign(9, &payload())), Err(OrderRefusal::BadSignature));
    }

    #[test]
    fn a_changed_payload_refuses() {
        let wire = String::from_utf8(sign(1, &payload())).expect("ascii");
        let (_, signature) = wire.split_once('.').expect("dot");
        let mut changed = payload();
        changed["target"] = "8".into();
        let forged = format!(
            "{}.{signature}",
            STANDARD.encode(serde_json::to_vec(&changed).expect("json"))
        );
        assert_eq!(run(forged.as_bytes()), Err(OrderRefusal::BadSignature));
    }

    #[test]
    fn unknown_fields_and_bad_ids_refuse() {
        assert_eq!(run(&with("extra", 1.into())), Err(OrderRefusal::BadPayload));
        assert_eq!(
            run(&with("nonce", "short".into())),
            Err(OrderRefusal::BadPayload)
        );
    }

    #[test]
    fn power_targets_are_checked_against_the_binarys_local_truth_before_expiry() {
        let mut p = payload();
        p["action"] = "sleep".into();
        p["target"] = "23".into();
        let keys = pinned(1);
        let enabled = Enabled::parse("power").expect("power");
        let node = "7".parse().expect("node");
        let checks = Checks {
            keys: &keys,
            enabled: &enabled,
            node: &node,
        };
        assert_eq!(
            check_with_target(&sign(1, &p), &checks, NOW, |_, _| false),
            Err(OrderRefusal::UnknownTarget)
        );
        let order = check_with_target(&sign(1, &p), &checks, NOW, |action, id| {
            action == Action::Sleep && id.as_str() == "23"
        })
        .expect("local container");
        assert_eq!(order.action(), Action::Sleep);
        p["expires_ms"] = NOW.into();
        assert_eq!(
            check_with_target(&sign(1, &p), &checks, NOW, |_, _| false),
            Err(OrderRefusal::UnknownTarget)
        );
    }

    #[test]
    fn each_check_has_its_own_refusal() {
        assert_eq!(
            run(&with("action", "hibernate".into())),
            Err(OrderRefusal::UnknownAction)
        );
        assert_eq!(run(&with("node", "8".into())), Err(OrderRefusal::WrongNode));
        assert_eq!(
            run(&with("target", "8".into())),
            Err(OrderRefusal::UnknownTarget)
        );
        assert_eq!(
            run(&with("expires_ms", NOW.into())),
            Err(OrderRefusal::Expired)
        );
        assert_eq!(
            run(&with("expires_ms", (NOW + 10_001).into())),
            Err(OrderRefusal::ExpiryTooFar)
        );
        assert!(run(&with("expires_ms", (NOW + 10_000).into())).is_ok());
    }

    #[test]
    fn a_valid_signature_does_not_enable_a_family() {
        let keys = pinned(1);
        let node: NodeId = "7".parse().expect("valid");
        let checks = Checks {
            keys: &keys,
            enabled: &Enabled::default(),
            node: &node,
        };
        assert_eq!(
            check(&sign(1, &payload()), &checks, NOW),
            Err(OrderRefusal::ActionNotEnabled)
        );
    }
}
