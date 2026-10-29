import { z } from "npm:zod@4.4.3";
import { Ajv2020 as Ajv } from "npm:ajv@8.17.1/dist/2020.js";

const Name = z.string().regex(/^[a-z][a-z0-9_-]*$/);
const SymbolName = z.string().regex(/^[a-z][a-z0-9_.-]*$/);
const Kind = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/);
const Path = z.string().regex(
  /^admitted\.[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/,
);
const JsonSchema = z.record(z.string(), z.json());
const Names = z.array(Name);
const Vault = z.enum([
  "orbit-control-inputs",
  "orbit-node-access",
  "orbit-control-outputs",
]);

// This is Orbit metadata. The native workflow is validated with the schema
// returned by the pinned Swamp binary, rather than a second DAG implementation.
export const Family = z.strictObject({
  schema: z.literal("orbit.family/v1"),
  family: Name,
  kind: Kind,
  actions: Names.nonempty(),
  input: JsonSchema,
  target: z.strictObject({ type: Name, node: Path, owner: Path }),
  workflow: z.record(z.string(), z.json()),
  commands: z.record(
    SymbolName,
    z.strictObject({
      transport: z.literal("orbit-agent"),
      names: z.array(z.string().regex(/^[a-z][a-z0-9_.:-]*$/)).nonempty(),
    }),
  ),
  steps: z.array(z.strictObject({
    key: Name,
    job: Name,
    step: Name,
    command: SymbolName.optional(),
    effect: z.enum(["read", "keyed"]),
  })).nonempty(),
  secrets: z.record(
    Name,
    z.strictObject({
      vault: Vault,
      key: Path,
      purpose: z.enum(["input", "node-access", "output"]),
    }),
  ),
  children: z.record(
    Name,
    z.strictObject({
      kind: Kind,
      node: Path,
      input: z.record(Name, Path),
    }),
  ),
  done_when: z.strictObject({
    workflow: z.literal("succeeded"),
    receipts: Names.nonempty(),
  }),
  result: z.strictObject({
    schema: JsonSchema,
    max_bytes: z.number().int().positive().max(1_048_576),
  }),
  projection: SymbolName,
  invariants: z.array(SymbolName),
  cancel: z.strictObject({
    mode: z.literal("cooperative"),
    boundary: Name,
  }),
  health: z.strictObject({
    unclaimed_seconds: z.number().int().positive(),
    stalled_seconds: z.number().int().positive(),
  }),
});
export type FamilySpec = z.infer<typeof Family>;

export function canonical(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
  if (value !== null && typeof value === "object") {
    const record = value as Record<string, unknown>;
    return `{${
      Object.keys(record).sort().map((key) =>
        `${JSON.stringify(key)}:${canonical(record[key])}`
      ).join(",")
    }}`;
  }
  const encoded = JSON.stringify(value);
  if (encoded === undefined) throw new Error("Not JSON");
  return encoded;
}

export async function digest(value: unknown): Promise<string> {
  const bytes = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(canonical(value)),
  );
  return Array.from(
    new Uint8Array(bytes),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
}

function unique(values: string[], field: string): void {
  if (new Set(values).size !== values.length) {
    throw new Error(`Duplicate ${field}`);
  }
}

function checkDependencies(
  nodes: { name: string; dependencies: string[] }[],
): void {
  const byName = new Map(nodes.map((node) => [node.name, node]));
  const active = new Set<string>();
  const done = new Set<string>();
  const visit = (name: string) => {
    if (active.has(name)) throw new Error("Cyclic native dependency");
    if (done.has(name)) return;
    const node = byName.get(name);
    if (!node) throw new Error("Unknown native dependency");
    active.add(name);
    node.dependencies.forEach(visit);
    active.delete(name);
    done.add(name);
  };
  nodes.forEach((node) => visit(node.name));
}

export async function compileFamily(raw: unknown, nativeSchema: object) {
  const spec = Family.parse(raw);
  const ajv = new Ajv({
    strict: false,
    allErrors: true,
    validateFormats: false,
    useDefaults: true,
  });
  const native = ajv.compile(nativeSchema);
  if (!native(spec.workflow)) throw new Error("Invalid native Swamp workflow");
  for (
    const [name, schema] of [
      ["input", spec.input],
      ["result", spec.result.schema],
    ] as const
  ) {
    if (schema.type !== "object" || schema.additionalProperties !== false) {
      throw new Error(`${name} must be a closed object schema`);
    }
    // Reject invalid schemas and unsupported/misspelled JSON Schema keywords.
    new Ajv({ strict: true, validateFormats: false }).compile(schema);
  }
  const input = spec.input.properties as Record<
    string,
    Record<string, unknown>
  >;
  if (canonical(input?.action?.enum) !== canonical(spec.actions)) {
    throw new Error("input.action.enum must equal actions");
  }
  if (!(spec.input.required as unknown[] | undefined)?.includes("action")) {
    throw new Error("action must be required");
  }
  unique(spec.actions, "actions");
  unique(spec.invariants, "invariants");
  unique(spec.steps.map((step) => step.key), "receipt keys");
  unique(spec.steps.map((step) => `${step.job}/${step.step}`), "step bindings");
  unique(spec.done_when.receipts, "completion receipts");
  const jobs = spec.workflow.jobs as {
    name: string;
    dependsOn: { job: string }[];
    steps: {
      name: string;
      allowFailure?: boolean;
      dependsOn: { step: string }[];
    }[];
  }[];
  unique(jobs.map((job) => job.name), "native jobs");
  checkDependencies(
    jobs.map((job) => ({
      name: job.name,
      dependencies: job.dependsOn.map((dep) => dep.job),
    })),
  );
  for (const job of jobs) {
    unique(job.steps.map((step) => step.name), "native steps");
    checkDependencies(
      job.steps.map((step) => ({
        name: step.name,
        dependencies: step.dependsOn.map((dep) => dep.step),
      })),
    );
  }
  const keys = new Set(spec.steps.map((step) => step.key));
  if (!keys.has(spec.cancel.boundary)) {
    throw new Error("Unknown cancel boundary");
  }
  for (const key of spec.done_when.receipts) {
    if (!keys.has(key)) throw new Error("Unknown completion receipt");
  }
  for (const step of spec.steps) {
    const nativeStep = jobs.find((job) => job.name === step.job)?.steps.find(
      (candidate) => candidate.name === step.step,
    );
    if (!nativeStep) throw new Error(`Unknown native step: ${step.key}`);
    if (step.command && !Object.hasOwn(spec.commands, step.command)) {
      throw new Error(`Unknown command catalogue: ${step.key}`);
    }
    if (step.effect === "keyed" && !step.command) {
      throw new Error("Keyed effects require a fixed command catalogue");
    }
    if (spec.done_when.receipts.includes(step.key) && nativeStep.allowFailure) {
      throw new Error("Completion receipts cannot allow failure");
    }
  }
  const vaultFor = {
    input: "orbit-control-inputs",
    "node-access": "orbit-node-access",
    output: "orbit-control-outputs",
  };
  for (const secret of Object.values(spec.secrets)) {
    if (vaultFor[secret.purpose] !== secret.vault) {
      throw new Error("Secret purpose does not match vault");
    }
  }
  for (const child of Object.values(spec.children)) {
    if (child.node === spec.target.node) {
      throw new Error("Use native steps for work on the same Node");
    }
  }
  const { workflow, ...contract } = spec;
  const specDigest = await digest(spec);
  return {
    workflow,
    contract: {
      ...contract,
      schema: "orbit.family-contract/v1",
      spec_digest: specDigest,
      workflow: {
        id: workflow.id,
        name: workflow.name,
        digest: await digest(workflow),
      },
    },
  };
}
