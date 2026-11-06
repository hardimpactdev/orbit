use super::super::tests::fake;
use super::*;
use serde_json::json;
#[test]
fn instance_image_inventory_refuses_bad_rows_and_missing_project() {
    let pin = "a".repeat(64);
    let rows =
        json!([{"name":"vm","config":{"volatile.base_image":pin}},{"name":"empty","config":{}}])
            .to_string();
    let mut host = fake(&["project", &rows, "project", &rows]);
    let project = Project {
        project: "orbit-tasks".into(),
    };
    let first = instance_images(&mut host, &project).unwrap();
    assert_eq!(first.instances["vm"], Some(pin));
    assert_eq!(first.instances["empty"], None);
    assert_eq!(instance_images(&mut host, &project).unwrap(), first);
    for row in [
        json!({}),
        json!({"name":"vm","config":null}),
        json!({"name":"vm","config":{"volatile.base_image":"bad"}}),
    ] {
        assert!(matches!(
            instance_images(&mut fake(&["p", &json!([row]).to_string()]), &project),
            Err(Error::Output { .. })
        ));
    }
    let mut host = fake(&[""]);
    host.answers[0].code = 1;
    assert!(matches!(
        instance_images(&mut host, &project),
        Err(Error::Failed { .. })
    ));
    assert_eq!(host.calls.len(), 1);
}
