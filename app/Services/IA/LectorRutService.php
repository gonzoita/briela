<?php

namespace App\Services\IA;

use App\Exceptions\IaException;
use App\Services\ConsultaNitService;
use App\Support\Fiscal;
use Illuminate\Http\UploadedFile;

/**
 * Lee un RUT —o una cámara de comercio, o una cédula— y devuelve los datos listos para
 * llenar la ficha de un cliente o el perfil de la empresa.
 *
 * **No guarda nada.** Devuelve lo que leyó y la pantalla lo pone en el formulario para que
 * una persona lo revise antes de guardar. La IA lee bien un RUT, pero un 7 que parece un 1
 * en una foto torcida es un NIT de otra empresa, y eso no se descubre hasta la factura.
 *
 * El dígito de verificación se comprueba con la misma cuenta que usa el resto del sistema:
 * si no cuadra con el NIT leído, se dice, porque es la señal de que algo se leyó mal.
 */
class LectorRutService
{
    public const TIPOS = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private IaService $ia,
        private ConsultaNitService $nit,
    ) {}

    /**
     * @return array{datos: array<string, mixed>, avisos: list<string>}
     */
    public function leer(UploadedFile $archivo): array
    {
        $mime = $archivo->getMimeType() ?: 'application/octet-stream';

        if (! in_array($mime, self::TIPOS, true)) {
            throw new IaException('El archivo tiene que ser un PDF o una foto (JPG, PNG o WEBP).');
        }

        $respuesta = $this->ia->leerDocumentos(
            'Lee este documento y devuelve los datos pedidos.',
            $this->instrucciones(),
            [['mime' => $mime, 'contenido' => (string) file_get_contents($archivo->getRealPath()), 'nombre' => $archivo->getClientOriginalName()]],
        );

        return $this->interpretar($respuesta);
    }

    /**
     * Convierte lo que devolvió la IA en los campos de Briela.
     *
     * Público para poder probarlo sin llamar a la IA.
     *
     * @return array{datos: array<string, mixed>, avisos: list<string>}
     */
    public function interpretar(string $respuesta): array
    {
        $json = $this->extraerJson($respuesta);

        if ($json === null) {
            throw new IaException('No se pudo entender lo que devolvió la IA al leer el documento. Intenta con un PDF o una foto más nítida.');
        }

        $avisos   = [];
        $esEmpresa = ($json['tipo_persona'] ?? '') !== 'natural';

        $numero = preg_replace('/\D/', '', (string) ($json['numero_identificacion'] ?? ''));
        $dvLeido = preg_replace('/\D/', '', (string) ($json['digito_verificacion'] ?? ''));

        // Hay lecturas que traen el NIT con el DV pegado: «9001234567». Solo en empresas,
        // cuyo NIT tiene nueve dígitos: una cédula de diez es una cédula de diez.
        if ($dvLeido === '' && $esEmpresa && strlen($numero) === 10 && $this->nit->calcularDv(substr($numero, 0, -1)) === substr($numero, -1)) {
            $dvLeido = substr($numero, -1);
            $numero  = substr($numero, 0, -1);
        }

        $dvCalculado = $numero !== '' ? $this->nit->calcularDv($numero) : null;

        if ($numero === '') {
            $avisos[] = 'No se encontró el número de identificación en el documento.';
        } elseif ($dvLeido !== '' && $dvCalculado !== null && $dvLeido !== $dvCalculado) {
            $avisos[] = "El dígito de verificación del documento ({$dvLeido}) no corresponde al NIT leído ({$numero}, que da {$dvCalculado}). Revisa el número: probablemente un dígito se leyó mal.";
        }

        $tipoDoc = strtoupper((string) ($json['tipo_documento'] ?? ''));
        $tipoIdentificacion = $esEmpresa
            ? 'NIT'
            : (in_array($tipoDoc, ['CC', 'CE', 'PA'], true) ? $tipoDoc : 'CC');

        $responsabilidades = Fiscal::codigos($json['responsabilidades'] ?? []);

        if ($responsabilidades === [] ) {
            $avisos[] = 'No se leyeron responsabilidades tributarias (casilla 53). Si el documento es un RUT, márcalas a mano.';
        }

        $ciiu = preg_replace('/\D/', '', (string) ($json['actividad_principal'] ?? ''));

        $datos = [
            'tipo'                       => $esEmpresa ? 'empresa' : 'persona',
            'tipo_identificacion'        => $tipoIdentificacion,
            'numero_identificacion'      => $numero,
            'digito_verificacion'        => $dvCalculado ?? ($dvLeido !== '' ? $dvLeido : null),
            'nombre'                     => $this->texto($esEmpresa ? ($json['razon_social'] ?? '') : ($json['nombres'] ?? '')),
            'apellido'                   => $esEmpresa ? '' : $this->texto($json['apellidos'] ?? ''),
            'email'                      => filter_var(trim((string) ($json['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
            'telefono'                   => mb_substr(preg_replace('/[^\d+ ]/', '', (string) ($json['telefono'] ?? '')), 0, 20),
            'ciudad'                     => $this->texto($json['ciudad'] ?? ''),
            'direccion'                  => mb_substr($this->texto($json['direccion'] ?? ''), 0, 200),
            'actividad_economica'        => $ciiu !== '' ? mb_substr($ciiu, 0, 10) : '',
            'responsabilidades_fiscales' => $responsabilidades,
            // Lo leído tal cual, para poder mirarlo después.
            'datos_rut' => array_merge($json, ['leido_el' => now()->toDateTimeString()]),
        ];

        return ['datos' => $datos, 'avisos' => $avisos];
    }

    private function instrucciones(): string
    {
        $codigos = collect(Fiscal::RESPONSABILIDADES)->map(fn ($n, $c) => "{$c} = {$n}")->implode('; ');

        return <<<TXT
        Eres un asistente que transcribe documentos tributarios colombianos. Lo normal es un RUT
        de la DIAN, pero también puede ser un certificado de cámara de comercio o una cédula.

        Responde SOLO un objeto JSON, sin texto antes ni después y sin bloques de código, con
        estas claves (usa "" o [] si un dato no aparece; NUNCA inventes un dato):

        {
          "tipo_persona": "juridica" o "natural",
          "tipo_documento": "NIT", "CC", "CE" o "PA",
          "numero_identificacion": solo dígitos, SIN el dígito de verificación,
          "digito_verificacion": el DV (casilla 6 del RUT), un dígito,
          "razon_social": razón social si es persona jurídica,
          "nombres": nombres si es persona natural,
          "apellidos": los dos apellidos si es persona natural,
          "direccion": dirección principal,
          "ciudad": municipio de la dirección principal,
          "departamento": departamento,
          "email": correo electrónico,
          "telefono": teléfono principal,
          "actividad_principal": código CIIU de 4 dígitos de la actividad principal,
          "responsabilidades": lista de los códigos de la casilla 53 «Responsabilidades,
            calidades y atributos», como texto de dos dígitos. Por ejemplo ["05","07","48","52"].
        }

        Algunos códigos frecuentes de la casilla 53, como referencia: {$codigos}.
        Transcribe los códigos que estén en el documento, aunque no estén en esta lista.
        TXT;
    }

    /** El JSON de la respuesta, aunque venga envuelto en ```json … ``` o con texto alrededor. */
    private function extraerJson(string $texto): ?array
    {
        $texto = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($texto)));

        $inicio = strpos($texto, '{');
        $fin    = strrpos($texto, '}');

        if ($inicio === false || $fin === false || $fin <= $inicio) {
            return null;
        }

        $json = json_decode(substr($texto, $inicio, $fin - $inicio + 1), true);

        return is_array($json) ? $json : null;
    }

    /** Texto limpio y en mayúscula inicial: los RUT vienen todos en MAYÚSCULAS. */
    private function texto(mixed $valor): string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $valor));

        if ($v === '') {
            return '';
        }

        // Solo se convierte si viene TODO en mayúsculas: «Acme S.A.S.» ya está bien escrito.
        if (mb_strtoupper($v) === $v) {
            $v = mb_convert_case(mb_strtolower($v), MB_CASE_TITLE, 'UTF-8');
            // Las siglas societarias sí van en mayúscula.
            $v = preg_replace_callback('/\b(S\.?a\.?s|S\.?a|Ltda|E\.?u|S\.?c\.?a|S\.?en\.?c)\b\.?/iu', fn ($m) => mb_strtoupper($m[0]), $v);
        }

        return mb_substr($v, 0, 200);
    }
}
