package proxy

import (
	"encoding/base64"
	"net/http"
	"strings"
)

// inject replaces each placeholder of a key bound to the host in that binding's headers, also inside Basic credentials. A placeholder
// of a key that is not bound to the host refuses the request with 421, wherever it is in the headers. It returns the injected keys.
func inject(header http.Header, container Container, host string) ([]string, *Refusal) {
	bound := map[string]bool{}
	for _, b := range boundHere(container, host) {
		bound[b.Key] = true
	}
	var injected []string
	for _, b := range container.Bindings {
		if !headersCarry(header, b.Placeholder) {
			continue
		}
		if !bound[b.Key] {
			return nil, refuse(http.StatusMisdirectedRequest, "placeholder_wrong_host")
		}
		done := false
		for _, name := range b.Headers {
			values := header.Values(name)
			if len(values) == 0 {
				continue
			}
			next := make([]string, len(values))
			for i, v := range values {
				next[i] = replaceIn(v, b.Placeholder, b.Value)
				done = done || next[i] != v
			}
			header.Del(name)
			for _, v := range next {
				header.Add(name, v)
			}
		}
		if done {
			injected = append(injected, b.Key)
		}
	}
	return injected, nil
}

// replaceIn swaps the placeholder in one header value, and inside the decoded credentials of `Basic`.
func replaceIn(value, placeholder, secret string) string {
	value = strings.ReplaceAll(value, placeholder, secret)
	if scheme, encoded, ok := strings.Cut(value, " "); ok && strings.EqualFold(scheme, "basic") {
		if decoded, err := base64.StdEncoding.DecodeString(strings.TrimSpace(encoded)); err == nil && strings.Contains(string(decoded), placeholder) {
			return scheme + " " + base64.StdEncoding.EncodeToString([]byte(strings.ReplaceAll(string(decoded), placeholder, secret)))
		}
	}
	return value
}

// headersCarry tells whether any header value holds the placeholder, also inside decoded Basic credentials.
func headersCarry(header http.Header, placeholder string) bool {
	for _, values := range header {
		for _, v := range values {
			if strings.Contains(v, placeholder) {
				return true
			}
			if scheme, encoded, ok := strings.Cut(v, " "); ok && strings.EqualFold(scheme, "basic") {
				if decoded, err := base64.StdEncoding.DecodeString(strings.TrimSpace(encoded)); err == nil && strings.Contains(string(decoded), placeholder) {
					return true
				}
			}
		}
	}
	return false
}
