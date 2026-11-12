//go:build linux

package metrics

import (
	"os"
	"path/filepath"
	"runtime"
	"sync"
	"syscall"
	"time"
)

// Collector samples Linux host metrics from procfs and statfs. It keeps the
// previous CPU counters so usage is a delta rather than an average since boot.
type Collector struct {
	mu            sync.Mutex
	procPath      string
	osReleasePath string
	diskPath      string
	previous      cpuTimes
}

// New returns a collector bound to the host's real filesystems.
func New() *Collector {
	return newWithPaths("/proc", "/etc/os-release", "/")
}

func newWithPaths(procPath, osReleasePath, diskPath string) *Collector {
	return &Collector{
		procPath:      procPath,
		osReleasePath: osReleasePath,
		diskPath:      diskPath,
	}
}

// HostInfo reports static host facts. Missing files leave fields at zero, which
// the protocol omits from the request.
func (c *Collector) HostInfo() (HostInfo, error) {
	info := HostInfo{
		Architecture:    runtime.GOARCH,
		OperatingSystem: "Linux",
		CPUCores:        runtime.NumCPU(),
	}

	if hostname, err := os.Hostname(); err == nil {
		info.Hostname = hostname
	}

	if contents, err := os.ReadFile(c.osReleasePath); err == nil {
		if name := parseOSRelease(string(contents)); name != "" {
			info.OperatingSystem = name
		}
	}

	if contents, err := os.ReadFile(filepath.Join(c.procPath, "meminfo")); err == nil {
		if memory, err := parseMemInfo(string(contents)); err == nil {
			info.TotalMemoryBytes = memory.TotalBytes
		}
	}

	var stat syscall.Statfs_t

	if err := syscall.Statfs(c.diskPath, &stat); err == nil {
		info.TotalDiskBytes = stat.Blocks * uint64(stat.Bsize)
	}

	return info, nil
}

// Sample reads one measurement. Individual metrics that cannot be read are
// omitted rather than reported as zero.
func (c *Collector) Sample(recordedAt time.Time) (Sample, error) {
	sample := Sample{RecordedAt: recordedAt.UTC()}

	if contents, err := os.ReadFile(filepath.Join(c.procPath, "stat")); err == nil {
		if current, err := parseCPUStat(string(contents)); err == nil {
			c.mu.Lock()
			if c.previous.Total == 0 {
				sample.CPUUsage = cpuUsagePercent(cpuTimes{}, current)
			} else {
				sample.CPUUsage = cpuUsagePercent(c.previous, current)
			}
			c.previous = current
			c.mu.Unlock()
		}
	}

	if contents, err := os.ReadFile(filepath.Join(c.procPath, "meminfo")); err == nil {
		if memory, err := parseMemInfo(string(contents)); err == nil {
			used := usedBytes(memory.TotalBytes, memory.AvailableBytes)
			sample.MemoryUsage = percentage(used, memory.TotalBytes)
			sample.MemoryUsedBytes = &used
		}
	}

	var stat syscall.Statfs_t

	if err := syscall.Statfs(c.diskPath, &stat); err == nil {
		blockSize := uint64(stat.Bsize)
		total := stat.Blocks * blockSize
		free := stat.Bfree * blockSize
		used := usedBytes(total, free)

		sample.DiskUsage = percentage(used, total)
		sample.DiskUsedBytes = &used
	}

	if contents, err := os.ReadFile(filepath.Join(c.procPath, "net", "dev")); err == nil {
		if counters, err := parseNetDev(string(contents)); err == nil {
			sample.NetworkInBytes = &counters.InBytes
			sample.NetworkOutBytes = &counters.OutBytes
			sample.Metadata = map[string]any{"network_counters": "cumulative_since_boot"}
		}
	}

	return sample, nil
}

// Uptime reports host uptime from procfs.
func (c *Collector) Uptime() (time.Duration, error) {
	contents, err := os.ReadFile(filepath.Join(c.procPath, "uptime"))
	if err != nil {
		return 0, err
	}

	return parseUptime(string(contents))
}

// LoadAverage returns the one, five and fifteen minute load averages.
func (c *Collector) LoadAverage() []float64 {
	contents, err := os.ReadFile(filepath.Join(c.procPath, "loadavg"))
	if err != nil {
		return nil
	}

	return parseLoadAverage(string(contents))
}
