import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import {
    behavior,
    bootstrapActionInput,
    enrollmentBootstrapObservation,
} from "./command_assets.ts";

const artifact = {
    url: "https://example.test/observer",
    sha256: "a".repeat(64),
};
const command = {
    user: "orbit",
    public_key: "ssh-ed25519 AAAA fixture",
    releases: ["resolute"],
    packages: ["sudo", "curl"],
    dns_policy: "preserve" as const,
};
const args = { action: "account" as const, user: "orbit", command, artifact };
const observation = {
    bootstrap: {
        node_id: 209,
        platform: "linux" as const,
        expected_architecture: "x86_64",
        target: { fingerprint: "SHA256:approved" },
    },
    observation: { stdout: "Linux x86_64\n", exitCode: 0 as const },
    host_key: {
        algorithm: "ssh-ed25519",
        publicKey: "ssh-ed25519 AAAA fixture",
        fingerprint: "SHA256:approved",
    },
};
Deno.test("enrollment accepts only the admitted first-contact fingerprint and returns full pins", () => {
    assertEquals(enrollmentBootstrapObservation(observation), {
        node_id: 209,
        probed: true,
        architecture: "x86_64",
        host_type: "ssh-ed25519",
        public_key: "AAAA",
        host_key_fingerprint: "SHA256:approved",
        host_keys: ["ssh-ed25519 AAAA fixture"],
    });
    assertThrows(
        () =>
            enrollmentBootstrapObservation({
                ...observation,
                host_key: {
                    ...observation.host_key,
                    fingerprint: "SHA256:other",
                },
            }),
        Error,
        "node.ssh_host_key_mismatch",
    );
    assertThrows(
        () =>
            enrollmentBootstrapObservation({
                ...observation,
                observation: { stdout: "Linux arm64\n", exitCode: 0 },
            }),
        Error,
        "node.architecture_unsupported",
    );
});
Deno.test("bootstrap actions expose only the public input for that action", () => {
    assertEquals(bootstrapActionInput(args), { user: "orbit" });
    assertEquals(bootstrapActionInput({ ...args, action: "packages" }), {
        packages: ["sudo", "curl"],
        releases: ["resolute"],
    });
    assertEquals(
        bootstrapActionInput({ ...args, action: "observer" }),
        artifact,
    );
    assertThrows(() => bootstrapActionInput({ ...args, identity: "private" }));
    assertThrows(() => bootstrapActionInput({ ...args, user: "other" }));
    assertThrows(() => bootstrapActionInput({ ...args, action: "custom" }));
    assertThrows(() =>
        bootstrapActionInput({
            ...args,
            artifact: {
                ...artifact,
                url: "https://user:password@example.test/bin",
            },
        })
    );
});
Deno.test("asset delivery serializes public data into a shipped action without executing it", async () => {
    const writes: unknown[] = [];
    const publicKey = "ssh-ed25519 AAAA quote'\"; print('public')";
    const result = await behavior.methods.read.execute({
        ...args,
        action: "authorized_key",
        command: { ...command, public_key: publicKey },
    }, {
        extensionFile: (name) => new URL(name, import.meta.url).pathname,
        writeResource: (...values) => Promise.resolve(writes.push(values)),
    });
    assertEquals(result.script_body.includes("BOOTSTRAP_INPUT = None"), false);
    assertEquals(result.script_body.includes("subprocess"), false);
    assertEquals(writes, [["assets", "assets", result]]);
    await assertRejects(() =>
        behavior.methods.read.execute({
            ...args,
            command: { ...command, public_key: "ssh-ed25519 AAAA\ninjected" },
        }, {
            extensionFile: () => {
                throw new Error("Invalid input read an asset");
            },
            writeResource: () => Promise.resolve(),
        })
    );
});

Deno.test("observer delivery reads its canonical asset from the artifact alone", async () => {
    const writes: unknown[] = [];
    const result = await behavior.methods.observer.execute(artifact, {
        extensionFile: (name) => new URL(name, import.meta.url).pathname,
        writeResource: (...values) => Promise.resolve(writes.push(values)),
    });
    const source = await Deno.readTextFile(
        new URL("scripts/bootstrap_observer.py", import.meta.url),
    );
    assertEquals(
        result.script_body,
        source.replace(
            "BOOTSTRAP_INPUT = None",
            "BOOTSTRAP_INPUT = json.loads(" +
                JSON.stringify(JSON.stringify(artifact)) + ")",
        ),
    );
    assertEquals(writes, [["assets", "assets", result]]);
    assertThrows(() =>
        behavior.methods.observer.execute({
            ...artifact,
            url: "http://example.test/observer",
        }, {
            extensionFile: () => {
                throw new Error("Invalid delivery read an asset");
            },
            writeResource: () => Promise.resolve(),
        })
    );
});

Deno.test("enrollment pin refuses mismatches before platform or action work", async () => {
    const { enrollmentBootstrapPin, enrollmentOperatingSystem } = await import(
        "./command_assets.ts"
    );
    assertEquals(
        enrollmentBootstrapPin({
            expected_fingerprint: "SHA256:approved",
            host_key: observation.host_key,
        }).host_keys,
        ["ssh-ed25519 AAAA"],
    );
    assertThrows(
        () =>
            enrollmentBootstrapPin({
                expected_fingerprint: "SHA256:other",
                host_key: observation.host_key,
            }),
        Error,
        "node.ssh_host_key_mismatch",
    );
    assertThrows(() =>
        enrollmentBootstrapPin({
            expected_fingerprint: "SHA256:approved",
            host_key: { ...observation.host_key, algorithm: "ssh-rsa" },
        })
    );
    assertEquals(
        enrollmentOperatingSystem('ID=ubuntu\nVERSION_CODENAME="resolute"\n'),
        { operating_system_ready: true },
    );
    assertThrows(
        () =>
            enrollmentOperatingSystem("ID=unknown\nVERSION_CODENAME=unknown\n"),
        Error,
        "node.platform_unsupported",
    );
});
