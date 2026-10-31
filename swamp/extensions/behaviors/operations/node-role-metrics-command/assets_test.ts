import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
    metricsPaths,
    metricsRuntimeSpec,
} from "../integrations/metrics_runtime_spec.ts";
import { roleMetricsSpecifications } from "./assets.ts";

const configuration = Object.fromEntries(
    metricsPaths.slice(0, -1).map((path) => [path, "public:" + path]),
);
Deno.test("native Metrics specifications preserve the canonical labels, pins and configuration digest", async () => {
    const result = await roleMetricsSpecifications({
        assignment_id: 31,
        address: "10.44.0.9",
        configuration,
    });
    assertEquals(result.prometheus.image, "prom/prometheus:v3.5.0");
    assertEquals(result.grafana.volume, "orbit-metrics-grafana-data");
    assertEquals(result.prometheus.labels, {
        "com.orbit.managed": "metrics",
        "com.orbit.metrics.service": "prometheus",
    });
    assertEquals(
        result.grafana.args,
        (await metricsRuntimeSpec("grafana", 31, "10.44.0.9", configuration))
            .args,
    );
    const changed = await roleMetricsSpecifications({
        assignment_id: 31,
        address: "10.44.0.9",
        configuration: {
            ...configuration,
            [metricsPaths[0]]: "changed scrape targets",
        },
    });
    assertEquals(result.prometheus.hash === changed.prometheus.hash, false);
    assertEquals(result.grafana.hash, changed.grafana.hash);
});
Deno.test("public Metrics metadata refuses private or incomplete configuration", async () => {
    for (
        const input of [{}, {
            ...configuration,
            [metricsPaths[7]]: "private-password",
        }]
    ) {
        await assertRejects(() =>
            roleMetricsSpecifications({
                assignment_id: 31,
                address: "10.44.0.9",
                configuration: input,
            })
        );
    }
});
