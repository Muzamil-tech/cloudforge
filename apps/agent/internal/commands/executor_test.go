package commands

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/applications"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/metrics"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/resources"
)

type reportedResult struct {
	CommandID string
	Result    protocol.CommandResultRequest
}

type fakeRunner struct {
	acks      []string
	results   []reportedResult
	ackErr    error
	reportErr error
}

func (f *fakeRunner) Acknowledge(_ context.Context, commandID string) error {
	if f.ackErr != nil {
		return f.ackErr
	}

	f.acks = append(f.acks, commandID)

	return nil
}

func (f *fakeRunner) ReportResult(_ context.Context, commandID string, result protocol.CommandResultRequest) error {
	if f.reportErr != nil {
		return f.reportErr
	}

	f.results = append(f.results, reportedResult{CommandID: commandID, Result: result})

	return nil
}

type fakeDocker struct {
	summary      protocol.DockerSummary
	capabilities []protocol.Capability
	calls        int
}

func (f *fakeDocker) Inspect(context.Context) (protocol.DockerSummary, []protocol.Capability) {
	f.calls++

	return f.summary, f.capabilities
}

type fakeResources struct {
	createSpec resources.CreateSpec
	endpoint   resources.Endpoint
	state      resources.State
	err        error
	calls      []string
}

func (f *fakeResources) Create(_ context.Context, spec resources.CreateSpec) (resources.Endpoint, error) {
	f.calls = append(f.calls, "create")
	f.createSpec = spec

	if f.err != nil {
		return resources.Endpoint{}, f.err
	}

	return f.endpoint, nil
}

func (f *fakeResources) Start(_ context.Context, containerRef string) error {
	f.calls = append(f.calls, "start:"+containerRef)

	return f.err
}

func (f *fakeResources) Stop(_ context.Context, containerRef string) error {
	f.calls = append(f.calls, "stop:"+containerRef)

	return f.err
}

func (f *fakeResources) Delete(_ context.Context, containerRef string, volumeName string) error {
	f.calls = append(f.calls, "delete:"+containerRef+":"+volumeName)

	return f.err
}

func (f *fakeResources) InspectResource(_ context.Context, containerRef string) (resources.State, error) {
	f.calls = append(f.calls, "inspect:"+containerRef)

	if f.err != nil {
		return resources.State{}, f.err
	}

	return f.state, nil
}

type fakeApplications struct {
	deploySpec applications.Spec
	endpoint   applications.Endpoint
	state      applications.State
	logs       string
	err        error
	calls      []string
}

func (f *fakeApplications) DeployApplication(_ context.Context, spec applications.Spec) (applications.Endpoint, error) {
	f.calls = append(f.calls, "deploy")
	f.deploySpec = spec

	if f.err != nil {
		return f.endpoint, f.err
	}

	return f.endpoint, nil
}

func (f *fakeApplications) StartApplication(_ context.Context, applicationID string, containerRef string) error {
	f.calls = append(f.calls, "start:"+applicationID+":"+containerRef)

	return f.err
}

func (f *fakeApplications) StopApplication(_ context.Context, applicationID string, containerRef string) error {
	f.calls = append(f.calls, "stop:"+applicationID+":"+containerRef)

	return f.err
}

func (f *fakeApplications) DeleteApplication(_ context.Context, applicationID string, containerRef string) error {
	f.calls = append(f.calls, "delete:"+applicationID+":"+containerRef)

	return f.err
}

func (f *fakeApplications) InspectApplication(_ context.Context, applicationID string, containerRef string, _ int) (applications.State, error) {
	f.calls = append(f.calls, "inspect:"+applicationID+":"+containerRef)

	if f.err != nil {
		return applications.State{}, f.err
	}

	return f.state, nil
}

func (f *fakeApplications) ApplicationLogs(_ context.Context, applicationID string, containerRef string, _ int) (string, error) {
	f.calls = append(f.calls, "logs:"+applicationID+":"+containerRef)

	if f.err != nil {
		return "", f.err
	}

	return f.logs, nil
}

type fakeCollector struct {
	host    metrics.HostInfo
	sample  metrics.Sample
	uptime  time.Duration
	load    []float64
	samples int
	err     error
}

func (f *fakeCollector) HostInfo() (metrics.HostInfo, error) {
	return f.host, nil
}

func (f *fakeCollector) Sample(recordedAt time.Time) (metrics.Sample, error) {
	f.samples++

	if f.err != nil {
		return metrics.Sample{}, f.err
	}

	sample := f.sample
	sample.RecordedAt = recordedAt

	return sample, nil
}

func (f *fakeCollector) Uptime() (time.Duration, error) {
	return f.uptime, nil
}

func (f *fakeCollector) LoadAverage() []float64 {
	return f.load
}

var testNow = time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC)

func testExecutor(t *testing.T, runner *fakeRunner, docker *fakeDocker, collector *fakeCollector) (*Executor, *Store) {
	t.Helper()

	return testExecutorWithResources(t, runner, docker, &fakeResources{}, collector)
}

func testExecutorWithResources(t *testing.T, runner *fakeRunner, docker *fakeDocker, resourceManager *fakeResources, collector *fakeCollector) (*Executor, *Store) {
	t.Helper()

	store, err := OpenStore(filepath.Join(t.TempDir(), "commands.json"))
	if err != nil {
		t.Fatalf("open store: %v", err)
	}

	executor := NewExecutor(runner, docker, resourceManager, collector, store, "1.0.0", nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	executor.now = func() time.Time {
		return testNow
	}

	return executor, store
}

func command(id, operation string, expires time.Time) protocol.Command {
	return protocol.Command{
		ID:        id,
		Operation: operation,
		Payload:   map[string]any{},
		IssuedAt:  expires.Add(-5 * time.Minute).Format(time.RFC3339),
		ExpiresAt: expires.Format(time.RFC3339),
		Attempt:   1,
	}
}

func TestHandleHealthCompletesAndReports(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	docker := &fakeDocker{summary: protocol.DockerSummary{Available: true, Version: "27.1.1"}}
	collector := &fakeCollector{uptime: 90 * time.Second, load: []float64{0.4, 0.3, 0.2}}
	executor, store := testExecutor(t, runner, docker, collector)

	cmd := command("cmd-health", OperationServerHealth, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.acks) != 1 || runner.acks[0] != "cmd-health" {
		t.Fatalf("unexpected acknowledgements %#v", runner.acks)
	}

	if len(runner.results) != 1 {
		t.Fatalf("expected one result, got %#v", runner.results)
	}

	result := runner.results[0]
	if result.CommandID != "cmd-health" || result.Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected result %#v", result)
	}

	if result.Result.Result["agent"] != "ok" || result.Result.Result["docker_available"] != true {
		t.Fatalf("unexpected health payload %#v", result.Result.Result)
	}

	if result.Result.Result["uptime_seconds"] != int64(90) {
		t.Fatalf("unexpected uptime %#v", result.Result.Result["uptime_seconds"])
	}

	stored, ok := store.Get("cmd-health")
	if !ok || stored.Status != OutcomeCompleted {
		t.Fatalf("unexpected stored outcome %#v", stored)
	}
}

func TestHandleInspectReportsInventory(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	docker := &fakeDocker{
		summary:      protocol.DockerSummary{Available: true, Version: "27.1.1"},
		capabilities: []protocol.Capability{{Name: "docker.engine", Version: "27.1.1"}},
	}
	collector := &fakeCollector{host: metrics.HostInfo{
		Hostname:         "node-one",
		OperatingSystem:  "Ubuntu 24.04 LTS",
		Architecture:     "amd64",
		CPUCores:         8,
		TotalMemoryBytes: 16 << 30,
	}}
	executor, _ := testExecutor(t, runner, docker, collector)

	if err := executor.Handle(context.Background(), command("cmd-inspect", OperationServerInspect, testNow.Add(time.Minute))); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.results) != 1 {
		t.Fatalf("expected one result, got %#v", runner.results)
	}

	inventory, ok := runner.results[0].Result.Result["inventory"].(protocol.InventoryRequest)
	if !ok {
		t.Fatalf("unexpected inventory payload %#v", runner.results[0].Result.Result)
	}

	if inventory.Hostname != "node-one" || inventory.CPUCores != 8 || inventory.AgentVersion != "1.0.0" {
		t.Fatalf("unexpected inventory %#v", inventory)
	}

	if inventory.Docker == nil || !inventory.Docker.Available {
		t.Fatalf("unexpected Docker summary %#v", inventory.Docker)
	}
}

func TestHandleMetricsCollectsASample(t *testing.T) {
	t.Parallel()

	cpu := 12.5

	runner := &fakeRunner{}
	collector := &fakeCollector{sample: metrics.Sample{CPUUsage: &cpu}}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, collector)

	if err := executor.Handle(context.Background(), command("cmd-metrics", OperationMetrics, testNow.Add(time.Minute))); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if collector.samples != 1 {
		t.Fatalf("expected one sample, got %d", collector.samples)
	}

	sample, ok := runner.results[0].Result.Result["sample"].(protocol.MetricPoint)
	if !ok || sample.CPUUsage == nil || *sample.CPUUsage != 12.5 {
		t.Fatalf("unexpected sample payload %#v", runner.results[0].Result.Result)
	}
}

func TestHandleRejectsUnsupportedAndExpiredCommands(t *testing.T) {
	t.Parallel()

	for name, testCase := range map[string]struct {
		command  protocol.Command
		contains string
	}{
		"unsupported": {
			command:  command("cmd-unsupported", "docker.run", testNow.Add(time.Minute)),
			contains: "not supported",
		},
		"expired": {
			command:  command("cmd-expired", OperationServerHealth, testNow.Add(-time.Minute)),
			contains: "expired",
		},
		"invalid expiry": {
			command:  protocol.Command{ID: "cmd-invalid", Operation: OperationServerHealth, ExpiresAt: "tomorrow"},
			contains: "expiry",
		},
	} {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			runner := &fakeRunner{}
			executor, store := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})

			if err := executor.Handle(context.Background(), testCase.command); err != nil {
				t.Fatalf("handle: %v", err)
			}

			if len(runner.acks) != 0 {
				t.Fatalf("a rejected command must not be acknowledged: %#v", runner.acks)
			}

			if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
				t.Fatalf("unexpected results %#v", runner.results)
			}

			if !strings.Contains(runner.results[0].Result.ErrorMessage, testCase.contains) {
				t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
			}

			stored, ok := store.Get(testCase.command.ID)
			if !ok || stored.Status != OutcomeFailed {
				t.Fatalf("unexpected stored outcome %#v", stored)
			}
		})
	}
}

func TestHandleDoesNotExecuteARedeliveredCommandTwice(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	docker := &fakeDocker{}
	executor, _ := testExecutor(t, runner, docker, &fakeCollector{})

	cmd := command("cmd-health", OperationServerHealth, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("first handle: %v", err)
	}

	callsAfterFirst := docker.calls

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("second handle: %v", err)
	}

	if docker.calls != callsAfterFirst {
		t.Fatal("a redelivered command must not be executed again")
	}

	if len(runner.acks) != 1 {
		t.Fatalf("expected a single acknowledgement, got %#v", runner.acks)
	}

	if len(runner.results) != 2 {
		t.Fatalf("expected the stored outcome to be reported again, got %#v", runner.results)
	}
}

func TestHandleFailsAnInterruptedExecutionWithoutRepeatingIt(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	docker := &fakeDocker{}
	executor, store := testExecutor(t, runner, docker, &fakeCollector{})

	if err := store.Put(Outcome{
		CommandID:   "cmd-interrupted",
		Operation:   OperationAgentRestart,
		Status:      OutcomeExecuting,
		CompletedAt: testNow.Add(-time.Minute),
	}); err != nil {
		t.Fatalf("seed store: %v", err)
	}

	cmd := command("cmd-interrupted", OperationAgentRestart, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if !strings.Contains(runner.results[0].Result.ErrorMessage, "not repeated") {
		t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
	}

	if docker.calls != 0 {
		t.Fatal("an interrupted command must not be re-executed")
	}

	stored, ok := store.Get("cmd-interrupted")
	if !ok || stored.Status != OutcomeFailed {
		t.Fatalf("unexpected stored outcome %#v", stored)
	}
}

func TestHandleRecoversAnInterruptedCreate(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{
		endpoint: resources.Endpoint{
			ContainerID: "container-123",
			Host:        "127.0.0.1",
			Port:        32768,
			Username:    "cloudforge",
			Database:    "cloudforge",
			Password:    "recovered-secret",
			Status:      "running",
		},
	}
	executor, store := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	if err := store.Put(Outcome{
		CommandID:   "cmd-create-interrupted",
		Operation:   OperationResourceCreate,
		Status:      OutcomeExecuting,
		CompletedAt: testNow.Add(-time.Minute),
	}); err != nil {
		t.Fatalf("seed store: %v", err)
	}

	cmd := command("cmd-create-interrupted", OperationResourceCreate, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
		"resource_type":  "postgresql",
		"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		"volume_name":    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(resourceManager.calls) != 1 || resourceManager.calls[0] != "create" {
		t.Fatalf("expected the idempotent create to be retried, got %#v", resourceManager.calls)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if runner.results[0].Result.Result["password"] != "recovered-secret" {
		t.Fatalf("the recovered result must include the password: %#v", runner.results[0].Result.Result)
	}

	stored, ok := store.Get("cmd-create-interrupted")
	if !ok || stored.Status != OutcomeCompleted {
		t.Fatalf("unexpected stored outcome %#v", stored)
	}

	if _, ok := stored.Result["password"]; ok {
		t.Fatal("the durable agent store must not retain the recovered password")
	}
}

func TestHandleRecoversACompletedCreateWhosePasswordWasRedacted(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{
		endpoint: resources.Endpoint{
			ContainerID: "container-123",
			Host:        "127.0.0.1",
			Port:        32768,
			Username:    "cloudforge",
			Database:    "cloudforge",
			Password:    "recovered-secret",
			Status:      "running",
		},
	}
	executor, store := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	if err := store.Put(Outcome{
		CommandID:   "cmd-create-redacted",
		Operation:   OperationResourceCreate,
		Status:      OutcomeCompleted,
		Result:      map[string]any{"container_id": "container-123"},
		CompletedAt: testNow.Add(-time.Minute),
	}); err != nil {
		t.Fatalf("seed store: %v", err)
	}

	cmd := command("cmd-create-redacted", OperationResourceCreate, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
		"resource_type":  "postgresql",
		"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		"volume_name":    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(resourceManager.calls) != 1 || resourceManager.calls[0] != "create" {
		t.Fatalf("expected the idempotent create to recover the password, got %#v", resourceManager.calls)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if runner.results[0].Result.Result["password"] != "recovered-secret" {
		t.Fatalf("the recovered result must include the password: %#v", runner.results[0].Result.Result)
	}
}

func TestHandleDoesNotRetryAFailedCreate(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{}
	executor, store := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	if err := store.Put(Outcome{
		CommandID:    "cmd-create-failed",
		Operation:    OperationResourceCreate,
		Status:       OutcomeFailed,
		ErrorMessage: "registry blocked",
		CompletedAt:  testNow.Add(-time.Minute),
	}); err != nil {
		t.Fatalf("seed store: %v", err)
	}

	cmd := command("cmd-create-failed", OperationResourceCreate, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(resourceManager.calls) != 0 {
		t.Fatalf("a failed create must not be retried, got %#v", resourceManager.calls)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if !strings.Contains(runner.results[0].Result.ErrorMessage, "registry blocked") {
		t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
	}
}

func TestHandleRemovesTheMarkerWhenAcknowledgementFails(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{ackErr: errors.New("control plane unavailable")}
	docker := &fakeDocker{}
	executor, store := testExecutor(t, runner, docker, &fakeCollector{})

	err := executor.Handle(context.Background(), command("cmd-health", OperationServerHealth, testNow.Add(time.Minute)))
	if err == nil {
		t.Fatal("expected the acknowledgement failure to surface")
	}

	if _, ok := store.Get("cmd-health"); ok {
		t.Fatal("a command that was never executed must not remain stored")
	}

	if len(runner.results) != 0 {
		t.Fatalf("no result must be reported for an unacknowledged command: %#v", runner.results)
	}

	if docker.calls != 0 {
		t.Fatal("execution must not start before acknowledgement succeeds")
	}
}

func TestAgentRestartTriggersOnlyAfterTheResultIsReported(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{reportErr: errors.New("network down")}
	docker := &fakeDocker{}
	executor, _ := testExecutor(t, runner, docker, &fakeCollector{})

	restarts := 0
	executor.restart = func() {
		restarts++
	}

	cmd := command("cmd-restart", OperationAgentRestart, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err == nil {
		t.Fatal("expected the failed report to surface")
	}

	if restarts != 0 {
		t.Fatal("the agent must not restart before the control plane records the result")
	}

	runner.reportErr = nil

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("replay: %v", err)
	}

	if restarts != 1 {
		t.Fatalf("expected exactly one restart, got %d", restarts)
	}
}

func TestExecutingCommandReportsFailedStatusForUnexpectedPanics(t *testing.T) {
	t.Parallel()

	// Guard the contract that only a completed outcome is reported as success.
	outcome := Outcome{CommandID: "cmd", Status: OutcomeExecuting}
	runner := &fakeRunner{}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})

	if err := executor.reportResult(outcome); err != nil {
		t.Fatalf("report: %v", err)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if runner.results[0].Result.ErrorMessage == "" {
		t.Fatal("a failed result must carry an error message")
	}
}

func TestExecutorProvisionsAResource(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{
		endpoint: resources.Endpoint{
			ContainerID: "container-123",
			Host:        "127.0.0.1",
			Port:        32768,
			Username:    "cloudforge",
			Database:    "cloudforge",
			Password:    "generated-secret",
			Status:      "running",
		},
	}
	executor, store := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	cmd := command("cmd-create", OperationResourceCreate, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
		"resource_type":  "postgresql",
		"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		"volume_name":    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(resourceManager.calls) != 1 || resourceManager.calls[0] != "create" {
		t.Fatalf("unexpected resource calls %#v", resourceManager.calls)
	}

	if resourceManager.createSpec.Kind != resources.Postgres {
		t.Fatalf("unexpected resource kind %#v", resourceManager.createSpec)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	result := runner.results[0].Result.Result
	if result["port"] != 32768 || result["password"] != "generated-secret" || result["container_id"] != "container-123" {
		t.Fatalf("unexpected resource result %#v", result)
	}

	stored, ok := store.Get("cmd-create")
	if !ok {
		t.Fatal("expected the resource outcome to be stored")
	}

	if _, ok := stored.Result["password"]; ok {
		t.Fatal("the durable agent store must not retain the generated password")
	}
}

func TestExecutorStartsStopsAndDeletesAResource(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{}
	executor, _ := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	for _, operation := range []string{OperationResourceStart, OperationResourceStop} {
		cmd := command("cmd-"+operation, operation, testNow.Add(time.Minute))
		cmd.Payload = map[string]any{
			"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
			"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		}

		if err := executor.Handle(context.Background(), cmd); err != nil {
			t.Fatalf("%s: %v", operation, err)
		}
	}

	deleteCommand := command("cmd-delete", OperationResourceDelete, testNow.Add(time.Minute))
	deleteCommand.Payload = map[string]any{
		"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
		"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		"volume_name":    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	}

	if err := executor.Handle(context.Background(), deleteCommand); err != nil {
		t.Fatalf("delete: %v", err)
	}

	if len(resourceManager.calls) != 3 {
		t.Fatalf("unexpected resource calls %#v", resourceManager.calls)
	}

	if resourceManager.calls[2] != "delete:cloudforge-01a1d058-8612-7209-9082-03adf0d11976:cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data" {
		t.Fatalf("unexpected delete call %q", resourceManager.calls[2])
	}
}

func TestExecutorDeploysAnApplication(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	manager := &fakeApplications{
		endpoint: applications.Endpoint{
			ApplicationID:            "01a1d058-8612-7209-9082-03adf0d11976",
			DeploymentID:             "01a1d058-8612-7209-9082-03adf0d11977",
			ContainerID:              "container-123",
			ContainerName:            "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678",
			Host:                     "127.0.0.1",
			Port:                     32780,
			Status:                   "running",
			Health:                   "healthy",
			PreviousContainerRemoved: true,
			Logs:                     "started",
		},
	}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})
	executor.SetApplicationManager(manager)

	cmd := command("cmd-deploy", OperationApplicationDeploy, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"application_id":          "01a1d058-8612-7209-9082-03adf0d11976",
		"deployment_id":           "01a1d058-8612-7209-9082-03adf0d11977",
		"container_name":          "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678",
		"previous_container_name": "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-00000000",
		"image":                   "nginx:1.27-alpine",
		"container_port":          float64(80),
		"health_path":             "/",
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(manager.calls) != 1 || manager.calls[0] != "deploy" {
		t.Fatalf("unexpected application calls %#v", manager.calls)
	}

	if manager.deploySpec.Image != "nginx:1.27-alpine" || manager.deploySpec.ContainerPort != 80 ||
		manager.deploySpec.PreviousContainerName != "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-00000000" {
		t.Fatalf("unexpected deploy spec %#v", manager.deploySpec)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	result := runner.results[0].Result.Result
	if result["port"] != 32780 || result["health"] != "healthy" || result["logs"] != "started" ||
		result["previous_container_removed"] != true {
		t.Fatalf("unexpected application result %#v", result)
	}
}

func TestExecutorReportsApplicationDeployFailureWithLogs(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	manager := &fakeApplications{
		endpoint: applications.Endpoint{
			ApplicationID: "01a1d058-8612-7209-9082-03adf0d11976",
			DeploymentID:  "01a1d058-8612-7209-9082-03adf0d11977",
			Health:        "unhealthy",
			Logs:          "listen tcp: address already in use",
		},
		err: errors.New("the application did not become healthy within 60 seconds"),
	}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})
	executor.SetApplicationManager(manager)

	cmd := command("cmd-deploy-failure", OperationApplicationDeploy, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"application_id": "01a1d058-8612-7209-9082-03adf0d11976",
		"deployment_id":  "01a1d058-8612-7209-9082-03adf0d11977",
		"container_name": "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678",
		"image":          "nginx:1.27-alpine",
		"container_port": float64(80),
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if !strings.Contains(runner.results[0].Result.ErrorMessage, "did not become healthy") {
		t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
	}

	if runner.results[0].Result.Result["logs"] != "listen tcp: address already in use" {
		t.Fatalf("the failed deployment logs were not reported: %#v", runner.results[0].Result.Result)
	}
}

func TestExecutorRunsApplicationLifecycleCommands(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	manager := &fakeApplications{
		state: applications.State{
			Status:      "running",
			ContainerID: "container-123",
			Port:        32780,
			Health:      "healthy",
			Image:       "nginx:1.27-alpine",
		},
		logs: "line one\n",
	}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})
	executor.SetApplicationManager(manager)

	payload := map[string]any{
		"application_id": "01a1d058-8612-7209-9082-03adf0d11976",
		"container_name": "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678",
		"container_port": float64(80),
	}

	for _, operation := range []string{
		OperationApplicationStart,
		OperationApplicationStop,
		OperationApplicationInspect,
		OperationApplicationLogs,
		OperationApplicationDelete,
	} {
		cmd := command("cmd-"+operation, operation, testNow.Add(time.Minute))
		cmd.Payload = payload

		if err := executor.Handle(context.Background(), cmd); err != nil {
			t.Fatalf("%s: %v", operation, err)
		}
	}

	if len(manager.calls) != 5 {
		t.Fatalf("unexpected application calls %#v", manager.calls)
	}

	if manager.calls[2] != "inspect:01a1d058-8612-7209-9082-03adf0d11976:cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678" {
		t.Fatalf("unexpected inspect call %q", manager.calls[2])
	}

	if len(runner.results) != 5 {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	inspectResult := runner.results[2].Result.Result
	if inspectResult["status"] != "running" || inspectResult["port"] != 32780 || inspectResult["health"] != "healthy" {
		t.Fatalf("unexpected inspect result %#v", inspectResult)
	}

	logResult := runner.results[3].Result.Result
	if logResult["logs"] != "line one\n" {
		t.Fatalf("unexpected logs result %#v", logResult)
	}
}

func TestHandleRecoversAnInterruptedApplicationDeploy(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	manager := &fakeApplications{
		endpoint: applications.Endpoint{
			ApplicationID: "01a1d058-8612-7209-9082-03adf0d11976",
			DeploymentID:  "01a1d058-8612-7209-9082-03adf0d11977",
			ContainerID:   "container-123",
			Host:          "127.0.0.1",
			Port:          32780,
			Status:        "running",
			Health:        "healthy",
			Logs:          "recovered",
		},
	}
	executor, store := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})
	executor.SetApplicationManager(manager)

	if err := store.Put(Outcome{
		CommandID:   "cmd-deploy-interrupted",
		Operation:   OperationApplicationDeploy,
		Status:      OutcomeExecuting,
		CompletedAt: testNow.Add(-time.Minute),
	}); err != nil {
		t.Fatalf("seed store: %v", err)
	}

	cmd := command("cmd-deploy-interrupted", OperationApplicationDeploy, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"application_id": "01a1d058-8612-7209-9082-03adf0d11976",
		"deployment_id":  "01a1d058-8612-7209-9082-03adf0d11977",
		"container_name": "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678",
		"image":          "nginx:1.27-alpine",
		"container_port": float64(80),
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(manager.calls) != 1 || manager.calls[0] != "deploy" {
		t.Fatalf("expected an idempotent deploy retry, got %#v", manager.calls)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeCompleted {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if runner.results[0].Result.Result["logs"] != "recovered" {
		t.Fatalf("unexpected recovered result %#v", runner.results[0].Result.Result)
	}
}

func TestExecutorRejectsApplicationCommandsWithoutAManager(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	executor, _ := testExecutor(t, runner, &fakeDocker{}, &fakeCollector{})

	cmd := command("cmd-deploy-no-manager", OperationApplicationDeploy, testNow.Add(time.Minute))

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if !strings.Contains(runner.results[0].Result.ErrorMessage, "not configured") {
		t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
	}
}
func TestExecutorReportsResourceManagerFailures(t *testing.T) {
	t.Parallel()

	runner := &fakeRunner{}
	resourceManager := &fakeResources{err: errors.New("Docker is unavailable")}
	executor, _ := testExecutorWithResources(t, runner, &fakeDocker{}, resourceManager, &fakeCollector{})

	cmd := command("cmd-create-failure", OperationResourceCreate, testNow.Add(time.Minute))
	cmd.Payload = map[string]any{
		"resource_id":    "01a1d058-8612-7209-9082-03adf0d11976",
		"resource_type":  "redis",
		"container_name": "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		"volume_name":    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	}

	if err := executor.Handle(context.Background(), cmd); err != nil {
		t.Fatalf("handle: %v", err)
	}

	if len(runner.results) != 1 || runner.results[0].Result.Status != OutcomeFailed {
		t.Fatalf("unexpected results %#v", runner.results)
	}

	if !strings.Contains(runner.results[0].Result.ErrorMessage, "Docker is unavailable") {
		t.Fatalf("unexpected error %q", runner.results[0].Result.ErrorMessage)
	}
}
