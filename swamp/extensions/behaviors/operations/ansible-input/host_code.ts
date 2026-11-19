import { importNames, phases, resolvePhases } from "./phase_code.ts";
import { roleSiblings } from "./converge_input.ts";

/**
 * The code digest of the Instance host on nested-app-dev (docs/reference/development-containers.md#instance-host-in-the-fleet): what its
 * `app-dev` role converge can load. That is every file a node.converge phase can load (phase_code.ts: classes a, b and d), the files the
 * phases name above the bundle and the modules they import, the staged role siblings (roleSiblings), the Tool helper and the Tool folder,
 * and the machine-software recipe. A file that only another Operation's playbook reaches (class c) is not in it. A phase the resolver
 * cannot bound brings the whole bundle.
 *
 * The refresh runs it in the Instance container over the checkout's HEAD (instance-fleet-refresh.yml): `deno run host_code.ts <root>`,
 * where `<root>` holds `swamp/` and `apps/gateway/resources/machine-software` of that commit. It prints the digest, or fails when a file
 * cannot be read, so the host is due.
 */
export async function hostCode(root: string): Promise<string> {
    const ansible = "swamp/ansible";
    const operations = "swamp/extensions/behaviors/operations";
    const resolved = await resolvePhases(`${root}/${ansible}`);
    const files = new Set<string>();
    const outside = new Set<string>(roleSiblings.map((sibling) => `../${sibling}`));
    for (const phase of phases) {
        const resolution = resolved[phase];
        for (const file of resolution.whole ? await tree(`${root}/${ansible}`) : resolution.files) files.add(`${ansible}/${file}`);
        for (const path of resolution.outside) outside.add(path);
    }
    // A file above the bundle and the modules it imports above the bundle, by path from the bundle.
    for (const path of outside) {
        const file = `swamp/${path.slice(3)}`;
        files.add(file);
        for (const module of importNames(path, await Deno.readTextFile(`${root}/${file}`))) if (module.startsWith("../")) outside.add(module);
    }
    for (const folder of [`${operations}/tools`, "apps/gateway/resources/machine-software"]) {
        for (const file of await tree(`${root}/${folder}`)) files.add(`${folder}/${file}`);
    }
    files.add(`${operations}/ansible-input/converge_tool.ts`);
    const rows: string[] = [];
    for (const file of [...files].sort()) rows.push(`${file}\0${await sha256(await Deno.readFile(`${root}/${file}`))}`);
    return sha256(new TextEncoder().encode(rows.join("\n")));
}

/** Every file under a folder, by relative path. A missing folder fails. */
async function tree(folder: string, relative = ""): Promise<string[]> {
    const found: string[] = [];
    for await (const entry of Deno.readDir(relative ? `${folder}/${relative}` : folder)) {
        const path = relative ? `${relative}/${entry.name}` : entry.name;
        if (entry.isDirectory && entry.name !== "__pycache__") found.push(...await tree(folder, path));
        else if (entry.isFile) found.push(path);
    }
    return found;
}

async function sha256(bytes: Uint8Array): Promise<string> {
    return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes as BufferSource)), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

if (import.meta.main) console.log(await hostCode(Deno.args[0]));
