import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { behavior } from "./node_batch.ts";
import { nodeCertificateGroups } from "./batch_steps.ts";
import { targetWorkflow } from "../routes/update-command/workflow.ts";

const leaves = Array.from({ length: 120 }, (_, index) => ({
    node_id: 13,
    scope: `route-${index + 1}-router`,
    user: "orbit",
    domain: `site-${index}.test`,
    version: "0123456789abcdef",
    root_hash: "a".repeat(64),
    agent_sha256: "b".repeat(64),
}));
const context = () => ({
    extensionFile: (path: string) => "/worker/cache/" + path,
    writeResource: (_spec: string, _name: string, value: unknown) =>
        Promise.resolve(value),
});
const requests = leaves.map(({ node_id, scope }) => ({
    node_id,
    scope,
    state: "request" as const,
    request_base64: btoa(
        "-----BEGIN CERTIFICATE REQUEST-----\n" + "A".repeat(1400) +
            "\n-----END CERTIFICATE REQUEST-----",
    ),
}));
const inspected = leaves.map(({ node_id, scope, version }) => ({
    node_id,
    scope,
    version,
    present: true as const,
}));
Deno.test("one120-leaf Node batch accounts for every CSR and every presence result", async () => {
    const assets = await behavior.methods.read.execute(
        { node_id: 13, leaves },
        context(),
    );
    assertEquals(JSON.parse(assets.input_json), { leaves });
    assertEquals(assets.scripts_directory, "/worker/cache/scripts");
    assert(JSON.stringify(requests).length > 131072);
    const result = await behavior.methods.requests.execute({
        leaves,
        stdout: JSON.stringify({ requests }),
    }, context());
    assertEquals(result.requests.length, 120);
    const completed = await behavior.methods.result.execute({
        leaves,
        stdout: JSON.stringify({ leaves: inspected }),
    }, context());
    assertEquals(completed, { ready: true, node_id: 13, leaves: inspected });
});
Deno.test("Node batch refuses mixed placement, duplicate scopes and mixed roots before effects", async () => {
    for (
        const invalid of [
            [leaves[0], { ...leaves[1], node_id: 9 }],
            [leaves[0], leaves[0]],
            [leaves[0], { ...leaves[1], root_hash: "c".repeat(64) }],
        ]
    ) {
        await assertRejects(() =>
            behavior.methods.read.execute(
                { node_id: 13, leaves: invalid },
                context(),
            )
        );
    }
    await assertRejects(() =>
        behavior.methods.read.execute({ node_id: 9, leaves }, context())
    );
});
Deno.test("missing duplicate foreign or private CSR records cannot reach the signer", async () => {
    for (
        const invalid of [
            requests.slice(1),
            [requests[0], ...requests.slice(0, -1)],
            [{ ...requests[0], node_id: 9 }, ...requests.slice(1)],
            [{
                ...requests[0],
                request_base64: btoa("-----BEGIN PRIVATE KEY-----"),
            }, ...requests.slice(1)],
        ]
    ) {
        await assertRejects(() =>
            behavior.methods.requests.execute({
                leaves,
                stdout: JSON.stringify({ requests: invalid }),
            }, context())
        );
    }
});
Deno.test("missing duplicate foreign wrong-version or absent inspection prevents Node success", async () => {
    for (
        const invalid of [
            inspected.slice(1),
            [inspected[0], ...inspected.slice(0, -1)],
            [{ ...inspected[0], node_id: 9 }, ...inspected.slice(1)],
            [
                { ...inspected[0], version: "f".repeat(16) },
                ...inspected.slice(1),
            ],
            [{ ...inspected[0], present: false }, ...inspected.slice(1)],
        ]
    ) {
        await assertRejects(() =>
            behavior.methods.result.execute({
                leaves,
                stdout: JSON.stringify({ leaves: invalid }),
            }, context())
        );
    }
});
Deno.test("current and pending leaves use the existing batch decoder with no extra signature", async () => {
    const current = {
        node_id: 13,
        scope: leaves[0].scope,
        state: "current" as const,
    };
    const requests = [current, {
        node_id: 13,
        scope: leaves[1].scope,
        state: "current" as const,
    }];
    const result = await behavior.methods.signed.execute({
        leaves: leaves.slice(0, 2),
        requests,
        stdout: '{"certificates":[]}',
    }, context());
    assertEquals(JSON.parse(result.publish_json), {
        leaves: leaves.slice(0, 2),
        certificates: [],
    });
    await assertRejects(() =>
        behavior.methods.signed.execute({
            leaves: leaves.slice(0, 2),
            requests,
            stdout: '{"certificates":[{}]}',
        }, context())
    );
});
Deno.test("native Node batch stages once and uses one serial SSH model per Node child", async () => {
    const workflow = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-route-certificate-publish.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: {
            steps: {
                name: string;
                dependsOn?: unknown;
                task: {
                    behaviorType?: string;
                    behaviorName?: string;
                    globalArgs?: unknown;
                    inputs: {
                        direction?: string;
                        stdin?: string;
                        env?: Record<string, string>;
                    };
                };
            }[];
        }[];
    };
    const steps = workflow.jobs[0].steps;
    const ssh = steps.filter((s) => s.task.behaviorType === "@swamp/ssh");
    assertEquals(
        new Set(ssh.map((s) => JSON.stringify(s.task.globalArgs))).size,
        1,
    );
    assertEquals(new Set(ssh.map((s) => s.task.behaviorName)).size, 1);
    assertEquals(ssh.filter((s) => s.name === "copy-assets").length, 1);
    const sign = steps.find((s) => s.name === "sign")!.task.inputs as {
        env: Record<string, string>;
    };
    assertEquals(sign.env.ORBIT_CERTIFICATE_MODE, "node");
    assertEquals(sign.env.ORBIT_CERTIFICATE_REQUESTS, undefined);
    assertEquals(
        steps.find((s) => s.name === "copy-requests")!.task.inputs.direction,
        "from",
    );
    for (const name of ["request", "publish", "trust", "inspect"]) {
        assert(steps.find((s) => s.name === name)!.task.inputs.stdin);
    }
    assertEquals(steps.find((s) => s.name === "cleanup-assets")!.dependsOn, [{
        step: "inspect",
        condition: { type: "succeeded" },
    }]);
});

Deno.test("every publishing caller groups its full affected leaf set by Node", async () => {
    const workflow = targetWorkflow() as {
        jobs: {
            steps: {
                name: string;
                forEach?: { in: string };
                task: { inputs: Record<string, unknown> };
            }[];
        }[];
    };
    const prepare = workflow.jobs[0].steps.find((step) =>
        step.name === "prepare-certificates"
    )!;
    assertEquals(
        prepare.forEach!.in,
        "${{ inputs.intent.effectful ? " +
            nodeCertificateGroups("inputs.intent.preparation.certificates") +
            " : [] }}",
    );
    assert(
        JSON.stringify(prepare.task.inputs).includes(
            "inputs.intent.preparation.certificates.filter(leaf,leaf.node_id == self.part.node_id)",
        ),
    );
    const analytics = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-operation-analytics-enable-direct.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: {
            name: string;
            steps: {
                forEach: { in: string };
                task: { inputs: { intent: { leaves: string } } };
            }[];
        }[];
    };
    const step =
        analytics.jobs.find((job) => job.name === "certificates")!.steps[0];
    assertEquals(
        step.forEach.in,
        "${{ " + nodeCertificateGroups("inputs.intent.certificates", "leaf") +
            " }}",
    );
    assertEquals(
        step.task.inputs.intent.leaves,
        "${{ inputs.intent.certificates.filter(cert,cert.leaf.node_id == self.item.leaf.node_id).map(cert,cert.leaf) }}",
    );
});
