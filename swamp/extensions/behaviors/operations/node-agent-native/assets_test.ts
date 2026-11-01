import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import {
    configure,
    decode,
    envelope,
    pools,
    result,
    storage,
} from "./assets.ts";
const pin = "a".repeat(64),
    account = {
        user: "orbit",
        uid: 1000,
        group: "orbit-group",
        home: "/home/orbit",
        shell: "/bin/zsh",
    };
const planned = {
    root: null,
    user: "orbit",
    create_missing: true,
    protected_roots: ["/etc"],
    managed_checkouts: ["/srv/checkouts/project"],
};
Deno.test("settings resolves the admitted account and protects home, system and checkout paths", () => {
    const e = JSON.parse(storage({ storage: planned, account, pin }).stdin);
    assertEquals(e.input, {
        root: "/home/orbit/apps",
        user: "orbit",
        group: "orbit-group",
        create_missing: true,
    });
    for (
        const root of [
            "/home/orbit",
            "/home",
            "/home/orbit/.ssh/apps",
            "/etc/data",
            "/srv/checkouts/project",
        ]
    ) {
        assertThrows(() =>
            storage({ storage: { ...planned, root }, account, pin })
        );
    }
    assertThrows(() =>
        storage({
            storage: planned,
            account: { ...account, user: "foreign" },
            pin,
        })
    );
});
Deno.test("frozen configure never receives worker credentials; normal convergence cannot rotate", () => {
    const configuration = {
        configuration: "public",
        certificate: "CA",
        unit: "ProtectHome=yes",
        checkouts: ["/home/orbit/apps/app", "/other/checkout"],
        user: "orbit",
        apps_root: null,
    };
    const args = {
        configuration,
        pin,
        account,
        changed: false,
        rotate: false,
        verifier_hash: pin,
    };
    const payload = JSON.parse(configure(args).stdin).input;
    assertEquals(payload.worker_credentials, null);
    assertEquals(payload.allow_secret_rotation, false);
    assertEquals(payload.secret_hash, pin);
    assertEquals(payload.checkouts, ["/home/orbit/apps/app"]);
    assertEquals(
        JSON.parse(configure({ ...args, rotate: true }).stdin).input
            .secret_hash,
        null,
    );
});
Deno.test("only a public verifier and bounded agent output enter typed metadata", () => {
    const output = decode(
        JSON.stringify({
            ok: true,
            data: { secret_hash: pin, restarted: true, unclosed: [] },
        }),
    );
    assertEquals(output, { verifier_hash: pin, restarted: true, unclosed: [] });
    assertThrows(() => decode("{malformed"));
    assertThrows(() =>
        decode('{"ok":false,"error":{"code":"agent.update_busy"}}')
    );
    assertThrows(() =>
        envelope({ command: "arbitrary.command", pin, input: {} })
    );
});
Deno.test("native recovery cannot claim a verifier on failure or missing observer completion", () => {
    const intent = {};
    assertEquals(
        result({
            kind: "node.agent_rekey",
            intent,
            steps: {
                observer: {
                    status: "succeeded",
                    outputs: { verifier_hash: pin },
                },
            },
        }),
        { rekeyed: true, restarted: true, verifier_hash: pin },
    );
    assertEquals(
        result({
            kind: "node.agent_rekey",
            intent,
            steps: { observer: { status: "failed" } },
        }),
        { rekeyed: false, error_code: "operation.native_failed" },
    );
});
Deno.test("pool templates share observed account values and keep only fixed agent argv", () => {
    const payload = JSON.parse(
        pools({
            pin,
            configurations: {
                "8.5":
                    "user=__orbit_converge_group__\nhome=/__orbit_converge_home__",
            },
            home: account.home,
            group: account.group,
        }).stdin,
    );
    assertEquals(
        payload.input.configurations["8.5"],
        "user=orbit-group\nhome=/home/orbit",
    );
});

Deno.test("existing agent commands retain their command-owned execution budgets", () => {
    for (
        const [command, deadline] of Object.entries({
            "account.lookup": 10000,
            "node.prepare-storage-root": 30000,
            "agent.configure": 420000,
            "agent.install": 180000,
            "caddy.install-package": 600000,
            "node.install-private-dns-listener": 60000,
            "php.configure-runtime": 900000,
            "php.converge-shared-pools": 180000,
            "node.publish-tmpfiles": 30000,
        })
    ) {
        const shaped = envelope({ command, pin, input: {} });
        assertEquals(JSON.parse(shaped.stdin).deadline_ms, deadline);
        assertEquals(shaped.timeoutSec, deadline / 1000);
    }
});
