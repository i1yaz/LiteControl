<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileManagerController extends Controller
{
    /**
     * Get the resolved, sanitized base directory for the authenticated user.
     */
    protected function getUserBaseDir(): string
    {
        $user = Auth::user();

        // In production on Ubuntu, user's home is /home/<username>
        $prodPath = "/home/{$user->username}";

        if (PHP_OS_FAMILY === 'Linux' && file_exists($prodPath)) {
            return $prodPath;
        }

        // Development/fallback sandboxed home directory inside storage/app/homes/<username>
        $sandbox = storage_path("app/homes/{$user->username}");
        if (! file_exists($sandbox)) {
            File::makeDirectory($sandbox . '/public_html', 0755, true, true);
            file_put_contents($sandbox . '/public_html/index.php', "<?php echo 'Welcome to LiteControl!'; ?>");
        }

        return realpath($sandbox) ?: $sandbox;
    }

    /**
     * Resolve and validate a relative path within the user's home jail.
     * Prevents directory traversal attacks (e.g. ../../../etc/passwd).
     */
    protected function resolveJailedPath(?string $subpath = ''): ?string
    {
        $subpath = $subpath ?? '';
        $baseDir = $this->getUserBaseDir();
        $trimmed = trim(str_replace(['\\', '..'], ['/', ''], $subpath), '/');
        $fullPath = empty($trimmed) ? $baseDir : $baseDir . DIRECTORY_SEPARATOR . $trimmed;

        $realBase = realpath($baseDir) ?: $baseDir;

        // If path doesn't exist yet (e.g. creating new file), check parent
        if (! file_exists($fullPath)) {
            $parent = dirname($fullPath);
            $realParent = realpath($parent);
            if ($realParent && str_starts_with($realParent, $realBase)) {
                return $fullPath;
            }
            return null;
        }

        $realFull = realpath($fullPath);
        if ($realFull && str_starts_with($realFull, $realBase)) {
            return $realFull;
        }

        return null;
    }

    /**
     * Display the file manager view.
     */
    public function index(): Response
    {
        return Inertia::render('Files/Index', [
            'baseDir' => "/home/" . Auth::user()->username,
        ]);
    }

    /**
     * Return directory listing as JSON.
     */
    public function list(Request $request)
    {
        $path = $request->input('path', '');
        $fullPath = $this->resolveJailedPath($path);

        if (! $fullPath || ! is_dir($fullPath)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid directory or access denied.',
            ], 403);
        }

        $items = [];
        $files = scandir($fullPath);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $fullPath . DIRECTORY_SEPARATOR . $file;
            $isDir = is_dir($filePath);
            $size = $isDir ? 0 : (@filesize($filePath) ?: 0);
            $perms = substr(sprintf('%o', @fileperms($filePath) ?: 0), -4);
            $mtime = @filemtime($filePath) ? date('Y-m-d H:i:s', filemtime($filePath)) : '';

            $items[] = [
                'name' => $file,
                'path' => trim($path . '/' . $file, '/'),
                'is_dir' => $isDir,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'permissions' => $perms,
                'modified_at' => $mtime,
                'extension' => $isDir ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            ];
        }

        // Sort dirs first, then files
        usort($items, function ($a, $b) {
            if ($a['is_dir'] === $b['is_dir']) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $a['is_dir'] ? -1 : 1;
        });

        return response()->json([
            'status' => 'success',
            'current_path' => trim($path, '/'),
            'items' => $items,
        ]);
    }

    /**
     * Read content of a file for the code editor.
     */
    public function content(Request $request)
    {
        $path = $request->input('path');
        $fullPath = $this->resolveJailedPath($path);

        if (! $fullPath || ! is_file($fullPath)) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 404);
        }

        if (filesize($fullPath) > 5 * 1024 * 1024) {
            return response()->json(['status' => 'error', 'message' => 'File is too large to open in web editor (> 5MB).'], 422);
        }

        $content = file_get_contents($fullPath);

        return response()->json([
            'status' => 'success',
            'path' => $path,
            'content' => $content,
            'size' => filesize($fullPath),
        ]);
    }

    /**
     * Save edited file content.
     */
    public function save(Request $request)
    {
        $path = $request->input('path');
        $content = $request->input('content', '');
        $fullPath = $this->resolveJailedPath($path);

        if (! $fullPath || ! is_file($fullPath)) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 404);
        }

        file_put_contents($fullPath, $content);

        return response()->json(['status' => 'success', 'message' => 'File saved successfully.']);
    }

    /**
     * Create a new file or directory.
     */
    public function create(Request $request)
    {
        $path = $request->input('path', '');
        $name = $request->input('name');
        $type = $request->input('type', 'file'); // 'file' or 'folder'

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid name.'], 422);
        }

        $targetSubpath = trim($path . '/' . $name, '/');
        $fullPath = $this->resolveJailedPath($targetSubpath);

        if (! $fullPath) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        if (file_exists($fullPath)) {
            return response()->json(['status' => 'error', 'message' => 'Item already exists.'], 422);
        }

        if ($type === 'folder') {
            File::makeDirectory($fullPath, 0755, true);
        } else {
            file_put_contents($fullPath, '');
        }

        return response()->json(['status' => 'success', 'message' => ucfirst($type) . ' created.']);
    }

    /**
     * Delete a file or directory.
     */
    public function delete(Request $request)
    {
        $path = $request->input('path');
        $fullPath = $this->resolveJailedPath($path);

        if (! $fullPath || ! file_exists($fullPath)) {
            return response()->json(['status' => 'error', 'message' => 'Target not found.'], 404);
        }

        if (is_dir($fullPath)) {
            File::deleteDirectory($fullPath);
        } else {
            @unlink($fullPath);
        }

        return response()->json(['status' => 'success', 'message' => 'Item deleted.']);
    }

    /**
     * Upload files into current path.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'path' => ['nullable', 'string'],
            'files.*' => ['required', 'file', 'max:102400'], // 100MB max per file
        ]);

        $path = $request->input('path', '');
        $destDir = $this->resolveJailedPath($path);

        if (! $destDir || ! is_dir($destDir)) {
            return response()->json(['status' => 'error', 'message' => 'Target directory does not exist.'], 422);
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $uploadedFile) {
                $filename = $uploadedFile->getClientOriginalName();
                $uploadedFile->move($destDir, $filename);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Files uploaded successfully.']);
    }

    /**
     * Format byte values to human readable string.
     */
    protected function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
