import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { normalizeVersion, ToolRefusal } from "./contract.ts";
import { homebrewBlock } from "./homebrew.ts";
import { inventory } from "./native.ts";
import { removalDecision, RemoveInput } from "./remove.ts";
import { decide, WriteInput } from "./write.ts";

// The `antigravity-cli` cask as Linux `brew info --json=v2 --cask` prints it.
const prefix = "/home/linuxbrew/.linuxbrew";
const version = "1.5.13,5999302598852608";
const cask = {
    token: "antigravity-cli",
    full_token: "antigravity-cli",
    tap: "homebrew/cask",
    url: "https://storage.googleapis.com/antigravity-public/antigravity-cli/1.5.13-5999302598852608/darwin-arm/cli_mac_arm64.tar.gz",
    version,
    installed: version,
    sha256: "1".repeat(64),
    disabled: false,
    depends_on: { macos: { ">=": ["12"] } },
    artifacts: [{
        command_wrapper: ["agy", {
            executable:
                `${prefix}/Caskroom/antigravity-cli/${version}/antigravity`,
            env: { AGY_CLI_DISABLE_AUTO_UPDATE: "true" },
        }],
        target: `${prefix}/bin/agy`,
    }, { zap: [{ trash: "~/.gemini/antigravity-cli" }] }],
    variations: {
        x86_64_linux: {
            url: "https://storage.googleapis.com/antigravity-public/antigravity-cli/1.5.13-5999302598852608/linux-x64/cli_linux_x64.tar.gz",
            sha256: "7".repeat(64),
            depends_on: {},
        },
    },
};
const base = {
    node_id: 9,
    manager: "brew-cask",
    package: "antigravity-cli",
    user: "orbit",
    platform: "linux",
    architecture: "x86_64",
};
const brew = { os_major: 0, prefix, home: "/home/orbit", token_count: 1 };
const facts = (versions: string[]) => ({
    scope: "present",
    manager_version: "Homebrew 5.0.0",
    versions,
    complete: true,
    ...brew,
    homebrew: cask,
});
const write = (changes: Record<string, unknown> = {}) =>
    WriteInput.parse({ kind: "tool.install", ...base, ...changes });
const candidate = (changes: Record<string, unknown> = {}) => ({
    ...brew,
    homebrew: { ...cask, ...changes },
});

Deno.test("Linux casks install the command_wrapper cask from its Linux variation under a comma-build constraint", () => {
    const install = decide(write({ version_constraint: "^1.5" }), {
        facts: facts([]),
        candidate: candidate(),
    });
    assertEquals([install.ready, install.mutate, install.outcome], [
        true,
        true,
        "applied",
    ]);
    assertEquals(
        decide(write({ version_constraint: "^2.0" }), {
            facts: facts([]),
            candidate: candidate(),
        }).error_code,
        "tool.version_constraint_blocked",
    );
    // A wrapper that writes outside the Homebrew prefix or the account's home needs authorization.
    assertEquals(
        homebrewBlock(write(), {
            ...brew,
            homebrew: {
                ...cask,
                artifacts: [{ ...cask.artifacts[0], target: "/usr/bin/agy" }],
            },
        }, true),
        "authorization_required",
    );
    // A macOS-only cask has no Linux variation that clears its macOS dependency.
    assertEquals(
        homebrewBlock(write(), candidate({ variations: {} }), true),
        "unsupported_artifact",
    );
    assertEquals(
        homebrewBlock(
            write(),
            candidate({
                variations: {
                    x86_64_linux: { sha256: "no_check", depends_on: {} },
                },
            }),
            true,
        ),
        "checksum_unavailable",
    );
});

Deno.test("Linux casks update to a newer build and stay unchanged on the same one", () => {
    const update = write({
        kind: "tool.update",
        tool_id: 1,
        version_constraint: "^1.5",
    });
    const newer = "1.5.14,6000000000000000";
    const applied = decide(update, {
        facts: facts([version]),
        candidate: candidate({ version: newer }),
    });
    assertEquals([applied.mutate, applied.outcome, applied.installed_version], [
        true,
        "applied",
        version,
    ]);
    assertEquals(
        decide(update, { facts: facts([version]), candidate: candidate() })
            .outcome,
        "unchanged",
    );
});

Deno.test("Linux casks record an installed comma-build version against the constraint", () => {
    const retry = decide(
        write({
            tool_id: 1,
            tool_status: "failed",
            version_constraint: "^1.5",
        }),
        { facts: facts([version]) },
    );
    assertEquals([retry.ready, retry.mutate, retry.installed_version], [
        true,
        false,
        version,
    ]);
    assertEquals(
        decide(
            write({
                tool_id: 1,
                tool_status: "failed",
                version_constraint: "^2.0",
            }),
            { facts: facts([version]) },
        ).error_code,
        "tool.installed_version_constraint_violated",
    );
});

Deno.test("Linux casks remove the command_wrapper cask and read it from the Linux prefix", () => {
    const input = RemoveInput.parse({ ...base, installed_version: version });
    assertEquals(removalDecision(input, { facts: facts([version]) }), {
        ready: true,
        remove: true,
        manager_version: "Homebrew 5.0.0",
    });
    const read: Record<string, unknown> = inventory(
        { ...input, user: "orbit" },
        {
            status: "succeeded",
            outputs: { stdout: "Homebrew 5.0.0\n", exitCode: 0 },
        },
        {
            status: "succeeded",
            outputs: {
                stdout: JSON.stringify({ formulae: [], casks: [cask] }),
                exitCode: 0,
            },
        },
    );
    assertEquals([read.versions, read.prefix, read.os_major], [
        [version],
        prefix,
        0,
    ]);
});

Deno.test("Linux arm64 has no cask tag and the build is dropped only for casks", () => {
    assertThrows(
        () =>
            homebrewBlock(write({ architecture: "arm64" }), candidate(), true),
        ToolRefusal,
        "tool.version_probe_failed",
    );
    assertEquals(normalizeVersion(version, "brew-cask"), "1.5.13");
    assertEquals(normalizeVersion(",5999302598852608", "brew-cask"), null);
    for (const manager of ["brew", "apt", "vp", "composer"] as const) {
        assertEquals(normalizeVersion(version, manager), null);
    }
});
