import { assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

// Chart structure needs YAML parsing, not a native repository or workflow run.
for (
    const [name, expected] of [
        ["operation-route-removal", [
            "Check each Node answers",
            "Remove private DNS",
            "Wait the DNS grace",
            "Withdraw site, remove cert",
            "Converge PHP-FPM pools",
            "Remove unused LAN rules",
            "Check nothing is left",
        ]],
        ["operation-route-target-set", [
            "Make CSRs on new Nodes",
            "Sign the CSRs",
            "Install cert, publish site",
            "Stage the new targets",
            "Probe each new target",
            "Switch the Router pool",
            "Retire detached targets",
            "Move private DNS",
        ]],
        ["operation-route-update", [
            "Make a CSR on the Node",
            "Sign the CSR",
            "Install cert, publish site",
            "Write the new APP_URL",
            "Probe the site over TLS",
            "Open or close the edge",
            "Move private DNS",
            "Wait the DNS grace",
            "Withdraw the old site",
        ]],
        ["operation-cluster-router", [
            "Prepare the Router",
            "Request the certificates",
            "Sign the CSRs",
            "Open LAN paths",
            "Hold the Route Nodes",
            "Install certs, publish",
            "Release the Route Nodes",
            "Probe the workloads",
            "Select the Router in DNS",
            "Hold the Nodes to retire",
            "Retire the old Router",
            "Release the retired Nodes",
            "Restore the before state",
        ]],
        ["operation-cluster-router-unset", [
            "Hold the Route Nodes",
            "Withdraw the Router sites",
            "Release the Route Nodes",
            "Remove the LAN rules",
            "Check the rules are gone",
        ]],
    ] as const
) {
    Deno.test(`${name} keeps its chart step order`, async () => {
        const flow = parse(
            await Deno.readTextFile(
                new URL(
                    `../../../../workflows/workflow-${name}.yaml`,
                    import.meta.url,
                ),
            ),
        ) as { jobs: { steps: { name: string }[] }[] };
        assertEquals(flow.jobs[0].steps.map((step) => step.name), [
            ...expected,
        ]);
    });
}
