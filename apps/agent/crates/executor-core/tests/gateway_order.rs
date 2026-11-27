//! The Gateway signer's shared fixture (`apps/agent/contract/fixtures/executor-order.json`) passes `order::check` unchanged.
//! The Gateway's Pest test checks the same fixture against its own signer, so both sides agree on the wire form.

use orbit_executor_core::action::{Action, Enabled, TargetKind};
use orbit_executor_core::ids::NodeId;
use orbit_executor_core::order::{check, check_with_target, Checks, OrderRefusal, PinnedKeys};

fn fixture() -> serde_json::Value {
    let text = std::fs::read_to_string(concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../contract/fixtures/executor-order.json"
    ))
    .expect("the shared fixture is readable");
    serde_json::from_str(&text).expect("the shared fixture is JSON")
}

#[test]
fn the_gateway_signed_destroy_preserves_the_instance_target_and_saved_identity() {
    let fixture: serde_json::Value = serde_json::from_str(include_str!("../../../contract/fixtures/executor-instance-destroy.json")).expect("fixture");
    let keys = PinnedKeys::parse(field(&fixture, "public_key")).expect("fixture key");
    let enabled = Enabled::parse("instance").expect("valid");
    let node = field(&fixture["fields"], "node").parse().expect("Node ID");
    let checks = Checks { keys: &keys, enabled: &enabled, node: &node };
    let now = fixture["now_ms"].as_u64().expect("now");
    let wire = field(&fixture, "wire").as_bytes();
    assert_eq!(check(wire, &checks, now), Err(OrderRefusal::UnknownTarget));
    let order = check_with_target(wire, &checks, now, |action, target| {
        assert_eq!(action, Action::InstanceDestroy);
        assert_eq!(action.target_kind(), TargetKind::Instance);
        target.as_str() == "12"
    }).expect("the fixture verifies");
    assert_eq!(order.operation().as_str(), "42:remove");
    assert_eq!(order.input(), &fixture["fields"]["input"]);
}

#[test]
fn the_gateway_signed_route_withdrawal_and_guarded_dns_records_verify_with_their_node_target() {
    for (file, family, action) in [
        (include_str!("../../../contract/fixtures/executor-route-withdraw-site.json"), "route", Action::RouteWithdrawSite),
        (include_str!("../../../contract/fixtures/executor-dns-guarded-records.json"), "dns", Action::DnsPublishRecords),
    ] {
        let fixture: serde_json::Value = serde_json::from_str(file).expect("fixture");
        let keys = PinnedKeys::parse(field(&fixture, "public_key")).expect("fixture key");
        let enabled = Enabled::parse(family).expect("valid");
        let node = field(&fixture["fields"], "node").parse().expect("Node ID");
        let checks = Checks { keys: &keys, enabled: &enabled, node: &node };
        let order = check(field(&fixture, "wire").as_bytes(), &checks, fixture["now_ms"].as_u64().expect("now")).expect("the fixture verifies");
        assert_eq!(order.action(), action);
        assert_eq!(action.target_kind(), TargetKind::Node);
        assert_eq!(order.input(), &fixture["fields"]["input"]);
    }
}

#[test]
fn the_gateway_signed_copy_sync_orders_target_the_copy_and_keep_their_input() {
    for (text, action, operation) in [
        (include_str!("../../../contract/fixtures/executor-instance-sync-base.json"), Action::InstanceSyncBase, "42:sync"),
        (include_str!("../../../contract/fixtures/executor-instance-clear-base.json"), Action::InstanceClearBase, "42:clear_after_failure"),
    ] {
        let fixture: serde_json::Value = serde_json::from_str(text).expect("fixture");
        let keys = PinnedKeys::parse(field(&fixture, "public_key")).expect("fixture key");
        let enabled = Enabled::parse("instance").expect("valid");
        let node = field(&fixture["fields"], "node").parse().expect("Node ID");
        let checks = Checks { keys: &keys, enabled: &enabled, node: &node };
        let now = fixture["now_ms"].as_u64().expect("now");
        let order = check_with_target(field(&fixture, "wire").as_bytes(), &checks, now, |found, target| {
            found == action && found.target_kind() == TargetKind::Instance && target.as_str() == "12"
        })
        .expect("the fixture verifies");
        assert_eq!(order.operation().as_str(), operation);
        assert_eq!(order.input(), &fixture["fields"]["input"]);
    }
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
