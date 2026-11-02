import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import { z } from "npm:zod@4.4.3";
import { roleCertificates } from "./role_certificates.ts";
import { roleCertificateJobs } from "./role_certificates_workflow.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const args = {
    operation_id: 17,
    node_id: 13,
    kind: "node.role_add" as const,
    run_id: "46dff6be-7de2-4985-b891-6ebcd0897656",
};
const leaf = {
    node_id: 14,
    scope: "role-metrics" as const,
    domain: "metrics.orbit",
    version: "0123456789abcdef",
    root_hash: "a".repeat(64),
};
class Fixture {
    current = {
        id: 17,
        node_id: 13,
        kind: "node.role_add",
        status: "running",
        cancel_requested: false,
        input: {
            certificates: {
                leaves: [leaf],
                withdrawals: [],
                connections: [{
                    node_id: leaf.node_id,
                    ssh: {
                        control_node_id: 209 as const,
                        identity_file:
                            "/etc/orbit/swamp-role-worker/ssh-identity" as const,
                        public_key: "ssh-ed25519 fixture",
                        target: {
                            name: "target" as const,
                            host: "10.44.0.14",
                            user: "orbit",
                            port: 22,
                            host_keys: ["ssh-ed25519 pinned"],
                        },
                    },
                }],
            },
        },
    };
    writes: unknown[] = [];
    posts: Record<string, unknown>[] = [];
    ctx = {
        globalArgs: {
            gatewayUrl: "https://gateway.test",
            caFile: "/test/ca.pem",
        },
        writeResource: (_spec: string, _name: string, value: unknown) => {
            this.writes.push(value);
            return Promise.resolve({});
        },
    };
    http: Http = (method, _path, body) => {
        if (method === "POST") {
            this.posts.push(z.record(z.string(), z.unknown()).parse(body));
        }
        return Promise.resolve({ status: 200, body: { data: this.current } });
    };
    behavior = roleCertificates(() => this.http);
}

Deno.test("role certificate inventory preserves admitted public leaves and exact effect Nodes", async () => {
    const f = new Fixture();
    assertEquals(await f.behavior.methods.inventory.execute(args, f.ctx), {
        ...f.current.input.certificates,
        node_ids: [14],
    });
    assertEquals(f.posts, []);
});

Deno.test("role certificate inventory refuses changed roots and duplicate placements before effects", async () => {
    for (
        const update of [
            { id: 18 },
            { node_id: 209 },
            { kind: "node.role_remove" },
            { status: "succeeded" },
        ]
    ) {
        const f = new Fixture();
        Object.assign(f.current, update);
        await assertRejects(() =>
            f.behavior.methods.inventory.execute(args, f.ctx)
        );
        assertEquals(f.writes, []);
    }
    const f = new Fixture();
    f.current.input.certificates.leaves.push(leaf);
    await assertRejects(() =>
        f.behavior.methods.inventory.execute(args, f.ctx)
    );
});

Deno.test("role certificate pre-effect cancellation refuses inventory but finished effects still record", async () => {
    const f = new Fixture();
    f.current.cancel_requested = true;
    await assertRejects(() =>
        f.behavior.methods.inventory.execute(args, f.ctx)
    );
    await f.behavior.methods.record.execute(
        { ...args, target_node_id: 14 },
        f.ctx,
    );
    assertEquals(f.posts.length, 1);
    assertEquals(f.posts[0].status, "running");
    assertEquals(typeof f.posts[0].started_at, "string");
    assertEquals(f.posts[0].result, {
        publication_phase: { phase: "certificates_published", node_id: 14 },
    });
    assertEquals(f.posts[0].run_id, args.run_id);
    assertEquals(f.writes, [{ phase: "certificates_published", node_id: 14 }]);
});

Deno.test("role certificate recording cannot select a foreign effect Node", async () => {
    const f = new Fixture();
    await assertRejects(() =>
        f.behavior.methods.record.execute(
            { ...args, target_node_id: 209 },
            f.ctx,
        )
    );
    assertEquals(f.posts, []);
    assertEquals(f.writes, []);
});

Deno.test("role certificate jobs use the native child before root-only phase writes", () => {
    const generated = roleCertificateJobs("baseline_job", {
        blocked: "!steps.admit.outputs.ready",
    });
    const jobs = generated.jobs as {
        name: string;
        dependsOn: unknown;
        steps: {
            labels?: unknown;
            guard?: string;
            task: Record<string, unknown>;
        }[];
    }[];
    let previous = "baseline_job";
    for (const job of jobs) {
        assertEquals(job.dependsOn, [{
            job: previous,
            condition: { type: "succeeded" },
        }]);
        assertEquals(job.steps[0].guard, "${{ !steps.admit.outputs.ready }}");
        previous = job.name;
    }
    assertEquals(jobs.length, 4);
    const child = jobs[1].steps[0];
    assertEquals(child.labels, { role: "swamp" });
    assertEquals(child.task.type, "workflow");
    assertEquals(child.task.workflowIdOrName, "node-role-certificate-publish");
    const inputs = child.task.inputs as Record<string, unknown>;
    assertEquals(inputs.parent_run_id, "${{ run.id }}");
    const ssh = inputs.ssh as {
        name: string;
        transport: Record<string, unknown>;
        hosts: { transport: Record<string, unknown> }[];
    };
    assertEquals(
        ssh.name,
        '${{ "role-certificate-" + run.id + "-" + string(self._index) }}',
    );
    assertEquals(
        ssh.transport.identityFile,
        "${{ steps.role_certificates_inventory.outputs.connections.filter(c, c.node_id == self.leaf.node_id)[0].ssh.identity_file }}",
    );
    assertEquals(
        ssh.hosts[0].transport.hostKeys,
        "${{ steps.role_certificates_inventory.outputs.connections.filter(c, c.node_id == self.leaf.node_id)[0].ssh.target.host_keys }}",
    );
    assertEquals(jobs[2].steps[0].task.methodName, "record");
    assertEquals(
        jobs[2].steps[0].labels,
        { node: "${{ string(inputs.node_id) }}" },
    );
    assertEquals(jobs[3].steps[0].task.methodName, "complete");
    assertEquals(jobs.at(-1)?.name, generated.lastJob);
    assertThrows(() =>
        roleCertificateJobs("baseline", { prefix: 'bad"prefix' })
    );
});

Deno.test("role inventory refuses missing duplicate or foreign SSH targets before children", async () => {
    for (const change of ["missing", "duplicate", "foreign"]) {
        const f = new Fixture();
        const plan = f.current.input.certificates;
        if (change === "missing") plan.connections = [];
        if (change === "duplicate") plan.connections.push(plan.connections[0]);
        if (change === "foreign") plan.connections[0].node_id = 209;
        await assertRejects(() =>
            f.behavior.methods.inventory.execute(args, f.ctx)
        );
        assertEquals(f.writes, []);
    }
});

Deno.test("a parent owns its aggregate certificate view without impersonating a standalone role kind", async () => {
    const f = new Fixture();
    f.current.kind = "node.add";
    await f.behavior.methods.inventory.execute(
        { ...args, kind: "node.add" },
        f.ctx,
    );
    assertEquals(f.posts, []);
    f.current.id++;
    await assertRejects(() =>
        f.behavior.methods.inventory.execute(
            { ...args, kind: "node.add" },
            f.ctx,
        )
    );
});
