@extends('instalador.layout', ['paso' => 2])

@section('contenido')
    <h1>Preparando la base de datos</h1>
    <p class="sub">
        Se están creando las tablas del sistema. En un servidor cargado esto puede
        tardar un par de minutos: no cierres la ventana.
    </p>

    <div id="estado" class="aviso-caja neutro">Creando las tablas…</div>

    <form method="GET" action="/instalar/cuenta" id="seguir" style="display:none">
        <button type="submit">Continuar</button>
    </form>

    <button type="button" id="reintentar" style="display:none" onclick="arrancar()">Volver a intentar</button>

    {{--
        Las migraciones van por AJAX y no dentro del formulario anterior porque en
        un hosting compartido son decenas de segundos: una petición normal se
        cortaría a la mitad y dejaría la base incompleta.
    --}}
    <script>
        const caja    = document.getElementById('estado');
        const seguir  = document.getElementById('seguir');
        const reintentar = document.getElementById('reintentar');

        function arrancar() {
            caja.className = 'aviso-caja neutro';
            caja.textContent = 'Creando las tablas…';
            seguir.style.display = 'none';
            reintentar.style.display = 'none';

            fetch('/instalar/base-datos/migrar', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            })
            .then(async (r) => {
                const datos = await r.json().catch(() => ({ mensaje: 'El servidor respondió algo inesperado.' }));
                if (!r.ok || !datos.ok) throw new Error(datos.mensaje || 'Falló la preparación.');
                caja.textContent = datos.mensaje;
                seguir.style.display = 'block';
            })
            .catch((e) => {
                caja.className = 'aviso-caja';
                caja.textContent = e.message;
                reintentar.style.display = 'block';
            });
        }

        arrancar();
    </script>
@endsection
