/**
 * The power helper of a test copy (docs/reference/prepared-restore.md#the-power-helper): the shipped call task as it is, the shipped
 * install task made fit for an unprivileged user (its folders in the fixture, no owner, no daemon reload, a probe that writes a
 * manifest without support, as instance_host.ts does for the converge), and a `systemd-run` in `bin` that answers as the helper
 * through fixtures/instance-power-helper.ts on the fixture's state.json. The helper's own engine has its own tests.
 */
import { parse } from "jsr:@std/yaml@1";

const root = new URL("../../ansible/", import.meta.url);

/** Install the fixture helper into `folder` (bin, state) for a playbook in `playbook` whose tasks live in `playbook/tasks`. */
export function installPowerHelper(folder: string, playbook: string) {
    for (const dir of ["bin", "state", "systemd", "libexec"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
    Deno.mkdirSync(`${playbook}/tasks`, { recursive: true });
    Deno.mkdirSync(`${playbook}/files`, { recursive: true });
    Deno.copyFileSync(new URL("files/instance-container-power.py", root), `${playbook}/files/instance-container-power.py`);
    Deno.copyFileSync(new URL("tasks/instance-power-call.yml", root), `${playbook}/tasks/instance-power-call.yml`);
    const tasks = (parse(Deno.readTextFileSync(new URL("tasks/instance-power-helper.yml", root))) as Record<string, unknown>[]).map((task) => {
        for (const module of ["ansible.builtin.copy", "ansible.builtin.file"]) {
            const args = task[module] as Record<string, unknown> | undefined;
            if (args) { delete args.owner; delete args.group; }
        }
        if (task["ansible.builtin.systemd_service"]) { delete task["ansible.builtin.systemd_service"]; task["ansible.builtin.debug"] = { msg: "daemon-reload" }; }
        if (task.name === "Probe the kernel primitives of the power helper") {
            task["ansible.builtin.command"] = { argv: ["sh", "-c", `echo '{"supported": false}' > /var/lib/orbit/hibernation/capabilities.json && echo '{"capabilities": {}}'`] };
        }
        return task;
    });
    let text = JSON.stringify(tasks);
    for (const [from, to] of [["/usr/local/libexec", `${folder}/libexec`], ["/var/lib/orbit/hibernation", `${folder}/state`], ["/etc/systemd/system/", `${folder}/systemd/`]]) {
        text = text.replaceAll(from, to);
    }
    Deno.writeTextFileSync(`${playbook}/tasks/instance-power-helper.yml`, text);
    Deno.writeTextFileSync(`${folder}/bin/systemd-run`,
        `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("../fixtures/instance-power-helper.ts", import.meta.url).pathname}' "$@"\n`);
    Deno.chmodSync(`${folder}/bin/systemd-run`, 0o755);
}

/** The helper calls of the run, each as its argument list. */
export function helperCalls(folder: string): string[][] {
    try {
        return Deno.readTextFileSync(`${folder}/helper-calls`).split("\n").filter(Boolean).map((line) => JSON.parse(line) as string[]);
    } catch {
        return [];
    }
}
