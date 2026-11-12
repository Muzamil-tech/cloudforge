package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"testing"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/resources"
)

type roundTripFunc func(*http.Request) (*http.Response, error)

func (f roundTripFunc) RoundTrip(request *http.Request) (*http.Response, error) {
	return f(request)
}

func jsonResponse(t *testing.T, status int, body string) *http.Response {
	t.Helper()

	return &http.Response{
		StatusCode: status,
		Header:     http.Header{"Content-Type": []string{"application/json"}},
		Body:       io.NopCloser(strings.NewReader(body)),
	}
}

func TestNewRejectsNonUnixEndpoints(t *testing.T) {
	t.Parallel()

	for _, endpoint := range []string{
		"",
		"tcp://127.0.0.1:2375",
		"http://docker.example.com",
		"https://docker.example.com",
		"ssh://root@docker.example.com",
		"npipe:////./pipe/docker_engine",
		"var/run/docker.sock",
	} {
		if _, err := New(endpoint); err == nil {
			t.Fatalf("expected %q to be rejected", endpoint)
		}
	}
}

func TestNewAcceptsUnixSocketPaths(t *testing.T) {
	t.Parallel()

	for _, endpoint := range []string{"/var/run/docker.sock", "unix:///var/run/docker.sock"} {
		client, err := New(endpoint)
		if err != nil {
			t.Fatalf("expected %q to be accepted: %v", endpoint, err)
		}

		if client.SocketPath() != "/var/run/docker.sock" {
			t.Fatalf("unexpected socket path %q", client.SocketPath())
		}
	}
}

func TestInspectReportsDaemonCapabilities(t *testing.T) {
	t.Parallel()

	client := &Client{
		socketPath: "/var/run/docker.sock",
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			switch request.URL.Path {
			case "/version":
				return jsonResponse(t, http.StatusOK, `{
					"Version": "27.1.1",
					"ApiVersion": "1.46",
					"Os": "linux",
					"Arch": "amd64"
				}`), nil
			case "/info":
				return jsonResponse(t, http.StatusOK, `{
					"ServerVersion": "27.1.1",
					"Driver": "overlay2",
					"SecurityOptions": [{"Name": "name=rootless"}],
					"Swarm": {"LocalNodeState": "active"}
				}`), nil
			default:
				t.Errorf("unexpected Docker path %q", request.URL.Path)

				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			}
		})},
	}

	summary, capabilities := client.Inspect(context.Background())

	if !summary.Available {
		t.Fatal("expected Docker to be available")
	}

	if summary.Version != "27.1.1" || summary.APIVersion != "1.46" {
		t.Fatalf("unexpected version summary %#v", summary)
	}

	if summary.StorageDriver != "overlay2" || !summary.Rootless {
		t.Fatalf("unexpected info summary %#v", summary)
	}

	names := map[string]string{}
	for _, capability := range capabilities {
		names[capability.Name] = capability.Version
	}

	for _, expected := range []string{"docker.engine", "docker.storage_driver", "docker.rootless", "docker.swarm"} {
		if _, ok := names[expected]; !ok {
			t.Fatalf("missing capability %q in %#v", expected, capabilities)
		}
	}

	if names["docker.engine"] != "27.1.1" || names["docker.storage_driver"] != "overlay2" {
		t.Fatalf("unexpected capability versions %#v", names)
	}
}

func TestInspectReportsUnavailableDaemon(t *testing.T) {
	t.Parallel()

	client := &Client{
		socketPath: "/var/run/docker.sock",
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			return nil, io.ErrUnexpectedEOF
		})},
	}

	summary, capabilities := client.Inspect(context.Background())

	if summary.Available {
		t.Fatal("expected Docker to be reported as unavailable")
	}

	if len(capabilities) != 0 {
		t.Fatalf("expected no capabilities, got %#v", capabilities)
	}
}

func TestInspectSurvivesInfoFailure(t *testing.T) {
	t.Parallel()

	client := &Client{
		socketPath: "/var/run/docker.sock",
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			if request.URL.Path == "/version" {
				return jsonResponse(t, http.StatusOK, `{"Version":"26.0.0","ApiVersion":"1.45"}`), nil
			}

			return jsonResponse(t, http.StatusInternalServerError, `{"message":"boom"}`), nil
		})},
	}

	summary, capabilities := client.Inspect(context.Background())

	if !summary.Available || summary.Version != "26.0.0" {
		t.Fatalf("unexpected summary %#v", summary)
	}

	if len(capabilities) != 1 || capabilities[0].Name != "docker.engine" {
		t.Fatalf("expected only the engine capability, got %#v", capabilities)
	}
}

func TestCreateResourceUsesAnAllowlistedContainerSpec(t *testing.T) {
	t.Parallel()

	var calls []string
	var createBody map[string]any

	client := &Client{
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			calls = append(calls, request.Method+" "+request.URL.Path)

			switch {
			case request.Method == http.MethodGet && request.URL.Path == "/images/postgres:16-alpine/json":
				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/images/create":
				return jsonResponse(t, http.StatusOK, `{"status":"Image is up to date"}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/containers/create":
				if err := json.NewDecoder(request.Body).Decode(&createBody); err != nil {
					t.Fatalf("decode create body: %v", err)
				}

				return jsonResponse(t, http.StatusCreated, `{"Id":"container-123"}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/containers/container-123/start":
				return jsonResponse(t, http.StatusNoContent, ""), nil
			case request.Method == http.MethodGet && request.URL.Path == "/containers/container-123/json":
				return jsonResponse(t, http.StatusOK, `{
					"Id":"container-123",
					"State":{"Running":true,"Status":"running"},
					"NetworkSettings":{"Ports":{"5432/tcp":[{"HostIp":"127.0.0.1","HostPort":"32768"}]}}
				}`), nil
			default:
				t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			}
		})},
	}

	endpoint, err := client.Create(context.Background(), resources.CreateSpec{
		ID:            "01a1d058-8612-7209-9082-03adf0d11976",
		Kind:          resources.Postgres,
		ContainerName: "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		VolumeName:    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	})
	if err != nil {
		t.Fatalf("create resource: %v", err)
	}

	if endpoint.Port != 32768 || endpoint.Host != "127.0.0.1" || endpoint.Username != "cloudforge" || endpoint.Database != "cloudforge" {
		t.Fatalf("unexpected endpoint %#v", endpoint)
	}

	if len(endpoint.Password) != 48 {
		t.Fatalf("expected a 48-character generated password, got %q", endpoint.Password)
	}

	if createBody["Image"] != "postgres:16-alpine" {
		t.Fatalf("unexpected image %#v", createBody["Image"])
	}

	hostConfig, ok := createBody["HostConfig"].(map[string]any)
	if !ok {
		t.Fatalf("missing HostConfig %#v", createBody)
	}

	binds, ok := hostConfig["Binds"].([]any)
	if !ok || len(binds) != 1 || binds[0] != "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data:/var/lib/postgresql/data" {
		t.Fatalf("unexpected binds %#v", hostConfig["Binds"])
	}

	if len(calls) != 5 {
		t.Fatalf("unexpected calls %#v", calls)
	}
}

func TestPrepareImageFallsBackToMirrorWhenDockerHubBlobIsBlocked(t *testing.T) {
	t.Parallel()

	var createBody map[string]any

	client := &Client{
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			switch {
			case request.Method == http.MethodGet && strings.HasPrefix(request.URL.Path, "/images/") && strings.HasSuffix(request.URL.Path, "/json"):
				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/images/create":
				if strings.Contains(request.URL.Query().Get("fromImage"), "mirror.gcr.io") {
					return jsonResponse(t, http.StatusOK, `{"status":"Downloaded newer image"}`), nil
				}

				return jsonResponse(t, http.StatusOK, `{"error":"unknown: failed to copy: httpReadSeeker: failed open: unexpected status from GET request to https://production.cloudfront.docker.com/blocked?Signature=secret"}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/containers/create":
				if err := json.NewDecoder(request.Body).Decode(&createBody); err != nil {
					t.Fatalf("decode create body: %v", err)
				}

				return jsonResponse(t, http.StatusCreated, `{"Id":"container-mirror"}`), nil
			case request.Method == http.MethodPost && request.URL.Path == "/containers/container-mirror/start":
				return jsonResponse(t, http.StatusNoContent, ""), nil
			case request.Method == http.MethodGet && request.URL.Path == "/containers/container-mirror/json":
				return jsonResponse(t, http.StatusOK, `{
					"Id":"container-mirror",
					"State":{"Running":true,"Status":"running"},
					"NetworkSettings":{"Ports":{"5432/tcp":[{"HostIp":"127.0.0.1","HostPort":"32770"}]}}
				}`), nil
			default:
				t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			}
		})},
	}

	_, err := client.Create(context.Background(), resources.CreateSpec{
		ID:            "01a1d058-8612-7209-9082-03adf0d11976",
		Kind:          resources.Postgres,
		ContainerName: "cloudforge-01a1d058-8612-7209-9082-03adf0d11976",
		VolumeName:    "cloudforge-01a1d058-8612-7209-9082-03adf0d11976-data",
	})
	if err != nil {
		t.Fatalf("create resource with mirror: %v", err)
	}

	if createBody["Image"] != "mirror.gcr.io/library/postgres:16-alpine" {
		t.Fatalf("expected the mirror image, got %#v", createBody["Image"])
	}
}

func TestCreateResourceRejectsUnknownKindsAndNames(t *testing.T) {
	t.Parallel()

	client := &Client{
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			t.Fatalf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return nil, fmt.Errorf("unexpected request")
		})},
	}

	cases := []resources.CreateSpec{
		{ID: "01a1d058-8612-7209-9082-03adf0d11976", Kind: "mysql", ContainerName: "db", VolumeName: "db-data"},
		{ID: "not-a-uuid", Kind: resources.Postgres, ContainerName: "db", VolumeName: "db-data"},
		{ID: "01a1d058-8612-7209-9082-03adf0d11976", Kind: resources.Postgres, ContainerName: "bad/name", VolumeName: "db-data"},
	}

	for _, spec := range cases {
		if _, err := client.Create(context.Background(), spec); err == nil {
			t.Fatalf("expected spec %#v to be rejected", spec)
		}
	}
}

func TestDeleteResourceTreatsMissingObjectsAsSuccess(t *testing.T) {
	t.Parallel()

	client := &Client{
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			if request.Method != http.MethodDelete {
				t.Fatalf("unexpected method %s", request.Method)
			}

			if strings.HasPrefix(request.URL.Path, "/containers/") {
				return jsonResponse(t, http.StatusNotFound, `{}`), nil
			}

			return jsonResponse(t, http.StatusNoContent, ""), nil
		})},
	}

	if err := client.Delete(context.Background(), "missing-container", "missing-volume-data"); err != nil {
		t.Fatalf("delete missing resource: %v", err)
	}
}

func TestInspectResourceReportsMissingContainer(t *testing.T) {
	t.Parallel()

	client := &Client{
		httpClient: &http.Client{Transport: roundTripFunc(func(request *http.Request) (*http.Response, error) {
			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		})},
	}

	state, err := client.InspectResource(context.Background(), "missing-container")
	if err != nil {
		t.Fatalf("inspect resource: %v", err)
	}

	if state.Status != "missing" {
		t.Fatalf("unexpected state %#v", state)
	}
}

func TestCleanPullMessageRemovesSignedRegistryUrls(t *testing.T) {
	t.Parallel()

	message := cleanPullMessage(`{"error":"unknown: failed to copy: httpReadSeeker: failed open: unexpected status from GET request to https://production.cloudfront.docker.com/blob?Signature=secret"}`)

	if strings.Contains(message, "Signature") || strings.Contains(message, "production.cloudfront.docker.com") {
		t.Fatalf("expected a sanitized message, got %q", message)
	}

	if !strings.Contains(message, "registry denied or network blocked") {
		t.Fatalf("unexpected message %q", message)
	}
}

func TestRedisResourceConfigUsesPasswordOnlyInTheCommand(t *testing.T) {
	t.Parallel()

	image, port, dataPath, env, command, username, database := resourceConfig(
		resources.Redis,
		"redis-secret",
		"postgres:16-alpine",
		"redis:7-alpine",
	)

	if image != "redis:7-alpine" || port != 6379 || dataPath != "/data" {
		t.Fatalf("unexpected Redis config %s %d %s", image, port, dataPath)
	}

	if len(env) != 0 || username != "" || database != "" {
		t.Fatalf("unexpected Redis environment %#v %q %q", env, username, database)
	}

	if len(command) != 5 || command[0] != "redis-server" || command[4] != "redis-secret" {
		t.Fatalf("unexpected Redis command %#v", command)
	}
}
