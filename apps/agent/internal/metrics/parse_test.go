package metrics

import (
	"encoding/json"
	"testing"
	"time"
)

func TestParseCPUStatReadsTheAggregateLine(t *testing.T) {
	t.Parallel()

	contents := `cpu  100 20 30 800 50 0 10 5 0 0
cpu0 10 1 2 80 5 0 1 0 0 0
intr 12345
`

	times, err := parseCPUStat(contents)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	// Total includes every reported field up to steal: 100+20+30+800+50+0+10+5.
	if times.Total != 1015 {
		t.Fatalf("unexpected total %d", times.Total)
	}

	if times.Idle != 850 {
		t.Fatalf("expected idle plus iowait, got %d", times.Idle)
	}
}

func TestParseCPUStatRejectsMalformedInput(t *testing.T) {
	t.Parallel()

	for name, contents := range map[string]string{
		"missing":        "intr 1\nctx 2\n",
		"too few fields": "cpu 1 2 3\n",
		"non numeric":    "cpu 1 2 nope 4 5\n",
	} {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			if _, err := parseCPUStat(contents); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}

func TestCPUUsagePercent(t *testing.T) {
	t.Parallel()

	usage := cpuUsagePercent(cpuTimes{Total: 1000, Idle: 800}, cpuTimes{Total: 2000, Idle: 1500})
	if usage == nil {
		t.Fatal("expected a measurement")
	}

	// 1000 jiffies elapsed with 700 idle leaves 300 busy jiffies (30%).
	if *usage < 29.9 || *usage > 30.1 {
		t.Fatalf("expected 30%% usage, got %f", *usage)
	}

	if cpuUsagePercent(cpuTimes{Total: 2000, Idle: 1500}, cpuTimes{Total: 2000, Idle: 1500}) != nil {
		t.Fatal("an empty window must not report a measurement")
	}

	// A counter reset cannot produce a negative or above-100 value.
	clamped := cpuUsagePercent(cpuTimes{Total: 100, Idle: 0}, cpuTimes{Total: 200, Idle: 500})
	if clamped == nil || *clamped != 0 {
		t.Fatalf("expected idle to clamp to the sample window, got %v", clamped)
	}
}

func TestParseMemInfo(t *testing.T) {
	t.Parallel()

	info, err := parseMemInfo(`MemTotal:       16000000 kB
MemFree:         1200000 kB
MemAvailable:    4000000 kB
Buffers:          200000 kB
`)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	if info.TotalBytes != 16000000*1024 {
		t.Fatalf("unexpected total %d", info.TotalBytes)
	}

	if info.AvailableBytes != 4000000*1024 {
		t.Fatalf("unexpected available %d", info.AvailableBytes)
	}

	if _, err := parseMemInfo("MemFree: 1 kB\n"); err == nil {
		t.Fatal("expected missing MemTotal to fail")
	}

	clamped, err := parseMemInfo("MemTotal: 100 kB\nMemAvailable: 500 kB\n")
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	if clamped.AvailableBytes != clamped.TotalBytes {
		t.Fatalf("available memory must not exceed total: %#v", clamped)
	}
}

func TestParseNetDevSkipsLoopback(t *testing.T) {
	t.Parallel()

	counters, err := parseNetDev(`Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo:  900000    1000    0    0    0     0          0         0   900000    1000    0    0    0     0       0          0
  eth0: 1500000    2000    0    0    0     0          0         0  2500000    3000    0    0    0     0       0          0
  eth1: 500000     1000    0    0    0     0          0         0  1000000    1500    0    0    0     0       0          0
`)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	if counters.InBytes != 2000000 {
		t.Fatalf("unexpected received bytes %d", counters.InBytes)
	}

	if counters.OutBytes != 3500000 {
		t.Fatalf("unexpected transmitted bytes %d", counters.OutBytes)
	}

	if _, err := parseNetDev("lo: 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16\n"); err == nil {
		t.Fatal("expected loopback-only input to fail")
	}
}

func TestParseLoadAverage(t *testing.T) {
	t.Parallel()

	values := parseLoadAverage("0.52 0.44 0.31 2/345 12345\n")
	if len(values) != 3 || values[0] != 0.52 || values[2] != 0.31 {
		t.Fatalf("unexpected values %#v", values)
	}

	if parseLoadAverage("only-one") != nil {
		t.Fatal("expected malformed input to yield nil")
	}
}

func TestParseUptime(t *testing.T) {
	t.Parallel()

	duration, err := parseUptime("12345.67 8901.23\n")
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	if duration != 12345*time.Second+670*time.Millisecond {
		t.Fatalf("unexpected duration %s", duration)
	}

	if _, err := parseUptime("not-a-number\n"); err == nil {
		t.Fatal("expected an error")
	}
}

func TestParseOSRelease(t *testing.T) {
	t.Parallel()

	contents := `NAME="Ubuntu"
VERSION="24.04.1 LTS (Noble Numbat)"
PRETTY_NAME="Ubuntu 24.04.1 LTS"
ID=ubuntu
`
	if got := parseOSRelease(contents); got != "Ubuntu 24.04.1 LTS" {
		t.Fatalf("unexpected pretty name %q", got)
	}

	if got := parseOSRelease("NAME=Debian\n"); got != "" {
		t.Fatalf("expected empty result, got %q", got)
	}
}

func TestDiskHelpers(t *testing.T) {
	t.Parallel()

	if used := usedBytes(1000, 400); used != 600 {
		t.Fatalf("unexpected used bytes %d", used)
	}

	if used := usedBytes(1000, 2000); used != 0 {
		t.Fatalf("free space above total must clamp to zero, got %d", used)
	}

	if percentage(50, 0) != nil {
		t.Fatal("a zero total must not report a percentage")
	}

	value := percentage(250, 1000)
	if value == nil || *value != 25 {
		t.Fatalf("unexpected percentage %v", value)
	}

	clamped := percentage(5000, 1000)
	if clamped == nil || *clamped != 100 {
		t.Fatalf("expected percentage to clamp at 100, got %v", clamped)
	}
}

func TestSamplePointPreservesNullability(t *testing.T) {
	t.Parallel()

	recorded := time.Date(2026, 11, 12, 10, 30, 0, 0, time.UTC)
	cpu := 42.5

	sample := Sample{
		RecordedAt: recorded,
		CPUUsage:   &cpu,
		Metadata:   map[string]any{"platform": "linux"},
	}

	point := sample.Point()

	if point.RecordedAt != "2026-11-12T10:30:00Z" {
		t.Fatalf("unexpected timestamp %q", point.RecordedAt)
	}

	if point.CPUUsage == nil || *point.CPUUsage != 42.5 {
		t.Fatalf("unexpected CPU usage %v", point.CPUUsage)
	}

	if point.MemoryUsage != nil || point.DiskUsage != nil {
		t.Fatal("unsupported metrics must stay nil rather than report zero")
	}

	encoded, err := json.Marshal(point)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	if string(encoded) == "" {
		t.Fatal("expected JSON output")
	}

	var decoded map[string]any
	if err := json.Unmarshal(encoded, &decoded); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if _, exists := decoded["memory_usage"]; exists {
		t.Fatal("nil metrics must be omitted from the wire format")
	}
}
