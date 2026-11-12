// Package resources defines the typed contract between the command executor and
// the Docker resource manager. The manager only creates allowlisted database
// images; it never accepts arbitrary image names or container commands.
package resources

import "context"

type Kind string

const (
	Postgres Kind = "postgresql"
	Redis    Kind = "redis"
)

type CreateSpec struct {
	ID            string
	Kind          Kind
	ContainerName string
	VolumeName    string
}

type Endpoint struct {
	ContainerID string
	Host        string
	Port        int
	Username    string
	Database    string
	Password    string
	Status      string
}

type State struct {
	Status      string
	Port        int
	ContainerID string
}

type Manager interface {
	Create(ctx context.Context, spec CreateSpec) (Endpoint, error)
	Start(ctx context.Context, containerRef string) error
	Stop(ctx context.Context, containerRef string) error
	Delete(ctx context.Context, containerRef string, volumeName string) error
	InspectResource(ctx context.Context, containerRef string) (State, error)
}
