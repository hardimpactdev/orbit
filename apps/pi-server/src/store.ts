import { mkdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/;
export function isValidSessionId(id: string): boolean {
    return ID_PATTERN.test(id);
}

export interface SessionConfig {
    cwd: string;
    model: string;
    thinkingLevel: string;
    appendSystemPrompt: string | null;
}

/** Bootstrap settings only. Durable owns submissions, run state, and transcripts. */
export interface SessionRecord {
    config: SessionConfig;
}

export class SessionStore {
    private readonly directory: string;
    private readonly agentDir: string;

    constructor(directory: string, agentDir: string) {
        this.directory = directory;
        this.agentDir = agentDir;
        mkdirSync(directory, { recursive: true });
        mkdirSync(join(agentDir, "durable"), { recursive: true });
    }

    read(id: string): SessionRecord | undefined {
        try {
            return JSON.parse(readFileSync(this.recordPath(id), "utf8")) as SessionRecord;
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code === "ENOENT") return undefined;
            throw error;
        }
    }

    write(id: string, record: SessionRecord): void {
        const path = this.recordPath(id);
        const temporary = `${path}.${process.pid}.tmp`;
        writeFileSync(temporary, JSON.stringify(record));
        renameSync(temporary, path);
    }

    databasePath(id: string): string {
        return join(this.agentDir, "durable", `${id}.sqlite`);
    }

    private recordPath(id: string): string {
        return join(this.directory, `${id}.orbit.json`);
    }
}
