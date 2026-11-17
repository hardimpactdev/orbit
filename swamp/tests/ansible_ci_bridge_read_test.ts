/**
 * The read of a ci-runner Node's CI bridge (ansible/tasks/ci-bridge-read.yml, docs/reference/development-containers.md#nodes), run by
 * Ansible against a stub `incus` that answers `orbitci0`'s address, and the workflow step that prints it for the Gateway.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

const tasks = fileURLToPath(new URL("../ansible/tasks/ci-bridge-read.yml", import.meta.url));
const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

async function read(answer: string | null) {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/bin/incus`, answer === null
            ? "#!/bin/sh\necho 'Error: Network not found' >&2\nexit 1\n"
            : `#!/bin/sh\n[ "$*" = "network get orbitci0 ipv4.address" ] && echo '${answer}'\n`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.writeTextFile(`${folder}/play.yml`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            tasks: [{ name: "Read the CI bridge", "ansible.builtin.include_tasks": tasks }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", `${folder}/play.yml`], stdout: "piped", stderr: "piped",
            env: { PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ORBIT_RUN: folder, ANSIBLE_LOCALHOST_WARNING: "False" },
        }).output();
        assertEquals(result.code, 0, new TextDecoder().decode(result.stdout));
        try { return Deno.readTextFileSync(`${folder}/ci-bridge`); } catch { return null; }
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
}

Deno.test("the CI bridge's address goes to the run folder; a Node without orbitci0 writes nothing", { ignore: !hasAnsible }, async () => {
    assertEquals(await read("10.253.7.1/24"), "10.253.7.1/24\n");
    assertEquals(await read(null), null);
});

Deno.test("the role converge reads the CI bridge before the Instance branch and after the CI resources, and prints it for a ci-runner run only", () => {
    const runtime = parse(Deno.readTextFileSync(new URL("../ansible/tasks/node-role-runtime.yml", import.meta.url))) as { name: string }[];
    const at = (name: string) => runtime.findIndex((task) => task.name === name);
    assertEquals(at("Read the CI bridge before the Instance container host") < at("Prepare the Instance container host"), true);
    assertEquals(at("Apply CI host resources") < at("Read the CI bridge after the CI host resources"), true);
    const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-role-add.yaml", import.meta.url))) as
        { jobs: { name: string; steps: Record<string, unknown>[] }[] };
    const step = workflow.jobs.find((job) => job.name === "node")!.steps.find((item) => item.name === "Read the CI bridge")!;
    const cel = new Environment({ unlistedVariablesAreDyn: true });
    for (const role of ["ci-runner", "app-dev"]) {
        for (const unchanged of [[], ["node"], ["agent"]]) {
            assertEquals(cel.evaluate((step.guard as string).slice(3, -2).trim(), {
                inputs: { intent: { role } }, steps: { "Compare phase digests": { outputs: { unchanged } } },
            }), role !== "ci-runner" || unchanged.includes("node"));
        }
    }
    assertEquals(step.dependsOn, [{ step: "Apply the role on the Node", condition: { type: "always" } }]);
});
