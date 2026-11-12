// Command cloudforge-agent enrolls a server with a CloudForge control plane and
// runs the long-lived agent that reports health, inventory and metrics and
// executes the Phase 1 command allowlist.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/agent"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/config"
	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/enrollment"
)

// version is replaced at build time with -ldflags "-X main.version=...".
var version = "1.0.0-dev"

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "cloudforge-agent:", err)

		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) == 0 {
		usage()

		return errors.New("a command is required")
	}

	switch args[0] {
	case "enroll":
		return runEnroll(args[1:])
	case "run":
		return runAgent(args[1:])
	case "version", "--version", "-version":
		fmt.Println(version)

		return nil
	case "help", "--help", "-h":
		usage()

		return nil
	default:
		usage()

		return fmt.Errorf("unknown command %q", args[0])
	}
}

func runEnroll(args []string) error {
	cfg := config.FromEnvironment(version)

	flags := flag.NewFlagSet("enroll", flag.ContinueOnError)
	addSharedFlags(flags, &cfg)

	var (
		token   string
		force   bool
		timeout time.Duration
	)

	flags.StringVar(&token, "token", cfg.EnrollmentToken, "single-use enrollment token (env CLOUDFORGE_ENROLLMENT_TOKEN)")
	flags.BoolVar(&force, "force", false, "replace an existing local identity")
	flags.DurationVar(&timeout, "timeout", cfg.RequestTimeout, "enrollment request timeout")

	if err := flags.Parse(args); err != nil {
		return err
	}

	cfg.EnrollmentToken = token

	if err := cfg.ValidateForEnrollment(); err != nil {
		return err
	}

	logger := newLogger(cfg.LogLevel)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	logger.Info("enrolling with the control plane",
		"control_plane", cfg.ControlPlaneURL,
		"server_id", cfg.ServerID,
	)

	result, err := enrollment.Enroll(ctx, enrollment.Options{
		ControlPlaneURL: cfg.ControlPlaneURL,
		ServerID:        cfg.ServerID,
		Token:           cfg.EnrollmentToken,
		MachineID:       cfg.MachineID,
		DataDir:         cfg.DataDir,
		DockerSocket:    cfg.DockerSocket,
		CAFile:          cfg.CAFile,
		AgentVersion:    cfg.AgentVersion,
		Timeout:         timeout,
		Force:           force,
	})
	if err != nil {
		return err
	}

	logger.Info("agent enrolled",
		"server_id", cfg.ServerID,
		"fingerprint", result.Identity.Fingerprint,
		"expires_at", result.Certificate.NotAfter,
		"data_dir", cfg.DataDir,
	)

	return nil
}

func runAgent(args []string) error {
	cfg := config.FromEnvironment(version)

	flags := flag.NewFlagSet("run", flag.ContinueOnError)
	addSharedFlags(flags, &cfg)

	flags.DurationVar(&cfg.HeartbeatInterval, "heartbeat-interval", cfg.HeartbeatInterval, "heartbeat cadence")
	flags.DurationVar(&cfg.CommandPollInterval, "command-poll-interval", cfg.CommandPollInterval, "pause between empty command polls")
	flags.IntVar(&cfg.CommandPollWait, "command-poll-wait", cfg.CommandPollWait, "long-poll wait in seconds (0-25)")
	flags.DurationVar(&cfg.MetricsInterval, "metrics-interval", cfg.MetricsInterval, "metrics sample cadence")
	flags.DurationVar(&cfg.InventoryInterval, "inventory-interval", cfg.InventoryInterval, "inventory refresh cadence")
	flags.DurationVar(&cfg.RequestTimeout, "request-timeout", cfg.RequestTimeout, "HTTP request timeout")
	flags.DurationVar(&cfg.RotateBefore, "rotate-before", cfg.RotateBefore, "rotate the certificate this long before expiry")

	if err := flags.Parse(args); err != nil {
		return err
	}

	if err := cfg.ValidateForRun(); err != nil {
		return err
	}

	logger := newLogger(cfg.LogLevel)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	runner, err := agent.New(cfg, logger)
	if err != nil {
		return err
	}

	return runner.Run(ctx)
}

func addSharedFlags(flags *flag.FlagSet, cfg *config.Config) {
	flags.StringVar(&cfg.ControlPlaneURL, "control-plane", cfg.ControlPlaneURL, "control plane base URL (env CLOUDFORGE_CONTROL_PLANE_URL)")
	flags.StringVar(&cfg.ServerID, "server-id", cfg.ServerID, "CloudForge server ID (env CLOUDFORGE_SERVER_ID)")
	flags.StringVar(&cfg.DataDir, "data-dir", cfg.DataDir, "agent state directory (env CLOUDFORGE_DATA_DIR)")
	flags.StringVar(&cfg.DockerSocket, "docker-socket", cfg.DockerSocket, "Docker Unix socket (env CLOUDFORGE_DOCKER_SOCKET)")
	flags.StringVar(&cfg.CAFile, "ca-file", cfg.CAFile, "additional CA bundle for the control plane TLS certificate")
	flags.StringVar(&cfg.LogLevel, "log-level", cfg.LogLevel, "debug, info, warn or error")

	flags.Usage = usage
}

func newLogger(level string) *slog.Logger {
	var threshold slog.Level

	switch strings.ToLower(level) {
	case "debug":
		threshold = slog.LevelDebug
	case "warn", "warning":
		threshold = slog.LevelWarn
	case "error":
		threshold = slog.LevelError
	default:
		threshold = slog.LevelInfo
	}

	return slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: threshold}))
}

func usage() {
	fmt.Fprintf(os.Stderr, `CloudForge agent %s

Usage:
  cloudforge-agent enroll [flags]   redeem a single-use enrollment token
  cloudforge-agent run [flags]      run the agent
  cloudforge-agent version          print the agent version

Enrollment flags:
  --token string              single-use enrollment token
  --force                     replace an existing local identity
  --timeout duration          enrollment timeout (default 45s)

Shared flags:
  --control-plane string      control plane base URL
  --server-id string          CloudForge server ID
  --data-dir string           state directory (default %s)
  --docker-socket string      Docker Unix socket (default %s)
  --ca-file string            extra CA bundle for control plane TLS
  --log-level string          debug, info, warn or error

Run flags:
  --heartbeat-interval duration
  --command-poll-interval duration
  --command-poll-wait int
  --metrics-interval duration
  --inventory-interval duration
  --request-timeout duration
  --rotate-before duration

Environment variables:
  CLOUDFORGE_CONTROL_PLANE_URL, CLOUDFORGE_SERVER_ID, CLOUDFORGE_ENROLLMENT_TOKEN,
  CLOUDFORGE_DATA_DIR, CLOUDFORGE_DOCKER_SOCKET, CLOUDFORGE_CA_FILE,
  CLOUDFORGE_LOG_LEVEL
`, version, config.DefaultDataDir, config.DefaultDockerSocket)
}
