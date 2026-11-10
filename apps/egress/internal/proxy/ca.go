package proxy

import (
	"container/list"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// CA is one container's own certificate authority. Only that container trusts it, and its key never leaves the Node.
type CA struct {
	cert *x509.Certificate
	key  *ecdsa.PrivateKey
	pem  []byte
}

const (
	caLifetime   = 5 * 365 * 24 * time.Hour
	leafLifetime = 24 * time.Hour
	clockSkew    = time.Hour
)

// EnsureCA returns the container's CA from state/<container>, and makes it first when it is missing. The folder is 0700 and the
// key 0600, owned by the proxy's user.
func EnsureCA(state, container string) (*CA, error) {
	if !validContainer(container) {
		return nil, fmt.Errorf("container name %q is not valid", container)
	}
	dir := filepath.Join(state, "ca", container)
	certPath, keyPath := filepath.Join(dir, "ca.pem"), filepath.Join(dir, "ca.key")
	if ca, err := loadCA(certPath, keyPath); err == nil {
		return ca, nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	// The pair is written into a new folder that is then renamed into place, so the proxy and `orbit-egress ca` never mix two pairs.
	if err := os.MkdirAll(filepath.Dir(dir), 0o700); err != nil {
		return nil, err
	}
	tmp, err := os.MkdirTemp(filepath.Dir(dir), ".new-*")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(tmp)
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber:          serial(),
		Subject:               pkix.Name{CommonName: "Orbit egress " + container, Organization: []string{"Orbit"}},
		NotBefore:             now.Add(-clockSkew),
		NotAfter:              now.Add(caLifetime),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		BasicConstraintsValid: true,
		IsCA:                  true,
		MaxPathLenZero:        true,
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		return nil, err
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile(filepath.Join(tmp, "ca.key"), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600); err != nil {
		return nil, err
	}
	if err := os.WriteFile(filepath.Join(tmp, "ca.pem"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		return nil, err
	}
	if err := os.Chmod(tmp, 0o700); err != nil {
		return nil, err
	}
	// Another process may have won the rename; its pair is the one to use.
	_ = os.Rename(tmp, dir)
	ca, err := loadCA(certPath, keyPath)
	if errors.Is(err, os.ErrNotExist) {
		return nil, fmt.Errorf("%s holds no CA: remove the folder", dir)
	}
	return ca, err
}

func loadCA(certPath, keyPath string) (*CA, error) {
	certPEM, err := os.ReadFile(certPath)
	if err != nil {
		return nil, err
	}
	keyPEM, err := os.ReadFile(keyPath)
	if err != nil {
		return nil, err
	}
	certBlock, _ := pem.Decode(certPEM)
	keyBlock, _ := pem.Decode(keyPEM)
	if certBlock == nil || keyBlock == nil {
		return nil, fmt.Errorf("%s: not PEM", filepath.Dir(certPath))
	}
	cert, err := x509.ParseCertificate(certBlock.Bytes)
	if err != nil {
		return nil, err
	}
	key, err := x509.ParseECPrivateKey(keyBlock.Bytes)
	if err != nil {
		return nil, err
	}
	return &CA{cert: cert, key: key, pem: certPEM}, nil
}

// PEM is the CA certificate the container installs.
func (ca *CA) PEM() []byte { return ca.pem }

// leaf signs a one-day certificate for one host, never outside the CA's own validity.
func (ca *CA) leaf(host string, now time.Time) (*tls.Certificate, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	notBefore, notAfter := now.Add(-clockSkew), now.Add(leafLifetime)
	if notBefore.Before(ca.cert.NotBefore) {
		notBefore = ca.cert.NotBefore
	}
	if notAfter.After(ca.cert.NotAfter) {
		notAfter = ca.cert.NotAfter
	}
	if !notAfter.After(now) {
		return nil, errors.New("ca_expired")
	}
	template := &x509.Certificate{
		SerialNumber: serial(),
		Subject:      pkix.Name{CommonName: host},
		DNSNames:     []string{host},
		NotBefore:    notBefore,
		NotAfter:     notAfter,
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, template, ca.cert, &key.PublicKey, ca.key)
	if err != nil {
		return nil, err
	}
	return &tls.Certificate{Certificate: [][]byte{der, ca.cert.Raw}, PrivateKey: key, Leaf: template}, nil
}

func serial() *big.Int {
	n, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	return n
}

// leafCache keeps the leaf certificates of the 1024 hosts used last, across containers, so wildcard bindings cannot grow memory.
type leafCache struct {
	mu    sync.Mutex
	order *list.List
	items map[string]*list.Element
}

type leafEntry struct {
	key  string
	cert *tls.Certificate
}

const leafCacheSize = 1024

func newLeafCache() *leafCache {
	return &leafCache{order: list.New(), items: map[string]*list.Element{}}
}

func (c *leafCache) get(ca *CA, host string, now time.Time) (*tls.Certificate, error) {
	key := ca.cert.SerialNumber.String() + "\x00" + host
	c.mu.Lock()
	if el, ok := c.items[key]; ok {
		entry := el.Value.(*leafEntry)
		if now.Add(time.Hour).Before(entry.cert.Leaf.NotAfter) {
			c.order.MoveToFront(el)
			c.mu.Unlock()
			return entry.cert, nil
		}
		c.order.Remove(el)
		delete(c.items, key)
	}
	c.mu.Unlock()
	cert, err := ca.leaf(host, now)
	if err != nil {
		return nil, err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.items[key] = c.order.PushFront(&leafEntry{key: key, cert: cert})
	for c.order.Len() > leafCacheSize {
		last := c.order.Back()
		c.order.Remove(last)
		delete(c.items, last.Value.(*leafEntry).key)
	}
	return cert, nil
}
