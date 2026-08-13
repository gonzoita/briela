# Copias de seguridad

**Administración → Backup.**

## Respaldo automático

Todas las noches a las **2:00 a.m.** el sistema genera una copia completa de la
base de datos. A esa hora no hay nadie trabajando, así que el volcado no
compite con la operación.

Se conservan **30 días**. Los más viejos se borran solos, pero **nunca queda la
carpeta vacía**: si por lo que sea solo hay uno y ya está viejo, ese se
conserva. Quedarse sin ningún respaldo por una regla de limpieza sería el peor
resultado posible.

### Cómo saber si está funcionando

Arriba de la pantalla hay una tarjeta con el estado:

- **Verde**: el último respaldo automático tiene menos de 48 horas.
- **Amarilla**: pasaron más de 48 horas, o nunca ha corrido.

Se usan 48 horas y no 24 porque saltarse una noche por un reinicio del servidor
no es motivo de alarma; dos noches seguidas sí significa que algo dejó de
correr.

**Si falla, llega una notificación a la campanita** de todos los
administradores. Un respaldo que falla en silencio es peor que no tener
respaldo: da una falsa sensación de seguridad hasta el día en que se necesita.

### Requisito en el servidor

El respaldo automático depende del cron que ejecuta el programador de Laravel.
Es el mismo que ya usan las demás tareas (cotizaciones vencidas, avisos de
entrega, publicaciones de redes). Si esas funcionan, esta también.

Si no está puesto, en Hostinger se agrega en **Avanzado → Trabajos Cron**:

```
* * * * * cd /home/USUARIO/domains/TUDOMINIO/public_html/briela && php artisan schedule:run >> /dev/null 2>&1
```

Para probar sin esperar a las 2:00 a.m.:

```
php artisan backup:crear
```

## Respaldo manual

El botón **Crear Backup** genera uno al instante y lo descarga. Los archivos
manuales quedan también en el servidor, marcados distinto en la lista.

Vale la pena hacer uno antes de una importación masiva, una actualización o un
cambio grande de configuración.

## Cuando el soporte de Briela pide un respaldo

El soporte puede **pedir** un respaldo desde su panel, por ejemplo antes de revisar
un problema. No entra a tu servidor: deja el pedido, y tu instalación lo recoge la
próxima vez que pregunta por su licencia —cada pocas horas—, lo hace con el mismo
mecanismo de siempre y le informa de vuelta que ya está.

**El archivo queda en tu servidor**, en esta misma lista. Briela recibe el aviso de
que se hizo y su tamaño; el respaldo no se le manda a nadie.

Si tu servidor no tiene cron, en esta pantalla aparece un aviso azul con el pedido y
un botón para ejecutarlo a mano. Sin cron no se haría solo, y quedarse esperando sin
que nadie sepa por qué es peor que pedir un clic.

## Restaurar

Restaurar **sobrescribe toda la base de datos** con el contenido del archivo.

Antes de hacerlo, el sistema **guarda automáticamente cómo está la base en ese
momento**, con el nombre `backup_antes-de-restaurar_...`. Si restauras el
archivo equivocado, ese es tu camino de vuelta.

Si ese respaldo previo no se puede crear, la restauración **se cancela**. Es a
propósito: restaurar sin red de seguridad es la forma más rápida de perder la
operación de un día completo.

## Dónde quedan los archivos

En `storage/app/backups` del servidor. Están fuera de git.

**Están en el mismo servidor que la base de datos**, así que protegen contra
errores humanos y daños de datos, pero no contra la pérdida del servidor
entero. Si algún día quieres cubrir eso, el paso siguiente es copiarlos a otro
lado — Google Drive, S3 o similar.
