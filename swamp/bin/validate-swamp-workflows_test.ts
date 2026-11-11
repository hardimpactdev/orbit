import {
    assert,
    assertEquals,
    assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { run, script } from "./gate-test-support.ts";
const source = await Deno.readTextFile(
    new URL("../../.github/workflows/swamp-shape.yml", import.meta.url),
);
const validate = source.split("\n  validate:\n")[1].split("\n  tests:\n")[0];
Deno.test("validation receives only its registry-read credentials", () => {
    for (const key of ["API_KEY", "SIGNIN_TOKEN"]) {
        assertStringIncludes(validate, "secrets.SWAMP_" + key);
    }
    assert(!source.includes("  native-tests:"));
    assert(!source.includes("  native-plan:"));
});
Deno.test("required validation only receives event-scoped credentials", () => {
    const shape = source.split("\n  shape:\n")[1].split("\n  validate:\n")[0],
        tests = source.split("\n  tests:\n")[1];
    for (const name of ["SWAMP_API_KEY", "SWAMP_SIGNIN_TOKEN"]) {
        assert(!shape.includes(name));
        assert(!tests.includes(name));
        assertStringIncludes(validate, "secrets." + name);
    }
    for (const forbidden of ["allow-auth-unavailable", "pull_request_target"]) {
        assert(!source.includes(forbidden));
    }
    assert(!validate.includes("continue-on-error"));
    assertStringIncludes(validate, "github.event_name == 'pull_request'");
    assertStringIncludes(validate, "github.ref == 'refs/heads/main'");
});
Deno.test("serve grant validation and coverage regressions remain required", async () => {
    assertStringIncludes(validate, "bin/swamp-shape-gate\" --only validate --strict");
    const gate = await Deno.readTextFile(
        new URL("../../bin/swamp-shape-gate", import.meta.url),
    );
    for (
        const required of [
            "'python3', 'bin/check-grant-patterns.py', '--cache'",
            "grant-validation.json",
        ]
    ) assertStringIncludes(gate, required);
    assert(
        (await Deno.stat(
            new URL(
                "../extensions/behaviors/operations/workflow_grant_patterns_test.ts",
                import.meta.url,
            ),
        )).isFile,
    );
    assert(!source.includes("check-grant-patterns.ts"));
    assert(!validate.includes("continue-on-error"));
    const ci = await Deno.readTextFile(
        new URL("../../.github/workflows/ci.yml", import.meta.url),
    );
    // Required checks depends on the reusable Swamp workflow, including validation.
    const required = ci.split("\n  required:\n")[1];
    const needs = required.match(/^    needs: \[(.+)\]$/m)?.[1].split(/,\s*/);
    assert(needs?.includes("swamp"));
});
Deno.test("native environment is explicit and configuration is isolated", async () => {
    const result = await run("python3", [
        "-c",
        `import importlib.util,json,sys
spec=importlib.util.spec_from_file_location('validate',sys.argv[1])
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
print(json.dumps([module.native_env('/fixture/fresh', {'HOME':'/ambient','SWAMP_CONFIG_DIR':'/ambient','SWAMP_API_KEY':'synthetic-key','SWAMP_SIGNIN_TOKEN':'synthetic-proof','UNAPPROVED':'synthetic'}), module.native_env('/fresh', {})]))`,
        script("validate-swamp-workflows.py"),
    ]);
    assertEquals(result.code, 0, result.stderr);
    const [env, empty] = JSON.parse(result.stdout);
    for (
        const name of [
            "HOME",
            "XDG_CONFIG_HOME",
            "XDG_CACHE_HOME",
            "SWAMP_CONFIG_DIR",
        ]
    ) assertEquals(env[name], "/fixture/fresh");
    assertEquals(env.SWAMP_API_KEY, "synthetic-key");
    assertEquals(env.SWAMP_SIGNIN_TOKEN, "synthetic-proof");
    assert(!("UNAPPROVED" in env));
    assert(!("SWAMP_API_KEY" in empty));
});
