package agent

import (
	"context"
	"log/slog"
	"path/filepath"
	"testing"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/config"
)

func TestRetryDelayDoublesAndCaps(t *testing.T) {
	t.Parallel()

	tests := []struct {
		failures int
		expected time.Duration
	}{
		{failures: 0, expected: time.Second},
		{failures: 1, expected: time.Second},
		{failures: 2, expected: 2 * time.Second},
		{failures: 3, expected: 4 * time.Second},
		{failures: 5, expected: 16 * time.Second},
		{failures: 10, expected: time.Minute},
		{failures: 100, expected: time.Minute},
	}

	for _, testCase := range tests {
		if got := retryDelay(testCase.failures, time.Second, time.Minute); got != testCase.expected {
			t.Fatalf("failures=%d: expected %s, got %s", testCase.failures, testCase.expected, got)
		}
	}
}

func TestSleepHonoursCancellation(t *testing.T) {
	t.Parallel()

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	if sleep(ctx, time.Hour) {
		t.Fatal("a cancelled context must stop the sleep")
	}

	if !sleep(context.Background(), time.Millisecond) {
		t.Fatal("expected a short sleep to complete")
	}

	if !sleep(context.Background(), 0) {
		t.Fatal("a zero duration must return immediately")
	}
}

func TestNewRequiresAnEnrolledIdentity(t *testing.T) {
	t.Parallel()

	cfg := config.Config{
		ControlPlaneURL:     "https://cloudforge.example.com",
		ServerID:            "server-one",
		DataDir:             filepath.Join(t.TempDir(), "missing"),
		DockerSocket:        "/var/run/docker.sock",
		HeartbeatInterval:   30 * time.Second,
		CommandPollInterval: 5 * time.Second,
		CommandPollWait:     25,
		MetricsInterval:     30 * time.Second,
		InventoryInterval:   10 * time.Minute,
		RequestTimeout:      45 * time.Second,
		RotateBefore:        30 * 24 * time.Hour,
	}

	if _, err := New(cfg, slog.Default()); err == nil {
		t.Fatal("expected a missing identity to be rejected")
	}
}

func TestRequestRestartIsIdempotent(t *testing.T) {
	t.Parallel()

	agent := &Agent{restartCh: make(chan struct{})}
	agent.requestRestart()
	agent.requestRestart()

	select {
	case <-agent.restartCh:
	default:
		t.Fatal("expected the restart channel to be closed")
	}

	// A second close would panic, so reaching this point proves the sync.Once
	// guard worked.
}
