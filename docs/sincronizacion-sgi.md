# Replicar cambios del sistema de origen en Briela

Briela nació como copia del SGI con **historial de git nuevo**, así que los dos
repos no tienen ancestro común: no se pueden mezclar con `merge`. Sí se pueden
pasar cambios de uno a otro con `cherry-pick`, que aplica el contenido de un
commit como parche.

**Último commit del SGI ya incluido en Briela: `be22a1d`**
*(«Manual: completar guia de montaje en otra PC», 1 ago 2026)*

> Al replicar commits nuevos, **actualizar esa línea**. Es lo único que permite
> saber qué falta sin revisar a mano todo el historial del SGI.

---

## Preparación — una sola vez

```bash
git remote add origen <ruta-local-del-sistema-de-origen>
```

Apunta a la carpeta local, así que solo funciona en la máquina donde están los
dos proyectos. Es de lectura: nada de lo que se haga en Briela toca el SGI.

## Cada vez que haya algo que replicar

```bash
git fetch sgi
```

```bash
git log --oneline be22a1d..sgi/main
```

Eso lista exactamente los commits del SGI que todavía no están en Briela
(cambiando `be22a1d` por el puntero de arriba). Después, por cada uno que valga
la pena traer:

```bash
git cherry-pick <hash>
```

Si hay conflicto, se resuelve como cualquier conflicto y se sigue con
`git cherry-pick --continue`. Si el commit no aplica o no tiene sentido en
Briela, `git cherry-pick --abort` y se rehace el cambio a mano.

### El estorbo conocido

El SGI **versiona `.claude/settings.local.json`**, que es estado local de la
máquina, y casi todos sus commits lo tocan. En Briela ese archivo está ignorado,
así que es la causa más frecuente de conflicto — y no aporta nada. Para saltarlo:

```bash
git cherry-pick -n <hash> && git checkout HEAD -- .claude/ && git commit
```

---

## Qué se puede replicar y qué no

**Se replica bien** (el código de negocio sigue casi idéntico):
controladores, servicios, modelos vivos, componentes Vue, migraciones nuevas,
correcciones de lógica.

**No se replica** — hay que rehacerlo a mano o simplemente no aplica:

| Zona | Por qué |
|---|---|
| `CLAUDE.md`, `README.md`, `CHANGELOG.md`, `.env.example`, `.gitignore` | Reescritos para Briela |
| `.github/workflows/deploy.yml` | Otro servidor y otros secretos |
| Los 5 modelos y 10 migraciones del código muerto | Ya no existen en Briela |
| `config/pdf_modulos.php`, `PdfVariablesEngine` | Corregidos en Briela para apuntar a `ops` / `op_items` |
| `app/Models/Proveedor.php` | Se le quitó la relación a `InventarioItem` |
| Marca, colores y logo | En Briela son configurables por cliente |
| `IaService` | En Briela sale por el proxy del superadmin, no por OpenRouter directo |
| Todo lo de licencia, instalador y actualizador | No existe en el SGI |

**La ventana de sincronización fácil se va cerrando.** Hoy los dos códigos son
casi iguales y un `cherry-pick` es trivial. Después de las fases 1 a 3 (marca
configurable, licencias, proxy de IA), archivos como `IaService` habrán
divergido tanto que replicar dejará de ser mecánico.

---

## La regla que evita el trabajo permanente

**Un cherry-pick de un commit de ayer es trivial; de hace tres meses, imposible.**
Lo que hace inviable mantener dos repos no es la dificultad técnica: es dejar que
se acumule.

Dirección recomendada del flujo:

| Caso | Dónde se arregla primero |
|---|---|
| Bug que detiene la operación del sistema de origen | En el SGI, y se replica a Briela **el mismo día** |
| Mejora, módulo nuevo, refactor | **En Briela**, y de ahí se lleva al SGI |

Lo segundo es lo que conviene por defecto: Briela es el producto que se vende, y
su código es el que debe estar bien. Si todo se arregla primero en el sistema de origen,
Briela vive siempre poniéndose al día, y la deriva termina haciendo imposible el
cherry-pick.

## La salida de fondo

Mantener dos ERPs completos en paralelo es un costo permanente que crece con cada
fase de Briela. La única salida que lo elimina de raíz es que **el sistema de origen pase a
ser una instalación de Briela** — con su serial, su marca y sus datos.

`BRIELA-CONTEXTO.md` descartó esa idea al arrancar, y para arrancar tenía razón:
Briela todavía no existía. Pero conviene volver a plantearla cuando Briela esté
madura, porque la alternativa es sincronizar a mano para siempre.
