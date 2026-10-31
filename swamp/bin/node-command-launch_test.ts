import { assertEquals } from "jsr:@std/assert@1.0.19";
import { nodeCommandLaunch } from "./node-command-launch.ts";

const head =
  '/usr/bin/python3 -I "${{ steps["proxy-assets"].outputs.runner }}" --node-id "$ORBIT_EFFECT_NODE_ID" --env ORBIT_PUBLIC_VALUE --secret-env ORBIT_PRIVATE_VALUE';
const valid = head +
  " <<'ORBIT_ACTION'\nprintf '%s' \"$ORBIT_PUBLIC_VALUE\"\nORBIT_ACTION\n";
Deno.test("native proxy launcher is one exact local command and a visible remote action", () => {
  assertEquals(nodeCommandLaunch(valid), {
    assetStep: "proxy-assets",
    env: ["ORBIT_PUBLIC_VALUE", "ORBIT_PRIVATE_VALUE"],
    action: "printf '%s' \"$ORBIT_PUBLIC_VALUE\"\n",
  });
  for (
    const source of [
      "touch /tmp/local\n" + valid,
      valid + "touch /tmp/local\n",
      valid.replace("/usr/bin/python3 -I", "python3"),
      valid.replace(" -I ", " "),
      valid.replace("ORBIT_PUBLIC_VALUE", "BASH_ENV"),
      valid.replace("ORBIT_PUBLIC_VALUE", "LD_PRELOAD"),
      valid.replace("ORBIT_PUBLIC_VALUE", "PYTHONPATH"),
      valid.replace("printf", "${{ inputs.action }}\nprintf"),
      valid.replace("--node-id", "$(touch /tmp/local) --node-id"),
      valid.replace(
        'steps["proxy-assets"].outputs.runner',
        "inputs.runner",
      ),
      valid.replace("ORBIT_ACTION\n", "ORBIT_ACTION\nORBIT_ACTION\n"),
      valid.replace("--env ORBIT_PUBLIC_VALUE", '--env "$ORBIT_PRIVATE_VALUE"'),
    ]
  ) assertEquals(nodeCommandLaunch(source), null, source);
});
