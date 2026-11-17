<?php

declare(strict_types=1);

namespace App\Domains\PhpRuntime\Queries;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;

class InstalledPhpVersionsQuery
{
    public function __construct(
        private PhpRuntimeDriverInterface $phpRuntime
    ) {}

    /**
     * @return array<int, string>
     */
    public function getInstalledVersionStrings(): array
    {
        $versions = $this->phpRuntime->listVersions();
        $result = [];

        foreach ($versions as $entry) {
            $raw = is_array($entry) ? (string) ($entry['version'] ?? '') : (string) $entry;
            $raw = trim($raw);

            if (preg_match('/^[5-9]\.[0-9]$/', $raw)) {
                $result[] = $raw;
            } elseif (preg_match('/^[5-9][0-9]$/', $raw)) {
                $result[] = $raw[0].'.'.$raw[1];
            }
        }

        return array_values(array_unique($result));
    }
}
