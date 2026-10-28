import { IndexSchema } from "./dependency_results_schema.ts";

export interface RetentionContext {
  modelType: unknown;
  modelId: string;
  dataRepository: {
    findAllForModel(
      type: unknown,
      id: string,
    ): Promise<{ name: string; version: number; createdAt: Date }[]>;
    listVersions(type: unknown, id: string, name: string): Promise<number[]>;
    findByName(
      type: unknown,
      id: string,
      name: string,
      version: number,
    ): Promise<{ createdAt: Date } | null>;
    getContent(
      type: unknown,
      id: string,
      name: string,
      version: number,
    ): Promise<Uint8Array | null>;
    delete(
      type: unknown,
      id: string,
      name: string,
      version?: number,
    ): Promise<void>;
  };
}
/** Native APIs, under the gateway's model lock. Never prune a referenced exact report. */
export async function pruneDependencyResults(
  ctx: RetentionContext,
  now = Date.now(),
) {
  const repo = ctx.dataRepository,
    type = ctx.modelType,
    id = ctx.modelId,
    cutoff = now - 7 * 86400000;
  const latest = await repo.findAllForModel(type, id),
    references = new Set<string>();
  for (
    const index of latest.filter((d) =>
      d.name.startsWith("dependencies-v1-project-")
    )
  ) {
    for (const version of await repo.listVersions(type, id, index.name)) {
      const meta = await repo.findByName(type, id, index.name, version);
      if (!meta) throw new Error("retention_index_version_missing");
      if (version !== index.version && meta.createdAt.getTime() < cutoff) {
        await repo.delete(type, id, index.name, version);
        continue;
      }
      const bytes = await repo.getContent(type, id, index.name, version);
      if (!bytes) throw new Error("retention_index_unavailable");
      const value = IndexSchema.parse(
        JSON.parse(new TextDecoder().decode(bytes)),
      );
      for (const s of value.sources) {
        if (s.report) {
          references.add(`${s.report.dataName}:${s.report.version}`);
        }
      }
    }
  }
  for (
    const report of latest.filter((d) =>
      d.name.startsWith("dependencies-v1-report-")
    )
  ) {
    for (const version of await repo.listVersions(type, id, report.name)) {
      if (references.has(`${report.name}:${version}`)) continue;
      const meta = await repo.findByName(type, id, report.name, version);
      if (!meta) throw new Error("retention_report_version_missing");
      if (meta.createdAt.getTime() < cutoff) {
        await repo.delete(type, id, report.name, version);
      }
    }
  }
}
