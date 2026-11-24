<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\IntelligenceArtifactContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntelligenceArtifactContractTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @return list<array<string, mixed>> */
    private function manifest(): array
    {
        return [
            ['kind' => 'xlsx', 'mime' => self::XLSX_MIME, 'size_bytes' => 39120, 'sha256' => str_repeat('b', 64)],
            ['kind' => 'pdf', 'mime' => 'application/pdf', 'size_bytes' => 482133, 'sha256' => str_repeat('a', 64)],
        ];
    }

    public function test_manifest_is_optional_and_normalized_to_a_canonical_order(): void
    {
        $this->assertSame([], IntelligenceArtifactContract::normalizeManifest(null));

        $normalized = IntelligenceArtifactContract::normalizeManifest($this->manifest());
        $this->assertSame(['pdf', 'xlsx'], array_column($normalized, 'kind'));

        $withUnknownField = $this->manifest();
        $withUnknownField[0]['url'] = 'https://example.test/signed';
        $this->assertSame($normalized, IntelligenceArtifactContract::normalizeManifest($withUnknownField));
    }

    public function test_set_hash_is_stable_regardless_of_input_order(): void
    {
        $forward = IntelligenceArtifactContract::normalizeManifest($this->manifest());
        $reversed = IntelligenceArtifactContract::normalizeManifest(array_reverse($this->manifest()));

        $this->assertSame(IntelligenceArtifactContract::setHash($forward), IntelligenceArtifactContract::setHash($reversed));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', IntelligenceArtifactContract::setHash($forward));
    }

    public function test_manifest_rejects_each_contract_violation(): void
    {
        $pdf = $this->manifest()[1];
        $xlsx = $this->manifest()[0];

        $invalid = [
            'objek alih-alih daftar' => ['pdf' => $pdf],
            'lebih dari dua artefak' => [$pdf, $xlsx, $pdf],
            'kind ganda' => [$pdf, $pdf],
            'kind tidak dikenal' => [[...$pdf, 'kind' => 'docx']],
            'mime tidak cocok' => [[...$pdf, 'mime' => self::XLSX_MIME]],
            'ukuran nol' => [[...$pdf, 'size_bytes' => 0]],
            'ukuran di atas batas' => [[...$pdf, 'size_bytes' => IntelligenceArtifactContract::MAX_BYTES + 1]],
            'ukuran bukan bilangan bulat' => [[...$pdf, 'size_bytes' => '482133']],
            'sha256 huruf besar' => [[...$pdf, 'sha256' => strtoupper(str_repeat('a', 64))]],
            'sha256 terlalu pendek' => [[...$pdf, 'sha256' => str_repeat('a', 63)]],
            'entri bukan objek' => ['pdf'],
        ];

        foreach ($invalid as $case => $artifacts) {
            try {
                IntelligenceArtifactContract::normalizeManifest($artifacts);
                $this->fail("Manifest seharusnya ditolak: {$case}.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_contract_constants_match_the_database_limits(): void
    {
        $this->assertSame(10 * 1024 * 1024, IntelligenceArtifactContract::MAX_BYTES);
        $this->assertSame(['pdf' => 'application/pdf', 'xlsx' => self::XLSX_MIME], IntelligenceArtifactContract::KINDS);
        $this->assertSame('local', IntelligenceArtifactContract::STORAGE_DISK);
    }
}
