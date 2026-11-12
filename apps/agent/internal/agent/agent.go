// Package agent runs the CloudForge agent event loops: heartbeat, inventory,
// metrics, command polling and certificate rotation.
package agent

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/client"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/commands"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/config"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/docker"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/identity"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/metrics"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

const (
	certificateCheckInterval = 12 * time.Hour
	shutdownGrace            = 20 * time.Second
	maxMetricBuffer          = 240
)

// Agent owns the long-running process state.
type Agent struct {
	cfg       config.Config
	api       *client.Client
	docker    *docker.Client
	collector *metrics.Collector
	executor  *commands.Executor
	log       *slog.Logger

	restartCh   chan struct{}
	restartOnce sync.Once
}

// New loads the persisted identity and constructs the agent.
func New(cfg config.Config, log *slog.Logger) (*Agent, error) {
	if log == nil {
		log = slog.Default()
	}

	keyPath, certPath, caPath := identity.Paths(cfg.DataDir)

	id, err := identity.Load(keyPath, certPath)
	if err != nil {
		return nil, fmt.Errorf("load agent identity from %s (run \"cloudforge-agent enroll\" first): %w", cfg.DataDir, err)
	}

	caPEM, err := os.ReadFile(caPath)
	if err != nil {
		return nil, fmt.Errorf("read agent CA certificate: %w", err)
	}

	api, err := client.New(cfg.ControlPlaneURL, id, caPEM, cfg.RequestTimeout)
	if err != nil {
		return nil, err
	}

	dockerClient, err := docker.New(cfg.DockerSocket)
	if err != nil {
		return nil, err
	}

	store, err := commands.OpenStore(filepath.Join(cfg.DataDir, "commands.json"))
	if err != nil {
		return nil, err
	}

	collector := metrics.New()

	a := &Agent{
		cfg:       cfg,
		api:       api,
		docker:    dockerClient,
		collector: collector,
		log:       log,
		restartCh: make(chan struct{}),
	}

	a.executor = commands.NewExecutor(api, dockerClient, dockerClient, collector, store, cfg.AgentVersion, a.requestRestart, log)

	return a, nil
}

// Run starts every loop and blocks until the context is cancelled or the
// control plane requests a restart.
func (a *Agent) Run(parent context.Context) error {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	a.log.Info("agent starting",
		"version", a.cfg.AgentVersion,
		"server_id", a.cfg.ServerID,
		"control_plane", a.cfg.ControlPlaneURL,
		"docker_socket", a.docker.SocketPath(),
	)

	var wg sync.WaitGroup

	loops := []func(context.Context){
		a.heartbeatLoop,
		a.inventoryLoop,
		a.metricsLoop,
		a.commandLoop,
		a.certificateLoop,
	}

	for _, loop := range loops {
		wg.Add(1)

		go func(run func(context.Context)) {
			defer wg.Done()
			run(ctx)
		}(loop)
	}

	select {
	case <-parent.Done():
		a.log.Info("shutdown signal received")
	case <-a.restartCh:
		a.log.Info("restart requested by the control plane")
	}

	cancel()

	finished := make(chan struct{})

	go func() {
		wg.Wait()
		close(finished)
	}()

	select {
	case <-finished:
	case <-time.After(shutdownGrace):
		a.log.Warn("agent loops did not stop within the shutdown grace period")
	}

	return nil
}

func (a *Agent) heartbeatLoop(ctx context.Context) {
	interval := a.cfg.HeartbeatInterval
	failures := 0

	for {
		response, err := a.sendHeartbeat(ctx)

		if ctx.Err() != nil {
			return
		}

		if err != nil {
			failures++

			if client.IsUnauthorized(err) {
				a.log.Error("the control plane rejected the agent certificate; revoke the certificate and enroll the server again", "error", err)
			} else {
				a.log.Warn("heartbeat failed", "error", err, "failures", failures)
			}
		} else {
			failures = 0

			if response != nil && response.HeartbeatIntervalSeconds > 0 {
				interval = time.Duration(response.HeartbeatIntervalSeconds) * time.Second
			}
		}

		delay := interval
		if failures > 0 {
			delay = retryDelay(failures, 5*time.Second, 2*time.Minute)
		}

		if !sleep(ctx, delay) {
			return
		}
	}
}

func (a *Agent) sendHeartbeat(ctx context.Context) (*protocol.HeartbeatResponse, error) {
	summary, _ := a.docker.Inspect(ctx)

	request := protocol.HeartbeatRequest{
		AgentVersion:    a.cfg.AgentVersion,
		DockerAvailable: summary.Available,
		DockerVersion:   summary.Version,
	}

	if uptime, err := a.collector.Uptime(); err == nil && uptime > 0 {
		seconds := int64(uptime.Seconds())
		request.UptimeSeconds = &seconds
	}

	if load := a.collector.LoadAverage(); len(load) > 0 {
		request.LoadAverage = load
	}

	return a.api.Heartbeat(ctx, request)
}

func (a *Agent) inventoryLoop(ctx context.Context) {
	for {
		if err := a.sendInventory(ctx); err != nil {
			if ctx.Err() != nil {
				return
			}

			a.log.Warn("inventory upload failed", "error", err)
		} else {
			a.log.Info("inventory uploaded")
		}

		if !sleep(ctx, a.cfg.InventoryInterval) {
			return
		}
	}
}

func (a *Agent) sendInventory(ctx context.Context) error {
	host, _ := a.collector.HostInfo()
	summary, capabilities := a.docker.Inspect(ctx)

	hostname := host.Hostname
	if hostname == "" {
		hostname = "unknown"
	}

	return a.api.Inventory(ctx, protocol.InventoryRequest{
		Hostname:         hostname,
		OperatingSystem:  host.OperatingSystem,
		Architecture:     host.Architecture,
		CPUCores:         host.CPUCores,
		TotalMemoryBytes: host.TotalMemoryBytes,
		TotalDiskBytes:   host.TotalDiskBytes,
		DockerVersion:    summary.Version,
		AgentVersion:     a.cfg.AgentVersion,
		CollectedAt:      time.Now().UTC().Format(time.RFC3339Nano),
		Docker:           &summary,
		Capabilities:     capabilities,
	})
}

func (a *Agent) metricsLoop(ctx context.Context) {
	buffer := make([]protocol.MetricPoint, 0, maxMetricBuffer)

	collect := func() {
		sample, err := a.collector.Sample(time.Now())
		if err != nil {
			a.log.Warn("metrics sample failed", "error", err)

			return
		}

		buffer = append(buffer, sample.Point())

		if len(buffer) > maxMetricBuffer {
			buffer = buffer[len(buffer)-maxMetricBuffer:]
		}

		if err := a.api.Metrics(ctx, protocol.MetricsRequest{Points: buffer}); err != nil {
			if ctx.Err() != nil {
				return
			}

			a.log.Warn("metrics upload failed; samples buffered", "error", err, "buffered", len(buffer))

			return
		}

		buffer = buffer[:0]
	}

	collect()

	ticker := time.NewTicker(a.cfg.MetricsInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			collect()
		}
	}
}

func (a *Agent) commandLoop(ctx context.Context) {
	failures := 0

	for {
		if ctx.Err() != nil {
			return
		}

		command, err := a.api.NextCommand(ctx, a.cfg.CommandPollWait)

		if err != nil {
			if ctx.Err() != nil {
				return
			}

			failures++

			if client.IsUnauthorized(err) {
				a.log.Error("the control plane rejected the agent certificate while polling for commands", "error", err)
			} else {
				a.log.Warn("command poll failed", "error", err, "failures", failures)
			}

			if !sleep(ctx, retryDelay(failures, time.Second, time.Minute)) {
				return
			}

			continue
		}

		failures = 0

		if command == nil {
			if !sleep(ctx, a.cfg.CommandPollInterval) {
				return
			}

			continue
		}

		a.log.Info("command received",
			"command_id", command.ID,
			"operation", command.Operation,
			"attempt", command.Attempt,
		)

		if err := a.executor.Handle(ctx, *command); err != nil {
			if ctx.Err() != nil {
				return
			}

			a.log.Error("command handling failed",
				"command_id", command.ID,
				"operation", command.Operation,
				"error", err,
			)
		}
	}
}

func (a *Agent) certificateLoop(ctx context.Context) {
	a.rotateCertificateIfNeeded(ctx)

	ticker := time.NewTicker(certificateCheckInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			a.rotateCertificateIfNeeded(ctx)
		}
	}
}

func (a *Agent) rotateCertificateIfNeeded(ctx context.Context) {
	current := a.api.Identity()
	if current == nil {
		return
	}

	remaining := time.Until(current.Certificate.NotAfter)

	if remaining > a.cfg.RotateBefore {
		return
	}

	if remaining <= 0 {
		a.log.Error("agent certificate has expired; re-enrollment is required", "expired_at", current.Certificate.NotAfter)

		return
	}

	a.log.Info("rotating agent certificate", "expires_at", current.Certificate.NotAfter, "remaining", remaining)

	key, err := identity.Generate()
	if err != nil {
		a.log.Error("certificate rotation failed while generating a key", "error", err)

		return
	}

	csrPEM, err := identity.CreateCSR(key, a.cfg.ServerID)
	if err != nil {
		a.log.Error("certificate rotation failed while creating a CSR", "error", err)

		return
	}

	response, err := a.api.RotateCertificate(ctx, string(csrPEM))
	if err != nil {
		if ctx.Err() == nil {
			a.log.Error("certificate rotation request failed", "error", err)
		}

		return
	}

	certPEM := []byte(response.Certificate.PEM)
	caPEM := []byte(response.CACertificate)

	if _, err := identity.VerifyIssued(certPEM, caPEM, a.cfg.ServerID, response.Certificate.FingerprintSHA256); err != nil {
		a.log.Error("control plane returned an invalid rotated certificate", "error", err)

		return
	}

	if err := identity.WriteIdentity(a.cfg.DataDir, key, certPEM, caPEM); err != nil {
		a.log.Error("failed to persist the rotated identity; the agent may be locked out", "error", err)

		return
	}

	keyPath, certPath, _ := identity.Paths(a.cfg.DataDir)

	loaded, err := identity.Load(keyPath, certPath)
	if err != nil {
		a.log.Error("failed to reload the rotated identity", "error", err)

		return
	}

	a.api.SetIdentity(loaded)

	a.log.Info("agent certificate rotated",
		"fingerprint", loaded.Fingerprint,
		"expires_at", loaded.Certificate.NotAfter,
	)
}

func (a *Agent) requestRestart() {
	a.restartOnce.Do(func() {
		close(a.restartCh)
	})
}

func sleep(ctx context.Context, duration time.Duration) bool {
	if duration <= 0 {
		select {
		case <-ctx.Done():
			return false
		default:
			return true
		}
	}

	timer := time.NewTimer(duration)
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}

func retryDelay(failures int, initial, maximum time.Duration) time.Duration {
	if failures < 1 {
		return initial
	}

	delay := initial

	for attempt := 1; attempt < failures && delay < maximum; attempt++ {
		delay *= 2

		if delay > maximum {
			delay = maximum
		}
	}

	return delay
}
