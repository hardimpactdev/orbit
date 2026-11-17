//! The Gateway signer's shared fixture (`apps/agent/contract/fixtures/executor-order.json`) passes `order::check` unchanged.
//! The Gateway's Pest test checks the same fixture against its own signer, so both sides agree on the wire form.

use orbit_executor_core::action::{Action, Enabled};
use orbit_executor_core::ids::NodeId;
use orbit_executor_core::order::{check, Checks, OrderRefusal, PinnedKeys};

fn fixture() -> serde_json::Value {
    let text = std::fs::read_to_string(concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../contract/fixtures/executor-order.json"
    ))
    .expect("the shared fixture is readable");
    serde_json::from_str(&text).expect("the shared fixture is JSON")
}

fn field<'a>(fixture: &'a serde_json::Value, name: &str) -> &'a str {
    fixture[name].as_str().expect("a string field")
}

#[test]
fn the_gateway_signed_order_verifies_unchanged() {
    let fixture = fixture();
    let keys = PinnedKeys::parse(field(&fixture, "public_key")).expect("the fixture's public key");
    let enabled = Enabled::parse("proof").expect("valid");
    let node: NodeId = fixture["fields"]["node"]
        .as_str()
        .expect("node")
        .parse()
        .expect("valid node");
    let checks = Checks {
        keys: &keys,
        enabled: &enabled,
        node: &node,
    };
    let now = fixture["now_ms"].as_u64().expect("now_ms");
    let order =
        check(field(&fixture, "wire").as_bytes(), &checks, now).expect("the order verifies");
    assert_eq!(order.action(), Action::ProofNoop);
    assert_eq!(
        order.operation().as_str(),
        fixture["fields"]["operation"].as_str().expect("operation")
    );
    assert_eq!(
        order.nonce().to_string(),
        fixture["fields"]["nonce"].as_str().expect("nonce")
    );
    assert_eq!(
        Some(order.expires_ms()),
        fixture["fields"]["expires_ms"].as_u64()
    );

    let expires = fixture["fields"]["expires_ms"]
        .as_u64()
        .expect("expires_ms");
    assert_eq!(
        check(field(&fixture, "wire").as_bytes(), &checks, expires),
        Err(OrderRefusal::Expired)
    );
}
