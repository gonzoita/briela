# Briela — documento de arranque

> Para abrir el chat del proyecto nuevo. Este archivo vive hoy en el repo del
> del sistema de origen porque es donde nació la idea, pero **se copia al repositorio de Briela**
> y allá se convierte en su documento de contexto.

Fecha: 1 ago 2026 · Punto de partida: una copia del código del sistema de origen.

---

## 1. Qué es Briela

**Briela es un proyecto NUEVO, con su propio repositorio, que arranca copiando
el código del sistema de origen.** El objetivo es convertirlo en un producto
SaaS multiempresa para vender a otros fabricantes.

Lo que esto implica, y es lo más importante de entender:

- **El sistema de origen NO se toca.** Sigue en https://el servidor del sistema de origen
  con su repo, su base de datos y su deploy. el sistema de origen no es cliente de
  Briela ni depende de ella.
- **Briela es un fork en el sentido práctico**: mismo código de partida, dos
  caminos separados desde el día uno. Un cambio en Briela no afecta a
  el sistema de origen y viceversa.
- **Briela sí es multiempresa por dentro**: un solo sistema donde cada empresa
  cliente ve únicamente sus datos.

> **Consecuencia a tener presente:** a partir del día de la copia, los dos
> proyectos divergen. Si se arregla un bug en Briela, no llega solo al sistema de origen (ni
> al revés). Hay que decidir si eso se acepta (lo normal) o si se quiere
> mantener alguna forma de sincronía (costoso, casi nunca vale la pena).

---

## 2. Cómo se crea el proyecto nuevo

### 2.1 Copiar el código SIN el historial de git

**No hagas `git clone` ni un fork de GitHub.** Copia los archivos y arranca un
historial limpio:

```powershell
# Desde donde quieras que viva el proyecto nuevo
robocopy <carpeta-del-sistema-de-origen> C:\laragon\www\briela /E /XD .git node_modules vendor graphify-out storage\app\public storage\logs public\build
cd C:\laragon\www\briela
git init
```

**Por qué el historial limpio y no un fork:** el historial del sistema de origen contiene la
contraseña de la base de datos de producción del sistema de origen en texto plano
dentro de `CLAUDE.md`. Un fork se la lleva completa, y borrarla después no la
saca del historial. Para un producto que se va a vender —y que puede terminar
con colaboradores externos— eso no puede viajar. Arrancar con `git init` corta
ese problema de raíz.

(Aprovechando: esa credencial debería rotarse en el sistema de origen de todos modos.)

### 2.2 Limpiar antes de escribir la primera línea

Esto es lo que **no** debe existir en Briela:

| Qué borrar | Por qué |
|---|---|
| `OrdenProduccion`, `LineaOP`, `ItemOP` + sus tablas y migraciones | Código muerto del sistema viejo de "3 líneas". No arrastrar a un producto que se vende |
| `InventarioItem`, `InventarioMovimiento` | Stock viejo, ya reemplazado por `Producto` |
| Credenciales del sistema de origen en `CLAUDE.md` y `.env` | Servidor, base, dominio y llaves son otros |
| `HANDOFF.md` | Documento muerto del 16 jul, ya obsoleto en el propio el sistema de origen |
| Marca del sistema de origen (logo, color `#0A4283`, textos) | Briela tiene identidad propia, y además la marca pasa a ser configurable por cada empresa cliente |
| `.github/workflows/deploy.yml` con los datos de Hostinger | Briela tiene su propio servidor y sus propios secretos |
| `docs/manual/*.md` específicos del sistema de origen | Se revisan uno por uno: la mayoría sirve como base, pero hablan del sistema de origen |
| `graphify-out/` | Se regenera con `graphify .` en el proyecto nuevo |

### 2.3 Infraestructura propia

Todo esto es nuevo y no se hereda: repositorio en GitHub, dominio, servidor,
base de datos, secretos del workflow de deploy, y el `.env` de producción.

El pipeline de deploy sí se reutiliza tal cual (GitHub Actions + `subir.ps1`);
solo cambian los secretos y las rutas. Ver `docs/manual/deploy-automatico.md`.

---

## 3. LO MÁS IMPORTANTE: multisede ≠ multiempresa

El sistema de origen ya tiene "multisede" (varias ciudades). **Es tentador pensar que
multiempresa es lo mismo con otro nombre. No lo es, y confundirlos es el error
que puede hundir el proyecto.**

| | Multisede (lo que hay) | Multiempresa (lo que Briela necesita) |
|---|---|---|
| Qué separa | Sucursales de **una misma empresa** | **Empresas distintas** que compiten entre sí |
| Si se filtra un dato | Molesto: alguien de una sede ve una OP de otra | **Catastrófico**: la empresa A ve clientes, precios y márgenes de la B |
| Cómo está hecho | `sede_id` + `ContextoSede::aplicar()` en cada consulta | Por definir (sección 4) |

En Briela la jerarquía queda: **empresa → sedes → datos**. Lo de sedes se
conserva; se le agrega un nivel por encima.

### El riesgo número uno, con números

El filtrado por sede de hoy es **opt-in**: funciona solo si el programador se
acuerda de llamar a `ContextoSede::aplicar()` en esa consulta concreta.

Medido en el repositorio del sistema de origen:
- Solo **7 de 190 migraciones** agregan `sede_id`.
- Solo **33 de 100 archivos** de modelos y servicios mencionan `ContextoSede`.
- Tamaño total a revisar: **100 modelos, 190 migraciones, 98 controladores.**

Para sedes eso es aceptable: se aplicó donde importaba. **Para empresas
distintas no lo es.** Cada consulta donde alguien olvide el filtro es una fuga
silenciosa que nadie nota hasta que un cliente ve lo que no debe.

> **Regla de oro de Briela: el aislamiento entre empresas NUNCA puede depender
> de que alguien se acuerde de filtrar.** Automático y por defecto, imposible
> de olvidar.

---

## 4. La decisión de arquitectura que hay que tomar primero

Hay que elegir **antes** de escribir código.

### A. Base de datos por empresa (recomendado)
Cada empresa con su propia base. Paquete maduro: `stancl/tenancy`, con
subdominio por cliente.

- ✅ **Aislamiento real**: es físicamente imposible que una consulta cruce empresas. Elimina de raíz el riesgo de la sección 3.
- ✅ El código de negocio heredado casi no se toca: sigue consultando como hoy.
- ✅ Respaldo, restauración y "exportar mis datos" por empresa, gratis.
- ✅ Una empresa que crece o se va no afecta a las demás.
- ❌ Las migraciones hay que correrlas en N bases.
- ❌ Más costo de infraestructura y más que operar.

### B. Una base con `tenant_id` en todas las tablas
El mismo patrón que `sede_id`, pero obligatorio y con *global scopes*
automáticos de Eloquent (nunca helpers opt-in).

- ✅ Infraestructura simple y barata.
- ❌ **Una sola consulta mal escrita filtra datos entre empresas.** Con 190 migraciones y 98 controladores heredados, la superficie de error es enorme.
- ❌ Las consultas crudas (`DB::raw`, `selectRaw`, joins) se saltan los scopes, y en el código heredado hay varias — por ejemplo en `ConsultasDatosService`.

### C. Esquema por empresa
Punto medio. Menos común en Laravel y más difícil de operar que A sin ganar mucho.

**Recomendación: A.** Este equipo ya sufrió dos pérdidas totales de datos por
un comando mal dirigido (15 jul 2026). Apostar el negocio a que nadie olvide
nunca un `where` no parece prudente. El aislamiento físico cuesta más en
infraestructura y evita el único error que no tiene vuelta atrás.

---

## 5. Inventario de trabajo real

### 5.1 De global a "por empresa"

| Pieza heredada | Estado en el sistema de origen | Qué implica |
|---|---|---|
| **`Configuracion`** (clave/valor) | **Totalmente global**, sin `sede_id` | El punto más crítico después del aislamiento. Ahí viven SMTP, interruptores de notificaciones, ajustes de IA y puntos de gamificación. Cada empresa necesita los suyos |
| **`Sede`** | Tabla global | Pasa a colgar de la empresa |
| **`Rol` y permisos** | Global configurable | Cada empresa define sus roles |
| **`PerfilMarca`** (logo, colores) | Instancia única | Es lo que hace que Briela se vea como la empresa del cliente. Deja de ser "la marca del sistema" |
| **Archivos subidos** | `storage/app/public/` plano | Separar por empresa. Ver además el asunto de Google Drive en 5.4 |
| **Backups** | Uno para todo | Por empresa, con restauración individual |
| **Tareas programadas** (`routes/console.php`) | Corren una vez | Deben recorrer todas las empresas. Son 5 comandos |
| **`SecuenciaService`** (numeración) | Ya es por sede ✅ | Solo colgarlo de la empresa |
| **Portales públicos por token** | `/op/{token}`, `/seguimiento`, aprobación de cotizaciones, certificados | Deben resolver a qué empresa pertenece el token, sin login |
| **Llave de IA (OpenRouter)** | Una sola, global | Decidir: ¿la paga Briela y se reparte el costo, o cada empresa pone la suya? Afecta el precio del producto |
| **Cuentas de redes sociales** | Tabla global | Por empresa, con sus tokens |

### 5.2 Producto nuevo (no existe nada de esto)

- **Registro y onboarding** de una empresa.
- **Aprovisionamiento**: crear base/esquema, migrar, datos semilla, primer usuario.
- **Panel de superadmin**: ver empresas, suspender, entrar como soporte.
- **Planes y límites** (usuarios, OPs, almacenamiento).
- **Facturación recurrente** (pasarela, moneda, impuestos, mora).
- **Landing, dominio y correo** de Briela.
- **Soporte y estado del servicio.**

### 5.3 Lo que se hereda funcionando

Vale la pena dimensionar lo que **ya está resuelto** y no hay que volver a
construir: CRM con pipeline · cotizador con motor de fórmulas · OPs con
trabajos por unidad física · control de calidad · remisiones con firma ·
inventario unificado · compras · informes · capacitación con certificados ·
RRHH con gamificación · notificaciones · auditoría · buscador global ·
asistente de IA · PWA · portales públicos · deploy automático.

Está documentado módulo por módulo en `docs/manual/`. **Ese manual es la
fuente de verdad de lo funcional** — no re-explorar el código para entender
qué hace algo.

### 5.4 Deudas heredadas que conviene saldar en la copia

- **Google Drive a medias**: el logo y el favicon ya se guardan en el servidor,
  pero `ArchivoController` **sigue subiendo a Drive primero** y solo cae a
  local si falla. La decisión tomada es salir de Drive. En Briela conviene
  arrancar ya sin Drive: no hay archivos históricos que migrar, así que sale
  gratis hacerlo bien desde el principio.
- **Redes sociales**: el módulo está construido pero nunca se ha conectado de
  verdad (faltan credenciales de Meta y aprobaciones de LinkedIn y Google).
  En Briela, además, cada empresa necesitaría sus propias apps o un modelo de
  app única de Briela — decisión pendiente y no trivial.

---

## 6. Preguntas de negocio sin resolver

No las contesta el código. Deben decidirse antes o durante el diseño:

1. **¿A quién se le vende?** El sistema está hecho a la medida de fabricar
   cuartos fríos: plantillas de ensamble con fórmulas, trabajos por unidad
   física, calidad con foto. Es una **ventaja enorme** frente a un ERP
   genérico, pero solo si el comprador fabrica algo parecido (metalmecánica,
   carpintería, fabricación por pedido con medidas variables). Venderle a un
   comercio o a una empresa de servicios exigiría rehacer el corazón del
   producto. **Definir esto antes de invertir meses.**
2. **Precio y modelo**: por usuario, por empresa, por volumen.
3. **¿Quién da soporte** cuando haya 20 empresas?
4. **¿Qué tanto se puede personalizar** por empresa sin volver el código un nudo?
5. **¿Quién mantiene el sistema de origen** ahora que los caminos se separan?

---

## 7. Reglas que Briela hereda y no se rompen

Están completas en el `CLAUDE.md` que viaja con la copia. Las críticas:

1. **Español colombiano neutro, prohibido el voseo** — UI, código, commits y chat.
2. Sin Ziggy · sin `resolvePageComponent` · sin CSS separado · vistas solo en Vue · `AppLayout.vue` en páginas autenticadas · **mobile-first siempre**.
3. ⛔ **Nunca `migrate:fresh`, `migrate:refresh` ni `db:wipe`** contra una base real. Causó dos pérdidas totales de datos el mismo día. En multiempresa el riesgo se multiplica por cada cliente.
4. **Principio de fondo del sistema**: cada acción real dispara sola el siguiente paso del proceso. Evaluar cada cosa nueva bajo ese criterio.

Al copiar, `CLAUDE.md` hay que **reescribirlo para Briela**: cambia el
repositorio, el dominio, el servidor, la base, la marca y el propósito.

---

## 8. Cómo arrancar el chat nuevo

Sugerencia de primer mensaje, ya parado en la carpeta de Briela:

> Estamos arrancando **Briela**: un producto SaaS multiempresa que parte de una
> copia del código del sistema de origen. El sistema de origen original no se toca.
> Lee en este orden: `docs/BRIELA-CONTEXTO.md`, `CLAUDE.md` y
> `docs/manual/00-indice.md`. No re-explores el código ya documentado.
>
> Primera tarea: decidir conmigo la arquitectura de aislamiento entre empresas
> (sección 4) y armar el plan por fases. No escribas código todavía.

**Primeras tareas, en orden:**

1. **Copiar y limpiar** el proyecto (sección 2). Historial de git nuevo.
2. **Decidir el aislamiento** (sección 4). Todo lo demás depende de eso.
3. **Prueba de concepto** con dos empresas de mentira y unos pocos módulos,
   antes de tocar los 98 controladores.
4. **Reescribir `CLAUDE.md`** para Briela.

> No empezar por la facturación ni por la landing. Si el aislamiento está mal,
> lo demás no importa.
