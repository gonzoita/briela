# Publicar una versión — del tag al superadmin, sola

Una versión nueva llega sola al superadmin. Lo único que se hace a mano es escribir las
notas, crear el tag y, al final, el clic de **Publicar**.

## Los pasos

1. En `CHANGELOG.md`, cambia `## [Sin publicar]` por el número y la fecha —
   `## [1.1.0] — 2026-10-04`— y abre un `## [Sin publicar]` vacío encima. Esas notas son
   las que verá el cliente en el botón de actualizar: se escriben para quien **usa** el
   sistema.
2. Commit y push a `main`, como siempre.
3. Crea el tag y súbelo:

   ```bash
   git tag v1.1.0
   git push origin v1.1.0
   ```

4. En unos 5 minutos GitHub Actions arma el paquete, y en menos de 15 más aparece en
   `superadmin.briela.app` → **Versiones**, como **borrador**.
5. Pruébala en `sistema.briela.app` y dale **Publicar**. Desde ahí la ven el instalador y
   el botón de actualizar de los clientes.

## Qué pasa por dentro

**En este repositorio**, `.github/workflows/publicar-version.yml` corre con cada tag `v*`:

- Saca las notas de la sección `## [1.1.0]` del `CHANGELOG.md`. **Si no existe esa sección,
  se detiene**: una versión sin notas no le dice nada al cliente.
- `composer install --no-dev` y `php artisan briela:empaquetar 1.1.0`, igual que a mano
  (ver `docs/despliegue.md`, sección 0).
- Crea el **release** de GitHub con `briela-1.1.0.zip`, su `.sha256` y `instalar.php`.

**En el superadmin**, `php artisan versiones:importar` corre cada 15 minutos:

- Pregunta a GitHub por el último release. Si ese número ya está en el panel, no hace nada
  más: es una sola consulta.
- Descarga el ZIP directo al disco, comprueba que coincida con su `.sha256` y que sea un
  paquete de Briela (que traiga `artisan` y `public/index.php`) —las mismas revisiones del
  formulario de subida—, y crea la versión como borrador con las notas del release.
- **Nunca publica.** Deja constancia en la bitácora.

## Por qué el superadmin va a buscarla, y no GitHub a dejarla

Por las mismas razones que el despliegue (ver [deploy-automatico.md](./deploy-automatico.md)):
Hostinger corta las conexiones que llegan desde los servidores de GitHub, y un ZIP de 80 MB
no cabe en el límite de subida de un hosting compartido. Bajarlo no tiene ese límite, y así
el superadmin no tiene que abrir un endpoint que acepte paquetes.

## Por qué un tag y no cada push a `main`

`main` se despliega solo en `sistema.briela.app` varias veces al día. Si cada push fuera una
versión, los clientes terminarían repartidos en decenas de versiones vivas, que es justo lo
que `docs/BRIELA-PLAN.md` sección 6.3 quiere evitar.

## Lo que necesita, una sola vez

En el `.env` del superadmin:

```
VERSIONES_GITHUB_TOKEN=github_pat_...
```

Un token **fine-grained** de GitHub, solo sobre `gonzoita/briela`, con el permiso
*Contents: Read-only* y nada más. Sin token el comando no se agenda y las versiones se suben a
mano como antes.

## Si no aparece

- **En GitHub → Actions** el workflow «Publicar versión» dice por qué falló. Lo más común:
  falta la sección del número en el `CHANGELOG.md`.
- **En el superadmin**, `php artisan versiones:importar` a mano dice qué le falta (token,
  release sin ZIP, hash que no coincide). Las corridas del cron que fallan dejan un aviso en
  `storage/logs/laravel.log`.
- Si salieron dos tags seguidos antes de que corriera, solo se importa el más nuevo. El de en
  medio se puede subir a mano si de verdad hace falta.
