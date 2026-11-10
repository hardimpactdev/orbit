# orbit-egress

The egress proxy of an Orbit Node's Instance containers: [ADR 0254](../../docs/decisions/0254-inject-external-secrets-at-the-node-egress-proxy.md) and [Secret bindings](../../docs/reference/development-containers.md#secret-bindings). Go, standard library only; add no dependency without an ADR change.

Run `gofmt -l .`, `go vet ./...` and `go test -race -count=1 ./...` here. Every behaviour has a test in `internal/proxy`. Never log a binding's value.
