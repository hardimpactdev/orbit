import {
    assert,
    assertEquals,
    assertFalse,
    assertMatch,
} from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { command, ROOT } from "./gate-test-support.ts";
type Definition = {
    id: string;
    name: string;
    globalArguments: unknown;
    methods: unknown;
    queueTimeout: number;
};

const yaml = (path: string) => parse(Deno.readTextFileSync(path)) as Definition;
Deno.test("model IDs are unique", async () => {
    const ids = new Set();
    for await (const path of expandGlob(`${ROOT}/models/**/*.yaml`)) {
        const model = yaml(path.path), id = model.id.toLowerCase();
        assertMatch(id, /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/);
        assertFalse(ids.has(id), path.path);
        ids.add(id);
    }
});
Deno.test("workflow queue bounds have measured per-kind budgets", async () => {
    for await (const path of expandGlob(`${ROOT}/workflows/*.yaml`)) {
        const workflow = yaml(path.path);
        const budgets: Record<string, number> = {
            "operation-task-vm-sweep": 60,
            "operation-task-vm-dispatch": 60,
            "operation-instance-create": 10,
            "operation-instance-mail-update": 10,
            "operation-instance-runtime-update": 10,
            "task-command-run": 300,
            "swamp-credential-monitor": 120,
            "operation-gateway-swamp-load": 120,
            "operation-gateway-release": 120,
            "gateway-release-activate": 120,
            "swamp-datastore-watch": 120,
        };
        if (workflow.name in budgets) {
            assertEquals(workflow.queueTimeout, budgets[workflow.name]);
        } else if ("queueTimeout" in workflow) {
            assert([30, 60].includes(workflow.queueTimeout), path.name);
        }
    }
});
Deno.test("every Ansible file ships with the ansible-input extension", async () => {
    // A playbook that imports a task file the manifest does not list fails on the worker before any task runs.
    const shipped = new Set(
        (parse(Deno.readTextFileSync(`${ROOT}/manifest.yaml`)) as {
            additionalFiles: string[];
        }).additionalFiles,
    );
    for await (
        const path of expandGlob(`${ROOT}/ansible/**/*.{yml,py,txt,cfg}`)
    ) {
        const relative = path.path.slice(ROOT.length + 1);
        assert(
            shipped.has(relative),
            `${relative} is missing from manifest.yaml additionalFiles`,
        );
    }
});
Deno.test("runtime credentials and generated records are ignored", async () => {
    const paths = [
        "models/swamp/enrollment-token/example.yaml",
        "vaults/local_encryption/example.yaml",
        "bin/__pycache__/serve-command.cpython-313.pyc",
    ];
    const result = await command([
        "git",
        "check-ignore",
        "--no-index",
        ...paths,
    ], ROOT);
    assertEquals(new Set(result.stdout.trim().split("\n")), new Set(paths));
});
