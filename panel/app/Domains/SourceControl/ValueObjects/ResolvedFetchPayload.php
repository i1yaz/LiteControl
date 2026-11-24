<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\ValueObjects;

use InvalidArgumentException;
use JsonException;

final readonly class ResolvedFetchPayload
{
    public const SCHEMA_VERSION = 1;

    public const MODE_TARBALL = 'tarball';

    public const MODE_SHALLOW_CLONE = 'shallow_clone';

    public function __construct(
        public string $mode,
        public string $commitSha,
        public ?string $branch = null,
        public ?string $tarballUrl = null,
        public ?string $authHeader = null,
        public ?string $cloneUrl = null,
        public ?string $httpsUsername = null,
        public ?string $httpsToken = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported fetch payload schema version: {$this->schemaVersion}.");
        }

        if (! in_array($this->mode, [self::MODE_TARBALL, self::MODE_SHALLOW_CLONE], true)) {
            throw new InvalidArgumentException("Unsupported fetch mode: {$this->mode}.");
        }

        if (trim($this->commitSha) === '') {
            throw new InvalidArgumentException('ResolvedFetchPayload requires a non-empty commit SHA.');
        }

        if ($this->mode === self::MODE_TARBALL) {
            if ($this->tarballUrl === null || trim($this->tarballUrl) === '') {
                throw new InvalidArgumentException('Tarball fetch payload requires a non-empty tarballUrl.');
            }
            if ($this->authHeader === null || trim($this->authHeader) === '') {
                throw new InvalidArgumentException('Tarball fetch payload requires a non-empty authHeader.');
            }
        }

        if ($this->mode === self::MODE_SHALLOW_CLONE) {
            if ($this->cloneUrl === null || trim($this->cloneUrl) === '') {
                throw new InvalidArgumentException('Shallow clone fetch payload requires a non-empty cloneUrl.');
            }
            if ($this->branch === null || trim($this->branch) === '') {
                throw new InvalidArgumentException('Shallow clone fetch payload requires a non-empty branch.');
            }
            if ($this->httpsUsername === null || trim($this->httpsUsername) === '') {
                throw new InvalidArgumentException('Shallow clone fetch payload requires a non-empty httpsUsername.');
            }
            if ($this->httpsToken === null || trim($this->httpsToken) === '') {
                throw new InvalidArgumentException('Shallow clone fetch payload requires a non-empty httpsToken.');
            }
        }
    }

    /**
     * Encode the versioned payload for piping to lc-cli over stdin.
     * Never log or persist the returned JSON string.
     *
     * @throws JsonException
     */
    public function toStdinJson(): string
    {
        $payload = [
            'schema_version' => $this->schemaVersion,
            'mode' => $this->mode,
            'commit_sha' => $this->commitSha,
        ];

        if ($this->branch !== null && $this->branch !== '') {
            $payload['branch'] = $this->branch;
        }

        if ($this->mode === self::MODE_TARBALL) {
            $payload['tarball_url'] = $this->tarballUrl;
            $payload['auth_header'] = $this->authHeader;
        } else {
            $payload['clone_url'] = $this->cloneUrl;
            $payload['https_username'] = $this->httpsUsername;
            $payload['https_token'] = $this->httpsToken;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Return sensitive credential substrings that must be scrubbed from logs/errors.
     *
     * @return list<string>
     */
    public function sensitiveValues(): array
    {
        $secrets = [];

        if ($this->authHeader !== null && $this->authHeader !== '') {
            $secrets[] = $this->authHeader;
            if (str_starts_with($this->authHeader, 'Bearer ')) {
                $rawToken = substr($this->authHeader, 7);
                if ($rawToken !== '') {
                    $secrets[] = $rawToken;
                }
            }
        }

        if ($this->httpsToken !== null && $this->httpsToken !== '') {
            $secrets[] = $this->httpsToken;
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Redact all sensitive credential values from an arbitrary text string.
     */
    public function redactText(string $text): string
    {
        foreach ($this->sensitiveValues() as $secret) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }

        return $text;
    }

    /**
     * Safe representation for structured logs and telemetry.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'mode' => $this->mode,
            'commit_sha' => $this->commitSha,
            'branch' => $this->branch,
            'tarball_url' => $this->tarballUrl,
            'auth_header' => $this->authHeader !== null ? '[REDACTED]' : null,
            'clone_url' => $this->cloneUrl,
            'https_username' => $this->httpsUsername,
            'https_token' => $this->httpsToken !== null ? '[REDACTED]' : null,
        ];
    }

    /**
     * Prevent var_dump(), dd(), or exception formatters from leaking tokens.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toRedactedArray();
    }
}
