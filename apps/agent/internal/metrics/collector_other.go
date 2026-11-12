//go:build !linux

package metrics

import (
	"os"
	"runtime"
	"time"
)

// Collector provides host identity on non-Linux development machines while
// reporting no host metrics. The production agent targets Linux nodes.
type Collector struct{}

// New returns a portable collector.
func New() *Collector {
	return &Collector{}
}

// HostInfo reports the values the Go runtime can provide without procfs.
func (c *Collector) HostInfo() (HostInfo, error) {
	info := HostInfo{
		Architecture:    runtime.GOARCH,
		OperatingSystem: runtime.GOOS,
		CPUCores:        runtime.NumCPU(),
	}

	if hostname, err := os.Hostname(); err == nil {
		info.Hostname = hostname
	}

	return info, nil
}

// Sample returns a timestamped sample with no host metrics.
func (c *Collector) Sample(recordedAt time.Time) (Sample, error) {
	return Sample{
		RecordedAt: recordedAt.UTC(),
		Metadata:   map[string]any{"platform": runtime.GOOS, "metrics": "unsupported"},
	}, nil
}

// Uptime is unavailable without procfs.
func (c *Collector) Uptime() (time.Duration, error) {
	return 0, nil
}

// LoadAverage is unavailable without procfs.
func (c *Collector) LoadAverage() []float64 {
	return nil
}
