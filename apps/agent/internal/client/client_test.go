package client

import (
	"context"
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/identity"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

var noncePattern = regexp.MustCompile(`^[A-Za-z0-9_-]{16,128}$`)

type capturedRequest struct {
	Canonical string
	Nonce     string
	Timestamp string
	Body      []byte
}

func TestSignedRequestsUseTheControlPlaneCanonicalString(t *testing.T) {
	t.Parallel()

	id := testIdentity(t, "server-one")

	var (
		mu       sync.Mutex
		captured []capturedRequest
	)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, err := io.ReadAll(r.Body)
		if err != nil {
			t.Errorf("read body: %v", err)
		}

		timestamp := r.Header.Get("X-CloudForge-Timestamp")
		nonce := r.Header.Get("X-CloudForge-Nonce")
		fingerprint := r.Header.Get("X-CloudForge-Fingerprint")

		if fingerprint != id.Fingerprint {
			t.Errorf("unexpected fingerprint %q", fingerprint)
		}

		bodyHash := sha256.Sum256(body)
		canonical := strings.Join([]string{
			r.Method,
			r.RequestURI,
			timestamp,
			nonce,
			hex.EncodeToString(bodyHash[:]),
		}, "\n")

		digest := sha256.Sum256([]byte(canonical))

		signature, err := base64.StdEncoding.DecodeString(r.Header.Get("X-CloudForge-Signature"))
		if err != nil {
			t.Errorf("decode signature: %v", err)
		}

		if err := rsa.VerifyPKCS1v15(&id.PrivateKey.PublicKey, crypto.SHA256, digest[:], signature); err != nil {
			t.Errorf("signature verification failed: %v", err)
		}

		mu.Lock()
		captured = append(captured, capturedRequest{Canonical: canonical, Nonce: nonce, Timestamp: timestamp, Body: body})
		mu.Unlock()

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok","server_status":"ready","heartbeat_interval_seconds":30,"server_time":"2026-11-11T12:00:00+00:00"}`))
	}))
	defer server.Close()

	api, err := New(server.URL, id, nil, 5*time.Second)
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	response, err := api.Heartbeat(context.Background(), protocol.HeartbeatRequest{
		AgentVersion:    "1.0.0",
		DockerAvailable: true,
		DockerVersion:   "27.1.1",
	})
	if err != nil {
		t.Fatalf("heartbeat: %v", err)
	}

	if response.ServerStatus != "ready" {
		t.Fatalf("unexpected status %q", response.ServerStatus)
	}

	if _, err := api.Heartbeat(context.Background(), protocol.HeartbeatRequest{AgentVersion: "1.0.0"}); err != nil {
		t.Fatalf("second heartbeat: %v", err)
	}

	mu.Lock()
	defer mu.Unlock()

	if len(captured) != 2 {
		t.Fatalf("expected two captured requests, got %d", len(captured))
	}

	if !noncePattern.MatchString(captured[0].Nonce) {
		t.Fatalf("nonce %q does not match the required format", captured[0].Nonce)
	}

	if _, err := time.ParseDuration(captured[0].Timestamp + "s"); err != nil {
		t.Fatalf("timestamp %q is not unix seconds", captured[0].Timestamp)
	}

	if captured[0].Nonce == captured[1].Nonce {
		t.Fatal("nonces must be single-use")
	}

	var body map[string]any
	if err := json.Unmarshal(captured[0].Body, &body); err != nil {
		t.Fatalf("decode request body: %v", err)
	}

	if body["agent_version"] != "1.0.0" {
		t.Fatalf("unexpected body %#v", body)
	}
}

func TestSignedRequestsRespectABasePath(t *testing.T) {
	t.Parallel()

	id := testIdentity(t, "server-one")

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/cloudforge/api/agent/v1/heartbeat" {
			t.Errorf("unexpected path %q", r.URL.Path)
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	}))
	defer server.Close()

	api, err := New(server.URL+"/cloudforge", id, nil, 5*time.Second)
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	if _, err := api.Heartbeat(context.Background(), protocol.HeartbeatRequest{AgentVersion: "1.0.0"}); err != nil {
		t.Fatalf("heartbeat: %v", err)
	}
}

func TestNextCommandHandlesNoContentAndWork(t *testing.T) {
	t.Parallel()

	id := testIdentity(t, "server-one")

	var calls int

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("wait") != "25" {
			t.Errorf("unexpected wait %q", r.URL.Query().Get("wait"))
		}

		calls++

		if calls == 1 {
			w.WriteHeader(http.StatusNoContent)

			return
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"command":{"id":"cmd-1","operation":"server.health","payload":{},"issued_at":"2026-11-11T12:00:00+00:00","expires_at":"2026-11-11T12:05:00+00:00","attempt":1}}`))
	}))
	defer server.Close()

	api, err := New(server.URL, id, nil, 5*time.Second)
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	command, err := api.NextCommand(context.Background(), 25)
	if err != nil {
		t.Fatalf("first poll: %v", err)
	}

	if command != nil {
		t.Fatalf("expected no command, got %#v", command)
	}

	command, err = api.NextCommand(context.Background(), 25)
	if err != nil {
		t.Fatalf("second poll: %v", err)
	}

	if command == nil || command.ID != "cmd-1" || command.Operation != "server.health" {
		t.Fatalf("unexpected command %#v", command)
	}
}

func TestApiErrorCarriesMessageAndStatus(t *testing.T) {
	t.Parallel()

	id := testIdentity(t, "server-one")

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"message":"The agent certificate is not active."}`))
	}))
	defer server.Close()

	api, err := New(server.URL, id, nil, 5*time.Second)
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	_, err = api.Heartbeat(context.Background(), protocol.HeartbeatRequest{AgentVersion: "1.0.0"})
	if err == nil {
		t.Fatal("expected an error")
	}

	if !IsUnauthorized(err) {
		t.Fatalf("expected an unauthorized error, got %v", err)
	}

	var apiErr *APIError
	if !errors.As(err, &apiErr) {
		t.Fatalf("expected APIError, got %T", err)
	}

	if apiErr.Message != "The agent certificate is not active." {
		t.Fatalf("unexpected message %q", apiErr.Message)
	}
}

func TestCustomCABundleIsTrusted(t *testing.T) {
	t.Parallel()

	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	}))
	defer server.Close()

	caPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: server.Certificate().Raw})

	api, err := NewAnonymous(server.URL, caPEM, 5*time.Second)
	if err != nil {
		t.Fatalf("new client: %v", err)
	}

	if _, err := api.Heartbeat(context.Background(), protocol.HeartbeatRequest{AgentVersion: "1.0.0"}); err != nil {
		t.Fatalf("heartbeat over trusted TLS: %v", err)
	}
}

func TestUnreadableCABundleIsRejected(t *testing.T) {
	t.Parallel()

	if _, err := NewAnonymous("https://example.com", []byte("not a certificate"), 5*time.Second); err == nil {
		t.Fatal("expected an invalid CA bundle to be rejected")
	}
}

func testIdentity(t *testing.T, serverID string) *identity.Identity {
	t.Helper()

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: serverID},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create certificate: %v", err)
	}

	cert, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse certificate: %v", err)
	}

	return &identity.Identity{
		PrivateKey:  key,
		Certificate: cert,
		CertPEM:     pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}),
		Fingerprint: identity.Fingerprint(cert),
	}
}
