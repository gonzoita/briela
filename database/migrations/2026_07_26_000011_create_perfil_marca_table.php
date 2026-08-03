<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Perfil de marca de la empresa, guardado por secciones. Es la fuente de
    // verdad que usa la IA para redactar con la voz de Interfrigo y para
    // responder preguntas sobre la empresa.
    //
    // Se siembra con el contenido del documento "Perfil de Marca Interfrigo"
    // para que el módulo nazca funcionando, no vacío.
    public function up(): void
    {
        Schema::create('perfil_marca', function (Blueprint $table) {
            $table->id();
            $table->string('seccion', 40)->unique(); // historia, mision, vision...
            $table->longText('contenido')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamp('generado_ia_at')->nullable(); // si lo escribió la IA
            $table->timestamps();
        });

        $secciones = [
            'identidad' => <<<'TXT'
            Nombre: Interfrigo SAS
            Slogan: Fabricamos e importamos calidad y tecnología para la refrigeración industrial.
            TXT,

            'historia' => <<<'TXT'
            Interfrigo nació en Colombia con la visión de transformar el mercado nacional de la
            refrigeración industrial, uniendo excelencia europea e innovación local. Comenzó con un
            emprendedor venezolano que, respaldado por expertos italianos, se propuso fabricar
            productos ajustados a las necesidades reales del sector y al estándar de calidad que el
            mercado esperaba.

            Desde el inicio el propósito fue claro: dar solución concreta a quienes buscan un proveedor
            confiable y práctico para sus proyectos. Se enfrentaron retos logísticos, económicos y
            culturales, y la respuesta siempre fue entender los desafíos de técnicos, contratistas y
            empresas, y asegurarles que con Interfrigo encontrarían todo lo necesario para sus
            instalaciones de refrigeración industrial y cuartos fríos.

            La empresa se consolidó fabricando puertas y paneles de acero y prepintado para cuartos
            fríos, acompañando el portafolio con accesorios y chapas importadas. Hoy Interfrigo es el
            proveedor estratégico que facilita el día a día de sus clientes, resolviendo sus necesidades
            en un solo lugar, con disponibilidad inmediata, asesoría cercana y respuesta efectiva.
            TXT,

            'proposito' => <<<'TXT'
            - Facilitar soluciones confiables para refrigeración industrial.
            - Ser el proveedor integral para cuartos fríos y accesorios.
            - Agilizar el acceso a productos y asesoría técnica especializada.
            - Garantizar disponibilidad inmediata y respuesta ágil.
            - Construir confianza y tranquilidad en cada proyecto.
            - Simplificar la gestión de compras para técnicos y empresas.
            TXT,

            'promesa' => <<<'TXT'
            Te entregamos soluciones confiables, completas y a tiempo para tus proyectos de
            refrigeración industrial, respaldadas por la calidad y tecnología de Intertecnica, referente
            mundial en componentes para cuartos fríos.

            Qué significa en la práctica:
            - Dispones de puertas, paneles en acero y prepintado, accesorios y herrajes con estándar italiano.
            - Encuentras en un solo lugar todo lo necesario, con asesoría técnica especializada.
            - Tu consulta se atiende con rapidez y claridad, con productos avalados por una marca líder mundial.

            Qué respalda la promesa:
            - Entregas rápidas y garantía en todos los productos gracias a la estructura y calidad Intertecnica.
            - Soporte técnico con experiencia comprobada y acceso directo a innovación europea.
            - Referencias y proyectos realizados en empresas líderes del país.
            TXT,

            'propuesta_valor' => <<<'TXT'
            - Puertas y paneles en acero inoxidable y lámina prepintada, accesorios y herrajes para
              cuartos fríos, todo en un solo lugar.
            - Entregas ágiles, inventario disponible y soporte técnico especializado.
            - Alianza exclusiva con Intertecnica Italia: componentes de clase mundial y tecnología certificada.
            - Atención personalizada y soluciones a la medida para técnicos, contratistas y empresas.
            - Tranquilidad y confianza: encuentras exactamente lo que necesitas, cuando lo necesitas,
              con acompañamiento de principio a fin.
            TXT,

            'mision' => <<<'TXT'
            Brindamos soluciones integrales en refrigeración industrial, superando las expectativas de
            calidad y confianza en el mercado colombiano. Buscamos ser el proveedor estratégico y
            confiable de técnicos, contratistas y empresas, entregando productos de alto estándar que
            reúnen la ingeniería italiana y la innovación local.

            Más que fabricar puertas, paneles y accesorios, nos comprometemos a generar confianza y
            facilitar el éxito de los proyectos de nuestros clientes en cada etapa.
            TXT,

            'vision' => <<<'TXT'
            Ser reconocidos como el proveedor de referencia en refrigeración industrial en Colombia,
            impulsando nuestro impacto hacia mercados internacionales y convirtiéndonos en la opción
            preferida para técnicos, contratistas y empresas.

            Trabajamos cada día para transformar la industria, ofreciendo soluciones que integran
            innovación, ingeniería italiana y un compromiso constante con la calidad y la confianza.
            TXT,

            'valores' => <<<'TXT'
            - Innovación constante: buscamos siempre nuevas formas de mejorar y adaptarnos al mercado.
            - Calidad garantizada: productos fabricados e importados bajo altos estándares técnicos.
            - Compromiso con el cliente: atención a cada requerimiento, con soluciones integrales.
            - Transparencia: claridad en nuestros procesos y ofertas.
            - Agilidad y disponibilidad: respuesta rápida e inventario inmediato.
            - Confianza: proveedor profesional, cercano y seguro.
            TXT,

            'elevator_pitch' => <<<'TXT'
            Interfrigo es la empresa referente en la fabricación de puertas y paneles para cuartos fríos
            en Colombia, y amplía su portafolio con la importación exclusiva de accesorios y componentes
            italianos certificados. Nos destacamos por la eficiencia logística, garantía de calidad y
            soporte técnico especializado, ofreciendo soluciones integrales y confiables para la industria
            de la refrigeración en proyectos empresariales de cualquier escala.
            TXT,

            'mensaje_clave' => <<<'TXT'
            Solucionamos las necesidades de refrigeración industrial fabricando puertas y paneles en
            Colombia, y abasteciendo al mercado con accesorios y componentes italianos certificados.
            En Interfrigo encuentras calidad, respaldo internacional y respuestas ágiles para tus proyectos.
            TXT,

            'tono_voz' => <<<'TXT'
            Tono: profesional, confiable y cercano, con entusiasmo discreto por la innovación y la
            solución de problemas. Transmite seguridad, conocimiento técnico y entendimiento práctico
            del sector, evitando exageraciones o pretensiones. Siempre claro, directo y orientado a
            generar confianza en empresas, técnicos y contratistas.

            Voz: Interfrigo se expresa como una empresa experta, eficiente y orientada al cliente, que
            entiende el entorno industrial y actúa como referente técnico. Su voz es precisa, cordial y
            proactiva. Habla de eficiencia, calidad y confianza, invitando a que los clientes simplifiquen
            su gestión y potencien sus proyectos.
            TXT,

            'clientes_ideales' => <<<'TXT'
            Sectores: alimenticio, farmacéutico, hotelero, morgues, logística y refrigeración industrial.

            Tipo de empresa: grandes industrias y corporativos; PYMES (plantas pequeñas, hoteles,
            laboratorios); instaladores y contratistas independientes.

            Ubicación: Colombia a nivel nacional, con proyección a Latinoamérica.

            Perfil transaccional: compras recurrentes, capacidad de inversión, negociación de descuentos
            y crédito (ej. 30 días). Proyectos nuevos, remodelaciones y mantenimientos.

            Decisores: jefes de mantenimiento y de compras, técnicos de instalación y operación,
            ingenieros, arquitectos y encargados de proyectos.

            Dolores: tiempos de entrega y cumplimiento de plazos, disponibilidad inmediata de producto,
            asesoría técnica en proyectos complejos, y facilidad de crédito según volumen.

            Ejemplos:
            1. Planta procesadora de alimentos (PYME o gran industria, nacional): necesita puertas y
               paneles con entrega inmediata para no parar producción. Decide el jefe de mantenimiento
               o compras. Compra recurrente con crédito a 30 días.
            2. Hotel 4 estrellas con restaurante (mediano, ciudades turísticas): cámaras refrigeradas
               para cocina y almacén. Valora diseño, cumplimiento y apoyo técnico. Compra ocasional y
               urgencias.
            3. Instalador HVAC independiente (micro o pequeña, nacional): necesita stock inmediato y
               soporte en instalación. Compra por proyecto; valora precio y agilidad.
            4. Laboratorio o distribuidor farmacéutico (mediana o grande): exige hermeticidad, control
               de temperatura y cumplimiento estricto de plazos. Compra regular con crédito.
            TXT,

            'kpis' => <<<'TXT'
            - Atracción: leads nuevos (cantidad de contactos potenciales).
            - Calificación: leads válidos (porcentaje que cumple el perfil objetivo).
            - Propuesta: velocidad de propuesta (días promedio para enviar cotización).
            - Negociación: conversión (porcentaje de propuestas que se vuelven venta).
            - Venta: ventas cerradas.
            - Entrega: entrega puntual (porcentaje entregado en la fecha prometida).
            - Postventa: satisfacción del cliente (encuestas).
            - Fidelización: recompra (porcentaje de clientes que vuelven a comprar).
            TXT,

            'dofa' => <<<'TXT'
            Fortalezas:
            - Herrajes de calidad certificada y exclusiva (Intertecnica).
            - Puertas y paneles con excelente acabado.
            - Todos los productos y accesorios en un solo lugar.
            - Variedad de accesorios para distintos requerimientos.
            - Únicos a nivel nacional con venta autorizada de herrajes Intertecnica.

            Debilidades:
            - Tránsitos largos y procesos logísticos que alargan la entrega.
            - Disponibilidad limitada de mercancía en ciertos momentos.
            - Espacio físico de bodega reducido.
            - Personal de ventas poco capacitado y alta rotación.

            Oportunidades:
            - Mudanza a nueva bodega (más espacio y mejor logística).
            - Marketing digital y comunicación.
            - Capacitación continua del personal.

            Amenazas:
            - Alta competencia local e internacional.
            - Variabilidad del euro (costos de importación).
            - Competidores nacionales con productos de menor precio.

            Acciones a implementar:
            1. Aprovechar la nueva bodega para reducir tiempos de entrega y mejorar stock.
            2. Campañas de marketing digital destacando exclusividad y calidad certificada.
            3. Programas formativos para el equipo comercial.
            4. Propuestas de valor que resalten calidad y respaldo frente a precios bajos.
            5. Acuerdos con proveedores que minimicen el impacto de la variabilidad del euro.
            TXT,
        ];

        $orden = 0;
        $filas = [];

        foreach ($secciones as $clave => $contenido) {
            // Los heredoc vienen indentados: se limpia el margen izquierdo.
            $texto = preg_replace('/^[ \t]+/m', '', trim($contenido));

            $filas[] = [
                'seccion'    => $clave,
                'contenido'  => $texto,
                'orden'      => $orden += 10,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('perfil_marca')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_marca');
    }
};
