package deploy

import (
	"context"
	"crypto/tls"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// CheckHealth performs an HTTP GET against 127.0.0.1 with Host: <domain>
// and returns nil if a non-5xx response is received within the configured retries.
func CheckHealth(ctx context.Context, domain string, cfg HealthCheckConfig) error {
	return checkHealthWithBaseURL(ctx, "http://127.0.0.1", domain, cfg)
}

func checkHealthWithBaseURL(ctx context.Context, baseURL string, domain string, cfg HealthCheckConfig) error {
	if !cfg.Enabled {
		return nil
	}

	timeout := time.Duration(cfg.TimeoutSeconds) * time.Second
	if timeout <= 0 {
		timeout = 5 * time.Second
	}

	retries := cfg.Retries
	if retries < 1 {
		retries = 1
	}

	path := cfg.Path
	if path == "" {
		path = "/"
	}
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}

	client := &http.Client{
		Timeout: timeout,
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
		},
	}

	targetURL := strings.TrimRight(baseURL, "/") + path
	var lastErr error

	for attempt := 1; attempt <= retries; attempt++ {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, targetURL, nil)
		if err != nil {
			return fmt.Errorf("build health check request: %w", err)
		}
		req.Host = domain

		resp, err := client.Do(req)
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode < 500 {
				return nil
			}
			lastErr = fmt.Errorf("health check returned HTTP %d", resp.StatusCode)
		} else {
			lastErr = fmt.Errorf("health check request failed: %w", err)
		}

		if attempt < retries {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(500 * time.Millisecond):
			}
		}
	}

	return lastErr
}
