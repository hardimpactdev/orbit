import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { bootstrapSsh } from "../../../../tests/_support/bootstrap_ssh.ts";

for (const missing of [false, true]) {
  Deno.test(missing ? "Mac without its admitted tunnel address fails" : "Mac inspection only reads the admitted host and keeps its identity private", async () => {
    const { repo, publicKey, reference } = await bootstrapSsh("operation-node-macos-inspect");
    await using _cleanup = repo;
    const audit = `${repo.dir}/calls`;
    await repo.fakeBinary("ssh", `
const command = Deno.args.at(-1);
Deno.writeTextFileSync(${JSON.stringify(audit)}, command + "\\n", {append:true});
if (command.includes("uname")) console.log("Darwin arm64");
else if (command.includes("ifconfig")) console.log("utun0:\\n\\tinet ${missing ? "10.44.0.120" : "10.44.0.12"} netmask 0xffffffff");
else Deno.exit(99);
`);
    repo.env.PATH = `${repo.dir}:${Deno.env.get("PATH")}`;
    const inputs = {
      operation_id: 1, node_id: 14, kind: "node.macos_inspect",
      intent: { bootstrap: { node_id: 12, expected_architecture: "arm64", address: "10.44.0.12",
        target: { host: "admitted-mac", port: 2222, user: "mac-user", host_keys: [publicKey] } } },
      execution_secrets: { bootstrap_identity: reference },
    };
    await repo.cli(["workflow", "validate", "operation-node-macos-inspect"]);
    const reply = await repo.cli(["workflow", "run", "operation-node-macos-inspect", "--stdin"], {input:JSON.stringify(inputs), success:!missing});
    if (missing) assertStringIncludes(reply.stdout + reply.stderr, "The Mac must have its admitted WireGuard address");
    else {
      const { history } = await repo.history(JSON.parse(reply.stdout).id);
      assertStringIncludes(JSON.stringify(history), "bootstrap-macos-12");
      const calls = await Deno.readTextFile(audit);
      for (const command of ["uname", "ifconfig"]) assertStringIncludes(calls, command);
      for (const command of ["orbit-agent", "id -"]) assertEquals(calls.includes(command), false);
    }
  });
}
