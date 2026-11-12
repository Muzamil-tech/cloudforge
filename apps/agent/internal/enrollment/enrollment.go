// Package enrollment redeems a single-use enrollment token, verifies the issued
// certificate against the returned CA, and writes the agent identity to disk.
package enrollment

import (
	"context"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"runtime"
	"strings"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/client"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/docker"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/identity"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/metrics"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

// Options describes one enrollment attempt.
type Options struct {
	ControlPlaneURL string
	ServerID        string
	Token           string
	MachineID       string
	DataDir         string
	DockerSocket    string
	CAFile          string
	AgentVersion    string
	Timeout         time.Duration
	Force           bool
}

// Result reports the identity that now backs the agent.
type Result struct {
	Identity    *identity.Identity
	Certificate *x509.Certificate
	CaPEM       []byte
}

// Enroll performs the full enrollment exchange. It refuses to overwrite an
// existing identity unless Force is set, because replacing the private key
// without revoking the previous certificate leaves a dangling credential.
func Enroll(ctx context.Context, options Options) (*Result, error) {
	if strings.TrimSpace(options.ServerID) == "" {
		return nil, errors.New("server ID is required")
	}

	if strings.TrimSpace(options.Token) == "" {
		return nil, errors.New("enrollment token is required")
	}

	machineID := identity.MachineID()

	if override := strings.TrimSpace(options.MachineID); override != "" {
		machineID = identity.NormalizeMachineID(override)

		if machineID == "" {
			return nil, errors.New("machine ID override must be exactly 32 hexadecimal characters")
		}
	}

	keyPath, certPath, _ := identity.Paths(options.DataDir)

	if !options.Force {
		if _, err := os.Stat(keyPath); err == nil {
			return nil, fmt.Errorf("agent identity already exists at %s; revoke the certificate before re-enrolling or pass --force", keyPath)
		}

		if _, err := os.Stat(certPath); err == nil {
			return nil, fmt.Errorf("agent certificate already exists at %s; revoke the certificate before re-enrolling", certPath)
		}
	}

	var caPEM []byte

	if options.CAFile != "" {
		contents, err := os.ReadFile(options.CAFile)
		if err != nil {
			return nil, fmt.Errorf("read CA file: %w", err)
		}

		if block, _ := pem.Decode(contents); block == nil || block.Type != "CERTIFICATE" {
			return nil, fmt.Errorf("CA file %s does not contain a PEM certificate", options.CAFile)
		}

		caPEM = contents
	}

	if options.Timeout <= 0 {
		options.Timeout = 45 * time.Second
	}

	api, err := client.NewAnonymous(options.ControlPlaneURL, caPEM, options.Timeout)
	if err != nil {
		return nil, err
	}

	key, err := identity.Generate()
	if err != nil {
		return nil, err
	}

	csrPEM, err := identity.CreateCSR(key, options.ServerID)
	if err != nil {
		return nil, err
	}

	hostname, err := os.Hostname()
	if err != nil || hostname == "" {
		hostname = "unknown"
	}

	host, _ := metrics.New().HostInfo()

	dockerVersion := ""

	if dockerClient, dockerErr := docker.New(options.DockerSocket); dockerErr == nil {
		if summary, inspectErr := dockerClient.Inspect(ctx); inspectErr == nil && summary.Available {
			dockerVersion = summary.Version
		}
	}

	response, err := api.Enroll(ctx, protocol.EnrollRequest{
		Token:           options.Token,
		CSR:             string(csrPEM),
		ProtocolVersion: protocol.Version,
		AgentVersion:    options.AgentVersion,
		Hostname:        hostname,
		OperatingSystem: host.OperatingSystem,
		Architecture:    runtime.GOARCH,
		DockerVersion:   dockerVersion,
		MachineID:       machineID,
	})
	if err != nil {
		return nil, fmt.Errorf("enrollment failed: %w", err)
	}

	if response.ProtocolVersion != "" && response.ProtocolVersion != protocol.Version {
		return nil, fmt.Errorf("control plane speaks protocol %s but this agent speaks %s", response.ProtocolVersion, protocol.Version)
	}

	if response.Server.ID != options.ServerID {
		return nil, fmt.Errorf("control plane enrolled server %s instead of %s", response.Server.ID, options.ServerID)
	}

	certPEM := []byte(response.Certificate.PEM)
	assertionCaPEM := []byte(response.CACertificate)

	certificate, err := identity.VerifyIssued(certPEM, assertionCaPEM, options.ServerID, response.Certificate.FingerprintSHA256)
	if err != nil {
		return nil, err
	}

	if err := identity.WriteIdentity(options.DataDir, key, certPEM, assertionCaPEM); err != nil {
		return nil, fmt.Errorf("persist agent identity: %w", err)
	}

	loaded, err := identity.Load(keyPath, certPath)
	if err != nil {
		return nil, fmt.Errorf("reload agent identity: %w", err)
	}

	return &Result{
		Identity:    loaded,
		Certificate: certificate,
		CaPEM:       assertionCaPEM,
	}, nil
}
