import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { withTaskCancellation } from "./cancellation.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
Deno.test("native Tasks observes Gateway cancellation while its own effect runs", async () => {
    let reads = 0;
    await assertRejects(() =>
        withTaskCancellation(
            {
                read: () => {
                    reads++;
                    return Promise.resolve({ cancel_requested: true });
                },
            },
            (signal) =>
                new Promise((_, reject) =>
                    signal.addEventListener(
                        "abort",
                        () =>
                            reject(
                                new DirectOperationCancelled({
                                    effectRunning: false,
                                }),
                            ),
                        { once: true },
                    )
                ),
            undefined,
            () => Promise.resolve(),
        ), DirectOperationCancelled);
    assertEquals(reads, 1);
});
Deno.test("an unreadable cancellation record never becomes a confirmed cancellation", async () => {
    await assertRejects(
        () =>
            withTaskCancellation(
                { read: () => Promise.reject(new Error("offline")) },
                (signal) =>
                    new Promise((_, reject) =>
                        signal.addEventListener("abort", () =>
                            reject(
                                new DirectOperationCancelled({
                                    effectRunning: false,
                                }),
                            ), {
                            once: true,
                        })
                    ),
                undefined,
                () => Promise.resolve(),
            ),
        Error,
        "observation unavailable",
    );
});
