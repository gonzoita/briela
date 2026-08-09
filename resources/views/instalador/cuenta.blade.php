@extends('instalador.layout', ['paso' => 3])

@section('contenido')
    <h1>Tu empresa y tu cuenta</h1>
    <p class="sub">
        Con esto queda listo. El nombre y los datos de la empresa se pueden cambiar
        después en Configuración.
    </p>

    <form method="POST" action="/instalar/cuenta">
        @csrf

        <label for="empresa">Nombre de la empresa</label>
        <input type="text" id="empresa" name="empresa" value="{{ old('empresa') }}" required autofocus>
        <p class="ayuda">Aparece en la pestaña del navegador, en los PDF y en los portales públicos.</p>

        <label for="nombre">Tu nombre</label>
        <input type="text" id="nombre" name="nombre" value="{{ old('nombre') }}" required>

        <label for="email">Tu correo</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required>
        <p class="ayuda">Con este correo vas a entrar al sistema.</p>

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <p class="ayuda">Mínimo 8 caracteres.</p>

        <label for="password_confirmation">Repite la contraseña</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">

        <button type="submit">Terminar la instalación</button>
    </form>
@endsection
