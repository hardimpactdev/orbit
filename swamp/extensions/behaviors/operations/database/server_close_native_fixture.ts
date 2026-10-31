import type { Http } from "../../automatic-deploy/gateway.ts";
const file = "private-user-fixture.json";
async function state() {
    return JSON.parse(await Deno.readTextFile(file));
}
export const fixtureHttp: Http = async (method, path, body) => {
    const value = await state();
    value.calls.push(path);
    await Deno.writeTextFile(file, JSON.stringify(value));
    if (path.includes("/private-material?")) {
        return { status: 200, body: { data: value.material } };
    }
    if (method === "GET") {
        return { status: 200, body: { data: value.operation } };
    }
    const projection = body as Record<string, unknown>;
    if (value.loseProjection && projection.status === "succeeded") {
        value.loseProjection = false;
        await Deno.writeTextFile(file, JSON.stringify(value));
        throw new Error("Lost terminal projection before commit");
    }
    value.operation.status = projection.status;
    value.operation.result = projection.result ?? null;
    await Deno.writeTextFile(file, JSON.stringify(value));
    return { status: 200, body: { data: value.operation } };
};

export function fixtureConnect() {
    return Promise.resolve({
        async command(...args: string[]) {
            if (args[0] === "AUTH") return "OK";
            if (args[0] === "ACL" && args[1] === "GETUSER") {
                return ["flags", ["on", "nopass"]];
            }
            if (args[0] === "CLIENT" && args[1] === "ID") return 17;
            if (args[0] === "CLIENT" && args[1] === "LIST") return "id=17\n";
            if (args[0] === "ACL" && args[1] === "SETUSER") {
                const value = JSON.parse(await Deno.readTextFile(file));
                value.effects++;
                await Deno.writeTextFile(file, JSON.stringify(value));
                if (value.failEffect) throw new Error(value.material.password);
            }
            return "OK";
        },
        close() {},
    });
}
