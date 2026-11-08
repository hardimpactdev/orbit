/** The rule planner and check against the ufw double; no host firewall calls. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior, check, inventory, plan, Refusal } from "./plan.ts";
import { UfwDouble } from "./native_fixture.ts";
import { partialApplication } from "./fixtures/try-10-partial-application.ts";

type Changes = Record<string, unknown>;
const intent = (ensure: string, changes: Changes) => ({
    rule: {
        node_id: 9,
        action: "allow",
        port: "443",
        protocol: "tcp",
        source: "192.0.2.1",
        comment: "orbit web",
        ...changes,
    },
    ensure,
});

/** Read, run every planned argv, read again after changes and check: the workflow's steps in order. A plan without changes checks the first read. */
function converge(ufw: UfwDouble, changes: Changes = {}, ensure = "present") {
    const first = ufw.status();
    const commands = plan(intent(ensure, changes), first);
    for (const argv of commands) ufw.apply(argv);
    const last = commands.length === 0 ? first : ufw.status();
    return {
        commands,
        result: check(intent(ensure, changes), last, commands.length),
    };
}

function refusal(compute: () => unknown) {
    try {
        compute();
    } catch (error) {
        if (error instanceof Refusal || error instanceof Error) {
            return error.message;
        }
    }
    throw new Error("Expected a refusal");
}

Deno.test("any writes one commented line per protocol and family above broad managed lines", () => {
    const ufw = new UfwDouble();
    const { result } = converge(ufw, { protocol: "any", source: "any" });
    const before = structuredClone(ufw.rows);
    const repeat = converge(ufw, { protocol: "any", source: "any" });
    assertEquals(repeat.commands, []);
    assertEquals(repeat.result, {
        backend: "active",
        skipped: "firewall.rule_exists",
    });
    assertEquals(result, { backend: "active" });
    assertEquals(ufw.tuples(), [
        ["443/tcp", "allow", "Anywhere"],
        ["443/udp", "allow", "Anywhere"],
        ["Anywhere on orbit", "allow", "10.44.0.0/24"],
        ["22/tcp (v6)", "allow", "Anywhere (v6)"],
        ["443/tcp (v6)", "allow", "Anywhere (v6)"],
        ["443/udp (v6)", "allow", "Anywhere (v6)"],
    ]);
    assertEquals([
        ...new Set(
            ufw.rows.filter((row) => row.to.startsWith("443")).map((row) =>
                row.comment
            ),
        ),
    ], ["orbit web"]);
    assertEquals(before, ufw.rows);
});

for (
    const { name, source, ipv4, ipv6 } of [
        {
            name: "both families",
            source: "any",
            ipv4: "Anywhere",
            ipv6: "Anywhere (v6)",
        },
        {
            name: "IPv4 only",
            source: "192.0.2.0/24",
            ipv4: "192.0.2.0/24",
            ipv6: null,
        },
        {
            name: "IPv6 only",
            source: "2001:db8::/64",
            ipv4: null,
            ipv6: "2001:db8::/64",
        },
    ]
) {
    Deno.test(`an allow range with protocol any converges narrow-first for ${name} and repeats without commands`, () => {
        const ufw = new UfwDouble();
        const range = { port: "8000:8100", protocol: "any", source };
        converge(ufw, { ...range, source: "192.0.2.1" });
        converge(ufw, { ...range, source: "2001:db8::1" });
        const narrow = structuredClone(ufw.rows);
        const { commands, result } = converge(ufw, range);
        const expected4 = ipv4 === null ? [] : [
            ["8000:8100/tcp", "allow", ipv4],
            ["8000:8100/udp", "allow", ipv4],
        ];
        const suffix = source === "any" ? " (v6)" : "";
        const expected6 = ipv6 === null ? [] : [
            [`8000:8100/tcp${suffix}`, "allow", ipv6],
            [`8000:8100/udp${suffix}`, "allow", ipv6],
        ];

        assertEquals(commands.length, expected4.length + expected6.length);
        assertEquals(
            commands.map((argv) => argv[argv.indexOf("port") + 1]),
            commands.map(() => "8000:8100"),
        );
        assertEquals(result, { backend: "active" });
        assertEquals(ufw.tuples(), [
            ["8000:8100/tcp", "allow", "192.0.2.1"],
            ["8000:8100/udp", "allow", "192.0.2.1"],
            ...expected4,
            ["Anywhere on orbit", "allow", "10.44.0.0/24"],
            ["22/tcp (v6)", "allow", "Anywhere (v6)"],
            ["8000:8100/tcp", "allow", "2001:db8::1"],
            ["8000:8100/udp", "allow", "2001:db8::1"],
            ...expected6,
        ]);
        assertEquals(
            ufw.rows.filter((row) =>
                narrow.some((old) =>
                    old.to === row.to && old.source === row.source
                )
            ),
            narrow,
        );
        const applied = structuredClone(ufw.rows);
        const repeat = converge(ufw, range);
        assertEquals(repeat.commands, []);
        assertEquals(repeat.result, {
            backend: "active",
            skipped: "firewall.rule_exists",
        });
        assertEquals(ufw.rows, applied);
    });
}

Deno.test("a deny sits above wireguard trust and an allowed source above the deny", () => {
    const ufw = new UfwDouble();
    converge(ufw, { action: "deny", port: "5432", source: "any" });
    converge(ufw, { port: "5432", source: "10.44.0.6" });
    assertEquals(ufw.tuples().slice(0, 3), [
        ["5432/tcp", "allow", "10.44.0.6"],
        ["5432/tcp", "deny", "Anywhere"],
        ["Anywhere on orbit", "allow", "10.44.0.0/24"],
    ]);
});

Deno.test("narrower rules sit above broader ones whatever their action", () => {
    const ufw = new UfwDouble();
    converge(ufw, { action: "deny", port: "5432", source: "any" });
    converge(ufw, { port: "5432", source: "192.0.2.10" });
    const first = ufw.tuples("5432");
    converge(ufw, { port: "25", source: "any" });
    converge(ufw, { action: "deny", port: "25", source: "192.0.2.10" });
    converge(ufw, { port: "25", source: "192.0.2.0/24" });
    const second = ufw.tuples("25").slice(0, 3);
    converge(ufw, { action: "deny", port: "20:30", source: "192.0.0.0/16" });
    const order = () =>
        ufw.tuples().filter(([to]) =>
            !to.endsWith("(v6)") && ["25", "20"].includes(to.slice(0, 2))
        );
    const third = order();
    converge(ufw, { port: "25", source: "192.0.2.0/24" }, "absent");
    const narrow = ["25/tcp", "deny", "192.0.2.10"],
        network = ["25/tcp", "allow", "192.0.2.0/24"];
    const broad = ["25/tcp", "allow", "Anywhere"],
        range = ["20:30/tcp", "deny", "192.0.0.0/16"];
    assertEquals(first, [
        ["5432/tcp", "allow", "192.0.2.10"],
        ["5432/tcp", "deny", "Anywhere"],
        ["5432/tcp (v6)", "deny", "Anywhere (v6)"],
    ]);
    assertEquals(second, [narrow, network, broad]);
    assertEquals(third, [narrow, network, range, broad]);
    assertEquals(order(), [narrow, range, broad]);
});

Deno.test("a misplaced line moves, and a racing writer fails the check", () => {
    const ufw = new UfwDouble();
    ufw.rows.splice(1, 0, {
        to: "80/tcp",
        action: "allow",
        source: "Anywhere",
        comment: "",
    }, {
        to: "80/tcp",
        action: "deny",
        source: "192.0.2.10",
        comment: "orbit",
    });
    const moved = converge(ufw, {
        port: "80",
        source: "192.0.2.10",
        action: "deny",
        comment: "orbit",
    });
    assertEquals(moved.commands.map((argv) => argv[3]), ["--force", "insert"]);
    assertEquals(ufw.tuples("80"), [["80/tcp", "deny", "192.0.2.10"], [
        "80/tcp",
        "allow",
        "Anywhere",
    ]]);
    ufw.misplace = true;
    assertEquals(
        refusal(() =>
            converge(ufw, { port: "80", source: "192.0.2.11", action: "deny" })
        ),
        "firewall.verify_failed",
    );
});

Deno.test("matching ignores the comment and removal keeps other tuples", () => {
    const ufw = new UfwDouble();
    ufw.rows.unshift({
        to: "443/tcp",
        action: "allow",
        source: "192.0.2.1",
        comment: "set by hand",
    }, {
        to: "400:500/tcp",
        action: "allow",
        source: "192.0.2.1",
        comment: "orbit range",
    });
    converge(ufw);
    assertEquals(
        ufw.tuples().filter((row) => row.join() === "443/tcp,allow,192.0.2.1")
            .length,
        1,
    );
    assertEquals(converge(ufw, {}, "absent").result, { backend: "absent" });
    assert(
        !ufw.tuples().some(([to, , source]) =>
            to === "443/tcp" && source === "192.0.2.1"
        ),
    );
    assert(
        ufw.tuples().some((row) =>
            row.join() === "400:500/tcp,allow,192.0.2.1"
        ),
    );
});

Deno.test("a retained removal leaves the managed line", () => {
    const ufw = new UfwDouble();
    converge(ufw, { action: "deny", port: "25" });
    assertEquals(
        converge(ufw, { action: "deny", port: "25", retain: true }, "absent")
            .commands,
        [],
    );
    assert(ufw.tuples().some((row) => row.join() === "25/tcp,deny,192.0.2.1"));
});

Deno.test("an inactive firewall, a lost removal and invalid input refuse", () => {
    const ufw = new UfwDouble();
    ufw.active = false;
    assertEquals(refusal(() => converge(ufw)), "firewall.backend_inactive");
    ufw.active = true;
    converge(ufw);
    assertEquals(
        refusal(() => check(intent("absent", {}), ufw.status())),
        "firewall.remove_verify_failed",
    );
    for (
        const bad of [
            { port: "ssh" },
            { protocol: "icmp" },
            { comment: "orbit it's" },
            { comment: "web" },
            { port: "9:8" },
            {
                action: "allowlist",
            },
            { source: "10.0.0.300" },
        ]
    ) {
        assertEquals(
            refusal(() => plan(intent("present", bad), ufw.status())),
            "firewall.input_invalid",
            JSON.stringify(bad),
        );
    }
});

Deno.test("a line from one IPv6 address sits above the IPv6 deny and is found again", () => {
    const ufw = new UfwDouble();
    converge(ufw, { action: "deny", port: "80", source: "any" });
    const first = converge(ufw, { port: "80", source: "fd42::10" });
    assertEquals(first.result, { backend: "active" });
    const rows = ufw.tuples("80");
    assert(
        rows.findIndex(([, action, from]) =>
            action === "allow" && from === "fd42::10"
        ) <
            rows.findIndex(([to, action]) =>
                action === "deny" && to.endsWith("(v6)")
            ),
    );
    const repeat = converge(ufw, { port: "80", source: "fd42::10" });
    assertEquals(repeat.commands, []);
    assertEquals(repeat.result, {
        backend: "active",
        skipped: "firewall.rule_exists",
    });
});

Deno.test("a plan without changes reads ufw once, and changes are read back once", () => {
    const ufw = new UfwDouble();
    const created = converge(ufw);
    assertEquals(created.result, { backend: "active" });
    assertEquals(ufw.reads, 2);
    ufw.reads = 0;
    const repeated = converge(ufw);
    assertEquals(repeated.commands, []);
    assertEquals(repeated.result, {
        backend: "active",
        skipped: "firewall.rule_exists",
    });
    assertEquals(ufw.reads, 1);
    ufw.reads = 0;
    const removed = converge(ufw, {}, "absent");
    assertEquals(removed.result, { backend: "absent" });
    assertEquals(ufw.reads, 2);
    ufw.reads = 0;
    assertEquals(converge(ufw, {}, "absent").result, { backend: "absent" });
    assertEquals(ufw.reads, 1);
});

Deno.test("numbered inserts count unrelated LIMIT, REJECT and outgoing rows", () => {
    const unrelated = [
        "[ 1] 22/tcp LIMIT IN Anywhere # hand-owned",
        "[ 2] 25/tcp ALLOW OUT Anywhere",
        "[ 3] 80/tcp REJECT IN Anywhere",
        "[ 4] 443/tcp ALLOW FWD 192.0.2.1",
        "[ 5] custom AUDIT IN Anywhere",
    ];
    const before = "Status: active\n" +
        [
            ...unrelated,
            "[ 6] Anywhere on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members",
        ].join("\n");
    const commands = plan(intent("present", {}), before);
    assertEquals(commands.length, 1);
    assertEquals(commands[0].slice(3, 5), ["insert", "6"]);
    const after = "Status: active\n" +
        [
            ...unrelated,
            "[ 6] 443/tcp ALLOW IN 192.0.2.1 # orbit web",
            "[ 7] Anywhere on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members",
        ].join("\n");
    assertEquals(plan(intent("present", {}), after), []);
    assertEquals(check(intent("present", {}), after), { backend: "active" });
});

for (const source of ["0.0.0.0/0", "::/0"]) {
    Deno.test(`an explicit ${source} affects only its family and repeats without duplicates`, () => {
        const ufw = new UfwDouble();
        const first = converge(ufw, { source, protocol: "any" });
        assertEquals(first.commands.length, 2);
        assertEquals(
            ufw.rows.filter((row) => row.to.startsWith("443")).length,
            2,
        );
        assertEquals(converge(ufw, { source, protocol: "any" }).result, {
            backend: "active",
            skipped: "firewall.rule_exists",
        });
    });
}

Deno.test("IPv4-embedded IPv6 is one IPv6 rule and matches UFW's equivalent spelling", () => {
    const source = "::ffff:192.0.2.1";
    const ufw = new UfwDouble();
    converge(ufw, { source });
    assertEquals(ufw.rows.filter((row) => row.to.startsWith("443")).length, 1);
    const row = ufw.rows.find((row) => row.to === "443/tcp")!;
    row.source = "::ffff:c000:201";
    assertEquals(converge(ufw, { source }).result, {
        backend: "active",
        skipped: "firewall.rule_exists",
    });
});

Deno.test("an interface-scoped source is preserved and does not stand in for an unrestricted allow", () => {
    const scoped = {
        to: "443/tcp",
        action: "allow",
        source: "192.0.2.1 on eth0",
        comment: "hand-owned",
    };
    const ufw = new UfwDouble([
        scoped,
        { to: "443/tcp", action: "deny", source: "Anywhere", comment: "" },
    ]);
    const applied = converge(ufw);
    assertEquals(applied.commands.length, 1);
    assertEquals(applied.commands[0].slice(3, 5), ["insert", "2"]);
    assertEquals(ufw.rows[0], scoped);
    assertEquals(ufw.rows.length, 3);
    assertEquals(converge(ufw).commands, []);
});

Deno.test("a both-protocol allow keeps the existing TCP line below unrelated managed ports and adds only UDP", () => {
    const existing = {
        to: "47443/tcp",
        action: "allow",
        source: "192.0.2.1",
        comment: "proof:existing",
    };
    const ufw = new UfwDouble([
        {
            to: "22/tcp",
            action: "allow",
            source: "Anywhere",
            comment: "orbit:public-ssh",
        },
        {
            to: "51820/udp",
            action: "allow",
            source: "Anywhere",
            comment: "orbit:wireguard",
        },
        existing,
        {
            to: "47443/tcp",
            action: "deny",
            source: "Anywhere",
            comment: "proof:deny",
        },
        {
            to: "47443/udp",
            action: "deny",
            source: "Anywhere",
            comment: "proof:deny",
        },
        ...new UfwDouble().rows,
    ]);
    const before = structuredClone(ufw.rows);
    const request = { port: "47443", protocol: "any" };
    const applied = converge(ufw, request);

    assertEquals(applied.commands, [[
        "timeout",
        "60",
        "/usr/sbin/ufw",
        "insert",
        "5",
        "allow",
        "in",
        "proto",
        "udp",
        "from",
        "192.0.2.1",
        "to",
        "any",
        "port",
        "47443",
        "comment",
        "orbit web",
    ]]);
    assertEquals(applied.result, { backend: "active" });
    assertEquals(
        ufw.rows.filter((row) =>
            row.to !== "47443/udp" || row.action !== "allow"
        ),
        before,
    );
    assertEquals(ufw.rows.filter((row) => row.comment === existing.comment), [
        existing,
    ]);
    assertEquals(converge(ufw, request).commands, []);
});

Deno.test("an equal TCP line below source-disjoint managed trust keeps its comment and only UDP is added", () => {
    const existing = {
        to: "47443/tcp",
        action: "allow",
        source: "10.232.2.11",
        comment: "proof:existing",
    };
    const ufw = new UfwDouble([
        ...new UfwDouble().rows.filter((row) => !row.to.includes("(v6)")),
        existing,
        {
            to: "47443/tcp",
            action: "deny",
            source: "Anywhere",
            comment: "proof:deny",
        },
        {
            to: "47443/udp",
            action: "deny",
            source: "Anywhere",
            comment: "proof:deny",
        },
    ]);
    const before = structuredClone(ufw.rows);
    const request = { port: "47443", protocol: "any", source: existing.source };
    const applied = converge(ufw, request);

    assertEquals(applied.commands, [[
        "timeout",
        "60",
        "/usr/sbin/ufw",
        "insert",
        "1",
        "allow",
        "in",
        "proto",
        "udp",
        "from",
        existing.source,
        "to",
        "any",
        "port",
        "47443",
        "comment",
        "orbit web",
    ]]);
    assertEquals(applied.result, { backend: "active" });
    assertEquals(
        ufw.rows.filter((row) =>
            row.to !== "47443/udp" || row.action !== "allow"
        ),
        before,
    );
    assertEquals(ufw.rows.filter((row) => row.comment === existing.comment), [
        existing,
    ]);
    assertEquals(converge(ufw, request).commands, []);
});

Deno.test("try-10 partial application preserves the actual TCP row below scoped trust and inserts only UDP", () => {
    const request = {
        port: "47372",
        protocol: "any",
        source: "10.232.2.11",
        comment: "orbit",
    };
    const before = inventory(partialApplication);
    const existing = before.find((row) => row.comment === "proof:existing")!;
    assertEquals(before[2], {
        to: "10.45.0.2 on orbit",
        action: "allow",
        direction: "in",
        source: "Anywhere",
        comment: "orbit:wireguard-members",
    });
    assertEquals(before[5], existing);
    const commands = plan(intent("present", request), partialApplication);
    assertEquals(commands, [[
        "timeout",
        "60",
        "/usr/sbin/ufw",
        "insert",
        "3",
        "allow",
        "in",
        "proto",
        "udp",
        "from",
        "10.232.2.11",
        "to",
        "any",
        "port",
        "47372",
        "comment",
        "orbit",
    ]]);
    const ufw = new UfwDouble(before);
    const original = ufw.rows[5];
    ufw.apply(commands[0]);
    // Keep the actual row, its comment, and all original rows in their original relative order.
    assert(ufw.rows[6] === original);
    assertEquals(ufw.rows[6], existing);
    assertEquals(ufw.rows.filter((_row, index) => index !== 2), before);
    assertEquals(
        check(intent("present", request), ufw.status(), commands.length),
        {
            backend: "active",
        },
    );
    const after = structuredClone(ufw.rows);
    const repeat = converge(ufw, request);
    assertEquals(repeat.commands, []);
    assertEquals(repeat.result, {
        backend: "active",
        skipped: "firewall.rule_exists",
    });
    assertEquals(ufw.rows, after);
});

Deno.test("managed trust agreeing with an allow does not hide an intervening operator deny", () => {
    const rows = inventory(partialApplication);
    [rows[5], rows[6]] = [rows[6], rows[5]];
    const ufw = new UfwDouble(rows);
    const request = { port: "47372", source: "10.232.2.11" };
    assertEquals(
        refusal(() => check(intent("present", request), ufw.status())),
        "firewall.verify_failed",
    );
    const applied = converge(ufw, request);
    assertEquals(applied.commands.map((argv) => argv[3]), [
        "--force",
        "insert",
    ]);
    assertEquals(applied.result, { backend: "active" });
    assertEquals(ufw.tuples("47372").slice(0, 2), [
        ["47372/tcp", "allow", "10.232.2.11"],
        ["47372/tcp", "deny", "Anywhere"],
    ]);
    assertEquals(converge(ufw, request).commands, []);
});

for (const to of ["Anywhere", "Anywhere on orbit", "10.45.0.2 on orbit"]) {
    Deno.test(`an overlapping managed allow to ${to} leaves the unrestricted existing allow intact`, () => {
        const rows = inventory(partialApplication);
        rows[2].to = to;
        const ufw = new UfwDouble(rows);
        const applied = converge(ufw, { port: "47372", source: "10.232.2.11" });
        assertEquals(applied.commands, []);
        assertEquals(applied.result, {
            backend: "active",
            skipped: "firewall.rule_exists",
        });
        assertEquals(ufw.rows, rows);
    });

    Deno.test(`an overlapping managed deny to ${to} still moves the unrestricted existing allow`, () => {
        const ufw = new UfwDouble([
            {
                to,
                action: "deny",
                source: "Anywhere",
                comment: "orbit:restricted",
            },
            {
                to: "47372/tcp",
                action: "allow",
                source: "10.232.2.11",
                comment: "proof:existing",
            },
        ]);
        const request = { port: "47372", source: "10.232.2.11" };
        assertEquals(
            refusal(() => check(intent("present", request), ufw.status())),
            "firewall.verify_failed",
        );
        const applied = converge(ufw, request);
        assertEquals(applied.commands.map((argv) => argv[3]), [
            "--force",
            "insert",
        ]);
        assertEquals(applied.result, { backend: "active" });
        assertEquals(ufw.rows[0].to, "47372/tcp");
        assertEquals(ufw.rows[1].to, to);
        assertEquals(converge(ufw, request).commands, []);
    });
}

Deno.test("a failed second allow keeps TCP and a retry adds only UDP for the same source", () => {
    const ufw = new UfwDouble();
    const request = intent("present", { protocol: "any" });
    const commands = plan(request, ufw.status());
    assertEquals(commands.length, 2);
    ufw.apply(commands[0]);
    const tcp = structuredClone(ufw.rows[0]);
    ufw.fail = true;
    assertEquals(
        refusal(() => ufw.apply(commands[1])),
        "firewall.apply_failed",
    );
    assertEquals(ufw.tuples("443"), [["443/tcp", "allow", "192.0.2.1"]]);

    ufw.fail = false;
    const retry = converge(ufw, { protocol: "any" });
    assertEquals(retry.commands.length, 1);
    assertEquals(
        retry.commands[0][retry.commands[0].indexOf("proto") + 1],
        "udp",
    );
    assertEquals(ufw.rows[0], tcp);
    assertEquals(retry.result, { backend: "active" });
    assertEquals(converge(ufw, { protocol: "any" }).commands, []);
});

Deno.test("a partial apply is not rolled back and a repeat repairs only the missing lines", () => {
    const ufw = new UfwDouble();
    const request = intent("present", { source: "any", protocol: "any" });
    const commands = plan(request, ufw.status());
    ufw.apply(commands[0]);
    ufw.fail = true;
    assertEquals(
        refusal(() => ufw.apply(commands[1])),
        "firewall.apply_failed",
    );
    assertEquals(
        refusal(() => check(request, ufw.status())),
        "firewall.verify_failed",
    );
    assertEquals(ufw.tuples("443").length, 1);
    ufw.fail = false;
    assertEquals(
        converge(ufw, { source: "any", protocol: "any" }).commands.length,
        3,
    );
    assertEquals(ufw.tuples("443").length, 4);
    assertEquals(
        converge(ufw, { source: "any", protocol: "any" }).commands,
        [],
    );
});

Deno.test("an interrupted move can leave the line absent and a repeat restores it", () => {
    const ufw = new UfwDouble([
        {
            to: "443/tcp",
            action: "deny",
            source: "Anywhere",
            comment: "proof:deny",
        },
        ...new UfwDouble().rows,
    ]);
    const source = "10.44.0.9";
    ufw.rows.push({
        to: "443/tcp",
        action: "allow",
        source,
        comment: "orbit web",
    });
    const request = intent("present", { source });
    const commands = plan(request, ufw.status());
    assertEquals(commands[0].slice(3, 5), ["--force", "delete"]);
    ufw.apply(commands[0]);
    assertEquals(
        refusal(() => check(request, ufw.status())),
        "firewall.verify_failed",
    );
    assertEquals(converge(ufw, { source }).commands.length, 1);
    assertEquals(converge(ufw, { source }).commands, []);
});

Deno.test("the typed native steps publish bounded inactive and verify-failed results", async () => {
    const context = {
        writeResource: (_spec: string, _name: string, value: unknown) =>
            Promise.resolve(value),
    };
    const request = intent("present", {});
    const planned = await behavior.methods.plan.execute({
        intent: request,
        stdout: "Status: inactive",
    }, context);
    assertEquals(planned, {
        stdout: '{"error_code":"firewall.backend_inactive"}',
        ok: false,
        commands: [],
    });
    const refused = await behavior.methods.check.execute({
        intent: request,
        stdout: "Status: inactive",
        refusal: planned.stdout,
        changes: 0,
    }, context);
    assertEquals(refused, planned);
    const failed = await behavior.methods.check.execute({
        intent: request,
        stdout: new UfwDouble().status(),
        refusal: "",
        changes: 1,
    }, context);
    assertEquals(failed, {
        stdout: '{"error_code":"firewall.verify_failed"}',
        ok: false,
        commands: [],
    });
});

for (
    const source of [
        "2001:db8:1",
        "2001:db8::1::2",
        "1:2:3:4:5:6:7:8:9",
        "1:2:3:4:5:6:7::8",
    ]
) {
    Deno.test(`invalid IPv6 ${source} is a typed refusal rather than an invented address`, () => {
        assertEquals(
            refusal(() =>
                plan(intent("present", { source }), "Status: active")
            ),
            "firewall.input_invalid",
        );
    });
}
