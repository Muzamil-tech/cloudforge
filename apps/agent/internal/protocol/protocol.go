package protocol

// Version is the agent protocol version understood by this build.
const Version = "1.0"

// EnrollRequest is posted once with a single-use enrollment token.
type EnrollRequest struct {
	Token           string `json:"token"`
	CSR             string `json:"csr"`
	ProtocolVersion string `json:"protocol_version"`
	AgentVersion    string `json:"agent_version"`
	Hostname        string `json:"hostname"`
	OperatingSystem string `json:"operating_system,omitempty"`
	Architecture    string `json:"architecture,omitempty"`
	DockerVersion   string `json:"docker_version,omitempty"`
	MachineID       string `json:"machine_id,omitempty"`
}

// EnrollResponse carries the issued client certificate and its trust anchor.
type EnrollResponse struct {
	ProtocolVersion string `json:"protocol_version"`
	Server          struct {
		ID       string `json:"id"`
		Name     string `json:"name"`
		Hostname string `json:"hostname"`
	} `json:"server"`
	Certificate struct {
		PEM               string `json:"pem"`
		Serial            string `json:"serial"`
		FingerprintSHA256 string `json:"fingerprint_sha256"`
		ExpiresAt         string `json:"expires_at"`
	} `json:"certificate"`
	CACertificate              string `json:"ca_certificate"`
	HeartbeatIntervalSeconds   int    `json:"heartbeat_interval_seconds"`
	CommandPollIntervalSeconds int    `json:"command_poll_interval_seconds"`
}

// HeartbeatRequest reports liveness and Docker reachability.
type HeartbeatRequest struct {
	AgentVersion    string    `json:"agent_version"`
	DockerAvailable bool      `json:"docker_available"`
	DockerVersion   string    `json:"docker_version,omitempty"`
	UptimeSeconds   *int64    `json:"uptime_seconds,omitempty"`
	LoadAverage     []float64 `json:"load_average,omitempty"`
}

// HeartbeatResponse is informational; the agent keeps its own cadence.
type HeartbeatResponse struct {
	Status                   string `json:"status"`
	ServerStatus             string `json:"server_status"`
	HeartbeatIntervalSeconds int    `json:"heartbeat_interval_seconds"`
	ServerTime               string `json:"server_time"`
}

// InventoryRequest is a full host and Docker inventory snapshot.
type InventoryRequest struct {
	Hostname         string         `json:"hostname"`
	OperatingSystem  string         `json:"operating_system,omitempty"`
	Architecture     string         `json:"architecture,omitempty"`
	CPUCores         int            `json:"cpu_cores,omitempty"`
	TotalMemoryBytes uint64         `json:"total_memory_bytes,omitempty"`
	TotalDiskBytes   uint64         `json:"total_disk_bytes,omitempty"`
	DockerVersion    string         `json:"docker_version,omitempty"`
	AgentVersion     string         `json:"agent_version"`
	CollectedAt      string         `json:"collected_at"`
	Docker           *DockerSummary `json:"docker,omitempty"`
	Capabilities     []Capability   `json:"capabilities,omitempty"`
}

// DockerSummary describes the daemon without exposing Docker credentials.
type DockerSummary struct {
	Available     bool   `json:"available"`
	Version       string `json:"version,omitempty"`
	APIVersion    string `json:"api_version,omitempty"`
	StorageDriver string `json:"storage_driver,omitempty"`
	Rootless      bool   `json:"rootless"`
}

// Capability advertises an inspected runtime facility.
type Capability struct {
	Name     string         `json:"name"`
	Version  string         `json:"version,omitempty"`
	Metadata map[string]any `json:"metadata,omitempty"`
}

// MetricsRequest batches host samples.
type MetricsRequest struct {
	Points []MetricPoint `json:"points"`
}

// MetricPoint is one host sample. Nullable fields are pointers so unsupported
// platforms omit them instead of reporting false zeroes.
type MetricPoint struct {
	RecordedAt      string         `json:"recorded_at"`
	CPUUsage        *float64       `json:"cpu_usage,omitempty"`
	MemoryUsage     *float64       `json:"memory_usage,omitempty"`
	DiskUsage       *float64       `json:"disk_usage,omitempty"`
	MemoryUsedBytes *uint64        `json:"memory_used_bytes,omitempty"`
	DiskUsedBytes   *uint64        `json:"disk_used_bytes,omitempty"`
	NetworkInBytes  *uint64        `json:"network_in_bytes,omitempty"`
	NetworkOutBytes *uint64        `json:"network_out_bytes,omitempty"`
	Metadata        map[string]any `json:"metadata,omitempty"`
}

// CommandEnvelope wraps one delivered command.
type CommandEnvelope struct {
	Command Command `json:"command"`
}

// Command is an allowlisted operation issued by the control plane.
type Command struct {
	ID        string         `json:"id"`
	Operation string         `json:"operation"`
	Payload   map[string]any `json:"payload"`
	IssuedAt  string         `json:"issued_at"`
	ExpiresAt string         `json:"expires_at"`
	Attempt   int            `json:"attempt"`
}

// CommandResultRequest reports the outcome of a command.
type CommandResultRequest struct {
	Status       string         `json:"status"`
	Result       map[string]any `json:"result,omitempty"`
	ErrorMessage string         `json:"error_message,omitempty"`
	DurationMS   int64          `json:"duration_ms,omitempty"`
}

// RotateRequest replaces the client certificate using a fresh CSR.
type RotateRequest struct {
	CSR string `json:"csr"`
}

// RotateResponse carries a replacement certificate.
type RotateResponse struct {
	Certificate struct {
		PEM               string `json:"pem"`
		Serial            string `json:"serial"`
		FingerprintSHA256 string `json:"fingerprint_sha256"`
		ExpiresAt         string `json:"expires_at"`
	} `json:"certificate"`
	CACertificate string `json:"ca_certificate"`
}

// StatusResponse is the generic acknowledgement returned by agent endpoints.
type StatusResponse struct {
	Status string `json:"status"`
}
