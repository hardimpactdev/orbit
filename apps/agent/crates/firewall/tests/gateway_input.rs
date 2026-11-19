//! The Gateway's `ufw.ensure_rule` input (`apps/agent/contract/fixtures/ufw-ensure-rule-input.json`) reads as the
//! primitive's typed input and passes its checks. The Gateway's Pest test signs the same input for the same intent, so
//! both sides agree on the primitive's contract.

use orbit_executor_firewall::plan::{validated, Input};

#[test]
fn the_gateway_input_reads_and_validates() {
    let text = std::fs::read_to_string(concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../contract/fixtures/ufw-ensure-rule-input.json"
    ))
    .expect("the shared fixture is readable");
    let fixture: serde_json::Value = serde_json::from_str(&text).expect("the shared fixture is JSON");
    let input: Input =
        serde_json::from_value(fixture["input"].clone()).expect("the Gateway's input has exactly the primitive's fields");
    assert!(validated(input).is_ok(), "the Gateway's input passes the primitive's checks");
}
