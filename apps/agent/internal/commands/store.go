// Package commands executes the Phase 1 agent operation allowlist and keeps a
// durable record of command outcomes so a redelivered command is never executed
// twice.
package commands

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

const (
	// OutcomeExecuting marks a command accepted for execution. If the agent
	// stops before recording a terminal outcome, the command is failed on
	// redelivery instead of being run a second time.
	OutcomeExecuting = "executing"
	OutcomeCompleted = "completed"
	OutcomeFailed    = "failed"

	storeVersion   = 1
	defaultHistory = 1000
)

// Outcome is the durable record of one command.
type Outcome struct {
	CommandID    string         `json:"command_id"`
	Operation    string         `json:"operation"`
	Status       string         `json:"status"`
	Result       map[string]any `json:"result,omitempty"`
	ErrorMessage string         `json:"error_message,omitempty"`
	DurationMS   int64          `json:"duration_ms,omitempty"`
	CompletedAt  time.Time      `json:"completed_at"`
}

type diskState struct {
	Version int                `json:"version"`
	Order   []string           `json:"order"`
	Entries map[string]Outcome `json:"entries"`
}

// Store persists command outcomes in a single JSON file. Writes are atomic and
// the in-memory view is updated before the file so a successful execution is
// never forgotten because of a transient write failure.
type Store struct {
	mu      sync.Mutex
	path    string
	limit   int
	entries map[string]Outcome
	order   []string
}

// OpenStore loads or creates the command state file.
func OpenStore(path string) (*Store, error) {
	store := &Store{
		path:    path,
		limit:   defaultHistory,
		entries: map[string]Outcome{},
	}

	contents, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return store, nil
	}

	if err != nil {
		return nil, fmt.Errorf("read command state: %w", err)
	}

	var state diskState

	if err := json.Unmarshal(contents, &state); err != nil {
		return nil, fmt.Errorf("command state %s is corrupt: %w", path, err)
	}

	if state.Version != storeVersion {
		return nil, fmt.Errorf("command state %s uses unsupported version %d", path, state.Version)
	}

	if state.Entries != nil {
		store.entries = state.Entries
	}

	for _, id := range state.Order {
		if _, exists := store.entries[id]; exists {
			store.order = append(store.order, id)
		}
	}

	// Entries written by an older build without an order list are retained and
	// appended in a stable order.
	for id := range store.entries {
		if !contains(store.order, id) {
			store.order = append(store.order, id)
		}
	}

	return store, nil
}

// Get returns a stored outcome.
func (s *Store) Get(commandID string) (Outcome, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	outcome, ok := s.entries[commandID]

	return outcome, ok
}

// Put records an outcome and writes the state file.
func (s *Store) Put(outcome Outcome) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if _, exists := s.entries[outcome.CommandID]; !exists {
		s.order = append(s.order, outcome.CommandID)
	}

	s.entries[outcome.CommandID] = outcome

	for len(s.order) > s.limit {
		oldest := s.order[0]
		s.order = s.order[1:]
		delete(s.entries, oldest)
	}

	return s.persistLocked()
}

// Delete removes a provisional outcome. It is used when a command could not be
// acknowledged and therefore was never executed.
func (s *Store) Delete(commandID string) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if _, exists := s.entries[commandID]; !exists {
		return
	}

	delete(s.entries, commandID)

	for index, id := range s.order {
		if id == commandID {
			s.order = append(s.order[:index], s.order[index+1:]...)
			break
		}
	}
}

func (s *Store) persistLocked() error {
	if err := os.MkdirAll(filepath.Dir(s.path), 0o750); err != nil {
		return fmt.Errorf("create command state directory: %w", err)
	}

	contents, err := json.MarshalIndent(diskState{
		Version: storeVersion,
		Order:   s.order,
		Entries: s.entries,
	}, "", "  ")
	if err != nil {
		return fmt.Errorf("encode command state: %w", err)
	}

	temporary := s.path + ".tmp"

	if err := os.WriteFile(temporary, contents, 0o600); err != nil {
		return fmt.Errorf("write command state: %w", err)
	}

	if err := os.Rename(temporary, s.path); err != nil {
		_ = os.Remove(temporary)

		return fmt.Errorf("publish command state: %w", err)
	}

	return nil
}

func contains(values []string, target string) bool {
	for _, value := range values {
		if value == target {
			return true
		}
	}

	return false
}
