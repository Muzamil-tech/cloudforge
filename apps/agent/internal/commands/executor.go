package commands

import (
	"context"
	"fmt"
	"log/slog"
	"strings"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/metrics"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/resources"
)

// Operations executed by the agent. Anything else is reported as unsupported
// and is never executed.
const (
	OperationServerInspect   = "server.inspect"
	OperationServerHealth    = "server.health"
	OperationMetrics         = "metrics.collect"
	OperationAgentRestart    = "agent.restart"
	OperationResourceCreate  = "resource.create"
	OperationResourceStart   = "resource.start"
	OperationResourceStop    = "resource.stop"
	OperationResourceDelete  = "resource.delete"
	OperationResourceInspect = "resource.inspect"
)

const (
	reportTimeout = 15 * time.Second
	maxErrorRunes = 1900
)

// Runner delivers the protocol acknowledgements. The HTTPS client implements
// this interface.
type Runner interface {
	Acknowledge(ctx context.Context, commandID string) error
	ReportResult(ctx context.Context, commandID string, result protocol.CommandResultRequest) error
}

// DockerInspector reports read-only Docker facts.
type DockerInspector interface {
	Inspect(ctx context.Context) (protocol.DockerSummary, []protocol.Capability)
}

// MetricsCollector samples the host.
type MetricsCollector interface {
	HostInfo() (metrics.HostInfo, error)
	Sample(recordedAt time.Time) (metrics.Sample, error)
	Uptime() (time.Duration, error)
	LoadAverage() []float64
}

// Executor handles delivered commands.
type Executor struct {
	runner       Runner
	docker       DockerInspector
	resources    resources.Manager
	metrics      MetricsCollector
	store        *Store
	agentVersion string
	restart      func()
	now          func() time.Time
	log          *slog.Logger
}

// NewExecutor wires the command executor.
func NewExecutor(
	runner Runner,
	docker DockerInspector,
	resourceManager resources.Manager,
	collector MetricsCollector,
	store *Store,
	agentVersion string,
	restart func(),
	log *slog.Logger,
) *Executor {
	if log == nil {
		log = slog.Default()
	}

	return &Executor{
		runner:       runner,
		docker:       docker,
		resources:    resourceManager,
		metrics:      collector,
		store:        store,
		agentVersion: agentVersion,
		restart:      restart,
		now:          time.Now,
		log:          log,
	}
}

// Handle acknowledges, executes and reports one command. The returned error
// describes transport or persistence problems; operational failures are
// reported to the control plane and returned as a nil error.
func (e *Executor) Handle(ctx context.Context, command protocol.Command) error {
	if stored, ok := e.store.Get(command.ID); ok {
		e.log.Info("replaying stored command outcome",
			"command_id", command.ID,
			"operation", stored.Operation,
			"status", stored.Status,
		)

		return e.replay(ctx, stored)
	}

	expiresAt, err := time.Parse(time.RFC3339, command.ExpiresAt)
	if err != nil {
		return e.finish(ctx, Outcome{
			CommandID:    command.ID,
			Operation:    command.Operation,
			Status:       OutcomeFailed,
			ErrorMessage: "the command expiry timestamp is invalid",
		})
	}

	if e.now().UTC().After(expiresAt.UTC()) {
		return e.finish(ctx, Outcome{
			CommandID:    command.ID,
			Operation:    command.Operation,
			Status:       OutcomeFailed,
			ErrorMessage: "the command expired before the agent could execute it",
		})
	}

	if !supported(command.Operation) {
		return e.finish(ctx, Outcome{
			CommandID:    command.ID,
			Operation:    command.Operation,
			Status:       OutcomeFailed,
			ErrorMessage: fmt.Sprintf("operation %q is not supported by this agent", command.Operation),
		})
	}

	marker := Outcome{
		CommandID:   command.ID,
		Operation:   command.Operation,
		Status:      OutcomeExecuting,
		CompletedAt: e.now().UTC(),
	}

	if err := e.store.Put(marker); err != nil {
		return fmt.Errorf("record command %s before execution: %w", command.ID, err)
	}

	if err := e.runner.Acknowledge(ctx, command.ID); err != nil {
		e.store.Delete(command.ID)

		return fmt.Errorf("acknowledge command %s: %w", command.ID, err)
	}

	started := e.now()
	outcome := e.execute(ctx, command)
	outcome.DurationMS = e.now().Sub(started).Milliseconds()
	outcome.CompletedAt = e.now().UTC()

	stored := redactOutcomeForStore(outcome)

	if err := e.store.Put(stored); err != nil {
		e.log.Error("persist command outcome",
			"command_id", command.ID,
			"error", err,
		)
	}

	if err := e.reportResult(outcome); err != nil {
		return err
	}

	e.triggerRestart(outcome)

	return nil
}

func (e *Executor) execute(ctx context.Context, command protocol.Command) Outcome {
	outcome := Outcome{
		CommandID: command.ID,
		Operation: command.Operation,
		Status:    OutcomeCompleted,
	}

	if e.resources == nil && strings.HasPrefix(command.Operation, "resource.") {
		outcome.Status = OutcomeFailed
		outcome.ErrorMessage = "resource operations are not configured on this agent"

		return outcome
	}

	switch command.Operation {
	case OperationServerInspect:
		outcome.Result = map[string]any{"inventory": e.inventory(ctx)}

	case OperationServerHealth:
		outcome.Result = e.health(ctx)

	case OperationMetrics:
		sample, err := e.metrics.Sample(e.now())
		if err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("collect metrics: %s", err))

			break
		}

		outcome.Result = map[string]any{"sample": sample.Point()}

	case OperationAgentRestart:
		result := map[string]any{"restarting": true}

		if reason, ok := command.Payload["reason"].(string); ok && strings.TrimSpace(reason) != "" {
			result["reason"] = truncate(reason)
		}

		outcome.Result = result

	case OperationResourceCreate:
		endpoint, err := e.resources.Create(ctx, resources.CreateSpec{
			ID:            payloadString(command.Payload, "resource_id"),
			Kind:          resources.Kind(payloadString(command.Payload, "resource_type")),
			ContainerName: payloadString(command.Payload, "container_name"),
			VolumeName:    payloadString(command.Payload, "volume_name"),
		})
		if err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("provision resource: %s", err))

			break
		}

		outcome.Result = map[string]any{
			"resource_id":  payloadString(command.Payload, "resource_id"),
			"status":       endpoint.Status,
			"container_id": endpoint.ContainerID,
			"host":         endpoint.Host,
			"port":         endpoint.Port,
			"username":     endpoint.Username,
			"database":     endpoint.Database,
			"password":     endpoint.Password,
		}

	case OperationResourceStart:
		resourceID := payloadString(command.Payload, "resource_id")
		containerName := payloadString(command.Payload, "container_name")

		if err := e.resources.Start(ctx, containerName); err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("start resource: %s", err))

			break
		}

		outcome.Result = map[string]any{"resource_id": resourceID, "status": "running"}

	case OperationResourceStop:
		resourceID := payloadString(command.Payload, "resource_id")
		containerName := payloadString(command.Payload, "container_name")

		if err := e.resources.Stop(ctx, containerName); err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("stop resource: %s", err))

			break
		}

		outcome.Result = map[string]any{"resource_id": resourceID, "status": "stopped"}

	case OperationResourceDelete:
		resourceID := payloadString(command.Payload, "resource_id")
		containerName := payloadString(command.Payload, "container_name")

		if err := e.resources.Delete(ctx, containerName, payloadString(command.Payload, "volume_name")); err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("delete resource: %s", err))

			break
		}

		outcome.Result = map[string]any{"resource_id": resourceID, "status": "deleted"}

	case OperationResourceInspect:
		resourceID := payloadString(command.Payload, "resource_id")
		state, err := e.resources.InspectResource(ctx, payloadString(command.Payload, "container_name"))
		if err != nil {
			outcome.Status = OutcomeFailed
			outcome.ErrorMessage = truncate(fmt.Sprintf("inspect resource: %s", err))

			break
		}

		outcome.Result = map[string]any{
			"resource_id":  resourceID,
			"status":       state.Status,
			"port":         state.Port,
			"container_id": state.ContainerID,
		}

	default:
		outcome.Status = OutcomeFailed
		outcome.ErrorMessage = fmt.Sprintf("operation %q is not supported", command.Operation)
	}

	return outcome
}

func (e *Executor) inventory(ctx context.Context) protocol.InventoryRequest {
	host, _ := e.metrics.HostInfo()
	summary, capabilities := e.docker.Inspect(ctx)

	hostname := host.Hostname
	if hostname == "" {
		hostname = "unknown"
	}

	return protocol.InventoryRequest{
		Hostname:         hostname,
		OperatingSystem:  host.OperatingSystem,
		Architecture:     host.Architecture,
		CPUCores:         host.CPUCores,
		TotalMemoryBytes: host.TotalMemoryBytes,
		TotalDiskBytes:   host.TotalDiskBytes,
		DockerVersion:    summary.Version,
		AgentVersion:     e.agentVersion,
		CollectedAt:      e.now().UTC().Format(time.RFC3339Nano),
		Docker:           &summary,
		Capabilities:     capabilities,
	}
}

func (e *Executor) health(ctx context.Context) map[string]any {
	summary, _ := e.docker.Inspect(ctx)

	result := map[string]any{
		"agent":            "ok",
		"agent_version":    e.agentVersion,
		"docker_available": summary.Available,
		"docker_version":   summary.Version,
	}

	if uptime, err := e.metrics.Uptime(); err == nil && uptime > 0 {
		result["uptime_seconds"] = int64(uptime.Seconds())
	}

	if load := e.metrics.LoadAverage(); len(load) > 0 {
		result["load_average"] = load
	}

	return result
}

func (e *Executor) replay(ctx context.Context, outcome Outcome) error {
	if outcome.Status == OutcomeExecuting {
		outcome.Status = OutcomeFailed
		outcome.ErrorMessage = "the agent stopped before this command completed; the operation was not repeated"
		outcome.CompletedAt = e.now().UTC()

		if err := e.store.Put(outcome); err != nil {
			e.log.Error("persist recovered command outcome", "command_id", outcome.CommandID, "error", err)
		}
	}

	if err := e.reportResult(outcome); err != nil {
		return err
	}

	e.triggerRestart(outcome)

	return nil
}

func (e *Executor) finish(ctx context.Context, outcome Outcome) error {
	outcome.CompletedAt = e.now().UTC()

	if err := e.store.Put(outcome); err != nil {
		return fmt.Errorf("persist command outcome: %w", err)
	}

	return e.reportResult(outcome)
}

func (e *Executor) reportResult(outcome Outcome) error {
	status := outcome.Status
	if status != OutcomeCompleted {
		status = OutcomeFailed
	}

	request := protocol.CommandResultRequest{
		Status:     status,
		Result:     outcome.Result,
		DurationMS: outcome.DurationMS,
	}

	if status == OutcomeFailed {
		request.ErrorMessage = truncate(outcome.ErrorMessage)

		if request.ErrorMessage == "" {
			request.ErrorMessage = "the command failed without an error message"
		}
	}

	// Reporting must survive cancellation of the run loop so a SIGTERM during
	// execution does not leave the control plane waiting for a result.
	ctx, cancel := context.WithTimeout(context.WithoutCancel(context.Background()), reportTimeout)
	defer cancel()

	if err := e.runner.ReportResult(ctx, outcome.CommandID, request); err != nil {
		return fmt.Errorf("report result for command %s: %w", outcome.CommandID, err)
	}

	e.log.Info("command result reported",
		"command_id", outcome.CommandID,
		"operation", outcome.Operation,
		"status", status,
		"duration_ms", outcome.DurationMS,
	)

	return nil
}

func (e *Executor) triggerRestart(outcome Outcome) {
	if outcome.Status != OutcomeCompleted || outcome.Operation != OperationAgentRestart || e.restart == nil {
		return
	}

	e.log.Info("control plane requested an agent restart", "command_id", outcome.CommandID)
	e.restart()
}

func supported(operation string) bool {
	switch operation {
	case OperationServerInspect,
		OperationServerHealth,
		OperationMetrics,
		OperationAgentRestart,
		OperationResourceCreate,
		OperationResourceStart,
		OperationResourceStop,
		OperationResourceDelete,
		OperationResourceInspect:
		return true
	default:
		return false
	}
}

func payloadString(payload map[string]any, key string) string {
	value, _ := payload[key].(string)

	return strings.TrimSpace(value)
}

func redactOutcomeForStore(outcome Outcome) Outcome {
	if outcome.Operation != OperationResourceCreate || outcome.Result == nil {
		return outcome
	}

	redacted := make(map[string]any, len(outcome.Result))
	for key, value := range outcome.Result {
		if key == "password" {
			continue
		}

		redacted[key] = value
	}

	outcome.Result = redacted

	return outcome
}

func truncate(value string) string {
	runes := []rune(strings.TrimSpace(value))

	if len(runes) <= maxErrorRunes {
		return string(runes)
	}

	return string(runes[:maxErrorRunes-1]) + "…"
}
