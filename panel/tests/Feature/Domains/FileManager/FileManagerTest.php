<?php

namespace Tests\Feature\Domains\FileManager;

use App\Domains\FileManager\Actions\ChmodFileEntryAction;
use App\Domains\FileManager\Actions\CreateFileEntryAction;
use App\Domains\FileManager\Actions\DeleteFileEntryAction;
use App\Domains\FileManager\Actions\ListDirectoryAction;
use App\Domains\FileManager\Actions\ReadFileContentAction;
use App\Domains\FileManager\Actions\SaveFileContentAction;
use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    private string $tempBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = storage_path('app/test_homes/'.uniqid('fm_', true));
        File::makeDirectory($this->tempBase.'/public_html', 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempBase) && file_exists($this->tempBase)) {
            File::deleteDirectory($this->tempBase);
        }
        parent::tearDown();
    }

    public function test_system_path_allows_valid_relative_paths_within_confined_base_directory(): void
    {
        $path = new SystemPath($this->tempBase, 'public_html');

        $this->assertTrue($path->isDir());
        $this->assertSame('public_html', $path->subpath);
        $this->assertSame(realpath($this->tempBase.'/public_html'), $path->fullPath);
    }

    public function test_system_path_rejects_directory_traversal_attempts(): void
    {
        $maliciousPaths = [
            '../etc/passwd',
            'public_html/../../etc/passwd',
            '..\\..\\Windows\\System32',
            '..',
        ];

        foreach ($maliciousPaths as $malicious) {
            try {
                new SystemPath($this->tempBase, $malicious);
                $this->fail("Expected InvalidArgumentException for path: {$malicious}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Path traversal is not allowed.', $e->getMessage());
            }
        }
    }

    public function test_system_path_rejects_null_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains null bytes.');

        new SystemPath($this->tempBase, "public_html\0.php");
    }

    public function test_system_path_allows_non_existent_target_when_must_exist_is_false(): void
    {
        $path = new SystemPath($this->tempBase, 'public_html/new_file.txt', mustExist: false);

        $this->assertFalse($path->exists());
        $this->assertSame('public_html/new_file.txt', $path->subpath);
    }

    public function test_system_path_throws_when_must_exist_is_true_and_target_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SystemPath($this->tempBase, 'public_html/missing.txt', mustExist: true);
    }

    public function test_system_path_rejects_symlink_traversal_escapes(): void
    {
        $outsideDir = storage_path('app/test_homes/'.uniqid('outside_', true));
        File::makeDirectory($outsideDir, 0755, true);

        $symlinkPath = $this->tempBase.'/public_html/escape_link';

        try {
            if (! @symlink($outsideDir, $symlinkPath)) {
                $this->markTestSkipped('Symlink creation is not permitted in this environment.');
            }

            $this->expectException(InvalidArgumentException::class);
            new SystemPath($this->tempBase, 'public_html/escape_link/nonexistent_dir/new_file.txt', mustExist: false);
        } finally {
            if (is_link($symlinkPath)) {
                @unlink($symlinkPath);
            }
            if (file_exists($outsideDir)) {
                File::deleteDirectory($outsideDir);
            }
        }
    }

    public function test_file_manager_actions_create_list_read_save_chmod_and_delete_entries(): void
    {
        $filePath = new SystemPath($this->tempBase, 'public_html/hello.txt');
        $dirPath = new SystemPath($this->tempBase, 'public_html');

        $mockCli = Mockery::mock(LcCliService::class);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['create', $filePath->fullPath, 'file'])
            ->andReturn(['status' => 'success', 'data' => []]);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['write', $filePath->fullPath, base64_encode('Hello LiteControl')])
            ->andReturn(['status' => 'success', 'data' => []]);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['read', $filePath->fullPath, '5242880'])
            ->andReturn(['status' => 'success', 'data' => ['content' => 'Hello LiteControl', 'size' => 17]]);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['list', $dirPath->fullPath])
            ->andReturn(['status' => 'success', 'data' => [['name' => 'hello.txt']]]);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['chmod', $filePath->fullPath, '0644'])
            ->andReturn(['status' => 'success', 'data' => ['permissions' => '0644']]);
        $mockCli->shouldReceive('run')
            ->once()
            ->with('filemanager', ['delete', $filePath->fullPath])
            ->andReturn(['status' => 'success', 'data' => []]);

        $this->app->instance(LcCliService::class, $mockCli);
        $driver = app(FileManagerDriverInterface::class);

        $createAction = new CreateFileEntryAction($driver);
        $listAction = new ListDirectoryAction($driver);
        $readAction = new ReadFileContentAction($driver);
        $saveAction = new SaveFileContentAction($driver);
        $chmodAction = new ChmodFileEntryAction($driver);
        $deleteAction = new DeleteFileEntryAction($driver);

        $createAction->handle($filePath, 'file');
        $saveAction->handle($filePath, 'Hello LiteControl');

        $read = $readAction->handle($filePath);
        $this->assertSame('Hello LiteControl', $read['content']);
        $this->assertSame(17, $read['size']);

        $items = $listAction->handle($dirPath);
        $this->assertCount(1, $items);
        $this->assertSame('hello.txt', $items[0]['name']);

        $perms = $chmodAction->handle($filePath, 0644);
        $this->assertSame('0644', $perms);

        $deleteAction->handle($filePath);
    }
}
