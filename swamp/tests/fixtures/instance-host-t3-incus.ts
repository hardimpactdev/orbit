/**
 * The Incus fake of the T3 drop-in converge cases (docs/reference/development-containers.md#t3s-unit-drop-ins): it answers the systemd
 * calls of the drop-in step itself and hands every other call, with its argv, stdin and environment, to the shared fake
 * (task-vm-host-incus.ts), which stays as every other case uses it.
 *
 * - `systemctl show -p NeedDaemonReload --value t3.service` prints `yes` for a container in the state's `need_reload`.
 * - `systemctl daemon-reload` takes the container out of `need_reload`.
 * - `systemctl try-restart t3.service` without the agent folder's `.restart` mark, the restart for a new drop-in alone, is recorded in
 *   `folder_restarts` as the shared fake records the folder's own restarts.
 */
const folder = Deno.env.get("HOST_FIXTURE")!;
const args = [...Deno.args];
const command = args.includes("--") ? args.slice(args.indexOf("--") + 1) : [];
const projectAt = args.indexOf("--project");
const project = projectAt >= 0 ? args[projectAt + 1] : "default";
const name = args[0] === "exec" ? args.filter((_, i) => i > 0 && i !== projectAt && i !== projectAt + 1)[0] : undefined;
const marked = () => {
    try {
        return Deno.statSync(`${folder}/opt/orbit-agent/.restart`).isFile;
    } catch {
        return false;
    }
};
const own = args[0] === "exec" && !args.includes("--user") && command[0] === "systemctl" && (
    (command[1] === "show" && command.includes("NeedDaemonReload")) || command[1] === "daemon-reload"
    || (command[1] === "try-restart" && command[2] === "t3.service" && !marked()));

if (!own) {
    const shared = new Deno.Command(Deno.execPath(), {
        args: ["run", "-A", new URL("task-vm-host-incus.ts", import.meta.url).pathname, ...Deno.args],
        stdin: "inherit", stdout: "inherit", stderr: "inherit",
    }).spawn();
    Deno.exit((await shared.status).code);
}

const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(Deno.args) + "\n", { append: true });
if (command[1] === "show") {
    console.log((state.need_reload ?? []).includes(name) ? "yes" : "no");
} else if (command[1] === "daemon-reload") {
    state.need_reload = (state.need_reload ?? []).filter((held: string) => held !== name);
} else {
    state.folder_restarts ??= [];
    state.folder_restarts.push({ project, name, unit: "t3.service", marked: false });
}
Deno.writeTextFileSync(file, JSON.stringify(state));
