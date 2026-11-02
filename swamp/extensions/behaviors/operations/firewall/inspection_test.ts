import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { inspectionResult } from "./inspection-result.ts";

const shape = {
    comment: "orbit:rule-7",
    action: "allow",
    direction: "in",
    source: "192.0.2.1",
    destination: "any",
    port: "443",
    protocol: "tcp",
    in_interface: null,
    out_interface: null,
    family: null,
} as const;
Deno.test("native comparison preserves exact/drift/unmanaged/missing semantics", () => {
    const result = inspectionResult({
        stdout:
            "Status: active\nTo Action From\n-- ------ ----\n[ 1] 443/tcp ALLOW IN 192.0.2.1 # orbit:rule-7\n[ 2] 22/tcp DENY OUT Anywhere # stale\n[ 3] 81/tcp ALLOW IN Anywhere # drift",
        expected: [
            { name: "office-web", shape },
            { name: "other", shape: { ...shape, comment: "missing" } },
            {
                name: "changed",
                shape: {
                    ...shape,
                    comment: "drift",
                    source: "any",
                    port: "80",
                },
            },
        ],
    });
    assertEquals(result.live.map((r) => [r.name, r.match]), [
        ["office-web", "exact"],
        ["stale", "unmanaged"],
        ["changed", "drift"],
    ]);
    assertEquals(result.missing.map((r) => [r.name, r.match]), [[
        "other",
        "missing",
    ]]);
});
Deno.test("numbered UFW parsing normalizes IPv4, ranges and interfaces; IPv6 stays excluded", () => {
    const result = inspectionResult({
        stdout:
            "Status: active\n[1] 10.2.3.7/24 00443:00444/tcp on orbit ALLOW FWD 192.0.2.1/32 on enp5s0 # owned\n[2] 443/tcp (v6) ALLOW IN Anywhere (v6) # ipv6",
        expected: [{
            name: "forward",
            shape: {
                ...shape,
                comment: "owned",
                direction: "fwd",
                destination: "10.2.3.0/24",
                port: "443:444",
                in_interface: "enp5s0",
                out_interface: "orbit",
            },
        }],
    });
    assertEquals(result.live, [{
        name: "forward",
        comment: "owned",
        action: "allow",
        source: "192.0.2.1",
        destination: "10.2.3.0/24",
        port: "443:444",
        protocol: "tcp",
        interface: "enp5s0",
        family: "v4",
        match: "exact",
    }]);
});
Deno.test("a wildcard desired family can match IPv4 but an explicit IPv6 rule cannot", () => {
    for (const family of [null, "v4", "v6"] as const) {
        const result = inspectionResult({
            stdout: "Status: active\n443/tcp ALLOW IN 192.0.2.1 # orbit:rule-7",
            expected: [{ name: "office", shape: { ...shape, family } }],
        });
        assertEquals(
            result.live[0].match,
            family === "v6" ? "unmanaged" : "exact",
        );
    }
});
Deno.test("inactive UFW gives an empty recorded comparison", () => {
    const result = inspectionResult({
        stdout: "Status: inactive\n",
        expected: [{ name: "office", shape }],
    });
    assertEquals([result.backend, result.live, result.missing], [
        "inactive",
        [],
        [],
    ]);
});
Deno.test("malformed or unrepresentable observations fail without partial output", () => {
    for (
        const stdout of [
            "",
            "sudo: denied",
            "Status: active\nStatus: inactive",
            "Status: active\n[1] 70000/tcp ALLOW IN Anywhere # bad",
            "Status: active\n[1] OpenSSH ALLOW IN Anywhere # profile",
            "Status: active\n[1] 22/tcp ALLOW IN 999.1.1.1 # bad",
        ]
    ) {
        assertThrows(
            () => inspectionResult({ stdout, expected: [] }),
            Error,
            "firewall.inspection_unavailable",
        );
    }
});
Deno.test("row and UTF8 byte bounds never produce a truncated successful result", () => {
    for (
        const stdout of [
            "Status: active\n" +
            Array.from(
                { length: 513 },
                (_, i) => `[${i + 1}] 443/tcp ALLOW IN Anywhere # r${i}`,
            ).join("\n"),
            "é".repeat(131072),
        ]
    ) {
        assertThrows(
            () => inspectionResult({ stdout, expected: [] }),
            Error,
            "firewall.inspection_too_large",
        );
    }
});
Deno.test("a changed interface is drift; duplicate observations do not fabricate missing rules", () => {
    const result = inspectionResult({
        stdout:
            "Status: active\n[1] 443/tcp on orbit ALLOW IN 192.0.2.1 # orbit:rule-7\n[2] 443/tcp ALLOW IN 192.0.2.1 # orbit:rule-7",
        expected: [{ name: "office", shape }],
    });
    assertEquals(result.live.map((r) => r.match), [
        "drift",
        "exact",
    ]);
    assertEquals(result.missing, []);
});

Deno.test("IPv6 text in an IPv4 comment cannot hide the row; IPv6-only intent stays excluded", () => {
    const result = inspectionResult({
        stdout:
            "Status: active\n[1] 443/tcp ALLOW IN 192.0.2.1 # office (v6) marker",
        expected: [{
            name: "ipv6-only",
            shape: { ...shape, source: "2001:db8::1", family: "v6" },
        }],
    });
    assertEquals(result.live.length, 1);
    assertEquals(result.live[0].comment, "office (v6) marker");
    assertEquals(result.missing, []);
});

Deno.test("official named tuples and shared aliases compare exactly without weakening role ownership", () => {
    const named = {
        ...shape,
        comment: "orbit web",
        source: "any",
        port: "443:444",
        family: null,
    };
    const expected = [{ name: "web", shape: named }, {
        name: "alias",
        shape: { ...named, comment: "orbit alias" },
    }, {
        name: "role",
        shape: {
            ...shape,
            comment: "orbit:router:9",
            source: "any",
            port: "443",
        },
    }];
    const complete = inspectionResult({
        stdout:
            "Status: active\n[1] 443/tcp ALLOW IN Anywhere\n[2] 444/tcp ALLOW IN Anywhere\n[3] 80/tcp ALLOW IN Anywhere\n",
        expected,
    });
    assertEquals(complete.live.map((row) => row.match), [
        "exact",
        "exact",
        "unmanaged",
    ]);
    assertEquals(complete.live[0].name, "web");
    assertEquals(complete.missing.map((row) => row.name), ["role"]);
    const partial = inspectionResult({
        stdout: "Status: active\n443/tcp ALLOW IN Anywhere\n",
        expected,
    });
    assertEquals(partial.missing.map((row) => row.name), [
        "web",
        "alias",
        "role",
    ]);
});

Deno.test("named tuples cannot hide drift of an explicit role comment owner", () => {
    const report = inspectionResult({
        stdout: "Status: active\n80/tcp ALLOW IN Anywhere # orbit:router:9\n",
        expected: [
            {
                name: "web",
                shape: {
                    ...shape,
                    comment: "orbit web",
                    source: "any",
                    port: "80",
                },
            },
            {
                name: "router",
                shape: {
                    ...shape,
                    comment: "orbit:router:9",
                    source: "any",
                    port: "443",
                },
            },
        ],
    });
    assertEquals(report.live.map((row) => [row.name, row.match]), [[
        "router",
        "drift",
    ]]);
});

Deno.test("an allow and a deny on one port match by tuple whatever their comment", () => {
    const expected = ["10.44.0.6", "10.44.0.9", "any"].map((source) => ({
        name: "7297/tcp from any",
        shape: {
            ...shape,
            comment: "orbit rex",
            source,
            port: "7297",
            action: source === "any" ? "deny" as const : "allow" as const,
            family: "v4" as const,
        },
    }));
    const lines = [
        "7297/tcp ALLOW IN 10.44.0.6 # orbit rex",
        "7297/tcp ALLOW IN 10.44.0.9 # orbit:node:9:allowlist:rex",
        "7297/tcp DENY IN Anywhere",
    ];
    const compare = (rows: string[]) =>
        inspectionResult({ stdout: "Status: active\n" + rows.join("\n"), expected });
    assertEquals(compare(lines).live.map((r) => r.match), ["exact", "exact", "exact"]);
    assertEquals(compare(lines).missing, []);
    assertEquals(compare([lines[0], lines[2]]).missing.map((r) => r.source), ["10.44.0.9"]);
    const changed = compare([lines[0], lines[1].replace("10.44.0.9", "10.44.0.7"), lines[2]]);
    assertEquals(changed.live[1].match, "unmanaged");
    assertEquals(changed.missing.map((r) => r.source), ["10.44.0.9"]);
});
