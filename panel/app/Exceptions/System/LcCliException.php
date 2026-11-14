<?php

namespace App\Exceptions\System;

use Exception;

class LcCliException extends Exception
{
    protected string $command;

    protected array $args;

    protected ?array $response;

    public function __construct(string $message, string $command = '', array $args = [], ?array $response = null, int $code = 0, ?\Throwable $previous = null)
    {
        $this->command = $command;
        $this->args = $args;
        $this->response = $response;

        $formattedMessage = $message;
        if (! empty($command)) {
            $formattedMessage = "[lc-cli {$command}] ".$message;
        }

        parent::__construct($formattedMessage, $code, $previous);
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }
}
