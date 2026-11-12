package identity

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

const keyBits = 2048

// machineIDPaths are the standard locations for the host's stable identifier.
var machineIDPaths = []string{"/etc/machine-id", "/var/lib/dbus/machine-id"}

// Identity is the agent's private key and issued client certificate.
type Identity struct {
	PrivateKey  *rsa.PrivateKey
	Certificate *x509.Certificate
	CertPEM     []byte
	Fingerprint string
}

// Load reads a PEM private key and certificate from disk.
func Load(keyPath, certPath string) (*Identity, error) {
	keyPEM, err := os.ReadFile(keyPath)
	if err != nil {
		return nil, fmt.Errorf("read private key: %w", err)
	}

	key, err := parsePrivateKey(keyPEM)
	if err != nil {
		return nil, err
	}

	certPEM, err := os.ReadFile(certPath)
	if err != nil {
		return nil, fmt.Errorf("read certificate: %w", err)
	}

	cert, err := ParseCertificate(certPEM)
	if err != nil {
		return nil, err
	}

	return &Identity{
		PrivateKey:  key,
		Certificate: cert,
		CertPEM:     certPEM,
		Fingerprint: Fingerprint(cert),
	}, nil
}

// Generate creates a new RSA private key.
func Generate() (*rsa.PrivateKey, error) {
	key, err := rsa.GenerateKey(rand.Reader, keyBits)
	if err != nil {
		return nil, fmt.Errorf("generate private key: %w", err)
	}

	return key, nil
}

// CreateCSR builds a PEM certificate signing request whose common name is the
// CloudForge server ID. The control plane verifies this binding.
func CreateCSR(key *rsa.PrivateKey, serverID string) ([]byte, error) {
	if serverID == "" {
		return nil, errors.New("server id is required for the certificate signing request")
	}

	der, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject: pkix.Name{CommonName: serverID},
	}, key)
	if err != nil {
		return nil, fmt.Errorf("create certificate signing request: %w", err)
	}

	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: der}), nil
}

// ParseCertificate parses the first certificate in a PEM bundle.
func ParseCertificate(certPEM []byte) (*x509.Certificate, error) {
	block, _ := pem.Decode(certPEM)
	if block == nil || block.Type != "CERTIFICATE" {
		return nil, errors.New("certificate is not valid PEM")
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("parse certificate: %w", err)
	}

	return cert, nil
}

// Fingerprint returns the lowercase SHA-256 fingerprint used by the control
// plane to identify the certificate.
func Fingerprint(cert *x509.Certificate) string {
	sum := sha256.Sum256(cert.Raw)

	return hex.EncodeToString(sum[:])
}

// MachineID returns the host's stable machine identifier, or an empty string
// when no standard file contains a valid value. Bootstrap enrollment is bound
// to this value by the control plane.
func MachineID() string {
	return MachineIDFromFiles(machineIDPaths...)
}

// MachineIDFromFiles reads the first valid machine identifier from paths. It
// is the testable form of MachineID.
func MachineIDFromFiles(paths ...string) string {
	for _, path := range paths {
		contents, err := os.ReadFile(path)
		if err != nil {
			continue
		}

		if machineID := NormalizeMachineID(string(contents)); machineID != "" {
			return machineID
		}
	}

	return ""
}

// NormalizeMachineID accepts a 32-character hexadecimal machine identifier and
// returns it in lowercase. Any other value is rejected.
func NormalizeMachineID(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))

	if len(value) != 32 {
		return ""
	}

	for _, character := range value {
		if (character < '0' || character > '9') && (character < 'a' || character > 'f') {
			return ""
		}
	}

	return value
}

// EncodePrivateKey serialises a key as PKCS#8 PEM.
func EncodePrivateKey(key *rsa.PrivateKey) ([]byte, error) {
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return nil, fmt.Errorf("marshal private key: %w", err)
	}

	return pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), nil
}

// WriteIdentity atomically writes the private key, certificate and CA.
func WriteIdentity(dataDir string, key *rsa.PrivateKey, certPEM, caPEM []byte) error {
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		return fmt.Errorf("create data directory: %w", err)
	}

	keyPEM, err := EncodePrivateKey(key)
	if err != nil {
		return err
	}

	if err := writeFileAtomic(filepath.Join(dataDir, "agent.key"), keyPEM, 0o600); err != nil {
		return err
	}

	if err := writeFileAtomic(filepath.Join(dataDir, "agent.crt"), certPEM, 0o644); err != nil {
		return err
	}

	if err := writeFileAtomic(filepath.Join(dataDir, "ca.crt"), caPEM, 0o644); err != nil {
		return err
	}

	return nil
}

// Paths returns the conventional identity file locations inside dataDir.
func Paths(dataDir string) (keyPath, certPath, caPath string) {
	return filepath.Join(dataDir, "agent.key"),
		filepath.Join(dataDir, "agent.crt"),
		filepath.Join(dataDir, "ca.crt")
}

func parsePrivateKey(keyPEM []byte) (*rsa.PrivateKey, error) {
	block, _ := pem.Decode(keyPEM)
	if block == nil {
		return nil, errors.New("private key is not valid PEM")
	}

	if key, err := x509.ParsePKCS8PrivateKey(block.Bytes); err == nil {
		rsaKey, ok := key.(*rsa.PrivateKey)
		if !ok {
			return nil, errors.New("private key is not RSA")
		}

		return rsaKey, nil
	}

	if key, err := x509.ParsePKCS1PrivateKey(block.Bytes); err == nil {
		return key, nil
	}

	return nil, errors.New("private key could not be parsed")
}

func writeFileAtomic(path string, contents []byte, mode os.FileMode) error {
	temporary := path + ".tmp"

	if err := os.WriteFile(temporary, contents, mode); err != nil {
		return fmt.Errorf("write %s: %w", path, err)
	}

	if err := os.Chmod(temporary, mode); err != nil && !errors.Is(err, os.ErrPermission) {
		// Chmod is a no-op on some filesystems; a permission error should not
		// prevent enrollment from completing.
		_ = err
	}

	if err := os.Rename(temporary, path); err != nil {
		_ = os.Remove(temporary)

		return fmt.Errorf("publish %s: %w", path, err)
	}

	return nil
}
