<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{{ $formulario->titulo_formulario }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  @if($formulario->captcha_activo && $siteKey)
  <script src="https://www.google.com/recaptcha/api.js?render={{ $siteKey }}"></script>
  @endif
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-lg w-full max-w-md p-6">

    <div class="mb-5 flex items-center gap-3">
      <img src="https://interfrigo.com.co/wp-content/uploads/2024/11/cropped-Diseno-sin-titulo-15.png"
           alt="Interfrigo" class="h-10 object-contain">
    </div>

    <div class="mb-5">
      <h2 class="text-xl font-bold text-gray-800">{{ $formulario->titulo_formulario }}</h2>
      @if($formulario->descripcion_formulario)
        <p class="text-sm text-gray-500 mt-1">{{ $formulario->descripcion_formulario }}</p>
      @endif
    </div>

    @if(session('success'))
      <div class="bg-green-50 border border-green-200 text-green-700 rounded-xl p-4 text-sm">
        ✓ {{ session('success') }}
      </div>
    @else
      @if ($errors->has('captcha'))
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3 text-sm mb-4">
          {{ $errors->first('captcha') }}
        </div>
      @endif

      <form method="POST" action="/f/{{ $formulario->slug }}" class="space-y-4" id="sgi-form">
        @csrf
        @if($formulario->captcha_activo && $siteKey)
          <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response"/>
        @endif

        @foreach($formulario->campos as $campo)
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              {{ $campo['etiqueta'] }}
              @if($campo['requerido'] ?? false)
                <span class="text-red-500">*</span>
              @endif
            </label>
            @if($campo['tipo'] === 'textarea')
              <textarea
                name="{{ $campo['nombre'] }}"
                rows="3"
                {{ ($campo['requerido'] ?? false) ? 'required' : '' }}
                class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
              ></textarea>
            @elseif($campo['tipo'] === 'select' && !empty($campo['opciones']))
              <select
                name="{{ $campo['nombre'] }}"
                {{ ($campo['requerido'] ?? false) ? 'required' : '' }}
                class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none bg-white"
              >
                <option value="">Seleccionar...</option>
                @foreach($campo['opciones'] as $opcion)
                  <option value="{{ $opcion }}">{{ $opcion }}</option>
                @endforeach
              </select>
            @else
              <input
                type="{{ $campo['tipo'] }}"
                name="{{ $campo['nombre'] }}"
                {{ ($campo['requerido'] ?? false) ? 'required' : '' }}
                class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
              />
            @endif
            @error($campo['nombre'])
              <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
            @enderror
          </div>
        @endforeach

        {{-- Sin captcha: el onclick deshabilita directamente --}}
        @if(!$formulario->captcha_activo || !$siteKey)
          <button type="submit" id="btn-submit"
            onclick="this.disabled=true; this.innerText='Enviando...'; this.form.submit();"
            class="w-full py-3 rounded-xl text-white font-semibold text-sm"
            style="background-color: #0A4283;">
            {{ $formulario->texto_boton }}
          </button>
        @else
          <button type="submit" id="btn-submit"
            class="w-full py-3 rounded-xl text-white font-semibold text-sm"
            style="background-color: #0A4283;">
            {{ $formulario->texto_boton }}
          </button>
        @endif
      </form>
    @endif

    <p class="text-center text-xs text-gray-400 mt-5">Powered by SGI Interfrigo</p>
  </div>

  @if($formulario->captcha_activo && $siteKey)
  <script>
    document.getElementById('sgi-form').addEventListener('submit', function(e) {
      e.preventDefault();
      var form = this;
      var btn  = document.getElementById('btn-submit');
      btn.disabled  = true;
      btn.innerText = 'Enviando...';
      grecaptcha.ready(function() {
        grecaptcha.execute('{{ $siteKey }}', { action: 'submit' }).then(function(token) {
          document.getElementById('g-recaptcha-response').value = token;
          form.submit();
        });
      });
    });
  </script>
  @endif
  <script>
    function notificarAltura() {
      var altura = document.body.scrollHeight;
      window.parent.postMessage({ tipo: 'sgi-form-altura', altura: altura }, '*');
    }
    window.addEventListener('load', notificarAltura);
    new MutationObserver(notificarAltura).observe(document.body, {
      childList: true, subtree: true, attributes: true
    });
  </script>
</body>
</html>
