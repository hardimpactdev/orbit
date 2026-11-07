/** The rule planner and check against the ufw double; no host firewall calls. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { check, plan, Refusal } from "./plan.ts";
import { UfwDouble } from "./native_fixture.ts";

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

Deno.test("any writes one commented line per protocol and family above managed lines", () => {
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
        ["443/tcp (v6)", "allow", "Anywhere (v6)"],
        ["443/udp (v6)", "allow", "Anywhere (v6)"],
        ["22/tcp (v6)", "allow", "Anywhere (v6)"],
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
