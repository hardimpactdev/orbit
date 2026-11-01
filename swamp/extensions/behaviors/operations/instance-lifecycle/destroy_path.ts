import { z } from "npm:zod@4.4.3";
export const DestroyPath = z.string().max(4096).refine((value) => {
    const parts = value.split("/");
    return parts[0] === "" && parts.length > 2 &&
        parts.slice(1).every((part) =>
            part !== "" && part !== "." && part !== ".." &&
            !/\p{Cc}/u.test(part)
        );
});
