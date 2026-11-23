<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class UmkmProfilePagesTest extends TestCase
{
    public function test_list_modal_renders_profile_fields_with_profile_labels(): void
    {
        $this->get('/umkms')->assertOk()
            ->assertSee('Modal Usaha (Rp)')
            ->assertSee('Tidak termasuk tanah dan bangunan tempat usaha')
            ->assertSee('Periode Omzet')
            ->assertSee('Tahun Mulai Usaha')
            ->assertSee('Jumlah Tenaga Kerja')
            ->assertSee('Identitas Usaha')
            ->assertSee('Profil Ekonomi & Operasional')
            ->assertDontSee('Tahun berdiri')
            ->assertDontSee('Jumlah karyawan');
    }

    public function test_detail_page_renders_economic_profile_labels(): void
    {
        $this->get('/umkms/1')->assertOk()
            ->assertSee('Profil Ekonomi & Operasional')
            ->assertSee('Modal Usaha')
            ->assertSee('Tahun Mulai Usaha')
            ->assertSee('Jumlah Tenaga Kerja')
            ->assertSee('Modal Usaha (Rp)')
            ->assertSee('Periode Omzet')
            ->assertDontSee('Tahun berdiri')
            ->assertDontSee('Jumlah karyawan');
    }
}
