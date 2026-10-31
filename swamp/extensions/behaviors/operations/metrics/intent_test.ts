import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { readIntent, serviceFilesWithPools } from "./intent.ts";
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

function withPool(enabled = true) {
    const base = intent();
    const runtime = {
        mode: "monitor" as const,
        user: "instance41",
        home: "/home/instance41",
        version: "8.5",
        marker: "instance:41",
        application: "/home/instance41/releases/current",
        initial_application: "/home/instance41/releases/initial",
        configuration: {
            main: "public main",
            pool: "enabled pool",
            initial_pool: "initial",
            local_defaults: "defaults",
            master_ini: "ini",
            unit: "unit",
        },
    };
    const pool = {
        instance_id: 41,
        inspect: {
            user: runtime.user,
            version: runtime.version,
            marker: runtime.marker,
        },
        enabled: runtime,
        disabled: {
            ...runtime,
            configuration: { ...runtime.configuration, pool: "disabled pool" },
        },
    };
    return {
        ...base,
        metrics_fleet: {
            ...base.metrics_fleet,
            fleet: base.metrics_fleet.fleet.map((node) =>
                node.node_id === 9
                    ? {
                        ...node,
                        fpm: enabled,
                        pools: [pool],
                        service: {
                            ...node.service,
                            config: enabled ? "managed fpm config\n" : null,
                        },
                    }
                    : node
            ),
        },
    };
}
Deno.test("PHP pool intent selects canonical descriptor and preserves admitted effect Node", () => {
    const enabled = readIntent(withPool()).pools;
    assertEquals(enabled[0].node_id, 9);
    assertEquals(enabled[0].key, "9-41");
    assertEquals(enabled[0].runtime.configuration.pool, "enabled pool");
    assertEquals(
        readIntent(withPool(false)).pools[0].runtime.configuration.pool,
        "disabled pool",
    );
});
Deno.test("verified PHP fingerprint is identical in published file and service verification intent", () => {
    const fingerprint = "f".repeat(64);
    const result = serviceFilesWithPools({
        intent: withPool(),
        observations: [{
            key: "9-41",
            stdout: JSON.stringify({
                present: true,
                enabled: true,
                fingerprint,
            }),
        }],
    });
    const file = JSON.parse(
        result.files.find((file) => file.key === "9-config")!.json,
    );
    const service = JSON.parse(
        result.services.find((node) => node.node_id === 9)!.json,
    );
    assertEquals(
        file.content,
        `managed fpm config\n# runtime 41: ${fingerprint}\n`,
    );
    assertEquals(service.config, file.content);
});
Deno.test("missing mismatched foreign and duplicate PHP observations cannot publish exporter configuration", () => {
    const state = JSON.stringify({
        present: true,
        enabled: true,
        fingerprint: "a".repeat(64),
    });
    for (
        const observations of [[], [{ key: "foreign", stdout: state }], [{
            key: "9-41",
            stdout: state,
        }, { key: "9-41", stdout: state }], [{
            key: "9-41",
            stdout: '{"present":false}',
        }], [{
            key: "9-41",
            stdout: JSON.stringify({
                present: true,
                enabled: false,
                fingerprint: "a".repeat(64),
            }),
        }]]
    ) {
        assertThrows(
            () => serviceFilesWithPools({ intent: withPool(), observations }),
            Error,
            "metrics.pool_unverified",
        );
    }
});
Deno.test("an empty PHP pool set retains service intent without inventing monitoring evidence", () => {
    const value = intent();
    const result = serviceFilesWithPools({ intent: value, observations: [] });
    assertEquals(
        result.services.map((node) => JSON.parse(node.json)),
        value.metrics_fleet.fleet.map((node) => node.service),
    );
});
