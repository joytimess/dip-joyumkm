<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Database\ConnectionInterface;

/**
 * Konteks eksekusi yang hanya dibuat AnalyticsDatasetSeeder setelah lock dan transaksi aktif.
 * Sub-seeder memanggil assertWritable() sebelum menulis, sehingga tidak bisa dipakai sebagai jalur terpisah.
 */
final class AnalyticsDatasetRun
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $lockName,
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @throws AnalyticsDatasetException
     */
    public function assertWritable(): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw AnalyticsDatasetException::because('Penulisan dataset analitik harus berada dalam transaksi.');
        }

        $held = $this->connection->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS held', [$this->lockName]);
        if ((int) ($held->held ?? 0) !== 1) {
            throw AnalyticsDatasetException::because('Lock dataset analitik tidak dipegang oleh koneksi ini.');
        }
    }
}
