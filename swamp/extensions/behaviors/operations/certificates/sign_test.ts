import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior, sign } from "./sign.ts";

const LEAF = "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----\n";
const ROOT = "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n";
const CSR =
    "-----BEGIN CERTIFICATE REQUEST-----\npublic\n-----END CERTIFICATE REQUEST-----\n";

async function fixture(status = 200) {
    const caFile = await Deno.makeTempFile();
    await Deno.writeTextFile(caFile, "orbit-root");
    const calls: { url: string; init: RequestInit }[] = [];
    const cas: string[] = [];
    let closed = 0;
    const send = ((url: URL, init: RequestInit) => {
        calls.push({ url: url.toString(), init });
        return Promise.resolve(
            new Response(
                JSON.stringify({
                    data: {
                        certificates: [{
                            node_id: 9,
                            scope: "route-7",
                            certificate_base64: btoa(LEAF),
                            root_certificate_base64: btoa(ROOT),
                        }],
                    },
                }),
                { status },
            ),
        );
    }) as typeof fetch;
    const connect = (ca: string) => {
        cas.push(ca);
        return { close: () => closed++ } as unknown as Deno.HttpClient;
    };
    const ctx = {
        globalArgs: { gatewayUrl: "https://gateway.orbit", caFile },
        writeResource: () => Promise.resolve({}),
    };
    const args = {
        operation_id: 19,
        run_id: "run-1",
        node_id: 9,
        scope: "route-7",
        request: CSR,
        restoring: true,
    };
    return { ctx, args, send, connect, calls, cas, closed: () => closed };
}

Deno.test("one call to the sign primitive returns the leaf then the root", async () => {
    const f = await fixture();
    const result = await sign(f.ctx, f.args, f.send, f.connect);
    assertEquals(result, { stdout: LEAF + ROOT });
    assertEquals(f.cas, ["orbit-root"]);
    assertEquals(f.closed(), 1);
    assertEquals(f.calls.length, 1);
    assertEquals(
        f.calls[0].url,
        "https://gateway.orbit/api/v1/direct-operations/19/certificates/sign",
    );
    assertEquals(f.calls[0].init.method, "POST");
    assertEquals(f.calls[0].init.redirect, "error");
    assertEquals(JSON.parse(f.calls[0].init.body as string), {
        run_id: "run-1",
        requests: [{
            node_id: 9,
            scope: "route-7",
            state: "request",
            request_base64: btoa(CSR),
        }],
        restoring: true,
    });
});

Deno.test("a refusal fails once, without retry", async () => {
    const f = await fixture(403);
    await assertRejects(
        () => sign(f.ctx, f.args, f.send, f.connect),
        Error,
        "app-dev.certificate_sign_failed",
    );
    assertEquals(f.calls.length, 1);
    assertEquals(f.closed(), 1);
});

Deno.test("a reply without this leaf fails", async () => {
    const f = await fixture();
    await assertRejects(
        () => sign(f.ctx, { ...f.args, scope: "route-8" }, f.send, f.connect),
        Error,
        "app-dev.certificate_sign_failed",
    );
});

Deno.test("the method writes the PEM pair as its result resource", async () => {
    const written: unknown[] = [];
    const caFile = await Deno.makeTempFile();
    await Deno.writeTextFile(caFile, "orbit-root");
    const original = globalThis.fetch;
    globalThis.fetch = (await fixture()).send;
    try {
        const out = await behavior.methods.sign.execute(
            {
                operation_id: 19,
                run_id: "run-1",
                node_id: 9,
                scope: "route-7",
                request: CSR,
                restoring: false,
            },
            {
                globalArgs: { gatewayUrl: "https://127.0.0.1:1", caFile },
                writeResource: (...args: unknown[]) => {
                    written.push(args);
                    return Promise.resolve("handle");
                },
            },
        );
        assertEquals(Object.keys(behavior.resources), ["result"]);
        assertEquals(out, { dataHandles: ["handle"] });
        assertEquals(written, [["result", "sign", { stdout: LEAF + ROOT }]]);
    } finally {
        globalThis.fetch = original;
    }
});
