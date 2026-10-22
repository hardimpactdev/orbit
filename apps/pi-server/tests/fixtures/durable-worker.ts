// This entry is also compiled in the tests: all model requests use pi-ai's faux provider.
import "@earendil-works/pi-ai/models";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import type { AddressInfo } from "node:net";
import { fauxAssistantMessage, fauxProvider, fauxToolCall } from "@earendil-works/pi-ai";
import { ModelRuntime } from "@earendil-works/pi-coding-agent";
import { createPiServer } from "../../src/http.ts";
import { SessionRegistry } from "../../src/registry.ts";

const [root, mode] = process.argv.slice(2);
if (!root) throw new Error("Expected fixture root");
const workspace = join(root, "workspace");
mkdirSync(join(workspace, ".git"), { recursive: true });
const faux = fauxProvider({ provider: "faux", models: [{ id: "model-a" }] });
if (mode === "hold" || mode === "park") {
    faux.setResponses([
        async (_context, options) => {
            writeFileSync(join(root, "request-started"), "yes");
            await new Promise<void>((resolve) =>
                options!.signal!.addEventListener("abort", () => resolve(), { once: true }),
            );
            return fauxAssistantMessage("cut off");
        },
    ]);
} else if (mode === "tool") {
    faux.setResponses([
        fauxAssistantMessage(
            [
                fauxToolCall("bash", {
                    command:
                        "printf once >> effects; echo $$ > shell.pid; touch tool-started; while ! test -f release; do sleep 0.02; done",
                }),
            ],
            { stopReason: "toolUse" },
        ),
    ]);
} else {
    faux.setResponses([fauxAssistantMessage("recovered")]);
}
const modelRuntime = await ModelRuntime.create({
    authPath: join(root, "agent", "auth.json"),
    modelsPath: null,
    refreshOnCreate: false,
});
modelRuntime.registerNativeProvider(faux.provider);
await modelRuntime.setRuntimeApiKey("faux", "fixture");
const registry = new SessionRegistry({
    modelRuntime,
    harnessSettings: mode?.startsWith("park") ? { restartLimit: 1 } : {},
    agentDir: join(root, "agent"),
    sessionDir: join(root, "sessions"),
    workspaceRoots: [root],
    allowApiKeys: true,
    allowedProviders: [],
    idleUnloadMs: 60_000,
});
const server = createPiServer({
    registry,
    token: "test-token-that-is-at-least-32-characters",
    capabilities: async () => ({ piVersion: "1.4.0", models: await registry.availableModels() }),
});
server.listen(0, "127.0.0.1", () =>
    console.log(
        JSON.stringify({ url: `http://127.0.0.1:${(server.address() as AddressInfo).port}` }),
    ),
);
