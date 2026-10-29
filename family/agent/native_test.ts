import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { stringify } from "jsr:@std/yaml@1.0.12";
import { nativeEnvironment } from "../../bin/compile-family.ts";

const binary = Deno.env.get("SWAMP_BIN");
if (!binary) throw new Error("Set SWAMP_BIN to the pinned Swamp executable");

Deno.test("pinned native runtime never persists identity, input or output sentinels anywhere in its repo", async () => {
  const scratch = await Deno.makeTempDir({ prefix: "typed-agent-native-" });
  const repo = `${scratch}/repo`;
  const upstream = new URL(
    "../../.swamp/pulled-extensions/@swamp/ssh/models/ssh.ts",
    import.meta.url,
  ).href;
  const factory = new URL("./ssh.ts", import.meta.url).href;
  const sentinels = {
    identity: "synthetic-native-identity-" + crypto.randomUUID(),
    input: "synthetic-native-input-" + crypto.randomUUID(),
    output: "synthetic-native-output-" + crypto.randomUUID(),
  };
  // A fake remote's fixture data stays outside the Swamp repo, like the remote itself.
  const secretFile = `${scratch}/remote-fixture.json`;
  const run = async (args: string[], source?: string) => {
    const process = new Deno.Command(binary!, {
      args: [...args, "--json"],
      cwd: repo,
      clearEnv: true,
      env: nativeEnvironment(),
      stdin: source === undefined ? "null" : "piped",
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    if (source !== undefined) {
      const writer = process.stdin.getWriter();
      await writer.write(new TextEncoder().encode(source));
      await writer.close();
    }
    const output = await process.output();
    let diagnostic = new TextDecoder().decode(output.stdout) +
      new TextDecoder().decode(output.stderr);
    for (const value of Object.values(sentinels)) {
      diagnostic = diagnostic.replaceAll(value, "[SENTINEL]");
    }
    assertEquals(output.code, 0, diagnostic);
    const text = new TextDecoder().decode(output.stdout);
    if (args[0] === "workflow") return text;
    return JSON.parse(text);
  };
  try {
    await Deno.mkdir(repo);
    await Deno.writeTextFile(secretFile, JSON.stringify(sentinels), {
      mode: 0o600,
    });
    await Deno.copyFile(".swamp.yaml", `${repo}/.swamp.yaml`);
    await Deno.mkdir(`${repo}/extensions/behaviors`, { recursive: true });
    await Deno.mkdir(`${repo}/workflows`);
    await Deno.mkdir(`${repo}/keys`, { mode: 0o700 });
    await Deno.writeTextFile(
      `${repo}/extensions/behaviors/ssh.ts`,
      `export {model} from ${JSON.stringify(upstream)};`,
    );
    await Deno.writeTextFile(
      `${repo}/extensions/behaviors/typed.ts`,
      `
import { z } from "npm:zod@4.4.3";
import { orbitAgentExtension } from ${JSON.stringify(factory)};
const fixture = async () => JSON.parse(await Deno.readTextFile(${
        JSON.stringify(secretFile)
      }));
export const extension = orbitAgentExtension([{
 name:"tool.apt-version", mutates:false,
 input:z.strictObject({token:z.string().meta({sensitive:true})}),
 output:z.strictObject({version:z.string().meta({sensitive:true})}),
 privilege:{linux:"account",macos:null},timeout_seconds:10,errors:[],
}], {
 prepare: async(hosts)=>{
   if(hosts[0].transport.identityContent !== (await fixture()).identity) throw new Error("identity fixture mismatch");
   return {hosts,cleanup:()=>Promise.resolve()};
 },
 process: async(request)=>{
   if(JSON.parse(request.input).input.token !== (await fixture()).input) throw new Error("input fixture mismatch");
   return {code:0,stdout:JSON.stringify({ok:true,data:{version:(await fixture()).output}})+"\\n"};
 },
});`,
    );
    for (
      const name of [
        "orbit-control-outputs",
        "orbit-control-inputs",
        "orbit-node-access",
      ]
    ) {
      await run([
        "vault",
        "create",
        "local_encryption",
        name,
        "--config",
        JSON.stringify({
          base_dir: repo,
          auto_generate: true,
          key_file: `${repo}/keys/${name}.key`,
        }),
        "--audit-reads",
      ]);
    }
    await run(
      ["vault", "put", "orbit-node-access", "fixture-identity"],
      sentinels.identity,
    );
    await run(
      ["vault", "put", "orbit-control-inputs", "fixture-input"],
      sentinels.input,
    );
    const { workflow } = JSON.parse(
      await Deno.readTextFile("tests/fixtures/family/example.json"),
    );
    workflow.jobs[0].steps[0].task = {
      type: "behavior",
      behaviorType: "@swamp/ssh",
      behaviorName: "typed-native-fixture",
      methodName: "orbit_agent",
      globalArgs: {
        name: "control-209",
        identityContentMode: "agent",
        transport: {
          kind: "ssh",
          user: "orbit",
          hostKeyPolicy: "pinned",
          hostKeys: [
            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIE1hsFb4g5nLZc6G/4IlOuHk9Q8OZG4mG9TV2tcqfrjY",
          ],
          identityContent:
            "${{ vault.get('orbit-node-access', 'fixture-identity') }}",
          identitiesOnly: true,
          controlMaster: { enabled: false },
        },
        hosts: [{
          name: "node-209",
          address: "10.44.0.15",
          attrs: {
            node_id: 209,
            platform: "linux",
            agent_sha256: "a".repeat(64),
          },
        }],
      },
      inputs: {
        node_id: 209,
        operation_id: 71,
        step: "version",
        command: "tool.apt-version",
        input:
          "${{ {'token': vault.get('orbit-control-inputs', 'fixture-input')} }}",
      },
    };
    await Deno.writeTextFile(
      `${repo}/workflows/workflow-typed.yaml`,
      stringify(workflow),
    );
    await run(["workflow", "validate", workflow.name]);
    await run(["workflow", "run", workflow.name]);
    const data = await run([
      "data",
      "query",
      'name == "op-71.version"',
      "--select",
      "content",
    ]);
    assert(
      JSON.stringify(data).includes("orbit-control-outputs"),
      "Persisted result must refer to the output vault",
    );
    const keys = await run(["vault", "list-keys", "orbit-control-outputs"]);
    assert(keys.count > 0);

    // This is a persistence leak test, so inspect every file directly, including
    // definitions, bundles, evaluated snapshots, data, history, logs and reports.
    // The fixture contains no plaintext sentinel in its source or definitions.
    let scanned = 0;
    const scan = async (path: string) => {
      for await (const entry of Deno.readDir(path)) {
        const child = `${path}/${entry.name}`;
        assert(!entry.isSymlink, "Unexpected symlink in native test repo");
        if (entry.isDirectory) await scan(child);
        else if (entry.isFile) {
          const content = new TextDecoder().decode(await Deno.readFile(child));
          for (const [label, value] of Object.entries(sentinels)) {
            assert(
              !content.includes(value),
              `Plaintext ${label} sentinel in ${child.slice(repo.length + 1)}`,
            );
          }
          scanned++;
        }
      }
    };
    await scan(repo);
    assert(scanned > 20, "The complete generated repo tree must be scanned");
  } finally {
    await Deno.remove(scratch, { recursive: true });
  }
});
