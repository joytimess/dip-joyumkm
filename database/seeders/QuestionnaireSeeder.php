<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Factor;
use Illuminate\Database\Seeder;

class QuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $radioOptions = [
            ['value' => 1, 'label' => 'Tidak'],
            ['value' => 2, 'label' => 'Dalam Proses'],
            ['value' => 3, 'label' => 'Ya'],
        ];
        $likertOptions = [
            ['value' => 1, 'label' => 'Sangat Tidak Setuju'],
            ['value' => 2, 'label' => 'Tidak Setuju'],
            ['value' => 3, 'label' => 'Netral'],
            ['value' => 4, 'label' => 'Setuju'],
            ['value' => 5, 'label' => 'Sangat Setuju'],
        ];
        $definitions = [
            [
                'code' => 'INS',
                'name' => 'Sumber Daya (INS) — Institutional Resources',
                'description' => 'Ketersediaan sumber daya kelembagaan dan dukungan eksternal untuk bisnis.',
                'color' => '#dc2626',
                'scale_min' => 1,
                'scale_max' => 3,
                'sort_order' => 1,
                'input_type' => 'labeled_radio',
                'questions' => [
                    'Apakah bisnis Anda memiliki Nomor Induk Berusaha (NIB)?',
                    'Apakah Anda pernah menerima bantuan pendanaan dari pemerintah?',
                    'Apakah Anda pernah mengikuti kegiatan yang diselenggarakan pemerintah?',
                    'Apakah Anda berkolaborasi dengan pedagang atau bisnis lain?',
                    'Apakah Anda menggunakan pemasaran digital untuk mempromosikan bisnis?',
                    'Apa sumber modal keuangan utama bisnis Anda?',
                ],
            ],
            [
                'code' => 'ECT',
                'name' => 'Eksternal (ECT) — Economic Connectivity',
                'description' => 'Kinerja ekonomi dan konektivitas bisnis dengan pasar eksternal.',
                'color' => '#0d9488',
                'scale_min' => 1,
                'scale_max' => 5,
                'sort_order' => 2,
                'input_type' => 'likert',
                'questions' => [
                    'Kemampuan bisnis kami menjangkau pasar atau pelanggan baru sangat baik',
                    'Pertumbuhan penjualan bisnis kami selama setahun terakhir sangat memuaskan',
                    'Liabilitas bisnis kami (pinjaman, utang) sangat dapat dikelola',
                    'Situasi arus kas bisnis kami sangat sehat dan stabil',
                ],
            ],
            [
                'code' => 'OPS',
                'name' => 'Operasional (OPS) — Operational Stability',
                'description' => 'Stabilitas operasional dan kemampuan bisnis mengelola proses sehari-hari.',
                'color' => '#ea580c',
                'scale_min' => 1,
                'scale_max' => 5,
                'sort_order' => 3,
                'input_type' => 'likert',
                'questions' => [
                    'Modal kerja mencukupi untuk operasional bisnis sehari-hari',
                    'Perusahaan mampu membayar gaji karyawan tepat waktu',
                    'Peralatan/mesin dalam kondisi baik dan berfungsi optimal',
                    'Rantai pasokan bisnis berjalan lancar tanpa gangguan signifikan',
                    'Permintaan produk/jasa dapat diprediksi dengan baik',
                    'Kualitas hubungan jangka panjang bisnis dengan pelanggan sangat baik',
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $questions = $definition['questions'];
            $inputType = $definition['input_type'];
            unset($definition['questions'], $definition['input_type']);
            $factor = Factor::query()->firstOrCreate(['code' => $definition['code']], $definition);

            foreach ($questions as $index => $text) {
                $options = $inputType === 'likert' ? $likertOptions : $radioOptions;
                if ($factor->code === 'INS' && $index === 5) {
                    $options = [
                        ['value' => 1, 'label' => 'Modal Sendiri'],
                        ['value' => 2, 'label' => 'Keluarga'],
                        ['value' => 3, 'label' => 'Bank/Kredit'],
                    ];
                }

                $factor->questions()->firstOrCreate(['sort_order' => $index + 1], [
                    'text' => $text,
                    'input_type' => $inputType,
                    'options' => $options,
                    'is_active' => true,
                ]);
            }
        }
    }
}
