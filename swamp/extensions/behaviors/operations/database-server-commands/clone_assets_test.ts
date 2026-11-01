import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "./clone_assets.ts";
import { CloneInput } from "../database/clone_input.ts";

const context = {
    extensionFile: (name: string) => "/native-cache/" + name,
    writeResource: () => Promise.resolve(),
};
const good = {
    status: "succeeded",
    outputs: { stdout: '{"backend":"active"}', exitCode: 0 },
};
const unknown = {
    stdout: '{"error_code":"database.clone_outcome_unknown"}\n',
    exitCode: 1,
};

Deno.test("clone requires every selected effect and cleanup and accepts nested child result", async () => {
    const result = behavior.methods.result.execute;
    assertEquals(
        await result({
            expected: ["copy", "cleanup_scripts"],
            steps: {
                copy: good,
                cleanup_scripts: {
                    status: "succeeded",
                    outputs: { stdout: "", exitCode: 0 },
                },
            },
        }, context),
        { stdout: '{"backend":"active"}\n', exitCode: 0 },
    );
    assertEquals(
        await result({
            expected: ["child"],
            steps: {
                child: {
                    status: "succeeded",
                    outputs: { result: good.outputs },
                },
            },
        }, context),
        { stdout: '{"backend":"active"}\n', exitCode: 0 },
    );
    const refused: Parameters<
        typeof behavior.methods.result.execute
    >[0]["steps"][] = [{}, { copy: good }, {
        copy: { ...good, status: "cancelled" },
    }, {
        copy: {
            status: "succeeded",
            outputs: {
                stdout: '{"backend":"active","password":"private"}',
                exitCode: 0,
            },
        },
    }];
    for (const steps of refused) {
        assertEquals(
            await result(
                { expected: ["copy", "cleanup_scripts"], steps },
                context,
            ),
            unknown,
        );
    }
    assertEquals(
        await result({
            expected: ["copy"],
            steps: {
                copy: {
                    status: "failed",
                    outputs: {
                        stdout: '{"error_code":"database.source_missing"}',
                        exitCode: 1,
                    },
                },
            },
        }, context),
        { stdout: '{"error_code":"database.source_missing"}\n', exitCode: 1 },
    );
});

Deno.test("clone snapshot metadata is bounded and rejects private or incomplete fields", async () => {
    const snapshot = {
        bytes: 4096,
        sha256: "a".repeat(64),
        mode: 0o600,
        copy: "snapshot",
    };
    assertEquals(
        await behavior.methods.snapshot.execute({
            stdout: JSON.stringify(snapshot),
            exit_code: 0,
        }, context),
        { json: JSON.stringify(snapshot) },
    );
    for (
        const value of [{ ...snapshot, bytes: 2 ** 40 + 1 }, {
            ...snapshot,
            sha256: "bad",
        }, { ...snapshot, password: "private" }]
    ) {
        await assertRejects(() =>
            behavior.methods.snapshot.execute({
                stdout: JSON.stringify(value),
                exit_code: 0,
            }, context)
        );
    }
    await assertRejects(() =>
        behavior.methods.snapshot.execute({
            stdout: JSON.stringify(snapshot),
            exit_code: 1,
        }, context)
    );
});

Deno.test("clone captured SQLite placement rejects mismatched nodes, paths and same source target", () => {
    const ssh = {
        host: "10.44.0.9",
        user: "orbit",
        host_keys: ["ssh-ed25519 fixture"],
    };
    const intent = {
        variant: "sqlite_local",
        node_id: 9,
        source_node_id: 9,
        source_ssh: ssh,
        target_ssh: ssh,
        source: {
            variant: "clone",
            node_id: 9,
            transport_user: "orbit",
            checkout: "/apps/source",
            source: "/apps/source/db/proof.sqlite",
            directory: "/tmp/orbit-sqlite-clone-" + "a".repeat(32),
        },
        target: {
            node_id: 9,
            transport_user: "orbit",
            checkout: "/apps/target",
            target: "/apps/target/db/proof.sqlite",
            incoming: "/apps/target/db/.orbit-sqlite-" + "b".repeat(32),
        },
    };
    assertEquals(CloneInput.safeParse(intent).success, true);
    for (
        const value of [{ ...intent, node_id: 10 }, {
            ...intent,
            target: {
                ...intent.target,
                target: intent.source.source,
                checkout: intent.source.checkout,
            },
        }, {
            ...intent,
            target: {
                ...intent.target,
                incoming: "/other/.orbit-sqlite-" + "b".repeat(32),
            },
        }, {
            ...intent,
            source: { ...intent.source, source: "/apps/source/../secret" },
        }]
    ) {
        assertEquals(CloneInput.safeParse(value).success, false);
    }
});
