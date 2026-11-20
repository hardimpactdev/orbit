import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { normalizeVersion, ToolRefusal } from "./contract.ts";
import { homebrewBlock } from "./homebrew.ts";
import { commands, inventory } from "./native.ts";
import { removalDecision, RemoveInput } from "./remove.ts";
import { behavior, decide, WriteInput } from "./write.ts";

// The `antigravity-cli` cask as Linux `brew info --json=v2 --cask` prints it: the base fields are the resolved
// x86_64 Linux build, and the macOS builds are variations.
const prefix = "/home/linuxbrew/.linuxbrew";
const version = "1.5.13,5999302598852608";
const download =
    "https://storage.googleapis.com/antigravity-public/antigravity-cli/1.5.13-5999302598852608";
const mac = {
    url: `${download}/darwin-x64/cli_mac_x64.tar.gz`,
    sha256: "0".repeat(64),
    depends_on: { macos: { ">=": ["12"] } },
};
const cask = {
    token: "antigravity-cli",
    full_token: "antigravity-cli",
    tap: "homebrew/cask",
    url: `${download}/linux-x64/cli_linux_x64.tar.gz`,
    version,
    installed: version,
    sha256: "7".repeat(64),
    disabled: false,
    depends_on: {},
    supported_platforms: [
        "golden_gate",
        "arm64_golden_gate",
        "tahoe",
        "arm64_tahoe",
        "sequoia",
        "arm64_sequoia",
        "sonoma",
        "arm64_sonoma",
        "ventura",
        "arm64_ventura",
        "monterey",
        "arm64_monterey",
        "x86_64_linux",
        "arm64_linux",
    ],
    artifacts: [{
        command_wrapper: ["agy", {
            executable:
                `${prefix}/Caskroom/antigravity-cli/${version}/antigravity`,
            env: { AGY_CLI_DISABLE_AUTO_UPDATE: "true" },
        }],
        target: `${prefix}/bin/agy`,
    }, { zap: [{ trash: "~/.gemini/antigravity-cli" }] }],
    variations: { sequoia: mac, arm64_sequoia: mac },
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
    assertEquals(
        homebrewBlock(write(), candidate({ sha256: "no_check" }), true),
        "checksum_unavailable",
    );
});

Deno.test("Linux casks need a build for x86_64 Linux in the brew info record", () => {
    // The API record Homebrew serves: a macOS base, with the Linux build as a variation.
    const api = candidate({
        ...mac,
        variations: {
            x86_64_linux: {
                url: cask.url,
                sha256: cask.sha256,
                depends_on: {},
            },
        },
    });
    assertEquals(homebrewBlock(write(), api, true), null);
    const macOnly = cask.supported_platforms.filter((p) =>
        !p.endsWith("linux")
    );
    for (
        const changes of [
            {
                ...mac,
                depends_on: {},
                supported_platforms: macOnly,
                variations: {},
            },
            { supported_platforms: undefined },
            { depends_on: { maximum_macos: "sonoma" } },
            { depends_on: { macos: { "<=": ["14"] } } },
            { depends_on: { arch: [{ type: "arm", bits: 64 }] } },
        ]
    ) {
        assertEquals(
            homebrewBlock(write(), candidate(changes), true),
            "unsupported_artifact",
        );
    }
    assertEquals(
        homebrewBlock(
            write(),
            candidate({ depends_on: { arch: [{ type: "intel", bits: 64 }] } }),
            true,
        ),
        null,
    );
});

Deno.test("Linux cask wrappers stay under the Homebrew prefix for install and removal", () => {
    const removal = RemoveInput.parse({ ...base, installed_version: version });
    for (
        const target of [
            "/home/orbit/bin/agy",
            "~/bin/agy",
            "$HOME/bin/agy",
            "/usr/bin/agy",
        ]
    ) {
        const artifacts = [{ ...cask.artifacts[0], target }];
        assertEquals(
            homebrewBlock(write(), candidate({ artifacts }), true),
            "authorization_required",
        );
        assertEquals(
            removalDecision(removal, {
                facts: {
                    ...facts([version]),
                    homebrew: { ...cask, artifacts },
                },
            }).error_code,
            "tool.remove_failed",
        );
    }
    assertEquals(
        homebrewBlock(
            write(),
            candidate({
                artifacts: [{
                    ...cask.artifacts[0],
                    target: "$HOMEBREW_PREFIX/bin/agy",
                }],
            }),
            true,
        ),
        null,
    );
    // Other artifacts keep their home targets.
    assertEquals(
        homebrewBlock(
            write(),
            candidate({
                artifacts: [{ binary: ["agy", { target: "~/bin/agy" }] }],
            }),
            true,
        ),
        null,
    );
});

Deno.test("Linux cask commands refresh the cask API, and Linux formulae do not", () => {
    const flag = "HOMEBREW_FORCE_API_AUTO_UPDATE=1";
    const c = commands({ ...write(), kind: "tool.install" });
    for (const argv of [c.write, c.candidate]) {
        assertEquals(argv.includes(flag), true);
        assertEquals(argv.includes("update"), false);
    }
    assertEquals(
        commands({ ...write({ kind: "tool.update" }), kind: "tool.update" })
            .write
            .includes(flag),
        true,
    );
    const formula = commands({
        ...write({ manager: "brew", package: "hello" }),
    });
    assertEquals(
        formula.write.includes(flag) || formula.candidate.includes(flag),
        false,
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
        "1.5.13",
    ]);
    // A new build of the same SemVer is still an update.
    assertEquals(
        decide(update, {
            facts: facts([version]),
            candidate: candidate({ version: "1.5.13,6000000000000000" }),
        }).mutate,
        true,
    );
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
        "1.5.13",
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

Deno.test("the Linux cask version read after a write reports the SemVer without the build", async () => {
    const observed = await behavior.methods.observe.execute({
        input: write({ version_constraint: "^1.5" }),
        after: {
            exitCode: 0,
            stdout: JSON.stringify({ formulae: [], casks: [cask] }),
        },
    }, {
        extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
        writeResource: () => Promise.resolve(null),
    });
    assertEquals([observed.installed_version, observed.present], [
        "1.5.13",
        true,
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
    for (
        const raw of [
            ",5999302598852608",
            version + "\n",
            version + "1".repeat(233),
        ]
    ) {
        assertEquals(normalizeVersion(raw, "brew-cask"), null);
    }
    for (const manager of ["brew", "apt", "vp", "composer"] as const) {
        assertEquals(normalizeVersion(version, manager), null);
    }
});
