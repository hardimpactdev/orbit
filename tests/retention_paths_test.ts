import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  assertRetentionTarget,
  RETENTION_EXCLUSIONS,
} from "../bin/retention-paths.ts";

Deno.test("retention refuses native control records, directories and their ancestors", () => {
  assertEquals(RETENTION_EXCLUSIONS, [".swamp/_control"]);
  for (
    const path of [
      ".swamp/_control",
      ".swamp/_control/pending-runs",
      ".swamp/_control/fire-records/record",
      ".swamp/_control/token-secrets/key",
      ".swamp/_control/continuations/child",
      ".swamp/data/../_control/active-runs",
      ".swamp",
      ".",
      "/",
    ]
  ) {
    assertThrows(
      () => assertRetentionTarget("/repo", path),
      Error,
      "control_plane_retention_refused",
    );
  }
  for (
    const path of [
      ".swamp/workflow-runs/old.yaml",
      ".swamp/workflows-evaluated/old.yaml",
      ".swamp/data/type/model/name/1",
      ".swamp/_control-backup",
      ".swamp/outputs/old.yaml",
    ]
  ) assertRetentionTarget("/repo", path);
});
