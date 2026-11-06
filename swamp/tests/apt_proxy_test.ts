/** A Node of a task VM's nested fleet reads its apt packages through the VM's package cache, and the pinned Tool Manager setup stays what it was. */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { toolSetupPlan } from "../extensions/behaviors/operations/node-bootstrap/tool_setup_plan.ts";

const read = (path: string) =>
    Deno.readTextFileSync(new URL(path, import.meta.url));
// deno-lint-ignore no-explicit-any
type Step = Record<string, any>;
const bootstrapSteps = (): Step[] =>
    (parse(read("../workflows/workflow-gateway-bootstrap.yaml")) as Step).jobs
        .flatMap((job: Step) => job.steps as Step[]);

Deno.test("the first apt call of a nested Gateway names the package cache of its intent, as the drop-in does", () => {
    const step = bootstrapSteps().find((s) =>
        s.name === "Install the prerequisites"
    )!;
    const argv = step.task.inputs.argv as string;
    assertStringIncludes(argv, "has(inputs.intent.apt_proxy)");
    assertStringIncludes(
        argv,
        '"Acquire::http::Proxy=" + inputs.intent.apt_proxy',
    );
    assertStringIncludes(argv, '"Acquire::http::Pipeline-Depth=0"');
    assertStringIncludes(argv, '"Acquire::https::Proxy=DIRECT"');
    // The drop-in of the later runs says the same.
    const dropIn = read("../ansible/tasks/apt-proxy.yml");
    assertStringIncludes(dropIn, 'Acquire::http::Pipeline-Depth "0";');
    assertStringIncludes(dropIn, 'Acquire::https::Proxy "DIRECT";');
});

Deno.test("the plan of the nested Gateway carries the package cache of its intent", () => {
    const step = bootstrapSteps().find((s) => s.name === "Render the plan")!;
    assertStringIncludes(
        step.task.inputs.run,
        '--apt-proxy="$ORBIT_APT_PROXY"',
    );
    assertStringIncludes(
        step.task.inputs.env.ORBIT_APT_PROXY,
        "inputs.intent.apt_proxy",
    );
});

Deno.test("the drop-in leaves the pinned Tool Manager setup as it was: it installs over https, and it names the cache itself to refresh", async () => {
    const plan = await toolSetupPlan({
        manager: "composer",
        input: { user: "orbit", version: "2.9.5-1" },
        apt: {
            snapshot: "20260101T000000Z",
            proxy: "http://10.232.2.1:3142",
            packages: [{ name: "composer", version: "2.9.5-1", artifact: null }],
        },
        packages: [],
    }, "/home/orbit");
    // The install reads https sources only; apt would send an https source through an http proxy (the cache denies the tunnel), so the drop-in says DIRECT.
    const install = plan.files.find((f) =>
        String(f.path).endsWith("/sources.list")
    )!;
    assertEquals(String(install.content).includes("] https://snapshot."), true);
    assertEquals(String(install.content).includes("http://"), false);
    assertEquals(
        plan.apt_install_prefix.some((a: string) => a.startsWith("Acquire::")),
        false,
    );
    // The index refresh ignores every drop-in and passes the same cache by itself.
    assertEquals(plan.apt_update.includes("Dir::Etc::parts=-"), true);
    assertEquals(
        plan.apt_update.includes("Acquire::http::Proxy=http://10.232.2.1:3142"),
        true,
    );
});
