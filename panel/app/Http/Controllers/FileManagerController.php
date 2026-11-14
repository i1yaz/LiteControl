<?php

namespace App\Http\Controllers;

use App\Domains\ServerOps\Actions\ChmodFileEntryAction;
use App\Domains\ServerOps\Actions\CreateFileEntryAction;
use App\Domains\ServerOps\Actions\DeleteFileEntryAction;
use App\Domains\ServerOps\Actions\ListDirectoryAction;
use App\Domains\ServerOps\Actions\ReadFileContentAction;
use App\Domains\ServerOps\Actions\ResolveUserBaseDirAction;
use App\Domains\ServerOps\Actions\SaveFileContentAction;
use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class FileManagerController extends Controller
{
    public function __construct(
        private readonly ResolveUserBaseDirAction $resolveUserBaseDir,
        private readonly ListDirectoryAction $listDirectory,
        private readonly ReadFileContentAction $readFileContent,
        private readonly SaveFileContentAction $saveFileContent,
        private readonly CreateFileEntryAction $createFileEntry,
        private readonly DeleteFileEntryAction $deleteFileEntry,
        private readonly ChmodFileEntryAction $chmodFileEntry,
        private readonly FileManagerDriverInterface $driver,
    ) {}

    /**
     * Resolve target domain and verify caller has authorization to access it.
     * Only administrators and the domain's owner are authorized.
     */
    protected function resolveTargetDomain(Request $request): ?Domain
    {
        $domainId = $request->input('domain_id');
        if (! $domainId) {
            return null;
        }

        $user = Auth::user();
        $targetDomain = Domain::with('user')->find($domainId);

        if (! $targetDomain) {
            return null;
        }

        if ($user && ($user->isAdmin() || (int) $targetDomain->user_id === (int) $user->id)) {
            return $targetDomain;
        }

        return null;
    }

    /**
     * Display the file manager view.
     */
    public function index(Request $request): Response
    {
        $domainId = $request->input('domain_id');
        $targetDomain = null;
        $initialPath = '';
        $user = Auth::user();

        if ($domainId) {
            $targetDomain = $this->resolveTargetDomain($request);
            if ($targetDomain) {
                $relDocRoot = $targetDomain->public_path
                    ? "{$targetDomain->domain}/public_html/{$targetDomain->public_path}"
                    : "{$targetDomain->domain}/public_html";

                $initialPath = $relDocRoot;

                try {
                    $baseDir = $this->resolveUserBaseDir->handle($user, $targetDomain?->user);
                    $systemPath = new SystemPath($baseDir, $initialPath);
                    $this->driver->ensureDirectory($systemPath);
                } catch (InvalidArgumentException) {
                    // Ignore invalid initial path
                }
            }
        }

        $domainsQuery = Domain::query()->select('id', 'domain', 'user_id', 'document_root', 'public_path');
        if (! $user->isAdmin()) {
            $domainsQuery->where('user_id', $user->id);
        }
        $availableDomains = $domainsQuery->orderBy('domain')->get();

        return Inertia::render('Files/Index', [
            'baseDir' => '/home/'.($targetDomain ? $targetDomain->user->username : $user->username),
            'initialPath' => $initialPath,
            'targetDomain' => $targetDomain,
            'availableDomains' => $availableDomains,
        ]);
    }

    /**
     * Return directory listing as JSON.
     */
    public function list(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);

        if ($domainId && ! $targetDomain) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid directory or access denied.',
            ], 403);
        }

        $path = (string) $request->input('path', '');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $path);
            $autoProvision = (bool) ($targetDomain && $path !== '');
            $items = $this->listDirectory->handle($systemPath, $autoProvision);
        } catch (InvalidArgumentException|RuntimeException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid directory or access denied.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'current_path' => trim($path, '/'),
            'items' => $items,
        ]);
    }

    /**
     * Read content of a file for the code editor.
     */
    public function content(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 403);
        }

        $path = (string) $request->input('path', '');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $path, mustExist: true);
            $result = $this->readFileContent->handle($systemPath);
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 404);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'path' => $path,
            'content' => $result['content'],
            'size' => $result['size'],
        ]);
    }

    /**
     * Save edited file content.
     */
    public function save(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 403);
        }

        $path = (string) $request->input('path', '');
        $content = (string) $request->input('content', '');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $path, mustExist: true);
            $this->saveFileContent->handle($systemPath, $content);
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'File not found or access denied.'], 404);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => 'File saved successfully.']);
    }

    /**
     * Create a new file or directory.
     */
    public function create(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        $path = (string) $request->input('path', '');
        $name = (string) $request->input('name', '');
        $type = (string) $request->input('type', 'file');

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid name.'], 422);
        }

        $targetSubpath = trim($path.'/'.$name, '/');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $targetSubpath);
            if ($systemPath->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Item already exists.'], 422);
            }
            $this->createFileEntry->handle($systemPath, $type);
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => ucfirst($type).' created.']);
    }

    /**
     * Delete a file or directory.
     */
    public function delete(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        $path = (string) $request->input('path', '');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $path, mustExist: true);
            $this->deleteFileEntry->handle($systemPath);
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'Target not found.'], 404);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => 'Item deleted.']);
    }

    /**
     * Upload files into current path.
     */
    public function upload(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        $request->validate([
            'path' => ['nullable', 'string'],
            'files.*' => ['required', 'file', 'max:102400'],
        ]);

        $path = (string) $request->input('path', '');
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $destDir = new SystemPath($baseDir, $path, mustExist: true);
            if (! $destDir->isDir()) {
                return response()->json(['status' => 'error', 'message' => 'Target directory does not exist.'], 422);
            }
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'Target directory does not exist.'], 422);
        }

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $uploadedFile) {
                $filename = $uploadedFile->getClientOriginalName();
                $uploadedFile->move($destDir->fullPath, $filename);
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Files uploaded successfully.']);
    }

    /**
     * Change permissions of a file or directory (chmod).
     */
    public function chmod(Request $request): JsonResponse
    {
        $domainId = $request->input('domain_id');
        $targetDomain = $this->resolveTargetDomain($request);
        if ($domainId && ! $targetDomain) {
            return response()->json(['status' => 'error', 'message' => 'Access denied.'], 403);
        }

        $request->validate([
            'path' => ['required', 'string'],
            'permissions' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
        ]);

        $path = (string) $request->input('path', '');
        $octal = octdec($request->input('permissions'));
        $baseDir = $this->resolveUserBaseDir->handle(Auth::user(), $targetDomain?->user);

        try {
            $systemPath = new SystemPath($baseDir, $path, mustExist: true);
            $newPerms = $this->chmodFileEntry->handle($systemPath, $octal);
        } catch (InvalidArgumentException) {
            return response()->json(['status' => 'error', 'message' => 'Target file or directory not found.'], 404);
        } catch (RuntimeException) {
            return response()->json(['status' => 'error', 'message' => 'Failed to change permissions.'], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Permissions updated successfully.',
            'permissions' => $newPerms,
        ]);
    }
}
