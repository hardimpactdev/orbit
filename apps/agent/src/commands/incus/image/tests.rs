use super::super::tests::{fake, output, Fake};
use super::*;
use serde_json::json;
fn input(op: Value) -> Input {
    serde_json::from_value(json!({"project":"orbit-tasks","operation":op})).unwrap()
}
fn inventory(pin: &str, aliases: &[&str]) -> String {
    json!([{"fingerprint":pin,"aliases":aliases.iter().map(|s|json!({"name":s})).collect::<Vec<_>>()}]).to_string()
}
#[test]
fn normalizes_only_seed_templates_preserving_maps_and_repeats() {
    let old = json!({"architecture":"x86_64","properties":{},"templates":{"/etc/hostname":{"properties":{},"template":"hostname.tpl"},"/var/lib/cloud/seed/nocloud-net/user-data":{"template":"cloud.tpl"}}});
    let expected = json!({"architecture":"x86_64","properties":{},"templates":{"/etc/hostname":{"properties":{},"template":"hostname.tpl"}}});
    let mut host = fake(&[&old.to_string(), "", &expected.to_string(), ""]);
    let request = input(json!({"kind":"prepare_metadata","name":"builder"}));
    for _ in 0..2 {
        assert!(change(&mut host, &request).unwrap().ready);
    }
    for call in [&host.calls[1], &host.calls[3]] {
        assert_eq!(call[2], "PUT");
        assert_eq!(serde_json::from_str::<Value>(&call[4]).unwrap(), expected);
    }
    for value in ["broken", "{}", "{\"templates\":[]}"] {
        let mut host = fake(&[value]);
        assert!(matches!(
            change(&mut host, &request),
            Err(Error::Output { .. })
        ));
        assert_eq!(host.calls.len(), 1);
    }
}
#[test]
fn publishes_stopped_builder_and_returns_existing_alias_on_repeat() {
    let pin = "a".repeat(64);
    let images = inventory(&pin, &["base-build-7"]);
    let mut host = fake(&["[]", "", "", &images, &images]);
    let request = input(
        json!({"kind":"publish","name":"builder","alias":"base-build-7","properties":{"orbit.recipe":"sha256:recipe","orbit.profile":"base"}}),
    );
    for _ in 0..2 {
        assert_eq!(
            change(&mut host, &request).unwrap().fingerprint,
            Some(pin.clone())
        );
    }
    assert!(host.calls[2].contains(&"orbit.profile=base".into()));
    assert_eq!(
        &host.calls[2][2..7],
        [
            "publish",
            "--compression",
            "none",
            "--alias",
            "base-build-7"
        ]
    );
    assert_eq!(host.calls.len(), 5);
}
#[test]
fn only_a_confirmed_stopped_builder_can_publish_after_a_failed_stop() {
    for status in ["Running", "Stopped"] {
        let pin = "a".repeat(64);
        let images = inventory(&pin, &["base"]);
        let rows = json!([{"name":"builder","status":status}]).to_string();
        let mut host = fake(&["[]", "", &rows, "", &images]);
        host.answers[1].code = 1;
        let request =
            input(json!({"kind":"publish","name":"builder","alias":"base","properties":{}}));
        let result = change(&mut host, &request);
        if status == "Stopped" {
            assert!(result.is_ok());
        } else {
            assert!(matches!(result, Err(Error::Failed { .. })));
            assert_eq!(host.calls.len(), 3);
        }
    }
    let mut host = fake(&["[]", "", "", "[]"]);
    assert!(matches!(
        change(
            &mut host,
            &input(json!({"kind":"publish","name":"builder","alias":"base","properties":{}}))
        ),
        Err(Error::Output { .. })
    ));
}
#[test]
fn promotes_by_create_or_patch_and_repeated_selection_does_not_mutate() {
    let pin = "a".repeat(64);
    let old = "b".repeat(64);
    let mut host = fake(&[
        "[]",
        "",
        &inventory(&old, &["orbit/base"]),
        "",
        &inventory(&pin, &["orbit/base"]),
    ]);
    let request = input(json!({"kind":"promote","alias":"orbit/base","fingerprint":pin}));
    for _ in 0..3 {
        assert!(change(&mut host, &request).is_ok());
    }
    assert_eq!(host.calls[1][4], "create");
    assert!(host.calls[3]
        .last()
        .unwrap()
        .contains("orbit%2Fbase?project=orbit-tasks"));
    assert_eq!(host.calls.len(), 5);
}
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
#[test]
fn prunes_only_owned_unused_images_and_retires_aliases_without_deleting_kept_disks() {
    let pins: Vec<_> = (b'a'..=b'f')
        .map(|b| (b as char).to_string().repeat(64))
        .collect();
    let rows = json!([{"name":"vm","config":{"volatile.base_image":pins[0]}}]).to_string();
    let images = json!([
        {"fingerprint":pins[0],"aliases":[{"name":"used"}]},
        {"fingerprint":pins[1],"aliases":[{"name":"obsolete"}]},
        {"fingerprint":pins[2],"aliases":[{"name":"foreign"},{"name":"old-app-dev"}]},
        {"fingerprint":pins[3],"aliases":[]},
        {"fingerprint":pins[4],"aliases":[{"name":"keep"}]},
        {"fingerprint":pins[5],"aliases":[{"name":"not-owned"}]}
    ])
    .to_string();
    let seed = format!("orbit-topology-seed-{}", &pins[1][..12]);
    let volumes = json!([{"type":"custom","name":seed}]).to_string();
    let mut host = fake(&[
        "p", &rows, &images, &volumes, "", "[]", "", "", "p", &rows, "[]",
    ]);
    let request = input(
        json!({"kind":"prune","pool":"tank","obsolete_aliases":["used","obsolete","old-app-dev","keep"],"retired_aliases":["old-app-dev"],"protected_fingerprints":[pins[4]]}),
    );
    assert!(change(&mut host, &request).is_ok());
    assert_eq!(host.calls[4].last(), Some(&seed));
    assert_eq!(host.calls[6].last(), Some(&pins[1]));
    assert_eq!(host.calls[7].last().unwrap(), "old-app-dev");
    assert!(change(&mut host, &request).is_ok());
    assert!(host.answers.is_empty());
}
#[test]
fn pruning_failure_stops_before_deleting_the_image_and_later_candidates() {
    let pin = "a".repeat(64);
    let inv = inventory(&pin, &["obsolete"]);
    let seed = format!("orbit-topology-seed-{}", &pin[..12]);
    let vol = json!([{"name":seed,"type":"custom"}]).to_string();
    let mut host = fake(&["p", "[]", &inv, &vol, "", &vol]);
    host.answers[4].code = 1;
    let request = input(
        json!({"kind":"prune","pool":"tank","obsolete_aliases":["obsolete"],"retired_aliases":[],"protected_fingerprints":[]}),
    );
    assert!(matches!(
        change(&mut host, &request),
        Err(Error::Failed { .. })
    ));
    assert!(host
        .calls
        .iter()
        .all(|c| !c.windows(2).any(|w| w == ["image", "delete"])));
}
#[test]
fn rejects_unsafe_intent_before_any_observation_or_change() {
    for op in [
        json!({"kind":"prepare_metadata","name":"../vm"}),
        json!({"kind":"publish","name":"vm","alias":"--evil","properties":{}}),
        json!({"kind":"publish","name":"vm","alias":"base","properties":{"evil":"value"}}),
        json!({"kind":"promote","alias":"base","fingerprint":"bad"}),
        json!({"kind":"prune","pool":"tank","obsolete_aliases":[],"retired_aliases":["foreign"],"protected_fingerprints":[]}),
    ] {
        let mut host = Fake::default();
        assert_eq!(change(&mut host, &input(op)), Err(Error::Invalid {}));
        assert!(host.calls.is_empty());
    }
    let mut request = input(json!({"kind":"prepare_metadata","name":"vm"}));
    request.project = "bad/project".into();
    assert_eq!(
        change(&mut Fake::default(), &request),
        Err(Error::Invalid {})
    );
    assert!(serde_json::from_value::<Input>(
        json!({"project":"p","operation":{"kind":"prepare_metadata","name":"vm","program":"shell"}})
    )
    .is_err());
    assert_eq!(ChangeImage::privilege(Platform::Macos), None);
}
#[test]
fn host_failures_keep_typed_operation_errors_and_stop_mutation() {
    let pin = "a".repeat(64);
    for (request, mut host, index) in [
        (
            input(json!({"kind":"prepare_metadata","name":"vm"})),
            fake(&["{\"templates\":{}}", ""]),
            1,
        ),
        (
            input(json!({"kind":"promote","alias":"base","fingerprint":pin})),
            fake(&["[]", ""]),
            1,
        ),
        (
            input(json!({"kind":"publish","name":"vm","alias":"base","properties":{}})),
            fake(&["[]", "", ""]),
            2,
        ),
    ] {
        host.answers[index] = output("");
        host.answers[index].code = 1;
        assert!(matches!(
            change(&mut host, &request),
            Err(Error::Failed { .. })
        ));
        assert_eq!(host.calls.len(), index + 1);
    }
}
