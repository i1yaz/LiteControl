//go:build !linux

package mail

import (
	"fmt"
	"os"
	"path/filepath"
)

func acquireReconcileLock(lockPath string) (*os.File, error) {
	if err := os.MkdirAll(filepath.Dir(lockPath), 0o755); err != nil {
		return nil, fmt.Errorf("mkdir lock dir: %w", err)
	}

	f, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return nil, fmt.Errorf("open reconcile lock %s: %w", lockPath, err)
	}
	return f, nil
}

func releaseReconcileLock(f *os.File) {
	if f == nil {
		return
	}
	_ = f.Close()
}
