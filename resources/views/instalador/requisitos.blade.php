@extends('instalador.layout', ['paso' => 1])

@section('contenido')
    <h1>Revisión del servidor</h1>
    <p class="sub">Antes de instalar, esto comprueba que el servidor tenga lo necesario.</p>

    @unless ($puedeSeguir)
        <div class="aviso-caja">
            Falta algo obligatorio. Corrígelo en el panel de tu hosting y recarga esta
            página. Si no sabes cómo, tu proveedor lo puede activar.
        </div>
    @endunless

    <ul class="chequeo">
        @foreach ($requisitos as $req)
            <li>
                <span class="icono {{ $req['ok'] ? 'si' : ($req['critico'] ? 'no' : 'aviso') }}">
                    {{ $req['ok'] ? '✓' : '!' }}
                </span>
                <span>
                    <span class="nombre">{{ $req['nombre'] }}</span>
                    <span class="detalle">{{ $req['detalle'] }}</span>
                </span>
            </li>
        @endforeach
    </ul>

    @if ($puedeSeguir)
        <form method="GET" action="/instalar/base-datos">
            <button type="submit">Continuar</button>
        </form>
    @else
        <button type="button" onclick="location.reload()">Volver a revisar</button>
    @endif
@endsection
