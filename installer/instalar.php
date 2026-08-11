<?php
/**
 * Briela — instalador
 * =============================================================================
 *
 * Un solo archivo. Se sube a la carpeta pública del dominio y se abre en el
 * navegador. Descarga Briela, la descomprime y le pasa el turno al asistente de
 * configuración.
 *
 * Cómo usarlo:
 *   1. Sube este archivo a la carpeta que sirve tu dominio (la que tiene que
 *      apuntar a `public`).
 *   2. Ábrelo en el navegador: https://tu-dominio/instalar.php
 *   3. Escribe tu código de instalación y sigue los pasos.
 *
 * No necesita composer, ni Node, ni acceso por consola.
 *
 * Por qué es PHP puro y no parte del sistema: cuando esto corre, Briela todavía
 * no está en el servidor. No hay framework, ni base de datos, ni configuración.
 */

declare(strict_types=1);

// ─── Ajustes ────────────────────────────────────────────────────────────────

/** De dónde se descarga el paquete. El código se agrega como parámetro. */
const ORIGEN = 'https://briela.app/descargas/';

/** Archivos por tanda al descomprimir. Bajarlo si el hosting corta la petición. */
const POR_TANDA = 600;

const VERSION_MINIMA_PHP = '8.3.0';

// ─── Preparación ────────────────────────────────────────────────────────────

@set_time_limit(0);
@ini_set('memory_limit', '512M');
session_start();

/** Raíz de la instalación: la carpeta que contiene a esta (public/..). */
$raiz    = dirname(__DIR__) === __DIR__ ? __DIR__ : dirname(__DIR__);
$zipRuta = $raiz . '/briela-paquete.zip';
$estado  = $raiz . '/briela-instalador-estado.json';

$_SESSION['token'] ??= bin2hex(random_bytes(16));
$paso = $_GET['paso'] ?? 'inicio';

// Si Briela ya está instalada, este archivo no debe poder hacer nada.
if (file_exists($raiz . '/artisan') && file_exists($raiz . '/storage/app/instalada.json')) {
    pantalla('Ya está instalada', '
        <p class="sub">Briela ya está funcionando en este servidor.</p>
        <div class="caja">Por seguridad, borra <code>instalar.php</code> del servidor.</div>
        <a class="boton" href="/login">Ir al sistema</a>');
}

// ─── Acciones ───────────────────────────────────────────────────────────────

if ($paso === 'descargar' && esPost()) {
    verificarToken();
    responderJson(descargar($_POST['codigo'] ?? '', $zipRuta, $estado));
}

if ($paso === 'extraer' && esPost()) {
    verificarToken();
    responderJson(extraerTanda($zipRuta, $raiz, $estado));
}

if ($paso === 'terminar' && esPost()) {
    verificarToken();

    if ($error = prepararEnv($raiz, (string) ($_POST['codigo'] ?? ''))) {
        responderJson(['ok' => false, 'mensaje' => $error]);
    }

    @unlink($zipRuta);
    @unlink($estado);
    @unlink(__FILE__); // este archivo no debe quedarse en un servidor público
    responderJson(['ok' => true]);
}

// ─── Pantalla principal ─────────────────────────────────────────────────────

$requisitos = requisitos($raiz);
$puedeSeguir = ! array_filter($requisitos, fn ($r) => $r['critico'] && ! $r['ok']);

$listaHtml = '';
foreach ($requisitos as $r) {
    $clase = $r['ok'] ? 'si' : ($r['critico'] ? 'no' : 'aviso');
    $listaHtml .= '<li><span class="icono ' . $clase . '">' . ($r['ok'] ? '&#10003;' : '!') . '</span>'
        . '<span><span class="nombre">' . e($r['nombre']) . '</span>'
        . '<span class="detalle">' . e($r['detalle']) . '</span></span></li>';
}

// Se muestra la carpeta destino porque el paquete se descomprime en la carpeta
// que CONTIENE a esta. Si el archivo se sube al sitio equivocado, Briela
// terminaría encima de otro sitio: verlo antes de empezar evita el accidente.
$ajenos = archivosAjenos($raiz);
$avisoRuta = '<div class="caja' . ($ajenos > 3 ? ' mal' : '') . '">'
    . 'Briela se instalará en:<br><code>' . e($raiz) . '</code>'
    . ($ajenos > 3
        ? '<br><br><strong>Ojo:</strong> esa carpeta ya tiene ' . $ajenos . ' archivos.'
          . ' Comprueba que sea la carpeta correcta y que el dominio apunte a'
          . ' <code>' . e(basename(__DIR__)) . '</code> dentro de ella.'
        : '')
    . '</div>';

$formulario = $puedeSeguir
    ? $avisoRuta . '
    <label for="codigo">Código de instalación</label>
    <input type="text" id="codigo" name="codigo" autocomplete="off" spellcheck="false" placeholder="BRL-XXXX-XXXX-XXXX">
    <p class="ayuda">Es el código que te entregaron al contratar Briela.</p>
    <button type="button" id="empezar" onclick="empezar()">Instalar Briela</button>

    <div id="progreso" style="display:none">
        <div class="barra"><div id="relleno"></div></div>
        <p class="estado" id="mensaje">Preparando…</p>
    </div>'
    : '<div class="caja mal">Falta algo obligatorio. Corrígelo en el panel de tu hosting y recarga esta página.</div>
       <button type="button" onclick="location.reload()">Volver a revisar</button>';

pantalla('Instalar Briela', '
    <p class="sub">Esto descarga Briela y la deja lista para configurar. Tarda unos minutos.</p>
    <ul class="chequeo">' . $listaHtml . '</ul>
    ' . $formulario, $_SESSION['token']);

// ─── Lógica ─────────────────────────────────────────────────────────────────

/**
 * @return array<int, array{nombre:string, ok:bool, detalle:string, critico:bool}>
 */
function requisitos(string $raiz): array
{
    $r = [];

    $ok = version_compare(PHP_VERSION, VERSION_MINIMA_PHP, '>=');
    $r[] = ['nombre' => 'PHP ' . VERSION_MINIMA_PHP . ' o superior', 'ok' => $ok,
            'detalle' => 'Tienes PHP ' . PHP_VERSION, 'critico' => true];

    $r[] = ['nombre' => 'Extensión zip', 'ok' => class_exists('ZipArchive'),
            'detalle' => class_exists('ZipArchive') ? 'Disponible' : 'Sin ella no se puede descomprimir el paquete',
            'critico' => true];

    $salida = function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    $r[] = ['nombre' => 'Conexión a internet desde PHP', 'ok' => $salida,
            'detalle' => $salida ? 'Disponible' : 'El servidor no puede descargar archivos',
            'critico' => true];

    $escribible = is_writable($raiz);
    $r[] = ['nombre' => 'Permiso de escritura', 'ok' => $escribible,
            'detalle' => $escribible ? 'Se puede escribir en la carpeta' : 'El servidor no puede escribir en ' . $raiz,
            'critico' => true];

    foreach (['pdo_mysql', 'mbstring', 'openssl'] as $ext) {
        $r[] = ['nombre' => 'Extensión ' . $ext, 'ok' => extension_loaded($ext),
                'detalle' => extension_loaded($ext) ? 'Disponible' : 'No está instalada', 'critico' => true];
    }

    $libre = @disk_free_space($raiz);
    $suficiente = $libre === false || $libre > 300 * 1024 * 1024;
    $r[] = ['nombre' => 'Espacio en disco', 'ok' => $suficiente,
            'detalle' => $libre === false ? 'No se pudo comprobar' : round($libre / 1048576) . ' MB libres',
            'critico' => false];

    return $r;
}

/** @return array<string, mixed> */
function descargar(string $codigo, string $zipRuta, string $estado): array
{
    $codigo = trim($codigo);

    if ($codigo === '') {
        return ['ok' => false, 'mensaje' => 'Escribe tu código de instalación.'];
    }

    // Si el ZIP ya está subido a mano, se usa ese y no se descarga nada. Sirve
    // para servidores sin salida a internet.
    if (file_exists($zipRuta) && filesize($zipRuta) > 1048576) {
        @unlink($estado);

        return ['ok' => true, 'mensaje' => 'Paquete encontrado en el servidor.',
                'tamano' => filesize($zipRuta), 'descargado' => false];
    }

    $url = ORIGEN . 'briela.zip?codigo=' . urlencode($codigo);

    $destino = @fopen($zipRuta, 'wb');
    if (! $destino) {
        return ['ok' => false, 'mensaje' => 'No se pudo crear el archivo en el servidor. Revisa los permisos.'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $destino,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_USERAGENT      => 'Briela Installer',
        ]);
        $exito  = curl_exec($ch);
        $codigoHttp = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        fclose($destino);

        if (! $exito) {
            @unlink($zipRuta);

            return ['ok' => false, 'mensaje' => match (true) {
                $codigoHttp === 403, $codigoHttp === 401 => 'El código no es válido o no tiene una licencia activa.',
                $codigoHttp === 404                      => 'No se encontró el paquete en el servidor de Briela.',
                default                                  => 'Falló la descarga: ' . ($error ?: 'error ' . $codigoHttp),
            }];
        }
    } else {
        $origen = @fopen($url, 'rb');
        if (! $origen) {
            fclose($destino);
            @unlink($zipRuta);

            return ['ok' => false, 'mensaje' => 'No se pudo conectar con el servidor de Briela.'];
        }
        stream_copy_to_stream($origen, $destino);
        fclose($origen);
        fclose($destino);
    }

    if (! file_exists($zipRuta) || filesize($zipRuta) < 1048576) {
        @unlink($zipRuta);

        return ['ok' => false, 'mensaje' => 'El paquete llegó incompleto. Vuelve a intentarlo.'];
    }

    @unlink($estado);

    return ['ok' => true, 'mensaje' => 'Paquete descargado.', 'tamano' => filesize($zipRuta), 'descargado' => true];
}

/**
 * Descomprime una tanda de archivos y devuelve el avance.
 *
 * Va por tandas porque un paquete son más de 45.000 archivos: hacerlo de una vez
 * se pasa del tiempo máximo de ejecución de casi cualquier hosting compartido y
 * dejaría la instalación a medias.
 *
 * @return array<string, mixed>
 */
function extraerTanda(string $zipRuta, string $raiz, string $rutaEstado): array
{
    if (! file_exists($zipRuta)) {
        return ['ok' => false, 'mensaje' => 'No se encuentra el paquete. Vuelve a empezar.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipRuta) !== true) {
        return ['ok' => false, 'mensaje' => 'El paquete está dañado. Bórralo y vuelve a intentarlo.'];
    }

    $total = $zip->numFiles;
    $desde = 0;

    if (file_exists($rutaEstado)) {
        $guardado = json_decode((string) file_get_contents($rutaEstado), true);
        $desde    = (int) ($guardado['hechos'] ?? 0);
    }

    $hasta   = min($desde + POR_TANDA, $total);
    $nombres = [];

    for ($i = $desde; $i < $hasta; $i++) {
        $nombre = $zip->getNameIndex($i);
        if ($nombre === false) {
            continue;
        }
        // Nunca escribir fuera de la carpeta de instalación.
        if (str_contains($nombre, '..')) {
            continue;
        }
        $nombres[] = $nombre;
    }

    if ($nombres !== [] && ! $zip->extractTo($raiz, $nombres)) {
        $zip->close();

        return ['ok' => false, 'mensaje' => 'No se pudieron escribir los archivos. Revisa los permisos de la carpeta.'];
    }

    $zip->close();

    file_put_contents($rutaEstado, json_encode(['hechos' => $hasta, 'total' => $total]));

    return [
        'ok'         => true,
        'hechos'     => $hasta,
        'total'      => $total,
        'terminado'  => $hasta >= $total,
        'porcentaje' => $total > 0 ? (int) round($hasta / $total * 100) : 100,
    ];
}

/**
 * Deja el archivo de configuración listo para que Briela pueda arrancar.
 *
 * El paquete trae `.env.example` pero nunca un `.env`: la configuración es de
 * cada instalación, y una llave de cifrado compartida entre servidores permitiría
 * falsificar sesiones de uno en otro. Así que la llave se genera aquí, y es única.
 *
 * La sesión y la caché quedan en archivos, no en base de datos: cuando el
 * asistente de configuración arranca, la base todavía no tiene tablas.
 *
 * El código que se escribió al empezar se guarda como serial. Antes no se guardaba, y
 * el resultado era que el cliente escribía su serial, entraba, y el sistema le decía
 * que no tenía licencia: sin verificación y sin asistente de IA hasta que alguien lo
 * volviera a escribir en Ajustes. Nadie lo iba a adivinar.
 *
 * Solo se guarda si tiene forma de serial. Un texto cualquiera guardado ahí haría que
 * la instalación reporte un serial inexistente, que es peor que no tener ninguno.
 *
 * @return string|null  El motivo si algo falló, o null si quedó bien.
 */
function prepararEnv(string $raiz, string $codigo = ''): ?string
{
    $env     = $raiz . '/.env';
    $ejemplo = $raiz . '/.env.example';

    if (file_exists($env)) {
        return null; // ya configurada: no se toca
    }

    if (! file_exists($ejemplo)) {
        return 'El paquete llegó sin su archivo de configuración de ejemplo.';
    }

    $texto = (string) file_get_contents($ejemplo);

    $reemplazos = [
        'APP_KEY'           => 'base64:' . base64_encode(random_bytes(32)),
        'APP_URL'           => urlDelSitio(),
        'SESSION_DRIVER'    => 'file',
        'CACHE_STORE'       => 'file',
        'QUEUE_CONNECTION'  => 'sync',
    ];

    $codigo = strtoupper(trim($codigo));

    if (preg_match('/^BRL(-[A-Z0-9]{4}){3}$/', $codigo)) {
        $reemplazos['BRIELA_SERIAL'] = $codigo;
    }

    foreach ($reemplazos as $clave => $valor) {
        $patron = '/^' . preg_quote($clave, '/') . '=.*$/m';
        $texto  = preg_replace($patron, $clave . '=' . $valor, $texto, 1, $hechos);

        if (! $hechos) {
            $texto = rtrim((string) $texto) . "\n" . $clave . '=' . $valor . "\n";
        }
    }

    if (@file_put_contents($env, $texto) === false) {
        return 'No se pudo escribir el archivo de configuración. Revisa los permisos de la carpeta.';
    }

    return null;
}

/**
 * Cuántos archivos hay ya en la carpeta destino que no son de una instalación
 * a medias. Sirve para avisar si el instalador se subió al sitio equivocado.
 */
function archivosAjenos(string $raiz): int
{
    $propios = ['public', 'briela-paquete.zip', 'briela-instalador-estado.json',
                'artisan', 'app', 'vendor', 'bootstrap', 'config', 'database',
                'resources', 'routes', 'storage', '.env', '.env.example', 'version.txt'];

    $cuenta = 0;
    foreach ((array) @scandir($raiz) as $entrada) {
        if ($entrada === '.' || $entrada === '..' || in_array($entrada, $propios, true)) {
            continue;
        }
        $cuenta++;
    }

    return $cuenta;
}

function urlDelSitio(): string
{
    $seguro  = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    return ($seguro ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ─── Utilidades ─────────────────────────────────────────────────────────────

function esPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function verificarToken(): void
{
    $enviado = $_POST['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';

    if (! hash_equals($_SESSION['token'] ?? '', (string) $enviado)) {
        responderJson(['ok' => false, 'mensaje' => 'La página caducó. Recárgala y vuelve a intentar.'], 419);
    }
}

/** @param array<string, mixed> $datos */
function responderJson(array $datos, int $codigo = 200): never
{
    http_response_code(($datos['ok'] ?? true) ? $codigo : ($codigo === 200 ? 422 : $codigo));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function pantalla(string $titulo, string $contenido, ?string $token = null): never
{
    $tokenJs = $token !== null ? "const TOKEN = '" . e($token) . "';" : '';
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> — Briela</title>
<style>
    :root { --marca:#2563EB; --marca-oscuro:#1E4FBF; --texto:#111827; --suave:#6B7280; --linea:#E5E7EB; --ok:#059669; --mal:#DC2626; }
    *{box-sizing:border-box} body{margin:0;background:#F8F9FA;color:var(--texto);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.5}
    .envoltura{max-width:560px;margin:0 auto;padding:24px 16px 48px}
    .marca{text-align:center;margin-bottom:20px}
    .marca strong{font-size:22px;letter-spacing:-.4px;color:var(--marca)}
    .marca span{display:block;font-size:13px;color:var(--suave);margin-top:2px}
    .tarjeta{background:#fff;border:1px solid var(--linea);border-radius:14px;padding:22px 18px}
    h1{font-size:19px;margin:0 0 4px} .sub{color:var(--suave);font-size:14px;margin:0 0 18px}
    label{display:block;font-size:13px;font-weight:600;margin:14px 0 5px}
    input{width:100%;padding:11px 12px;border:1px solid var(--linea);border-radius:9px;font-size:15px;font-family:inherit;letter-spacing:.5px}
    input:focus{outline:2px solid var(--marca);outline-offset:-1px;border-color:var(--marca)}
    .ayuda{font-size:12px;color:var(--suave);margin-top:5px}
    button,.boton{display:block;width:100%;margin-top:20px;padding:13px;border:0;border-radius:10px;background:var(--marca);color:#fff;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;text-align:center;text-decoration:none}
    button:hover,.boton:hover{background:var(--marca-oscuro)} button[disabled]{background:#9CA3AF;cursor:not-allowed}
    ul.chequeo{list-style:none;margin:0;padding:0}
    ul.chequeo li{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid var(--linea)}
    ul.chequeo li:last-child{border-bottom:0}
    .icono{flex-shrink:0;width:18px;height:18px;border-radius:50%;color:#fff;font-size:12px;line-height:18px;text-align:center;font-weight:700}
    .icono.si{background:var(--ok)} .icono.no{background:var(--mal)} .icono.aviso{background:#D97706}
    .nombre{font-size:14px} .detalle{font-size:12px;color:var(--suave);display:block}
    .caja{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;border-radius:10px;padding:12px 14px;font-size:13px;margin:16px 0}
    .caja.mal{background:#FEF2F2;border-color:#FECACA;color:#991B1B}
    .barra{height:8px;background:var(--linea);border-radius:4px;overflow:hidden;margin-top:20px}
    #relleno{height:100%;width:0;background:var(--marca);transition:width .25s}
    .estado{font-size:13px;color:var(--suave);text-align:center;margin-top:10px}
    code{background:#F3F4F6;padding:2px 6px;border-radius:5px;font-size:12px}
    @media(min-width:640px){.envoltura{padding-top:56px}.tarjeta{padding:28px}}
</style>
</head>
<body>
<div class="envoltura">
    <div class="marca"><strong>Briela</strong><span>Instalador</span></div>
    <div class="tarjeta">
        <h1><?= e($titulo) ?></h1>
        <?= $contenido ?>
    </div>
</div>
<script>
<?= $tokenJs ?>

const boton  = document.getElementById('empezar');
const caja   = document.getElementById('progreso');
const relleno = document.getElementById('relleno');
const mensaje = document.getElementById('mensaje');

function decir(texto) { if (mensaje) mensaje.textContent = texto; }

function fallar(texto) {
    decir(texto);
    if (mensaje) mensaje.style.color = '#DC2626';
    if (boton) { boton.disabled = false; boton.textContent = 'Volver a intentar'; }
}

async function pedir(paso, datos) {
    const cuerpo = new FormData();
    cuerpo.append('token', TOKEN);
    for (const k in (datos || {})) cuerpo.append(k, datos[k]);

    const r = await fetch('?paso=' + paso, { method: 'POST', body: cuerpo, headers: { 'X-Token': TOKEN } });
    const j = await r.json().catch(() => ({ mensaje: 'El servidor respondió algo inesperado.' }));
    if (!j.ok) throw new Error(j.mensaje || 'Algo falló.');
    return j;
}

async function empezar() {
    const codigo = (document.getElementById('codigo') || {}).value || '';
    boton.disabled = true;
    caja.style.display = 'block';
    if (mensaje) mensaje.style.color = '';

    try {
        decir('Descargando Briela… esto puede tardar.');
        const d = await pedir('descargar', { codigo: codigo });
        decir(d.descargado === false ? 'Paquete encontrado en el servidor.' : 'Descarga lista.');

        let terminado = false;
        while (!terminado) {
            const e = await pedir('extraer', {});
            relleno.style.width = e.porcentaje + '%';
            decir('Instalando archivos… ' + e.porcentaje + '% (' + e.hechos + ' de ' + e.total + ')');
            terminado = e.terminado;
        }

        decir('Terminando…');
        // El código se manda otra vez: es el serial de esta instalación y hay que
        // guardarlo en la configuración. Sin esto, quien lo escribió arriba entra a un
        // sistema que cree no tener licencia.
        await pedir('terminar', { codigo: codigo });
        decir('Listo. Abriendo el asistente de configuración…');
        setTimeout(() => location.href = '/instalar', 1200);
    } catch (err) {
        fallar(err.message);
    }
}
</script>
</body>
</html><?php
    exit;
}
