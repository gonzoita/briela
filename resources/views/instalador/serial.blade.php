@extends('instalador.layout', ['paso' => 1])

@section('contenido')
    <h1>Serial de licencia</h1>
    <p class="sub">
        El código que te entregaron al comprar Briela. Sin uno válido, la
        instalación no puede continuar.
    </p>

    <form method="POST" action="/instalar">
        @csrf

        <label for="serial">Serial</label>
        <input type="text" id="serial" name="serial" value="{{ old('serial') }}" required autofocus autocomplete="off">
        <p class="ayuda">Si no lo tienes a mano, contacta a quien te vendió Briela.</p>

        <button type="submit">Validar y continuar</button>
    </form>
@endsection
