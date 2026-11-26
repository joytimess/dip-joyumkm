<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsDatasetException;
use App\Services\Analytics\AnalyticsDatasetInvocation;
use Database\Seeders\AnalyticsDatasetSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Pembungkus aman untuk AnalyticsDatasetSeeder. Kata sandi diterima dari berkas, stdin, atau prompt interaktif,
 * lalu dijalankan melalui db:seed --class=AnalyticsDatasetSeeder --force. Kata sandi tidak pernah dicetak.
 */
#[Signature('analytics-dataset:seed {--password-file= : Berkas berisi kata sandi akun analitik (baris pertama dibaca, tidak dicetak)} {--password-stdin : Baca kata sandi dari stdin} {--reference-end= : Bulan akhir periode referensi YYYY-MM, hanya saat cohort baru dibuat}')]
#[Description('Buat atau verifikasi dataset analitik sintetis dengan kata sandi yang diberikan per eksekusi')]
class AnalyticsDatasetSeedCommand extends Command
{
    public function handle(): int
    {
        try {
            $secret = $this->resolveSecret();
            $referenceEnd = $this->option('reference-end');

            $this->laravel->instance(AnalyticsDatasetInvocation::class, new AnalyticsDatasetInvocation(
                $secret,
                is_string($referenceEnd) && $referenceEnd !== '' ? $referenceEnd : null,
            ));
        } catch (AnalyticsDatasetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->call('db:seed', [
            '--class' => AnalyticsDatasetSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    private function resolveSecret(): string
    {
        $file = $this->option('password-file');
        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                throw AnalyticsDatasetException::because('Berkas kata sandi tidak ditemukan atau tidak dapat dibaca.');
            }

            return $this->firstLine((string) file_get_contents($file));
        }

        if ($this->option('password-stdin')) {
            return $this->firstLine((string) stream_get_contents(STDIN));
        }

        if ($this->option('no-interaction')) {
            throw AnalyticsDatasetException::because('Mode noninteraktif memerlukan --password-file atau --password-stdin.');
        }

        return (string) $this->secret('Kata sandi akun analitik sintetis (minimal 12 karakter)');
    }

    private function firstLine(string $contents): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents);

        return is_array($lines) ? (string) ($lines[0] ?? '') : '';
    }
}
