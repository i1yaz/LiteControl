package deploy

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

func TestCheckHealthWithBaseURL_Disabled(t *testing.T) {
	err := checkHealthWithBaseURL(context.Background(), "http://127.0.0.1:0", "example.com", HealthCheckConfig{
		Enabled: false,
	})
	if err != nil {
		t.Fatalf("expected nil when disabled, got %v", err)
	}
}

func TestCheckHealthWithBaseURL_SucceedsAfterRetry(t *testing.T) {
	var attempts atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Host != "example.com" {
			t.Errorf("expected Host=example.com, got %q", r.Host)
		}
		if r.URL.Path != "/health" {
			t.Errorf("expected Path=/health, got %q", r.URL.Path)
		}
		if attempts.Add(1) == 1 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	err := checkHealthWithBaseURL(context.Background(), srv.URL, "example.com", HealthCheckConfig{
		Enabled:        true,
		Path:           "/health",
		TimeoutSeconds: 2,
		Retries:        2,
	})
	if err != nil {
		t.Fatalf("expected health check to succeed on retry, got %v", err)
	}
	if attempts.Load() != 2 {
		t.Fatalf("expected 2 attempts, got %d", attempts.Load())
	}
}

func TestCheckHealthWithBaseURL_FailsOn5xx(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	err := checkHealthWithBaseURL(context.Background(), srv.URL, "example.com", HealthCheckConfig{
		Enabled:        true,
		Path:           "/",
		TimeoutSeconds: 1,
		Retries:        1,
	})
	if err == nil {
		t.Fatal("expected error on HTTP 500, got nil")
	}
}
