// Collect registrations before running any fixture body. Costs bind to this inventory.
const value = Deno.env.get("ORBIT_TEST_SLICE") ?? "0/1";
if (!/^\d+\/[1-9]\d*$/.test(value)) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const [k, n] = value.split("/").map(Number);
if (k >= n) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const skip = new Set<string>(JSON.parse(Deno.env.get("ORBIT_TEST_SKIP") ?? "[]"));
const expected: string[] | undefined = JSON.parse(Deno.env.get("ORBIT_TEST_CASES") ?? "null") ?? undefined;
const collect = Deno.env.get("ORBIT_TEST_COLLECT");
const file = Deno.env.get("ORBIT_TEST_TIMING_FILE");
const register = Deno.test;
// deno-lint-ignore no-explicit-any
type Callback = (...args: any[]) => any;
const pending: { register: Callback; args: unknown[]; name: string }[] = [];
const receipt: { startup_seconds?: number; registered: string[]; excluded: string[]; cases: { name: string; status: string; seconds: number; started_at_ms?: number }[] } = {
  registered: [], excluded: [], cases: [],
};
function save() {
  if (file) Deno.writeTextFileSync(file, JSON.stringify(receipt));
}
function timed(fn: Callback, row: typeof receipt.cases[number]): Callback {
  const wrapped = async (...args: unknown[]) => {
    if (receipt.startup_seconds === undefined) {
      receipt.startup_seconds = (Date.now() - Number(Deno.env.get("ORBIT_TEST_STARTED_AT_MS"))) / 1000;
    }
    row.status = "running";
    row.started_at_ms = Date.now();
    const started = performance.now();
    save();
    try {
      const result = await fn(...args);
      row.status = "passed";
      return result;
    } catch (error) {
      row.status = "FAILED";
      throw error;
    } finally {
      row.seconds = (performance.now() - started) / 1000;
      save();
    }
  };
  return Object.defineProperty(wrapped, "name", { value: fn.name });
}
function select(register: Callback, args: unknown[]) {
  const first = args[0];
  const name = typeof first === "string" ? first : typeof first === "function" ? first.name : (first as { name: string; fn: Callback }).name ?? (first as { fn: Callback }).fn.name;
  pending.push({ register, args, name });
}
Object.defineProperty(Deno, "test", { value: Object.assign((...args: unknown[]) => select(register, args), {
  ...register,
  only: (...args: unknown[]) => select(register.only, args),
  ignore: (...args: unknown[]) => select(register.ignore, args),
}) as typeof Deno.test, writable: true, configurable: true });

addEventListener("load", () => {
  receipt.registered = pending.map((test) => test.name);
  if (collect) {
    Deno.writeTextFileSync(collect, JSON.stringify(receipt.registered));
    return;
  }
  receipt.excluded = receipt.registered.filter((name) => skip.has(name));
  save();
  const stale = [...skip].filter((name) => !receipt.registered.includes(name));
  if (stale.length) throw new Error(`Stale after_merge_cases: ${JSON.stringify(stale)}`);
  if (!pending.length) throw new Error("Fixture registered zero tests");
  if (expected && JSON.stringify(expected) !== JSON.stringify(receipt.registered)) {
    throw new Error(`Stale fixture case population: expected ${expected.length}, registered ${pending.length}; actual names: ${JSON.stringify(receipt.registered)}`);
  }
  const selected = pending.filter((test) => !skip.has(test.name)).filter((_, index) => index % n === k);
  if (!selected.length) throw new Error(`Fixture slice ${value} registered zero tests after exclusions`);
  for (const test of selected) {
    const options = test.args[0];
    const ignored = test.register === register.ignore || typeof options === "object" && options && "ignore" in options && options.ignore;
    const row = { name: test.name, status: ignored ? "ignored" : "NOT RUN", seconds: 0 };
    receipt.cases.push(row);
    test.register(...test.args.map((arg) => {
      if (typeof arg === "function") return timed(arg as Callback, row);
      if (arg && typeof arg === "object" && "fn" in arg) return { ...arg, fn: timed(arg.fn as Callback, row) };
      return arg;
    }));
  }
  save();
});
