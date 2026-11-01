import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { assets } from "../node-worker-assets/assets.ts";
import { behavior } from "./assets.ts";
import {
    allPhases,
    budgets,
    Intent,
    phases,
    SwampRole,
    swampRolePlan,
} from "./plan.ts";
import { swampRoleConvergeSteps } from "./steps.ts";
const intent: Intent = {
    schema_version: 1,
    role: "swamp",
    node_id: 22,
    assignment_id: 13,
    created: true,
    user: "orbit",
    address: "10.44.0.19",
    services_proxy_source: "10.44.0.3",
    source_sha: "a".repeat(40),
    activate: false,
};
const worker = await assets(
    { node_id: 209, user: "orbit" },
    (path) =>
        Deno.readTextFile(
            new URL("../node-worker-assets/" + path, import.meta.url),
        ),
);
const ctx = {
    extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
    writeResource: () => Promise.resolve(),
};
Deno.test("Swamp role preserves captured TLS custody, worker capacities and the shared pin", () => {
    assertEquals(
        SwampRole.singleton && SwampRole.mutable && SwampRole.relocatable,
        true,
    );
    assertEquals(SwampRole.conflicts, []);
    const plan = swampRolePlan(intent, worker);
    assertEquals(plan.workers.map((w) => w.capacity), [7, 2, 2, 2]);
    const serve = plan.files.find((f) =>
        f.path.endsWith("orbit-swamp-serve.service")
    )!;
    assertEquals(
        serve.content.includes(
            "LoadCredential=orbit-worker-enrollment.key:/etc/orbit/swamp-issuer/enrollment-vault.key",
        ),
        true,
    );
    const services = plan.files.find((f) =>
        f.path.endsWith("orbit-swamp209-services.service")
    )!;
    assertEquals(
        services.content.includes("--label pool=services-local"),
        true,
    );
    assertEquals(
        services.content.includes(
            "--ca-cert=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
        ),
        true,
    );
    const tls = plan.files.find((f) => f.path.endsWith("serve-tls/Caddyfile"))!;
    assertEquals(tls.owner, "orbit:orbit");
    assertEquals(tls.mode, "0600");
    assertEquals(tls.content.includes("bind 10.44.0.19"), true);
    assertEquals(
        tls.content.includes(
            "serve-tls/cert.pem /home/orbit/.config/swamp/serve-tls/key.pem",
        ),
        true,
    );
    assertEquals(tls.content.includes("127.0.0.1:9418"), true);
    assertEquals(
        plan.files.some((f) => /\/(cert|key)\.pem$/.test(f.path)),
        false,
    );
    assertEquals(plan.files.some((f) => f.path.endsWith("serve.yaml")), false);
    assertEquals(plan.install.artifact.sha256, worker.artifact_sha256);
    assertEquals(
        plan.files.find((f) => f.path === worker.unit_path)!.content,
        worker.unit,
    );
    assertEquals(
        plan.files.find((f) => f.path === worker.checker_path)!.content,
        worker.checker,
    );
});
Deno.test("Swamp contribution resolves assets once and bounds every SSH phase", () => {
    const steps = swampRoleConvergeSteps(
        "target",
        "inputs.intent.target",
        {
            name: "role-ssh",
            transport: { user: "orbit", port: 22, identityFile: "/key" },
        },
        "prepared",
        "inputs.skip",
    );
    assertEquals(
        steps.filter((s) =>
            (s.task as Record<string, unknown>).methodName === "bundle"
        ).length,
        1,
    );
    for (const step of steps) {
        assertEquals(step.target, "orbit-node-209");
        assertEquals(step.queueTimeout, 30);
    }
    for (const phase of phases) {
        const step = steps.find((s) => s.name === "target_" + phase)!;
        const inputs = (step.task as Record<string, unknown>).inputs as Record<
            string,
            unknown
        >;
        assertEquals((inputs.argv as unknown[]).slice(0, 2), [
            "/usr/bin/timeout",
            String(budgets[phase]),
        ]);
        assertEquals(inputs.timeoutSec, budgets[phase] + 10);
        if (phase === "units") {
            assertEquals((inputs.argv as unknown[]).slice(2), [
                "/usr/bin/systemctl",
                "daemon-reload",
            ]);
        }
    }
    assertEquals(JSON.stringify(steps).includes("${{ steps }}"), false);
});
Deno.test("image preparation cannot claim activated role or publication proof", async () => {
    const statuses = Object.fromEntries(
        allPhases.map((
            p,
        ) => [
            p,
            ["firewall", "activation", "verify"].includes(p)
                ? "skipped"
                : "succeeded",
        ]),
    );
    const result = await behavior.methods.complete.execute(
        { intent, statuses },
        ctx,
    );
    assertEquals(result.prepared, true);
    assertEquals(result.role_proven, false);
    assertEquals(result.runtime_proven, false);
    assertEquals(result.publication_proven, false);
    const failure = await behavior.methods.complete.execute({
        intent: { ...intent, activate: true },
        statuses: {
            ...statuses,
            firewall: "succeeded",
            activation: "succeeded",
            verify: "failed",
        },
    }, ctx);
    assertEquals(failure.prepared, true);
    assertEquals(failure.role_proven, false);
    assertEquals(failure.failed_step, "verify");
});
Deno.test("Swamp bundle contains public files and rejects private extra intent fields", async () => {
    const bundle = await behavior.methods.bundle.execute(
        { intent, worker },
        ctx,
    );
    try {
        const plan = JSON.parse(
            await Deno.readTextFile(bundle.directory + "/plan.json"),
        );
        assertEquals(
            plan.credential_files.every((r: Record<string, unknown>) =>
                Object.keys(r).sort().join() === "owner,path"
            ),
            true,
        );
        await assertRejects(async () => {
            Intent.parse({ ...intent, server_token: "forbidden" });
        });
    } finally {
        await Deno.remove(bundle.directory, { recursive: true });
    }
});
