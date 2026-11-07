import { assertEquals } from "jsr:@std/assert@1";

// The guest firewall denies incoming traffic by default, so a resume must allow Pi's port.
const PI_209 =
    "ufw allow in on orbit proto tcp from 10.44.0.15 to any port 3774 comment orbit:task-vm-pi-209";
const PI_GATEWAY =
    "ufw allow in on orbit proto tcp from 10.44.0.2 to any port 3774 comment orbit:task-vm-pi-gateway";
// A routed workspace answers on 443 to the Cluster router and to 209's TLS probe of the Route create.
const SITE = "ufw allow in on orbit proto tcp to any port 443 comment orbit:task-vm-site";

async function workflow(name: string): Promise<string> {
    return await Deno.readTextFile(
        new URL(`../../../../workflows/${name}.yaml`, import.meta.url),
    );
}

function chains(text: string): string[] {
    return text.split("\n").flatMap((line) =>
        line.match(/ufw --force delete allow in on orbit[^"]*ufw --force enable/g) ?? []
    );
}

Deno.test("workflow-operation-task-vm-resume allows Pi from 209 and the Gateway before ufw is enabled", async () => {
    const found = chains(await workflow("workflow-operation-task-vm-resume"));
    assertEquals(found.length, 1);
    for (const chain of found) {
        const enable = chain.indexOf("ufw --force enable");
        assertEquals(chain.indexOf(PI_209) > -1, true);
        assertEquals(chain.indexOf(PI_GATEWAY) > -1, true);
        assertEquals(chain.indexOf(PI_209) < enable, true);
        assertEquals(chain.indexOf(PI_GATEWAY) < enable, true);
        assertEquals(chain.indexOf(SITE) > -1 && chain.indexOf(SITE) < enable, true);
    }
});
