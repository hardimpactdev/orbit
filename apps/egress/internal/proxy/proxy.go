// Package proxy is Orbit's egress proxy for Instance containers (ADR 0254). It forwards every request of a container to the public
// internet, and for the hosts a container's secret bindings name, it swaps the placeholder in the bound headers for the real value.
package proxy

import (
	"bufio"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/netip"
	"net/url"
	"strconv"
	"sync"
	"time"
)

// Policy decides whether a container may reach a host and port. Nil allows every public host, today's rule; an allowlist joins here.
type Policy func(container Container, host string, port int) *Refusal

// Proxy serves the bridge. Its fields are set once, before Serve.
type Proxy struct {
	Config   Config
	Store    *Store
	Resolver Resolver
	Floor    interface{ Allows(netip.Addr) bool }
	Policy   Policy
	// UpstreamRoots verifies intercepted hosts; nil means the system roots.
	UpstreamRoots *x509.CertPool
	// Log receives one JSON line per request.
	Log  io.Writer
	Now  func() time.Time
	Dial func(ctx context.Context, network, address string) (net.Conn, error)

	leaves    *leafCache
	casMu     sync.Mutex
	cas       map[string]*CA
	transport *http.Transport
	logMu     sync.Mutex
	once      sync.Once
}

// Record is the journal line of one request. It never holds a value.
type Record struct {
	Time      string   `json:"time"`
	Container string   `json:"container"`
	Client    string   `json:"client"`
	Method    string   `json:"method"`
	Host      string   `json:"host"`
	Port      int      `json:"port"`
	Decision  string   `json:"decision"`
	Status    int      `json:"status"`
	Reason    string   `json:"reason,omitempty"`
	Injected  []string `json:"injected,omitempty"`
}

func (p *Proxy) init() {
	p.once.Do(func() {
		if p.Now == nil {
			p.Now = time.Now
		}
		if p.Dial == nil {
			p.Dial = (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext
		}
		p.leaves = newLeafCache()
		p.cas = map[string]*CA{}
		p.transport = &http.Transport{
			DialContext: func(ctx context.Context, _, address string) (net.Conn, error) {
				host, port, err := net.SplitHostPort(address)
				if err != nil {
					return nil, err
				}
				addr, refusal := p.resolve(ctx, host)
				if refusal != nil {
					return nil, refusal
				}
				return p.Dial(ctx, "tcp4", net.JoinHostPort(addr.String(), port))
			},
			TLSClientConfig:       &tls.Config{RootCAs: p.UpstreamRoots, MinVersion: tls.VersionTLS12},
			ForceAttemptHTTP2:     true,
			MaxIdleConns:          256,
			IdleConnTimeout:       90 * time.Second,
			TLSHandshakeTimeout:   10 * time.Second,
			ResponseHeaderTimeout: 5 * time.Minute,
			Proxy:                 nil,
		}
	})
}

func (p *Proxy) record(r Record) {
	r.Time = p.Now().UTC().Format(time.RFC3339Nano)
	line, _ := json.Marshal(r)
	p.logMu.Lock()
	defer p.logMu.Unlock()
	if p.Log != nil {
		p.Log.Write(append(line, '\n'))
	}
}

// ServeHTTP handles one request on the bridge: a CONNECT tunnel, or a plain HTTP request in absolute form.
func (p *Proxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	p.init()
	container := p.container(r.RemoteAddr)
	rec := Record{Container: container.Name, Client: r.RemoteAddr, Method: r.Method}
	if r.Method == http.MethodConnect {
		p.connect(w, r, container, rec)
		return
	}
	p.forward(w, r, container, rec)
}

func (p *Proxy) container(remote string) Container {
	ap, err := netip.ParseAddrPort(remote)
	if err != nil {
		return Container{}
	}
	c, _ := p.Store.Lookup(ap.Addr())
	return c
}

// target checks a request's host and port: the name rules, the private domains and the policy. It does not resolve.
func (p *Proxy) target(container Container, hostport string, defaultPort int) (string, int, *Refusal) {
	host, portText, err := net.SplitHostPort(hostport)
	if err != nil {
		host, portText = hostport, strconv.Itoa(defaultPort)
	}
	port, err := strconv.Atoi(portText)
	if err != nil || port < 1 || port > 65535 {
		return host, 0, refuse(400, "port_invalid")
	}
	host, ok := normalizeHost(host)
	if !ok {
		return host, port, refuse(400, "name_invalid")
	}
	if privateName(host, p.Config.PrivateDomains) {
		return host, port, refuse(403, "private_name")
	}
	if p.Policy != nil {
		if refusal := p.Policy(container, host, port); refusal != nil {
			return host, port, refusal
		}
	}
	return host, port, nil
}

func (p *Proxy) fail(w http.ResponseWriter, rec Record, refusal *Refusal) {
	rec.Decision, rec.Status, rec.Reason = "refuse", refusal.Status, refusal.Reason
	p.record(rec)
	http.Error(w, "orbit-egress: "+refusal.Reason, refusal.Status)
}

func asRefusal(err error) *Refusal {
	var refusal *Refusal
	if errors.As(err, &refusal) {
		return refusal
	}
	return refuse(502, "upstream_failed")
}

// boundHere lists the container's bindings for a host.
func boundHere(container Container, host string) []Binding {
	var out []Binding
	for _, b := range container.Bindings {
		for _, pattern := range b.Hosts {
			if pattern, ok := normalizeHostPattern(pattern); ok && hostMatches(pattern, host) {
				out = append(out, b)
				break
			}
		}
	}
	return out
}

func (p *Proxy) connect(w http.ResponseWriter, r *http.Request, container Container, rec Record) {
	host, port, refusal := p.target(container, r.Host, 443)
	rec.Host, rec.Port = host, port
	if refusal != nil {
		p.fail(w, rec, refusal)
		return
	}
	intercept := port == 443 && len(boundHere(container, host)) > 0
	var upstream net.Conn
	if !intercept {
		addr, refusal := p.resolve(r.Context(), host)
		if refusal != nil {
			p.fail(w, rec, refusal)
			return
		}
		conn, err := p.Dial(r.Context(), "tcp4", net.JoinHostPort(addr.String(), strconv.Itoa(port)))
		if err != nil {
			p.fail(w, rec, refuse(502, "connect_failed"))
			return
		}
		upstream = conn
	}
	hijacker, ok := w.(http.Hijacker)
	if !ok {
		p.fail(w, rec, refuse(500, "no_hijack"))
		return
	}
	client, buffered, err := hijacker.Hijack()
	if err != nil {
		if upstream != nil {
			upstream.Close()
		}
		return
	}
	if _, err := client.Write([]byte("HTTP/1.1 200 Connection Established\r\n\r\n")); err != nil {
		client.Close()
		if upstream != nil {
			upstream.Close()
		}
		return
	}
	if intercept {
		rec.Decision, rec.Status = "intercept", 200
		p.record(rec)
		p.intercept(&bufferedConn{Conn: client, r: buffered.Reader}, container, host)
		return
	}
	// net/http's hijacked reader holds 4 KiB; a ClientHello may be larger. The bytes it already holds are kept.
	p.tunnel(client, bufio.NewReaderSize(buffered.Reader, maxClientHello), upstream, host, rec)
}

// tunnel checks the SNI of a TLS ClientHello against the CONNECT host, then copies both ways.
func (p *Proxy) tunnel(client net.Conn, reader *bufio.Reader, upstream net.Conn, host string, rec Record) {
	defer client.Close()
	defer upstream.Close()
	client.SetReadDeadline(p.Now().Add(30 * time.Second))
	serverName, isTLS, err := clientHelloServerName(reader)
	client.SetReadDeadline(time.Time{})
	if err != nil && !errors.Is(err, io.EOF) {
		rec.Decision, rec.Status, rec.Reason = "refuse", 0, "client_hello_invalid"
		p.record(rec)
		return
	}
	// A name's ClientHello must name it; an address's must name nothing, as TLS has no SNI for an address.
	if isTLS {
		want := host
		if _, err := netip.ParseAddr(host); err == nil {
			want = ""
		}
		if name, _ := normalizeHost(serverName); name != want {
			rec.Decision, rec.Status, rec.Reason = "refuse", 0, "sni_mismatch"
			p.record(rec)
			return
		}
	}
	rec.Decision, rec.Status = "tunnel", 200
	p.record(rec)
	done := make(chan struct{}, 2)
	go func() { io.Copy(upstream, reader); closeWrite(upstream); done <- struct{}{} }()
	go func() { io.Copy(client, upstream); closeWrite(client); done <- struct{}{} }()
	<-done
	<-done
}

func closeWrite(c net.Conn) {
	if cw, ok := c.(interface{ CloseWrite() error }); ok {
		cw.CloseWrite()
	}
}

type bufferedConn struct {
	net.Conn
	r *bufio.Reader
}

func (c *bufferedConn) Read(p []byte) (int, error) { return c.r.Read(p) }

// ca returns a container's CA, made on first use and kept in memory.
func (p *Proxy) ca(container string) (*CA, error) {
	p.casMu.Lock()
	defer p.casMu.Unlock()
	if ca, ok := p.cas[container]; ok {
		return ca, nil
	}
	ca, err := EnsureCA(p.Config.State, container)
	if err != nil {
		return nil, err
	}
	p.cas[container] = ca
	return ca, nil
}

// intercept ends the container's TLS with a leaf of its own CA for the CONNECT host and serves the requests inside.
func (p *Proxy) intercept(client net.Conn, container Container, host string) {
	defer client.Close()
	ca, err := p.ca(container.Name)
	if err != nil {
		p.record(Record{Container: container.Name, Client: client.RemoteAddr().String(), Host: host, Port: 443, Decision: "refuse", Reason: "ca_unavailable"})
		return
	}
	conn := tls.Server(client, &tls.Config{
		MinVersion: tls.VersionTLS12,
		NextProtos: []string{"http/1.1"},
		GetCertificate: func(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
			if name, _ := normalizeHost(hello.ServerName); name != host {
				return nil, errors.New("sni_mismatch")
			}
			return p.leaves.get(ca, host, p.Now())
		},
	})
	conn.SetDeadline(p.Now().Add(30 * time.Second))
	if err := conn.Handshake(); err != nil {
		p.record(Record{Container: container.Name, Client: client.RemoteAddr().String(), Host: host, Port: 443, Decision: "refuse", Reason: "tls_handshake_failed"})
		return
	}
	conn.SetDeadline(time.Time{})
	server := &http.Server{
		Handler:           http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { p.inside(w, r, container, host) }),
		ReadHeaderTimeout: 30 * time.Second,
		IdleTimeout:       2 * time.Minute,
		ErrorLog:          log.New(io.Discard, "", 0),
	}
	listener := newOneConnListener(conn)
	server.Serve(listener)
	<-listener.closed
}

// inside serves one request in an intercepted tunnel: same host, placeholders only where bound, then on to the host over TLS.
func (p *Proxy) inside(w http.ResponseWriter, r *http.Request, container Container, host string) {
	rec := Record{Container: container.Name, Client: r.RemoteAddr, Method: r.Method, Host: host, Port: 443}
	// TRACE echoes the request, so an injected value would come back to the container.
	if r.Method == http.MethodTrace || r.Method == "TRACK" {
		p.fail(w, rec, refuse(http.StatusMethodNotAllowed, "trace_refused"))
		return
	}
	if name, port, err := splitRequestHost(r.Host, 443); err != nil || name != host || port != 443 {
		p.fail(w, rec, refuse(http.StatusMisdirectedRequest, "host_mismatch"))
		return
	}
	injected, refusal := inject(r.Header, container, host)
	if refusal != nil {
		p.fail(w, rec, refusal)
		return
	}
	rec.Injected = injected
	p.proxyTo(w, r, rec, "https", host)
}

// forward serves a plain HTTP request in absolute form. A placeholder never travels in clear text.
func (p *Proxy) forward(w http.ResponseWriter, r *http.Request, container Container, rec Record) {
	if r.URL.Scheme != "http" || r.URL.Host == "" {
		p.fail(w, rec, refuse(400, "absolute_http_required"))
		return
	}
	host, port, refusal := p.target(container, r.URL.Host, 80)
	rec.Host, rec.Port = host, port
	if refusal != nil {
		p.fail(w, rec, refusal)
		return
	}
	for _, b := range container.Bindings {
		if headersCarry(r.Header, b.Placeholder) {
			p.fail(w, rec, refuse(http.StatusMisdirectedRequest, "placeholder_in_clear_text"))
			return
		}
	}
	p.proxyTo(w, r, rec, "http", net.JoinHostPort(host, strconv.Itoa(port)))
}

func (p *Proxy) proxyTo(w http.ResponseWriter, r *http.Request, rec Record, scheme, authority string) {
	status := 0
	rp := &httputil.ReverseProxy{
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.Out.URL = &url.URL{Scheme: scheme, Host: authority, Path: r.URL.Path, RawPath: r.URL.RawPath, RawQuery: r.URL.RawQuery}
			pr.Out.Host = ""
			pr.Out.Header.Del("Proxy-Authorization")
			pr.Out.Header.Del("Proxy-Connection")
		},
		Transport:     p.transport,
		FlushInterval: -1,
		ErrorLog:      log.New(io.Discard, "", 0),
		ModifyResponse: func(resp *http.Response) error {
			status = resp.StatusCode
			return nil
		},
		ErrorHandler: func(w http.ResponseWriter, _ *http.Request, err error) {
			refusal := asRefusal(err)
			status = refusal.Status
			rec.Reason = refusal.Reason
			http.Error(w, "orbit-egress: "+refusal.Reason, refusal.Status)
		},
	}
	rp.ServeHTTP(w, r)
	rec.Decision, rec.Status = "forward", status
	if rec.Reason != "" {
		rec.Decision = "refuse"
	}
	p.record(rec)
}

func splitRequestHost(hostport string, defaultPort int) (string, int, error) {
	host, portText, err := net.SplitHostPort(hostport)
	if err != nil {
		host, portText = hostport, strconv.Itoa(defaultPort)
	}
	port, err := strconv.Atoi(portText)
	if err != nil {
		return "", 0, err
	}
	name, ok := normalizeHost(host)
	if !ok {
		return "", 0, errors.New("name_invalid")
	}
	return name, port, nil
}

// oneConnListener hands one connection to http.Server, then blocks until that connection closes.
type oneConnListener struct {
	conn   net.Conn
	once   sync.Once
	closed chan struct{}
}

func newOneConnListener(conn net.Conn) *oneConnListener {
	return &oneConnListener{conn: conn, closed: make(chan struct{})}
}

func (l *oneConnListener) Accept() (net.Conn, error) {
	var conn net.Conn
	l.once.Do(func() { conn = &notifyConn{Conn: l.conn, closed: l.closed} })
	if conn != nil {
		return conn, nil
	}
	<-l.closed
	return nil, net.ErrClosed
}
func (l *oneConnListener) Close() error   { return nil }
func (l *oneConnListener) Addr() net.Addr { return l.conn.LocalAddr() }

type notifyConn struct {
	net.Conn
	once   sync.Once
	closed chan struct{}
}

func (c *notifyConn) Close() error {
	err := c.Conn.Close()
	c.once.Do(func() { close(c.closed) })
	return err
}

// Serve listens on the configured address until the context ends.
func (p *Proxy) Serve(ctx context.Context) error {
	p.init()
	listener, err := net.Listen("tcp4", p.Config.Listen)
	if err != nil {
		return err
	}
	server := &http.Server{Handler: p, ReadHeaderTimeout: 30 * time.Second, IdleTimeout: 2 * time.Minute, ErrorLog: log.New(io.Discard, "", 0)}
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		server.Shutdown(shutdown)
	}()
	if err := server.Serve(listener); !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}
