package config

import (
	"path/filepath"
	"testing"
	"time"
)

// baseConfig returns a valid configuration with paths that are absolute on
// every supported platform so the suite also runs on developer workstations.
func baseConfig(t *testing.T) Config {
	t.Helper()

	dir := t.TempDir()

	return Config{
		ControlPlaneURL:     "https://cloudforge.example.com",
		ServerID:            "server-id",
		DataDir:             dir,
		DockerSocket:        filepath.Join(dir, "docker.sock"),
		HeartbeatInterval:   30 * time.Second,
		CommandPollInterval: 5 * time.Second,
		CommandPollWait:     25,
		MetricsInterval:     30 * time.Second,
		InventoryInterval:   10 * time.Minute,
		RequestTimeout:      45 * time.Second,
		RotateBefore:        30 * 24 * time.Hour,
	}
}

func TestFromEnvironmentAppliesDefaults(t *testing.T) {
	t.Setenv("CLOUDFORGE_CONTROL_PLANE_URL", "")
	t.Setenv("CLOUDFORGE_SERVER_ID", "")

	cfg := FromEnvironment("test-version")

	if cfg.DataDir != DefaultDataDir {
		t.Fatalf("unexpected data dir %q", cfg.DataDir)
	}

	if cfg.DockerSocket != DefaultDockerSocket {
		t.Fatalf("unexpected docker socket %q", cfg.DockerSocket)
	}

	if cfg.HeartbeatInterval != 30*time.Second {
		t.Fatalf("unexpected heartbeat interval %s", cfg.HeartbeatInterval)
	}

	if cfg.CommandPollWait != 25 {
		t.Fatalf("unexpected poll wait %d", cfg.CommandPollWait)
	}

	if cfg.AgentVersion != "test-version" {
		t.Fatalf("unexpected version %q", cfg.AgentVersion)
	}
}

func TestFromEnvironmentReadsValues(t *testing.T) {
	t.Setenv("CLOUDFORGE_CONTROL_PLANE_URL", "https://cloudforge.example.com/")
	t.Setenv("CLOUDFORGE_SERVER_ID", "server-id")
	t.Setenv("CLOUDFORGE_DATA_DIR", "/srv/cloudforge")
	t.Setenv("CLOUDFORGE_HEARTBEAT_INTERVAL", "45")
	t.Setenv("CLOUDFORGE_METRICS_INTERVAL", "90s")
	t.Setenv("CLOUDFORGE_LOG_LEVEL", "DEBUG")

	cfg := FromEnvironment("1.2.3")

	if cfg.ControlPlaneURL != "https://cloudforge.example.com" {
		t.Fatalf("unexpected control plane URL %q", cfg.ControlPlaneURL)
	}

	if cfg.HeartbeatInterval != 45*time.Second {
		t.Fatalf("unexpected heartbeat interval %s", cfg.HeartbeatInterval)
	}

	if cfg.MetricsInterval != 90*time.Second {
		t.Fatalf("unexpected metrics interval %s", cfg.MetricsInterval)
	}

	if cfg.LogLevel != "debug" {
		t.Fatalf("unexpected log level %q", cfg.LogLevel)
	}
}

func TestInvalidDurationsFallBack(t *testing.T) {
	t.Setenv("CLOUDFORGE_HEARTBEAT_INTERVAL", "not-a-duration")
	t.Setenv("CLOUDFORGE_REQUEST_TIMEOUT", "-5")

	cfg := FromEnvironment("test")

	if cfg.HeartbeatInterval != 30*time.Second {
		t.Fatalf("unexpected heartbeat interval %s", cfg.HeartbeatInterval)
	}

	if cfg.RequestTimeout != 45*time.Second {
		t.Fatalf("unexpected request timeout %s", cfg.RequestTimeout)
	}
}

func TestValidateForRun(t *testing.T) {
	valid := baseConfig(t)

	if err := valid.ValidateForRun(); err != nil {
		t.Fatalf("expected valid config: %v", err)
	}

	tests := map[string]func(*Config){
		"http remote": func(cfg *Config) {
			cfg.ControlPlaneURL = "http://cloudforge.example.com"
		},
		"tcp docker": func(cfg *Config) {
			cfg.DockerSocket = "tcp://127.0.0.1:2375"
		},
		"missing server": func(cfg *Config) {
			cfg.ServerID = ""
		},
		"relative data dir": func(cfg *Config) {
			cfg.DataDir = "agent-data"
		},
		"excessive poll wait": func(cfg *Config) {
			cfg.CommandPollWait = 60
		},
		"short request timeout": func(cfg *Config) {
			cfg.RequestTimeout = 20 * time.Second
		},
		"negative rotation window": func(cfg *Config) {
			cfg.RotateBefore = -time.Hour
		},
		"query string": func(cfg *Config) {
			cfg.ControlPlaneURL = "https://cloudforge.example.com?tenant=1"
		},
	}

	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			cfg := valid
			mutate(&cfg)

			if err := cfg.ValidateForRun(); err == nil {
				t.Fatal("expected validation to fail")
			}
		})
	}
}

func TestValidateAllowsLoopbackHTTP(t *testing.T) {
	cfg := baseConfig(t)
	cfg.ControlPlaneURL = "http://localhost:8000"

	if err := cfg.ValidateForRun(); err != nil {
		t.Fatalf("expected loopback http to be allowed: %v", err)
	}

	cfg.ControlPlaneURL = "http://127.0.0.1:8000"

	if err := cfg.ValidateForRun(); err != nil {
		t.Fatalf("expected loopback ip http to be allowed: %v", err)
	}
}

func TestValidateForEnrollmentRequiresToken(t *testing.T) {
	cfg := baseConfig(t)

	if err := cfg.ValidateForEnrollment(); err == nil {
		t.Fatal("expected a missing token to be rejected")
	}

	cfg.EnrollmentToken = "cf_enroll_test"

	if err := cfg.ValidateForEnrollment(); err != nil {
		t.Fatalf("expected valid enrollment config: %v", err)
	}

	cfg.ServerID = ""

	if err := cfg.ValidateForEnrollment(); err == nil {
		t.Fatal("expected a missing server ID to be rejected")
	}
}
