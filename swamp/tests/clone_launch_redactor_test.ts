/**
 * The clone's launch copy against Swamp's own redactor at the pinned release. Swamp registers every value a run reads with
 * `vault.get`; a value with a newline becomes one regular expression, which V8 refuses for a line of about 32 KB (release 2080 failed
 * every clone create at clone-container with "Regular expression too large"). The launch copy is one line
 * (apps/gateway/app/Domain/Instances/CloneStage.php). The redactor masks a value only as a whole: a token inside the copy is not
 * masked when it appears alone, so the clone script's own redaction keeps it out of a failure detail (instance_clone_test.py
 * CopyStepRedactionTest).
 */
import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { SecretRedactor } from "https://raw.githubusercontent.com/swamp-club/swamp/537e03d18f517965c7a0e49b9f5954365f4da70c/src/domain/secrets/secret_redactor.ts";

const read = (path: string) => Deno.readTextFileSync(new URL(`../${path}`, import.meta.url));
// The public part as CloneStage::payload builds it, from the real release files.
const stage = {
    launcher: read("scripts/instance_clone_launch.py"), script: read("scripts/instance_clone.py"),
    container: { instance_id: 7, clone: { source: "instance-1" } }, guest: read("scripts/instance_clone_guest.py"),
    network: read("ansible/instance_network.py"), account: "orbit",
    bundle: { node_services: read("ansible/instance_node_services.py"), machine_name: read("ansible/instance_machine_name.py"), root_disk: read("ansible/instance_root_disk.py"),
        mail: read("ansible/instance_mail.py"), bridge: read("ansible/instance_bridge.py"), node: read("ansible/instance_node_runtime.py"),
        workspace_owner: read("ansible/instance_workspace_owner.py") },
};
// The clone copy's text in its real shape (InstanceContainer::modelEnvironment), with fixture values of the real length.
const token = "ghs_" + "T".repeat(36);
const modelKey = "orbit-model-" + "m".repeat(52);
const privateText = `https://x-access-token:${token}@github.com\n#orbit-model-environment\nANTHROPIC_BASE_URL=http://10.253.0.1:8319\n` +
    `ANTHROPIC_AUTH_TOKEN=${modelKey}\nORBIT_MODEL_KEY=${modelKey}\nCLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1\nDISABLE_TELEMETRY=1\nDISABLE_ERROR_REPORTING=1\n`;
const launch = JSON.stringify({ stage, private: privateText });
const maxBytes = Number(/MaxBytes = ([0-9_]+);/.exec(Deno.readTextFileSync(new URL("../../apps/gateway/app/Domain/Instances/CloneStage.php", import.meta.url)))![1].replaceAll("_", ""));

Deno.test("the launch copy is one line under its cap, and Swamp's redactor registers and masks it as a plain string", () => {
    assertEquals(launch.includes("\n"), false);
    assert(launch.length > 150_000 && new TextEncoder().encode(launch).length < maxBytes, String(launch.length));
    const redactor = new SecretRedactor();
    redactor.addSecret(launch);
    assertEquals(redactor.redact(`step output ${launch} end`), "step output *** end");
});

Deno.test("release 2079's two-part stage copy is what broke 2080: its first redact throws Regular expression too large", () => {
    const redactor = new SecretRedactor();
    redactor.addSecret(JSON.stringify(stage) + "\n" + privateText);
    assertThrows(() => redactor.redact("step output"), SyntaxError, "Regular expression too large");
});

Deno.test("Swamp masks only the whole launch copy: a token or model key that appears alone stays for the script's own redaction", () => {
    const alone = `fatal: could not read from https://x-access-token:${token}@github.com; key ${modelKey}`;
    const redactor = new SecretRedactor();
    redactor.addSecret(launch);
    assert(redactor.redact(alone).includes(token) && redactor.redact(alone).includes(modelKey));
});
