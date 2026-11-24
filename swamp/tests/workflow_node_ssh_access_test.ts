/** node:ssh-access:converge (docs/cli/node/ssh-access-converge.mmd): a Linux Node takes the official authorized_key behavior, which
 * reads the account with getent; a Mac Node, which has no getent, takes its own step, run here against a stand-in dscl. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { environment } from "./_support/swamp_workflow.ts";
import { swampExpression } from "./_support/swamp_expression.ts";

type Step = { name: string; dependsOn?: { step: string; condition: unknown }[]; forEach: { item: string; in: string }; task: { behaviorType: string; inputs: { argv?: string[] } } };
const steps = (parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-node-ssh-access-converge.yaml", import.meta.url))) as {
    jobs: { steps: Step[] }[];
}).jobs[0].steps;
const linux = steps.find((step) => step.name === "Write the Orbit keys")!;
const mac = steps.find((step) => step.name === "Write the Mac keys")!;
const key = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl swamp-worker";
const inputs = (platform: string) => ({ node_id: 8n, intent: { platform, keys: [key], target: { user: "nick" } } });
const items = (step: Step, platform: string) => environment.evaluate(swampExpression(step.forEach.in), { inputs: inputs(platform) });

Deno.test("a Linux Node's keys go to the official authorized_key behavior, and a Mac Node's to the Mac step only", () => {
    assertEquals(linux.task.behaviorType, "@swamp/cfgmgmt/authorized_key");
    assertEquals([items(linux, "linux"), items(mac, "linux")], [[key], []]);
    assertEquals([items(linux, "macos"), items(mac, "macos")], [[], [key]]);
    // The login check runs after whichever step wrote the keys, and not after a failed write.
    const login = steps.find((step) => step.name === "Log in with Swamp identity")!;
    const written = { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] };
    assertEquals(login.dependsOn, [{ step: linux.name, condition: written }, { step: mac.name, condition: written }]);
});

Deno.test("on a Mac, the key is added once to the home that dscl names, other lines stay, and getent is never run", () => {
    const folder = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(`${folder}/Users/nick/.ssh`, { recursive: true });
        const tool = (name: string, text: string) => {
            Deno.writeTextFileSync(`${folder}/bin/${name}`, `#!/bin/sh\n${text}\n`);
            Deno.chmodSync(`${folder}/bin/${name}`, 0o755);
        };
        tool("dscl", `[ "$1 $2 $3 $4" = ". -read /Users/nick NFSHomeDirectory" ] || exit 56\necho "NFSHomeDirectory: ${folder}/Users/nick"`);
        tool("getent", 'echo "zsh:1: command not found: getent" >&2; exit 127');
        const file = `${folder}/Users/nick/.ssh/authorized_keys`;
        // An operator key with no final newline.
        Deno.writeTextFileSync(file, "ssh-ed25519 AAAAoperator nick@laptop");
        const argv = mac.task.inputs.argv!.map((value) =>
            value.startsWith("${{") ? environment.evaluate(swampExpression(value), { inputs: inputs("macos"), self: { key, _index: 0n } }) as string : value);
        const run = () => {
            const done = new Deno.Command(argv[0], { args: argv.slice(1), env: { PATH: `${folder}/bin:/usr/bin:/bin` } }).outputSync();
            assert(done.success, new TextDecoder().decode(done.stderr));
        };
        run();
        assertEquals(Deno.readTextFileSync(file), `ssh-ed25519 AAAAoperator nick@laptop\n${key}\n`);
        run();
        assertEquals(Deno.readTextFileSync(file), `ssh-ed25519 AAAAoperator nick@laptop\n${key}\n`);
        assert(!argv.join(" ").includes("getent"));
        // A missing key folder is made private.
        Deno.removeSync(`${folder}/Users/nick/.ssh`, { recursive: true });
        run();
        assertEquals([Deno.statSync(`${folder}/Users/nick/.ssh`).mode! & 0o777, Deno.statSync(file).mode! & 0o777], [0o700, 0o600]);
        assertEquals(Deno.readTextFileSync(file), `${key}\n`);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
