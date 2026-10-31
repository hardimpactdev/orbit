# Native local Node commands

`extensions/behaviors/operations/_lib/node-commands.ts::run(command, stdin, as_user, options?)` implements the merged `node-worker/command.ts` contract. A native parent can pass it as the required third argument to `configureNodeWorker`.

Commands and arguments come from reviewed source. Private bytes use stdin. The account is `node`, `root`, or a fixed admitted user. The runner uses no shell, caller environment, SSH, key, credential API, or Gateway identity routing. It refuses orbit-agent action invocations.

On Linux, a bounded fixed `cat /proc/self/cgroup` subprocess checks worker membership, because the compiled native runtime forbids direct `/proc` reads. The local runner refuses every `orbit-node-proxy-worker-N.service` cgroup before effects. It never runs a proxy Node's work on209. Logical Node1/18 workflow targets remain exact and wait for separately approved proxy execution.

The process helper drains stdin and stdout concurrently, preserves nonzero exit status, hides stderr, bounds output, and propagates cancellation or deadline failure without exposing private bytes. No retry or result projection belongs in this helper.

Families that consumed draft proxy plumbing must remove its `node-proxy.ts` imports and Gateway HTTP forwarding before shipping only this local runner. Do not treat the draft proxy transport or key setup as approved source. Proxy credential and execution review remains separate.
