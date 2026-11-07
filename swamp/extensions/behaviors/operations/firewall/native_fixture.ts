/** A ufw double: numbered status, add, insert and delete; `from any` has IPv4 and IPv6 lines, and a line from one IPv6 address shows the address alone. */
import type { Row } from "./plan.ts";

export const Trust: Row = {
    to: "Anywhere on orbit",
    action: "allow",
    source: "10.44.0.0/24",
    comment: "orbit:wireguard-members",
};
export const Trust6: Row = {
    to: "22/tcp (v6)",
    action: "allow",
    source: "Anywhere (v6)",
    comment: "orbit:public-ssh-recovery",
};

export class UfwDouble {
    rows: Row[];
    active = true;
    fail = false;
    /** Ignore insert positions, as a racing writer would. */
    misplace = false;
    /** How many times `status` was read: the workflow reads ufw once, and a second time only after changes. */
    reads = 0;

    constructor(rows: Row[] = [Trust, Trust6]) {
        this.rows = structuredClone(rows);
    }

    status() {
        this.reads++;
        return `Status: ${
            this.active ? "active" : "inactive"
        }\n\n     To  Action  From\n` +
            this.rows.map((row, index) =>
                `[${
                    index + 1
                }] ${row.to} ${row.action.toUpperCase()} IN ${row.source}` +
                (row.comment ? ` # ${row.comment}` : "")
            ).join("\n");
    }

    /** Runs one planned argv: `timeout 60 /usr/sbin/ufw …`. */
    apply(argv: string[]) {
        if (argv.slice(0, 3).join(" ") !== "timeout 60 /usr/sbin/ufw") {
            throw new Error(`unexpected ${argv.join(" ")}`);
        }
        if (this.fail) throw new Error("firewall.apply_failed");
        const args = argv.slice(3);
        const remove = args[0] === "--force" && args[1] === "delete";
        const line = args[0] === "insert" && !this.misplace
            ? Number(args[1])
            : null;
        const rule = remove || args[0] === "insert" ? args.slice(2) : args;
        const [action, , , protocol, , source, , , , port] = rule;
        const comment = rule.includes("comment")
            ? rule[rule.indexOf("comment") + 1]
            : "";
        const families = ({
            any: [false, true],
            "0.0.0.0/0": [false],
            "::/0": [true],
        } as Record<string, boolean[]>)[source] ??
            [source.includes(":")];
        for (const six of families) {
            const anywhere = ["any", "0.0.0.0/0", "::/0"].includes(source);
            const shown = anywhere ? "Anywhere" : source;
            const row = {
                to: `${port}/${protocol}${six && anywhere ? " (v6)" : ""}`,
                action,
                source: shown + (six && anywhere ? " (v6)" : ""),
                comment,
            };
            const same = this.rows.filter((old) =>
                old.to === row.to && old.source === row.source
            );
            if (remove) {
                this.rows = this.rows.filter((old) =>
                    !(old.to === row.to && old.source === row.source &&
                        old.action === action)
                );
            } else if (same.length) {
                Object.assign(same[0], { action, comment });
            } else {
                const first6 = this.rows.findIndex((old) =>
                    (old.to + old.source).includes("(v6)") ||
                    old.source.includes(":")
                );
                this.rows.splice(
                    line
                        ? line - 1
                        : six || first6 < 0
                        ? this.rows.length
                        : first6,
                    0,
                    row,
                );
            }
        }
    }

    tuples(port?: string) {
        return this.rows.filter((row) =>
            port === undefined || row.to.split("/")[0] === port
        )
            .map((row) => [row.to, row.action, row.source]);
    }
}
