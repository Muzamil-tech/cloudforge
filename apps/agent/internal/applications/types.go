// Package applications defines the typed contract between the control plane
// and the agent for application container deployments.
package applications

import "context"

// Spec is the allowlisted application deployment request. It contains no
// shell command, mount, privileged flag or host network option.
type Spec struct {
	ID                    string
	DeploymentID          string
	ContainerName         string
	PreviousContainerName string
	Image                 string
	ContainerPort         int
	HealthPath            string
}

// Endpoint is the real result of an application deployment or inspection.
type Endpoint struct {
	ApplicationID            string
	DeploymentID             string
	ContainerID              string
	ContainerName            string
	Host                     string
	Port                     int
	Status                   string
	Health                   string
	PreviousContainerRemoved bool
	Logs                     string
	Warnings                 []string
}

// State is the current Docker state for an application container.
type State struct {
	Status      string
	ContainerID string
	Port        int
	Health      string
	Image       string
}

// Manager is implemented by the Docker client and used by the executor.
type Manager interface {
	DeployApplication(ctx context.Context, spec Spec) (Endpoint, error)
	StartApplication(ctx context.Context, applicationID string, containerRef string) error
	StopApplication(ctx context.Context, applicationID string, containerRef string) error
	DeleteApplication(ctx context.Context, applicationID string, containerRef string) error
	InspectApplication(ctx context.Context, applicationID string, containerRef string, containerPort int) (State, error)
	ApplicationLogs(ctx context.Context, applicationID string, containerRef string, tail int) (string, error)
}
