import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { readIntent } from "./intent.ts";
const paths = [
    "prometheus.yml",
    "grafana/grafana.ini",
    "grafana/provisioning/datasources/prometheus.yml",
    "grafana/provisioning/dashboards/provider.yml",
    "grafana/dashboards/orbit-node-resources.json",
    "grafana/dashboards/orbit-caddy.json",
    "grafana/dashboards/orbit-fpm.json",
];
function intent() {
    return {
        participant_node_ids: [1, 9, 18],
        metrics_fleet: {
            schema_version: 1,
            assignment_id: 19,
            metrics_node_id: 9,
            metrics_address: "10.44.0.9",
            digest: "a".repeat(64),
            runtime_files: Object.fromEntries(
                paths.map((p) => ["/etc/orbit/metrics/" + p, "public config"]),
            ),
            fleet: [1, 9, 18].map((node_id) => ({
                node_id,
                address: `10.44.0.${node_id}`,
                worker: true,
                selected: true,
                fpm: false,
                pools: [],
                service: {
                    config: null,
                    unit: null,
                    rules: [],
                    active: false,
                    enabled: false,
                    binary: false,
                },
            })),
        },
        caddy_publications: [],
    };
}
Deno.test("full intent retains both logical proxy Nodes in placed exporter actions", () => {
    const result = readIntent(intent());
    assertEquals(result.ids, [1, 9, 18]);
    assertEquals(result.exporters.map((e) => e.key), [
        "1-node_exporter",
        "1-cadvisor",
        "9-node_exporter",
        "9-cadvisor",
        "18-node_exporter",
        "18-cadvisor",
    ]);
    assertEquals(result.exporters.map((e) => JSON.parse(e.json).address), [
        "10.44.0.1",
        "10.44.0.1",
        "10.44.0.9",
        "10.44.0.9",
        "10.44.0.18",
        "10.44.0.18",
    ]);
});
Deno.test("missing exact worker is refused instead of dropping a fleet member", () => {
    const value = intent();
    value.metrics_fleet.fleet[0].worker = false;
    assertThrows(() => readIntent(value));
});
Deno.test("duplicate and unadmitted participants cannot select a different effect Node", () => {
    const value = intent();
    value.metrics_fleet.fleet[0].node_id = 18;
    assertThrows(
        () => readIntent(value),
        Error,
        "metrics.participant_mismatch",
    );
    const other = intent();
    other.participant_node_ids = [9, 18, 209];
    assertThrows(
        () => readIntent(other),
        Error,
        "metrics.participant_mismatch",
    );
});
Deno.test("no secret or caller-selected runtime file can enter public file actions", () => {
    const value = intent();
    value.metrics_fleet
        .runtime_files["/etc/orbit/metrics/grafana/admin-password"] =
            "sentinel";
    assertThrows(
        () => readIntent(value),
        Error,
        "metrics.runtime_files_invalid",
    );
    const other = intent();
    delete other.metrics_fleet
        .runtime_files["/etc/orbit/metrics/prometheus.yml"];
    other.metrics_fleet.runtime_files["/etc/shadow"] = "foreign";
    assertThrows(
        () => readIntent(other),
        Error,
        "metrics.runtime_files_invalid",
    );
});
Deno.test("disabled exporter intent never discards unselected Nodes or changes target paths", () => {
    const value = intent();
    value.metrics_fleet.fleet[0].selected = false;
    const result = readIntent(value);
    assertEquals(result.exporters[0].selected, false);
    assertEquals(
        JSON.parse(result.exporters[0].json).path,
        "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
    );
});
