package deploy

import (
	"encoding/json"
	"io"
	"time"
)

// ExecutionLogEntry is a structured JSON log record emitted for deployment stages and hooks.
type ExecutionLogEntry struct {
	Timestamp    string `json:"timestamp"`
	Event        string `json:"event"`
	Status       string `json:"status"`
	SiteID       int64  `json:"site_id"`
	DeploymentID int64  `json:"deployment_id"`
	Stage        string `json:"stage"`
	Slot         string `json:"slot"`
	HookID       int64  `json:"hook_id"`
	HookName     string `json:"hook_name"`
	User         string `json:"user,omitempty"`
	Cwd          string `json:"cwd"`
	ExitCode     int    `json:"exit_code"`
	DurationMs   int64  `json:"duration_ms"`
	Error        string `json:"error,omitempty"`
}

// EmitStructuredLog writes entry as a single JSON line to w.
func EmitStructuredLog(w io.Writer, entry ExecutionLogEntry) {
	if w == nil {
		return
	}

	if entry.Timestamp == "" {
		entry.Timestamp = time.Now().UTC().Format(time.RFC3339Nano)
	}

	encoded, err := json.Marshal(entry)
	if err != nil {
		return
	}

	_, _ = w.Write(append(encoded, '\n'))
}
