// ORBIT_TEST_SLICE="k/n" registers every n-th test, starting at zero.
const value = Deno.env.get("ORBIT_TEST_SLICE") ?? "0/1";
if (!/^\d+\/[1-9]\d*$/.test(value)) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
const [k, n] = value.split("/").map(Number);
if (k >= n) throw new Error(`Invalid ORBIT_TEST_SLICE: ${value}`);
let index = 0;
const register = Deno.test;
// Preserve every overload and route .only/.ignore through the same counter.
Deno.test = Object.assign((...args: Parameters<typeof Deno.test>) => {
  if (index++ % n === k) (register as (...args: unknown[]) => void)(...args);
}, {
  ...register,
  only: (...args: Parameters<typeof Deno.test.only>) => {
    if (index++ % n === k) (register.only as (...args: unknown[]) => void)(...args);
  },
  ignore: (...args: Parameters<typeof Deno.test.ignore>) => {
    if (index++ % n === k) (register.ignore as (...args: unknown[]) => void)(...args);
  },
}) as typeof Deno.test;
