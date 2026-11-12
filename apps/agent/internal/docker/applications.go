package docker

import (
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/Muzamil-tech/cloudforge/apps/agent/internal/applications"
)

const (
	applicationHealthTimeout = 60 * time.Second
	applicationLogLimitBytes = 64 << 10
)

var (
	applicationImagePattern  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:/@-]{0,254}$`)
	applicationTagPattern    = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$`)
	applicationDigestPattern = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)
	applicationRepoPattern   = regexp.MustCompile(`^[a-z0-9]+(?:[._-][a-z0-9]+)*$`)
	applicationHostPattern   = regexp.MustCompile(`^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)(?::[0-9]{1,5})?$`)
	applicationHealthPattern = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*$`)
)

type imageReference struct {
	Original string
	Registry string
	Name     string
	Tag      string
	Digest   string
}

func (r imageReference) FullName() string {
	if r.Registry == "" {
		return r.Name
	}

	return r.Registry + "/" + r.Name
}

func parseImageReference(image string) (imageReference, error) {
	ref := imageReference{Original: image}
	value := strings.TrimSpace(image)

	if value == "" {
		return ref, errors.New("image reference is empty")
	}

	if len(value) > 255 {
		return ref, errors.New("image reference is longer than 255 characters")
	}

	if !applicationImagePattern.MatchString(value) {
		return ref, errors.New("image reference contains unsupported characters")
	}

	if strings.Contains(value, "..") || strings.Contains(value, "//") {
		return ref, errors.New("image reference contains an invalid path segment")
	}

	nameAndTag := value

	if index := strings.Index(value, "@"); index >= 0 {
		if strings.Contains(value[index+1:], "@") {
			return ref, errors.New("image reference contains more than one digest")
		}

		ref.Digest = value[index+1:]
		nameAndTag = value[:index]

		if !applicationDigestPattern.MatchString(ref.Digest) {
			return ref, errors.New("only sha256 image digests are supported")
		}
	}

	lastSlash := strings.LastIndex(nameAndTag, "/")
	lastColon := strings.LastIndex(nameAndTag, ":")

	if lastColon > lastSlash {
		ref.Tag = nameAndTag[lastColon+1:]
		nameAndTag = nameAndTag[:lastColon]

		if !applicationTagPattern.MatchString(ref.Tag) {
			return ref, errors.New("image tag is invalid")
		}
	}

	segments := strings.Split(nameAndTag, "/")

	for _, segment := range segments {
		if segment == "" || segment == "." || segment == ".." {
			return ref, errors.New("image path contains an invalid segment")
		}
	}

	if len(segments) > 1 && isRegistrySegment(segments[0]) {
		ref.Registry = segments[0]

		if !applicationHostPattern.MatchString(ref.Registry) {
			return ref, errors.New("image registry host is invalid")
		}

		segments = segments[1:]
	}

	if len(segments) == 0 {
		return ref, errors.New("image repository is empty")
	}

	for _, segment := range segments {
		if !applicationRepoPattern.MatchString(segment) {
			return ref, fmt.Errorf("image repository segment %q is invalid", segment)
		}
	}

	ref.Name = strings.Join(segments, "/")

	if ref.Name == "" {
		return ref, errors.New("image repository is empty")
	}

	return ref, nil
}

func isRegistrySegment(segment string) bool {
	return segment == "localhost" || strings.Contains(segment, ".") || strings.Contains(segment, ":")
}

func applicationImageCandidates(image string) []string {
	ref, err := parseImageReference(image)
	if err != nil {
		return []string{image}
	}

	if ref.Registry != "" && ref.Registry != "docker.io" && ref.Registry != "index.docker.io" {
		return []string{image}
	}

	name := ref.Name
	if !strings.Contains(name, "/") {
		name = "library/" + name
	}

	mirror := "mirror.gcr.io/" + name

	switch {
	case ref.Digest != "":
		mirror += "@" + ref.Digest
	case ref.Tag != "":
		mirror += ":" + ref.Tag
	}

	return uniqueStrings([]string{mirror, image})
}

func uniqueStrings(values []string) []string {
	seen := make(map[string]bool, len(values))
	result := make([]string, 0, len(values))

	for _, value := range values {
		if value == "" || seen[value] {
			continue
		}

		seen[value] = true
		result = append(result, value)
	}

	return result
}

func (c *Client) prepareApplicationImage(ctx context.Context, image string) (string, error) {
	candidates := applicationImageCandidates(image)

	for _, candidate := range candidates {
		exists, lookupErr := c.imageExists(ctx, candidate)

		if lookupErr == nil && exists {
			slog.Info("application image already present", "image", candidate)

			return candidate, nil
		}
	}

	var failures []string

	for _, candidate := range candidates {
		slog.Info("pulling application image", "image", candidate)

		if err := c.pullImage(ctx, candidate); err != nil {
			failures = append(failures, fmt.Sprintf("%s: %s", candidate, err))

			continue
		}

		slog.Info("application image ready", "image", candidate)

		return candidate, nil
	}

	return "", fmt.Errorf("could not prepare the application image: %s", strings.Join(failures, "; "))
}

func validateApplicationSpec(spec applications.Spec) error {
	if !uuidPattern.MatchString(spec.ID) {
		return errors.New("invalid application ID")
	}

	if !uuidPattern.MatchString(spec.DeploymentID) {
		return errors.New("invalid deployment ID")
	}

	if !namePattern.MatchString(spec.ContainerName) {
		return errors.New("invalid application container name")
	}

	if spec.PreviousContainerName != "" && !namePattern.MatchString(spec.PreviousContainerName) {
		return errors.New("invalid previous application container name")
	}

	if spec.ContainerPort < 1 || spec.ContainerPort > 65535 {
		return errors.New("application container port must be between 1 and 65535")
	}

	if spec.HealthPath != "" {
		if len(spec.HealthPath) > 255 || !applicationHealthPattern.MatchString(spec.HealthPath) {
			return errors.New("application health path is invalid")
		}
	}

	if _, err := parseImageReference(spec.Image); err != nil {
		return fmt.Errorf("invalid application image: %w", err)
	}

	return nil
}

// Deploy creates the new application container, waits for it to become healthy
// and only then removes the previous container. On failure the new container is
// removed and the previous container is left untouched.
func (c *Client) DeployApplication(ctx context.Context, spec applications.Spec) (applications.Endpoint, error) {
	endpoint := applications.Endpoint{
		ApplicationID: spec.ID,
		DeploymentID:  spec.DeploymentID,
		ContainerName: spec.ContainerName,
		Host:          "127.0.0.1",
	}

	if err := validateApplicationSpec(spec); err != nil {
		return endpoint, err
	}

	existing, found, err := c.applicationContainer(ctx, spec.ID, spec.ContainerName)
	if err != nil {
		return endpoint, err
	}

	if found {
		if !existing.State.Running {
			if err := c.startContainer(ctx, existing.ID); err != nil {
				return endpoint, fmt.Errorf("start the existing deployment container: %w", err)
			}
		}

		return c.finishApplicationDeploy(ctx, spec, existing.ID, endpoint)
	}

	resolvedImage, err := c.prepareApplicationImage(ctx, spec.Image)
	if err != nil {
		return endpoint, err
	}

	config := containerConfig{
		Image:        resolvedImage,
		ExposedPorts: map[string]struct{}{fmt.Sprintf("%d/tcp", spec.ContainerPort): {}},
		Labels: map[string]string{
			"cloudforge.managed":        "true",
			"cloudforge.application_id": spec.ID,
			"cloudforge.deployment_id":  spec.DeploymentID,
			"cloudforge.resource_type":  "application",
		},
		HostConfig: hostConfig{
			PortBindings: map[string][]portBinding{
				fmt.Sprintf("%d/tcp", spec.ContainerPort): {{HostIP: "127.0.0.1"}},
			},
			RestartPolicy: restartPolicy{Name: "unless-stopped"},
			SecurityOpt:   []string{"no-new-privileges"},
		},
	}

	created, err := c.createContainer(ctx, spec.ContainerName, config)
	if err != nil {
		// A retry or a concurrent attempt may have won the race. Recover the
		// labelled container instead of reporting a false provisioning failure.
		existing, found, recoverErr := c.applicationContainer(ctx, spec.ID, spec.ContainerName)
		if recoverErr == nil && found {
			return c.finishApplicationDeploy(ctx, spec, existing.ID, endpoint)
		}

		return endpoint, err
	}

	if err := c.startContainer(ctx, created.ID); err != nil {
		endpoint.Logs = c.applicationLogsBestEffort(ctx, created.ID, 200)
		_ = c.removeContainer(ctx, created.ID)

		return endpoint, fmt.Errorf("start application container: %w", err)
	}

	return c.finishApplicationDeploy(ctx, spec, created.ID, endpoint)
}

func (c *Client) finishApplicationDeploy(
	ctx context.Context,
	spec applications.Spec,
	containerID string,
	endpoint applications.Endpoint,
) (applications.Endpoint, error) {
	inspect, err := c.inspectContainer(ctx, containerID)
	if err != nil {
		return endpoint, err
	}

	port, err := inspect.boundPort(spec.ContainerPort)
	if err != nil {
		endpoint.Logs = c.applicationLogsBestEffort(ctx, containerID, 200)
		_ = c.removeContainer(ctx, containerID)

		return endpoint, err
	}

	endpoint.ContainerID = inspect.ID
	endpoint.Port = port

	health, logs, err := c.waitForApplicationHealth(ctx, containerID, spec.ContainerPort, spec.HealthPath)
	endpoint.Health = health
	endpoint.Logs = logs

	if err != nil {
		_ = c.removeContainer(ctx, containerID)

		return endpoint, err
	}

	endpoint.Status = "running"
	endpoint.PreviousContainerRemoved = true

	if spec.PreviousContainerName != "" && spec.PreviousContainerName != spec.ContainerName {
		previous, found, lookupErr := c.applicationContainer(ctx, spec.ID, spec.PreviousContainerName)

		switch {
		case lookupErr != nil:
			endpoint.PreviousContainerRemoved = false
			endpoint.Warnings = append(endpoint.Warnings, "the previous container could not be inspected: "+lookupErr.Error())
		case found:
			if removeErr := c.removeContainer(ctx, previous.ID); removeErr != nil && !errors.Is(removeErr, ErrNotFound) {
				endpoint.PreviousContainerRemoved = false
				endpoint.Warnings = append(endpoint.Warnings, "the previous container could not be removed: "+removeErr.Error())
			}
		}
	}

	return endpoint, nil
}

func (c *Client) waitForApplicationHealth(
	ctx context.Context,
	containerID string,
	containerPort int,
	healthPath string,
) (string, string, error) {
	budget := c.healthTimeout
	if budget <= 0 {
		budget = applicationHealthTimeout
	}

	deadline := time.Now().Add(budget)
	healthClient := &http.Client{
		Timeout: 5 * time.Second,
		CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}

	for {
		inspect, err := c.inspectContainer(ctx, containerID)
		if err != nil {
			return "unknown", c.applicationLogsBestEffort(ctx, containerID, 200), err
		}

		if !inspect.State.Running {
			return "exited", c.applicationLogsBestEffort(ctx, containerID, 200),
				errors.New("the application container exited before it became healthy")
		}

		if healthPath != "" {
			if port, portErr := inspect.boundPort(containerPort); portErr == nil {
				status, requestErr := applicationHTTPHealth(ctx, healthClient, port, healthPath)

				if requestErr == nil && status >= 200 && status < 400 {
					return "healthy", c.applicationLogsBestEffort(ctx, containerID, 200), nil
				}
			}
		} else if inspect.State.Health != nil {
			switch inspect.State.Health.Status {
			case "healthy":
				return "healthy", c.applicationLogsBestEffort(ctx, containerID, 200), nil
			case "unhealthy":
				return "unhealthy", c.applicationLogsBestEffort(ctx, containerID, 200),
					errors.New("Docker reported the application container as unhealthy")
			}
		} else {
			return "running", c.applicationLogsBestEffort(ctx, containerID, 200), nil
		}

		if time.Now().After(deadline) {
			return "unhealthy", c.applicationLogsBestEffort(ctx, containerID, 200),
				errors.New("the application did not become healthy within 60 seconds")
		}

		select {
		case <-ctx.Done():
			return "unknown", c.applicationLogsBestEffort(ctx, containerID, 200), ctx.Err()
		case <-time.After(2 * time.Second):
		}
	}
}

func applicationHTTPHealth(ctx context.Context, client *http.Client, port int, path string) (int, error) {
	request, err := http.NewRequestWithContext(
		ctx,
		http.MethodGet,
		fmt.Sprintf("http://127.0.0.1:%d%s", port, path),
		nil,
	)
	if err != nil {
		return 0, err
	}

	response, err := client.Do(request)
	if err != nil {
		return 0, err
	}

	defer func() {
		_ = response.Body.Close()
	}()

	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 4<<10))

	return response.StatusCode, nil
}

func (c *Client) applicationContainer(
	ctx context.Context,
	applicationID string,
	containerRef string,
) (containerInspect, bool, error) {
	if !uuidPattern.MatchString(applicationID) {
		return containerInspect{}, false, errors.New("invalid application ID")
	}

	if !validContainerRef(containerRef) {
		return containerInspect{}, false, errors.New("invalid application container reference")
	}

	inspect, err := c.inspectContainer(ctx, containerRef)
	if errors.Is(err, ErrNotFound) {
		return containerInspect{}, false, nil
	}

	if err != nil {
		return containerInspect{}, false, err
	}

	if inspect.Config.Labels["cloudforge.managed"] != "true" ||
		inspect.Config.Labels["cloudforge.application_id"] != applicationID ||
		inspect.Config.Labels["cloudforge.resource_type"] != "application" {
		return containerInspect{}, false, fmt.Errorf(
			"container %q is not managed by application %s",
			containerRef,
			applicationID,
		)
	}

	return inspect, true, nil
}

func (c *Client) StartApplication(ctx context.Context, applicationID string, containerRef string) error {
	inspect, found, err := c.applicationContainer(ctx, applicationID, containerRef)
	if err != nil {
		return err
	}

	if !found {
		return errors.New("the application container does not exist")
	}

	if inspect.State.Running {
		return nil
	}

	return c.startContainer(ctx, inspect.ID)
}

func (c *Client) StopApplication(ctx context.Context, applicationID string, containerRef string) error {
	inspect, found, err := c.applicationContainer(ctx, applicationID, containerRef)
	if errors.Is(err, ErrNotFound) {
		return nil
	}

	if err != nil {
		return err
	}

	if !found || !inspect.State.Running {
		return nil
	}

	return c.request(
		ctx,
		http.MethodPost,
		"/containers/"+url.PathEscape(inspect.ID)+"/stop?t=10",
		nil,
		nil,
	)
}

// Delete removes every container labelled for this application, including
// orphaned containers from an earlier failed deployment.
func (c *Client) DeleteApplication(ctx context.Context, applicationID string, containerRef string) error {
	if !uuidPattern.MatchString(applicationID) {
		return errors.New("invalid application ID")
	}

	if containerRef != "" && !validContainerRef(containerRef) {
		return errors.New("invalid application container reference")
	}

	var containers []containerListEntry

	filters := url.QueryEscape(fmt.Sprintf(`{"label":["cloudforge.application_id=%s"]}`, applicationID))

	if err := c.get(ctx, "/containers/json?all=1&filters="+filters, &containers); err != nil {
		return err
	}

	for _, container := range containers {
		if container.Labels["cloudforge.application_id"] != applicationID {
			continue
		}

		if err := c.removeContainer(ctx, container.ID); err != nil && !errors.Is(err, ErrNotFound) {
			return err
		}
	}

	return nil
}

func (c *Client) InspectApplication(
	ctx context.Context,
	applicationID string,
	containerRef string,
	containerPort int,
) (applications.State, error) {
	inspect, found, err := c.applicationContainer(ctx, applicationID, containerRef)
	if errors.Is(err, ErrNotFound) {
		return applications.State{Status: "missing"}, nil
	}

	if err != nil {
		return applications.State{}, err
	}

	if !found {
		return applications.State{Status: "missing"}, nil
	}

	state := applications.State{
		Status:      "stopped",
		ContainerID: inspect.ID,
		Health:      "unknown",
		Image:       inspect.Config.Image,
	}

	if inspect.State.Running {
		state.Status = "running"
	}

	if inspect.State.Health != nil {
		state.Health = inspect.State.Health.Status
	}

	if port, portErr := inspect.boundPort(containerPort); portErr == nil {
		state.Port = port
	}

	return state, nil
}

func (c *Client) ApplicationLogs(
	ctx context.Context,
	applicationID string,
	containerRef string,
	tail int,
) (string, error) {
	inspect, found, err := c.applicationContainer(ctx, applicationID, containerRef)
	if err != nil {
		return "", err
	}

	if !found {
		return "", errors.New("the application container does not exist")
	}

	return c.containerLogs(ctx, inspect.ID, tail)
}

func (c *Client) applicationLogsBestEffort(ctx context.Context, containerRef string, tail int) string {
	logs, err := c.containerLogs(ctx, containerRef, tail)
	if err != nil {
		return ""
	}

	return logs
}

func (c *Client) containerLogs(ctx context.Context, containerRef string, tail int) (string, error) {
	if !validContainerRef(containerRef) {
		return "", errors.New("invalid container reference")
	}

	if tail < 1 {
		tail = 200
	}

	if tail > 500 {
		tail = 500
	}

	path := "/containers/" + url.PathEscape(containerRef) +
		"/logs?stdout=1&stderr=1&timestamps=0&tail=" + strconv.Itoa(tail)

	request, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker"+path, nil)
	if err != nil {
		return "", fmt.Errorf("build Docker logs request: %w", err)
	}

	response, err := c.httpClient.Do(request)
	if err != nil {
		return "", fmt.Errorf("read Docker logs: %w", err)
	}

	defer func() {
		_ = response.Body.Close()
	}()

	if response.StatusCode == http.StatusNotFound {
		return "", ErrNotFound
	}

	if response.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxResponseBytes))

		return "", fmt.Errorf("Docker logs returned HTTP %d", response.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(response.Body, (applicationLogLimitBytes+4096)*4))
	if err != nil {
		return "", fmt.Errorf("read Docker logs response: %w", err)
	}

	return boundLogs(decodeDockerLogStream(raw), tail), nil
}

func decodeDockerLogStream(raw []byte) string {
	var out bytes.Buffer

	for len(raw) >= 8 {
		stream := raw[0]

		if stream > 2 || raw[1] != 0 || raw[2] != 0 || raw[3] != 0 {
			break
		}

		size := int(binary.BigEndian.Uint32(raw[4:8]))
		if size < 0 || 8+size > len(raw) {
			break
		}

		if stream == 1 || stream == 2 {
			out.Write(raw[8 : 8+size])
		}

		raw = raw[8+size:]
	}

	if out.Len() == 0 {
		return string(raw)
	}

	if len(raw) > 0 {
		out.Write(raw)
	}

	return out.String()
}

func boundLogs(text string, tail int) string {
	text = strings.ReplaceAll(text, "\r\n", "\n")
	lines := strings.Split(text, "\n")

	if len(lines) > tail {
		lines = lines[len(lines)-tail:]
	}

	result := strings.TrimRight(strings.Join(lines, "\n"), "\n")

	if len(result) > applicationLogLimitBytes {
		start := len(result) - applicationLogLimitBytes

		for start < len(result) && !utf8.RuneStart(result[start]) {
			start++
		}

		result = result[start:]
	}

	return result
}

type containerListEntry struct {
	ID     string            `json:"Id"`
	Names  []string          `json:"Names"`
	Labels map[string]string `json:"Labels"`
	State  string            `json:"State"`
}
