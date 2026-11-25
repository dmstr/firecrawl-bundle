<?php

// file generated with AI assistance: Claude Code - 2026-10-22 08:30:00 UTC

declare(strict_types=1);

namespace Dmstr\Firecrawl\Crawl;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends the bytes of a document file to the converter container (`converter/`)
 * and returns the Markdown. The converter sees the file only: no URL, no
 * cookie, no secret.
 */
final class FileConverter
{
    private const int DEFAULT_TIMEOUT = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $connection configJson of a `file-converter` connection (`base_url`, `timeout`)
     * @param string|null $converter `markitdown`, `pandas` or `xlsx-formulas`; null lets the converter pick by extension
     * @param bool        $ocr       pages of a PDF without a text layer are read by OCR
     *
     * @return array{markdown: string, title: ?string, converter: string, pages: ?int, route: ?string} route: how the text was recovered, e.g. `tesseract (p2 of 8)`
     *
     * @throws \RuntimeException when the converter is unreachable or rejects the file
     */
    public function convert(array $connection, string $bytes, string $ext, ?string $converter = null, bool $pageMarkers = false, bool $ocr = false): array
    {
        $boundary = bin2hex(random_bytes(12));
        $fields = ['ext' => $ext, 'converter' => $converter ?? '', 'page_markers' => $pageMarkers ? 'true' : 'false', 'ocr' => $ocr ? 'true' : 'false'];
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"document.$ext\"\r\n"
            ."Content-Type: application/octet-stream\r\n\r\n$bytes\r\n--$boundary--\r\n";

        try {
            $response = $this->httpClient->request('POST', rtrim((string) ($connection['base_url'] ?? ''), '/').'/convert', [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
                'body' => $body,
                'timeout' => (int) ($connection['timeout'] ?? self::DEFAULT_TIMEOUT),
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('Converter not reachable: '.$e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            throw new \RuntimeException((string) ($data['detail'] ?? 'Converter answered HTTP '.$status));
        }

        return [
            'markdown' => (string) ($data['markdown'] ?? ''),
            'title' => isset($data['title']) ? (string) $data['title'] : null,
            'converter' => (string) ($data['converter'] ?? ''),
            'pages' => isset($data['pages']) ? (int) $data['pages'] : null,
            'route' => isset($data['route']) ? (string) $data['route'] : null,
        ];
    }
}

// - revised 2026-11-17
