package docker

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/applications"
)

const (
	testApplicationID   = "01a1d058-8612-7209-9082-03adf0d11976"
	testDeploymentID    = "01a1d058-8612-7209-9082-03adf0d11977"
	testApplicationName = "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-12345678"
	testPreviousName    = "cloudforge-app-01a1d058-8612-7209-9082-03adf0d11976-00000000"
)

func TestParseImageReference(t *testing.T) {
	t.Parallel()

	valid := []struct {
		image    string
		registry string
		name     string
		tag      string
		digest   string
	}{
		{image: "nginx:1.27-alpine", name: "nginx", tag: "1.27-alpine"},
		{image: "library/nginx", name: "library/nginx"},
		{image: "bitnami/nginx:1.27", name: "bitnami/nginx", tag: "1.27"},
		{image: "mirror.gcr.io/library/nginx:1.27", registry: "mirror.gcr.io", name: "library/nginx", tag: "1.27"},
		{image: "registry.example.com:5000/team/app:sha-abC_1", registry: "registry.example.com:5000", name: "team/app", tag: "sha-abC_1"},
		{image: "nginx@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", name: "nginx", digest: "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},
	}

	for _, testCase := range valid {
		ref, err := parseImageReference(testCase.image)
		if err != nil {
			t.Fatalf("parse %q: %v", testCase.image, err)
		}

		if ref.Registry != testCase.registry || ref.Name != testCase.name || ref.Tag != testCase.tag || ref.Digest != testCase.digest {
			t.Fatalf("unexpected reference for %q: %#v", testCase.image, ref)
		}
	}

	invalid := []string{
		"",
		"nginx:",
		"UPPER/nginx",
		"../nginx",
		"nginx//app",
		"nginx@sha256:short",
		"nginx with space",
		"nginx:bad tag",
		"https://registry.example.com/nginx",
	}

	for _, image := range invalid {
		if _, err := parseImageReference(image); err == nil {
			t.Fatalf("expected %q to be rejected", image)
		}
	}
}

func TestApplicationImageCandidatesPreferTheMirrorForDockerHub(t *testing.T) {
	t.Parallel()

	for image, expected := range map[string][]string{
		"nginx:1.27": {
			"mirror.gcr.io/library/nginx:1.27",
			"nginx:1.27",
		},
		"bitnami/nginx:1.27": {
			"mirror.gcr.io/bitnami/nginx:1.27",
			"bitnami/nginx:1.27",
		},
		"docker.io/library/nginx:1.27": {
			"mirror.gcr.io/library/nginx:1.27",
			"docker.io/library/nginx:1.27",
		},
		"registry.example.com/team/app:1.0": {
			"registry.example.com/team/app:1.0",
		},
		"mirror.gcr.io/library/nginx:1.27": {
			"mirror.gcr.io/library/nginx:1.27",
		},
	} {
		candidates := applicationImageCandidates(image)

		if len(candidates) != len(expected) {
			t.Fatalf("unexpected candidates for %q: %#v", image, candidates)
		}

		for index, candidate := range expected {
			if candidates[index] != candidate {
				t.Fatalf("unexpected candidates for %q: %#v", image, candidates)
			}
		}
	}
}

func TestDeployApplicationCreatesAndStartsAContainer(t *testing.T) {
	t.Parallel()

	health := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/" {
			t.Fatalf("unexpected health path %q", request.URL.Path)
		}

		response.WriteHeader(http.StatusOK)
		_, _ = response.Write([]byte("ok"))
	}))
	defer health.Close()

	healthPort := portFromServerURL(t, health.URL)

	var createBody map[string]any
	removedPrevious := false

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		switch {
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testApplicationName+"/json":
			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		case request.Method == http.MethodGet && request.URL.Path == "/images/mirror.gcr.io/library/nginx:1.27-alpine/json":
			return jsonResponse(t, http.StatusOK, `{}`), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/create":
			if err := json.NewDecoder(request.Body).Decode(&createBody); err != nil {
				t.Fatalf("decode create body: %v", err)
			}

			return jsonResponse(t, http.StatusCreated, `{"Id":"new-container"}`), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/new-container/start":
			return jsonResponse(t, http.StatusNoContent, ""), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/new-container/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"new-container",
				"Config":{"Image":"mirror.gcr.io/library/nginx:1.27-alpine","Labels":{"cloudforge.managed":"true","cloudforge.application_id":"%s","cloudforge.resource_type":"application"}},
				"State":{"Running":true,"Status":"running"},
				"NetworkSettings":{"Ports":{"80/tcp":[{"HostIp":"127.0.0.1","HostPort":"%d"}]}}
			}`, testApplicationID, healthPort)), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/new-container/logs":
			return logResponse(t, http.StatusOK, dockerLogFrame(1, "application started\n")), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testPreviousName+"/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"old-container",
				"Config":{"Labels":{"cloudforge.managed":"true","cloudforge.application_id":"%s","cloudforge.resource_type":"application"}},
				"State":{"Running":true,"Status":"running"},
				"NetworkSettings":{"Ports":{}}
			}`, testApplicationID)), nil
		case request.Method == http.MethodDelete && request.URL.Path == "/containers/old-container":
			removedPrevious = true

			return jsonResponse(t, http.StatusNoContent, ""), nil
		default:
			t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		}
	})

	endpoint, err := client.DeployApplication(context.Background(), applications.Spec{
		ID:                    testApplicationID,
		DeploymentID:          testDeploymentID,
		ContainerName:         testApplicationName,
		PreviousContainerName: testPreviousName,
		Image:                 "nginx:1.27-alpine",
		ContainerPort:         80,
		HealthPath:            "/",
	})
	if err != nil {
		t.Fatalf("deploy application: %v", err)
	}

	if endpoint.ContainerID != "new-container" || endpoint.Port != healthPort || endpoint.Status != "running" {
		t.Fatalf("unexpected endpoint %#v", endpoint)
	}

	if endpoint.Health != "healthy" || !endpoint.PreviousContainerRemoved {
		t.Fatalf("unexpected health or cleanup %#v", endpoint)
	}

	if !strings.Contains(endpoint.Logs, "application started") {
		t.Fatalf("unexpected logs %q", endpoint.Logs)
	}

	if !removedPrevious {
		t.Fatal("the previous application container was not removed")
	}

	if createBody["Image"] != "mirror.gcr.io/library/nginx:1.27-alpine" {
		t.Fatalf("unexpected image %#v", createBody["Image"])
	}

	labels, ok := createBody["Labels"].(map[string]any)
	if !ok || labels["cloudforge.application_id"] != testApplicationID ||
		labels["cloudforge.deployment_id"] != testDeploymentID ||
		labels["cloudforge.resource_type"] != "application" {
		t.Fatalf("unexpected labels %#v", createBody["Labels"])
	}

	hostConfig, ok := createBody["HostConfig"].(map[string]any)
	if !ok {
		t.Fatalf("missing HostConfig %#v", createBody)
	}

	portBindings, ok := hostConfig["PortBindings"].(map[string]any)
	if !ok {
		t.Fatalf("missing port bindings %#v", hostConfig)
	}

	bindings, ok := portBindings["80/tcp"].([]any)
	if !ok || len(bindings) != 1 {
		t.Fatalf("unexpected port bindings %#v", portBindings)
	}

	binding, ok := bindings[0].(map[string]any)
	if !ok || binding["HostIp"] != "127.0.0.1" || binding["HostPort"] != nil {
		t.Fatalf("unexpected loopback binding %#v", binding)
	}

	if _, ok := hostConfig["Binds"]; ok {
		t.Fatalf("application containers must not receive arbitrary binds: %#v", hostConfig)
	}

	if _, ok := hostConfig["Privileged"]; ok {
		t.Fatalf("application containers must not be privileged: %#v", hostConfig)
	}

	securityOpt, ok := hostConfig["SecurityOpt"].([]any)
	if !ok || len(securityOpt) != 1 || securityOpt[0] != "no-new-privileges" {
		t.Fatalf("application containers must drop new privileges: %#v", hostConfig["SecurityOpt"])
	}

	restartPolicy, ok := hostConfig["RestartPolicy"].(map[string]any)
	if !ok || restartPolicy["Name"] != "unless-stopped" {
		t.Fatalf("unexpected restart policy %#v", hostConfig["RestartPolicy"])
	}
}

func TestDeployApplicationRemovesTheNewContainerWhenHealthFails(t *testing.T) {
	t.Parallel()

	health := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.WriteHeader(http.StatusInternalServerError)
	}))
	defer health.Close()

	healthPort := portFromServerURL(t, health.URL)
	removedNew := false
	inspectedPrevious := false

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		switch {
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testApplicationName+"/json":
			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		case request.Method == http.MethodGet && strings.HasPrefix(request.URL.Path, "/images/"):
			return jsonResponse(t, http.StatusOK, `{}`), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/create":
			return jsonResponse(t, http.StatusCreated, `{"Id":"new-container"}`), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/new-container/start":
			return jsonResponse(t, http.StatusNoContent, ""), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/new-container/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"new-container",
				"Config":{"Image":"nginx:1.27-alpine"},
				"State":{"Running":true,"Status":"running"},
				"NetworkSettings":{"Ports":{"80/tcp":[{"HostIp":"127.0.0.1","HostPort":"%d"}]}}
			}`, healthPort)), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/new-container/logs":
			return logResponse(t, http.StatusOK, dockerLogFrame(2, "application failed\n")), nil
		case request.Method == http.MethodDelete && request.URL.Path == "/containers/new-container":
			removedNew = true

			return jsonResponse(t, http.StatusNoContent, ""), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testPreviousName+"/json":
			inspectedPrevious = true

			return jsonResponse(t, http.StatusOK, `{}`), nil
		default:
			t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		}
	})
	client.healthTimeout = 50 * time.Millisecond

	endpoint, err := client.DeployApplication(context.Background(), applications.Spec{
		ID:                    testApplicationID,
		DeploymentID:          testDeploymentID,
		ContainerName:         testApplicationName,
		PreviousContainerName: testPreviousName,
		Image:                 "nginx:1.27-alpine",
		ContainerPort:         80,
		HealthPath:            "/",
	})
	if err == nil {
		t.Fatal("expected the failed health check to surface")
	}

	if !removedNew {
		t.Fatal("the failed new container was not removed")
	}

	if inspectedPrevious {
		t.Fatal("the previous container must not be touched when the new deployment is not healthy")
	}

	if !strings.Contains(endpoint.Logs, "application failed") {
		t.Fatalf("the failed deployment logs were not returned: %q", endpoint.Logs)
	}
}

func TestDeployApplicationRecoversAnExistingDeploymentContainer(t *testing.T) {
	t.Parallel()

	health := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		response.WriteHeader(http.StatusNoContent)
	}))
	defer health.Close()

	healthPort := portFromServerURL(t, health.URL)
	created := false

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		switch {
		case request.Method == http.MethodGet && (request.URL.Path == "/containers/"+testApplicationName+"/json" || request.URL.Path == "/containers/existing-container/json"):
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"existing-container",
				"Config":{"Image":"mirror.gcr.io/library/nginx:1.27-alpine","Labels":{"cloudforge.managed":"true","cloudforge.application_id":"%s","cloudforge.resource_type":"application"}},
				"State":{"Running":true,"Status":"running"},
				"NetworkSettings":{"Ports":{"80/tcp":[{"HostIp":"127.0.0.1","HostPort":"%d"}]}}
			}`, testApplicationID, healthPort)), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/create":
			created = true

			return jsonResponse(t, http.StatusCreated, `{"Id":"new-container"}`), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/existing-container/logs":
			return logResponse(t, http.StatusOK, dockerLogFrame(1, "recovered\n")), nil
		default:
			t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		}
	})

	endpoint, err := client.DeployApplication(context.Background(), applications.Spec{
		ID:            testApplicationID,
		DeploymentID:  testDeploymentID,
		ContainerName: testApplicationName,
		Image:         "nginx:1.27-alpine",
		ContainerPort: 80,
		HealthPath:    "/",
	})
	if err != nil {
		t.Fatalf("recover application: %v", err)
	}

	if created {
		t.Fatal("an existing deployment container must not be recreated")
	}

	if endpoint.ContainerID != "existing-container" || endpoint.Port != healthPort {
		t.Fatalf("unexpected recovered endpoint %#v", endpoint)
	}
}

func TestApplicationStartStopDeleteInspectAndLogs(t *testing.T) {
	t.Parallel()

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		switch {
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testApplicationName+"/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"container-1",
				"Config":{"Image":"nginx:1.27-alpine","Labels":{"cloudforge.managed":"true","cloudforge.application_id":"%s","cloudforge.resource_type":"application"}},
				"State":{"Running":true,"Status":"running"},
				"NetworkSettings":{"Ports":{"80/tcp":[{"HostIp":"127.0.0.1","HostPort":"32780"}]}}
			}`, testApplicationID)), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/container-1/stop":
			return jsonResponse(t, http.StatusNoContent, ""), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`[{"Id":"container-1","Labels":{"cloudforge.application_id":"%s"}}]`, testApplicationID)), nil
		case request.Method == http.MethodDelete && request.URL.Path == "/containers/container-1":
			return jsonResponse(t, http.StatusNoContent, ""), nil
		case request.Method == http.MethodGet && request.URL.Path == "/containers/container-1/logs":
			return logResponse(t, http.StatusOK, dockerLogFrame(1, "line one\n")), nil
		default:
			t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		}
	})

	if err := client.StopApplication(context.Background(), testApplicationID, testApplicationName); err != nil {
		t.Fatalf("stop application: %v", err)
	}

	state, err := client.InspectApplication(context.Background(), testApplicationID, testApplicationName, 80)
	if err != nil {
		t.Fatalf("inspect application: %v", err)
	}

	if state.Status != "running" || state.Port != 32780 || state.Image != "nginx:1.27-alpine" {
		t.Fatalf("unexpected state %#v", state)
	}

	logs, err := client.ApplicationLogs(context.Background(), testApplicationID, testApplicationName, 200)
	if err != nil {
		t.Fatalf("application logs: %v", err)
	}

	if !strings.Contains(logs, "line one") {
		t.Fatalf("unexpected logs %q", logs)
	}

	if err := client.DeleteApplication(context.Background(), testApplicationID, testApplicationName); err != nil {
		t.Fatalf("delete application: %v", err)
	}
}

func TestApplicationStartStartsAStoppedContainer(t *testing.T) {
	t.Parallel()

	started := false

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		switch {
		case request.Method == http.MethodGet && request.URL.Path == "/containers/"+testApplicationName+"/json":
			return jsonResponse(t, http.StatusOK, fmt.Sprintf(`{
				"Id":"container-1",
				"Config":{"Labels":{"cloudforge.managed":"true","cloudforge.application_id":"%s","cloudforge.resource_type":"application"}},
				"State":{"Running":false,"Status":"exited"}
			}`, testApplicationID)), nil
		case request.Method == http.MethodPost && request.URL.Path == "/containers/container-1/start":
			started = true

			return jsonResponse(t, http.StatusNoContent, ""), nil
		default:
			t.Errorf("unexpected Docker request %s %s", request.Method, request.URL.Path)

			return jsonResponse(t, http.StatusNotFound, `{}`), nil
		}
	})

	if err := client.StartApplication(context.Background(), testApplicationID, testApplicationName); err != nil {
		t.Fatalf("start application: %v", err)
	}

	if !started {
		t.Fatal("the stopped application container was not started")
	}
}

func TestApplicationOperationsRejectForeignContainers(t *testing.T) {
	t.Parallel()

	client := applicationTestClient(t, func(request *http.Request) (*http.Response, error) {
		return jsonResponse(t, http.StatusOK, `{
			"Id":"foreign",
			"Config":{"Labels":{"cloudforge.managed":"true","cloudforge.application_id":"01a1d058-8612-7209-9082-03adf0d11999","cloudforge.resource_type":"application"}},
			"State":{"Running":true,"Status":"running"}
		}`), nil
	})

	if err := client.StopApplication(context.Background(), testApplicationID, testApplicationName); err == nil {
		t.Fatal("expected a foreign container to be rejected")
	}

	if _, err := client.InspectApplication(context.Background(), testApplicationID, testApplicationName, 80); err == nil {
		t.Fatal("expected inspection of a foreign container to be rejected")
	}

	if _, err := client.ApplicationLogs(context.Background(), testApplicationID, testApplicationName, 200); err == nil {
		t.Fatal("expected logs from a foreign container to be rejected")
	}
}

func TestDecodeDockerLogStreamAndBoundLogs(t *testing.T) {
	t.Parallel()

	stream := append(dockerLogFrame(1, "out one\n"), dockerLogFrame(2, "err one\n")...)
	stream = append(stream, dockerLogFrame(1, "out two\n")...)

	decoded := decodeDockerLogStream(stream)
	if decoded != "out one\nerr one\nout two\n" {
		t.Fatalf("unexpected decoded logs %q", decoded)
	}

	lines := make([]string, 300)
	for index := range lines {
		lines[index] = fmt.Sprintf("line-%03d", index+1)
	}

	bounded := boundLogs(strings.Join(lines, "\n"), 200)
	if !strings.HasPrefix(bounded, "line-101") {
		t.Fatalf("unexpected bounded log start %q", bounded[:16])
	}

	if strings.Contains(bounded, "line-100") {
		t.Fatal("the bounded logs retained a line older than the tail")
	}
}

func applicationTestClient(t *testing.T, transport roundTripFunc) *Client {
	t.Helper()

	return &Client{
		httpClient:    &http.Client{Transport: transport},
		healthTimeout: 2 * time.Second,
	}
}

func portFromServerURL(t *testing.T, rawURL string) int {
	t.Helper()

	parsed, err := url.Parse(rawURL)
	if err != nil {
		t.Fatalf("parse server URL: %v", err)
	}

	port, err := strconv.Atoi(parsed.Port())
	if err != nil {
		t.Fatalf("parse server port: %v", err)
	}

	return port
}

func logResponse(t *testing.T, status int, frame []byte) *http.Response {
	t.Helper()

	return &http.Response{
		StatusCode: status,
		Header:     http.Header{"Content-Type": []string{"application/vnd.docker.multiplexed-stream"}},
		Body:       io.NopCloser(bytes.NewReader(frame)),
	}
}

func dockerLogFrame(stream byte, text string) []byte {
	frame := make([]byte, 8+len(text))
	frame[0] = stream
	binary.BigEndian.PutUint32(frame[4:8], uint32(len(text)))
	copy(frame[8:], text)

	return frame
}
