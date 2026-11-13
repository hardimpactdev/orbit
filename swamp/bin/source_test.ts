import {
    assert,
    assertEquals,
    assertFalse,
    assertMatch,
} from "jsr:@std/assert@1.0.19";
import { expandGlob } from "jsr:@std/fs@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { command, type Flow, ROOT } from "./gate-test-support.ts";
import { resolveConstantName } from "./constant-name.ts";
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

Deno.test("hot workflow trees avoid literal behavior lookups and preserve constant names", async () => {
    const workflows = new Map<string, Flow>();
    for await (const path of expandGlob(`${ROOT}/workflows/*.yaml`)) {
        const workflow = parse(await Deno.readTextFile(path.path)) as Flow;
        workflows.set(workflow.name!, workflow);
        if (workflow.id) workflows.set(workflow.id, workflow);
    }
    // These fixed identities must never become computed names.
    const expected = new Map(Object.entries({
        "operation-instance-migrate/input": "ansible-input",
        "route-withdraw-node/input": "ansible-input",
        "route-dns-grace/wait-private-dns-ttl": "route-dns-grace",
        "route-ingress-apply/result": "route-ingress-result",
        "route-lan-apply/changes": "route-lan-changes",
        "route-lan-apply/verify-tuples": "route-lan-applied",
        "route-lan-apply/result": "route-lan-result",
        "route-mutation-forward/result": "route-mutation-phase",
        "route-mutation-withdraw/result": "route-mutation-phase",
        "route-mutation-restore/result": "route-mutation-phase",
        "route-withdraw/result": "route-mutation-phase",
        "operation-vault-sync/Prepare public data": "vault-sync-inputs",
        "operation-vault-sync/Prepare session file": "vault-sync-session-file",
        "operation-vault-sync/Resolve the session": "vault-sync-session",
        "operation-vault-sync/Read private inventory": "vault-sync-read-inventory",
        "operation-vault-sync/inventory": "vault-sync-public-inventory",
        "operation-vault-sync/Read private trash": "vault-sync-read-trash",
        "operation-vault-sync/trash": "vault-sync-public-trash",
    }));
    const seen = new Set<Flow>();
    function walk(target: string) {
        const workflow = workflows.get(resolveConstantName(target));
        assert(workflow, `Unknown child workflow: ${target}`);
        if (seen.has(workflow)) return;
        seen.add(workflow);
        for (const job of workflow.jobs) {
            for (const step of job.steps) {
                const task = step.task;
                if (task.type === "workflow") {
                    assert(task.workflowIdOrName, `${workflow.name}/${step.name}`);
                    walk(task.workflowIdOrName);
                } else if (task.type === "behavior" || task.type === "model_method") {
                    const at = `${workflow.name}/${step.name}`;
                    // Swamp reads the same reference from either spelling of a method step.
                    const spelled = task as Record<string, unknown>;
                    const name = spelled.behaviorName ?? spelled.modelName ?? spelled.behaviorIdOrName ??
                        spelled.modelIdOrName;
                    assert(
                        typeof name === "string" && name.includes("${{"),
                        `${at}: literal behavior name ${name}`,
                    );
                    const plain = resolveConstantName(name);
                    if (plain !== name) {
                        assertEquals(name, '${{ "' + plain + '" }}', at);
                    } else {
                        // Existing computed names depend on a target or fan-out item.
                        // Reject alternative constant spellings and constant computations.
                        assertMatch(name, /\b(?:inputs|steps|self|run)\./, at);
                    }
                    if (expected.has(at)) {
                        assertEquals(
                            name,
                            '${{ "' + expected.get(at) + '" }}',
                            at,
                        );
                        expected.delete(at);
                    }
                }
            }
        }
    }
    for (const root of [
        "route-mutation-forward",
        "route-mutation-withdraw",
        "route-withdraw",
        "operation-instance-migrate",
        // Runs every two minutes on the shared starter.
        "operation-vault-sync",
    ]) walk(root);
    assertEquals(
        [...expected.keys()],
        [],
        "Every fixed identity must be reached",
    );
});
