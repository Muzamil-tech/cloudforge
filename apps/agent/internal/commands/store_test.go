package commands

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestStorePersistsOutcomesAcrossReopen(t *testing.T) {
	t.Parallel()

	path := filepath.Join(t.TempDir(), "commands.json")

	store, err := OpenStore(path)
	if err != nil {
		t.Fatalf("open store: %v", err)
	}

	outcome := Outcome{
		CommandID:   "cmd-1",
		Operation:   OperationServerHealth,
		Status:      OutcomeCompleted,
		Result:      map[string]any{"agent": "ok"},
		CompletedAt: time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC),
	}

	if err := store.Put(outcome); err != nil {
		t.Fatalf("put: %v", err)
	}

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat state file: %v", err)
	}

	if info.Size() == 0 {
		t.Fatal("expected the state file to contain data")
	}

	reopened, err := OpenStore(path)
	if err != nil {
		t.Fatalf("reopen store: %v", err)
	}

	stored, ok := reopened.Get("cmd-1")
	if !ok {
		t.Fatal("expected the outcome to survive a restart")
	}

	if stored.Operation != OperationServerHealth || stored.Status != OutcomeCompleted {
		t.Fatalf("unexpected outcome %#v", stored)
	}

	if stored.Result["agent"] != "ok" {
		t.Fatalf("unexpected result %#v", stored.Result)
	}
}

func TestStoreRejectsCorruptOrUnknownState(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()

	corrupt := filepath.Join(dir, "corrupt.json")
	if err := os.WriteFile(corrupt, []byte("{not json"), 0o600); err != nil {
		t.Fatalf("write corrupt state: %v", err)
	}

	if _, err := OpenStore(corrupt); err == nil {
		t.Fatal("expected corrupt state to be rejected")
	}

	future := filepath.Join(dir, "future.json")
	if err := os.WriteFile(future, []byte(`{"version":99,"order":[],"entries":{}}`), 0o600); err != nil {
		t.Fatalf("write future state: %v", err)
	}

	if _, err := OpenStore(future); err == nil {
		t.Fatal("expected an unsupported version to be rejected")
	}
}

func TestStoreEvictsOldestOutcomes(t *testing.T) {
	t.Parallel()

	store, err := OpenStore(filepath.Join(t.TempDir(), "commands.json"))
	if err != nil {
		t.Fatalf("open store: %v", err)
	}

	store.limit = 2

	for _, id := range []string{"cmd-1", "cmd-2", "cmd-3"} {
		if err := store.Put(Outcome{CommandID: id, Status: OutcomeCompleted}); err != nil {
			t.Fatalf("put %s: %v", id, err)
		}
	}

	if _, ok := store.Get("cmd-1"); ok {
		t.Fatal("expected the oldest outcome to be evicted")
	}

	for _, id := range []string{"cmd-2", "cmd-3"} {
		if _, ok := store.Get(id); !ok {
			t.Fatalf("expected %s to be retained", id)
		}
	}
}

func TestStoreDeleteRemovesAProvisionalOutcome(t *testing.T) {
	t.Parallel()

	store, err := OpenStore(filepath.Join(t.TempDir(), "commands.json"))
	if err != nil {
		t.Fatalf("open store: %v", err)
	}

	if err := store.Put(Outcome{CommandID: "cmd-1", Status: OutcomeExecuting}); err != nil {
		t.Fatalf("put: %v", err)
	}

	store.Delete("cmd-1")
	store.Delete("cmd-1")

	if _, ok := store.Get("cmd-1"); ok {
		t.Fatal("expected the outcome to be deleted")
	}

	reopened, err := OpenStore(filepath.Join(t.TempDir(), "commands.json"))
	if err != nil {
		t.Fatalf("open empty store: %v", err)
	}

	if _, ok := reopened.Get("cmd-1"); ok {
		t.Fatal("deleted outcomes must not reappear")
	}
}

func TestStoreToleratesEntriesWithoutAnOrderList(t *testing.T) {
	t.Parallel()

	path := filepath.Join(t.TempDir(), "commands.json")
	contents := `{"version":1,"entries":{"cmd-1":{"command_id":"cmd-1","status":"completed"}}}`

	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatalf("write state: %v", err)
	}

	store, err := OpenStore(path)
	if err != nil {
		t.Fatalf("open store: %v", err)
	}

	if _, ok := store.Get("cmd-1"); !ok {
		t.Fatal("expected legacy entries to be retained")
	}
}
