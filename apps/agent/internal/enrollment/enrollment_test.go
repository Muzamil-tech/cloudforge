package enrollment

import (
	"context"
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/identity"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

type enrollmentFixture struct {
	server         *httptest.Server
	caFile         string
	caPEM          []byte
	serverID       string
	requests       []protocol.EnrollRequest
	certificatePEM func(csr *x509.CertificateRequest) ([]byte, string)
}

// newEnrollmentFixture starts an HTTPS enrollment endpoint that signs the
// submitted CSR with a freshly generated CA, mirroring the control plane.
func newEnrollmentFixture(t *testing.T, serverID string) *enrollmentFixture {
	t.Helper()

	caKey, caCert := testCA(t)
	caPEM := pemFor(caCert)

	fixture := &enrollmentFixture{
		serverID: serverID,
		caPEM:    caPEM,
	}

	fixture.certificatePEM = func(csr *x509.CertificateRequest) ([]byte, string) {
		return signCSR(t, caKey, caCert, csr, csr.Subject.CommonName, caPEM)
	}

	var mu sync.Mutex

	fixture.server = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/agent/v1/enroll" {
			t.Errorf("unexpected enrollment path %q", r.URL.Path)

			http.NotFound(w, r)

			return
		}

		if r.Method != http.MethodPost {
			t.Errorf("unexpected enrollment method %q", r.Method)
		}

		var request protocol.EnrollRequest
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Errorf("decode enrollment request: %v", err)

			http.Error(w, "bad request", http.StatusBadRequest)

			return
		}

		mu.Lock()
		fixture.requests = append(fixture.requests, request)
		mu.Unlock()

		block, _ := pem.Decode([]byte(request.CSR))
		if block == nil || block.Type != "CERTIFICATE REQUEST" {
			t.Errorf("enrollment request did not contain a CSR")

			http.Error(w, "bad request", http.StatusBadRequest)

			return
		}

		csr, err := x509.ParseCertificateRequest(block.Bytes)
		if err != nil {
			t.Errorf("parse csr: %v", err)

			http.Error(w, "bad request", http.StatusBadRequest)

			return
		}

		certPEM, fingerprint := fixture.certificatePEM(csr)

		certificate, err := identity.ParseCertificate(certPEM)
		if err != nil {
			t.Errorf("parse signed cert: %v", err)

			http.Error(w, "internal error", http.StatusInternalServerError)

			return
		}

		w.Header().Set("Content-Type", "application/json")

		_ = json.NewEncoder(w).Encode(map[string]any{
			"protocol_version": protocol.Version,
			"server": map[string]any{
				"id":       fixture.serverID,
				"name":     "node-one",
				"hostname": "node-one",
			},
			"certificate": map[string]any{
				"pem":                string(certPEM),
				"serial":             certificate.SerialNumber.String(),
				"fingerprint_sha256": fingerprint,
				"expires_at":         certificate.NotAfter.Format(time.RFC3339),
			},
			"ca_certificate":                string(caPEM),
			"heartbeat_interval_seconds":    30,
			"command_poll_interval_seconds": 5,
		})
	}))

	t.Cleanup(fixture.server.Close)

	// The agent trusts the TLS server certificate explicitly; the enrollment
	// response separately carries the issuing CA for the client certificate.
	fixture.caFile = filepath.Join(t.TempDir(), "tls-ca.pem")

	tlsPEM := pemFor(fixture.server.Certificate())
	if err := os.WriteFile(fixture.caFile, tlsPEM, 0o600); err != nil {
		t.Fatalf("write TLS CA file: %v", err)
	}

	return fixture
}

func TestEnrollPersistsAVerifiedIdentity(t *testing.T) {
	t.Parallel()

	const serverID = "5c9f0f7a-6e0e-4d43-9f0f-2b1c3d4e5f60"

	fixture := newEnrollmentFixture(t, serverID)
	dataDir := t.TempDir()

	result, err := Enroll(context.Background(), Options{
		ControlPlaneURL: fixture.server.URL,
		ServerID:        serverID,
		Token:           "cf_enroll_single_use",
		DataDir:         dataDir,
		DockerSocket:    "/var/run/docker.sock",
		CAFile:          fixture.caFile,
		AgentVersion:    "1.0.0",
		Timeout:         5 * time.Second,
	})
	if err != nil {
		t.Fatalf("enroll: %v", err)
	}

	if result.Identity == nil || result.Certificate == nil {
		t.Fatal("expected an identity and certificate")
	}

	if result.Certificate.Subject.CommonName != serverID {
		t.Fatalf("unexpected certificate subject %q", result.Certificate.Subject.CommonName)
	}

	if result.Identity.Fingerprint != identity.Fingerprint(result.Certificate) {
		t.Fatal("stored identity does not match the issued certificate")
	}

	keyPath, certPath, caPath := identity.Paths(dataDir)

	for _, path := range []string{keyPath, certPath, caPath} {
		if _, err := os.Stat(path); err != nil {
			t.Fatalf("expected %s to exist: %v", path, err)
		}
	}

	for _, request := range fixture.requests {
		if request.Token != "cf_enroll_single_use" {
			t.Fatalf("unexpected token %q", request.Token)
		}

		if request.ProtocolVersion != protocol.Version || request.AgentVersion != "1.0.0" {
			t.Fatalf("unexpected protocol metadata %#v", request)
		}

		block, _ := pem.Decode([]byte(request.CSR))
		if block == nil {
			t.Fatal("expected a PEM certificate request")
		}

		csr, err := x509.ParseCertificateRequest(block.Bytes)
		if err != nil {
			t.Fatalf("parse csr: %v", err)
		}

		if csr.Subject.CommonName != serverID {
			t.Fatalf("CSR is bound to %q", csr.Subject.CommonName)
		}
	}

	loaded, err := identity.Load(keyPath, certPath)
	if err != nil {
		t.Fatalf("reload identity: %v", err)
	}

	if loaded.Fingerprint != result.Identity.Fingerprint {
		t.Fatal("persisted identity does not match the enrollment result")
	}
}

func TestEnrollSendsANormalizedMachineIDOverride(t *testing.T) {
	t.Parallel()

	const serverID = "0d6a9c1b-2f3e-4a5b-8c7d-9e0f1a2b3c4d"

	fixture := newEnrollmentFixture(t, serverID)

	_, err := Enroll(context.Background(), Options{
		ControlPlaneURL: fixture.server.URL,
		ServerID:        serverID,
		Token:           "cf_enroll_machine_id",
		MachineID:       "0123456789ABCDEF0123456789ABCDEF",
		DataDir:         t.TempDir(),
		DockerSocket:    "/var/run/docker.sock",
		CAFile:          fixture.caFile,
		AgentVersion:    "1.0.0",
		Timeout:         5 * time.Second,
	})
	if err != nil {
		t.Fatalf("enroll: %v", err)
	}

	if len(fixture.requests) != 1 {
		t.Fatalf("expected one enrollment request, got %d", len(fixture.requests))
	}

	if got, want := fixture.requests[0].MachineID, "0123456789abcdef0123456789abcdef"; got != want {
		t.Fatalf("machine_id = %q, want %q", got, want)
	}
}

func TestEnrollRejectsAnInvalidMachineIDOverride(t *testing.T) {
	t.Parallel()

	_, err := Enroll(context.Background(), Options{
		ControlPlaneURL: "https://127.0.0.1:1",
		ServerID:        "server-one",
		Token:           "cf_enroll_token",
		MachineID:       "not-a-machine-id",
		DataDir:         t.TempDir(),
		DockerSocket:    "/var/run/docker.sock",
	})
	if err == nil || !strings.Contains(err.Error(), "machine ID override") {
		t.Fatalf("expected a machine ID validation error, got %v", err)
	}
}

func TestEnrollRefusesToOverwriteAnExistingIdentity(t *testing.T) {
	t.Parallel()

	dataDir := t.TempDir()

	if err := os.WriteFile(filepath.Join(dataDir, "agent.key"), []byte("existing"), 0o600); err != nil {
		t.Fatalf("seed identity: %v", err)
	}

	// The refusal must happen before any network request, so an unreachable
	// control plane is sufficient.
	_, err := Enroll(context.Background(), Options{
		ControlPlaneURL: "https://127.0.0.1:1",
		ServerID:        "server-one",
		Token:           "cf_enroll_token",
		DataDir:         dataDir,
		DockerSocket:    "/var/run/docker.sock",
	})
	if err == nil {
		t.Fatal("expected an existing identity to block enrollment")
	}
}

func TestEnrollForceOverwritesAnExistingIdentity(t *testing.T) {
	t.Parallel()

	fixture := newEnrollmentFixture(t, "server-one")
	dataDir := t.TempDir()

	if err := os.WriteFile(filepath.Join(dataDir, "agent.key"), []byte("stale"), 0o600); err != nil {
		t.Fatalf("seed identity: %v", err)
	}

	if _, err := Enroll(context.Background(), Options{
		ControlPlaneURL: fixture.server.URL,
		ServerID:        "server-one",
		Token:           "cf_enroll_token",
		DataDir:         dataDir,
		DockerSocket:    "/var/run/docker.sock",
		CAFile:          fixture.caFile,
		Timeout:         5 * time.Second,
		Force:           true,
	}); err != nil {
		t.Fatalf("forced enroll: %v", err)
	}

	keyPEM, err := os.ReadFile(filepath.Join(dataDir, "agent.key"))
	if err != nil {
		t.Fatalf("read key: %v", err)
	}

	if string(keyPEM) == "stale" {
		t.Fatal("expected the stale key to be replaced")
	}
}

func TestEnrollRejectsMismatchedServerOrProtocol(t *testing.T) {
	t.Parallel()

	t.Run("server id", func(t *testing.T) {
		t.Parallel()

		fixture := newEnrollmentFixture(t, "a-different-server")
		dataDir := t.TempDir()

		_, err := Enroll(context.Background(), Options{
			ControlPlaneURL: fixture.server.URL,
			ServerID:        "requested-server",
			Token:           "cf_enroll_token",
			DataDir:         dataDir,
			DockerSocket:    "/var/run/docker.sock",
			CAFile:          fixture.caFile,
			Timeout:         5 * time.Second,
		})
		if err == nil {
			t.Fatal("expected a server ID mismatch to be rejected")
		}

		assertNoIdentity(t, dataDir)
	})

	t.Run("protocol", func(t *testing.T) {
		t.Parallel()

		server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")

			_ = json.NewEncoder(w).Encode(map[string]any{
				"protocol_version": "2.0",
				"server":           map[string]any{"id": "server-one"},
				"certificate":      map[string]any{"pem": "", "fingerprint_sha256": ""},
				"ca_certificate":   "",
			})
		}))
		defer server.Close()

		caFile := filepath.Join(t.TempDir(), "ca.pem")
		if err := os.WriteFile(caFile, pemFor(server.Certificate()), 0o600); err != nil {
			t.Fatalf("write CA: %v", err)
		}

		dataDir := t.TempDir()

		_, err := Enroll(context.Background(), Options{
			ControlPlaneURL: server.URL,
			ServerID:        "server-one",
			Token:           "cf_enroll_token",
			DataDir:         dataDir,
			DockerSocket:    "/var/run/docker.sock",
			CAFile:          caFile,
			Timeout:         5 * time.Second,
		})
		if err == nil {
			t.Fatal("expected a protocol mismatch to be rejected")
		}

		assertNoIdentity(t, dataDir)
	})
}

func TestEnrollRejectsACertificateNotSignedByTheAdvertisedCA(t *testing.T) {
	t.Parallel()

	fixture := newEnrollmentFixture(t, "server-one")

	wrongKey, wrongCA := testCA(t)

	fixture.certificatePEM = func(csr *x509.CertificateRequest) ([]byte, string) {
		// The certificate is valid but signed by an unrelated CA while the
		// response advertises the fixture CA.
		certPEM, fingerprint := signCSR(t, wrongKey, wrongCA, csr, csr.Subject.CommonName, fixture.caPEM)
		_ = wrongKey

		return certPEM, fingerprint
	}

	dataDir := t.TempDir()

	_, err := Enroll(context.Background(), Options{
		ControlPlaneURL: fixture.server.URL,
		ServerID:        "server-one",
		Token:           "cf_enroll_token",
		DataDir:         dataDir,
		DockerSocket:    "/var/run/docker.sock",
		CAFile:          fixture.caFile,
		Timeout:         5 * time.Second,
	})
	if err == nil {
		t.Fatal("expected a certificate from another CA to be rejected")
	}

	assertNoIdentity(t, dataDir)
}

func TestEnrollRejectsAnInvalidCAFile(t *testing.T) {
	t.Parallel()

	caFile := filepath.Join(t.TempDir(), "ca.pem")
	if err := os.WriteFile(caFile, []byte("not a certificate"), 0o600); err != nil {
		t.Fatalf("write CA: %v", err)
	}

	_, err := Enroll(context.Background(), Options{
		ControlPlaneURL: "https://127.0.0.1:1",
		ServerID:        "server-one",
		Token:           "cf_enroll_token",
		DataDir:         t.TempDir(),
		DockerSocket:    "/var/run/docker.sock",
		CAFile:          caFile,
	})
	if err == nil {
		t.Fatal("expected an invalid CA file to be rejected")
	}
}

func assertNoIdentity(t *testing.T, dataDir string) {
	t.Helper()

	for _, name := range []string{"agent.key", "agent.crt", "ca.crt"} {
		if _, err := os.Stat(filepath.Join(dataDir, name)); !os.IsNotExist(err) {
			t.Fatalf("expected %s not to be written", name)
		}
	}
}

func signCSR(
	t *testing.T,
	caKey *rsa.PrivateKey,
	caCert *x509.Certificate,
	csr *x509.CertificateRequest,
	commonName string,
	advertisedCAPEM []byte,
) ([]byte, string) {
	t.Helper()

	if err := csr.CheckSignature(); err != nil {
		t.Fatalf("CSR signature is invalid: %v", err)
	}

	template := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: commonName},
		NotBefore:    time.Now().Add(-time.Minute),
		NotAfter:     time.Now().Add(24 * time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}

	der, err := x509.CreateCertificate(rand.Reader, template, caCert, csr.PublicKey, caKey)
	if err != nil {
		t.Fatalf("sign certificate: %v", err)
	}

	certificate, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse certificate: %v", err)
	}

	_ = advertisedCAPEM

	return pemFor(certificate), identity.Fingerprint(certificate)
}

func testCA(t *testing.T) (*rsa.PrivateKey, *x509.Certificate) {
	t.Helper()

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate CA key: %v", err)
	}

	template := &x509.Certificate{
		SerialNumber:          big.NewInt(time.Now().UnixNano()),
		Subject:               pkix.Name{CommonName: "CloudForge Enrollment Test CA"},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(24 * time.Hour),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create CA certificate: %v", err)
	}

	certificate, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse CA certificate: %v", err)
	}

	return key, certificate
}

func pemFor(certificate *x509.Certificate) []byte {
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate.Raw})
}
