# Automatizaciones

«Cuando pase esto, haz aquello». Está en **Sistema → Automatizaciones** y **viene apagado**: se enciende en
Configuración → Módulos. Permisos: `automatizaciones.ver`, `.gestionar` (crear y editar) y `.ejecutar` (lanzar a mano).

## Cómo se arma

Una automatización es una lista de pasos que empieza por un **disparador**:

- **Un evento**: una cotización cambia de estado, entra un lead, un lead cambia de etapa, una orden cambia de estado o se registra un pago.
- **Un horario**: a una hora fija (diario, semanal, mensual).
- **A mano**: alguien pulsa «Ejecutar».

Después vienen **condiciones** (si el estado es X, si el valor supera Y), **esperas** (minutos, horas o días, hasta 90) y **acciones**.

## Qué pueden hacer (y qué no)

Acciones: avisar a una persona o a quienes tienen un permiso, crear una tarea en el CRM, dejar una nota en una cotización y poner a trabajar a un agente del Equipo de IA.

**Nunca**: escribir a clientes ni a redes, registrar pagos, mover el estado de una orden, despachar, borrar ni cambiar precios. Para eso están las pantallas y las aprobaciones.

## Reglas de seguridad

- **Los permisos de quien la guardó son su techo**, al guardar y en cada corrida. Si esa persona pierde el permiso, la automatización falla en vez de seguir actuando.
- Si otra persona edita una automatización, pasa a ser suya.
- Nace **apagada**. «Probar» la corre contra un documento real sin escribir ni avisar.
- Una espera guarda la corrida y el cron la reanuda; **al volver a leer el documento** decide con datos frescos, no con los de hace cinco días.
- Cada corrida guarda su propia copia del grafo: editar la automatización no cambia lo que ya está esperando.
- Tope por hora (60 por omisión; lo que sobra se descarta y se avisa una vez), tope de 3 generaciones (una automatización que dispara otra) y detección de ciclos.
- Cinco fallas seguidas la pausan sola y avisan a quien la creó.
- Pausar o eliminar cancela lo que estaba esperando.

## Historial

Cada corrida deja nodo por nodo qué hizo (ok, omitido, espera, error). Una corrida en espera se puede cancelar.

## Plantillas de fábrica

Cotización enviada sin respuesta en 5 días, lead nuevo con tarea de contacto para mañana, orden lista para calidad que avisa a quienes revisan, y el Analista del día cada mañana.

## Para quien programa

`App\Services\Automatizaciones\`: `CatalogoAutomatizaciones` (eventos y acciones: un evento o acción nueva se agrega aquí), `GrafoAutomatizacion` (validación), `MotorAutomatizaciones` (recorrido), `LanzadorAutomatizaciones` (único sitio donde se crea una corrida), `EventosAutomatizacion` (oyentes de modelos; **nunca devuelven `false`**, que en Eloquent corta a los demás observers). El cron corre `automatizaciones:avanzar` cada minuto.
