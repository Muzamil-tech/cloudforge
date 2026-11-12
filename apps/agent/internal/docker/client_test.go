package docker

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
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
