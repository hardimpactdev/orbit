import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { homebrewBlock } from "./homebrew.ts";
import { ToolRefusal } from "./contract.ts";

function fixture(manager: "brew" | "brew-cask") {
    const input = {
        manager,
        package: "hello",
        platform: manager === "brew-cask" ? "macos" : "linux",
        architecture: "x86_64",
    } as const;
    const facts: Record<string, unknown> = {
        os_major: 15,
        prefix: "/prefix",
        home: "/home/orbit",
    };
    facts.homebrew = manager === "brew"
        ? {
            name: "hello",
            full_name: "hello",
            tap: "homebrew/core",
            disabled: false,
            installed: [{ version: "1.0.0", installed_on_request: true }],
            versions: { bottle: true },
            bottle: {
                stable: {
                    files: {
                        x86_64_linux: {
                            sha256: "a".repeat(64),
                            url: "https://ghcr.io/v2/homebrew/core/hello/sha256:" +
                                "a".repeat(64),
                        },
                    },
                },
            },
        }
        : {
            token: "hello",
            full_token: "hello",
            tap: "homebrew/cask",
            disabled: false,
            url: "https://example.test/download",
            version: "1.0.0",
            sha256: "b".repeat(64),
            artifacts: [{ font: ["Font.ttf"] }],
            variations: {},
        };
    return { input: { ...input }, facts };
}

Deno.test("Homebrew eligibility preserves official roots, current bottles and safe cask artifacts", () => {
    const f = fixture("brew"),
        record = f.facts.homebrew as Record<string, unknown>;
    assertEquals(homebrewBlock(f.input, f.facts), null);
    record.installed = [{ version: "1.0.0", installed_on_request: false }];
    assertEquals(homebrewBlock(f.input, f.facts), "dependency");
    assertEquals(homebrewBlock(f.input, f.facts, true), null);
    record.installed = [{ version: "1.0.0", installed_on_request: true }];
    record.disabled = true;
    assertEquals(homebrewBlock(f.input, f.facts), "unsupported_artifact");
    record.disabled = false;
    record.bottle = { stable: { files: {} } };
    assertEquals(homebrewBlock(f.input, f.facts), "bottle_unavailable");
    const c = fixture("brew-cask"),
        cask = c.facts.homebrew as Record<string, unknown>;
    for (
        const [artifacts, block] of [
            [[{ app: ["Hello.app"] }], "authorization_required"],
            [[{ preflight: [] }], "unsupported_artifact"],
            [[{ font: ["Font.ttf"] }, {
                uninstall: [{ pkgutil: "com.example" }],
            }], "authorization_required"],
            [
                [{ font: ["Font.ttf"] }, {
                    uninstall: [{ script: "anything" }],
                }],
                "unsupported_artifact",
            ],
        ] as const
    ) {
        cask.artifacts = artifacts;
        assertEquals(homebrewBlock(c.input, c.facts), block);
    }
    cask.artifacts = [{ font: ["Font.ttf"] }];
    cask.sha256 = "no_check";
    assertEquals(homebrewBlock(c.input, c.facts), "checksum_unavailable");
    cask.sha256 = "a".repeat(64);
    cask.variations = { sequoia: { artifacts: [{ app: ["Hello.app"] }] } };
    assertEquals(homebrewBlock(c.input, c.facts), "authorization_required");
});

Deno.test("Homebrew requires supported target variations and accepts canonical architecture aliases", () => {
    const f = fixture("brew");
    f.input.architecture = "amd64" as "x86_64";
    assertEquals(homebrewBlock(f.input, f.facts), null);
    const c = fixture("brew-cask");
    c.facts.os_major = 10;
    assertThrows(
        () => homebrewBlock(c.input, c.facts),
        ToolRefusal,
        "tool.version_probe_failed",
    );
    c.facts.os_major = 15;
    c.input.architecture = "unknown" as "x86_64";
    assertThrows(
        () => homebrewBlock(c.input, c.facts),
        ToolRefusal,
        "tool.version_probe_failed",
    );
});
