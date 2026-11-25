/** The Conn Server rollout (docs/reference/development-containers.md#conn-server-rollout): the shipped workflow's steps, and its one
 * phase run by real Ansible against fixture Nodes: a Mac bundle with stand-ins for codesign, ditto, launchctl and osascript, a Linux
 * release folder with a stand-in systemctl, and one /health server that answers with the version the fixture's job runs. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { encodeHex } from "jsr:@std/encoding@1/hex";

type Step = { name: string; dependsOn?: { step: string; condition: { type: string } }[]; task: { inputs: { run?: string } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-fleet-conn-server-rollout.yaml", import.meta.url))) as {
    inputs: { properties: { intent: { required: string[] } } };
    jobs: { name: string; steps: Step[] }[];
};
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const playbook = Deno.env.get("ANSIBLE_PLAYBOOK") ?? "ansible-playbook";
const available = new Deno.Command("sh", { args: ["-c", `command -v ${playbook}`] }).outputSync().success;
const user = new TextDecoder().decode(new Deno.Command("id", { args: ["-un"] }).outputSync().stdout).trim();

Deno.test("the rollout runs one tagged phase of the shipped playbook between the input and the result, and takes no secret", () => {
    const steps = workflow.jobs.flatMap((job) => job.steps);
    assertEquals(steps.map((step) => step.name), ["input", "Roll out", "result", "cleanup"]);
    assertEquals(steps[2].dependsOn, [{ step: "Roll out", condition: { type: "always" } }]);
    assertEquals(workflow.inputs.properties.intent.required, ["dry_run", "notice", "nodes"]);
    assertEquals(steps[1].task.inputs.run, 'timeout 13220 /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags rollout "$ORBIT_ANSIBLE/conn-server-rollout.yml"');
    assert(!JSON.stringify(workflow).includes("vault.get"));
});

type Task = { name: string; timeout?: number; delegate_to?: string; block?: Task[]; rescue?: Task[] } & Record<string, unknown>;
/** The tasks of a task file, those in its blocks and rescues too. */
const tasks = (file: string): Task[] => {
    const flat = (task: Task): Task[] => [task, ...(task.block ?? []).flatMap(flat), ...(task.rescue ?? []).flatMap(flat)];
    return (parse(Deno.readTextFileSync(`${shipped}/tasks/${file}`)) as Task[]).flatMap(flat);
};
const macHealthFile = "conn-server-mac-health.yml";
const macFiles = ["conn-server-mac.yml", "conn-server-mac-start.yml", "conn-server-mac-round.yml", macHealthFile, "conn-server-notice.yml"];
/** The task timeout of the named task in a task file. */
const timeout = (file: string, name: string) => {
    const task = tasks(file).find((task) => task.name === name);
    assert(task?.timeout, `${file}: ${name}`);
    return task.timeout;
};

// Over SSH, a module on mini took 10 s from the start of its python3 to its own code (run 33436), which tripped a 10 s step.
Deno.test("every module that a Mac Node runs has a task timeout of at least 60 s", () => {
    const controller = ["ansible.builtin.set_fact", "ansible.builtin.assert", "ansible.builtin.debug", "ansible.builtin.include_tasks", "ansible.builtin.meta"];
    const modules = macFiles.flatMap((file) => tasks(file).filter((task) => !task.block && task.delegate_to !== "localhost"
        && !Object.keys(task).some((key) => controller.includes(key))).map((task) => ({ file, name: task.name, timeout: task.timeout })));
    assert(modules.length >= 20, `${modules.length}`);
    assertEquals(modules.filter((task) => !(task.timeout! >= 60)), []);
});

Deno.test("the phase's deadline covers every Node's bounded worst case, so a run-level timeout never cuts a rollback", () => {
    const plays = parse(Deno.readTextFileSync(`${shipped}/conn-server-rollout.yml`)) as { vars: Record<string, number> }[];
    const { max_nodes, node_seconds } = plays[0].vars;
    const { health_seconds, health_rounds, health_delay } = plays[1].vars;
    const mac = (...names: string[]) => names.reduce((sum, name) => sum + timeout("conn-server-mac.yml", name), 0);
    // A Mac start: the kickstart and the health wait, which starts no round after health_seconds; a round takes up to the sum of
    // its task timeouts.
    const macHealth = timeout(macHealthFile, "Read the health");
    const round = tasks("conn-server-mac-round.yml").reduce((sum, task) => sum + (task.timeout ?? 0), macHealth);
    const macStart = timeout("conn-server-mac-start.yml", "Kickstart the LaunchAgent in the gui domain") + health_seconds + round;
    // The checks before the download and after it; the designated requirements are read for two bundles, then the running version.
    const macChecks = mac("Read the home folder and the uid", "Check that the Conn Server is installed", "Read the loaded LaunchAgent", "Make the staging folder",
        "Read the zip's SHA-256", "Unpack the zip", "Verify the staged signature", "Read the staged signer")
        + 2 * mac("Read the designated requirements") + macHealth;
    // The swap's other steps: the notice from the worker, two renames, the rollback's two reads and two renames.
    const notice = timeout("conn-server-notice.yml", "Post the notice") + timeout("conn-server-notice.yml", "Run the notice command");
    const macSwap = mac("Make the backup folder", "Move the installed bundle into the backup", "Put the new bundle on the same path",
        "Set the new bundle aside", "Restore the backup on the same path") + notice + 2 * mac("Read the backup and what holds the bundle's path");
    // A Linux start: a 40 s relink, a 100 s restart and the health wait: `retries: health_rounds` reads after the first, so
    // health_rounds + 1 reads of up to 5 s, health_delay apart.
    const linuxStart = 40 + 100 + (health_rounds + 1) * 5 + health_rounds * health_delay;
    // A download may take 900 s, so a slow link (mini's tunnel, about 35 KB/s: 400 s for the zip) gets the asset; get_url's own
    // timeout stays a 60 s limit per read.
    const download = (file: string, name: string) => {
        const task = (parse(Deno.readTextFileSync(`${shipped}/tasks/${file}`)) as
            { name: string; timeout: number; "ansible.builtin.get_url": { timeout: number } }[]).find((task) => task.name === name)!;
        assertEquals([task.timeout, task["ansible.builtin.get_url"].timeout], [900, 60]);
        return task.timeout;
    };
    // The checks (the download and the rest), then the swap's other task timeouts and two starts: the new server's and the rollback's.
    const worst = Math.max(download("conn-server-mac.yml", "Download the zip") + macChecks + macSwap + 2 * macStart,
        download("conn-server-linux.yml", "Download the tarball") + 200 + 220 + 2 * linuxStart);
    // node_seconds adds 160 s for the SSH set-up and the tasks without a timeout.
    assert(node_seconds >= worst + 160, `${node_seconds} < ${worst} + 160`);
    const deadline = Number(/^timeout (\d+) /.exec(workflow.jobs[0].steps[1].task.inputs.run!)![1]);
    assert(deadline >= max_nodes * node_seconds + 100, `${deadline}`);
    // The Gateway's run budget covers the deadline, the 30 s queue wait and 270 s for the input, the result and the cleanup.
    const table = Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Operations/OperationBudgetTable.php", import.meta.url));
    const budget = Number(/'fleet\.conn_server_rollout' => (\d+),/.exec(table)![1]);
    assert(budget >= deadline + 300, `${budget}`);
});

const shims: Record<string, string> = {
    // The fixture signature of a bundle is Contents/sig: `valid|invalid team id requirement`.
    codesign: `#!/bin/sh
for app; do :; done
read -r valid team id dr < "$app/Contents/sig"
case "$1" in
  --verify) [ "$valid" = valid ] ;;
  -d) if [ "$2" = -r ]; then echo "Executable=$app"; echo "designated => $dr"; else printf 'Identifier=%s\\nTeamIdentifier=%s\\n' "$id" "$team" >&2; fi ;;
esac`,
    ditto: `#!/bin/sh\nmkdir -p "$4" && tar -xf "$3" -C "$4"`,
    // The job runs the version of the bundle on the path; a version listed in $FIXTURE/broken never answers. With $FIXTURE/stale the
    // old server keeps answering. $FIXTURE/prompt-on-start is the TCC prompt that the first start raises. A version listed in
    // $FIXTURE/exit-<command> makes that command exit 1: kickstart and restart with the server down, the click with the prompt up.
    launchctl: `#!/bin/sh
target="gui/$(id -u)/com.nckrtl.conn-server"
if [ "$1" = print ]; then
  echo "$*" >> "$FIXTURE/launchctl-print"
  echo fixture-private-launchagent-environment
  [ "$2" = "$target" ]
  exit $?
fi
echo "$*" >> "$FIXTURE/launchctl"
[ "$3" = "$target" ] || exit 113
[ -f "$FIXTURE/prompt-on-start" ] && mv "$FIXTURE/prompt-on-start" "$FIXTURE/prompt"
v=$(cat "$FIXTURE_APP/Contents/version")
if grep -qx "$v" "$FIXTURE/exit-launchctl" 2>/dev/null; then echo down > "$FIXTURE/running"; exit 1; fi
[ -f "$FIXTURE/stale" ] && exit 0
if grep -qx "$v" "$FIXTURE/broken" 2>/dev/null; then echo down > "$FIXTURE/running"; else echo "$v" > "$FIXTURE/running"; fi`,
    osascript: `#!/bin/sh
case "$2" in
  *click*) grep -qx "$(cat "$FIXTURE_APP/Contents/version")" "$FIXTURE/exit-osascript" 2>/dev/null && exit 1; echo click >> "$FIXTURE/clicks"; rm -f "$FIXTURE/prompt" ;;
  *) [ -f "$FIXTURE/prompt" ] && cat "$FIXTURE/prompt" ;;
esac
exit 0`,
    // With $FIXTURE/exit-mv-backup, the move of the installed bundle into the backup renames it and then exits 1, as a move that
    // completes but whose task then fails or times out.
    mv: `#!/bin/sh
PATH=\${PATH#*:} mv "$@" || exit
for last; do :; done
case "$last" in *backup*/"Conn Server.app") [ -f "$FIXTURE/exit-mv-backup" ] && exit 1 ;; esac
exit 0`,
    // The workflow's ssh, for Ansible's ssh connection plugin: like real ssh it refuses a host that the run's UserKnownHostsFile does
    // not pin, so only the Nodes are reached, and it runs each command on this machine.
    // With $FIXTURE/slow-starts holding n, the next n modules wait 15 s before they start, as a module on mini took 10 s over SSH.
    "ssh-node": `#!/bin/sh
for a; do case "$a" in UserKnownHostsFile=*) known=\${a#UserKnownHostsFile=} ;; esac; done
eval "host=\\\${$(($# - 1))}" && eval "command=\\\${$#}"
grep -q "^$host " "$known" 2>/dev/null || { echo "Host key verification failed." >&2; exit 255; }
case "$command" in *python*) n=$(cat "$FIXTURE/slow-starts" 2>/dev/null || echo 0)
  if [ "$n" -gt 0 ]; then echo $((n - 1)) > "$FIXTURE/slow-starts"; sleep 15; fi ;; esac
exec /bin/sh -c "$command"`,
    // `show` prints the unit's ExecStart as systemd does; the program is $FIXTURE_EXEC and its arguments follow `serve`.
    systemctl: `#!/bin/sh
if [ "$1" = show ]; then echo "{ path=$FIXTURE_EXEC ; argv[]=$FIXTURE_EXEC serve $FIXTURE_ARGS ; ignore_errors=no ; start_time=[n/a] ; stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }"; exit 0; fi
if [ "$1" = is-enabled ]; then
  if [ -f "$FIXTURE/disabled" ]; then echo disabled; exit 1; else echo enabled; exit 0; fi
fi
if [ "$1" = is-active ]; then
  if [ "$(cat "$FIXTURE/running")" = down ]; then echo inactive; exit 3; else echo active; exit 0; fi
fi
echo "$*" >> "$FIXTURE/systemctl"
case "$1" in
  disable) touch "$FIXTURE/disabled"; exit 0 ;;
  stop) echo down > "$FIXTURE/running"; [ ! -f "$FIXTURE/exit-stop" ]; exit $? ;;
  enable) rm -f "$FIXTURE/disabled"; exit 0 ;;
esac
v=$(cat "$FIXTURE_LINUX/current/version")
if grep -qx "$v" "$FIXTURE/exit-systemctl" 2>/dev/null; then echo down > "$FIXTURE/running"; exit 1; fi
if grep -qx "$v" "$FIXTURE/broken" 2>/dev/null; then echo down > "$FIXTURE/running"; else echo "$v" > "$FIXTURE/running"; fi`,
};

type Server = { platform: "mac" | "linux"; canary?: boolean; notify?: boolean; service?: string; managed?: boolean };
type Options = { nodes: Server[]; sha?: string; team?: string; dr?: string; valid?: boolean; broken?: string[]; prompt?: string; promptOnStart?: string; stale?: boolean; exec?: string; topFolder?: string; dryRun?: boolean; notice?: boolean; exitOn?: "launchctl" | "osascript" | "systemctl" | "mv-backup"; name?: string;
    // `args` are the Linux unit's arguments after `serve`; `listen` is the one address the /health server listens on.
    args?: string; listen?: string;
    // `down` starts with the running server answering 503 and no version.
    down?: boolean;
    // Override the direct health response before any restart, after the swap, or after rollback.
    health?: (running: string, restarts: number) => Response | undefined;
    proxy?: boolean;
    // The Linux layout: `first` keeps the old release outside a `releases` folder, which does not exist yet, as on a Node set up before
    // this rollout; `taken` already holds this run's release folder; `blocked` has a file where the `releases` folder goes.
    releases?: "first" | "taken" | "blocked"; noticeUrl?: (port: number) => string; noticeCommand?: string[]; ssh?: boolean;
    // `slowStarts` modules over ssh each take 15 s to start.
    slowStarts?: number; verbose?: boolean; withdraw?: boolean; failStop?: boolean; resumeWithdrawal?: "stopped" | "unlinked" };

const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";
/** The names in a folder, null when it does not exist, and "file" when a file stands in its place. */
const listed = (path: string) => {
    try { return [...Deno.readDirSync(path)].map((entry) => entry.name).sort(); } catch (error) {
        if (error instanceof Deno.errors.NotFound) return null;
        if (Deno.statSync(path).isFile) return "file";
        throw error;
    }
};

async function rollout(options: Options) {
    const folder = Deno.makeTempDirSync();
    try {
        const old = options.releases === "first" || options.releases === "blocked" ? "linux/0.4.2" : "linux/releases/0.4.2";
        for (const dir of ["bin", "run", "home/Applications", "build/Conn Server.app/Contents", old, `linux-build/${options.topFolder ?? "conn-server-0.5.0-linux-x64"}`]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.chmodSync(`${folder}/linux`, 0o755);
        for (const [name, text] of Object.entries(shims)) {
            Deno.writeTextFileSync(`${folder}/bin/${name}`, text);
            Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
        }
        const app = `${folder}/home/Applications/Conn Server.app`;
        Deno.mkdirSync(`${app}/Contents`, { recursive: true });
        Deno.writeTextFileSync(`${app}/Contents/Info.plist`, "<plist/>");
        Deno.writeTextFileSync(`${app}/Contents/version`, "0.4.2");
        Deno.writeTextFileSync(`${app}/Contents/sig`, "valid 9SVJ4SYB9B com.nckrtl.conn-server anchor-team-9SVJ4SYB9B\n");
        const build = `${folder}/build/Conn Server.app/Contents`;
        Deno.writeTextFileSync(`${build}/Info.plist`, "<plist/>");
        Deno.writeTextFileSync(`${build}/version`, "0.5.0");
        Deno.writeTextFileSync(`${build}/sig`, `${options.valid === false ? "invalid" : "valid"} ${options.team ?? "9SVJ4SYB9B"} com.nckrtl.conn-server ${options.dr ?? "anchor-team-9SVJ4SYB9B"}\n`);
        new Deno.Command("tar", { args: ["-cf", `${folder}/conn-server.zip`, "-C", `${folder}/build`, "Conn Server.app"] }).outputSync();
        Deno.writeTextFileSync(`${folder}/${old}/version`, "0.4.2");
        Deno.symlinkSync(`${folder}/${old}`, `${folder}/linux/current`);
        if (options.releases === "taken") Deno.mkdirSync(`${folder}/linux/releases/0.5.0-${runId}`);
        if (options.releases === "blocked") Deno.writeTextFileSync(`${folder}/linux/releases`, "");
        // The release tarball holds one top folder, which the unpack strips.
        const top = options.topFolder ?? "conn-server-0.5.0-linux-x64";
        Deno.writeTextFileSync(`${folder}/linux-build/${top}/version`, "0.5.0");
        Deno.writeTextFileSync(`${folder}/linux-build/${top}/conn-server`, "");
        new Deno.Command("tar", { args: ["-czf", `${folder}/conn-server.tar.gz`, "-C", `${folder}/linux-build`, top] }).outputSync();
        Deno.writeTextFileSync(`${folder}/running`, options.down || options.resumeWithdrawal ? "down" : "0.4.2");
        Deno.writeTextFileSync(`${folder}/broken`, (options.broken ?? []).map((v) => `${v}\n`).join(""));
        if (options.prompt) Deno.writeTextFileSync(`${folder}/prompt`, options.prompt);
        if (options.promptOnStart) Deno.writeTextFileSync(`${folder}/prompt-on-start`, options.promptOnStart);
        if (options.stale) Deno.writeTextFileSync(`${folder}/stale`, "");
        if (options.slowStarts) Deno.writeTextFileSync(`${folder}/slow-starts`, `${options.slowStarts}`);
        if (options.exitOn) Deno.writeTextFileSync(`${folder}/exit-${options.exitOn}`, "0.5.0\n");
        if (options.failStop) Deno.writeTextFileSync(`${folder}/exit-stop`, "");
        const sha = async (path: string) => encodeHex(await crypto.subtle.digest("SHA-256", Deno.readFileSync(path)));
        const noticed: unknown[] = [];
        const read = (name: string) => { try { return Deno.readTextFileSync(`${folder}/${name}`).trim().split("\n").filter(Boolean); } catch { return []; } };
        const server = Deno.serve({ port: 0, hostname: options.listen ?? "127.0.0.1", onListen() {} }, async (request) => {
            if (request.method === "POST") {
                if (new URL(request.url).pathname !== "/notice") return new Response(null, { status: 500 });
                noticed.push(await request.json());
                return new Response(null, { status: 204 });
            }
            const running = Deno.readTextFileSync(`${folder}/running`).trim();
            if (options.health && new URL(request.url).pathname === "/health") {
                const response = options.health(running, read("launchctl").length);
                if (response) return response;
            }
            return running === "down" ? new Response("down", { status: 503 }) : Response.json({ server: options.name ?? "conn-server", version: running });
        });
        const port = server.addr.port;
        if (options.resumeWithdrawal) {
            Deno.writeTextFileSync(`${folder}/disabled`, "");
            const path = `${folder}/linux/current`;
            if (options.resumeWithdrawal === "unlinked") Deno.removeSync(path);
            Deno.writeTextFileSync(`${folder}/linux/.orbit-conn-withdrawal.json`, JSON.stringify({
                identity: { path, service: "conn-server", health_url: `http://127.0.0.1:${port}/health` },
                target: `${folder}/${old}`, version: "0.4.2", enablement: "enabled", completed: false,
            }), { mode: 0o600 });
        }
        Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
            dry_run: options.dryRun ?? false, ...(options.withdraw ? { withdraw: true } : {}),
            ...(!options.withdraw || options.sha ? {
                version: "0.5.0",
                mac: { url: `file://${folder}/conn-server.zip`, sha256: options.sha ?? await sha(`${folder}/conn-server.zip`), bundle_id: "com.nckrtl.conn-server", team_id: "9SVJ4SYB9B" },
                linux: { url: `file://${folder}/conn-server.tar.gz`, sha256: options.sha ?? await sha(`${folder}/conn-server.tar.gz`) },
            } : {}),
            notice: options.noticeCommand ? { url: null, command: options.noticeCommand }
                : options.notice ? { url: options.noticeUrl?.(port) ?? `http://127.0.0.1:${port}/notice`, command: null } : { url: null, command: null },
            nodes: options.nodes.map((node, index) => ({
                node_id: 45 + index,
                ...(node.managed ? { tool_id: 11 } : {}),
                ssh: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: user, host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
                server: node.platform === "mac"
                    ? { platform: "mac", path: "~/Applications/Conn Server.app", service: node.service ?? "com.nckrtl.conn-server", port, user, canary: node.canary ?? false, notify_before_restart: node.notify ?? false }
                    : { platform: "linux", path: `${folder}/linux/current`, service: "conn-server", port, user, canary: node.canary ?? false, notify_before_restart: node.notify ?? false },
            })),
        }));
        let proxyRequests = 0;
        const proxy = options.proxy ? Deno.serve({ port: 0, hostname: "127.0.0.1", onListen() {} }, () => {
            proxyRequests++;
            return new Response(null, { status: 502 });
        }) : undefined;
        const done = await new Deno.Command(playbook, {
            // With `ssh`, as the workflow runs it: no connection override, so the Nodes and localhost take the ssh connection plugin.
            args: ["-i", "localhost,", "--tags", "rollout", ...(options.verbose ? ["-v"] : []), ...(options.ssh ? [] : ["-e", "ansible_connection=local"]), "-e", "ansible_become=false", "-e", `linux_releases_owner=${user}`,
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", "health_rounds=3", "-e", "health_delay=0", `${shipped}/conn-server-rollout.yml`],
            env: { HOME: `${folder}/home`, FIXTURE: folder, FIXTURE_APP: app, FIXTURE_LINUX: `${folder}/linux`, FIXTURE_EXEC: options.exec ?? `${folder}/linux/current/conn-server`, FIXTURE_ARGS: options.args ?? "", ORBIT_RUN: `${folder}/run`, ORBIT_RUN_ID: runId,
                PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg`,
                ...(proxy ? { http_proxy: `http://127.0.0.1:${proxy.addr.port}`, no_proxy: "", NO_PROXY: "" } : {}),
                ...(options.ssh ? { ANSIBLE_SSH_EXECUTABLE: `${folder}/bin/ssh-node`, ANSIBLE_SSH_TRANSFER_METHOD: "piped",
                    ANSIBLE_SSH_ARGS: `-o ControlMaster=no -o IdentitiesOnly=yes -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile=${folder}/run/known_hosts` } : {}) },
        }).output();
        await Promise.all([server.shutdown(), proxy?.shutdown()]);
        const output = new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr);
        let result = null;
        try { result = JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)); } catch { /* no result: the phase stopped first */ }
        return {
            ok: done.success,
            output,
            result,
            installed: Deno.readTextFileSync(`${app}/Contents/version`),
            linux: read("linux/current/version")[0] ?? null,
            releases: listed(`${folder}/linux/releases`),
            launchctl: read("launchctl"), launchctlPrint: read("launchctl-print"), systemctl: read("systemctl"), clicks: read("clicks"), noticed, promptLeft: read("prompt").length > 0,
            running: Deno.readTextFileSync(`${folder}/running`).trim(),
            proxyRequests,
            backups: [...Deno.readDirSync(`${folder}/home/Applications`)].map((entry) => entry.name).filter((name) => name.includes("backup")),
        };
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
}

const test = (name: string, fn: () => Promise<void>) => Deno.test({ name, ignore: !available, fn });

test("a Mac canary checks, swaps the bundle on the same path, kickstarts in the gui domain and answers /health with the version", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }] });
    assert(run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "updated", previous_version: "0.4.2", prompts: [] }]);
    assertEquals(run.installed, "0.5.0");
    assertEquals(run.launchctl.length, 1);
    assertEquals(run.launchctlPrint, [run.launchctl[0].replace("kickstart -k ", "print ")]);
    assert(/^kickstart -k gui\/\d+\/com\.nckrtl\.conn-server$/.test(run.launchctl[0]), run.launchctl[0]);
    assertEquals(run.backups, []);
});

test("a Linux withdrawal checks without stopping, then stops and unlinks or restores the old service after a failed stop", async () => {
    const input: Options = { nodes: [{ platform: "linux", canary: true, notify: true }], withdraw: true, notice: true };
    const dry = await rollout({ ...input, dryRun: true });
    assert(dry.ok, dry.output);
    assertEquals([dry.result.nodes[0].outcome, dry.linux, dry.systemctl, dry.noticed], ["checked", "0.4.2", [], []]);
    const applied = await rollout({ ...input, sha: "0".repeat(64) });
    assert(applied.ok, applied.output);
    assertEquals(applied.result.nodes, [{ node_id: 45, outcome: "withdrawn", previous_version: "0.4.2" }]);
    assertEquals([applied.linux, applied.running, applied.releases], [null, "down", ["0.4.2"]]);
    assertEquals(applied.noticed, [{ node_id: 45, version: "0.4.2", event: "conn_server.withdrawal" }]);
    assertEquals(applied.systemctl.map((call) => call.split(" ")[0]), ["disable", "stop"]);
    const failed = await rollout({ ...input, failStop: true });
    assert(!failed.ok, failed.output);
    assertEquals(failed.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.withdraw_failed", rolled_back: true }]);
    assertEquals([failed.linux, failed.running], ["0.4.2", "0.4.2"]);
    assertEquals(failed.systemctl.map((call) => call.split(" ")[0]), ["disable", "stop", "restart", "enable"]);
});

test("Linux withdrawal resumes after the stop or unlink without restarting or sending the notice again", async () => {
    for (const resumeWithdrawal of ["stopped", "unlinked"] as const) {
        const run = await rollout({ nodes: [{ platform: "linux", notify: true }], withdraw: true, notice: true, resumeWithdrawal });
        assert(run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "withdrawn", previous_version: "0.4.2" }]);
        assertEquals([run.linux, run.running, run.releases, run.systemctl, run.noticed], [null, "down", ["0.4.2"], [], []]);
    }
});

test("managed Conn dry runs report observed health rather than the selected replacement", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", managed: true, canary: true }, { platform: "linux", managed: true }], dryRun: true });
    assert(run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "checked", observed_version: "0.4.2" }, { node_id: 46, outcome: "checked", observed_version: "0.4.2" }]);
    assertEquals([run.launchctl, run.systemctl], [[], []]);
});

test("a Mac Node with the wrong LaunchAgent label fails before staging, in a dry run too", async () => {
    const nodes: Server[] = [{ platform: "mac", canary: true, notify: true, service: "com.nckrtl.t3-headless" }];
    for (const dryRun of [true, false]) {
        const run = await rollout({ nodes, dryRun, notice: true, verbose: true });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.service_not_loaded", rolled_back: false }]);
        assertEquals(run.launchctlPrint.length, 1);
        assert(/^print gui\/\d+\/com\.nckrtl\.t3-headless$/.test(run.launchctlPrint[0]), run.launchctlPrint[0]);
        assertEquals([run.installed, run.running, run.launchctl, run.backups, run.noticed], ["0.4.2", "0.4.2", [], [], []]);
        assert(!run.output.includes("Make the staging folder"), run.output);
        assert(!run.output.includes("fixture-private-launchagent-environment"), run.output);
    }
});

test("a zip whose SHA-256 differs fails the Node and installs nothing", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "linux" }], sha: "0".repeat(64) });
    assert(!run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.hash_mismatch", rolled_back: false }, { node_id: 46, outcome: "not_reached" }]);
    assertEquals([run.installed, run.launchctl], ["0.4.2", []]);
});

test("a bundle signed by another team, or with an invalid signature, fails the Node and installs nothing", async () => {
    for (const [options, reason] of [[{ team: "AAAAAAAAAA" }, "conn_server.team_mismatch"], [{ valid: false }, "conn_server.signature_invalid"]] as const) {
        const run = await rollout({ nodes: [{ platform: "mac", canary: true }], ...options });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes[0].reason, reason);
        assertEquals([run.installed, run.launchctl], ["0.4.2", []]);
    }
});

test("a changed designated requirement holds the canary, installs nothing and stops the run", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "linux" }], dr: "anchor-team-OTHER" });
    assert(!run.ok, run.output);
    assertEquals(run.result.error_code, "conn_server.canary_held");
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "held", reason: "designated_requirement_changed" }, { node_id: 46, outcome: "not_reached" }]);
    assertEquals([run.installed, run.linux, run.launchctl], ["0.4.2", "0.4.2", []]);
});

test("a failed health check restores the backup, kickstarts the old server, fails the canary and stops the run", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "linux" }], broken: ["0.5.0"] });
    assert(!run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: true }, { node_id: 46, outcome: "not_reached" }]);
    assertEquals([run.installed, run.linux, run.launchctl.length], ["0.4.2", "0.4.2", 2]);
    assertEquals(run.backups.length, 1);
});

const removable = '"Conn Server" would like to access files on a removable volume., Don’t Allow, Allow';

test("a same-identity swap answers the TCC prompt its start raises, leaves none unanswered, and /health names the version", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }], promptOnStart: removable });
    assert(run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "updated", previous_version: "0.4.2", prompts: [removable] }]);
    assertEquals([run.installed, run.clicks, run.promptLeft, run.launchctl.length], ["0.5.0", ["click"], false, 1]);
});

test("a server that still answers the old version fails the /health version check and is rolled back", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }], stale: true });
    assert(!run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: true }]);
    assertEquals([run.installed, run.launchctl.length, run.backups.length], ["0.4.2", 2, 1]);
});

test("the expected Conn Server prompt is allowed; any other prompt fails the Node and rolls back", async () => {
    const allowed = await rollout({ nodes: [{ platform: "mac", canary: true }], prompt: '"Conn Server" would like to access files on a removable volume., Don’t Allow, Allow' });
    assert(allowed.ok, allowed.output);
    assertEquals([allowed.installed, allowed.clicks], ["0.5.0", ["click"]]);
    const other = await rollout({ nodes: [{ platform: "mac", canary: true }], prompt: '"Terminal" would like to access your contacts.' });
    assert(!other.ok, other.output);
    // The prompt stays up, so the restored bundle cannot answer either: the Node reports that it is not rolled back.
    assertEquals(other.result.nodes[0], { node_id: 45, outcome: "failed", reason: "conn_server.unexpected_prompt", rolled_back: false });
    assertEquals([other.installed, other.clicks], ["0.4.2", []]);
});

test("a Linux Node whose new release fails its health check points current back at the old release", async () => {
    const run = await rollout({ nodes: [{ platform: "linux", canary: true }], broken: ["0.5.0"] });
    assert(!run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: true }]);
    assertEquals(run.linux, "0.4.2");
});

test("a kickstart, Allow click or systemctl restart that exits non-zero restores the old version, which runs again", async () => {
    for (const [options, launches] of [[{ exitOn: "launchctl" }, 2], [{ exitOn: "osascript", promptOnStart: removable }, 2]] as const) {
        const run = await rollout({ nodes: [{ platform: "mac", canary: true }], ...options });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.step_failed", rolled_back: true }]);
        assertEquals([run.installed, run.running, run.launchctl.length], ["0.4.2", "0.4.2", launches]);
    }
    const linux = await rollout({ nodes: [{ platform: "linux", canary: true }], exitOn: "systemctl" });
    assert(!linux.ok, linux.output);
    assertEquals(linux.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.step_failed", rolled_back: true }]);
    assertEquals([linux.linux, linux.running], ["0.4.2", "0.4.2"]);
});

test("a Linux Node whose /health names another server is not updated, as on a Mac", async () => {
    for (const platform of ["mac", "linux"] as const) {
        const run = await rollout({ nodes: [{ platform, canary: true }], name: "t3" });
        assert(!run.ok, run.output);
        // Each platform reads the running server before any change, so it fails in its checks.
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_unreachable", rolled_back: false }]);
        assertEquals([run.installed, run.linux, run.launchctl, run.systemctl], ["0.4.2", "0.4.2", [], []]);
    }
});

test("a Linux Node whose unit or tarball differs from the assumed layout fails before any change, in a dry run too", async () => {
    for (const options of [{ exec: "/opt/conn-server/bin/conn-server" }, { topFolder: "conn-server" }]) {
        for (const dryRun of [true, false]) {
            const run = await rollout({ nodes: [{ platform: "linux", canary: true }], dryRun, ...options });
            assert(!run.ok, run.output);
            assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.layout_mismatch", rolled_back: false }]);
            assertEquals([run.linux, run.releases], ["0.4.2", ["0.4.2"]]);
        }
    }
});

test("after the canary, the Linux Node moves current to a new release under systemd; a dry run only checks", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "linux" }] });
    assert(run.ok, run.output);
    assertEquals(run.result.nodes.map((node: { outcome: string }) => node.outcome), ["updated", "updated"]);
    assertEquals(run.linux, "0.5.0");
    const dry = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "linux" }], dryRun: true });
    assert(dry.ok, dry.output);
    assertEquals(dry.result.nodes, [{ node_id: 45, outcome: "checked" }, { node_id: 46, outcome: "checked" }]);
    assertEquals([dry.installed, dry.linux, dry.launchctl], ["0.4.2", "0.4.2", []]);
});

// 127.0.0.2 is a loopback alias that a read on 127.0.0.1 misses.
test("a Linux Node reads /health on its unit's --host, in the check, the start and the rollback", async () => {
    for (const args of ["--host=127.0.0.2 --port=3773 --no-browser", "--host 127.0.0.2 --port=3773"]) {
        const run = await rollout({ nodes: [{ platform: "linux", canary: true }], args, listen: "127.0.0.2" });
        assert(run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "updated", previous_version: "0.4.2" }]);
        assertEquals(run.linux, "0.5.0");
    }
    const broken = await rollout({ nodes: [{ platform: "linux", canary: true }], args: "--host=127.0.0.2", listen: "127.0.0.2", broken: ["0.5.0"] });
    assertEquals(broken.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: true }]);
    assertEquals([broken.linux, broken.running], ["0.4.2", "0.4.2"]);
});

test("a Linux unit without --host, or with a wildcard one, reads /health on 127.0.0.1", async () => {
    for (const args of ["--port=3773 --no-browser", "--host=0.0.0.0", "--host ::"]) {
        const run = await rollout({ nodes: [{ platform: "linux", canary: true }], args });
        assert(run.ok, run.output);
        assertEquals([run.result.nodes[0].outcome, run.linux], ["updated", "0.5.0"]);
    }
});

test("a Linux Node whose running server does not answer 200 with its version fails before any change, in a dry run too", async () => {
    for (const [options, dryRun] of [[{ args: "--host=127.0.0.2" }, true], [{ args: "--host=127.0.0.2" }, false], [{ down: true }, true], [{ down: true }, false]] as const) {
        const run = await rollout({ nodes: [{ platform: "linux", canary: true }], dryRun, ...options });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_unreachable", rolled_back: false }]);
        assertEquals([run.linux, run.releases, run.systemctl], ["0.4.2", ["0.4.2"], []]);
    }
});

test("a Mac Node whose running server answers 503 without a version fails before any change, in a dry run too", async () => {
    for (const dryRun of [true, false]) {
        const run = await rollout({ nodes: [{ platform: "mac", canary: true }], dryRun, down: true });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_unreachable", rolled_back: false }]);
        assertEquals([run.installed, run.launchctl, run.backups], ["0.4.2", [], []]);
    }
    // A healthy Mac still reaches checked in a dry run and updated in a real one, with the old version it read in its checks.
    const dry = await rollout({ nodes: [{ platform: "mac", canary: true }], dryRun: true });
    assertEquals(dry.result.nodes, [{ node_id: 45, outcome: "checked" }]);
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }] });
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "updated", previous_version: "0.4.2", prompts: [] }]);
});

function macHealth(version: unknown, status = 200) {
    return Response.json({ server: "conn-server", version }, { status });
}

function redirectHealth() {
    return new Response(null, { status: 302, headers: { Location: "/redirected-health" } });
}

test("Mac preflight rejects redirected, non-200 and malformed version responses", async () => {
    for (const [label, response] of [
        ["redirect", redirectHealth()],
        ["non-200 with a version", macHealth("0.4.2", 503)],
        ["whitespace", macHealth(" \t")],
        ["number", macHealth(42)],
        ["boolean", macHealth(true)],
        ["object", macHealth({ version: "0.4.2" })],
    ] as const) {
        const run = await rollout({ nodes: [{ platform: "mac", canary: true }], dryRun: true, health: () => response.clone() });
        assert(!run.ok, label);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_unreachable", rolled_back: false }], label);
        assertEquals([run.installed, run.launchctl, run.backups], ["0.4.2", [], []], label);
    }
});

test("Mac health bypasses the configured HTTP proxy before a swap and after each restart", async () => {
    const nodes: Server[] = [{ platform: "mac", canary: true }];
    const healthy = await rollout({ nodes, proxy: true });
    assert(healthy.ok, healthy.output);
    assertEquals([healthy.result.nodes[0].outcome, healthy.launchctl.length, healthy.proxyRequests], ["updated", 1, 0]);
    const broken = await rollout({ nodes, proxy: true, broken: ["0.5.0"] });
    assert(!broken.ok, broken.output);
    assertEquals(broken.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: true }]);
    assertEquals([broken.installed, broken.launchctl.length, broken.proxyRequests], ["0.4.2", 2, 0]);
});

test("Mac restart and rollback each require a direct 200 with the intended version", async () => {
    const nodes: Server[] = [{ platform: "mac", canary: true }];
    for (const [label, health, rolledBack] of [
        ["503 on restart", (running: string, restarts: number) => restarts === 1 ? macHealth(running, 503) : undefined, true],
        ["redirect on restart, 503 on rollback", (running: string, restarts: number) =>
            restarts === 1 ? redirectHealth() : restarts === 2 ? macHealth(running, 503) : undefined, false],
        ["redirect on rollback", (_running: string, restarts: number) =>
            restarts === 1 ? macHealth("0.4.2") : restarts === 2 ? redirectHealth() : undefined, false],
        ["wrong version on rollback", (_running: string, restarts: number) => restarts > 0 ? macHealth("0.6.0") : undefined, false],
    ] as const) {
        const run = await rollout({ nodes, health });
        assert(!run.ok, label);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.health_check_failed", rolled_back: rolledBack }], label);
        assertEquals([run.installed, run.launchctl.length, run.backups.length], ["0.4.2", 2, 1], label);
    }
});

test("a Node set to notify posts the notice before its restart; with no target set, nothing is sent", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "mac", notify: true }], notice: true });
    assert(run.ok, run.output);
    assertEquals(run.noticed, [{ node_id: 46, version: "0.5.0", event: "conn_server.restart" }]);
    const silent = await rollout({ nodes: [{ platform: "mac", canary: true, notify: true }] });
    assert(silent.ok, silent.output);
    assertEquals(silent.noticed, []);
});

test("a move into the backup that renames the bundle and then fails restores the old bundle, which runs again", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }], exitOn: "mv-backup" });
    assert(!run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.step_failed", rolled_back: true }]);
    assertEquals([run.installed, run.running, run.launchctl.length], ["0.4.2", "0.4.2", 1]);
});

test("a failed notice URL or command fails the Node with its code before any change, and appears in no output", async () => {
    const secret = "s3cr3t-token-7f2b";
    for (const options of [{ noticeUrl: (port: number) => `http://127.0.0.1:${port}/hook?token=${secret}` }, { noticeCommand: ["sh", "-c", `echo ${secret}; echo ${secret} >&2; exit 1`, secret] }]) {
        const run = await rollout({ nodes: [{ platform: "mac", canary: true, notify: true }], notice: true, ...options });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason: "conn_server.notice_failed", rolled_back: false }]);
        assertEquals([run.installed, run.launchctl], ["0.4.2", []]);
        assert(!run.output.includes(secret) && !JSON.stringify(run.result).includes(secret), run.output);
    }
});

test("run as the workflow runs it, over ssh with no connection override, a Mac dry run writes its result from the worker", async () => {
    const dry = await rollout({ nodes: [{ platform: "mac", canary: true }], dryRun: true, ssh: true });
    assert(dry.ok, dry.output);
    assertEquals(dry.result.nodes, [{ node_id: 45, outcome: "checked" }]);
    assertEquals([dry.installed, dry.launchctl], ["0.4.2", []]);
    // The first step logs where its module runs: the python3 on PATH, the working folder, TMPDIR and the module's payload folder.
    assert(/"python3=\/\S+python3 pwd=\/\S* TMPDIR=\S* module_tmp=\/\S*\/ansible_\S*command_payload_\S+ ?"/.test(dry.output), dry.output);
    // The notice is sent from the worker too, before the Node that is set to notify restarts.
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, { platform: "mac", notify: true }], notice: true, ssh: true });
    assert(run.ok, run.output);
    assertEquals(run.noticed, [{ node_id: 46, version: "0.5.0", event: "conn_server.restart" }]);
});

test("over ssh, a Mac dry run whose first three modules each take 15 s to start still reaches checked", async () => {
    // The first three: the home and uid read, the installed check and the staging folder, whose task timeouts were 10, 20 and 20 s.
    const dry = await rollout({ nodes: [{ platform: "mac", canary: true }], dryRun: true, ssh: true, slowStarts: 3 });
    assert(dry.ok, dry.output);
    assertEquals(dry.result.nodes, [{ node_id: 45, outcome: "checked" }]);
});

test("a run with more Nodes than its deadline covers is refused before any Node is reached", async () => {
    const run = await rollout({ nodes: [{ platform: "mac", canary: true }, ...Array(4).fill({ platform: "linux" })] });
    assert(!run.ok && run.result === null, run.output);
    assert(run.output.includes("conn_server.too_many_nodes"), run.output);
    assertEquals([run.installed, run.linux, run.launchctl], ["0.4.2", "0.4.2", []]);
});

test("a Linux Node set up before this rollout, with no releases folder, gets the folder and is updated; a dry run makes none", async () => {
    const dry = await rollout({ nodes: [{ platform: "linux", canary: true }], releases: "first", dryRun: true });
    assert(dry.ok, dry.output);
    assertEquals(dry.result.nodes, [{ node_id: 45, outcome: "checked" }]);
    assertEquals([dry.linux, dry.releases], ["0.4.2", null]);
    const run = await rollout({ nodes: [{ platform: "linux", canary: true }], releases: "first" });
    assert(run.ok, run.output);
    assertEquals(run.result.nodes, [{ node_id: 45, outcome: "updated", previous_version: "0.4.2" }]);
    assertEquals([run.linux, run.running, run.releases], ["0.5.0", "0.5.0", [`0.5.0-${runId}`]]);
});

test("an existing release folder fails with backup_exists, and a releases folder that cannot be made fails with its own cause", async () => {
    for (const [releases, reason, left] of [["taken", "conn_server.backup_exists", ["0.4.2", `0.5.0-${runId}`]], ["blocked", "conn_server.step_failed", "file"]] as const) {
        const run = await rollout({ nodes: [{ platform: "linux", canary: true }], releases });
        assert(!run.ok, run.output);
        assertEquals(run.result.nodes, [{ node_id: 45, outcome: "failed", reason, rolled_back: false }]);
        assertEquals([run.linux, run.running, run.releases], ["0.4.2", "0.4.2", left]);
    }
});
