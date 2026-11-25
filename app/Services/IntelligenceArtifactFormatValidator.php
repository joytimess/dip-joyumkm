<?php

declare(strict_types=1);

namespace App\Services;

use ZipArchive;

/**
 * Validasi byte PDF dengan QPDF dan XLSX dengan metadata ZIP serta bagian XML yang dibatasi.
 */
final class IntelligenceArtifactFormatValidator
{
    private const MAX_ZIP_ENTRIES = 200;

    private const MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    private const MAX_TOTAL_UNCOMPRESSED = 150 * 1024 * 1024;

    private const MAX_COMPRESSION_RATIO = 200;

    private const MAX_XML_PART_BYTES = 2 * 1024 * 1024;

    private const REQUIRED_PARTS = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml'];

    private const FORBIDDEN_REL_TYPES = ['externalLink', 'externalLinkPath', 'oleObject', 'vbaProject', 'attachedTemplate', 'frame', 'package'];

    /**
     * @return string|null kode alasan penolakan, atau null bila valid.
     */
    public function validate(string $kind, string $path): ?string
    {
        return match ($kind) {
            'pdf' => $this->validatePdf($path),
            'xlsx' => $this->validateXlsx($path),
            default => 'unsupported_kind',
        };
    }

    private function validatePdf(string $path): ?string
    {
        $size = filesize($path);
        if ($size === false || $size < 64 || $size > IntelligenceArtifactContract::MAX_BYTES) {
            return 'pdf_size';
        }
        $content = file_get_contents($path, false, null, 0, 8);
        if ($content === false || preg_match('/\A%PDF-[12]\.\d/', $content) !== 1) {
            return 'pdf_signature';
        }
        $tail = file_get_contents($path, false, null, max(0, $size - 2048));
        if ($tail === false || preg_match('/startxref\s+\d+\s+%%EOF[\x00\x09\x0a\x0c\x0d\x20]*\z/', $tail) !== 1) {
            return 'pdf_truncated';
        }

        $absolutePath = realpath($path);
        $python = config('services.hermes_intelligence_artifact.python');
        $qpdf = config('services.hermes_intelligence_artifact.qpdf');
        // Executable kosong atau tidak dikonfigurasi harus gagal tertutup, bukan memanggil proc_open dengan null.
        if ($absolutePath === false || ! function_exists('proc_open')
            || ! is_string($python) || trim($python) === '' || ! is_string($qpdf) || trim($qpdf) === '') {
            return 'pdf_validation_failed';
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $deadline = hrtime(true) + 8_000_000_000;
        $process = @proc_open([
            $python,
            '-I',
            __DIR__.'/validate-intelligence-pdf.py',
            $qpdf,
            $absolutePath,
        ], [
            ['file', $null, 'r'],
            ['file', $null, 'w'],
            ['file', $null, 'w'],
        ], $pipes, options: ['bypass_shell' => true]);
        if ($process === false) {
            return 'pdf_validation_failed';
        }

        try {
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    return match ($status['exitcode']) {
                        0 => null,
                        3 => 'pdf_unsafe_content',
                        4 => 'pdf_encrypted',
                        default => 'pdf_validation_failed',
                    };
                }
                usleep(10_000);
            } while (hrtime(true) < $deadline);

            proc_terminate($process, 9);

            return 'pdf_validation_failed';
        } finally {
            proc_close($process);
        }
    }

    private function validateXlsx(string $path): ?string
    {
        $header = file_get_contents($path, false, null, 0, 4);
        if ($header !== "PK\x03\x04") {
            return 'xlsx_not_zip';
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return 'xlsx_not_zip';
        }

        try {
            return $this->inspectZip($zip);
        } finally {
            $zip->close();
        }
    }

    private function inspectZip(ZipArchive $zip): ?string
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            return 'xlsx_entry_count';
        }

        $names = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                return 'xlsx_corrupt';
            }
            $name = $stat['name'];
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $name) === 1 || preg_match('#^[A-Za-z]:#', $name) === 1) {
                return 'xlsx_path_traversal';
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                return 'xlsx_encrypted';
            }
            if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                return 'xlsx_too_large_uncompressed';
            }
            $total += $stat['size'];
            if ($total > self::MAX_TOTAL_UNCOMPRESSED
                || ($stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > self::MAX_COMPRESSION_RATIO && $stat['size'] > 1024 * 1024)) {
                return 'xlsx_zip_bomb';
            }
            $lower = strtolower($name);
            if (str_contains($lower, 'vbaproject') || str_starts_with($lower, 'xl/externallinks/')
                || str_starts_with($lower, 'xl/embeddings/') || str_starts_with($lower, 'xl/activex/') || str_ends_with($lower, '.bin')) {
                return 'xlsx_unsafe_content';
            }
            $names[$name] = true;
        }

        foreach (self::REQUIRED_PARTS as $required) {
            if (! isset($names[$required])) {
                return 'xlsx_missing_part';
            }
        }

        $contentTypes = $this->readPart($zip, '[Content_Types].xml');
        if ($contentTypes === null || stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false
            || ! str_contains($contentTypes, 'spreadsheetml.sheet.main+xml')) {
            return 'xlsx_content_types';
        }

        foreach (array_keys($names) as $name) {
            if (str_ends_with($name, '.rels')) {
                $rels = $this->readPart($zip, $name);
                if ($rels === null) {
                    return 'xlsx_zip_bomb';
                }
                if ($this->hasForbiddenRelationship($rels)) {
                    return 'xlsx_unsafe_content';
                }
            }
        }

        return null;
    }

    private function hasForbiddenRelationship(string $rels): bool
    {
        if (preg_match_all('/<Relationship\b[^>]*>/i', $rels, $matches) === false) {
            return true;
        }
        foreach ($matches[0] as $tag) {
            $type = preg_match('/\bType="([^"]*)"/i', $tag, $typeMatch) === 1 ? $typeMatch[1] : '';
            $external = preg_match('/\bTargetMode="External"/i', $tag) === 1;
            $leaf = strtolower(substr($type, (int) strrpos($type, '/') + 1));
            if (in_array($leaf, array_map('strtolower', self::FORBIDDEN_REL_TYPES), true) || ($external && $leaf !== 'hyperlink')) {
                return true;
            }
        }

        return false;
    }

    private function readPart(ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }
        $data = stream_get_contents($stream, self::MAX_XML_PART_BYTES + 1);
        fclose($stream);

        return $data === false || strlen($data) > self::MAX_XML_PART_BYTES ? null : $data;
    }
}
