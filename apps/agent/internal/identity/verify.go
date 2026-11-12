package identity

import (
	"crypto/x509"
	"errors"
	"fmt"
	"strings"
	"time"
)

// VerifyIssued checks that an issued certificate is bound to the expected
// server, signed by the supplied CA, currently valid and matches the fingerprint
// reported by the control plane.
func VerifyIssued(certPEM, caPEM []byte, serverID, expectedFingerprint string) (*x509.Certificate, error) {
	certificate, err := ParseCertificate(certPEM)
	if err != nil {
		return nil, fmt.Errorf("issued certificate is invalid: %w", err)
	}

	if certificate.Subject.CommonName != serverID {
		return nil, fmt.Errorf("issued certificate is bound to %q instead of %q", certificate.Subject.CommonName, serverID)
	}

	if expectedFingerprint != "" && !strings.EqualFold(Fingerprint(certificate), expectedFingerprint) {
		return nil, errors.New("issued certificate fingerprint does not match the control plane response")
	}

	caCertificate, err := ParseCertificate(caPEM)
	if err != nil {
		return nil, fmt.Errorf("control plane CA certificate is invalid: %w", err)
	}

	if err := certificate.CheckSignatureFrom(caCertificate); err != nil {
		return nil, fmt.Errorf("issued certificate is not signed by the control plane CA: %w", err)
	}

	now := time.Now()
	if now.Before(certificate.NotBefore) || now.After(certificate.NotAfter) {
		return nil, errors.New("issued certificate is not currently valid")
	}

	return certificate, nil
}
