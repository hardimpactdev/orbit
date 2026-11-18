/** The gateway role's runtime removes the retired settings from the shared Gateway environment and keeps every other line. */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const hasAnsible =
  new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] })
    .outputSync().success;

type Task = { name: string };
const runtime = parse(
  Deno.readTextFileSync(
    new URL("../ansible/tasks/gateway-runtime.yml", import.meta.url),
  ),
) as Task[];

Deno.test({
  name:
    "retired settings leave the Gateway environment; the other lines stay as they were",
  ignore: !hasAnsible,
  fn() {
    const folder = Deno.makeTempDirSync();
    const env = `${folder}/gateway.env`;
    Deno.writeTextFileSync(
      env,
      "APP_KEY=base64:abc\nORBIT_TASK_VMS_UPCLOUD_PLAN=4xCPU-8GB\nORBIT_TASK_VMS_ENABLED=true\nORBIT_TASK_VMS_UPCLOUD_DISK_GB=80\n",
    );
    const task = runtime.find((t) =>
      t.name === "Remove retired settings from the Gateway environment"
    );
    const play = [{
      hosts: "localhost",
      gather_facts: false,
      vars: {
        rt: {
          gateway: {
            environment: env,
            retired_settings: [
              "ORBIT_TASK_VMS_UPCLOUD_PLAN",
              "ORBIT_TASK_VMS_UPCLOUD_DISK_GB",
              "ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE",
            ],
          },
        },
      },
      tasks: [task],
    }];
    Deno.writeTextFileSync(`${folder}/play.yml`, JSON.stringify(play));
    const done = new Deno.Command("ansible-playbook", {
      args: ["-i", "localhost,", "-c", "local", `${folder}/play.yml`],
      env: {
        ANSIBLE_HOME: `${folder}/home`,
        ANSIBLE_LOCAL_TEMP: `${folder}/tmp`,
        ANSIBLE_NOCOLOR: "1",
      },
    }).outputSync();
    assertEquals(done.success, true, new TextDecoder().decode(done.stdout));
    assertEquals(
      Deno.readTextFileSync(env),
      "APP_KEY=base64:abc\nORBIT_TASK_VMS_ENABLED=true\n",
    );
    Deno.removeSync(folder, { recursive: true });
  },
});

Deno.test("host recovery removal requires a committed handover in the recorded inventory version", () => {
    const tasks = parse(Deno.readTextFileSync(
        new URL("../ansible/tasks/gateway-retire.yml", import.meta.url),
    )) as Record<string, unknown>[];
    const fence = tasks.find((task) =>
        String(task.name).startsWith("Require a completed container handover")
    )!;
    const { argv } = fence["ansible.builtin.command"] as { argv: string[] };
    const folder = Deno.makeTempDirSync();
    try {
        const inventory = folder + "/inventory";
        const marker = folder + "/host-retired";
        const check = (old: string | null, host_retired: boolean, version = 1) => {
            Deno.writeTextFileSync(
                inventory,
                JSON.stringify({
                    version,
                    live: "a".repeat(40),
                    old,
                    host_retired,
                }),
            );
            return new Deno.Command(argv[0], {
                args: [...argv.slice(1, -2), inventory, marker],
            }).outputSync().success;
        };
        assertEquals(check("b".repeat(40), true), false);
        Deno.writeTextFileSync(marker, "42");
        assertEquals(check(null, false), false);
        assertEquals(check("b".repeat(40), false), false);
        assertEquals(check("a".repeat(40), true), false);
        assertEquals(check("b".repeat(40), true), true);
        assertEquals(check(null, true, 2), true);
        assertEquals(check("b".repeat(40), true, 2), false);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
