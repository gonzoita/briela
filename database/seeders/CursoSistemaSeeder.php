<?php

namespace Database\Seeders;

use App\Models\Curso;
use App\Models\CursoEvaluacion;
use App\Models\CursoLeccion;
use App\Models\CursoModulo;
use App\Models\EvaluacionOpcion;
use App\Models\EvaluacionPregunta;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Curso de inducción "Cómo usar el sistema", dentro del propio sistema.
 *
 *     php artisan db:seed --class=CursoSistemaSeeder
 *
 * A diferencia del curso del SGI de Interfrigo, este está redactado en
 * términos GENÉRICOS: Briela se instala en empresas distintas, así que no
 * puede hablar de cuartos fríos, ni nombrar a una empresa concreta. Habla de
 * "productos fabricados a la medida" y de "la empresa".
 *
 * Es idempotente: al volver a correrlo reemplaza el contenido del curso sin
 * tocar las inscripciones ni el progreso de quien ya lo empezó.
 *
 * El contenido es HTML porque la vista del estudiante lo pinta con v-html
 * dentro de clases `prose`.
 */
class CursoSistemaSeeder extends Seeder
{
    private const TITULO = 'Cómo usar el sistema';

    public function run(): void
    {
        DB::transaction(function () {
            $curso = Curso::updateOrCreate(
                ['titulo' => self::TITULO],
                [
                    'descripcion'      => 'Recorrido completo del sistema, del primer ingreso hasta el despacho. '
                        . 'Está pensado para hacerlo en orden la primera semana en la empresa, '
                        . 'pero cada módulo se puede consultar suelto cuando haga falta.',
                    'categoria'        => 'Inducción',
                    'publico_objetivo' => 'colaborador',
                    'obligatorio'      => false,
                    'activo'           => true,
                    'puntos_otorga'    => 50,
                ]
            );

            $idsModulos = $curso->modulos()->pluck('id');

            $evaluaciones = CursoEvaluacion::where('curso_id', $curso->id)
                ->orWhereIn('curso_modulo_id', $idsModulos)
                ->get();

            foreach ($evaluaciones as $evaluacion) {
                foreach ($evaluacion->preguntas as $pregunta) {
                    $pregunta->opciones()->delete();
                    $pregunta->delete();
                }
                $evaluacion->delete();
            }

            CursoLeccion::whereIn('curso_modulo_id', $idsModulos)->delete();
            CursoModulo::whereIn('id', $idsModulos)->delete();

            foreach ($this->modulos() as $ordenModulo => $datosModulo) {
                $modulo = CursoModulo::create([
                    'curso_id' => $curso->id,
                    'nombre'   => $datosModulo['nombre'],
                    'orden'    => $ordenModulo + 1,
                ]);

                foreach ($datosModulo['lecciones'] as $ordenLeccion => $leccion) {
                    CursoLeccion::create([
                        'curso_modulo_id'  => $modulo->id,
                        'nombre'           => $leccion['nombre'],
                        'tipo'             => 'texto',
                        'contenido'        => $leccion['contenido'],
                        'duracion_minutos' => $leccion['minutos'],
                        'orden'            => $ordenLeccion + 1,
                    ]);
                }
            }

            $this->crearEvaluacionFinal($curso);
        });

        $this->command?->info('Curso "' . self::TITULO . '" cargado.');
    }

    private function modulos(): array
    {
        return [
            [
                'nombre'    => '1. Primeros pasos',
                'lecciones' => [
                    [
                        'nombre'  => 'Qué es el sistema y cómo entrar',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<p>Este sistema reúne toda la operación de la empresa en un solo lugar: los
clientes, las cotizaciones, las órdenes de producción, el inventario y los
despachos. Todo lo que antes vivía en cuadernos, archivos de Excel y en la
memoria de cada quien.</p>

<h3>Entrar</h3>
<p>Se entra con el correo y la contraseña que le asignaron. Funciona igual en
el computador y en el celular: está diseñado <em>primero</em> para el celular,
porque buena parte del trabajo pasa en planta y no frente a un escritorio.</p>

<h3>Instalarlo en el celular</h3>
<p>No está en Play Store ni en App Store. Se instala desde el navegador: abre
la página y, en el menú del navegador, elige <strong>"Agregar a pantalla de
inicio"</strong>. Queda con su ícono, como cualquier otra aplicación.</p>

<h3>Lo que ve cada persona</h3>
<p>El sistema no le muestra lo mismo a todo el mundo. Según su rol, usted ve
unos módulos y otros no. Si un compañero ve una opción que usted no tiene, no
es un error: es que su rol no la necesita. Si de verdad la necesita, se le
pide al administrador.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'La pantalla: qué hay en cada parte',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<p>La pantalla tiene tres zonas fijas que no cambian nunca, sin importar en qué
módulo esté.</p>

<h3>Arriba (el encabezado)</h3>
<ul>
  <li><strong>El buscador</strong>, en el centro. Busca en todo el sistema.</li>
  <li><strong>El selector de sede</strong>. Ponga cuidado a esto, tiene su propia lección.</li>
  <li><strong>La campanita</strong>, con los avisos que le llegan.</li>
  <li><strong>Su foto o inicial</strong>, a la derecha: ahí sale su perfil y el botón de salir.</li>
</ul>

<h3>Abajo (la barra de navegación, en celular)</h3>
<p>Cinco botones: <strong>Inicio</strong>, <strong>OPs</strong>, el botón redondo
de <strong>cámara</strong> en el centro, el lector de <strong>QR</strong> y
<strong>Perfil</strong>.</p>
<p>El botón de cámara y el de QR son los que más se usan en planta: uno para
tomar la foto de una evidencia, el otro para escanear el código de un trabajo
y entrar directo sin buscar nada.</p>

<h3>En el medio</h3>
<p>El contenido de donde esté parado. Es lo único que cambia al navegar.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'La sede activa (la lección que evita más confusiones)',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>Si la empresa tiene varias sedes, <strong>el sistema le muestra únicamente lo
de la sede que esté seleccionada arriba</strong>. Si el encabezado dice una
sede, usted no ve las órdenes de otra. Ni sus clientes, ni su inventario.</p>

<div style="border-left:4px solid #64748B;padding-left:12px;margin:16px 0">
<p><strong>Regla de oro:</strong> si algo "no aparece" —un cliente, una orden,
un producto que usted juraría que existe— <strong>revise la sede antes de
concluir que no está</strong>. Es, de lejos, la causa número uno de "el sistema
no lo encuentra".</p>
</div>

<h3>Cambiar de sede</h3>
<p>Se hace desde el selector del encabezado. Solo aparecen las sedes a las que
usted tenga acceso.</p>

<h3>Por qué funciona así</h3>
<p>Porque cada sede opera como una unidad: su numeración de documentos, su
inventario, su gente. Mezclarlo todo haría imposible saber qué produjo cada
planta. La separación es a propósito, no una limitación.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'El buscador y la campanita',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<h3>El buscador global</h3>
<p>Está siempre arriba. En el computador se abre también con
<strong>Ctrl + K</strong>; en el celular es la lupa. Busca <em>mientras
escribe</em>, sin oprimir nada.</p>
<p>Encuentra clientes, órdenes de producción, cotizaciones, remisiones,
productos, proveedores, órdenes de compra, oportunidades y usuarios. También
encuentra un <strong>número de serie</strong> y lo lleva a la orden de esa pieza.</p>
<p>Con las flechas ↑↓ se mueve entre resultados y con Enter abre el que esté
resaltado.</p>
<p>Dos cosas que conviene saber: solo aparece <strong>lo que usted tiene
permiso de ver</strong>, y solo lo de <strong>la sede activa</strong>.</p>

<h3>La campanita</h3>
<p>Ahí llegan los avisos del sistema: una cotización que sigue sin respuesta,
una entrega que se acerca, un insumo por debajo del mínimo, una orden que
quedó lista para calidad.</p>
<p>No es decorativa: <strong>es la forma en que el sistema le avisa que algo
necesita su atención</strong> sin que usted tenga que acordarse de revisar.
Vale la pena mirarla al empezar el día.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '2. Clientes y oportunidades',
                'lecciones' => [
                    [
                        'nombre'  => 'Crear y encontrar clientes',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>Todo empieza por el cliente: sin cliente no hay cotización, y sin cotización
no hay orden de producción.</p>

<h3>Crear un cliente</h3>
<p>En <strong>Clientes → Nuevo</strong>. Lo mínimo es el nombre y la
identificación. Si es empresa y pone el número de identificación tributaria, el
sistema puede calcular el dígito de verificación y traer los datos del registro
mercantil.</p>

<h3>La ficha del cliente</h3>
<p>Al entrar a un cliente no solo ve sus datos: abajo aparece
<strong>todo lo que tiene en el sistema</strong> — sus cotizaciones, sus
órdenes de producción, sus remisiones y sus oportunidades. Es la forma rápida
de responder "¿qué le hemos vendido a este cliente?".</p>

<h3>Contactos</h3>
<p>Un cliente empresa puede tener varios contactos (el de compras, el de obra).
Se agregan en su ficha y uno se marca como principal.</p>

<div style="border-left:4px solid #64748B;padding-left:12px;margin:16px 0">
<p><strong>Recuerde:</strong> el cliente queda en la <strong>sede</strong> donde
se creó. Si no lo encuentra, revise la sede antes de crearlo otra vez — crear
un cliente duplicado es peor que no encontrarlo.</p>
</div>
HTML,
                    ],
                    [
                        'nombre'  => 'El pipeline: seguirle el rastro a una venta',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>El <strong>CRM</strong> es el tablero donde se ven las oportunidades de venta
como tarjetas que avanzan por etapas: del primer contacto hasta ganada o
perdida.</p>

<h3>Para qué sirve de verdad</h3>
<p>Para que ninguna oportunidad se pierda por olvido. Una llamada que no quedó
anotada no existe; a los tres días nadie se acuerda.</p>

<h3>Cómo se usa</h3>
<ul>
  <li>Se crea una <strong>oportunidad</strong> cuando aparece un interesado.</li>
  <li>Se arrastra la tarjeta de etapa en etapa según avanza la conversación.</li>
  <li>Se anotan las <strong>actividades</strong> (llamadas, visitas, correos) y las <strong>tareas</strong> pendientes.</li>
</ul>

<h3>Lo que hace solo</h3>
<p>Al mover una oportunidad a la etapa de cotización, <strong>el sistema crea
solo un borrador de cotización</strong> ligado a ella. No hay que empezar de
cero ni volver a escribir los datos del cliente.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '3. Cotizar',
                'lecciones' => [
                    [
                        'nombre'  => 'Armar una cotización',
                        'minutos' => 8,
                        'contenido' => <<<'HTML'
<p>La cotización es la propuesta que se le manda al cliente. Se arma en
<strong>Cotizaciones → Nueva</strong>.</p>

<h3>Los tres pasos</h3>
<ol>
  <li><strong>El cliente.</strong> Se busca y se elige. Si viene de una oportunidad del CRM, ya está puesto.</li>
  <li><strong>Los ítems.</strong> Cada línea puede ser un producto del catálogo o un <strong>ensamble</strong> (algo que se fabrica a la medida).</li>
  <li><strong>Las condiciones.</strong> Validez, forma de pago, tiempo de entrega.</li>
</ol>

<h3>Productos y ensambles no son lo mismo</h3>
<p>Un <strong>producto</strong> tiene precio de lista: se elige y ya. Un
<strong>ensamble</strong> se calcula: usted pone las medidas y el sistema
calcula los materiales y el precio. Eso tiene su propia lección.</p>

<h3>Los precios y hasta dónde puede negociar</h3>
<p>El sistema maneja tres precios (mayorista, distribuidor y cliente final) y
un <strong>descuento máximo</strong> autorizado. No es un adorno: es el límite
por debajo del cual la venta deja de ser rentable.</p>
<p>La <strong>comisión del vendedor</strong> se calcula sobre lo que se vendió
<em>por encima del precio mayorista</em>, no sobre el total. Es decir: mientras
mejor negocie, más gana. Regalar descuento es regalar comisión propia.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'Ensambles: cuando el precio depende de las medidas',
                        'minutos' => 7,
                        'contenido' => <<<'HTML'
<p>Un producto fabricado a la medida no tiene precio de lista: depende de las
dimensiones, los materiales y los acabados que pida el cliente. Antes eso se
calculaba aparte y se pegaba el resultado a mano.</p>

<h3>Cómo funciona ahora</h3>
<p>Alguien (normalmente el administrador) creó una <strong>plantilla</strong>:
la receta de ese producto. La plantilla sabe qué preguntas hay que responder y
qué materiales consume según las respuestas.</p>
<p>Usted, al cotizar, solo <strong>llena las medidas</strong>. El sistema
calcula la lista de materiales, el costo y el precio.</p>

<h3>Lo que usted ve y lo que no</h3>
<p>La cotización que ve el cliente muestra lo que se decidió mostrarle —
normalmente el producto y su precio, no la lista completa de materiales. El
detalle queda guardado por dentro, para producción y para el inventario.</p>

<h3>Si algo se ve raro</h3>
<p>Si un ensamble da un precio que no tiene sentido, <strong>no lo corrija a
mano en la cotización</strong>: probablemente la receta tiene una fórmula mal
puesta y el error se va a repetir en todas las cotizaciones siguientes. Avise
para que se corrija la plantilla.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'Enviar la cotización y que el cliente la apruebe',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<h3>El PDF</h3>
<p>Desde la cotización se genera el PDF con el formato de la empresa, listo
para mandar.</p>

<h3>El enlace de aprobación</h3>
<p>Además del PDF, cada cotización tiene un <strong>enlace propio</strong> que
se le puede mandar al cliente. Ahí él ve la propuesta y puede
<strong>aprobarla o rechazarla</strong> con un clic, sin tener que entrar al
sistema ni tener usuario.</p>
<p>Cuando el cliente aprueba, el estado cambia solo y le llega el aviso al
vendedor. Se acabó el "¿ya le llegó?, ¿qué le pareció?".</p>

<h3>Estados</h3>
<p>La cotización pasa por <strong>borrador → enviada → aprobada</strong> (o
rechazada). Si se vence sin respuesta, el sistema la marca
<strong>vencida</strong> solo.</p>

<h3>Seguimiento</h3>
<p>Si una cotización lleva días sin respuesta, el sistema le avisa por la
campanita. Ese aviso es la diferencia entre cerrar la venta y enterarse un mes
después de que el cliente compró en otro lado.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '4. Producir',
                'lecciones' => [
                    [
                        'nombre'  => 'De la cotización a la orden de producción',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>Cuando el cliente aprueba, la cotización se convierte en una <strong>OP
(Orden de Producción)</strong>. Es el documento que manda en planta.</p>

<h3>Qué se hereda</h3>
<p>La OP nace con el cliente, los ítems, las medidas y los precios de la
cotización. No hay que volver a escribir nada, y por eso mismo <strong>no se
pueden inventar cosas nuevas en la OP</strong>: lo que se produce es lo que se
vendió.</p>

<h3>Los estados de una OP</h3>
<ol>
  <li><strong>Borrador</strong> — se está armando.</li>
  <li><strong>Confirmada</strong> — aprobada para producir.</li>
  <li><strong>En producción</strong> — ya hay avance real en planta.</li>
  <li><strong>Calidad</strong> — terminó de fabricarse y espera revisión.</li>
  <li><strong>Despachada</strong> — salió con su remisión.</li>
</ol>
<p>Si calidad rechaza, pasa a <strong>reproceso</strong>.</p>

<div style="border-left:4px solid #64748B;padding-left:12px;margin:16px 0">
<p><strong>Lo importante:</strong> usted casi nunca tiene que cambiar el estado
a mano. La OP <strong>se mueve sola</strong> según lo que pasa de verdad: si un
operario avanza pasos, pasa a "en producción"; si todos los ítems terminan,
pasa a "calidad". El estado refleja la realidad, no lo que alguien se acordó
de marcar.</p>
</div>
HTML,
                    ],
                    [
                        'nombre'  => 'Trabajos y pasos: el día a día del operario',
                        'minutos' => 8,
                        'contenido' => <<<'HTML'
<p>Un <strong>trabajo</strong> es la hoja de ruta de <strong>una unidad
física</strong>. Si la OP pide tres piezas iguales, el sistema crea
<strong>tres trabajos</strong>, no uno con cantidad tres. Cada pieza se arma y
se termina por separado, porque así es como pasa en planta.</p>

<h3>No hay que crearlos</h3>
<p>Los trabajos y sus pasos <strong>se generan solos</strong> cuando se crea el
ítem en la OP, copiados de la receta del ensamble. Nadie tiene que elegir nada.</p>

<h3>Entrar por QR</h3>
<p>Cada trabajo tiene su código QR. El operario lo escanea con el botón de QR
de la barra inferior y cae directo en la lista de pasos de <em>esa</em> unidad.
Sin buscar la OP, sin navegar menús.</p>
<p>Ojo: el QR es un atajo, <strong>no una puerta abierta</strong>. Hay que tener
la sesión iniciada.</p>

<h3>Completar un paso</h3>
<p>Al marcar un paso se puede registrar:</p>
<ul>
  <li><strong>Quiénes lo hicieron</strong> — pueden ser varios, cada uno con su tiempo.</li>
  <li><strong>Cuánto tomó.</strong></li>
  <li><strong>Fotos</strong> de evidencia.</li>
</ul>
<p>Algunos pasos <strong>dependen de otros</strong>: no se puede marcar un paso
si el anterior sigue pendiente. El sistema lo impide.</p>

<h3>Puntos</h3>
<p>Completar pasos <strong>da puntos</strong> según la dificultad, con bono si
se termina antes del tiempo estimado. Si un paso se desmarca porque se marcó
por error, los puntos se devuelven.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'Control de calidad',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<p>Cuando todos los ítems de una OP terminan, la orden pasa sola a
<strong>calidad</strong>.</p>

<h3>Qué se hace</h3>
<p>Se revisa la pieza y se registra el resultado con <strong>foto de
evidencia</strong> y observaciones.</p>
<ul>
  <li>Si <strong>aprueba</strong>: la OP queda habilitada para despacharse.</li>
  <li>Si <strong>rechaza</strong>: pasa a <strong>reproceso</strong>, con el motivo anotado.</li>
</ul>

<div style="border-left:4px solid #64748B;padding-left:12px;margin:16px 0">
<p><strong>Este es un candado real:</strong> una OP que no pasó por calidad
<strong>no se puede remisionar ni despachar</strong>. No es un aviso que se
pueda saltar. Es la garantía de que nada sale de la planta sin revisarse.</p>
</div>

<h3>Por qué la foto</h3>
<p>Porque cuando un cliente reclama tres semanas después, la foto del día del
despacho es la única prueba de en qué estado salió la pieza.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '5. Inventario y compras',
                'lecciones' => [
                    [
                        'nombre'  => 'Productos, insumos y existencias',
                        'minutos' => 7,
                        'contenido' => <<<'HTML'
<p>En el sistema, "producto" no significa solo lo que se vende. La misma ficha
sirve para el material que se compra, la pieza que se fabrica y el servicio que
se cobra. Lo que cambia es cómo está marcado:</p>
<ul>
  <li><strong>Vendible</strong> — puede ir en una cotización.</li>
  <li><strong>Insumo</strong> — puede usarse como material en una receta.</li>
  <li><strong>Inventariable</strong> — se le lleva stock. Un servicio no lo es.</li>
</ul>
<p>Uno puede ser varias cosas a la vez: un material se vende suelto y además se
consume dentro de un producto fabricado.</p>

<h3>El stock es por bodega</h3>
<p>No hay "un número" de existencias: hay existencias <strong>por
bodega</strong>. Y usted ve las de la sede activa.</p>

<h3>Todo movimiento queda registrado</h3>
<p>Entradas, salidas, consumos, devoluciones, ajustes y traslados. Cada uno
guarda cuánto había antes, cuánto quedó, quién lo hizo y por qué. Ese historial
(el <strong>kardex</strong>) es la respuesta a "¿por qué hay 12 y no 20?".</p>

<h3>Si el conteo no cuadra</h3>
<p>Se registra un <strong>ajuste</strong>, con su nota. No se "corrige el
número" por debajo: el rastro es justamente lo que permite entender qué pasó.</p>

<h3>Stock mínimo</h3>
<p>Cada insumo puede tener un mínimo. Cuando cae por debajo, aparece marcado y
llega un aviso por la campanita.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'Comprar lo que falta',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>El flujo va: <strong>solicitud de compra → orden de compra →
recepción</strong>.</p>

<h3>Solicitud</h3>
<p>Es el pedido interno: "necesito esto". Alguien con autoridad la
<strong>aprueba o la rechaza</strong>. Ese paso es manual a propósito: aprobar
un gasto es una decisión de negocio, no algo que deba automatizarse.</p>

<h3>Orden de compra</h3>
<p>La solicitud aprobada se convierte en orden para un proveedor. Al
convertirla, la solicitud pasa sola a "en proceso".</p>

<h3>Recepción</h3>
<p>Cuando llega la mercancía se registra cuánto llegó de cada ítem. El estado
de la orden cambia solo a <strong>recibida</strong> o <strong>recibida
parcial</strong>, y —esto es lo importante— <strong>el stock entra al
inventario real</strong>, el mismo que consume producción.</p>

<h3>El aviso de material faltante</h3>
<p>En el detalle de una OP, el sistema compara lo que piden las recetas contra
lo que hay en bodega y muestra un aviso si falta algo.</p>
<p><strong>Es solo un aviso: no bloquea nada.</strong> No impide seguir
produciendo, porque en la práctica muchas veces se puede avanzar con lo que
hay. Y como Compras y producción usan el mismo inventario, cuando la compra se
recibe, el faltante desaparece solo.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '6. Despachar',
                'lecciones' => [
                    [
                        'nombre'  => 'Remisiones y entrega',
                        'minutos' => 6,
                        'contenido' => <<<'HTML'
<p>La <strong>remisión</strong> es el documento con el que la mercancía sale de
la planta.</p>

<h3>Antes de remisionar</h3>
<p>La OP tiene que haber <strong>pasado por calidad</strong>. Sin eso el sistema
no deja. (Sí, se insiste: es el candado más importante del proceso.)</p>

<h3>Armar la remisión</h3>
<p>Se eligen los ítems que van en ese despacho. <strong>Se admiten entregas
parciales</strong>: si de tres piezas solo van dos, se remisionan dos y la OP
queda con una pendiente.</p>

<h3>La firma del cliente</h3>
<p>Al entregar, el cliente <strong>firma en la pantalla</strong> del celular.
Esa firma queda guardada en el sistema y cierra la entrega.</p>

<h3>Qué pasa solo</h3>
<ul>
  <li>La OP pasa a <strong>despachada</strong>.</li>
  <li>El <strong>inventario se descuenta</strong> cuando la remisión sale.</li>
</ul>
<p>Nadie tiene que acordarse de descontar el material a mano.</p>

<h3>El cliente puede seguir su pedido</h3>
<p>Cada OP tiene un enlace público con código QR. El cliente lo abre y ve en
qué va su pedido, sin llamar a preguntar y sin tener usuario.</p>
HTML,
                    ],
                ],
            ],
            [
                'nombre'    => '7. Herramientas que le ahorran tiempo',
                'lecciones' => [
                    [
                        'nombre'  => 'La asistente del sistema',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<p>El sistema tiene una <strong>asistente</strong> que se abre desde la burbuja
de chat. Se le pregunta en español normal, y también se le puede hablar por
voz.</p>

<h3>Qué sabe hacer</h3>
<ul>
  <li>Responder sobre los datos: <em>"¿qué cotizaciones están sin respuesta?"</em>, <em>"¿cómo va la OP 45?"</em>.</li>
  <li>Crear cotizaciones en <strong>borrador</strong>.</li>
  <li>Ayudar a redactar textos.</li>
</ul>

<h3>Dos límites que conviene tener claros</h3>
<p><strong>Todo lo que crea queda en borrador.</strong> Nada sale hacia un
cliente sin que una persona lo revise y lo confirme.</p>
<p><strong>Los precios los pone el sistema, no ella.</strong> La asistente elige
qué productos y cuántos; cuánto valen sale del catálogo.</p>

<h3>Buscador o asistente: cuál usar</h3>
<p>El <strong>buscador</strong> es instantáneo y sirve para encontrar
<em>un registro concreto</em>. La <strong>asistente</strong> se demora unos
segundos pero entiende preguntas. Cada uno hace lo que el otro no puede.</p>
HTML,
                    ],
                    [
                        'nombre'  => 'Informes y buenas costumbres',
                        'minutos' => 5,
                        'contenido' => <<<'HTML'
<h3>Informes</h3>
<p>En el módulo de Informes están las cifras de ventas, producción, inventario
y cartera, con filtros por fecha, sede y responsable, y con totales y
promedios. Todo sale de lo que se registra en el día a día: si algo no se
registró, no aparece en el informe.</p>

<h3>Todo queda registrado</h3>
<p>El sistema guarda quién creó, modificó o eliminó cada cosa. No es para
vigilar a nadie: es para poder responder "¿quién cambió este precio?" sin que
se vuelva una discusión.</p>

<h3>Cinco costumbres que hacen la diferencia</h3>
<ol>
  <li><strong>Revise la sede</strong> antes de decir que algo no existe.</li>
  <li><strong>Mire la campanita</strong> al empezar el día.</li>
  <li><strong>Registre en el momento</strong>, no al final del día. Lo que no se anota, se pierde.</li>
  <li><strong>Tome la foto</strong> cuando el sistema la pida. Es la prueba del futuro.</li>
  <li><strong>Si un cálculo se ve mal, avise</strong> en vez de corregirlo a mano: el error se va a repetir.</li>
</ol>

<p>Y lo de fondo: <strong>cada acción real dispara sola el siguiente paso</strong>.
Por eso importa registrar lo que de verdad pasó, cuando pasó.</p>
HTML,
                    ],
                ],
            ],
        ];
    }

    private function crearEvaluacionFinal(Curso $curso): void
    {
        $evaluacion = CursoEvaluacion::create([
            'curso_id'                 => $curso->id,
            'curso_modulo_id'          => null,
            'nombre'                   => 'Evaluación final — Cómo usar el sistema',
            'nota_minima_aprobacion'   => 70,
            'intentos_permitidos'      => 3,
            'requiere_revision_manual' => false,
        ]);

        foreach ($this->preguntas() as $orden => $p) {
            $pregunta = EvaluacionPregunta::create([
                'curso_evaluacion_id' => $evaluacion->id,
                'enunciado'           => $p['enunciado'],
                'tipo'                => 'opcion_multiple',
                'orden'               => $orden + 1,
            ]);

            foreach ($p['opciones'] as $ordenOpcion => $opcion) {
                EvaluacionOpcion::create([
                    'evaluacion_pregunta_id' => $pregunta->id,
                    'texto'                  => $opcion[0],
                    'es_correcta'            => $opcion[1],
                    'orden'                  => $ordenOpcion + 1,
                ]);
            }
        }
    }

    private function preguntas(): array
    {
        return [
            [
                'enunciado' => 'Busca un cliente que usted sabe que existe y el sistema no lo encuentra. ¿Qué revisa primero?',
                'opciones'  => [
                    ['La sede seleccionada en el encabezado', true],
                    ['Que el cliente no esté eliminado', false],
                    ['Se crea de nuevo y listo', false],
                    ['Se reinicia el sistema', false],
                ],
            ],
            [
                'enunciado' => 'Una OP pide 3 piezas iguales. ¿Cuántos trabajos crea el sistema?',
                'opciones'  => [
                    ['Tres: uno por cada unidad física', true],
                    ['Uno, con cantidad 3', false],
                    ['Uno por cada operario asignado', false],
                    ['Ninguno; hay que crearlos a mano', false],
                ],
            ],
            [
                'enunciado' => '¿Qué se necesita para poder remisionar y despachar una OP?',
                'opciones'  => [
                    ['Que haya pasado el control de calidad', true],
                    ['Que el cliente haya pagado el total', false],
                    ['Que el jefe cambie el estado a mano', false],
                    ['Que no haya faltantes de material', false],
                ],
            ],
            [
                'enunciado' => 'El aviso de material faltante en una OP...',
                'opciones'  => [
                    ['Avisa, pero no bloquea: se puede seguir produciendo', true],
                    ['Impide confirmar la OP hasta comprar el material', false],
                    ['Reserva el material para esa OP', false],
                    ['Genera la orden de compra automáticamente', false],
                ],
            ],
            [
                'enunciado' => '¿Sobre qué se calcula la comisión del vendedor?',
                'opciones'  => [
                    ['Sobre lo vendido por encima del precio mayorista', true],
                    ['Sobre el total de la venta', false],
                    ['Sobre el costo de los materiales', false],
                    ['Es un valor fijo por cotización', false],
                ],
            ],
            [
                'enunciado' => '¿Cuándo se descuenta el material del inventario?',
                'opciones'  => [
                    ['Cuando sale la remisión', true],
                    ['Al confirmar la cotización', false],
                    ['Al crear la OP', false],
                    ['Cuando el operario marca el primer paso', false],
                ],
            ],
            [
                'enunciado' => 'La asistente crea una cotización. ¿En qué estado queda?',
                'opciones'  => [
                    ['En borrador, para que una persona la revise', true],
                    ['Enviada al cliente automáticamente', false],
                    ['Aprobada y lista para producir', false],
                    ['Depende de quién se lo pida', false],
                ],
            ],
            [
                'enunciado' => 'Un ensamble está dando un precio que no tiene sentido. ¿Qué es lo correcto?',
                'opciones'  => [
                    ['Avisar para que se corrija la plantilla', true],
                    ['Corregir el valor a mano en la cotización', false],
                    ['Usar otro ensamble parecido', false],
                    ['Aplicar un descuento que compense', false],
                ],
            ],
            [
                'enunciado' => 'El código QR de un trabajo, ¿qué permite?',
                'opciones'  => [
                    ['Entrar directo a los pasos de esa unidad, con la sesión iniciada', true],
                    ['Ver el trabajo sin necesidad de tener usuario', false],
                    ['Marcar todos los pasos como completados', false],
                    ['Cambiar el estado de la OP', false],
                ],
            ],
            [
                'enunciado' => '¿Por qué la OP cambia sola de estado a "en producción"?',
                'opciones'  => [
                    ['Porque hay avance real registrado en los trabajos', true],
                    ['Porque pasaron 24 horas desde que se confirmó', false],
                    ['Porque el vendedor la envió a planta', false],
                    ['Porque se recibió el anticipo', false],
                ],
            ],
        ];
    }
}
