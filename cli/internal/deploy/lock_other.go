//go:build !linux

package deploy

import (
	"fmt"
	"os"
)

func acquireDeployLock(lockPath string) (*os.File, error) {
	f, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return nil, fmt.Errorf("open lock file %s: %w", lockPath, err)
	}
	return f, nil
}

func releaseDeployLock(f *os.File) {
	if f == nil {
		return
	}
	_ = f.Close()
}
