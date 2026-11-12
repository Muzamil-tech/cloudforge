package metrics

import (
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/protocol"
)

// HostInfo is the static inventory reported to the control plane.
type HostInfo struct {
	Hostname         string
	OperatingSystem  string
	Architecture     string
	CPUCores         int
	TotalMemoryBytes uint64
	TotalDiskBytes   uint64
}

// Sample is one point-in-time host measurement. Unsupported or unavailable
// values stay nil so the control plane does not record a false zero.
type Sample struct {
	RecordedAt      time.Time
	CPUUsage        *float64
	MemoryUsage     *float64
	DiskUsage       *float64
	MemoryUsedBytes *uint64
	DiskUsedBytes   *uint64
	NetworkInBytes  *uint64
	NetworkOutBytes *uint64
	Metadata        map[string]any
}

// Point converts a sample to the wire representation.
func (s Sample) Point() protocol.MetricPoint {
	point := protocol.MetricPoint{
		RecordedAt:      s.RecordedAt.UTC().Format(time.RFC3339Nano),
		CPUUsage:        s.CPUUsage,
		MemoryUsage:     s.MemoryUsage,
		DiskUsage:       s.DiskUsage,
		MemoryUsedBytes: s.MemoryUsedBytes,
		DiskUsedBytes:   s.DiskUsedBytes,
		NetworkInBytes:  s.NetworkInBytes,
		NetworkOutBytes: s.NetworkOutBytes,
		Metadata:        s.Metadata,
	}

	return point
}
