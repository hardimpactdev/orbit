/** Every copy of the base finds its uplink: the boot phase matches it by type and name, never by the base VM's MAC (refresh 13137). */
import { assert, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

type Task = {
  name: string;
  "ansible.builtin.copy"?: { dest: string; content: string };
};
const tasks = parse(
  Deno.readTextFileSync(
    new URL("../ansible/tasks/task-vm-boot.yml", import.meta.url),
  ),
) as Task[];
const file = (dest: string) =>
  tasks.find((task) => task["ansible.builtin.copy"]?.dest === dest)
    ?.["ansible.builtin.copy"]?.content ?? "";

Deno.test("the uplink file matches any Ethernet link by name and asks DHCP for IPv4", () => {
  const uplink = file("/etc/systemd/network/00-orbit-task-vm.network");
  assertStringIncludes(uplink, "[Match]\nType=ether\nName=en*\n");
  assertStringIncludes(uplink, "DHCP=ipv4\n");
  assert(!/MACAddress|PermanentMACAddress/.test(uplink));
});

Deno.test("boot waits online for IPv4 only", () => {
  assertStringIncludes(
    file(
      "/etc/systemd/system/systemd-networkd-wait-online.service.d/zz-orbit-ipv4.conf",
    ),
    "--any --ipv4",
  );
});
