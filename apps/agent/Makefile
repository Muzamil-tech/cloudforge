GO ?= go
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo 1.0.0-dev)
DIST_DIR ?= dist

.PHONY: all build test vet fmt tidy package clean

all: fmt vet test build

build:
	$(GO) build -trimpath -ldflags "-s -w -X main.version=$(VERSION)" -o bin/cloudforge-agent ./cmd/cloudforge-agent

test:
	$(GO) test ./...

vet:
	$(GO) vet ./...

fmt:
	$(GO) fmt ./...

tidy:
	$(GO) mod tidy

package:
	VERSION="$(VERSION)" DIST_DIR="$(DIST_DIR)" sh packaging/package.sh

clean:
	rm -rf bin $(DIST_DIR)
