import { z } from "npm:zod@4";
export const behavior = {
  type: "@fixture/native-leaf",
  version: "2026.10.29.1",
  globalArguments: z.object({}),
  methods: {
    probe: {
      description: "Native leaf",
      arguments: z.object({}),
      execute: () => ({ dataHandles: [] }),
    },
  },
};
