import type { ProxyRuntime } from "./proxy-runtime.ts";

export const runtimeFixture: ProxyRuntime = {
  contract: "running209-artifact-before-proxy-effects-v1",
  node_id: 209,
  user: "orbit",
  pid: 123,
  invocation: "a".repeat(32),
  sha256: "b".repeat(64),
  bytes: 128,
  device: 1,
  inode: 2,
  unit_sha256: "c".repeat(64),
};
