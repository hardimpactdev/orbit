package proxy

import (
	"bufio"
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	stripePlaceholder   = "sk_test_orbit_secret_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	postmarkPlaceholder = "orbit_secret_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	stripeValue         = "sk_test_REAL_STRIPE_VALUE"
	postmarkValue       = "REAL_POSTMARK_VALUE"
)

// fakeResolver answers from a fixed table.
type fakeResolver map[string][]netip.Addr

func (f fakeResolver) Lookup(_ context.Context, host string) ([]netip.Addr, error) {
	if addrs, ok := f[host]; ok {
		return addrs, nil
	}
	return nil, &net.DNSError{Err: "no such host", Name: host, IsNotFound: true}
}

type syncBuffer struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *syncBuffer) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.Write(p)
}

func (s *syncBuffer) String() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.String()
}

type harness struct {
	t        *testing.T
	proxy    *Proxy
	server   *httptest.Server
	upstream *httptest.Server
	seen     chan http.Header
	log      *syncBuffer
	dir      string
	state    string
}

// newHarness runs the proxy on loopback. The test client's address, 127.0.0.1, is container instance-7. The upstream is an
// httptest TLS server whose certificate names example.com; the resolver maps the test hosts to public addresses, and Dial sends
// every address to the upstream.
func newHarness(t *testing.T, bindings []Binding) *harness {
	t.Helper()
	h := &harness{t: t, seen: make(chan http.Header, 16), log: &syncBuffer{}, dir: t.TempDir(), state: t.TempDir()}
	h.upstream = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h.seen <- r.Header.Clone()
		fmt.Fprint(w, "upstream ok")
	}))
	t.Cleanup(h.upstream.Close)
	roots := x509.NewCertPool()
	roots.AddCert(h.upstream.Certificate())
	h.writeContainer(Container{Name: "instance-7", Address: "127.0.0.1", Bindings: bindings})
	upstreamAddr := h.upstream.Listener.Addr().String()
	h.proxy = &Proxy{
		Config:        Config{PrivateDomains: []string{"beast.lan"}, State: h.state},
		Store:         NewStore(h.dir),
		Resolver:      fakeResolver{"example.com": {netip.MustParseAddr("93.184.216.34")}, "api.example.com": {netip.MustParseAddr("93.184.216.35")}, "other.example.net": {netip.MustParseAddr("93.184.216.36")}, "private.example.com": {netip.MustParseAddr("10.1.2.3")}, "mixed.example.com": {netip.MustParseAddr("93.184.216.37"), netip.MustParseAddr("192.168.1.1")}},
		Floor:         Floor{},
		UpstreamRoots: roots,
		Log:           h.log,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "tcp", upstreamAddr)
		},
	}
	h.server = httptest.NewServer(h.proxy)
	t.Cleanup(h.server.Close)
	return h
}

func (h *harness) writeContainer(c Container) {
	h.t.Helper()
	raw, _ := json.Marshal(c)
	if err := writeAtomic(filepath.Join(h.dir, c.Name+".json"), raw, 0o640); err != nil {
		h.t.Fatal(err)
	}
}

// client trusts the container's CA, as a container does, plus the upstream for tunnels that are not read.
func (h *harness) client() *http.Client {
	h.t.Helper()
	ca, err := EnsureCA(h.state, "instance-7")
	if err != nil {
		h.t.Fatal(err)
	}
	roots := x509.NewCertPool()
	roots.AppendCertsFromPEM(ca.PEM())
	roots.AddCert(h.upstream.Certificate())
	proxyURL, _ := url.Parse(h.server.URL)
	return &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{Proxy: http.ProxyURL(proxyURL), TLSClientConfig: &tls.Config{RootCAs: roots}}}
}

func (h *harness) do(method, target string, header http.Header) (*http.Response, string) {
	h.t.Helper()
	req, _ := http.NewRequest(method, target, nil)
	for k, vs := range header {
		for _, v := range vs {
			req.Header.Add(k, v)
		}
	}
	resp, err := h.client().Do(req)
	if err != nil {
		h.t.Fatalf("%s %s: %v", method, target, err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	return resp, string(body)
}

func (h *harness) upstreamSaw() http.Header {
	h.t.Helper()
	select {
	case header := <-h.seen:
		return header
	case <-time.After(5 * time.Second):
		h.t.Fatal("the upstream got no request")
		return nil
	}
}

func (h *harness) upstreamSawNothing() {
	h.t.Helper()
	select {
	case header := <-h.seen:
		h.t.Fatalf("the upstream got a request: %v", header)
	default:
	}
}

func (h *harness) records() []Record {
	var out []Record
	for _, line := range strings.Split(strings.TrimSpace(h.log.String()), "\n") {
		if line == "" {
			continue
		}
		var r Record
		if err := json.Unmarshal([]byte(line), &r); err != nil {
			h.t.Fatalf("log line %q: %v", line, err)
		}
		out = append(out, r)
	}
	return out
}

func stripeBinding() Binding {
	return Binding{Key: "STRIPE_SECRET", Placeholder: stripePlaceholder, Hosts: []string{"example.com"}, Headers: []string{"Authorization"}, Value: stripeValue}
}

func postmarkBinding() Binding {
	return Binding{Key: "POSTMARK_TOKEN", Placeholder: postmarkPlaceholder, Hosts: []string{"*.example.com"}, Headers: []string{"X-Postmark-Server-Token"}, Value: postmarkValue}
}

func TestInjectsBearerIntoTheBoundHeaderOfTheBoundHost(t *testing.T) {
	h := newHarness(t, []Binding{stripeBinding()})
	resp, body := h.do("GET", "https://example.com/v1/charges", http.Header{"Authorization": {"Bearer " + stripePlaceholder}, "X-Other": {"kept"}})
	if resp.StatusCode != 200 || body != "upstream ok" {
		t.Fatalf("status %d body %q", resp.StatusCode, body)
	}
	seen := h.upstreamSaw()
	if got := seen.Get("Authorization"); got != "Bearer "+stripeValue {
		t.Fatalf("upstream Authorization %q", got)
	}
	if seen.Get("X-Other") != "kept" {
		t.Fatal("another header changed")
	}
	if strings.Contains(h.log.String(), stripeValue) {
		t.Fatal("the log holds the value")
	}
	var forwarded *Record
	for _, r := range h.records() {
		if r.Decision == "forward" {
			forwarded = &r
		}
	}
	if forwarded == nil || forwarded.Container != "instance-7" || len(forwarded.Injected) != 1 || forwarded.Injected[0] != "STRIPE_SECRET" || forwarded.Status != 200 {
		t.Fatalf("records %+v", h.records())
	}
}

func TestInjectsInsideBasicCredentials(t *testing.T) {
	b := stripeBinding()
	h := newHarness(t, []Binding{b})
	encoded := base64.StdEncoding.EncodeToString([]byte("api:" + stripePlaceholder))
	resp, _ := h.do("GET", "https://example.com/", http.Header{"Authorization": {"Basic " + encoded}})
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	want := "Basic " + base64.StdEncoding.EncodeToString([]byte("api:"+stripeValue))
	if got := h.upstreamSaw().Get("Authorization"); got != want {
		t.Fatalf("upstream Authorization %q, want %q", got, want)
	}
}

func TestInjectsIntoACustomHeaderOnAWildcardHost(t *testing.T) {
	h := newHarness(t, []Binding{postmarkBinding()})
	resp, _ := h.do("POST", "https://api.example.com/email", http.Header{"X-Postmark-Server-Token": {postmarkPlaceholder}, "Authorization": {"Bearer " + postmarkPlaceholder}})
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	seen := h.upstreamSaw()
	if seen.Get("X-Postmark-Server-Token") != postmarkValue {
		t.Fatalf("custom header %q", seen.Get("X-Postmark-Server-Token"))
	}
	// Only the bound header gets the value.
	if seen.Get("Authorization") != "Bearer "+postmarkPlaceholder {
		t.Fatalf("an unbound header got the value: %q", seen.Get("Authorization"))
	}
}

func TestRefusesAPlaceholderSentToAHostItsKeyIsNotBoundTo(t *testing.T) {
	// api.example.com is read for POSTMARK_TOKEN; STRIPE_SECRET is bound to example.com only.
	h := newHarness(t, []Binding{stripeBinding(), postmarkBinding()})
	resp, _ := h.do("GET", "https://api.example.com/", http.Header{"Authorization": {"Bearer " + stripePlaceholder}})
	if resp.StatusCode != http.StatusMisdirectedRequest {
		t.Fatalf("status %d, want 421", resp.StatusCode)
	}
	h.upstreamSawNothing()
}

func TestRefusesAPlaceholderInClearText(t *testing.T) {
	h := newHarness(t, []Binding{stripeBinding()})
	resp, _ := h.do("GET", "http://other.example.net/", http.Header{"Authorization": {"Bearer " + stripePlaceholder}})
	if resp.StatusCode != http.StatusMisdirectedRequest {
		t.Fatalf("status %d, want 421", resp.StatusCode)
	}
	h.upstreamSawNothing()
}

func TestRefusesAnotherHostInsideAReadTunnel(t *testing.T) {
	h := newHarness(t, []Binding{stripeBinding()})
	conn := h.connect("example.com:443")
	defer conn.Close()
	ca, _ := EnsureCA(h.state, "instance-7")
	roots := x509.NewCertPool()
	roots.AppendCertsFromPEM(ca.PEM())
	tlsConn := tls.Client(conn, &tls.Config{ServerName: "example.com", RootCAs: roots})
	fmt.Fprintf(tlsConn, "GET / HTTP/1.1\r\nHost: other.example.net\r\nAuthorization: Bearer %s\r\n\r\n", stripePlaceholder)
	resp, err := http.ReadResponse(bufio.NewReader(tlsConn), nil)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != http.StatusMisdirectedRequest {
		t.Fatalf("status %d, want 421", resp.StatusCode)
	}
	h.upstreamSawNothing()
}

func TestTunnelsAHostWithoutABindingUntouched(t *testing.T) {
	// The container has a binding, but not for example.com: the tunnel is not read, and the upstream's own certificate answers.
	h := newHarness(t, []Binding{postmarkBinding()})
	h.writeContainer(Container{Name: "instance-7", Address: "127.0.0.1", Bindings: []Binding{{Key: "POSTMARK_TOKEN", Placeholder: postmarkPlaceholder, Hosts: []string{"postmark.example.org"}, Headers: []string{"Authorization"}, Value: postmarkValue}}})
	resp, _ := h.do("GET", "https://example.com/", http.Header{"Authorization": {"Bearer " + postmarkPlaceholder}})
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if resp.TLS == nil || resp.TLS.PeerCertificates[0].Issuer.CommonName == "Orbit egress instance-7" {
		t.Fatal("the tunnel was read")
	}
	if got := h.upstreamSaw().Get("Authorization"); got != "Bearer "+postmarkPlaceholder {
		t.Fatalf("upstream Authorization %q", got)
	}
	if rs := h.records(); len(rs) != 1 || rs[0].Decision != "tunnel" {
		t.Fatalf("records %+v", rs)
	}
}

func TestAnUnknownAddressGetsPlainEgress(t *testing.T) {
	h := newHarness(t, []Binding{stripeBinding()})
	os.Remove(filepath.Join(h.dir, "instance-7.json"))
	resp, _ := h.do("GET", "https://example.com/", http.Header{"Authorization": {"Bearer " + stripePlaceholder}})
	if resp.StatusCode != 200 || resp.TLS.PeerCertificates[0].Issuer.CommonName == "Orbit egress instance-7" {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if got := h.upstreamSaw().Get("Authorization"); got != "Bearer "+stripePlaceholder {
		t.Fatalf("upstream Authorization %q", got)
	}
}

func TestClosesATunnelWhoseSNINamesAnotherHost(t *testing.T) {
	for _, bindings := range [][]Binding{nil, {stripeBinding()}} {
		h := newHarness(t, bindings)
		conn := h.connect("example.com:443")
		tlsConn := tls.Client(conn, &tls.Config{ServerName: "evil.example.net", InsecureSkipVerify: true})
		if err := tlsConn.Handshake(); err == nil {
			t.Fatalf("bindings %v: the handshake passed", bindings)
		}
		conn.Close()
		h.upstreamSawNothing()
	}
}

func TestRefusesPrivateNamesAndAddresses(t *testing.T) {
	h := newHarness(t, nil)
	cases := map[string]int{
		"gateway.orbit:443":       403,
		"db.beast.lan:443":        403,
		"private.example.com:443": 403,
		"mixed.example.com:443":   403,
		"127.0.0.1:443":           403,
		"10.0.0.2:22":             403,
		"[::1]:443":               400,
		"evil..example.com:443":   400,
		"example.123:443":         400,
		"missing.example.com:443": 502,
	}
	for target, status := range cases {
		conn, err := net.Dial("tcp", h.server.Listener.Addr().String())
		if err != nil {
			t.Fatal(err)
		}
		fmt.Fprintf(conn, "CONNECT %s HTTP/1.1\r\nHost: %s\r\n\r\n", target, target)
		resp, err := http.ReadResponse(bufio.NewReader(conn), &http.Request{Method: "CONNECT"})
		conn.Close()
		if err != nil {
			t.Fatalf("%s: %v", target, err)
		}
		if resp.StatusCode != status {
			t.Errorf("%s: status %d, want %d", target, resp.StatusCode, status)
		}
	}
	h.upstreamSawNothing()
}

func TestReadsAChangedContainerFileWithoutARestart(t *testing.T) {
	h := newHarness(t, nil)
	resp, _ := h.do("GET", "https://example.com/", http.Header{"Authorization": {"Bearer " + stripePlaceholder}})
	if resp.TLS.PeerCertificates[0].Issuer.CommonName == "Orbit egress instance-7" {
		t.Fatal("read before the binding")
	}
	h.upstreamSaw()
	h.writeContainer(Container{Name: "instance-7", Address: "127.0.0.1", Bindings: []Binding{stripeBinding()}})
	h.proxy.Store.checked = time.Time{}
	h.do("GET", "https://example.com/", http.Header{"Authorization": {"Bearer " + stripePlaceholder}})
	if got := h.upstreamSaw().Get("Authorization"); got != "Bearer "+stripeValue {
		t.Fatalf("upstream Authorization %q", got)
	}
}

func TestAPolicyRefusalStopsTheRequest(t *testing.T) {
	h := newHarness(t, nil)
	h.proxy.Policy = func(_ Container, host string, _ int) *Refusal {
		if host == "example.com" {
			return refuse(403, "not_allowed")
		}
		return nil
	}
	conn := h.connect("other.example.net:443")
	conn.Close()
	c, _ := net.Dial("tcp", h.server.Listener.Addr().String())
	defer c.Close()
	fmt.Fprint(c, "CONNECT example.com:443 HTTP/1.1\r\nHost: example.com:443\r\n\r\n")
	resp, _ := http.ReadResponse(bufio.NewReader(c), &http.Request{Method: "CONNECT"})
	if resp.StatusCode != 403 {
		t.Fatalf("status %d", resp.StatusCode)
	}
}

// connect opens a CONNECT tunnel through the proxy and returns it after the 200.
func (h *harness) connect(target string) net.Conn {
	h.t.Helper()
	conn, err := net.Dial("tcp", h.server.Listener.Addr().String())
	if err != nil {
		h.t.Fatal(err)
	}
	fmt.Fprintf(conn, "CONNECT %s HTTP/1.1\r\nHost: %s\r\n\r\n", target, target)
	reader := bufio.NewReader(conn)
	resp, err := http.ReadResponse(reader, &http.Request{Method: "CONNECT"})
	if err != nil || resp.StatusCode != 200 {
		h.t.Fatalf("CONNECT %s: %v %v", target, resp, err)
	}
	return &bufferedConn{Conn: conn, r: reader}
}

func TestStoreRefusesABadFileAndKeepsTheLastGoodState(t *testing.T) {
	dir := t.TempDir()
	store := NewStore(dir)
	var reported []error
	store.Report = func(err error) { reported = append(reported, err) }
	good, _ := json.Marshal(Container{Name: "instance-1", Address: "10.9.0.2", Bindings: []Binding{stripeBinding()}})
	os.WriteFile(filepath.Join(dir, "instance-1.json"), good, 0o640)
	if c, ok := store.Lookup(netip.MustParseAddr("10.9.0.2")); !ok || c.Name != "instance-1" {
		t.Fatal("the good file was not read")
	}
	for _, bad := range []string{
		`{"name":"instance-1","address":"10.9.0.3","bindings":[{"key":"A","placeholder":"short","hosts":["x.example.com"],"headers":["Authorization"],"value":"v"}]}`,
		`{"name":"instance-2","address":"10.9.0.3","bindings":[]}`,
		`{"name":"instance-1","address":"fd00::1","bindings":[]}`,
		`not json`,
	} {
		os.WriteFile(filepath.Join(dir, "instance-1.json"), []byte(bad), 0o640)
		os.Chtimes(filepath.Join(dir, "instance-1.json"), time.Now(), time.Now().Add(time.Duration(len(reported)+1)*time.Second))
		store.checked = time.Time{}
		if c, ok := store.Lookup(netip.MustParseAddr("10.9.0.2")); !ok || c.Name != "instance-1" {
			t.Fatalf("%s: the last good state went", bad)
		}
		if store.Err() == nil {
			t.Fatalf("%s: no error", bad)
		}
	}
	if len(reported) != 4 {
		t.Fatalf("reported %d errors", len(reported))
	}
}

func TestHostRules(t *testing.T) {
	for pattern, host := range map[string]string{"*.example.com": "api.example.com", "example.com": "example.com", "EXAMPLE.com.": "example.com"} {
		p, ok := normalizeHostPattern(pattern)
		h, _ := normalizeHost(host)
		if !ok || !hostMatches(p, h) {
			t.Errorf("%s should match %s", pattern, host)
		}
	}
	for _, pair := range [][2]string{{"*.example.com", "example.com"}, {"example.com", "api.example.com"}, {"*.example.com", "evilexample.com"}} {
		p, _ := normalizeHostPattern(pair[0])
		if hostMatches(p, pair[1]) {
			t.Errorf("%s should not match %s", pair[0], pair[1])
		}
	}
	for _, bad := range []string{"", "*", "*.", "a..b", "1.2.3.4", "exa mple.com", "*.*.example.com", "example.com:443"} {
		if _, ok := normalizeHostPattern(bad); ok {
			t.Errorf("pattern %q should be refused", bad)
		}
	}
}

func TestFloor(t *testing.T) {
	floor := Floor{Local: []netip.Addr{netip.MustParseAddr("203.0.114.9")}}
	for _, a := range []string{"10.0.0.1", "172.16.5.5", "192.168.0.1", "127.0.0.1", "169.254.169.254", "100.64.0.1", "0.0.0.0", "224.0.0.1", "203.0.114.9", "::1", "2606:4700::1", "::ffff:10.0.0.1"} {
		if floor.Allows(netip.MustParseAddr(a)) {
			t.Errorf("%s should be refused", a)
		}
	}
	for _, a := range []string{"93.184.216.34", "1.1.1.1", "::ffff:1.1.1.1"} {
		if !floor.Allows(netip.MustParseAddr(a)) {
			t.Errorf("%s should be allowed", a)
		}
	}
}

func TestClientHelloServerName(t *testing.T) {
	server, client := net.Pipe()
	go tls.Client(client, &tls.Config{ServerName: "api.example.com", InsecureSkipVerify: true}).Handshake()
	name, isTLS, err := clientHelloServerName(bufio.NewReader(server))
	server.Close()
	client.Close()
	if err != nil || !isTLS || name != "api.example.com" {
		t.Fatalf("name %q tls %v err %v", name, isTLS, err)
	}
	name, isTLS, err = clientHelloServerName(bufio.NewReader(strings.NewReader("SSH-2.0-OpenSSH\r\n")))
	if err != nil || isTLS || name != "" {
		t.Fatalf("plain bytes: %q %v %v", name, isTLS, err)
	}
}

func TestCALeafStaysWithinTheCA(t *testing.T) {
	ca, err := EnsureCA(t.TempDir(), "instance-3")
	if err != nil {
		t.Fatal(err)
	}
	if !ca.cert.IsCA || ca.cert.MaxPathLen != 0 {
		t.Fatal("not a CA limited to leaves")
	}
	leaf, err := ca.leaf("example.com", ca.cert.NotAfter.Add(-time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	if leaf.Leaf.NotAfter.After(ca.cert.NotAfter) {
		t.Fatal("the leaf outlives the CA")
	}
	if _, err := ca.leaf("example.com", ca.cert.NotAfter.Add(time.Hour)); err == nil {
		t.Fatal("an expired CA signed a leaf")
	}
	if _, err := EnsureCA(t.TempDir(), "../etc"); err == nil {
		t.Fatal("a path name was accepted")
	}
}
