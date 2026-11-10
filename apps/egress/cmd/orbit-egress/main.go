// orbit-egress is the egress proxy of an Orbit Node's Instance containers (docs/decisions/0254-inject-external-secrets-at-the-node-egress-proxy.md).
//
//	orbit-egress serve --config /etc/orbit/egress/config.json
//	orbit-egress ca --config /etc/orbit/egress/config.json --container instance-12
//
// `ca` makes the container's CA when it is missing and prints its certificate, which the container installs.
package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	"github.com/hardimpactdev/orbit/apps/egress/internal/proxy"
)

func main() {
	if len(os.Args) < 2 {
		usage()
	}
	flags := flag.NewFlagSet(os.Args[1], flag.ExitOnError)
	configPath := flags.String("config", "/etc/orbit/egress/config.json", "the Node's proxy configuration")
	container := flags.String("container", "", "the container, for ca")
	flags.Parse(os.Args[2:])
	config, err := proxy.LoadConfig(*configPath)
	if err != nil {
		fail(err)
	}
	switch os.Args[1] {
	case "serve":
		ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
		defer stop()
		store := proxy.NewStore(config.Containers)
		store.Report = func(err error) { fmt.Fprintln(os.Stderr, "orbit-egress: containers:", err) }
		p := &proxy.Proxy{
			Config:   config,
			Store:    store,
			Resolver: proxy.DNSResolver{Server: config.Resolver},
			Floor:    proxy.NodeFloor(),
			Log:      os.Stdout,
		}
		if err := p.Serve(ctx); err != nil {
			fail(err)
		}
	case "ca":
		ca, err := proxy.EnsureCA(config.State, *container)
		if err != nil {
			fail(err)
		}
		os.Stdout.Write(ca.PEM())
	default:
		usage()
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, "usage: orbit-egress serve|ca --config FILE [--container NAME]")
	os.Exit(2)
}

func fail(err error) {
	fmt.Fprintln(os.Stderr, "orbit-egress:", err)
	os.Exit(1)
}
