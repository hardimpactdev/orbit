# Automatic deployment ownership

Swamp owns production automatic-deploy policy only. Its policy.ts and production helpers, execution, persisted pause and schedule identity are unchanged. The Gateway owns development selection, exact-head green checks, retry accounting and admission of ordinary instance.deploy. Development uses no behavior HTTP calls, native policy schedule or development-* checkpoints.

Development auto-deploy remains disabled during cutover. Follow docs/solutions/development-auto-deploy-cutover.md before any later authorized activation. A deployment effect is still the existing native instance.deploy workflow. The Gateway records its terminal facts; it executes no Node work.
