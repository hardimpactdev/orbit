// ORBIT_TEST_SLICE="k/n" registers every n-th test, starting at zero.
const value = Deno.env.get("ORBIT_TEST_SLICE") ?? "0/1";
if (!/^\d+\/[1-9]\d*$/.test(value)) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const [k, n] = value.split("/").map(Number);
if (k >= n) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const skip = new Set<string>(JSON.parse(Deno.env.get("ORBIT_TEST_SKIP") ?? "[]"));
let index = 0;
let first = true;
const register = Deno.test;
// deno-lint-ignore no-explicit-any
type Callback = (...args: any[]) => any;
function timed(fn: Callback): Callback {
  const wrapped = (...args: unknown[]) => {
    const file = Deno.env.get("ORBIT_TEST_TIMING_FILE");
    if (first && file) {
      first = false;
      const started = Number(Deno.env.get("ORBIT_TEST_STARTED_AT_MS"));
      Deno.writeTextFileSync(file, JSON.stringify({ startup_seconds: (Date.now() - started) / 1000 }));
    }
    return fn(...args);
  };
  return Object.defineProperty(wrapped, "name", { value: fn.name });
}
function select(fn: Callback, args: unknown[]) {
  const first = args[0];
  const name = typeof first === "string" ? first : (first as { name: string }).name;
  if (skip.has(name)) return;
  if (index++ % n !== k) return;
  fn(...args.map((arg) => {
    if (typeof arg === "function") return timed(arg as Callback);
    if (arg && typeof arg === "object" && "fn" in arg) {
      return { ...arg, fn: timed(arg.fn as Callback) };
    }
    return arg;
  }));
}
// Preserve every overload and route .only/.ignore through the same counter.
Deno.test = Object.assign((...args: unknown[]) => select(register, args), {
  ...register,
  only: (...args: unknown[]) => select(register.only, args),
  ignore: (...args: unknown[]) => select(register.ignore, args),
}) as typeof Deno.test;
