import { hostTasks } from "./_support/host_task_scope.ts";
/**
 * The agent folder step's T3 restart (docs/reference/development-containers.md#t3s-unit-drop-ins): right before each restart the Node
 * reads the server's open runs, of every thread, and defers the restart of a T3 with an open turn until a converge finds it idle.
 * The drop-in cases themselves live in ansible_instance_host_browser_test.ts; both use the T3 fake fixtures/instance-host-t3-incus.ts.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { DatabaseSync } from "node:sqlite";
import { fixture, hasAnsible } from "./_support/instance_host.ts";

/** The T3 program each t3_files entry names, as the Gateway's ContainerServer::program() does. */
const program = "/opt/orbit-agent/current/agent/bin/t3";

Deno.test({
    name: "a T3 with an open turn gets its drop-in and reload but no restart, stays on the Node's list as deferred, and restarts once when idle",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            hostTasks(f.folder, ["Bring the T3 unit drop-ins of the existing containers to their text", "Install the shared folders"]);
            Deno.writeTextFileSync(`${f.folder}/bin/incus`,
                `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-host-t3-incus.ts", import.meta.url).pathname}' "$@"\n`);
            const ready = await f.run();
            assert(ready.ok, ready.output);
            const server = "/etc/systemd/system/t3.service.d/20-orbit-server.conf";
            const names = ["app-busy", "app-idle"];
            const state = f.state();
            state.instances.push(...names.map((name) => ({ project: "orbit-instances", name, status: "Running" })));
            state.files = Object.fromEntries(names.map((name) => [name + server, { content: "# old\n", mode: "0644", uid: "0", gid: "0" }]));
            state.t3_busy = ["app-busy"];
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            const write = (content: string, only = names) =>
                f.write({ ...f.instances, t3_files: only.map((container) => ({ project: "orbit-instances", container, program, files: [{ path: server, content, mode: "0644" }] })) });
            const entry = (name: string) => ({ project: "orbit-instances", name });
            const pending = () => f.has("t3-restart.json") ? JSON.parse(Deno.readTextFileSync(`${f.folder}/t3-restart.json`)) : [];
            const exec = (name: string, ...command: string[]) => ["exec", "--project", "orbit-instances", name, "--", "systemctl", ...command];
            const pass = async () => {
                const before = f.has("calls") ? f.calls().length : 0;
                const run = await f.run();
                assert(run.ok, run.output);
                return { run, execs: f.calls().slice(before).filter((call) => call[0] === "exec" && !call.includes("NeedDaemonReload")) };
            };

            // (a) Only the busy container: its drop-in is written and reloaded, but its T3 keeps running its turn.
            write("# new\n", ["app-busy"]);
            let { run, execs } = await pass();
            assertEquals(f.state().files[`app-busy${server}`].content, "# new\n");
            assertEquals(execs, [exec("app-busy", "daemon-reload")]);
            assertEquals(pending(), [entry("app-busy")]);
            assertStringIncludes(run.output, "open turn in thread-app-busy");
            // T3 itself is asked, as agent, with the program the Gateway names.
            assertEquals(f.state().t3_checks.filter((check: { check: string }) => check.check === "list").map((check: { program: string }) => check.program), [program]);

            // (b) The turn ended: the next converge restarts that T3 once and empties the list; a third changes nothing.
            const idle = f.state();
            idle.t3_busy = [];
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(idle));
            assertEquals((await pass()).execs, [exec("app-busy", "try-restart", "t3.service")]);
            assert(!f.has("t3-restart.json"));
            assertEquals((await pass()).execs, []);

            // (c) An agent folder flip with one busy and one idle T3: only the idle one restarts, the busy one waits on the list.
            const busyAgain = f.state();
            busyAgain.t3_busy = ["app-busy"];
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(busyAgain));
            write("# new\n");
            const agent = f.instances.folders.agent;
            Deno.writeTextFileSync(`${agent.path}/${agent.version}/old`, "a file the new version lacks");
            f.instances.folders.agent = { ...agent, version: "444444444444" };
            write("# new\n");
            ({ run, execs } = await pass());
            const restarted = execs.filter((call) => call.includes("try-restart")).map((call) => call[3]);
            assert(restarted.includes("app-idle") && !restarted.includes("app-busy"), JSON.stringify(execs));
            assertEquals(pending(), [entry("app-busy")]);
            // Every target was restarted or listed, so the folder's restart mark is gone.
            assert(!(() => { try { return Deno.statSync(`${agent.path}/.restart`).isFile; } catch { return false; } })());

            // (d) A list that fails while T3 runs counts as busy: no blind restart.
            const failing = f.state();
            failing.t3_busy = [];
            failing.t3_list_fail = ["app-busy"];
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(failing));
            ({ run, execs } = await pass());
            assertEquals(execs, []);
            assertEquals(pending(), [entry("app-busy")]);
            assertStringIncludes(run.output, "its open runs could not be read");
            // A T3 that does not run needs no list: its try-restart is a no-op, and it leaves the list.
            const stopped = f.state();
            stopped.t3_inactive = ["app-busy"];
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(stopped));
            assertEquals((await pass()).execs, [exec("app-busy", "try-restart", "t3.service")]);
            assert(!f.has("t3-restart.json"));
        } finally {
            f.cleanup();
        }
    },
});

Deno.test({
    name: "an idle root with a running subagent child (T3) or side chat (Conn Server) defers the restart, read from the server's own store, or the other server's when a switched unit still runs the old one; once they settle it restarts once, and a container with no store restarts at once",
    ignore: !hasAnsible,
    async fn() {
        const f = await fixture();
        try {
            hostTasks(f.folder, ["Bring the T3 unit drop-ins of the existing containers to their text", "Install the shared folders"]);
            Deno.writeTextFileSync(`${f.folder}/bin/incus`,
                `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-host-t3-incus.ts", import.meta.url).pathname}' "$@"\n`);
            const ready = await f.run();
            assert(ready.ok, ready.output);
            const server = "/etc/systemd/system/t3.service.d/20-orbit-server.conf";
            // app-switched's unit names the Conn Server while T3 still runs and keeps its store, as on instance-3728; app-fresh has none.
            const names = { "app-t3": "t3", "app-conn": "conn-server", "app-switched": "conn-server", "app-fresh": "t3" } as Record<string, string>;
            const kinds = { "app-t3": "t3", "app-conn": "conn-server", "app-switched": "t3", "app-fresh": "t3" } as Record<string, string>;
            const state = f.state();
            state.instances.push(...Object.keys(names).map((name) => ({ project: "orbit-instances", name, status: "Running" })));
            state.files = Object.fromEntries(Object.keys(names).map((name) => [name + server, { content: "# old\n", mode: "0644", uid: "0", gid: "0" }]));
            Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
            // Each server's store as the live one keeps it: the root thread's run is over and a hidden thread's run is open. Each
            // program lists only the root, as `thread list` does: it hides subagent children and side chats.
            const lineage = (relationshipToParent: string | null, sideChat: boolean) =>
                JSON.stringify({ lineage: { parentThreadId: relationshipToParent ? "root" : null, relationshipToParent, rootThreadId: "root", ...(sideChat ? { sideChat } : {}) } });
            const store = (name: string, hidden: string) => {
                const home = `${f.folder}/homes/${name}`;
                Deno.mkdirSync(`${home}/.t3/userdata`, { recursive: true });
                Deno.mkdirSync(`${home}/bin`);
                Deno.writeTextFileSync(`${home}/bin/${names[name]}`, `#!/bin/sh\necho '[{"threadId":"root","status":"completed"}]'\n`);
                Deno.chmodSync(`${home}/bin/${names[name]}`, 0o755);
                Deno.writeTextFileSync(`${home}/.t3/userdata/projects.json`, JSON.stringify([{ title: "workspace", workspaceRoot: "/srv/workspace" }]));
                const db = new DatabaseSync(`${home}/.t3/userdata/${kinds[name] === "t3" ? "statev2" : "engine"}.sqlite`);
                if (kinds[name] === "t3") {
                    db.exec(`CREATE TABLE projection_projects (project_id TEXT PRIMARY KEY, workspace_root TEXT NOT NULL, deleted_at TEXT);
                        INSERT INTO projection_projects VALUES ('project-1', '/srv/workspace', NULL);
                        CREATE TABLE orchestration_v2_projection_threads (thread_id TEXT PRIMARY KEY, payload_json TEXT NOT NULL);
                        CREATE TABLE orchestration_v2_projection_runs (run_id TEXT PRIMARY KEY, thread_id TEXT NOT NULL, status TEXT NOT NULL);`);
                    db.prepare("INSERT INTO orchestration_v2_projection_threads VALUES (?, ?), (?, ?)").run("root", lineage(null, false), hidden, lineage("subagent", false));
                    db.prepare("INSERT INTO orchestration_v2_projection_runs VALUES ('run-root', 'root', 'completed'), ('run-hidden', ?, 'running')").run(hidden);
                } else {
                    db.exec(`CREATE TABLE threads (id TEXT PRIMARY KEY, thread TEXT NOT NULL);
                        CREATE TABLE entities (thread_id TEXT NOT NULL, kind TEXT NOT NULL, id TEXT NOT NULL, body TEXT NOT NULL, PRIMARY KEY (thread_id, kind, id));`);
                    db.prepare("INSERT INTO threads VALUES (?, ?), (?, ?)").run("root", lineage(null, false), hidden, lineage("fork", true));
                    db.prepare("INSERT INTO entities VALUES ('root', 'runs', 'run-root', ?), (?, 'runs', 'run-hidden', ?), (?, 'messages', 'm', ?)")
                        .run(JSON.stringify({ status: "completed" }), hidden, JSON.stringify({ status: "running" }), hidden, JSON.stringify({ status: "running" }));
                }
                db.close();
                return () => {
                    const settle = new DatabaseSync(`${home}/.t3/userdata/${kinds[name] === "t3" ? "statev2" : "engine"}.sqlite`);
                    settle.exec(kinds[name] === "t3" ? "UPDATE orchestration_v2_projection_runs SET status = 'completed'"
                        : "UPDATE entities SET body = '{\"status\":\"completed\"}' WHERE kind = 'runs'");
                    settle.close();
                };
            };
            const settleChild = store("app-t3", "child");
            const settleSide = store("app-conn", "side");
            const settleSwitched = store("app-switched", "switched-child");
            Deno.mkdirSync(`${f.folder}/homes/app-fresh/.t3/userdata`, { recursive: true });
            Deno.mkdirSync(`${f.folder}/homes/app-fresh/bin`);
            f.write({ ...f.instances, t3_files: Object.entries(names).map(([container, bin]) => ({ project: "orbit-instances", container,
                program: `/opt/orbit-agent/current/agent/bin/${bin}`, files: [{ path: server, content: "# new\n", mode: "0644" }] })) });
            const exec = (name: string, ...command: string[]) => ["exec", "--project", "orbit-instances", name, "--", "systemctl", ...command];
            const pass = async () => {
                const before = f.has("calls") ? f.calls().length : 0;
                const run = await f.run();
                assert(run.ok, run.output);
                return { run, execs: f.calls().slice(before).filter((call) => call[0] === "exec" && !call.includes("NeedDaemonReload")) };
            };
            const pending = () => f.has("t3-restart.json") ? JSON.parse(Deno.readTextFileSync(`${f.folder}/t3-restart.json`)) : [];

            const busy = await pass();
            assertEquals(busy.execs, ["app-conn", "app-fresh", "app-switched", "app-t3"].map((name) => exec(name, "daemon-reload")).concat([
                exec("app-fresh", "try-restart", "t3.service")]));
            assertStringIncludes(busy.run.output, "open turn in child");
            assertStringIncludes(busy.run.output, "open turn in side");
            // The switched unit's missing engine.sqlite is no failed read: T3's own store answers, with its open run.
            assertStringIncludes(busy.run.output, "open turn in switched-child");
            assert(!busy.run.output.includes("could not be read"), busy.run.output);
            assertEquals(pending(), ["app-conn", "app-switched", "app-t3"].map((name) => ({ project: "orbit-instances", name })));

            // The child settles: only that T3 restarts. Then the side chat settles: the Conn Server restarts. Then the switched unit's.
            settleChild();
            assertEquals((await pass()).execs, [exec("app-t3", "try-restart", "t3.service")]);
            assertEquals(pending(), ["app-conn", "app-switched"].map((name) => ({ project: "orbit-instances", name })));
            settleSide();
            assertEquals((await pass()).execs, [exec("app-conn", "try-restart", "t3.service")]);
            settleSwitched();
            assertEquals((await pass()).execs, [exec("app-switched", "try-restart", "t3.service")]);
            assert(!f.has("t3-restart.json"));
        } finally {
            f.cleanup();
        }
    },
});
