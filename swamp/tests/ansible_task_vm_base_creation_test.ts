/** Run the shipped base creation tasks against failed leftovers and recorded bases. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";

const hasAnsible = new Deno.Command("sh", {
  args: ["-c", "command -v ansible-playbook"],
}).outputSync().success;
const plays = parse(Deno.readTextFileSync(
  new URL("../ansible/task-vm-host.yml", import.meta.url),
)) as { name: string; tasks: { name: string; block: unknown[] }[] }[];
const baseTasks = plays.find((p) => p.name === "Task VM base")!.tasks
  .find((t) => t.name === "Start the base VM")!.block.slice(0, 3);

function run(current: string | null, rebuild = false, leftover = true) {
  const folder = Deno.makeTempDirSync();
  try {
    Deno.mkdirSync(`${folder}/bin`);
    const file = `${folder}/state.json`;
    Deno.writeTextFileSync(
      file,
      JSON.stringify({
        vm: leftover
          ? { name: "host-base", generation: "failed-first-boot" }
          : null,
        seed: leftover,
        calls: [],
      }),
    );
    Deno.writeTextFileSync(
      `${folder}/bin/incus`,
      `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${
        new URL("fixtures/task-vm-base-incus.ts", import.meta.url).pathname
      }' "$@"\n`,
    );
    Deno.chmodSync(`${folder}/bin/incus`, 0o755);
    Deno.writeTextFileSync(
      `${folder}/play.yml`,
      stringify([{
        name: "Base creation",
        hosts: "localhost",
        gather_facts: false,
        vars: {
          host: {
            project: "orbit-tasks",
            pool: "orbit-tasks",
            image: "ubuntu-26.04-vm",
            network: "orbittask0",
            current,
            rebuild,
          },
          base: {
            vm: "host-base",
            seed: "host-base-seed",
            seed_size: "45GiB",
            cpus: 6,
            memory: "12GiB",
            disk: "60GiB",
            launch_config: "{}",
          },
          incus: ["incus", "--project", "orbit-tasks"],
        },
        tasks: baseTasks,
      }]),
    );
    const done = new Deno.Command("ansible-playbook", {
      args: [
        "-i",
        "localhost,",
        "-c",
        "local",
        "-e",
        "ansible_python_interpreter={{ ansible_playbook_python }}",
        `${folder}/play.yml`,
      ],
      env: {
        BASE_FIXTURE: file,
        PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
        ANSIBLE_HOME: `${folder}/home`,
        ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        ANSIBLE_NOCOLOR: "1",
      },
    }).outputSync();
    assertEquals(
      done.success,
      true,
      new TextDecoder().decode(done.stdout) +
        new TextDecoder().decode(done.stderr),
    );
    return JSON.parse(Deno.readTextFileSync(file));
  } finally {
    Deno.removeSync(folder, { recursive: true });
  }
}

Deno.test({
  name: "an unpromoted failed base and seed are replaced from stock",
  ignore: !hasAnsible,
  fn() {
    const state = run(null);
    assertEquals(state.vm.generation, "stock");
    assertEquals(state.seed, true);
    assertEquals(state.calls.slice(0, 2), [
      ["--project", "orbit-tasks", "delete", "--force", "host-base"],
      [
        "--project",
        "orbit-tasks",
        "storage",
        "volume",
        "delete",
        "orbit-tasks",
        "host-base-seed",
      ],
    ]);
    assertStringIncludes(JSON.stringify(state.calls), "init");
  },
});

Deno.test({
  name: "a recorded base stays intact unless rebuilding from stock is explicit",
  ignore: !hasAnsible,
  fn() {
    const kept = run("r20261107120000");
    assertEquals(kept.vm.generation, "failed-first-boot");
    assertEquals(kept.calls, [[
      "--project",
      "orbit-tasks",
      "list",
      "--format",
      "json",
      "--",
      "^host-base$",
    ]]);
    const rebuilt = run("r20261107120000", true);
    assertEquals(rebuilt.vm.generation, "stock");
  },
});

Deno.test({
  name:
    "a new host with no leftovers builds from stock despite not-found deletions",
  ignore: !hasAnsible,
  fn() {
    const state = run(null, false, false);
    assertEquals(state.vm.generation, "stock");
    assertEquals(state.seed, true);
  },
});
