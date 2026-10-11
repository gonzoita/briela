{{--
    Un bloque de firma: la imagen (si la persona la tiene guardada o se firmó el documento), la
    línea, el nombre y el cargo. Va en tabla y no en flex: dompdf no entiende flex.

    @param string|null $firma  data:image/...;base64 (ya validada al guardarla)
    @param string|null $nombre
    @param string|null $cargo
--}}
<div style="text-align:center;">
    <div style="height:56px;">
        @if(! empty($firma) && str_starts_with($firma, 'data:image/'))
            <img src="{{ $firma }}" alt="Firma" style="max-height:54px; max-width:190px;"/>
        @endif
    </div>
    <div style="border-top:1px solid #555; padding-top:4px; margin:0 12px; font-size:9px; color:#333;">
        <strong>{{ $nombre ?: '—' }}</strong>
        @if(! empty($cargo))<br><span style="color:#777;">{{ $cargo }}</span>@endif
    </div>
</div>
