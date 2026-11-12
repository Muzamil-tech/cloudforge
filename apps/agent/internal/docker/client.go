// Package docker inspects a local Docker Engine over its Unix socket and
// creates the small set of managed database containers that the CloudForge
// resource layer allows.
//
// CloudForge never exposes the Docker socket over TCP, never shells out to the
// docker CLI, and never accepts arbitrary container images or commands. The
// resource manager validates every spec and builds the Docker request itself.
package docker

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/resources"
)

const (
	maxResponseBytes = 1 << 20
	pullTimeout      = 15 * time.Minute
)

var (
	ErrNotFound = errors.New("Docker object not found")

	namePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$`)
	refPattern  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	uuidPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
)

// Client is a Docker Engine API client bound to one Unix socket.
type Client struct {
	socketPath string
	httpClient *http.Client
	pullClient *http.Client
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
		pullClient: &http.Client{Transport: transport.Clone(), Timeout: pullTimeout},
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

// Create provisions one allowlisted database container and returns its
// connection details once. The generated password is never logged.
func (c *Client) Create(ctx context.Context, spec resources.CreateSpec) (resources.Endpoint, error) {
	if err := validateSpec(spec); err != nil {
		return resources.Endpoint{}, err
	}

	password, err := randomPassword()
	if err != nil {
		return resources.Endpoint{}, fmt.Errorf("generate resource password: %w", err)
	}

	image, containerPort, dataPath, env, cmd, username, database := resourceConfig(spec.Kind, password)

	if err := c.pullImage(ctx, image); err != nil {
		return resources.Endpoint{}, err
	}

	config := containerConfig{
		Image:        image,
		Env:          env,
		Cmd:          cmd,
		ExposedPorts: map[string]struct{}{fmt.Sprintf("%d/tcp", containerPort): {}},
		Labels: map[string]string{
			"cloudforge.managed":       "true",
			"cloudforge.resource_id":   spec.ID,
			"cloudforge.resource_type": string(spec.Kind),
		},
		HostConfig: hostConfig{
			Binds: []string{spec.VolumeName + ":" + dataPath},
			PortBindings: map[string][]portBinding{
				fmt.Sprintf("%d/tcp", containerPort): {{HostIP: "127.0.0.1"}},
			},
			RestartPolicy: restartPolicy{Name: "unless-stopped"},
		},
	}

	created, err := c.createContainer(ctx, spec.ContainerName, config)
	if err != nil {
		return resources.Endpoint{}, err
	}

	if err := c.startContainer(ctx, created.ID); err != nil {
		return resources.Endpoint{}, err
	}

	inspect, err := c.inspectContainer(ctx, created.ID)
	if err != nil {
		return resources.Endpoint{}, err
	}

	port, err := inspect.boundPort(containerPort)
	if err != nil {
		return resources.Endpoint{}, err
	}

	return resources.Endpoint{
		ContainerID: created.ID,
		Host:        "127.0.0.1",
		Port:        port,
		Username:    username,
		Database:    database,
		Password:    password,
		Status:      "running",
	}, nil
}

func (c *Client) Start(ctx context.Context, containerRef string) error {
	if !validContainerRef(containerRef) {
		return errors.New("invalid container reference")
	}

	return c.request(ctx, http.MethodPost, "/containers/"+url.PathEscape(containerRef)+"/start", nil, nil)
}

func (c *Client) Stop(ctx context.Context, containerRef string) error {
	if !validContainerRef(containerRef) {
		return errors.New("invalid container reference")
	}

	return c.request(ctx, http.MethodPost, "/containers/"+url.PathEscape(containerRef)+"/stop?t=10", nil, nil)
}

func (c *Client) Delete(ctx context.Context, containerRef string, volumeName string) error {
	if !validContainerRef(containerRef) {
		return errors.New("invalid container reference")
	}

	if !namePattern.MatchString(volumeName) {
		return errors.New("invalid volume name")
	}

	if err := c.removeContainer(ctx, containerRef); err != nil && !errors.Is(err, ErrNotFound) {
		return err
	}

	if err := c.removeVolume(ctx, volumeName); err != nil && !errors.Is(err, ErrNotFound) {
		return err
	}

	return nil
}

func (c *Client) InspectResource(ctx context.Context, containerRef string) (resources.State, error) {
	if !validContainerRef(containerRef) {
		return resources.State{}, errors.New("invalid container reference")
	}

	inspect, err := c.inspectContainer(ctx, containerRef)
	if errors.Is(err, ErrNotFound) {
		return resources.State{Status: "missing"}, nil
	}

	if err != nil {
		return resources.State{}, err
	}

	state := resources.State{
		ContainerID: inspect.ID,
		Status:      "stopped",
	}

	if inspect.State.Running {
		state.Status = "running"
	}

	for _, key := range []string{"5432/tcp", "6379/tcp"} {
		if port, err := inspect.boundPortFor(key); err == nil {
			state.Port = port
			break
		}
	}

	return state, nil
}

func (c *Client) pullImage(ctx context.Context, image string) error {
	name := image
	tag := "latest"

	if imageName, imageTag, ok := strings.Cut(image, ":"); ok {
		name, tag = imageName, imageTag
	}

	path := "/images/create?fromImage=" + url.QueryEscape(name) + "&tag=" + url.QueryEscape(tag)

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, "http://docker"+path, nil)
	if err != nil {
		return fmt.Errorf("build Docker image pull request: %w", err)
	}

	request.Header.Set("Accept", "application/json")
	request.Header.Set("X-Registry-Auth", "e30=")

	client := c.pullClient
	if client == nil {
		client = c.httpClient
	}

	response, err := client.Do(request)
	if err != nil {
		return fmt.Errorf("pull Docker image %s: %w", image, err)
	}

	defer func() {
		_ = response.Body.Close()
	}()

	if response.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxResponseBytes))

		return fmt.Errorf("Docker image pull for %s returned HTTP %d", image, response.StatusCode)
	}

	scanner := bufio.NewScanner(io.LimitReader(response.Body, 16*maxResponseBytes))
	for scanner.Scan() {
		line := scanner.Text()

		if strings.Contains(line, `"error"`) {
			if len(line) > 500 {
				line = line[:500]
			}

			return fmt.Errorf("Docker image pull for %s failed: %s", image, line)
		}
	}

	if err := scanner.Err(); err != nil {
		return fmt.Errorf("read Docker image pull response: %w", err)
	}

	return nil
}

func (c *Client) createContainer(ctx context.Context, name string, config containerConfig) (containerCreateResponse, error) {
	var created containerCreateResponse

	if err := c.request(ctx, http.MethodPost, "/containers/create?name="+url.QueryEscape(name), config, &created); err != nil {
		return containerCreateResponse{}, err
	}

	if created.ID == "" {
		return containerCreateResponse{}, errors.New("Docker created a container without an ID")
	}

	return created, nil
}

func (c *Client) startContainer(ctx context.Context, containerID string) error {
	if !validContainerRef(containerID) {
		return errors.New("invalid container ID")
	}

	return c.request(ctx, http.MethodPost, "/containers/"+url.PathEscape(containerID)+"/start", nil, nil)
}

func (c *Client) inspectContainer(ctx context.Context, containerRef string) (containerInspect, error) {
	var inspect containerInspect

	if err := c.request(ctx, http.MethodGet, "/containers/"+url.PathEscape(containerRef)+"/json", nil, &inspect); err != nil {
		return containerInspect{}, err
	}

	return inspect, nil
}

func (c *Client) removeContainer(ctx context.Context, containerRef string) error {
	return c.request(ctx, http.MethodDelete, "/containers/"+url.PathEscape(containerRef)+"?force=1&v=0", nil, nil)
}

func (c *Client) removeVolume(ctx context.Context, volumeName string) error {
	return c.request(ctx, http.MethodDelete, "/volumes/"+url.PathEscape(volumeName)+"?force=1", nil, nil)
}

func (c *Client) get(ctx context.Context, path string, out any) error {
	return c.request(ctx, http.MethodGet, path, nil, out)
}

func (c *Client) request(ctx context.Context, method string, path string, body any, out any) error {
	var reader io.Reader

	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encode Docker %s request: %w", path, err)
		}

		reader = bytes.NewReader(encoded)
	}

	request, err := http.NewRequestWithContext(ctx, method, "http://docker"+path, reader)
	if err != nil {
		return fmt.Errorf("build Docker request: %w", err)
	}

	request.Header.Set("Accept", "application/json")

	if body != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	response, err := c.httpClient.Do(request)
	if err != nil {
		return fmt.Errorf("call Docker %s: %w", path, err)
	}

	defer func() {
		_ = response.Body.Close()
	}()

	switch response.StatusCode {
	case http.StatusOK, http.StatusCreated, http.StatusNoContent, http.StatusNotModified:
	case http.StatusNotFound:
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxResponseBytes))

		return ErrNotFound
	default:
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxResponseBytes))

		return fmt.Errorf("Docker %s returned HTTP %d", path, response.StatusCode)
	}

	if out == nil || response.StatusCode == http.StatusNoContent || response.StatusCode == http.StatusNotModified {
		return nil
	}

	if err := json.NewDecoder(io.LimitReader(response.Body, maxResponseBytes)).Decode(out); err != nil {
		return fmt.Errorf("decode Docker %s: %w", path, err)
	}

	return nil
}

func validateSpec(spec resources.CreateSpec) error {
	if !uuidPattern.MatchString(spec.ID) {
		return errors.New("invalid resource ID")
	}

	if spec.Kind != resources.Postgres && spec.Kind != resources.Redis {
		return fmt.Errorf("resource type %q is not supported", spec.Kind)
	}

	if !namePattern.MatchString(spec.ContainerName) {
		return errors.New("invalid container name")
	}

	if !namePattern.MatchString(spec.VolumeName) {
		return errors.New("invalid volume name")
	}

	return nil
}

func validContainerRef(value string) bool {
	return refPattern.MatchString(value)
}

func resourceConfig(kind resources.Kind, password string) (string, int, string, []string, []string, string, string) {
	switch kind {
	case resources.Postgres:
		return "postgres:16-alpine",
			5432,
			"/var/lib/postgresql/data",
			[]string{"POSTGRES_USER=cloudforge", "POSTGRES_PASSWORD=" + password, "POSTGRES_DB=cloudforge"},
			nil,
			"cloudforge",
			"cloudforge"
	case resources.Redis:
		return "redis:7-alpine",
			6379,
			"/data",
			nil,
			[]string{"redis-server", "--appendonly", "yes", "--requirepass", password},
			"",
			""
	default:
		return "", 0, "", nil, nil, "", ""
	}
}

func randomPassword() (string, error) {
	bytes := make([]byte, 24)

	if _, err := rand.Read(bytes); err != nil {
		return "", err
	}

	return hex.EncodeToString(bytes), nil
}

type portBinding struct {
	HostIP   string `json:"HostIp,omitempty"`
	HostPort string `json:"HostPort,omitempty"`
}

type restartPolicy struct {
	Name string `json:"Name"`
}

type hostConfig struct {
	Binds         []string                 `json:"Binds,omitempty"`
	PortBindings  map[string][]portBinding `json:"PortBindings,omitempty"`
	RestartPolicy restartPolicy            `json:"RestartPolicy"`
}

type containerConfig struct {
	Image        string              `json:"Image"`
	Env          []string            `json:"Env,omitempty"`
	Cmd          []string            `json:"Cmd,omitempty"`
	ExposedPorts map[string]struct{} `json:"ExposedPorts,omitempty"`
	Labels       map[string]string   `json:"Labels,omitempty"`
	HostConfig   hostConfig          `json:"HostConfig"`
}

type containerCreateResponse struct {
	ID       string   `json:"Id"`
	Warnings []string `json:"Warnings"`
}

type containerInspect struct {
	ID    string `json:"Id"`
	State struct {
		Running bool   `json:"Running"`
		Status  string `json:"Status"`
	} `json:"State"`
	NetworkSettings struct {
		Ports map[string][]portBinding `json:"Ports"`
	} `json:"NetworkSettings"`
}

func (i containerInspect) boundPort(containerPort int) (int, error) {
	return i.boundPortFor(fmt.Sprintf("%d/tcp", containerPort))
}

func (i containerInspect) boundPortFor(key string) (int, error) {
	bindings := i.NetworkSettings.Ports[key]

	if len(bindings) == 0 || bindings[0].HostPort == "" {
		return 0, fmt.Errorf("Docker did not publish port %s", key)
	}

	port := 0
	if _, err := fmt.Sscanf(bindings[0].HostPort, "%d", &port); err != nil || port <= 0 || port > 65535 {
		return 0, fmt.Errorf("Docker returned an invalid published port %q", bindings[0].HostPort)
	}

	return port, nil
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
