<?php

namespace Mayaram\LaravelOcr\Tests\Unit;

use Mayaram\LaravelOcr\Drivers\TesseractDriver;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

class TesseractPdfTest extends TestCase
{
    public function test_text_only_pdf_is_extracted_without_ghostscript_or_tesseract(): void
    {
        $path = $this->createPdf('.pdf');
        try {
            if (! defined('LARAVEL_START')) {
                define('LARAVEL_START', microtime(true));
            }
            $this->assertStringContainsString('Selectable text', (new Parser)->parseFile($path)->getText());
            $driver = $this->driver();
            $result = $driver->extract($path);
            $this->assertStringContainsString('Selectable text', $result['text']);
            $this->assertSame('pdfparser', $result['metadata']['method']);
            $this->assertNull($driver->convertedPath);
            $this->assertFileExists($path);
            $this->assertNull($driver->preparedText());
        } finally {
            unlink($path);
        }
    }

    public function test_mixed_pdf_still_goes_through_ocr(): void
    {
        $path = $this->createPdf('.pdf', true);
        try {
            $this->assertStringContainsString('Selectable text', (new Parser)->parseFile($path)->getText());
            $driver = $this->driver();
            $this->assertSame('rendered-pages.tiff', $driver->prepare($path));
            $this->assertSame($path, $driver->convertedPath);
            $this->assertFileExists($path);
        } finally {
            unlink($path);
        }
    }

    public function test_windows_temporary_upload_is_detected_as_pdf(): void
    {
        $path = $this->createPdf('.tmp', true);
        try {
            $driver = $this->driver();
            $this->assertSame('rendered-pages.tiff', $driver->prepare($path));
            $this->assertSame($path, $driver->convertedPath);
        } finally {
            unlink($path);
        }
    }

    public function test_image_input_is_preserved(): void
    {
        $driver = $this->driver();
        $this->assertSame('original.png', $driver->prepare('original.png'));
        $this->assertNull($driver->convertedPath);
    }

    public function test_inline_images_trigger_ocr(): void
    {
        $path = $this->createPdf('.pdf', false, true);
        try {
            $this->assertSame('rendered-pages.tiff', $this->driver()->prepare($path));
        } finally {
            unlink($path);
        }
    }

    public function test_thin_decorative_image_does_not_trigger_ocr(): void
    {
        $path = $this->createPdf('.pdf', true, false, 366, 6);
        try {
            $driver = $this->driver();
            $this->assertSame($path, $driver->prepare($path));
            $this->assertNull($driver->convertedPath);
            $this->assertStringContainsString('Selectable text', $driver->preparedText());
        } finally {
            unlink($path);
        }
    }

    public function test_image_threshold_can_be_disabled(): void
    {
        $path = $this->createPdf('.pdf', true, false, 366, 6);
        try {
            $driver = $this->driver(['pdf_min_image_dimension' => 1]);
            $this->assertSame('rendered-pages.tiff', $driver->prepare($path));
        } finally {
            unlink($path);
        }
    }

    public function test_preparing_another_document_clears_previous_pdf_text(): void
    {
        $path = $this->createPdf('.pdf');
        try {
            $driver = $this->driver();
            $driver->prepare($path);
            $this->assertStringContainsString('Selectable text', $driver->preparedText());
            $driver->prepare('original.png');
            $this->assertNull($driver->preparedText());
        } finally {
            unlink($path);
        }
    }

    private function driver(array $config = []): TesseractDriver
    {
        return new class($config) extends TesseractDriver
        {
            public ?string $convertedPath = null;

            public function preparedText(): ?string
            {
                return $this->pdfExtractedText;
            }

            public function prepare(string $path): string
            {
                return $this->prepareDocument($path);
            }

            protected function convertPdfToImage($pdfPath): string
            {
                $this->convertedPath = $pdfPath;

                return 'rendered-pages.tiff';
            }
        };
    }

    private function createPdf(string $extension, bool $withImage = false, bool $inlineImage = false, int $width = 16, int $height = 16): string
    {
        $stream = 'BT /F1 18 Tf 20 100 Td (Selectable text) Tj ET';
        if ($withImage) {
            $stream .= "\nq 50 0 0 50 20 20 cm /Im1 Do Q";
        }
        if ($inlineImage) {
            $stream .= "\nq 50 0 0 50 20 20 cm BI /W 1 /H 1 /CS /G /BPC 8 ID \x80 EI Q";
        }
        $resources = $withImage ? '/XObject << /Im1 6 0 R >>' : '';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> '.$resources.' >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
        ];
        if ($withImage) {
            $pixels = str_repeat("\x80", $width * $height);
            $objects[] = '<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceGray /BitsPerComponent 8 /Length '.strlen($pixels).">>\nstream\n".$pixels."\nendstream";
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $path = sys_get_temp_dir().'/'.uniqid('ocr_regression_', true).$extension;
        file_put_contents($path, $pdf);

        return $path;
    }
}
