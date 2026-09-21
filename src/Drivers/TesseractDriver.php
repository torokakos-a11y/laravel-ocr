<?php

namespace Mayaram\LaravelOcr\Drivers;

use Mayaram\LaravelOcr\Contracts\OCRDriver;
use Mayaram\LaravelOcr\Exceptions\OCRException;
use thiagoalessio\TesseractOCR\TesseractOCR;

class TesseractDriver implements OCRDriver
{
    protected array $config;

    protected ?string $pdfExtractedText = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function extract($document, array $options = []): array
    {
        try {
            $imagePath = $this->prepareDocument($document);

            if ($this->pdfExtractedText !== null) {
                return [
                    'text' => $this->pdfExtractedText,
                    'confidence' => 0.90,
                    'bounds' => [],
                    'metadata' => [
                        'engine' => 'tesseract',
                        'method' => 'pdfparser',
                        'language' => $options['language'] ?? $this->config['language'] ?? 'eng',
                        'processing_time' => microtime(true) - LARAVEL_START,
                    ],
                ];
            }

            $ocr = new TesseractOCR($imagePath);

            // Set the tesseract binary path from config (Herd/Valet have limited $PATH)
            if (! empty($this->config['binary'])) {
                $ocr->executable($this->config['binary']);
            }

            if (isset($options['language'])) {
                $ocr->lang($options['language']);
            } elseif (isset($this->config['language'])) {
                $ocr->lang($this->config['language']);
            }

            if (isset($options['whitelist'])) {
                $ocr->whitelist($options['whitelist']);
            }

            if (isset($options['psm'])) {
                $ocr->psm($options['psm']);
            }

            // The wrapper also uses "did not produce any output" for process
            // failures, missing language data, and unreadable images. Preserve
            // its diagnostics instead of reporting those failures as empty text.
            $text = $ocr->run();

            $bounds = $this->extractBounds($ocr);

            return [
                'text' => $text,
                'confidence' => $this->calculateConfidence($ocr),
                'bounds' => $bounds,
                'metadata' => [
                    'engine' => 'tesseract',
                    'language' => $options['language'] ?? $this->config['language'] ?? 'eng',
                    'processing_time' => microtime(true) - LARAVEL_START,
                ],
            ];
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            throw new OCRException('Tesseract extraction failed: '.$e->getMessage(), 0, $e);
        } finally {
            if (isset($imagePath) && file_exists($imagePath) && $imagePath !== $document) {
                unlink($imagePath);
            }
            $this->pdfExtractedText = null;
        }
    }

    public function extractTable($document, array $options = []): array
    {
        $extraction = $this->extract($document, array_merge($options, ['psm' => 6]));

        $lines = explode("\n", $extraction['text']);
        $table = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $cells = preg_split('/\s{2,}|\t/', $line);
                $table[] = array_map('trim', $cells);
            }
        }

        return [
            'table' => $table,
            'raw_text' => $extraction['text'],
            'metadata' => $extraction['metadata'],
        ];
    }

    public function extractBarcode($document, array $options = []): array
    {
        throw new OCRException('Barcode extraction not supported by Tesseract driver. Use a specialized barcode library.');
    }

    public function extractQRCode($document, array $options = []): array
    {
        throw new OCRException('QR code extraction not supported by Tesseract driver. Use a specialized QR code library.');
    }

    public function getSupportedLanguages(): array
    {
        return [
            'eng' => 'English',
            'spa' => 'Spanish',
            'fra' => 'French',
            'deu' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'rus' => 'Russian',
            'jpn' => 'Japanese',
            'kor' => 'Korean',
            'chi_sim' => 'Chinese (Simplified)',
            'chi_tra' => 'Chinese (Traditional)',
            'ara' => 'Arabic',
            'hin' => 'Hindi',
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['jpg', 'jpeg', 'png', 'tiff', 'bmp', 'pdf'];
    }

    protected function prepareDocument($document): string
    {
        $this->pdfExtractedText = null;

        if (filter_var($document, FILTER_VALIDATE_URL)) {
            $tempPath = tempnam(sys_get_temp_dir(), 'ocr_');
            if ($tempPath === false) {
                throw new OCRException('Unable to create a temporary download file.');
            }

            try {
                if (! copy($document, $tempPath)) {
                    throw new OCRException('Unable to download document.');
                }

                // Detect the downloaded content, including PDF URLs without an extension.
                $preparedPath = $this->prepareDocument($tempPath);

                return $preparedPath;
            } finally {
                if ((! isset($preparedPath) || $preparedPath !== $tempPath) && file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }
        }

        $extension = strtolower(pathinfo($document, PATHINFO_EXTENSION));

        // If no extension (e.g. PHP temp upload like /tmp/phpXXXXX), detect from MIME type
        if (empty($extension) || $extension === 'tmp') {
            $mimeType = mime_content_type($document);
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/tiff' => 'tiff',
                'image/bmp' => 'bmp',
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
            ];
            $extension = $mimeToExt[$mimeType] ?? '';
        }

        if ($extension === 'pdf') {
            $text = $this->extractTextOnlyPdf($document);
            if ($text !== null) {
                $this->pdfExtractedText = $text;

                return $document;
            }

            // Mixed or scanned PDFs need OCR even when some text is selectable.
            return $this->convertPdfToImage($document);
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'tiff', 'bmp'])) {
            return $document;
        }

        throw new OCRException("Unsupported file format: {$extension}");
    }

    protected function extractTextOnlyPdf(string $pdfPath): ?string
    {
        try {
            $pdf = (new \Smalot\PdfParser\Parser)->parseFile($pdfPath);
            $minimumDimension = max(1, (int) ($this->config['pdf_min_image_dimension'] ?? 8));
            foreach ($pdf->getObjectsByType('XObject', 'Image') as $image) {
                $details = $image->getDetails(false);
                $width = (int) ($details['Width'] ?? 0);
                $height = (int) ($details['Height'] ?? 0);

                // Very thin raster rules and tiny decorative images should not
                // force a text document through OCR. Unknown dimensions remain
                // conservative; set the threshold to 1 to include every image.
                if ($width <= 0 || $height <= 0 || min($width, $height) >= $minimumDimension) {
                    return null;
                }
            }

            // Inline images have no separate Image XObject. Inspect decoded
            // streams too, including nested Form XObjects.
            foreach ($pdf->getObjects() as $object) {
                if (preg_match('/(?:^|\s)BI\s+\//', $object->getContent() ?? '')) {
                    return null;
                }
            }

            $text = $pdf->getText();

            return trim($text) !== '' ? $text : null;
        } catch (\Exception $e) {
            // If parsing fails, rendering can still recover the visible text.
            return null;
        }
    }

    protected function convertPdfToImage($pdfPath): string
    {
        if (! extension_loaded('imagick')) {
            throw new OCRException('PDF OCR requires the Imagick extension and Ghostscript.');
        }

        $imagePath = sys_get_temp_dir().'/'.uniqid('ocr_', true).'.tiff';

        // Ensure Ghostscript can be found (Herd/Valet have limited $PATH)
        $currentPath = getenv('PATH') ?: '';
        if (PHP_OS_FAMILY === 'Darwin' && ! str_contains($currentPath, '/opt/homebrew/bin')) {
            putenv('PATH=/opt/homebrew/bin'.PATH_SEPARATOR.'/usr/local/bin'.PATH_SEPARATOR.$currentPath);
        }

        $imagick = new \Imagick;
        try {
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath);
            foreach ($imagick as $page) {
                $page->setImageBackgroundColor('white');
                $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $page->setImageFormat('tiff');
                $page->setImageCompression(\Imagick::COMPRESSION_LZW);
            }
            // Tesseract reads every frame of a multi-page TIFF in page order.
            if (! $imagick->writeImages($imagePath, true)) {
                throw new OCRException('Unable to write rendered PDF pages.');
            }
        } catch (\Throwable $e) {
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
            throw $e;
        } finally {
            $imagick->clear();
            $imagick->destroy();
            if (PHP_OS_FAMILY === 'Darwin') {
                putenv('PATH='.$currentPath);
            }
        }

        return $imagePath;
    }

    protected function extractBounds($ocr): array
    {
        return [];
    }

    protected function calculateConfidence($ocr): float
    {
        return 0.0;
    }
}
