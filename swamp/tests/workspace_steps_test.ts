import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Edge = { step: string; condition?: { type?: string } };
type Step = { name: string; guard?: string; dependsOn?: Edge[]; task?: { behaviorType?: string; methodName?: string; inputs?: Record<string, unknown> } };
type Workflow = { jobs: { steps: Step[] }[] };

const workflows = new URL("../workflows/", import.meta.url);
const ansible = new URL("../ansible/", import.meta.url);

async function source(): Promise<Step[]> {
    const workflow = parse(await Deno.readTextFile(new URL("workflow-task-workspace-source.yaml", workflows))) as Workflow;
    return workflow.jobs.flatMap((job) => job.steps);
}
const parents = (step: Step) => (step.dependsOn ?? []).map((edge) => edge.step);

/**
 * The number of dispatches on the longest chain of `dependsOn` edges ending at `name`; a forEach step counts once. The three proof club steps
 * run only for a proof Project and are skipped by their guard otherwise, so the bound leaves them out.
 */
function depth(steps: Step[], name: string, seen = new Map<string, number>()): number {
    const known = seen.get(name);
    if (known !== undefined) return known;
    const step = steps.find((candidate) => candidate.name === name);
    assert(step, `no step ${name}`);
    const value = 1 + Math.max(0, ...parents(step).filter((parent) => !parent.startsWith("proof_club")).map((parent) => depth(steps, parent, seen)));
    seen.set(name, value);
    return value;
}

Deno.test("the workspace source keeps its guest work in two Ansible phases and a short chain of dispatches", async () => {
    const steps = await source();
    const names = steps.map((step) => step.name);
    // The thirteen guest and control steps that the two phases and the merged clone replaced are gone.
    for (const gone of ["prepare_remote", "control", "directory", "bundle", "copy_bundle", "import", "origin", "branch", "identity", "inspect", "composer"]) {
        assert(!names.includes(gone), `${gone} must not come back as a step of its own`);
    }
    assert(names.every((name) => !name.startsWith("access-")), "the Route's rows of Caddy access run inside the checkout phase");
    assert(steps.length <= 22, `the source has ${steps.length} steps`);
    // The longest chain of dispatches from the start to the last step. It was 20 before the phases (23 with the inner steps of the child workflows).
    const longest = Math.max(...names.filter((name) => !name.startsWith("proof_club")).map((name) => depth(steps, name)));
    assert(longest <= 9, `the longest chain is ${longest} dispatches`);
});

Deno.test("the phases and the data steps are ordered by what they read", async () => {
    const byName = new Map((await source()).map((step) => [step.name, step]));
    const needs = (name: string) => parents(byName.get(name)!);
    assertEquals(needs("mint_clone"), []);
    assertEquals(needs("clone"), ["mint_clone"]);
    assertEquals(needs("input"), ["guest"]);
    assertEquals(needs("prepare"), ["input"]);
    // The checkout phase needs the folders and the bundle; nothing before it needs the other.
    assertEquals(needs("checkout").sort(), ["clone", "prepare"]);
    assertEquals(needs("profile"), ["checkout"]);
    for (const name of ["deps_write_composer", "deps_write_npm"]) {
        assert(needs(name).includes("checkout") && !needs(name).includes("profile"), `${name} waits for the checkout, not for the profile`);
    }
    // The receipt does not wait for the output copy; the cleanups wait for every reader of the run folders.
    assert(!needs("result").includes("setup_output"));
    for (const name of ["cleanup", "cleanup_remote"]) {
        assert(needs(name).includes("setup_output") && needs(name).includes("checkout"), `${name} must wait for the readers of the run folders`);
    }
    // The setup steps still wait for the profile, which gates them on its error code.
    assert(needs("setup-${{ self._index }}").includes("profile"));
});

Deno.test("the phase steps run the playbook with the pinned inventory and the two run folders", async () => {
    const byName = new Map((await source()).map((step) => [step.name, step]));
    for (const [name, tag] of [["prepare", "prepare"], ["checkout", "checkout"]] as const) {
        const run = String(byName.get(name)!.task?.inputs?.run);
        assert(run.includes(`--tags ${tag} `) && run.includes("task-vm-workspace.yml"), `${name} runs the ${tag} part`);
        assert(run.includes('-i "$ORBIT_RUN/inventory.yml"'), `${name} uses the generated inventory`);
        const env = byName.get(name)!.task?.inputs?.env as Record<string, string>;
        for (const key of ["ORBIT_RUN", "ORBIT_ANSIBLE", "ANSIBLE_SSH_ARGS", "ORBIT_REMOTE_ROOT", "ORBIT_LOCAL_ROOT"]) assert(key in env, `${name} needs ${key}`);
        assert(String(env.ANSIBLE_SSH_ARGS).includes("UserKnownHostsFile=/tmp/orbit-ansible-"), `${name} pins the host keys of the intent`);
    }
    // Only the first phase builds the inventory and the known hosts.
    assert(String(byName.get("prepare")!.task?.inputs?.run).includes("build_vars.py"));
    assert(!String(byName.get("checkout")!.task?.inputs?.run).includes("build_vars.py"));
});

Deno.test("the workflow hands the playbook every field of the workspace that it reads", async () => {
    const byName = new Map((await source()).map((step) => [step.name, step]));
    const intent = String(byName.get("input")!.task?.inputs?.intent);
    const playbook = await Deno.readTextFile(new URL("task-vm-workspace.yml", ansible));
    const used = new Set([...playbook.matchAll(/workspace\.([a-z_]+)/g)].map((match) => match[1]));
    assert(used.size >= 7, `the playbook reads ${used.size} workspace fields`);
    for (const field of used) assert(intent.includes(`"${field}":`), `the input step must pass workspace.${field}`);
    assert(intent.includes('"ssh_execution":'), "the vars builder reads ssh_execution");
});

Deno.test("the workspace playbook has the two parts, runs no script on the VM, and writes only the two result files on the Swamp Node", async () => {
    const text = await Deno.readTextFile(new URL("task-vm-workspace.yml", ansible));
    type Play = { tasks: { name: string; tags: string[]; block: Record<string, unknown>[] }[] };
    const play = (parse(text) as Play[])[0];
    assertEquals(play.tasks.map((task) => task.tags[0]), ["prepare", "checkout"]);
    const modules = play.tasks.flatMap((task) => task.block.flatMap((step) => Object.keys(step).filter((key) => key.startsWith("ansible.builtin."))));
    assert(!modules.includes("ansible.builtin.shell") && !modules.includes("ansible.builtin.script") && !modules.includes("ansible.builtin.raw"), "no shell, script or raw task");
    // The files that come back to the Swamp Node, which `profile` reads and deletes.
    assert(text.includes('dest: "{{ local_root }}/facts.json"') && text.includes('dest: "{{ local_root }}/composer.json"'));
    // The clone credential never reaches the VM: the playbook reads no vault value and no token.
    assert(!/token|vault|authorization/i.test(text.replace(/#.*$/gm, "")), "the playbook holds no credential");
});

Deno.test("the vars builder reaches the workspace VM with its pinned host key and hands the playbook the workspace", async () => {
    const folder = await Deno.makeTempDir();
    const intent = {
        ssh_execution: {
            identity_file: "/role/identity",
            target: { host: "10.252.7.9", port: 22, user: "orbit", host_keys: ["ssh-ed25519 PINNED"] },
        },
        workspace: { checkout_path: "/home/orbit/apps/p/workspace", repository: "https://github.com/acme/{{ not a template }}.git", access: [] },
    };
    await Deno.writeTextFile(`${folder}/intent.json`, JSON.stringify(intent));
    const build = new URL("build_vars.py", ansible);
    const output = await new Deno.Command("python3", { args: ["-I", build.pathname, folder, "task_vm.workspace"] }).output();
    assertEquals(output.code, 0, new TextDecoder().decode(output.stderr));
    const inventory = JSON.parse(await Deno.readTextFile(`${folder}/inventory.yml`));
    const host = inventory.all.children.task_vm.hosts.vm;
    assertEquals([host.ansible_host, host.ansible_user, host.ansible_port, host.ansible_ssh_private_key_file], ["10.252.7.9", "orbit", 22, "/role/identity"]);
    assertEquals(await Deno.readTextFile(`${folder}/known_hosts`), "10.252.7.9 ssh-ed25519 PINNED\n");
    // Gateway text stays literal: a template in the repository URL must not be rendered by Ansible.
    assert((await Deno.readTextFile(`${folder}/vars.yml`)).includes("!unsafe"));
    await Deno.remove(folder, { recursive: true });
});

Deno.test("every workflow that asks the provision data behavior for a guest passes only the arguments the method knows", async () => {
    const known = new Set(["intent", "vm", "root"]);
    let callers = 0;
    for await (const entry of Deno.readDir(workflows)) {
        if (!entry.name.endsWith(".yaml")) continue;
        const flow = parse(await Deno.readTextFile(new URL(entry.name, workflows))) as Workflow;
        for (const step of flow.jobs.flatMap((job) => job.steps)) {
            if (step.task?.behaviorType !== "@nckrtl/task-provision-data" || step.task.methodName !== "guest") continue;
            callers += 1;
            // Only the workspace source builds the setup argv, so only it passes a root.
            assertEquals("root" in (step.task.inputs ?? {}), entry.name === "workflow-task-workspace-source.yaml", `${entry.name}/${step.name}: root`);
            for (const key of Object.keys(step.task.inputs ?? {})) assert(known.has(key), `${entry.name}/${step.name}: unknown argument ${key}`);
        }
    }
    // The workspace source, the fleet guest of a provision and the nested fleet workflow.
    assert(callers >= 3, `only ${callers} callers found`);
});
