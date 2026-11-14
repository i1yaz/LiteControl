<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\ValueObjects\LinuxUsername;
use Illuminate\Support\Facades\File;

class ResolveUserBaseDirAction
{
    public function handle(User $actor, ?User $targetOwner = null): string
    {
        if ($targetOwner && ($actor->isAdmin() || (int) $targetOwner->id === (int) $actor->id)) {
            return $this->resolveForUsername(new LinuxUsername($targetOwner->username), seedIndex: false);
        }

        return $this->resolveForUsername(new LinuxUsername($actor->username), seedIndex: true);
    }

    private function resolveForUsername(LinuxUsername $username, bool $seedIndex): string
    {
        $prodPath = "/home/{$username->value}";

        if (PHP_OS_FAMILY === 'Linux' && file_exists($prodPath)) {
            return $prodPath;
        }

        $sandbox = storage_path("app/homes/{$username->value}");
        if (! file_exists($sandbox)) {
            File::makeDirectory($sandbox.'/public_html', 0755, true, true);
            if ($seedIndex) {
                file_put_contents($sandbox.'/public_html/index.php', "<?php echo 'Welcome to LiteControl!'; ?>");
            }
        }

        return realpath($sandbox) ?: $sandbox;
    }
}
