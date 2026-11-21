import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior, MAXIMUM_ROWS, report, ReportArgs } from "./assets.ts";

const NOW = new Date("2026-11-02T07:07:00Z");
const at = (hours: number) =>
    new Date(NOW.getTime() + hours * 3_600_000).toISOString();
const access = (
    name: string,
    hours: number,
    state = "active",
    principalId = "worker:x",
) => ({
    name,
    state,
    principalId,
    expiresAt: at(hours),
});
const enrollment = (name: string, hours: number, state = "enrolled") => ({
    name,
    state,
    expiresAt: at(hours),
});
const certificate = "notAfter=Nov  7 20:56:22 2027 GMT\n";
const lists = (
    accessRows: unknown[],
    enrollmentRows: unknown[],
) => ({
    access: JSON.stringify(accessRows),
    enrollment: JSON.stringify(enrollmentRows),
    certificate,
    held: "",
});
const pair = [access("orbit-node-209-access", 500)];
const pairEnrollment = [enrollment("orbit-node-209", 500)];

Deno.test("each expiry class alerts by its own reason; ok and no_expiry stay quiet", () => {
    const result = report(
        lists(
            [
                access("orbit-node-209-access", 5),
                access("old", -1, "revoked"),
                access("orbit-gateway-read", 400),
                { ...access("forever", 1), expiresAt: null },
            ],
            [enrollment("orbit-node-209", 30), enrollment("orbit-node-13", -2)],
        ),
        NOW,
    );
    assertEquals(result.alerts.map((item) => [item.name, item.reason]), [
        ["orbit-node-13", "expired"],
        ["orbit-node-209-access", "within_6h"],
        ["orbit-node-209", "within_72h"],
    ]);
    assertEquals(result.checked_at, NOW.toISOString());
});

Deno.test("class boundaries follow the hours left", () => {
    for (
        const [hours, reason] of [[72, "within_72h"], [6, "within_6h"], [
            0,
            "expired",
        ]] as const
    ) {
        const result = report(
            lists([...pair, access("x", hours)], pairEnrollment),
            NOW,
        );
        assertEquals(result.alerts.map((item) => item.reason), [reason]);
    }
    assertEquals(
        report(lists([...pair, access("x", 100)], pairEnrollment), NOW).alerts,
        [],
    );
});

Deno.test("retired orbit-node-209 pair is not reported missing when absent", () => {
    assertEquals(report(lists([], []), NOW).alerts, []);
});

Deno.test("local --repo-dir access list recorded fixture (envelope shape) is parsed cleanly", async () => {
    const fixture = await Deno.readTextFile(
        new URL("fixtures/access-token-list-local.json", import.meta.url),
    );
    const result = report(
        {
            access: fixture,
            enrollment: JSON.stringify([enrollment("swamp-role-209-a", 500)]),
            certificate,
            held: "serve-driver-20261121\nswamp-role-209-a-access-20261121\n",
        },
        NOW,
    );
    assertEquals(result.alerts, []);
});

Deno.test("a changed native shape or certificate fails the run", () => {
    assertThrows(
        () =>
            report(
                { ...lists(pair, pairEnrollment), access: '{"unexpected":[]}' },
                NOW,
            ),
        Error,
        "access list changed shape",
    );
    assertThrows(
        () =>
            report({ ...lists(pair, pairEnrollment), access: "not json" }, NOW),
        Error,
        "access list is not JSON",
    );
    assertThrows(
        () => report({ ...lists(pair, pairEnrollment), certificate: "" }, NOW),
        Error,
        "openssl returned no notAfter",
    );
    assertThrows(
        () =>
            report(
                lists([...pair, {
                    ...access("x", 1),
                    expiresAt: "2026-11-02T08:00:00",
                }], pairEnrollment),
                NOW,
            ),
        Error,
        "no time zone",
    );
});

Deno.test("the report stays bounded, soonest first, and skips week-old expiries", () => {
    const many = [
        ...[0, 1, 2].map((n) => access(`stale-${n}`, -200)),
        ...Array.from(
            { length: 400 },
            (_, n) => access(`live-${n}`, (n + 1) / 10),
        ),
    ];
    const result = report(lists([...many, ...pair], pairEnrollment), NOW);
    assertEquals(result.alerts.length, MAXIMUM_ROWS);
    assertEquals(result.alerts[0].name, "live-0");
    assertEquals(
        result.alerts.some((item) => item.name.startsWith("stale-")),
        false,
    );
});

Deno.test("the report is written as a native resource and the manifest version matches", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.credential_report.execute(
        lists(pair, pairEnrollment),
        {
            writeResource: (...args) => Promise.resolve(writes.push(args)),
        },
    );
    assertEquals(writes, [["credential_report", "report", result]]);
    const manifest = parse(
        await Deno.readTextFile(new URL("manifest.yaml", import.meta.url)),
    ) as { version: string };
    assertEquals(manifest.version, behavior.version);
});

Deno.test("the monitor reads only what the Node's service key may read and passes each source to the report", async () => {
    const workflow = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-swamp-credential-monitor.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: {
            steps: {
                name: string;
                task: { inputs: Record<string, string> };
            }[];
        }[];
    };
    const steps = workflow.jobs[0].steps;
    const check = steps.find((step) => step.name === "check")!;
    const sources = steps.filter((step) => step !== check);
    assertEquals(
        Object.keys(check.task.inputs).sort(),
        Object.keys(ReportArgs.shape).sort(),
    );
    assertEquals(
        sources.map((step) => step.name).sort(),
        Object.keys(ReportArgs.shape).sort(),
    );
    // `swamp auth` needs a personal Swamp Club account; a Node has none.
    for (const step of sources) {
        const run = step.task.inputs.run;
        assertEquals(
            run.startsWith(
                "SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key timeout 30 /usr/local/bin/swamp ",
            ) || run.startsWith("timeout 30 openssl x509 ") ||
                (step.name === "held" &&
                    run.includes("for f in /home/orbit/.config/swamp/serve-driver-token")),
            true,
            step.name,
        );
        assertEquals(run.includes("swamp auth "), false, step.name);
    }
    // Canary: the held step must never print an undelimited secret from a credential file.
    const heldStep = sources.find((step) => step.name === "held")!;
    const folder = await Deno.makeTempDir();
    try {
        const canary = "CANARY_SECRET_NO_DOT_MONITOR";
        await Deno.mkdir(`${folder}/home/.config/swamp`, { recursive: true });
        await Deno.mkdir(`${folder}/worker/a`, { recursive: true });
        await Deno.writeTextFile(
            `${folder}/home/.config/swamp/serve-driver-token`,
            `${canary}\n`,
        );
        await Deno.writeTextFile(
            `${folder}/worker/a/server.token`,
            `swamp-role-209-a-access-20261121.fake-secret-ok\n`,
        );
        await Deno.writeTextFile(
            `${folder}/worker/a/enrollment.token`,
            `${canary}\n`,
        );
        const script = heldStep.task.inputs.run
            .replace("/home/orbit/.config/swamp/serve-driver-token", `${folder}/home/.config/swamp/serve-driver-token`)
            .replaceAll("/etc/orbit/swamp-role-worker", `${folder}/worker`);
        const done = await new Deno.Command("sh", {
            args: ["-c", script],
            stdout: "piped",
            stderr: "piped",
        }).output();
        const stdout = new TextDecoder().decode(done.stdout);
        const stderr = new TextDecoder().decode(done.stderr);
        assertEquals(done.code, 0, stderr);
        assertEquals(stdout.trim(), "swamp-role-209-a-access-20261121");
        assertEquals((stdout + stderr).includes(canary), false);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("a generation is skipped only when a consumer holds a newer one of its family and principal", () => {
    const rows = [
        ...pair,
        access("serve-driver", 2, "active", "user:nckrtl"),
        access("serve-driver-20261121", 700, "active", "user:nckrtl"),
        // Same family name but different principal must NOT be suppressed.
        access("serve-driver-20261001", 10, "active", "user:other"),
        access("swamp-role-209-b-access", 50, "active", "worker:orbit-node-209"),
        access("swamp-role-209-b-access-20261121", 700, "active", "worker:orbit-node-209"),
        // The Gateway env is not visible: an installed reader near expiry alerts even beside a fresh mint whose install failed.
        access("orbit-gateway-read-20261027", 50, "active", "user:orbit-gateway-read"),
        access("orbit-gateway-read-20261121", 700, "active", "user:orbit-gateway-read"),
    ];
    const alerts = (held: string) =>
        report({ ...lists(rows, pairEnrollment), held }, NOW).alerts.map((item) => [item.name, item.reason]);
    assertEquals(alerts("serve-driver-20261121\nswamp-role-209-b-access\nswamp-role-209-b\n"), [
        ["serve-driver-20261001", "within_72h"],
        ["swamp-role-209-b-access", "within_72h"],
        ["orbit-gateway-read-20261027", "within_72h"],
    ]);
    // Nothing held: no generation is skipped.
    assertEquals(alerts("").map(([name]) => name), [
        "serve-driver",
        "serve-driver-20261001",
        "swamp-role-209-b-access",
        "orbit-gateway-read-20261027",
    ]);
});

Deno.test("orbit-orchestrator-read, renewed by no fleet consumer, alerts ten days ahead", () => {
    const alerts = (hours: number) =>
        report(
            lists([...pair, access("orbit-orchestrator-read-20261121", hours)], pairEnrollment),
            NOW,
        ).alerts.map((item) => item.reason);
    assertEquals(alerts(241), []);
    assertEquals(alerts(240), ["within_10d"]);
    assertEquals(alerts(72), ["within_72h"]);
    assertEquals(
        report(lists([...pair, access("x", 200)], pairEnrollment), NOW).alerts,
        [],
    );
});
