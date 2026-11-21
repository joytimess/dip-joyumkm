@php
    $listQuery = request()->query('list');
    $listQuery = is_string($listQuery) ? $listQuery : '';
    $parentParameters = $listQuery === '' ? [] : ['list' => $listQuery];
@endphp
<nav class="workspace-breadcrumb" aria-label="Breadcrumb" @if($dynamic ?? false) data-context-breadcrumb hidden @endif>
    <ol>
        <li><a href="{{ route('umkms') }}{{ $listQuery === '' ? '' : '?'.$listQuery }}">Data UMKM</a></li>
        @if(isset($detailId) || ($dynamic ?? false))
            <li><a data-context-umkm href="{{ isset($detailId) ? route('umkms.show', ['id' => $detailId] + $parentParameters) : route('umkms') }}">Detail UMKM</a></li>
        @endif
        <li aria-current="page">{{ $current }}</li>
    </ol>
</nav>
