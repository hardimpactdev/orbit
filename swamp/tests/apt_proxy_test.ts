/** A Node of a task VM's nested fleet reads its apt packages through the VM's package cache; every other Node reads the archive. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const read = (path: string) =>
    Deno.readTextFileSync(new URL(path, import.meta.url));
// deno-lint-ignore no-explicit-any
type Step = Record<string, any>;
const bootstrapSteps = (): Step[] =>
    (parse(read("../workflows/workflow-gateway-bootstrap.yaml")) as Step).jobs
        .flatMap((job: Step) => job.steps as Step[]);

Deno.test("the first apt call of a nested Gateway names the package cache of its intent, as the drop-in does", () => {
    const step = bootstrapSteps().find((s) =>
        s.name === "Install the prerequisites"
    )!;
    const argv = step.task.inputs.argv as string;
    assertStringIncludes(argv, "has(inputs.intent.apt_proxy)");
    assertStringIncludes(
        argv,
        '"Acquire::http::Proxy=" + inputs.intent.apt_proxy',
    );
    assertStringIncludes(argv, '"Acquire::http::Pipeline-Depth=0"');
    assertStringIncludes(argv, '"Acquire::https::Proxy=DIRECT"');
    // The drop-in of the later runs says the same.
    const dropIn = read("../ansible/tasks/apt-proxy.yml");
    assertStringIncludes(dropIn, 'Acquire::http::Pipeline-Depth "0";');
    assertStringIncludes(dropIn, 'Acquire::https::Proxy "DIRECT";');
});

Deno.test("the plan of the nested Gateway carries the package cache of its intent", () => {
    const step = bootstrapSteps().find((s) => s.name === "Render the plan")!;
    assertStringIncludes(
        step.task.inputs.run,
        '--apt-proxy="$ORBIT_APT_PROXY"',
    );
    assertStringIncludes(
        step.task.inputs.env.ORBIT_APT_PROXY,
        "inputs.intent.apt_proxy",
    );
});

// Some of these task files hold a `!!null ""` that @std/yaml does not read, so the order is read from the text.
const firstTask = (task: string) =>
    read(`../ansible/${task}`).split("\n- name: ")[1]?.split("\n- ")[0];

Deno.test("every apt run of a nested Node comes after the drop-in", () => {
    for (
        const [task, source] of [["tasks/role-prerequisites.yml", "intent"], [
            "tasks/exporter.yml",
            "exporter",
        ]]
    ) {
        assertEquals(
            firstTask(task),
            `Point apt at the package cache\n  ansible.builtin.import_tasks: apt-proxy.yml\n  vars: { apt_proxy: "{{ ${source}.apt_proxy | default(none) }}" }`,
        );
    }
    const enrollment = read("../ansible/operation-node-add.yml");
    const drop = enrollment.indexOf(
        "- name: Point apt at the package cache\n      ansible.builtin.import_tasks: tasks/apt-proxy.yml\n",
    );
    assertEquals(
        drop >= 0 &&
            drop < enrollment.indexOf("- name: Install the base packages"),
        true,
    );
});

const PROXY = "http://10.232.2.1:3142";
const DROP_IN = "90orbit-package-cache";
const hasAnsible =
    new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
        .outputSync().success;

/** Runs `tasks/apt-proxy.yml` on localhost against a scratch apt.conf.d; returns the drop-in path and the play output. */
const runTaskFile = (extra: string[], flags: string[], stale = false) => {
    const folder = Deno.makeTempDirSync();
    Deno.mkdirSync(`${folder}/conf`);
    if (stale) Deno.writeTextFileSync(`${folder}/conf/${DROP_IN}`, "stale\n");
    const play = [{
        hosts: "localhost",
        gather_facts: false,
        vars: {
            apt_conf_directory: `${folder}/conf`,
            intent: { apt_proxy: null },
        },
        tasks: [{
            "ansible.builtin.import_tasks":
                new URL("../ansible/tasks/apt-proxy.yml", import.meta.url)
                    .pathname,
            vars: { apt_proxy: "{{ intent.apt_proxy | default(none) }}" },
        }],
    }];
    Deno.writeTextFileSync(`${folder}/play.yml`, JSON.stringify(play));
    const done = new Deno.Command("ansible-playbook", {
        args: [
            "-i",
            "localhost,",
            "-c",
            "local",
            ...flags,
            ...extra,
            `${folder}/play.yml`,
        ],
        env: {
            ANSIBLE_HOME: `${folder}/home`,
            ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
            ANSIBLE_NOCOLOR: "1",
        },
    }).outputSync();
    const output = new TextDecoder().decode(done.stdout);
    assertEquals(
        done.success,
        true,
        output + new TextDecoder().decode(done.stderr),
    );
    return { path: `${folder}/conf/${DROP_IN}`, output, folder };
};
const exists = (path: string) => {
    try {
        Deno.statSync(path);
        return true;
    } catch {
        return false;
    }
};

Deno.test({
    name: "a nested Node gets the drop-in",
    ignore: !hasAnsible,
    fn: () => {
        // Check mode with a diff shows the file the play would write without the root account that its owner needs.
        const run = runTaskFile([
            "-e",
            JSON.stringify({ intent: { apt_proxy: PROXY } }),
        ], ["--check", "--diff"]);
        for (
            const line of [
                `+Acquire::http::Proxy "${PROXY}";`,
                '+Acquire::http::Pipeline-Depth "0";',
                '+Acquire::https::Proxy "DIRECT";',
            ]
        ) {
            assertStringIncludes(run.output, line);
        }
        assertStringIncludes(run.output, "changed=1");
        Deno.removeSync(run.folder, { recursive: true });
    },
});

Deno.test({
    name: "every other Node gets none and loses a stale one",
    ignore: !hasAnsible,
    fn: () => {
        const clean = runTaskFile([], []);
        assertEquals(exists(clean.path), false);
        assertStringIncludes(clean.output, "changed=0");
        Deno.removeSync(clean.folder, { recursive: true });
        const stale = runTaskFile([], [], true);
        assertEquals(exists(stale.path), false);
        assertStringIncludes(stale.output, "changed=1");
        Deno.removeSync(stale.folder, { recursive: true });
    },
});
