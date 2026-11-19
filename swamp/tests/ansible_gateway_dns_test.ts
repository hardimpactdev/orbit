/** The live Gateway DNS wrapper, listener and withdrawal run against an isolated service-manager boundary. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import {
    driftedGatewayDns,
    existingGatewayDns,
    freshGatewayDnsCheck,
    missingGatewayDnsTool,
    productionGatewayDns,
    withdrawGatewayDns,
} from "./_support/gateway_dns.ts";

const hasAnsible = new Deno.Command("sh", {
    args: ["-c", "command -v ansible-playbook"],
}).outputSync().success;

for (const check of [false, true]) {
    Deno.test({
        name:
            `local Gateway DNS fails with a named error before activation when dig is missing${
                check ? " in check mode" : ""
            }`,
        ignore: !hasAnsible,
        async fn() {
            const result = await missingGatewayDnsTool(check);
            assertEquals(result.status, "failed", result.output);
            assertStringIncludes(
                result.output,
                "gateway.private_dns_tool_missing",
            );
            assertStringIncludes(result.output, "dig is required");
            assertEquals(result.units, []);
            assertEquals(result.output.includes("FAILED - RETRYING"), false);
        },
    });
}

Deno.test({
    name:
        "an already-running local listener is reused with zero changes and peer-managed Gateway DNS is preserved",
    ignore: !hasAnsible,
    async fn() {
        const result = await existingGatewayDns();
        assertEquals(result.firstStatus, "succeeded", result.first);
        assertEquals(result.secondStatus, "succeeded", result.second);
        assertStringIncludes(result.second, "changed=0");
        assertEquals(result.samePid, true);
        assertEquals(
            result.calls.filter((call) =>
                call.name === "orbit-private-dns.service" &&
                call.state === "restarted"
            ).length,
            1,
        );
        assertEquals(
            result.calls.some((call) =>
                call.name === "orbit-gateway-dns.service" && call.state
            ),
            false,
        );
        assertStringIncludes(
            result.second,
            "TASK [Read Gateway resolver policy]",
        );
        assertStringIncludes(result.second, "skipping: [localhost]");
    },
});

Deno.test({
    name:
        "drifted release files alone are rewritten, restart the listener and pass the DNS proof",
    ignore: !hasAnsible,
    async fn() {
        const result = await driftedGatewayDns();
        assertEquals(result.repairStatus, "succeeded", result.repair);
        const write = result.repair.split(
            "TASK [Write private DNS release files]",
        )[1].split("TASK [")[0];
        assertEquals(
            write.match(/^changed: /gm)?.length,
            4,
            write,
        );
        // The copy loops over the drifted files alone; an unchanged item would mean every file was copied again.
        assertEquals(write.includes("ok: [localhost] => (item="), false, write);
        for (const path of result.drifted) {
            assertStringIncludes(write, `(item=${path})`);
        }
        assertEquals(result.repaired, [true, true, true, true]);
        assertEquals(
            result.repairCalls.some((call) =>
                call.name === "orbit-private-dns.service" &&
                call.state === "restarted"
            ),
            true,
        );
        assertStringIncludes(
            result.repair,
            "TASK [Prove the local Gateway DNS answer before routing]",
        );
        assertEquals(result.againStatus, "succeeded", result.again);
        assertStringIncludes(result.again, "changed=0");
        assertEquals(
            result.againCalls.some((call) => call.state === "restarted"),
            false,
        );
    },
});

Deno.test({
    name:
        "production Gateway vars from main create no listener or catalog and change no resolver",
    ignore: !hasAnsible,
    async fn() {
        const result = await productionGatewayDns();
        assertEquals(result.status, "succeeded", result.output);
        assertStringIncludes(result.output, "changed=0");
        assertEquals(result.dnsDirectory, false);
        assertEquals(result.listener, false);
        assertEquals(result.socket, false);
        assertEquals(result.calls.some((call) => call.state), false);
    },
});

Deno.test({
    name:
        "local Gateway DNS check mode skips activation and readiness without retries or mutation",
    ignore: !hasAnsible,
    async fn() {
        const result = await existingGatewayDns(true);
        assertEquals(result.firstStatus, "succeeded", result.first);
        assertEquals(result.secondStatus, "succeeded", result.second);
        assertStringIncludes(result.second, "changed=0");
        assertEquals(result.samePid, true);
        assertEquals(result.second.includes("FAILED - RETRYING"), false);
        assertStringIncludes(
            result.second,
            "TASK [Prove the local Gateway DNS answer before routing]",
        );
        assertStringIncludes(result.second, "skipping: [localhost]");
    },
});

Deno.test({
    name:
        "a recorded local listener is disabled and its stale catalog removed after routing to a separate VPN",
    ignore: !hasAnsible,
    async fn() {
        const result = await withdrawGatewayDns();
        assertEquals(result.status, "succeeded", result.output);
        assertEquals(result.retained, []);
        assertEquals(result.running, false);
        const route = result.calls.findIndex((call) =>
            call.name === "orbit-gateway-dns.service" &&
            call.state === "started"
        );
        const stop = result.calls.findIndex((call) =>
            call.name === "orbit-private-dns.socket" && call.state === "stopped"
        );
        assertEquals(stop > route && route >= 0, true);
        assertEquals(
            result.calls.filter((call) => call.state === "stopped").map((
                call,
            ) => [call.name, call.enabled]),
            [
                ["orbit-private-dns.socket", false],
                ["orbit-private-dns.service", false],
            ],
        );
    },
});

Deno.test({
    name:
        "fresh local Gateway DNS check mode plans resources without requiring an installed release",
    ignore: !hasAnsible,
    async fn() {
        const result = await freshGatewayDnsCheck();
        assertEquals(result.status, "succeeded", result.output);
        assertEquals(result.output.includes("FAILED - RETRYING"), false);
    },
});

Deno.test({
    name:
        "withdrawal retains the listener when resolver policy still names its local address",
    ignore: !hasAnsible,
    async fn() {
        const result = await withdrawGatewayDns(true);
        assertEquals(result.status, "failed", result.output);
        assertEquals(result.running, true);
        assertEquals(result.retained.length, 4);
        assertEquals(
            result.calls.some((call) => call.state === "stopped"),
            false,
        );
    },
});

for (const installation of ["later-failure", "not-started"]) {
    Deno.test({
        name: `conservative DNS ownership can withdraw after ${installation}`,
        ignore: !hasAnsible,
        async fn() {
            const result = await withdrawGatewayDns(false, installation);
            if (installation === "later-failure") {
                assertEquals(result.firstStatus, "failed", result.first);
                assertStringIncludes(result.first, "fixture.php_fpm_invalid");
                assertStringIncludes(
                    result.first,
                    "Prove the local Gateway DNS answer before routing",
                );
                assertEquals(result.installed, true);
            } else {
                assertEquals(result.installed, false);
            }
            assertEquals(result.status, "succeeded", result.output);
            assertEquals(result.retained, []);
            assertEquals(result.running, false);
        },
    });
}
