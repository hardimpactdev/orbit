import { z } from "npm:zod@4";

const LinkSchema = z.strictObject({
    id: z.string(),
    url: z.string().nullable(),
});
export const ChangeSchema = z.strictObject({
    manager: z.enum(["composer", "js"]),
    path: z.string(),
    package: z.string(),
    from: z.string(),
    to: z.string(),
    major: z.boolean(),
    advisories: z.array(LinkSchema),
});
export type Change = z.infer<typeof ChangeSchema>;

export function majorChange(from: string, to: string): boolean {
    const a = /^v?(\d+)\.(\d+)\./.exec(from);
    const b = /^v?(\d+)\.(\d+)\./.exec(to);
    if (!a || !b) return false;
    return +b[1] !== +a[1] || (+a[1] === 0 && +a[2] !== +b[2]);
}
