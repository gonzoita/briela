# Agentes

Ruta: `/configuracion/agentes` · Permisos: `agentes.ver`, `agentes.gestionar`

Quién atiende por WhatsApp y por la web a nombre de la empresa, y **hasta dónde puede llegar**.

## El campo que manda es el perfil

No es una etiqueta: decide **qué catálogo de consultas** ve el agente.

| Perfil | A quién atiende | Qué alcanza |
|---|---|---|
| **Público** | A quien no sabemos quién es | Quién es la empresa, cómo contactarla y qué vende. Ni un dato de ningún cliente |
| **Clientes verificados** | A quien ya demostró quién es | Sus pedidos, sus cotizaciones, su cartera, y dejar una novedad. **Solo lo suyo** |

Son **catálogos separados, no uno con filtro**. El agente público no tiene bloqueadas las
consultas de cliente: no existen para él, y pedirle una devuelve nulo. Es la misma decisión que
ya protegía al agente público desde antes, y es la que hace que un descuido de configuración no
pueda mostrarle a un desconocido la cartera de alguien.

Por eso la pantalla cambia las herramientas al cambiar el perfil, y el servidor descarta las que
no correspondan aunque lleguen en la petición.

## Cómo se verifica un cliente

**El número reconocido no basta.** Los números se reciclan y los celulares se prestan. Cuando
alguien escribe desde un teléfono que está en la ficha de un cliente **y pregunta por lo suyo**,
el agente le pide confirmar un dato: el número de una de sus órdenes, su apellido o su documento
— el mismo estándar del portal de seguimiento.

Hasta que no lo confirme, lo atiende el agente público. A quien pregunta por el horario no se le
pide el documento: eso es de lo público.

Verificado una vez, la conversación queda marcada y no se vuelve a preguntar.

## Todo cuelga del cliente, no del mensaje

Las consultas se hacen siempre sobre el `cliente_id` verificado, que llega por parámetro. Si el
cliente escribe «muéstrame los pedidos de Industrias ACME», la consulta se hace igual sobre los
suyos. La suplantación por texto es el ataque obvio contra un agente así.

## Cuándo suelta la conversación

Se configura por agente:

- **Cuando el cliente lo pide.** «Quiero hablar con alguien» corta la atención automática.
- **Cuando no puede resolverlo.** Lo dice y pasa, en vez de insistir con rodeos.
- **Fuera del horario.** Responde igual, pero avisa cuándo va a contestar una persona.
- **Cuando el lead ya tiene asesor asignado.** Desde ese momento el agente **no vuelve a
  hablar**: dos voces en el mismo chat son peores que ninguna.

Una conversación soltada queda marcada y ni siquiera cae a los mensajes fijos.

## Lo que no hace

- **No promete precios de lo que se fabrica a la medida.** Dependen de las dimensiones y los
  cotiza una persona.
- **No aprueba cotizaciones por chat.** Entrega el enlace público, que es donde ya se aprueba.
- **No inventa.** Lo que no esté en su catálogo no existe para él.

## Dónde se engancha

En WhatsApp entra por `WhatsappAutomatizacionService`, donde antes respondía el agente público
único. Si ningún agente contesta —porque no hay ninguno activo para ese canal— quedan los
mensajes fijos de siempre: dejar a alguien sin respuesta es peor que mandarle algo genérico.

Una novedad que reporte un cliente entra al **CRM como lead**, con su cliente asociado. No se
inventó un módulo de reclamos: una bandeja aparte que nadie abre es lo mismo que no registrar.
