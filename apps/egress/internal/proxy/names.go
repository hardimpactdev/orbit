package proxy

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"strings"
	"time"
)

// Refusal is a request the proxy refuses, with the status it answers and the reason it records.
type Refusal struct {
	Status int
	Reason string
}

func (r *Refusal) Error() string { return r.Reason }

func refuse(status int, reason string) *Refusal { return &Refusal{Status: status, Reason: reason} }

// normalizeHost lowercases a host name, drops one trailing dot, and accepts only letters, digits, `-`, `_` and `.` with
// no empty label, or an IPv4 address.
func normalizeHost(host string) (string, bool) {
	host = strings.ToLower(strings.TrimSuffix(host, "."))
	if host == "" || len(host) > 253 {
		return "", false
	}
	if addr, err := netip.ParseAddr(host); err == nil {
		return addr.String(), addr.Is4()
	}
	for _, label := range strings.Split(host, ".") {
		if label == "" || len(label) > 63 {
			return "", false
		}
		for _, r := range label {
			if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-' || r == '_') {
				return "", false
			}
		}
	}
	// A single label would take the resolver's search domains, which can name a private domain after the check.
	if !strings.Contains(host, ".") {
		return "", false
	}
	// A name whose last label is all digits reads as an address to some resolvers.
	last := host[strings.LastIndex(host, ".")+1:]
	if strings.Trim(last, "0123456789") == "" || strings.HasPrefix(last, "0x") {
		return "", false
	}
	return host, true
}

// normalizeHostPattern accepts an exact name or `*.` plus a name.
func normalizeHostPattern(pattern string) (string, bool) {
	if rest, ok := strings.CutPrefix(pattern, "*."); ok {
		host, ok := normalizeHost(rest)
		if !ok || net.ParseIP(host) != nil {
			return "", false
		}
		return "*." + host, true
	}
	host, ok := normalizeHost(pattern)
	if !ok || net.ParseIP(host) != nil {
		return "", false
	}
	return host, true
}

// hostMatches tells whether a normalized host matches a pattern. `*.example.com` matches each name below it, not the name itself.
func hostMatches(pattern, host string) bool {
	if suffix, ok := strings.CutPrefix(pattern, "*"); ok {
		return strings.HasSuffix(host, suffix) && len(host) > len(suffix)
	}
	return pattern == host
}

// privateName tells whether a name is in one of the fleet's private domains, `orbit` always among them.
func privateName(host string, domains []string) bool {
	for _, domain := range append([]string{"orbit"}, domains...) {
		domain = strings.Trim(strings.ToLower(domain), ".")
		if domain != "" && (host == domain || strings.HasSuffix(host, "."+domain)) {
			return true
		}
	}
	return false
}

var specialV4 = func() []netip.Prefix {
	var out []netip.Prefix
	for _, p := range []string{"0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12",
		"192.0.0.0/24", "192.0.2.0/24", "192.168.0.0/16", "198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4"} {
		out = append(out, netip.MustParsePrefix(p))
	}
	return out
}()

// Floor refuses every address that is not on the public IPv4 internet, and the Node's own addresses.
type Floor struct {
	Local []netip.Addr
}

// NodeFloor reads the Node's own addresses once, at start.
func NodeFloor() Floor {
	var local []netip.Addr
	if addrs, err := net.InterfaceAddrs(); err == nil {
		for _, a := range addrs {
			if prefix, err := netip.ParsePrefix(a.String()); err == nil {
				local = append(local, prefix.Addr().Unmap())
			}
		}
	}
	return Floor{Local: local}
}

func (f Floor) Allows(addr netip.Addr) bool {
	addr = addr.Unmap()
	if !addr.Is4() {
		return false
	}
	for _, p := range specialV4 {
		if p.Contains(addr) {
			return false
		}
	}
	for _, l := range f.Local {
		if l == addr {
			return false
		}
	}
	return true
}

// Resolver looks a name up and returns its addresses. The proxy dials only the addresses it checked.
type Resolver interface {
	Lookup(ctx context.Context, host string) ([]netip.Addr, error)
}

// DNSResolver asks the Node's stub resolver for IPv4 addresses. It looks the name up as absolute, so no search domain joins it; Go
// still reads /etc/hosts first, whose answers meet the same address floor.
type DNSResolver struct {
	Server string
}

func (r DNSResolver) Lookup(ctx context.Context, host string) ([]netip.Addr, error) {
	resolver := &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, network, r.Server)
	}}
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	return resolver.LookupNetIP(ctx, "ip4", host+".")
}

// resolve checks a host and returns the one address to dial: any refused answer refuses the name, as a name that resolves to
// a private address among public ones could pick either.
func (p *Proxy) resolve(ctx context.Context, host string) (netip.Addr, *Refusal) {
	if addr, err := netip.ParseAddr(host); err == nil {
		if !p.Floor.Allows(addr) {
			return netip.Addr{}, refuse(403, "address_refused")
		}
		return addr, nil
	}
	if privateName(host, p.Config.PrivateDomains) {
		return netip.Addr{}, refuse(403, "private_name")
	}
	addrs, err := p.Resolver.Lookup(ctx, host)
	var dnsErr *net.DNSError
	if errors.As(err, &dnsErr) && dnsErr.IsNotFound || err == nil && len(addrs) == 0 {
		return netip.Addr{}, refuse(502, "name_not_found")
	}
	if err != nil {
		return netip.Addr{}, refuse(502, "lookup_failed")
	}
	for _, a := range addrs {
		if !p.Floor.Allows(a) {
			return netip.Addr{}, refuse(403, "address_refused")
		}
	}
	return addrs[0].Unmap(), nil
}
