const read = async () =>
    JSON.parse(await Deno.readTextFile("role-certificates.json"));
const record = (value: unknown) =>
    Deno.writeTextFile(
        "role-certificates.ndjson",
        JSON.stringify(value) + "\n",
        {
            append: true,
        },
    );
export async function fixtureCertificateHttp(
    method: string,
    _path: string,
    body?: unknown,
) {
    await record({ method, ...(method === "POST" ? { body } : {}) });
    return { status: 200, body: { data: (await read()).operation } };
}
