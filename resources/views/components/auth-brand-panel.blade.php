@props(['register' => false])
<aside class="auth-side" aria-label="Ruang kerja pendamping UMKM">
    <x-public-brand light />
    <div class="auth-brand-story">
        <span class="story-kicker">{{ $register ? 'MULAI DARI PEMAHAMAN' : 'RUANG KERJA PENDAMPING UMKM' }}</span>
        <h2>{{ $register ? 'Langkah kecil.' : 'Pahami usahanya.' }}<br>{{ $register ? 'Dampak lebih berarti.' : 'Dampingi langkahnya.' }}</h2>
        <p>{{ $register ? 'Bangun ruang kerja pendampingan yang rapi. Kenali kondisi setiap UMKM dan temukan fokus tindakan berikutnya.' : 'Akses data, evaluasi, dan insight UMKM dampingan Anda dalam satu dashboard yang terarah.' }}</p>
        <div class="auth-insight" aria-label="{{ $register ? 'Ilustrasi alur pendampingan' : 'Ilustrasi skor dimensi' }}">
            <div class="insight-header"><b>{{ $register ? 'Alur pendampingan' : 'Gambaran tiga dimensi' }}</b><span>{{ $register ? '3 langkah' : 'Data ilustrasi' }}</span></div>
            <div class="insight-row"><span>{{ $register ? 'Catat usaha' : 'Sumber Daya' }}</span><div class="track"><div class="fill" style="width:{{ $register ? 100 : 78 }}%"></div></div><strong>{{ $register ? '01' : '78' }}</strong></div>
            <div class="insight-row"><span>{{ $register ? 'Isi evaluasi' : 'Eksternal' }}</span><div class="track"><div class="fill secondary" style="width:{{ $register ? 76 : 62 }}%"></div></div><strong>{{ $register ? '02' : '62' }}</strong></div>
            <div class="insight-row"><span>{{ $register ? 'Baca insight' : 'Operasional' }}</span><div class="track"><div class="fill accent" style="width:{{ $register ? 54 : 86 }}%"></div></div><strong>{{ $register ? '03' : '86' }}</strong></div>
        </div>
    </div>
    <div class="auth-side-foot"><i aria-hidden="true"></i>{{ $register ? 'Satu akun untuk seluruh UMKM dampingan Anda' : 'Sumber Daya ? Eksternal ? Operasional' }}</div>
</aside>
