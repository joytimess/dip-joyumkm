<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IntelligenceArtifactFormatValidator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IntelligencePdfValidatorTest extends TestCase
{
    public function test_hermes_pdf_is_accepted_and_missing_qpdf_fails_closed(): void
    {
        $pdf = base_path('docs/evidence/p3-07/samples/complete/report.pdf');
        $validator = new IntelligenceArtifactFormatValidator;

        $this->assertNull($validator->validate('pdf', $pdf));

        config(['services.hermes_intelligence_artifact.qpdf' => storage_path('missing-qpdf-for-test')]);

        $this->assertSame('pdf_validation_failed', $validator->validate('pdf', $pdf));
    }

    public function test_missing_python_and_unconfigured_executables_fail_closed(): void
    {
        $pdf = base_path('docs/evidence/p3-07/samples/complete/report.pdf');
        $validator = new IntelligenceArtifactFormatValidator;
        $configuredPython = config('services.hermes_intelligence_artifact.python');

        config(['services.hermes_intelligence_artifact.python' => storage_path('missing-python-for-test')]);
        $this->assertSame('pdf_validation_failed', $validator->validate('pdf', $pdf));

        config(['services.hermes_intelligence_artifact.python' => '']);
        $this->assertSame('pdf_validation_failed', $validator->validate('pdf', $pdf));

        config([
            'services.hermes_intelligence_artifact.python' => $configuredPython,
            'services.hermes_intelligence_artifact.qpdf' => null,
        ]);
        $this->assertSame('pdf_validation_failed', $validator->validate('pdf', $pdf));
    }

    public static function rejectedPdfs(): array
    {
        return [
            'compressed escaped JavaScript' => ['<< /S /Java#53cript /JS () >>', 'pdf_unsafe_content'],
            'compressed launch action' => ['<< /S /Launch /F (fixture.bin) >>', 'pdf_unsafe_content'],
            'compressed executable attachment metadata' => ['<< /Type /Filespec /F (fixture.exe) /EF << /F 5 0 R >> >>', 'pdf_unsafe_content'],
            'encrypted with blank user password' => [null, 'pdf_encrypted'],
            'malformed xref' => [null, 'pdf_validation_failed'],
        ];
    }

    #[DataProvider('rejectedPdfs')]
    public function test_pdf_rejections(?string $object, string $reason): void
    {
        $directory = sys_get_temp_dir().'/joyumkm-pdf-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory, 0700);
        $source = $directory.'/source.pdf';
        $output = $directory.'/output.pdf';

        try {
            file_put_contents($source, $this->pdf($object));
            if ($reason === 'pdf_validation_failed') {
                file_put_contents($output, preg_replace('/startxref\n\d+/', "startxref\n1", file_get_contents($source)));
            } else {
                $options = $object === null
                    ? ['--encrypt', '', 'fixture-owner-password', '256', '--']
                    : ['--object-streams=generate'];
                (new Process([config('services.hermes_intelligence_artifact.qpdf'), ...$options, $source, $output], timeout: 8))->mustRun();
                if ($object !== null) {
                    $this->assertStringContainsString('/ObjStm', file_get_contents($output));
                    $this->assertStringNotContainsString($object, file_get_contents($output));
                }
            }

            $this->assertSame($reason, (new IntelligenceArtifactFormatValidator)->validate('pdf', $output));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function pdf(?string $object): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R'.($object === null ? '' : ' /Fixture 4 0 R').' >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
        ];
        if ($object !== null) {
            $objects[] = $object;
        }
        if ($object !== null && str_contains($object, '/EF')) {
            $objects[] = "<< /Type /EmbeddedFile /Length 2 >>\nstream\nMZ\nendstream";
        }
        $pdf = "%PDF-1.7\n";
        $xref = "0000000000 65535 f \n";
        foreach ($objects as $index => $value) {
            $xref .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1)." 0 obj\n".$value."\nendobj\n";
        }
        $size = count($objects) + 1;

        return $pdf."xref\n0 $size\n".$xref."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n".strlen($pdf)."\n%%EOF\n";
    }
}
