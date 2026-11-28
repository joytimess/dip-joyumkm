@extends('layouts.app', ['page' => 'admin-transfers', 'title' => 'Riwayat Transfer Kepemilikan'])
@section('content')
<div class="page">
    <div class="page-heading">
        <div>
            <h1>Riwayat Transfer Kepemilikan</h1>
            <p class="muted">Catatan permanen pemindahan UMKM antar Analyst. Riwayat tidak dapat diubah atau dihapus. Alokasi awal data contoh bukan transfer dan tidak tercatat di sini.</p>
        </div>
        <a href="{{ route('umkms') }}" class="btn button button-outline">Pilih UMKM untuk dipindahkan</a>
    </div>
    <section class="card card-pad panel" aria-labelledby="transfer-list-title">
        <h2 id="transfer-list-title" class="sr-only">Daftar transfer</h2>
        <div id="transfer-state" aria-live="polite" aria-busy="true"></div>
        <div id="transfer-pager" class="home-six-pager" hidden>
            <span id="transfer-count" class="muted"></span>
            <div>
                <button id="transfer-prev" type="button" class="button button-outline">← Sebelumnya</button>
                <button id="transfer-next" type="button" class="button button-outline">Berikutnya →</button>
            </div>
        </div>
    </section>
</div>
@endsection
