package proxy

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// Config is the Node's proxy configuration, /etc/orbit/egress/config.json, written by the converge.
type Config struct {
	// Listen is the bridge's gateway address and port, such as 10.212.0.1:3128.
	Listen string `json:"listen"`
	// Resolver is the Node's stub resolver, such as 127.0.0.53:53.
	Resolver string `json:"resolver"`
	// PrivateDomains are the fleet's private routing domains. `orbit` is always among them.
	PrivateDomains []string `json:"private_domains"`
	// Containers is the folder of the per-container files that delivery writes.
	Containers string `json:"containers"`
	// State is the folder of the per-container CAs.
	State string `json:"state"`
}

// LoadConfig reads and checks the Node's configuration.
func LoadConfig(path string) (Config, error) {
	var config Config
	raw, err := os.ReadFile(path)
	if err != nil {
		return config, err
	}
	if err := json.Unmarshal(raw, &config); err != nil {
		return config, fmt.Errorf("parse %s: %w", path, err)
	}
	if config.Listen == "" || config.Resolver == "" || config.Containers == "" || config.State == "" {
		return config, errors.New("listen, resolver, containers and state are required")
	}
	return config, nil
}

// Binding is one marked environment key of a container, with its real value.
type Binding struct {
	Key         string   `json:"key"`
	Placeholder string   `json:"placeholder"`
	Hosts       []string `json:"hosts"`
	Headers     []string `json:"headers"`
	Value       string   `json:"value"`
}

// Container is one container's file: its name, its bridge address and its bindings.
type Container struct {
	Name     string    `json:"name"`
	Address  string    `json:"address"`
	Bindings []Binding `json:"bindings"`
}

func (c Container) check() error {
	if !validContainer(c.Name) {
		return fmt.Errorf("container name %q is not valid", c.Name)
	}
	if addr, err := netip.ParseAddr(c.Address); err != nil || !addr.Is4() {
		return fmt.Errorf("container %s: address %q is not IPv4", c.Name, c.Address)
	}
	for _, b := range c.Bindings {
		if b.Key == "" || len(b.Placeholder) < 32 || b.Value == "" || len(b.Hosts) == 0 || len(b.Headers) == 0 {
			return fmt.Errorf("container %s: binding %q is incomplete", c.Name, b.Key)
		}
		for _, h := range b.Hosts {
			if _, ok := normalizeHostPattern(h); !ok {
				return fmt.Errorf("container %s: binding %s: host %q is not valid", c.Name, b.Key, h)
			}
		}
	}
	return nil
}

func validContainer(name string) bool {
	if name == "" || len(name) > 63 {
		return false
	}
	for _, r := range name {
		if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-') {
			return false
		}
	}
	return true
}

// Store holds the containers' files in memory. It reads the folder again when a file in it changes, so a delivery
// takes effect on the next request without a restart, and a request never reads the Gateway.
type Store struct {
	dir string
	// Report hears each failed read; the proxy keeps the last good state.
	Report func(error)

	mu        sync.RWMutex
	stamp     string
	checked   time.Time
	byAddress map[netip.Addr]Container
	err       error
}

func NewStore(dir string) *Store {
	return &Store{dir: dir, byAddress: map[netip.Addr]Container{}}
}

// Lookup returns the container at a bridge address. An unknown address has no bindings.
func (s *Store) Lookup(addr netip.Addr) (Container, bool) {
	s.refresh()
	s.mu.RLock()
	defer s.mu.RUnlock()
	c, ok := s.byAddress[addr.Unmap()]
	return c, ok
}

// Err is the error of the last read, if the folder held a file that does not parse. The previous state stays then.
func (s *Store) Err() error {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.err
}

func (s *Store) refresh() {
	s.mu.RLock()
	fresh := time.Since(s.checked) < time.Second
	s.mu.RUnlock()
	if fresh {
		return
	}
	stamp, files := s.scan()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.checked = time.Now()
	if stamp == s.stamp {
		return
	}
	// A bad file is reported once; the next change reads the folder again.
	s.stamp = stamp
	next := map[netip.Addr]Container{}
	for _, file := range files {
		raw, err := os.ReadFile(file)
		if err != nil {
			s.err = err
			s.report()
			return
		}
		var c Container
		if err := json.Unmarshal(raw, &c); err != nil {
			s.err = fmt.Errorf("parse %s: %w", filepath.Base(file), err)
			s.report()
			return
		}
		if err := c.check(); err != nil {
			s.err = err
			s.report()
			return
		}
		if filepath.Base(file) != c.Name+".json" {
			s.err = fmt.Errorf("%s names container %s", filepath.Base(file), c.Name)
			s.report()
			return
		}
		addr := netip.MustParseAddr(c.Address)
		if other, taken := next[addr]; taken {
			s.err = fmt.Errorf("containers %s and %s share address %s", other.Name, c.Name, addr)
			s.report()
			return
		}
		next[addr] = c
	}
	s.byAddress, s.err = next, nil
}

func (s *Store) report() {
	if s.Report != nil {
		s.Report(s.err)
	}
}

// scan lists the folder's files with their size and change time, so any change, a rename into place included, alters the stamp.
func (s *Store) scan() (string, []string) {
	entries, err := os.ReadDir(s.dir)
	if err != nil {
		return "missing", nil
	}
	var parts, files []string
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".json") {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		path := filepath.Join(s.dir, e.Name())
		files = append(files, path)
		parts = append(parts, fmt.Sprintf("%s:%d:%d", e.Name(), info.Size(), info.ModTime().UnixNano()))
	}
	sort.Strings(parts)
	return strings.Join(parts, "|"), files
}
