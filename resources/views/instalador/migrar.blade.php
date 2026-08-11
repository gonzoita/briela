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
        Las migraciones van por AJAX y de a pocas por petición.

        De una sola vez se pasan del tiempo máximo en un hosting compartido cargado, y
        el navegador recibe una respuesta cortada: error rojo con la base a medio
        construir. Pasó en la instalación propia. Cada llamada aplica unas cuantas y la
        siguiente sigue donde quedó; volver a llamar es seguro porque las ya aplicadas
        quedan registradas y no se repiten.
    --}}
    <script>
        const caja       = document.getElementById('estado');
        const seguir     = document.getElementById('seguir');
        const reintentar = document.getElementById('reintentar');

        async function unaTanda() {
            const r = await fetch('/instalar/base-datos/migrar', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });

            const datos = await r.json().catch(() => ({ mensaje: 'El servidor respondió algo inesperado.' }));

            if (!r.ok || !datos.ok) throw new Error(datos.mensaje || 'Falló la preparación.');

            return datos;
        }

        async function arrancar() {
            caja.className = 'aviso-caja neutro';
            caja.textContent = 'Creando las tablas…';
            seguir.style.display = 'none';
            reintentar.style.display = 'none';

            try {
                let datos;
                let hechas = 0;

                do {
                    datos = await unaTanda();

                    if (!datos.listo) {
                        hechas += datos.hechas;
                        const total = hechas + datos.quedan;
                        caja.textContent = 'Creando las tablas… ' + hechas + ' de ' + total;
                    }
                } while (!datos.listo);

                caja.textContent = datos.mensaje;

                // Un aviso no detiene la instalación, pero tiene que verse: si no se
                // pudo crear el enlace de archivos, las imágenes se van a ver rotas y
                // nadie va a saber por qué.
                if (datos.aviso) {
                    const av = document.createElement('div');
                    av.className = 'aviso-caja';
                    av.textContent = datos.aviso;
                    caja.after(av);
                }

                seguir.style.display = 'block';
            } catch (e) {
                caja.className = 'aviso-caja';
                caja.textContent = e.message;
                reintentar.style.display = 'block';
            }
        }

        arrancar();
    </script>
@endsection
