<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DatabaseEngine;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected $seed = true;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app['config']->get('database.default') !== 'mysql'
            || $app['config']->get('database.connections.mysql.database') !== 'joyumkm_testing') {
            throw new RuntimeException('Pengujian hanya boleh memakai database MySQL joyumkm_testing.');
        }

        return $app;
    }

    /**
     * Kode error CHECK berbeda antara MySQL (3819) dan MariaDB (4025); kode lain sama di kedua engine.
     */
    protected function databaseErrorCode(int $mysqlCode): int
    {
        return $mysqlCode === DatabaseEngine::MYSQL_CHECK_VIOLATION && DatabaseEngine::isMariaDb(DB::connection())
            ? DatabaseEngine::MARIADB_CHECK_VIOLATION
            : $mysqlCode;
    }

    /**
     * @return array<string, string> nama CHECK => status enforced. MariaDB selalu menegakkan CHECK dan tidak memiliki kolom ENFORCED.
     */
    protected function checkConstraintEnforcement(string $table): array
    {
        $checks = DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::scalar('SELECT DATABASE()'))
            ->where('table_name', $table)
            ->where('constraint_type', 'CHECK')
            ->orderBy('CONSTRAINT_NAME');

        if (DatabaseEngine::isMariaDb(DB::connection())) {
            return $checks->pluck('CONSTRAINT_NAME')
                ->mapWithKeys(fn (string $name): array => [$name => 'YES'])->all();
        }

        return $checks->pluck('ENFORCED', 'CONSTRAINT_NAME')->all();
    }

    /**
     * MariaDB melaporkan lebar tampilan integer (`tinyint(3) unsigned`); MySQL 8.4 tidak melaporkannya.
     */
    protected function withoutDisplayWidth(string $type): string
    {
        return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
    }

    /**
     * MariaDB menyimpan default string sebagai literal SQL berkutip (`'draft'`); MySQL mengembalikan nilainya.
     */
    protected function columnDefault(?string $default): ?string
    {
        if ($default === null || ! DatabaseEngine::isMariaDb(DB::connection())) {
            return $default;
        }

        if (strlen($default) >= 2 && str_starts_with($default, "'") && str_ends_with($default, "'")) {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }
}
