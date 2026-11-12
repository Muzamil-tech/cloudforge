package identity

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCreateCSRBindsServerID(t *testing.T) {
	t.Parallel()

	key, err := Generate()
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	csrPEM, err := CreateCSR(key, "5c9f0f7a-6e0e-4d43-9f0f-2b1c3d4e5f60")
	if err != nil {
		t.Fatalf("create csr: %v", err)
	}

	block, _ := pem.Decode(csrPEM)
	if block == nil || block.Type != "CERTIFICATE REQUEST" {
		t.Fatalf("expected a certificate request, got %#v", block)
	}

	csr, err := x509.ParseCertificateRequest(block.Bytes)
	if err != nil {
		t.Fatalf("parse csr: %v", err)
	}

	if csr.Subject.CommonName != "5c9f0f7a-6e0e-4d43-9f0f-2b1c3d4e5f60" {
		t.Fatalf("unexpected common name %q", csr.Subject.CommonName)
	}

	if _, err := CreateCSR(key, ""); err == nil {
		t.Fatal("expected an empty server id to be rejected")
	}
}

func TestVerifyIssuedAcceptsMatchingCertificate(t *testing.T) {
	t.Parallel()

	caKey, caCert := makeCA(t)
	key, err := Generate()
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	certPEM, cert := signLeaf(t, caKey, caCert, key, "server-one", time.Now().Add(-time.Minute), time.Now().Add(time.Hour))

	verified, err := VerifyIssued(certPEM, certificatePEM(t, caCert), "server-one", Fingerprint(cert))
	if err != nil {
		t.Fatalf("verify: %v", err)
	}

	if verified.Subject.CommonName != "server-one" {
		t.Fatalf("unexpected subject %q", verified.Subject.CommonName)
	}
}

func TestVerifyIssuedRejectsMismatches(t *testing.T) {
	t.Parallel()

	caKey, caCert := makeCA(t)
	key, err := Generate()
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	certPEM, cert := signLeaf(t, caKey, caCert, key, "server-one", time.Now().Add(-time.Minute), time.Now().Add(time.Hour))
	caPEM := certificatePEM(t, caCert)

	tests := map[string]func() error{
		"wrong server": func() error {
			_, err := VerifyIssued(certPEM, caPEM, "server-two", Fingerprint(cert))

			return err
		},
		"wrong fingerprint": func() error {
			_, err := VerifyIssued(certPEM, caPEM, "server-one", "deadbeef")

			return err
		},
		"wrong ca": func() error {
			_, otherCA := makeCA(t)
			_, err := VerifyIssued(certPEM, certificatePEM(t, otherCA), "server-one", Fingerprint(cert))

			return err
		},
	}

	for name, run := range tests {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			if err := run(); err == nil {
				t.Fatal("expected verification to fail")
			}
		})
	}
}

func TestVerifyIssuedRejectsExpiredCertificate(t *testing.T) {
	t.Parallel()

	caKey, caCert := makeCA(t)
	key, err := Generate()
	if err != nil {
		t.Fatalf("generate key: %v", err)
	}

	certPEM, cert := signLeaf(t, caKey, caCert, key, "server-one", time.Now().Add(-2*time.Hour), time.Now().Add(-time.Hour))

	if _, err := VerifyIssued(certPEM, certificatePEM(t, caCert), "server-one", Fingerprint(cert)); err == nil {
		t.Fatal("expected an expired certificate to be rejected")
	}
}

func TestFingerprintIsLowercaseHex(t *testing.T) {
	t.Parallel()

	_, cert := makeCA(t)
	fingerprint := Fingerprint(cert)

	if len(fingerprint) != 64 {
		t.Fatalf("expected 64 hex characters, got %d", len(fingerprint))
	}

	for _, character := range fingerprint {
		if (character < '0' || character > '9') && (character < 'a' || character > 'f') {
			t.Fatalf("fingerprint contains %q", character)
		}
	}
}

func TestNormalizeMachineID(t *testing.T) {
	t.Parallel()

	const canonical = "5c9f0f7a6e0e4d439f0f2b1c3d4e5f60"

	tests := map[string]string{
		canonical:                                canonical,
		"  " + strings.ToUpper(canonical) + "\n": canonical,
		"uninitialized":                          "",
		"":                                       "",
		strings.Repeat("g", 32):                  "",
		canonical[:31]:                           "",
	}

	for input, expected := range tests {
		if actual := NormalizeMachineID(input); actual != expected {
			t.Fatalf("NormalizeMachineID(%q) = %q, want %q", input, actual, expected)
		}
	}
}

func TestMachineIDFromFilesUsesFirstValidValue(t *testing.T) {
	t.Parallel()

	directory := t.TempDir()
	invalidPath := filepath.Join(directory, "invalid")
	validPath := filepath.Join(directory, "machine-id")
	secondPath := filepath.Join(directory, "dbus-machine-id")

	if err := os.WriteFile(invalidPath, []byte("not-a-machine-id\n"), 0o600); err != nil {
		t.Fatalf("write invalid machine id: %v", err)
	}

	const canonical = "5c9f0f7a6e0e4d439f0f2b1c3d4e5f60"

	if err := os.WriteFile(validPath, []byte(strings.ToUpper(canonical)+"\n"), 0o600); err != nil {
		t.Fatalf("write machine id: %v", err)
	}

	const second = "0123456789abcdef0123456789abcdef"

	if err := os.WriteFile(secondPath, []byte(second+"\n"), 0o600); err != nil {
		t.Fatalf("write second machine id: %v", err)
	}

	actual := MachineIDFromFiles(
		filepath.Join(directory, "missing"),
		invalidPath,
		validPath,
		secondPath,
	)
	if actual != canonical {
		t.Fatalf("MachineIDFromFiles = %q, want %q", actual, canonical)
	}

	if actual := MachineIDFromFiles(filepath.Join(directory, "missing")); actual != "" {
		t.Fatalf("expected no machine id, got %q", actual)
	}
}

func makeCA(t *testing.T) (*rsa.PrivateKey, *x509.Certificate) {
	t.Helper()

	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("generate ca key: %v", err)
	}

	template := &x509.Certificate{
		SerialNumber:          big.NewInt(1),
		Subject:               pkix.Name{CommonName: "CloudForge Test CA"},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(24 * time.Hour),
		IsCA:                  true,
		BasicConstraintsValid: true,
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature,
	}

	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create ca certificate: %v", err)
	}

	cert, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse ca certificate: %v", err)
	}

	return key, cert
}

func signLeaf(
	t *testing.T,
	caKey *rsa.PrivateKey,
	caCert *x509.Certificate,
	key *rsa.PrivateKey,
	commonName string,
	notBefore, notAfter time.Time,
) ([]byte, *x509.Certificate) {
	t.Helper()

	template := &x509.Certificate{
		SerialNumber: big.NewInt(time.Now().UnixNano()),
		Subject:      pkix.Name{CommonName: commonName},
		NotBefore:    notBefore,
		NotAfter:     notAfter,
		KeyUsage:     x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
	}

	der, err := x509.CreateCertificate(rand.Reader, template, caCert, &key.PublicKey, caKey)
	if err != nil {
		t.Fatalf("create leaf certificate: %v", err)
	}

	cert, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatalf("parse leaf certificate: %v", err)
	}

	return certificatePEM(t, cert), cert
}

func certificatePEM(t *testing.T, cert *x509.Certificate) []byte {
	t.Helper()

	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw})
}
