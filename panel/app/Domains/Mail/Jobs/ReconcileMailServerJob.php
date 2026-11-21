<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileMailServerJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public int $uniqueFor = 30;

    public function __construct(
        public readonly string $scope = 'all',
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'mail_reconcile_'.$this->scope;
    }

    public function handle(MailServerDriverInterface $mailDriver): void
    {
        $mailDriver->reconcile($this->scope);
    }
}
