// Package client implements the signed HTTPS transport used by the agent.
//
// Every authenticated request carries:
//
//	X-CloudForge-Fingerprint  lowercase SHA-256 certificate fingerprint
//	X-CloudForge-Timestamp    unix seconds
//	X-CloudForge-Nonce        single-use base64url nonce
//	X-CloudForge-Signature    base64 RSA-SHA256 signature
//
// The signature covers:
//
//	METHOD \n request-uri \n timestamp \n nonce \n sha256(raw-body)
//
// which is the same canonical string verified by the control plane.
package client

import (
	"bytes"
	"context"
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync/atomic"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/identity"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

const (
	userAgent        = "cloudforge-agent"
	maxResponseBytes = 4 << 20
)

// APIError is returned when the control plane rejects a request.
type APIError struct {
	StatusCode int
	Message    string
	Errors     map[string][]string
	Body       string
}

func (e *APIError) Error() string {
	if e.Message != "" {
		return fmt.Sprintf("control plane returned %d: %s", e.StatusCode, e.Message)
	}

	return fmt.Sprintf("control plane returned %d", e.StatusCode)
}

// IsUnauthorized reports whether the control plane rejected agent identity.
func IsUnauthorized(err error) bool {
	var apiErr *APIError

	return errors.As(err, &apiErr) && (apiErr.StatusCode == http.StatusUnauthorized || apiErr.StatusCode == http.StatusForbidden)
}

// Client talks to one control plane. A nil identity produces unsigned requests
// and is only used for enrollment.
type Client struct {
	base       *url.URL
	httpClient *http.Client
	identity   atomic.Pointer[identity.Identity]
	now        func() time.Time
	random     io.Reader
}

// New builds a client authenticated with the agent certificate. caPEM, when
// non-empty, is appended to the system trust store so a private control-plane
// certificate can be trusted explicitly.
func New(rawURL string, id *identity.Identity, caPEM []byte, timeout time.Duration) (*Client, error) {
	client, err := newClient(rawURL, caPEM, timeout)
	if err != nil {
		return nil, err
	}

	if id != nil {
		client.SetIdentity(id)
	}

	return client, nil
}

// NewAnonymous builds a client without an identity for enrollment.
func NewAnonymous(rawURL string, caPEM []byte, timeout time.Duration) (*Client, error) {
	return newClient(rawURL, caPEM, timeout)
}

func newClient(rawURL string, caPEM []byte, timeout time.Duration) (*Client, error) {
	base, err := url.Parse(strings.TrimRight(rawURL, "/"))
	if err != nil {
		return nil, fmt.Errorf("parse control plane URL: %w", err)
	}

	if base.Host == "" {
		return nil, errors.New("control plane URL has no host")
	}

	tlsConfig, err := tlsConfiguration(caPEM)
	if err != nil {
		return nil, err
	}

	if timeout <= 0 {
		timeout = 45 * time.Second
	}

	transport := &http.Transport{
		TLSClientConfig:     tlsConfig,
		ForceAttemptHTTP2:   true,
		MaxIdleConns:        8,
		MaxIdleConnsPerHost: 4,
		IdleConnTimeout:     90 * time.Second,
	}

	return &Client{
		base:       base,
		httpClient: &http.Client{Transport: transport, Timeout: timeout},
		now:        time.Now,
		random:     rand.Reader,
	}, nil
}

func tlsConfiguration(caPEM []byte) (*tls.Config, error) {
	roots, err := x509.SystemCertPool()
	if err != nil || roots == nil {
		roots = x509.NewCertPool()
	}

	if len(bytes.TrimSpace(caPEM)) > 0 && !roots.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("the supplied CA bundle does not contain a valid certificate")
	}

	return &tls.Config{
		MinVersion: tls.VersionTLS12,
		RootCAs:    roots,
	}, nil
}

// SetIdentity installs or replaces the certificate used for signing. It is safe
// to call while requests are in flight, which supports online rotation.
func (c *Client) SetIdentity(id *identity.Identity) {
	c.identity.Store(id)

	if transport, ok := c.httpClient.Transport.(*http.Transport); ok {
		tlsConfig := transport.TLSClientConfig.Clone()

		if id == nil {
			tlsConfig.Certificates = nil
		} else {
			tlsConfig.Certificates = []tls.Certificate{{
				Certificate: [][]byte{id.Certificate.Raw},
				PrivateKey:  id.PrivateKey,
				Leaf:        id.Certificate,
			}}
		}

		transport.TLSClientConfig = tlsConfig
	}
}

// Identity returns the certificate currently used for signing.
func (c *Client) Identity() *identity.Identity {
	return c.identity.Load()
}

// Enroll redeems a single-use token. No signature is sent because the agent has
// no certificate yet.
func (c *Client) Enroll(ctx context.Context, request protocol.EnrollRequest) (*protocol.EnrollResponse, error) {
	var response protocol.EnrollResponse

	if err := c.do(ctx, http.MethodPost, "/api/agent/v1/enroll", nil, request, &response); err != nil {
		return nil, err
	}

	return &response, nil
}

// Heartbeat reports liveness and Docker reachability.
func (c *Client) Heartbeat(ctx context.Context, request protocol.HeartbeatRequest) (*protocol.HeartbeatResponse, error) {
	var response protocol.HeartbeatResponse

	if err := c.do(ctx, http.MethodPost, "/api/agent/v1/heartbeat", nil, request, &response); err != nil {
		return nil, err
	}

	return &response, nil
}

// Inventory submits a full host snapshot.
func (c *Client) Inventory(ctx context.Context, request protocol.InventoryRequest) error {
	return c.do(ctx, http.MethodPost, "/api/agent/v1/inventory", nil, request, nil)
}

// Metrics submits a batch of host samples.
func (c *Client) Metrics(ctx context.Context, request protocol.MetricsRequest) error {
	return c.do(ctx, http.MethodPost, "/api/agent/v1/metrics", nil, request, nil)
}

// NextCommand long-polls for work. A nil command means the poll elapsed without
// work (HTTP 204).
func (c *Client) NextCommand(ctx context.Context, waitSeconds int) (*protocol.Command, error) {
	query := url.Values{}
	query.Set("wait", fmt.Sprintf("%d", waitSeconds))

	body, err := c.doRaw(ctx, http.MethodGet, "/api/agent/v1/commands/next", query, nil)
	if err != nil {
		return nil, err
	}

	if body == nil || len(body) == 0 {
		return nil, nil
	}

	var envelope protocol.CommandEnvelope
	if err := json.Unmarshal(body, &envelope); err != nil {
		return nil, fmt.Errorf("decode command: %w", err)
	}

	if envelope.Command.ID == "" {
		return nil, errors.New("control plane returned a command without an ID")
	}

	return &envelope.Command, nil
}

// Acknowledge records that the agent accepted a command.
func (c *Client) Acknowledge(ctx context.Context, commandID string) error {
	path := "/api/agent/v1/commands/" + url.PathEscape(commandID) + "/ack"

	return c.do(ctx, http.MethodPost, path, nil, nil, nil)
}

// ReportResult reports the terminal outcome of a command.
func (c *Client) ReportResult(ctx context.Context, commandID string, result protocol.CommandResultRequest) error {
	path := "/api/agent/v1/commands/" + url.PathEscape(commandID) + "/result"

	return c.do(ctx, http.MethodPost, path, nil, result, nil)
}

// RotateCertificate replaces the agent certificate with a fresh CSR.
func (c *Client) RotateCertificate(ctx context.Context, csrPEM string) (*protocol.RotateResponse, error) {
	var response protocol.RotateResponse

	request := protocol.RotateRequest{CSR: csrPEM}
	if err := c.do(ctx, http.MethodPost, "/api/agent/v1/certificates/rotate", nil, request, &response); err != nil {
		return nil, err
	}

	return &response, nil
}

func (c *Client) do(
	ctx context.Context,
	method string,
	path string,
	query url.Values,
	body any,
	out any,
) error {
	var payload []byte

	if body != nil {
		var err error
		payload, err = json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encode request: %w", err)
		}
	}

	response, err := c.doRaw(ctx, method, path, query, payload)
	if err != nil {
		return err
	}

	if out == nil || len(response) == 0 {
		return nil
	}

	if err := json.Unmarshal(response, out); err != nil {
		return fmt.Errorf("decode response: %w", err)
	}

	return nil
}

func (c *Client) doRaw(
	ctx context.Context,
	method string,
	path string,
	query url.Values,
	payload []byte,
) ([]byte, error) {
	endpoint := *c.base
	endpoint.Path = strings.TrimRight(c.base.Path, "/") + path
	endpoint.RawPath = ""
	endpoint.RawQuery = ""
	endpoint.Fragment = ""

	if query != nil {
		endpoint.RawQuery = query.Encode()
	}

	var reader io.Reader
	if payload != nil {
		reader = bytes.NewReader(payload)
	}

	request, err := http.NewRequestWithContext(ctx, method, endpoint.String(), reader)
	if err != nil {
		return nil, fmt.Errorf("build request: %w", err)
	}

	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", userAgent)

	if payload != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	if id := c.identity.Load(); id != nil {
		if err := c.sign(request, id, payload); err != nil {
			return nil, err
		}
	}

	response, err := c.httpClient.Do(request)
	if err != nil {
		return nil, fmt.Errorf("%s %s: %w", method, path, err)
	}

	defer func() {
		_ = response.Body.Close()
	}()

	body, err := io.ReadAll(io.LimitReader(response.Body, maxResponseBytes))
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}

	if response.StatusCode == http.StatusNoContent || response.StatusCode == http.StatusNotModified {
		return nil, nil
	}

	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return nil, decodeError(response.StatusCode, body)
	}

	return body, nil
}

func (c *Client) sign(request *http.Request, id *identity.Identity, payload []byte) error {
	timestamp := fmt.Sprintf("%d", c.now().UTC().Unix())

	nonce, err := c.nonce()
	if err != nil {
		return err
	}

	bodyHash := sha256.Sum256(payload)

	canonical := strings.Join([]string{
		request.Method,
		request.URL.RequestURI(),
		timestamp,
		nonce,
		hex.EncodeToString(bodyHash[:]),
	}, "\n")

	digest := sha256.Sum256([]byte(canonical))

	signature, err := rsa.SignPKCS1v15(c.random, id.PrivateKey, crypto.SHA256, digest[:])
	if err != nil {
		return fmt.Errorf("sign request: %w", err)
	}

	request.Header.Set("X-CloudForge-Fingerprint", id.Fingerprint)
	request.Header.Set("X-CloudForge-Timestamp", timestamp)
	request.Header.Set("X-CloudForge-Nonce", nonce)
	request.Header.Set("X-CloudForge-Signature", base64.StdEncoding.EncodeToString(signature))

	return nil
}

func (c *Client) nonce() (string, error) {
	raw := make([]byte, 24)

	if _, err := io.ReadFull(c.random, raw); err != nil {
		return "", fmt.Errorf("generate nonce: %w", err)
	}

	return base64.RawURLEncoding.EncodeToString(raw), nil
}

func decodeError(status int, body []byte) error {
	apiErr := &APIError{
		StatusCode: status,
		Body:       strings.TrimSpace(string(body)),
	}

	var envelope struct {
		Message string              `json:"message"`
		Errors  map[string][]string `json:"errors"`
	}

	if err := json.Unmarshal(body, &envelope); err == nil {
		apiErr.Message = envelope.Message
		apiErr.Errors = envelope.Errors
	}

	if apiErr.Message == "" {
		apiErr.Message = strings.TrimSpace(string(body))
	}

	return apiErr
}
