import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { behavior, compare, planReads } from "./doctor-read/doctor.ts";
import { Families, Input, type Read } from "./doctor_contract.ts";
const node = (id = 9) => ({
    id,
    name: `node-${id}`,
    platform: "linux",
    architecture: "x86_64",
    user: "orbit",
    managed: true,
    parked: false,
    ssh: {
        host: `10.44.0.${id}`,
        user: "orbit",
        port: 22,
        host_keys: ["ssh-ed25519 fixture-public"],
    },
    roles: [{ id: 7, role: "router", status: "active", services: ["caddy"] }],
    resources: {
        node_checks: [{ status: "active" }],
        processes: [{
            id: 4,
            name: "web",
            runtime: "systemd",
            desired: "running",
            keep_alive: false,
            hibernation_path: null,
            input: { name: "orbit-process-4-web.service" },
        }],
    },
    observations: { freshness: "fresh", valid_until: 1000 },
});
const intent = () =>
    Input.parse({
        nodes: [node()],
        families: ["node", "role", "process"],
        collect_problems: false,
    });
const unit = (active = "active", sub = "running", load = "loaded") =>
    `LoadState=${load}\nActiveState=${active}\nSubState=${sub}\nNRestarts=49\n`;
const step = (stdout: string, exitCode = 0) => ({
    status: "succeeded",
    outputs: { stdout, exitCode },
});
const facts = (value = intent()) =>
    Object.fromEntries(
        planReads(value).map((read) => [
            read.key,
            step(
                read.check === "platform"
                    ? "Linux x86_64\n"
                    : read.check === "disk"
                    ? "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/root 10000000 1000000 9000000 10% /\n"
                    : read.check === "docker"
                    ? "orbit-process-4-web\trunning\n"
                    : unit(),
            ),
        ]),
    );
const report = (value = intent(), results = facts(value)) =>
    compare(value, results, 900).report as {
        healthy: boolean;
        nodes: {
            families: {
                family: string;
                checked: number;
                observed_resources: unknown[];
                observed_checks: Record<string, string[]>;
                issues: { code: string; kind: string }[];
            }[];
        }[];
        summary: { checks: number; drift: number; unverifiable: number };
    };
const codes = (value: ReturnType<typeof report>) =>
    value.nodes.flatMap((n) =>
        n.families.flatMap((f) => f.issues.map((i) => i.code))
    );
function readFor(value: ReturnType<typeof intent>, check: Read["check"]) {
    return planReads(value).find((r) => r.check === check)!;
}

Deno.test("one native exec per focused fact, without a script bundle or private material", () => {
    const value = intent(), reads = planReads(value);
    assertEquals(reads.map((r) => r.argv[0]), [
        "uname",
        "df",
        "systemctl",
        "systemctl",
        "systemctl",
    ]);
    assert(reads.every((r) => r.ssh.host === "10.44.0.9"));
    assert(!JSON.stringify(reads).includes("python"));
    assertEquals(report(value).healthy, true);
    assertEquals(report(value).summary.checks, 5);
});
Deno.test("all retired families return zero checks and no recovery scope", () => {
    const value = intent();
    value.families = Families.filter((f) =>
        !["node", "role", "process", "firewall"].includes(f)
    );
    assertEquals(planReads(value), []);
    for (const family of report(value, {}).nodes[0].families) {
        assertEquals(family.checked, 0);
        assertEquals(family.observed_resources, []);
        assertEquals(family.observed_checks, {});
    }
});
Deno.test("native commands expose limited Docker state and never Config.Env", () => {
    const value = intent();
    value.nodes[0].resources.processes[0].runtime = "docker";
    const read = readFor(value, "docker");
    assertEquals(read.argv, [
        "docker",
        "container",
        "ls",
        "--all",
        "--filter",
        "name=^/orbit-process-4-web.service$",
        "--format",
        "{{.Names}}\t{{.State}}",
    ]);
    const results = facts(value);
    results[read.key] = step("orbit-process-4-web.service\trunning\n");
    assertEquals(report(value, results).healthy, true);
    results[read.key] = step("");
    assert(codes(report(value, results)).includes("process.runtime_missing"));
    results[read.key] = step("", 1);
    assert(codes(report(value, results)).includes("process.inspection_failed"));
});
Deno.test("failed, absent, cancelled and malformed reads stay unverifiable without hiding sibling Nodes", () => {
    for (
        const output of [
            undefined,
            { status: "failed" },
            { status: "cancelled" },
            step("bad"),
            step("Linux x86_64", 1),
        ]
    ) {
        const value = intent();
        value.nodes.push({
            ...node(14),
            resources: {
                node_checks: [],
                processes: [],
                firewall_expected: [],
                firewall_rules: [],
            },
            roles: [],
        });
        const results = facts(value), key = readFor(value, "platform").key;
        if (output) results[key] = output as ReturnType<typeof step>;
        else delete results[key];
        const result = report(value, results);
        assert(codes(result).includes("node.inspection_failed"));
        assertEquals(
            result.nodes[1].families.every((f) => f.issues.length === 0),
            true,
        );
        assert(
            !result.nodes[0].families[0].observed_checks["9"].includes(
                "node.platform_mismatch",
            ),
        );
    }
});
Deno.test("Node identity and root disk thresholds compare native facts", () => {
    const value = intent(), results = facts(value);
    results[readFor(value, "platform").key] = step("Darwin arm64");
    results[readFor(value, "disk").key] = step(
        "Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/root 10000000 9000000 1000000 90% /\n",
    );
    assertEquals(codes(report(value, results)), [
        "node.platform_mismatch",
        "node.architecture_mismatch",
        "node.disk_low",
    ]);
});
Deno.test("freshness is checked when the result is compared, even after admission", () => {
    const value = intent(), results = facts(value);
    value.nodes[0].observations!.valid_until = 899;
    assert(codes(report(value, results)).includes("node.agent_view_stale"));
    value.nodes[0].observations!.valid_until = 1000;
    assert(!codes(report(value, results)).includes("node.agent_view_stale"));
});
Deno.test("missing/inactive agent and role services use separate finding codes", () => {
    const value = intent(), results = facts(value);
    results[readFor(value, "agent").key] = step(
        unit("inactive", "dead", "not-found"),
    );
    results[readFor(value, "service").key] = step(unit("failed", "failed"));
    assertEquals(codes(report(value, results)), [
        "node.agent_missing",
        "role.services_inactive",
    ]);
});
Deno.test("current auto-restart is crash evidence; cumulative NRestarts alone is not", () => {
    const value = intent(),
        results = facts(value),
        key = readFor(value, "systemd").key;
    assertEquals(codes(report(value, results)), []);
    results[key] = step(unit("activating", "auto-restart"));
    assertEquals(codes(report(value, results)), ["process.crash_loop"]);
    value.nodes[0].resources.processes[0].desired = "stopped";
    assertEquals(codes(report(value, results)), ["process.state_mismatch"]);
});
Deno.test("hibernation suppresses stopped state only on proven absent marker", () => {
    const value = intent();
    value.nodes[0].resources.processes[0].hibernation_path =
        "/dev/shm/orbit/hibernation/app-instance-1.awake";
    const results = facts(value);
    results[readFor(value, "systemd").key] = step(unit("inactive", "dead"));
    const key = readFor(value, "awake").key;
    results[key] = step("", 1);
    assertEquals(codes(report(value, results)), []);
    results[key] = step("");
    assertEquals(codes(report(value, results)), ["process.state_mismatch"]);
    delete results[key];
    assertEquals(codes(report(value, results)), ["process.inspection_failed"]);
});
Deno.test("recovery covers only compared codes, never a retired resource-wide check", () => {
    const result = report();
    const family = result.nodes[0].families[0];
    assertEquals(family.observed_resources, []);
    assert(!family.observed_checks["9"].includes("node.agent_secret_mismatch"));
    assert(family.observed_checks["9"].includes("node.agent_inactive"));
});
Deno.test("parked and unmanaged Nodes dispatch no SSH and do not close old incidents", () => {
    for (const override of [{ parked: true }, { managed: false }]) {
        const value = intent();
        Object.assign(value.nodes[0], override);
        assertEquals(planReads(value), []);
        assert(
            report(value, {}).nodes[0].families.every((f) =>
                Object.keys(f.observed_checks).length === 0
            ),
        );
    }
});
Deno.test("macOS uses uname and df without Linux systemctl or Process work", () => {
    const value = intent();
    value.nodes[0].platform = "macos";
    value.nodes[0].architecture = "arm64";
    assertEquals(planReads(value).map((r) => r.argv[0]), ["uname", "df"]);
    const results = facts(value);
    results[readFor(value, "platform").key] = step("Darwin arm64");
    assertEquals(report(value, results).healthy, true);
});
Deno.test("managed placement requires stored SSH pins", () => {
    const value = intent();
    delete value.nodes[0].ssh;
    assertThrows(() => planReads(value), Error, "doctor.ssh_missing");
});
Deno.test("typed result projects redacted collection and preserves a cursor on failed log read", async () => {
    const value = intent();
    value.collect_problems = true;
    const results = facts(value), saved: { stdout?: string } = {};
    const execute = () =>
        behavior.methods.result.execute({ intent: value, results }, {
            writeResource: (_s, _n, v) => {
                Object.assign(saved, v);
                return Promise.resolve();
            },
        });
    await execute();
    assertEquals(JSON.parse(saved.stdout!).logs, undefined);
    results["logs-0"] = step(
        JSON.stringify({
            cursor: { path: "/logs/laravel.log", inode: 1, offset: 50 },
            signals: [],
        }),
    );
    await execute();
    assertEquals(JSON.parse(saved.stdout!).logs.cursor.offset, 50);
});

Deno.test("one unavailable role service cannot clear a prior service failure", () => {
    const value = intent();
    value.nodes[0].roles[0].services.push("docker");
    const results = facts(value),
        reads = planReads(value).filter((r) => r.check === "service");
    delete results[reads[1].key];
    const role = report(value, results).nodes[0].families.find((f) =>
        f.family === "role"
    )!;
    assert(!role.observed_checks["7"].includes("role.services_inactive"));
    assert(role.observed_checks["7"].includes("role.lifecycle_not_active"));
});

Deno.test("native read failure recovery uses only the new Node check scope after every read succeeds", () => {
    const value = intent(), results = facts(value);
    const key = readFor(value, "disk").key;
    delete results[key];
    const failed = compare(value, results, 900).report as ReturnType<
        typeof report
    >;
    const family = failed.nodes[0].families[0];
    assert(
        !family.observed_checks["node:9"]?.includes("node.inspection_failed"),
    );
    assertEquals(
        (family.issues[0] as unknown as { resource_id: string }).resource_id,
        "node:9",
    );
    results[key] = facts(value)[key];
    const healthy = report(value, results).nodes[0].families[0];
    assert(
        healthy.observed_checks["node:9"].includes("node.inspection_failed"),
    );
    assert(!healthy.observed_checks["9"].includes("node.inspection_failed"));
});

Deno.test("Doctor matches source allows and their deny by tuple without false drift", () => {
    const comment = "orbit rex";
    const base = {
        comment,
        direction: "in",
        destination: "any",
        port: "7297",
        protocol: "tcp",
        in_interface: null,
        out_interface: null,
        family: "v4",
    };
    const value = Input.parse({
        nodes: [{
            ...node(),
            resources: {
                firewall_rules: [{ id: 11, name: "7297/tcp from any", status: "active" }],
                firewall_expected: [
                    {
                        name: "7297/tcp from any",
                        shape: {
                            ...base,
                            action: "allow",
                            source: "10.44.0.6",
                        },
                    },
                    {
                        name: "7297/tcp from any",
                        shape: { ...base, action: "deny", source: "any" },
                    },
                ],
            },
        }],
        families: ["firewall"],
        collect_problems: false,
    });
    const read = planReads(value)[0];
    assertEquals(read.argv, ["/usr/sbin/ufw", "status", "numbered"]);
    const allow = "7297/tcp ALLOW IN 10.44.0.6 # " + comment;
    const deny = "7297/tcp DENY IN Anywhere # " + comment;
    const trust = "Anywhere ALLOW IN 10.44.0.0/24 # orbit:wireguard-members";
    const observe = (rows: string[]) =>
        report(value, {
            [read.key]: step("Status: active\n" + rows.join("\n")),
        });
    assertEquals(codes(observe([allow, deny, trust])), []);
    assertEquals(codes(observe([allow.replace(" # " + comment, ""), deny, trust])), []);
    assert(codes(observe([deny, trust])).includes("firewall.rule_missing"));
    value.nodes[0].resources.firewall_rules[0].status = "failed";
    assert(
        codes(observe([allow, deny, trust])).includes(
            "firewall.lifecycle_not_active",
        ),
    );
});
