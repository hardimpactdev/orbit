package proxy

import (
	"bufio"
	"crypto/tls"
	"errors"
	"io"
	"net"
	"time"
)

// clientHelloServerName reads the first bytes of a tunnel. For a TLS ClientHello it reads the whole message and returns its SNI;
// other first bytes return isTLS false. The bytes stay in the reader, so the tunnel sends them on unchanged.
func clientHelloServerName(r *bufio.Reader) (serverName string, isTLS bool, err error) {
	first, err := r.Peek(1)
	if err != nil {
		return "", false, err
	}
	if first[0] != 0x16 {
		return "", false, nil
	}
	// The ClientHello may span records: read records until the handshake message is whole, up to 64 KiB.
	offset, payload, need := 0, 0, -1
	for need < 0 || payload < need {
		header, err := r.Peek(offset + 5)
		if err != nil {
			return "", true, err
		}
		if header[offset] != 0x16 {
			return "", true, errors.New("not a ClientHello")
		}
		length := int(header[offset+3])<<8 | int(header[offset+4])
		if offset+5+length > 64<<10 {
			return "", true, errors.New("ClientHello too large")
		}
		record, err := r.Peek(offset + 5 + length)
		if err != nil {
			return "", true, err
		}
		if need < 0 {
			if length < 4 {
				return "", true, errors.New("not a ClientHello")
			}
			body := record[offset+5:]
			need = 4 + (int(body[1])<<16 | int(body[2])<<8 | int(body[3]))
		}
		payload += length
		offset += 5 + length
	}
	record, _ := r.Peek(offset)
	// crypto/tls parses the ClientHello; the handshake stops at GetConfigForClient, so nothing is answered.
	var name string
	errStop := errors.New("stop")
	conn := tls.Server(&readOnlyConn{data: record}, &tls.Config{GetConfigForClient: func(hello *tls.ClientHelloInfo) (*tls.Config, error) {
		name = hello.ServerName
		return nil, errStop
	}})
	if err := conn.Handshake(); !errors.Is(err, errStop) {
		return "", true, errors.New("not a ClientHello")
	}
	return name, true, nil
}

// readOnlyConn feeds a fixed buffer to crypto/tls and drops what it writes.
type readOnlyConn struct{ data []byte }

func (c *readOnlyConn) Read(p []byte) (int, error) {
	if len(c.data) == 0 {
		return 0, io.EOF
	}
	n := copy(p, c.data)
	c.data = c.data[n:]
	return n, nil
}
func (*readOnlyConn) Write(p []byte) (int, error)      { return len(p), nil }
func (*readOnlyConn) Close() error                     { return nil }
func (*readOnlyConn) LocalAddr() net.Addr              { return &net.TCPAddr{} }
func (*readOnlyConn) RemoteAddr() net.Addr             { return &net.TCPAddr{} }
func (*readOnlyConn) SetDeadline(time.Time) error      { return nil }
func (*readOnlyConn) SetReadDeadline(time.Time) error  { return nil }
func (*readOnlyConn) SetWriteDeadline(time.Time) error { return nil }
