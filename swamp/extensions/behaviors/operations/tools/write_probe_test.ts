import { assertEquals } from "jsr:@std/assert@1";
import {
    before,
    candidate,
    decision,
    WriteInput,
    writeResult,
} from "./write.ts";

const observation = (facts: unknown) => ({
    stdout: JSON.stringify(facts),
    exitCode: 0,
});

function installed(manager: "composer" | "brew-cask", kind = "tool.install") {
    const input = WriteInput.parse({
        kind,
        manager,
        node_id: 9,
        package: manager === "composer" ? "vendor/dependency" : "hello",
        user: "orbit",
        platform: manager === "composer" ? "linux" : "macos",
        architecture: "x86_64",
        tool_id: kind === "tool.update" ? 41 : null,
    });
    const facts = {
        scope: "present",
        manager_version: "3.0.0",
        versions: ["1.0.0"],
        complete: true,
        roots: [],
        os_major: 15,
        prefix: "/prefix",
        home: "/home/orbit",
        token_count: 1,
        homebrew: {
            token: "hello",
            full_token: "hello",
            tap: "homebrew/cask",
            disabled: false,
            url: "https://example.test/hello.zip",
            sha256: "a".repeat(64),
            version: "2.0.0",
            artifacts: [
                { font: ["Font.ttf"] },
                { uninstall: [{ pkgutil: "com.example.hello" }] },
            ],
            variations: {},
        },
    };
    return { input, facts };
}

for (const manager of ["composer", "brew-cask"] as const) {
    Deno.test(`2211-03: ${manager} existing-package install keeps its write refusal`, () => {
        const { input, facts } = installed(manager);
        const checked = before(input, observation(facts));
        assertEquals(checked.error_code, "tool.package_already_installed");
        const result = writeResult(input, {
            "inspect-0": { status: "succeeded", outputs: { ready: true } },
            "before-0": { status: "succeeded", outputs: checked },
        });
        assertEquals(result.ok, false);
        assertEquals(
            JSON.parse(result.stdout).error_code,
            "tool.package_already_installed",
        );
    });

    Deno.test(`2211-03: ${manager} update reads inventory without removal eligibility`, () => {
        const { input, facts } = installed(manager, "tool.update");
        const checked = before(input, observation(facts));
        assertEquals(checked.ready, true);
        assertEquals(checked.package_required, true);
    });

    Deno.test(`2211-03: ${manager} post-write check verifies the installed version`, () => {
        const { input, facts } = installed(manager);
        const checked = before(input, observation(facts), true);
        assertEquals(checked.ready, true);
        assertEquals(checked.package_required, false);
    });
}

Deno.test("2211-03: reconciliation uses write inventory for a Composer dependency", () => {
    const { input, facts } = installed("composer", "tool.update");
    const result = decision(
        input,
        facts,
        { ready: true, version: "2.0.0", allowed: true },
        observation(facts),
    );
    assertEquals(result.ready, true);
    assertEquals(result.mutate, true);
});

Deno.test("cask candidate still refuses artifacts requiring authorization", () => {
    const { input, facts } = installed("brew-cask", "tool.update");
    assertEquals(candidate(input, observation(facts)).ready, false);
});
