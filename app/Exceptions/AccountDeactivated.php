<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Login ditolak karena akun dinonaktifkan; pesannya aman ditampilkan setelah kata sandi terverifikasi. */
class AccountDeactivated extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('Akun Anda dinonaktifkan. Hubungi Superadmin.');
    }
}
