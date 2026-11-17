/**
 * The Incus fake of the T3 drop-in converge cases (docs/reference/development-containers.md#t3s-unit-drop-ins): it answers the systemd
 * calls of the drop-in step itself and hands every other call, with its argv, stdin and environment, to the shared fake
 * (task-vm-host-incus.ts), which stays as every other case uses it.
 *
 * - `systemctl show -p NeedDaemonReload --value t3.service` prints `yes` for a container in the state's `need_reload`.
 * - `systemctl daemon-reload` takes the container out of `need_reload`.
 * - `systemctl is-active t3.service` prints `inactive` (exit 3) for a container in `t3_inactive`, else `active`.
 * - The agent's thread list (`runuser -u agent ... node - PROGRAM`) prints one `running` thread for a container in `t3_busy`, fails
 *   for one in `t3_list_fail`, and prints no thread otherwise. Both checks go to the state's `t3_checks`, not to `calls`.
 *   A container with a home folder `homes/NAME` runs the real script from stdin instead, with Deno, that home as HOME (and its
 *   server's store, which the case writes), and `homes/NAME/bin/` + PROGRAM's base name as the program.
 * - `systemctl try-restart t3.service` without the agent folder's `.restart` mark, the restart for a new drop-in alone, is recorded in
 *   `folder_restarts` as the shared fake records the folder's own restarts. A name in `fail_restarts` refuses the call instead.
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
    || (command[1] === "try-restart" && command[2] === "t3.service" && !marked()) || (command[1] === "is-active" && command[2] === "t3.service"))
    || (args[0] === "exec" && command[0] === "/usr/sbin/runuser" && command.includes("node"));

if (!own) {
    const shared = new Deno.Command(Deno.execPath(), {
        args: ["run", "-A", new URL("task-vm-host-incus.ts", import.meta.url).pathname, ...Deno.args],
        stdin: "inherit", stdout: "inherit", stderr: "inherit",
    }).spawn();
    Deno.exit((await shared.status).code);
}

const file = `${folder}/state.json`;
const state = JSON.parse(Deno.readTextFileSync(file));
if (command[0] === "/usr/sbin/runuser" || command[1] === "is-active") {
    state.t3_checks ??= [];
    state.t3_checks.push({ name, check: command[1] === "is-active" ? "is-active" : "list", program: command.at(-1) });
    Deno.writeTextFileSync(file, JSON.stringify(state));
    if (command[1] === "is-active") {
        const inactive = (state.t3_inactive ?? []).includes(name);
        console.log(inactive ? "inactive" : "active");
        Deno.exit(inactive ? 3 : 0);
    }
    const home = `${folder}/homes/${name}`;
    if ((() => { try { return Deno.statSync(home).isDirectory; } catch { return false; } })()) {
        Deno.writeTextFileSync(`${home}/threads.cjs`, await new Response(Deno.stdin.readable).text());
        const listed = new Deno.Command(Deno.execPath(), {
            args: ["run", "-A", `${home}/threads.cjs`, command.at(-2)!, `${home}/bin/${command.at(-1)!.split("/").at(-1)}`],
            env: { HOME: home }, stdout: "inherit", stderr: "inherit",
        }).spawn();
        Deno.exit((await listed.status).code);
    }
    if ((state.t3_list_fail ?? []).includes(name)) {
        console.error("injected T3 list failure");
        Deno.exit(1);
    }
    console.log(JSON.stringify((state.t3_busy ?? []).includes(name) ? [{ threadId: `thread-${name}`, status: "running" }] : []));
    Deno.exit(0);
}
Deno.writeTextFileSync(`${folder}/calls`, JSON.stringify(Deno.args) + "\n", { append: true });
if (command[1] === "show") {
    console.log((state.need_reload ?? []).includes(name) ? "yes" : "no");
} else if (command[1] === "daemon-reload") {
    state.need_reload = (state.need_reload ?? []).filter((held: string) => held !== name);
} else {
    if ((state.fail_restarts ?? []).includes(name)) {
        console.error("injected T3 restart failure");
        Deno.exit(1);
    }
    state.folder_restarts ??= [];
    state.folder_restarts.push({ project, name, unit: "t3.service", marked: false });
}
Deno.writeTextFileSync(file, JSON.stringify(state));
