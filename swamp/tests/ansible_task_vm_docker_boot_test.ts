/** A task VM starts Docker at boot, as its package does: the agent's Docker watcher would start it through the socket at once anyway. */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Task = {
  name: string;
  loop?: string[];
  "ansible.builtin.systemd_service"?: { name: string; enabled: unknown };
};
const tasks = parse(
  Deno.readTextFileSync(
    new URL("../ansible/tasks/task-vm-boot.yml", import.meta.url),
  ),
) as Task[];

Deno.test("the boot enables containerd, Docker and its socket, and disables none of them", () => {
  const docker = tasks.filter((task) =>
    (task.loop ?? []).some((unit) => /docker|containerd/.test(String(unit)))
  );
  assertEquals(docker.map((task) => task.loop), [[
    "containerd.service",
    "docker.service",
    "docker.socket",
  ]]);
  assertEquals(docker[0]["ansible.builtin.systemd_service"]?.enabled, true);
});
