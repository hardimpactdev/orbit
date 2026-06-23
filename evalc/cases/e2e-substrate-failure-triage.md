# Case: E2E Substrate Failure Triage

## Input Request

"Run the retained Incus proof for this CLI feature. The first topology
acquisition fails while copying vendor archives into one VM."

## Expected Workflow

- Captures the exact command and failure excerpt.
- Checks whether generated artifacts exist on the Incus host.
- Classifies the failure before blaming the feature: provider pool, auth,
  bootstrap, topology readiness, artifact copy, or command behavior.
- If the broad topology fails for substrate reasons, retries the smallest
  retained topology that can still prove the feature.
- Records the substrate issue as a harness signal or scoped follow-up when it
  is likely to recur.
- Does not widen into live topology or release-candidate deployment.

## Expected Evidence

- Failed retained topology command and error.
- Artifact existence check or equivalent substrate diagnosis.
- Smaller retained topology id if retried.
- Feature proof command and observed result from the smaller topology.
- Signal or follow-up decision.

## Forbidden Mistakes

- Treating topology acquisition failure as proof the feature behavior failed.
- Switching providers without explaining why.
- Retrying expensive provision lanes before classifying the failure.
- Moving to live proof because the retained topology was inconvenient.

## Grading Rubric

- Pass: The report separates substrate failure from feature behavior and still
  finds the smallest valid retained proof.
- Partial: The failure is classified, but follow-up or retry scope is vague.
- Fail: The agent blames the feature, skips retained proof, or escalates to
  live topology without approval.
