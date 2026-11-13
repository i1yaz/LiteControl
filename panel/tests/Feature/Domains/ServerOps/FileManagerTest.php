<?php

namespace Tests\Feature\Domains\ServerOps;

use App\Domains\ServerOps\Actions\ChmodFileEntryAction;
use App\Domains\ServerOps\Actions\CreateFileEntryAction;
use App\Domains\ServerOps\Actions\DeleteFileEntryAction;
use App\Domains\ServerOps\Actions\ListDirectoryAction;
use App\Domains\ServerOps\Actions\ReadFileContentAction;
use App\Domains\ServerOps\Actions\SaveFileContentAction;
use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    private string $tempBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = storage_path('app/test_homes/' . uniqid('fm_', true));
        File::makeDirectory($this->tempBase . '/public_html', 0755, true);
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
        $this->assertSame(realpath($this->tempBase . '/public_html'), $path->fullPath);
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

    public function test_file_manager_actions_create_list_read_save_chmod_and_delete_entries(): void
    {
        $driver = app(FileManagerDriverInterface::class);

        $createAction = new CreateFileEntryAction($driver);
        $listAction = new ListDirectoryAction($driver);
        $readAction = new ReadFileContentAction($driver);
        $saveAction = new SaveFileContentAction($driver);
        $chmodAction = new ChmodFileEntryAction($driver);
        $deleteAction = new DeleteFileEntryAction($driver);

        $filePath = new SystemPath($this->tempBase, 'public_html/hello.txt');
        $createAction->handle($filePath, 'file');
        $this->assertTrue($filePath->exists());

        $saveAction->handle($filePath, 'Hello LiteControl');
        $read = $readAction->handle($filePath);
        $this->assertSame('Hello LiteControl', $read['content']);
        $this->assertSame(17, $read['size']);

        $dirPath = new SystemPath($this->tempBase, 'public_html');
        $items = $listAction->handle($dirPath);
        $this->assertCount(1, $items);
        $this->assertSame('hello.txt', $items[0]['name']);

        $perms = $chmodAction->handle($filePath, 0644);
        $this->assertIsString($perms);

        $deleteAction->handle($filePath);
        $this->assertFalse($filePath->exists());
    }
}
