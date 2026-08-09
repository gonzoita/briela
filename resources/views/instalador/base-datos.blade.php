@extends('instalador.layout', ['paso' => 2])

@section('contenido')
    <h1>Base de datos</h1>
    <p class="sub">
        Los datos de la base que creaste en el panel de tu hosting. Briela solo se
        conecta: no la crea ni borra nada de lo que haya dentro.
    </p>

    <form method="POST" action="/instalar/base-datos">
        @csrf

        <div class="fila">
            <div>
                <label for="host">Servidor</label>
                <input type="text" id="host" name="host" value="{{ old('host', $valores['host']) }}" required>
            </div>
            <div style="max-width:110px">
                <label for="port">Puerto</label>
                <input type="text" id="port" name="port" value="{{ old('port', $valores['port']) }}" required>
            </div>
        </div>
        <p class="ayuda">Casi siempre <code>127.0.0.1</code> y <code>3306</code>.</p>

        <label for="database">Nombre de la base de datos</label>
        <input type="text" id="database" name="database" value="{{ old('database', $valores['database']) }}" required autofocus>

        <label for="username">Usuario</label>
        <input type="text" id="username" name="username" value="{{ old('username', $valores['username']) }}" required>

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" autocomplete="new-password">

        <button type="submit">Probar y continuar</button>
    </form>
@endsection
