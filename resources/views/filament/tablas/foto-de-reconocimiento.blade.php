@php($url = \App\Filament\RelationManagers\FotosDeReconocimientoRelationManager::enlace($getRecord()))
@if ($url)
    <a href="{{ $url }}" target="_blank" rel="noopener" title="Verla en grande">
        <img src="{{ $url }}" alt="Foto de reconocimiento" style="height:4.5rem;width:auto;max-width:9rem;object-fit:contain;border-radius:.4rem;background:#f4f4f5">
    </a>
@else
    <span style="color:#9ca3af;font-size:.8rem">Ya no está en el servidor</span>
@endif
