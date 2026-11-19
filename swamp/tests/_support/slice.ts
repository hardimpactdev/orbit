// Collect registrations before running any fixture body. Costs bind to this inventory.
const value = Deno.env.get("ORBIT_TEST_SLICE") ?? "0/1";
if (!/^\d+\/[1-9]\d*$/.test(value)) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const [k, n] = value.split("/").map(Number);
if (k >= n) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const expected: string[] | undefined = JSON.parse(Deno.env.get("ORBIT_TEST_CASES") ?? "null") ?? undefined;
const collect = Deno.env.get("ORBIT_TEST_COLLECT");
const file = Deno.env.get("ORBIT_TEST_TIMING_FILE");
const register = Deno.test;
// deno-lint-ignore no-explicit-any
type Callback = (...args: any[]) => any;
const pending: { register: Callback; args: unknown[]; name: string }[] = [];
const receipt: { startup_seconds?: number; registered: string[]; cases: { name: string; status: string; seconds: number; started_at_ms?: number; failed_step?: boolean }[] } = {
  registered: [], cases: [],
};
function save() {
  if (file) Deno.writeTextFileSync(file, JSON.stringify(receipt));
}
// A failed step does not end its case: the case can go on and hang. The receipt records the failed step at once, so a
// unit that the runner cuts later still counts as failed. Steps of steps and `parent` are followed too. A context has
// one wrapper, so `inner.parent === t` holds as it does without the wrapper.
const wrappers = new WeakMap<object, unknown>();
function stepped(context: unknown, row: typeof receipt.cases[number]): unknown {
  if (!context || typeof context !== "object" || typeof (context as { step?: unknown }).step !== "function") return context;
  const known = wrappers.get(context);
  if (known) return known;
  // Deno requires a named step function, so the wrapper keeps the name.
  const inside = (fn: Callback) => Object.defineProperty((inner: unknown) => fn(stepped(inner, row)), "name", { value: fn.name });
  const follow = (arg: unknown) => {
    if (typeof arg === "function") return inside(arg as Callback);
    if (arg && typeof arg === "object" && "fn" in arg) return { ...arg, fn: inside(arg.fn as Callback) };
    return arg;
  };
  const wrapper = new Proxy(context, {
    get(target, property) {
      const value = Reflect.get(target, property);
      if (property === "parent") return stepped(value, row);
      if (property !== "step") return typeof value === "function" ? value.bind(target) : value;
      return async (...args: unknown[]) => {
        // An ignored step also returns false, and no assertion ran in it. Read the flag as Deno gets it, before the
        // step runs: the caller may change the definition afterwards.
        const ignored = args.some((arg) => arg && typeof arg === "object" && "ignore" in arg && arg.ignore);
        const passed = await (value as Callback).apply(target, args.map(follow));
        if (!passed && !ignored) {
          row.failed_step = true;
          save();
        }
        return passed;
      };
    },
  });
  wrappers.set(context, wrapper);
  return wrapper;
}
function timed(fn: Callback, row: typeof receipt.cases[number]): Callback {
  const wrapped = async (...args: unknown[]) => {
    args = [stepped(args[0], row), ...args.slice(1)];
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
  save();
  if (!pending.length) throw new Error("Fixture registered zero tests");
  if (expected && JSON.stringify(expected) !== JSON.stringify(receipt.registered)) {
    throw new Error(`Stale fixture case population: expected ${expected.length}, registered ${pending.length}; actual names: ${JSON.stringify(receipt.registered)}`);
  }
  const selected = pending.filter((_, index) => index % n === k);
  if (!selected.length) throw new Error(`Fixture slice ${value} registered zero tests`);
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
