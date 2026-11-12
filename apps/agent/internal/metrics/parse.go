package metrics

import (
	"bufio"
	"errors"
	"strconv"
	"strings"
	"time"
)

type cpuTimes struct {
	Total uint64
	Idle  uint64
}

type memInfo struct {
	TotalBytes     uint64
	AvailableBytes uint64
}

type netCounters struct {
	InBytes  uint64
	OutBytes uint64
}

// parseCPUStat reads the aggregate "cpu" line from /proc/stat. Guest time is
// deliberately excluded because Linux already accounts it inside user time.
func parseCPUStat(contents string) (cpuTimes, error) {
	scanner := bufio.NewScanner(strings.NewReader(contents))

	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) == 0 || fields[0] != "cpu" {
			continue
		}

		if len(fields) < 5 {
			return cpuTimes{}, errors.New("the aggregate cpu line has too few fields")
		}

		var total uint64

		for index, raw := range fields[1:] {
			if index >= 8 {
				break
			}

			value, err := strconv.ParseUint(raw, 10, 64)
			if err != nil {
				return cpuTimes{}, errors.New("the aggregate cpu line contains a non-numeric field")
			}

			total += value
		}

		idle, _ := strconv.ParseUint(fields[4], 10, 64)
		wait, _ := strconv.ParseUint(fields[5], 10, 64)

		return cpuTimes{Total: total, Idle: idle + wait}, nil
	}

	return cpuTimes{}, errors.New("no aggregate cpu line found")
}

// cpuUsagePercent derives busy time from two cumulative samples. It returns nil
// when the sample window is empty so a zero is never reported as a measurement.
func cpuUsagePercent(previous, current cpuTimes) *float64 {
	if current.Total <= previous.Total {
		return nil
	}

	total := current.Total - previous.Total
	idle := current.Idle - previous.Idle

	if idle > total {
		idle = total
	}

	usage := float64(total-idle) / float64(total) * 100

	if usage < 0 {
		usage = 0
	}

	if usage > 100 {
		usage = 100
	}

	return &usage
}

func parseMemInfo(contents string) (memInfo, error) {
	var info memInfo

	scanner := bufio.NewScanner(strings.NewReader(contents))

	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 2 {
			continue
		}

		value, err := strconv.ParseUint(fields[1], 10, 64)
		if err != nil {
			continue
		}

		switch strings.TrimSuffix(fields[0], ":") {
		case "MemTotal":
			info.TotalBytes = value * 1024
		case "MemAvailable":
			info.AvailableBytes = value * 1024
		}
	}

	if info.TotalBytes == 0 {
		return memInfo{}, errors.New("MemTotal is missing from meminfo")
	}

	if info.AvailableBytes > info.TotalBytes {
		info.AvailableBytes = info.TotalBytes
	}

	return info, nil
}

// parseNetDev sums received and transmitted byte counters across every
// interface except loopback. Values are cumulative since boot.
func parseNetDev(contents string) (netCounters, error) {
	var counters netCounters
	var found bool

	scanner := bufio.NewScanner(strings.NewReader(contents))

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())

		separator := strings.Index(line, ":")
		if separator < 0 {
			continue
		}

		name := strings.TrimSpace(line[:separator])
		if name == "" || name == "lo" {
			continue
		}

		fields := strings.Fields(line[separator+1:])
		if len(fields) < 9 {
			continue
		}

		received, err := strconv.ParseUint(fields[0], 10, 64)
		if err != nil {
			continue
		}

		transmitted, err := strconv.ParseUint(fields[8], 10, 64)
		if err != nil {
			continue
		}

		counters.InBytes += received
		counters.OutBytes += transmitted
		found = true
	}

	if !found {
		return netCounters{}, errors.New("no network interfaces were found")
	}

	return counters, nil
}

func parseLoadAverage(contents string) []float64 {
	fields := strings.Fields(contents)
	if len(fields) < 3 {
		return nil
	}

	values := make([]float64, 0, 3)

	for _, raw := range fields[:3] {
		value, err := strconv.ParseFloat(raw, 64)
		if err != nil {
			return nil
		}

		values = append(values, value)
	}

	return values
}

func parseUptime(contents string) (time.Duration, error) {
	fields := strings.Fields(contents)
	if len(fields) == 0 {
		return 0, errors.New("uptime file is empty")
	}

	seconds, err := strconv.ParseFloat(fields[0], 64)
	if err != nil {
		return 0, errors.New("uptime value is not numeric")
	}

	return time.Duration(seconds * float64(time.Second)), nil
}

// parseOSRelease extracts PRETTY_NAME from /etc/os-release.
func parseOSRelease(contents string) string {
	scanner := bufio.NewScanner(strings.NewReader(contents))

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())

		if !strings.HasPrefix(line, "PRETTY_NAME=") {
			continue
		}

		value := strings.TrimPrefix(line, "PRETTY_NAME=")

		return strings.Trim(value, `"'`)
	}

	return ""
}

// usedBytes combines total and free values for a filesystem.
func usedBytes(total, free uint64) uint64 {
	if free > total {
		return 0
	}

	return total - free
}

func percentage(used, total uint64) *float64 {
	if total == 0 {
		return nil
	}

	value := float64(used) / float64(total) * 100

	if value < 0 {
		value = 0
	}

	if value > 100 {
		value = 100
	}

	return &value
}
