<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use PDO;

/**
 * Deteksi engine server yang sebenarnya dan ekspresi SQL yang diterima MySQL 8.4 maupun MariaDB 11.4.
 *
 * Deteksi memakai versi yang dilaporkan server melalui PDO, bukan nama connection: connection `mysql`
 * dapat menunjuk ke MariaDB, dan connection `mariadb` dapat menunjuk ke server lain.
 */
final class DatabaseEngine
{
    public const MYSQL_CHECK_VIOLATION = 3819;

    public const MARIADB_CHECK_VIOLATION = 4025;

    public static function isMariaDb(Connection $connection): bool
    {
        $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) && str_contains(strtolower($version), 'mariadb');
    }

    /**
     * Predikat CHECK bahwa kolom berisi digest heksadesimal huruf kecil dengan panjang tetap.
     *
     * REGEXP_LIKE dengan mode 'c' hanya tersedia di MySQL. MariaDB menolaknya di CHECK (error 1901),
     * sehingga memakai REGEXP atas nilai biner: pencocokan tetap peka huruf besar-kecil di kedua engine.
     */
    public static function hexDigestCheck(bool $mariaDb, string $column, int $length): string
    {
        $pattern = "'^[0-9a-f]{{$length}}$'";

        return $mariaDb
            ? "CAST({$column} AS BINARY) REGEXP {$pattern}"
            : "REGEXP_LIKE({$column}, {$pattern}, 'c')";
    }
}
