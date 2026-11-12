// Package docker inspects a local Docker Engine over its Unix socket.
//
// CloudForge never exposes the Docker socket over TCP, never shells out to the
// docker CLI, and never accepts arbitrary container commands in Phase 1. This
// client performs read-only version and info calls used for inventory and
// capability reporting.
package docker

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

const maxResponseBytes = 1 << 20

// Client is a read-only Docker Engine API client bound to one Unix socket.
type Client struct {
	socketPath string
	httpClient *http.Client
}

// New binds the client to a Unix socket path. TCP, SSH and named-pipe endpoints
// are rejected so a compromised config cannot turn the agent into a remote
// Docker proxy.
func New(socketPath string) (*Client, error) {
	path := strings.TrimSpace(socketPath)
	path = strings.TrimPrefix(path, "unix://")

	if path == "" {
		return nil, errors.New("Docker socket path is empty")
	}

	for _, prefix := range []string{"tcp://", "http://", "https://", "ssh://", "npipe://"} {
		if strings.HasPrefix(strings.ToLower(path), prefix) {
			return nil, fmt.Errorf("Docker endpoint %q is not a Unix socket; TCP and SSH endpoints are not permitted", socketPath)
		}
	}

	if !strings.HasPrefix(path, "/") {
		return nil, fmt.Errorf("Docker socket path must be absolute, got %q", socketPath)
	}

	transport := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			dialer := &net.Dialer{Timeout: 5 * time.Second}

			return dialer.DialContext(ctx, "unix", path)
		},
		MaxIdleConns:    2,
		IdleConnTimeout: 30 * time.Second,
	}

	return &Client{
		socketPath: path,
		httpClient: &http.Client{Transport: transport, Timeout: 10 * time.Second},
	}, nil
}

// SocketPath returns the inspected socket path.
func (c *Client) SocketPath() string {
	return c.socketPath
}

// Inspect reports the daemon version, storage driver and rootless status. A
// daemon that is not running is reported as unavailable rather than as an
// error, because Docker is optional for the agent process itself.
func (c *Client) Inspect(ctx context.Context) (protocol.DockerSummary, []protocol.Capability) {
	summary := protocol.DockerSummary{}

	var version versionResponse

	if err := c.get(ctx, "/version", &version); err != nil {
		return summary, nil
	}

	summary.Available = true
	summary.Version = version.Version
	summary.APIVersion = version.APIVersion

	capabilities := []protocol.Capability{{
		Name:    "docker.engine",
		Version: version.Version,
		Metadata: map[string]any{
			"api_version": version.APIVersion,
			"os":          version.Os,
			"arch":        version.Arch,
		},
	}}

	var info infoResponse

	if err := c.get(ctx, "/info", &info); err != nil {
		return summary, capabilities
	}

	summary.StorageDriver = info.Driver
	summary.Rootless = info.IsRootless()

	if info.Driver != "" {
		capabilities = append(capabilities, protocol.Capability{
			Name:    "docker.storage_driver",
			Version: info.Driver,
		})
	}

	if summary.Rootless {
		capabilities = append(capabilities, protocol.Capability{Name: "docker.rootless"})
	}

	if strings.EqualFold(info.Swarm.LocalNodeState, "active") {
		capabilities = append(capabilities, protocol.Capability{Name: "docker.swarm"})
	}

	return summary, capabilities
}

func (c *Client) get(ctx context.Context, path string, out any) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker"+path, nil)
	if err != nil {
		return fmt.Errorf("build Docker request: %w", err)
	}

	request.Header.Set("Accept", "application/json")

	response, err := c.httpClient.Do(request)
	if err != nil {
		return fmt.Errorf("call Docker %s: %w", path, err)
	}

	defer func() {
		_ = response.Body.Close()
	}()

	if response.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxResponseBytes))

		return fmt.Errorf("Docker %s returned HTTP %d", path, response.StatusCode)
	}

	if err := json.NewDecoder(io.LimitReader(response.Body, maxResponseBytes)).Decode(out); err != nil {
		return fmt.Errorf("decode Docker %s: %w", path, err)
	}

	return nil
}

type versionResponse struct {
	Version       string `json:"Version"`
	APIVersion    string `json:"ApiVersion"`
	Os            string `json:"Os"`
	Arch          string `json:"Arch"`
	GitCommit     string `json:"GitCommit"`
	KernelVersion string `json:"KernelVersion"`
}

type infoResponse struct {
	ServerVersion   string `json:"ServerVersion"`
	OperatingSystem string `json:"OperatingSystem"`
	OSType          string `json:"OSType"`
	Architecture    string `json:"Architecture"`
	NCPU            int    `json:"NCPU"`
	MemTotal        int64  `json:"MemTotal"`
	Driver          string `json:"Driver"`
	DockerRootDir   string `json:"DockerRootDir"`
	SecurityOptions []struct {
		Name string `json:"Name"`
	} `json:"SecurityOptions"`
	Swarm struct {
		LocalNodeState string `json:"LocalNodeState"`
	} `json:"Swarm"`
}

func (i infoResponse) IsRootless() bool {
	for _, option := range i.SecurityOptions {
		name := strings.ToLower(option.Name)

		if name == "rootless" || name == "name=rootless" {
			return true
		}
	}

	return false
}
