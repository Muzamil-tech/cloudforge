// Package config resolves agent configuration from environment variables and
// command-line flags. The systemd unit supplies environment variables through
// /etc/cloudforge/agent.env so credentials never appear in process listings.
package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	// DefaultDataDir is the FHS location for agent state.
	DefaultDataDir = "/var/lib/cloudforge-agent"

	// DefaultDockerSocket is the only Docker endpoint the agent will inspect.
	DefaultDockerSocket = "/var/run/docker.sock"

	// DefaultPostgresImage and DefaultRedisImage are the allowlisted managed
	// resource images. Operators can override them for an internal registry.
	DefaultPostgresImage = "postgres:16-alpine"
	DefaultRedisImage    = "redis:7-alpine"

	defaultHeartbeatInterval       = 30 * time.Second
	defaultCommandInterval         = 5 * time.Second
	defaultMetricsInterval         = 30 * time.Second
	defaultInventoryInterval       = 10 * time.Minute
	defaultRequestTimeout          = 45 * time.Second
	defaultCertificateRotateBefore = 30 * 24 * time.Hour
)

// Config is the fully resolved agent configuration.
type Config struct {
	ControlPlaneURL     string
	ServerID            string
	EnrollmentToken     string
	MachineID           string
	DataDir             string
	DockerSocket        string
	AgentVersion        string
	CAFile              string
	PostgresImage       string
	RedisImage          string
	LogLevel            string
	HeartbeatInterval   time.Duration
	CommandPollInterval time.Duration
	CommandPollWait     int
	MetricsInterval     time.Duration
	InventoryInterval   time.Duration
	RequestTimeout      time.Duration
	RotateBefore        time.Duration
}

// FromEnvironment builds a configuration from CLOUDFORGE_* variables and
// defaults. Empty or malformed duration values fall back to the default so a
// typo cannot silently disable heartbeats.
func FromEnvironment(agentVersion string) Config {
	return Config{
		ControlPlaneURL:     strings.TrimRight(strings.TrimSpace(os.Getenv("CLOUDFORGE_CONTROL_PLANE_URL")), "/"),
		ServerID:            strings.TrimSpace(os.Getenv("CLOUDFORGE_SERVER_ID")),
		EnrollmentToken:     strings.TrimSpace(os.Getenv("CLOUDFORGE_ENROLLMENT_TOKEN")),
		MachineID:           strings.TrimSpace(os.Getenv("CLOUDFORGE_MACHINE_ID")),
		DataDir:             envString("CLOUDFORGE_DATA_DIR", DefaultDataDir),
		DockerSocket:        envString("CLOUDFORGE_DOCKER_SOCKET", DefaultDockerSocket),
		AgentVersion:        agentVersion,
		CAFile:              strings.TrimSpace(os.Getenv("CLOUDFORGE_CA_FILE")),
		PostgresImage:       envString("CLOUDFORGE_POSTGRES_IMAGE", DefaultPostgresImage),
		RedisImage:          envString("CLOUDFORGE_REDIS_IMAGE", DefaultRedisImage),
		LogLevel:            strings.ToLower(envString("CLOUDFORGE_LOG_LEVEL", "info")),
		HeartbeatInterval:   envDuration("CLOUDFORGE_HEARTBEAT_INTERVAL", defaultHeartbeatInterval),
		CommandPollInterval: envDuration("CLOUDFORGE_COMMAND_POLL_INTERVAL", defaultCommandInterval),
		CommandPollWait:     envInt("CLOUDFORGE_COMMAND_POLL_WAIT", 25),
		MetricsInterval:     envDuration("CLOUDFORGE_METRICS_INTERVAL", defaultMetricsInterval),
		InventoryInterval:   envDuration("CLOUDFORGE_INVENTORY_INTERVAL", defaultInventoryInterval),
		RequestTimeout:      envDuration("CLOUDFORGE_REQUEST_TIMEOUT", defaultRequestTimeout),
		RotateBefore:        envDuration("CLOUDFORGE_CERTIFICATE_ROTATE_BEFORE", defaultCertificateRotateBefore),
	}
}

// ValidateForEnrollment checks the fields required to redeem an enrollment
// token. The control plane URL must be HTTPS except for loopback development.
func (c Config) ValidateForEnrollment() error {
	if err := c.validateCommon(); err != nil {
		return err
	}

	if c.EnrollmentToken == "" {
		return errors.New("enrollment token is required (CLOUDFORGE_ENROLLMENT_TOKEN)")
	}

	if c.ServerID == "" {
		return errors.New("server ID is required (CLOUDFORGE_SERVER_ID)")
	}

	return nil
}

// ValidateForRun checks the fields required to serve an already enrolled host.
func (c Config) ValidateForRun() error {
	if err := c.validateCommon(); err != nil {
		return err
	}

	if c.ServerID == "" {
		return errors.New("server ID is required (CLOUDFORGE_SERVER_ID)")
	}

	if c.CommandPollWait < 0 || c.CommandPollWait > 25 {
		return fmt.Errorf("command poll wait must be between 0 and 25 seconds, got %d", c.CommandPollWait)
	}

	if c.RequestTimeout < time.Duration(c.CommandPollWait)*time.Second+5*time.Second {
		return errors.New("request timeout must exceed the command poll wait by at least five seconds")
	}

	if c.RotateBefore <= 0 {
		return errors.New("certificate rotation window must be positive")
	}

	return nil
}

func (c Config) validateCommon() error {
	if c.ControlPlaneURL == "" {
		return errors.New("control plane URL is required (CLOUDFORGE_CONTROL_PLANE_URL)")
	}

	parsed, err := url.Parse(c.ControlPlaneURL)
	if err != nil {
		return fmt.Errorf("control plane URL is invalid: %w", err)
	}

	if parsed.Host == "" {
		return errors.New("control plane URL must include a host")
	}

	if parsed.Scheme != "https" && !(parsed.Scheme == "http" && isLoopback(parsed.Hostname())) {
		return errors.New("control plane URL must use https (http is only allowed for loopback development)")
	}

	if parsed.RawQuery != "" || parsed.Fragment != "" {
		return errors.New("control plane URL must not include a query string or fragment")
	}

	if !filepath.IsAbs(c.DataDir) {
		return fmt.Errorf("data directory must be absolute, got %q", c.DataDir)
	}

	if c.DockerSocket == "" {
		return errors.New("Docker socket path is required")
	}

	if strings.Contains(c.DockerSocket, "://") {
		return errors.New("Docker endpoint must be a Unix socket path; TCP endpoints are not permitted")
	}

	if !filepath.IsAbs(c.DockerSocket) {
		return fmt.Errorf("Docker socket must be an absolute path, got %q", c.DockerSocket)
	}

	for name, value := range map[string]time.Duration{
		"heartbeat interval": c.HeartbeatInterval,
		"command poll":       c.CommandPollInterval,
		"metrics interval":   c.MetricsInterval,
		"inventory interval": c.InventoryInterval,
		"request timeout":    c.RequestTimeout,
	} {
		if value <= 0 {
			return fmt.Errorf("%s must be positive", name)
		}
	}

	return nil
}

func isLoopback(host string) bool {
	return host == "localhost" || host == "127.0.0.1" || host == "::1"
}

func envString(key, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}

	return fallback
}

func envDuration(key string, fallback time.Duration) time.Duration {
	raw := strings.TrimSpace(os.Getenv(key))
	if raw == "" {
		return fallback
	}

	if seconds, err := strconv.Atoi(raw); err == nil {
		if seconds <= 0 {
			return fallback
		}

		return time.Duration(seconds) * time.Second
	}

	parsed, err := time.ParseDuration(raw)
	if err != nil || parsed <= 0 {
		return fallback
	}

	return parsed
}

func envInt(key string, fallback int) int {
	raw := strings.TrimSpace(os.Getenv(key))
	if raw == "" {
		return fallback
	}

	parsed, err := strconv.Atoi(raw)
	if err != nil {
		return fallback
	}

	return parsed
}
