<?php

namespace App\Domains\FileManager\Queries;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class ResolveUserBaseDirQuery
{
    public function handle(User $actor, ?User $targetOwner = null): string
    {
        if ($targetOwner && ($actor->isAdmin() || (int) $targetOwner->id === (int) $actor->id)) {
            return $this->resolveForUsername($this->sanitizeUsername($targetOwner->username), seedIndex: false);
        }

        return $this->resolveForUsername($this->sanitizeUsername($actor->username), seedIndex: true);
    }

    private function sanitizeUsername(string $username): string
    {
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $username) || $username === '.' || $username === '..') {
            throw new InvalidArgumentException("Invalid username '{$username}'.");
        }

        return $username;
    }

    private function resolveForUsername(string $username, bool $seedIndex): string
    {
        $prodPath = "/home/{$username}";

        if (PHP_OS_FAMILY === 'Linux' && file_exists($prodPath)) {
            return $prodPath;
        }

        $sandbox = storage_path("app/homes/{$username}");
        if (! file_exists($sandbox)) {
            File::makeDirectory($sandbox.'/public_html', 0755, true, true);
            if ($seedIndex) {
                file_put_contents($sandbox.'/public_html/index.php', "<?php echo 'Welcome to LiteControl!'; ?>");
            }
        }

        return realpath($sandbox) ?: $sandbox;
    }
}
