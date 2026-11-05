import { assert } from "jsr:@std/assert@1.0.19";

const ENABLE = "operation-metrics-exporter-enable",
    DISABLE = "operation-metrics-exporter-disable";

Deno.test("exporter workflows stay within their requested line budget", async () => {
    for (
        const name of [
            ENABLE,
            DISABLE,
            "metrics-exporter-node",
            "metrics-exporter-allows",
            "metrics-prometheus-publish",
        ]
    ) {
        assert(
            (await Deno.readTextFile(
                new URL(
                    `../../../../workflows/workflow-${name}.yaml`,
                    import.meta.url,
                ),
            )).trimEnd()
                .split("\n").length <= 200,
            name,
        );
    }
    let removed = false;
    try {
        await Deno.stat(
            new URL(
                "../../../../workflows/workflow-operation-metrics-exporter.yaml",
                import.meta.url,
            ),
        );
    } catch (e) {
        if (e instanceof Deno.errors.NotFound) removed = true;
        else throw e;
    }
    assert(removed);
});
