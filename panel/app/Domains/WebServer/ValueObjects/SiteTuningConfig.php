<?php

namespace App\Domains\WebServer\ValueObjects;

use InvalidArgumentException;

final readonly class SiteTuningConfig
{
    public function __construct(
        public int $maxConns,
        public int $initTimeout,
        public bool $persistConn,
        public int $pcKeepAliveTimeout,
        public int $memSoftLimitMb,
        public int $memHardLimitMb,
        public int $procSoftLimit,
        public int $procHardLimit,
        public int $phpMemoryLimitMb,
        public int $phpMaxExecutionTime,
        public int $phpMaxInputTime,
        public int $phpUploadMaxFilesizeMb,
        public int $phpPostMaxSizeMb,
        public int $phpMaxInputVars,
        public bool $phpDisplayErrors,
    ) {
        $this->validate();
    }

    public static function defaults(): self
    {
        return new self(
            maxConns: 10,
            initTimeout: 60,
            persistConn: true,
            pcKeepAliveTimeout: 5,
            memSoftLimitMb: 512,
            memHardLimitMb: 1024,
            procSoftLimit: 100,
            procHardLimit: 200,
            phpMemoryLimitMb: 256,
            phpMaxExecutionTime: 60,
            phpMaxInputTime: 60,
            phpUploadMaxFilesizeMb: 64,
            phpPostMaxSizeMb: 64,
            phpMaxInputVars: 3000,
            phpDisplayErrors: false,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            maxConns: (int) ($data['max_conns'] ?? 10),
            initTimeout: (int) ($data['init_timeout'] ?? 60),
            persistConn: (bool) ($data['persist_conn'] ?? true),
            pcKeepAliveTimeout: (int) ($data['pc_keep_alive_timeout'] ?? 5),
            memSoftLimitMb: (int) ($data['mem_soft_limit_mb'] ?? 512),
            memHardLimitMb: (int) ($data['mem_hard_limit_mb'] ?? 1024),
            procSoftLimit: (int) ($data['proc_soft_limit'] ?? 100),
            procHardLimit: (int) ($data['proc_hard_limit'] ?? 200),
            phpMemoryLimitMb: (int) ($data['php_memory_limit_mb'] ?? 256),
            phpMaxExecutionTime: (int) ($data['php_max_execution_time'] ?? 60),
            phpMaxInputTime: (int) ($data['php_max_input_time'] ?? 60),
            phpUploadMaxFilesizeMb: (int) ($data['php_upload_max_filesize_mb'] ?? 64),
            phpPostMaxSizeMb: (int) ($data['php_post_max_size_mb'] ?? 64),
            phpMaxInputVars: (int) ($data['php_max_input_vars'] ?? 3000),
            phpDisplayErrors: (bool) ($data['php_display_errors'] ?? false),
        );
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'max_conns' => $this->maxConns,
            'init_timeout' => $this->initTimeout,
            'persist_conn' => $this->persistConn,
            'pc_keep_alive_timeout' => $this->pcKeepAliveTimeout,
            'mem_soft_limit_mb' => $this->memSoftLimitMb,
            'mem_hard_limit_mb' => $this->memHardLimitMb,
            'proc_soft_limit' => $this->procSoftLimit,
            'proc_hard_limit' => $this->procHardLimit,
            'php_memory_limit_mb' => $this->phpMemoryLimitMb,
            'php_max_execution_time' => $this->phpMaxExecutionTime,
            'php_max_input_time' => $this->phpMaxInputTime,
            'php_upload_max_filesize_mb' => $this->phpUploadMaxFilesizeMb,
            'php_post_max_size_mb' => $this->phpPostMaxSizeMb,
            'php_max_input_vars' => $this->phpMaxInputVars,
            'php_display_errors' => $this->phpDisplayErrors,
        ];
    }

    public function renderOlsFragment(string $unixUser, string $phpVersionClean): string
    {
        $persist = $this->persistConn ? '1' : '0';
        $displayErrors = $this->phpDisplayErrors ? 'On' : 'Off';

        return <<<CONF
extprocessor {$unixUser} {
  type                    lsapi
  address                 UDS://tmp/lshttpd/{$unixUser}.sock
  maxConns                {$this->maxConns}
  env                     LSAPI_CHILDREN={$this->maxConns}
  initTimeout             {$this->initTimeout}
  retryTimeout            0
  persistConn             {$persist}
  pcKeepAliveTimeout      {$this->pcKeepAliveTimeout}
  respBuffer              0
  autoStart               1
  path                    /usr/local/lsws/lsphp{$phpVersionClean}/bin/lsphp
  extUser                 {$unixUser}
  extGroup                {$unixUser}
  memSoftLimit            {$this->memSoftLimitMb}M
  memHardLimit            {$this->memHardLimitMb}M
  procSoftLimit           {$this->procSoftLimit}
  procHardLimit           {$this->procHardLimit}
}

phpIniOverride  {
  php_admin_value memory_limit {$this->phpMemoryLimitMb}M
  php_admin_value max_execution_time {$this->phpMaxExecutionTime}
  php_admin_value max_input_time {$this->phpMaxInputTime}
  php_admin_value upload_max_filesize {$this->phpUploadMaxFilesizeMb}M
  php_admin_value post_max_size {$this->phpPostMaxSizeMb}M
  php_admin_value max_input_vars {$this->phpMaxInputVars}
  php_admin_flag display_errors {$displayErrors}
}
CONF;
    }

    private function validate(): void
    {
        if ($this->maxConns < 1 || $this->maxConns > 500) {
            throw new InvalidArgumentException('max_conns must be between 1 and 500.');
        }

        if ($this->initTimeout < 5 || $this->initTimeout > 3600) {
            throw new InvalidArgumentException('init_timeout must be between 5 and 3600 seconds.');
        }

        if ($this->pcKeepAliveTimeout < 0 || $this->pcKeepAliveTimeout > 3600) {
            throw new InvalidArgumentException('pc_keep_alive_timeout must be between 0 and 3600 seconds.');
        }

        if ($this->memSoftLimitMb < 64 || $this->memSoftLimitMb > 65536) {
            throw new InvalidArgumentException('mem_soft_limit_mb must be between 64 and 65536.');
        }

        if ($this->memHardLimitMb < $this->memSoftLimitMb || $this->memHardLimitMb > 65536) {
            throw new InvalidArgumentException('mem_hard_limit_mb must be >= mem_soft_limit_mb and <= 65536.');
        }

        if ($this->procSoftLimit < 10 || $this->procSoftLimit > 4096) {
            throw new InvalidArgumentException('proc_soft_limit must be between 10 and 4096.');
        }

        if ($this->procHardLimit < $this->procSoftLimit || $this->procHardLimit > 4096) {
            throw new InvalidArgumentException('proc_hard_limit must be >= proc_soft_limit and <= 4096.');
        }

        if ($this->phpMemoryLimitMb < 32 || $this->phpMemoryLimitMb > 32768) {
            throw new InvalidArgumentException('php_memory_limit_mb must be between 32 and 32768.');
        }

        if ($this->memHardLimitMb < $this->phpMemoryLimitMb + 64) {
            throw new InvalidArgumentException('mem_hard_limit_mb must be >= php_memory_limit_mb + 64 MB.');
        }

        if ($this->phpPostMaxSizeMb < $this->phpUploadMaxFilesizeMb) {
            throw new InvalidArgumentException('php_post_max_size_mb must be >= php_upload_max_filesize_mb.');
        }

        if ($this->phpMaxInputVars < 100 || $this->phpMaxInputVars > 100000) {
            throw new InvalidArgumentException('php_max_input_vars must be between 100 and 100000.');
        }
    }
}
