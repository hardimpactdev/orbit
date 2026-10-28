import { FailureFixture } from "./failure.ts";
import { productionSmoke } from "../../../extensions/behaviors/production-smoke/mod.ts";
import { Repair } from "../../../extensions/behaviors/production-smoke/repair.ts";
import { type z } from "npm:zod@4.4.3";
export class RepairFixture extends FailureFixture {
  repairs = new Map<string, z.infer<typeof Repair>>();
  factory = new Map<string, Record<string, unknown>>();
  starts = 0;
  repairLostBefore = false;
  repairLostAfter = false;
  lostFactory = false;
  crashAfterReceipt = false;
  refused = false;
  badLookup = false;
  badGroup = false;
  groupStatus = "todo";
  queryFactory: ((predicate: string) => Promise<unknown[]>) | undefined;
  startFactory: ((workItem: string) => Promise<void>) | undefined;
  constructor() {
    super();
    const base = this.http;
    this.http = async (method, path, body, key) => {
      if (!path.includes("/repair") && !path.includes("/task-groups/")) {
        return await base(method, path, body, key);
      }
      this.requests.push({ method, path, body, key });
      if (path.includes("/task-groups/")) {
        return {
          status: 200,
          body: {
            data: {
              id: this.badGroup ? 999 : 91,
              project_id: 20,
              orchestration: "external",
              status: this.groupStatus,
              tasks: [{ id: 92, parent_id: 91 }],
            },
          },
        };
      }
      const request_key = key ??
        new URL("https://fixture" + path).searchParams.get("request_key")!;
      if (method === "GET") {
        return {
          status: 200,
          body: {
            data: this.badLookup
              ? { request_key, found: true, repair: null }
              : {
                request_key,
                found: this.repairs.has(request_key),
                repair: this.repairs.get(request_key) ?? null,
              },
          },
        };
      }
      if (this.refused) {
        return { status: 409, body: { error: { code: "extension.disabled" } } };
      }
      if (this.repairLostBefore) {
        throw new Error("Lost before repair admission");
      }
      if (!this.repairs.has(request_key)) {
        this.repairs.set(
          request_key,
          Repair.parse({
            request_key,
            instance_id: 7,
            project_id: 20,
            failure_key: (body as { failure_key: string }).failure_key,
            group_id: 91,
            task_id: 92,
          }),
        );
      }
      if (this.repairLostAfter) throw new Error("Lost after repair admission");
      return { status: 201, body: { data: this.repairs.get(request_key) } };
    };
  }
  override context() {
    const base = super.context();
    return {
      ...base,
      queryData: async (predicate = "") => {
        if (predicate.includes('name.startsWith("repair-")')) {
          return [...this.stored.entries()].filter(([name, row]) =>
            name.startsWith("repair-") &&
            !["started", "completed"].includes(String(row.status))
          ).map(([, row]) => row);
        }
        if (predicate.includes('modelName == "orbit-groups"')) {
          return this.queryFactory
            ? await this.queryFactory(predicate)
            : [...this.factory.values()];
        }
        return await base.queryData(predicate);
      },
      runModel: async (
        args?: {
          modelType: string;
          name: string;
          method: string;
          arguments?: Record<string, unknown>;
        },
      ) => {
        if (args?.modelType !== "@swamp/software-factory") return { ok: true };
        this.starts++;
        const workItem = String(args.arguments?.workItem);
        if (this.startFactory) await this.startFactory(workItem);
        else this.factory.set(workItem, { workItem, status: "active" });
        return { ok: !this.lostFactory };
      },
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        if (this.crashAfterReceipt && spec === "repair" && value.repair) {
          throw new Error("Fixture process died before receipt persistence");
        }
        return await base.writeResource(spec, name, value);
      },
    };
  }
  async repair(apply = true, effects = true, deploymentId?: number) {
    await productionSmoke(() => this.http, () => this.now, 120_000, effects)
      .methods.repair.execute({ apply, deploymentId }, this.context());
    return this.stored.get("repair")!;
  }
  get repairPosts() {
    return this.posts.filter((row) => row.path.endsWith("/repair"));
  }
}
