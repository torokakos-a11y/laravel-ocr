<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Drivers\TesseractDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use PHPUnit\Framework\TestCase;

class TesseractTemporaryDirectoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/'.uniqid('ocr_shared_test_', true);
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function test_image_is_copied_to_shared_directory_without_changing_original(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'ocr_upload_');
        // A valid one-pixel PNG also tests MIME detection for temporary uploads.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=');
        file_put_contents($source, $bytes);
        try {
            $driver = new TesseractDriver(['temp_dir' => $this->directory]);
            $prepared = $this->invoke($driver, 'prepareDocument', $source);
            $this->assertSame(realpath($this->directory), realpath(dirname($prepared)));
            $this->assertNotSame($source, $prepared);
            $this->assertSame($bytes, file_get_contents($prepared));
            $this->assertSame($bytes, file_get_contents($source));
        } finally {
            unlink($source);
        }
    }

    public function test_tesseract_output_uses_the_shared_directory(): void
    {
        $driver = new TesseractDriver(['temp_dir' => $this->directory]);
        $ocr = $this->invoke($driver, 'createTesseract', 'input.tiff');
        $output = $ocr->command->getOutputFile();
        $this->assertSame(realpath($this->directory), realpath(dirname($output)));
    }

    public function test_missing_directory_fails_instead_of_using_private_tmp(): void
    {
        $driver = new TesseractDriver(['temp_dir' => $this->directory.'/missing']);
        $this->expectException(OCRException::class);
        $this->expectExceptionMessage('must exist and be writable');
        $this->invoke($driver, 'createTesseract', 'input.tiff');
    }

    public function test_default_output_directory_is_unchanged(): void
    {
        $ocr = $this->invoke(new TesseractDriver, 'createTesseract', 'input.tiff');
        $this->assertSame(sys_get_temp_dir(), $ocr->command->getTempDir());
    }

    private function invoke(TesseractDriver $driver, string $method, string $argument): mixed
    {
        return (new \ReflectionMethod($driver, $method))->invoke($driver, $argument);
    }
}
