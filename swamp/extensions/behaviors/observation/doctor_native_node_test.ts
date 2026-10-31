import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nodeReadProgram, readNode } from "./doctor_native_node.ts";
import type { NodeIntent, Row } from "./doctor_contract.ts";
Deno.test("native Node read preserves filesystem deduplication, installed drift and secret digest redaction", async () => {
  const root = await Deno.makeTempDir();
  try {
    for (
      const directory of [
        "usr/local/bin",
        "etc/systemd/system",
        "etc/orbit/agent",
        "home/test",
      ]
    ) await Deno.mkdir(`${root}/${directory}`, { recursive: true });
    await Deno.writeTextFile(
      `${root}/usr/local/bin/orbit-agent`,
      "fixture-binary",
    );
    await Deno.chmod(`${root}/usr/local/bin/orbit-agent`, 0o755);
    await Deno.writeTextFile(
      `${root}/etc/orbit/agent/secret`,
      "secret-fixture-never-output",
    );
    await Deno.writeTextFile(
      `${root}/etc/systemd/system/orbit-agent.service`,
      "",
    );
    const program = `import json,types\nns={'__name__':'fixture'}\nexec(${
      JSON.stringify(nodeReadProgram)
    },ns)\nns['ROOT']=ns['Path'](${
      JSON.stringify(root)
    })\nns['pwd'].getpwnam=lambda user:types.SimpleNamespace(pw_dir='/home/test')\nns['os'].uname=lambda:types.SimpleNamespace(sysname='Linux',machine='amd64')\ndef run(argv,**options):\n assert argv==['ip','-j','-4','address','show']\n return types.SimpleNamespace(returncode=0,stdout=b'[{"addr_info":[{"family":"inet","local":"10.44.0.9"}]}]')\nns['subprocess'].run=run\nprint(json.dumps(ns['observe']({'user':'test','address':'10.44.0.9'})))`;
    const output = await new Deno.Command("python3", {
      args: ["-c", program],
      stdout: "piped",
      stderr: "piped",
    }).output();
    assert(output.success, new TextDecoder().decode(output.stderr));
    const text = new TextDecoder().decode(output.stdout),
      result = JSON.parse(text);
    assertEquals(result.platform, "linux");
    assertEquals(result.architecture, "x86_64");
    assertEquals(result.wireguard, true);
    assertEquals(result.agent_binary, true);
    assertEquals(result.agent_unit, true);
    assertEquals(result.agent_active, null);
    assertEquals(result.disks.length, 1);
    assert(result.disks[0].size_kib > 0);
    assert(/^[a-f0-9]{64}$/.test(result.secret_checksum));
    assert(!text.includes("secret-fixture-never-output"));
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("Node observer liveness comes from Gateway freshness and never invents an inactive service", async () => {
  const node = {
    platform: "linux",
    user: "orbit",
    address: "10.44.0.9",
    observations: { freshness: "fresh" },
  } as unknown as NodeIntent;
  const calls: string[][] = [];
  const local = (args: string[]) => {
    calls.push(args);
    return Promise.resolve(JSON.stringify({ agent_binary: true }));
  };
  const fresh = await readNode(node, local);
  assertEquals(fresh.agent_active, true);
  assertEquals(fresh.observer_unavailable, false);
  (node.observations as Row).freshness = "stale";
  const stale = await readNode(node, local);
  assertEquals(stale.agent_active, null);
  assertEquals(stale.observer_unavailable, true);
  assert(
    calls.every((args) =>
      args[0] === "sudo" && args[1] === "-n" &&
      !args.some((v) => v === "orbit-agent")
    ),
  );
});
