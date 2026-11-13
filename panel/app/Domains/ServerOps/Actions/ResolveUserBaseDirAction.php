<?php

namespace App\Domains\ServerOps\Actions;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\File;

class ResolveUserBaseDirAction
{
    public function handle(User $actor, ?Domain $targetDomain = null): string
    {
        if ($targetDomain && ($actor->isAdmin() || (int) $targetDomain->user_id === (int) $actor->id)) {
            $owner = $targetDomain->user;
            return $this->resolveForUsername($owner->username, seedIndex: false);
        }

        return $this->resolveForUsername($actor->username, seedIndex: true);
    }

    private function resolveForUsername(string $username, bool $seedIndex): string
    {
        $prodPath = "/home/{$username}";

        if (PHP_OS_FAMILY === 'Linux' && file_exists($prodPath)) {
            return $prodPath;
        }

        $sandbox = storage_path("app/homes/{$username}");
        if (! file_exists($sandbox)) {
            File::makeDirectory($sandbox . '/public_html', 0755, true, true);
            if ($seedIndex) {
                file_put_contents($sandbox . '/public_html/index.php', "<?php echo 'Welcome to LiteControl!'; ?>");
            }
        }

        return realpath($sandbox) ?: $sandbox;
    }
}

