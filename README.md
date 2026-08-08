# Briela

ERP para fabricantes por pedido: cotización con medidas variables, órdenes de
producción con seguimiento por unidad física, control de calidad, inventario,
compras, despachos, capacitación y RRHH.

Se vende e **instala en el servidor de cada cliente**, cada uno con su propia
base de datos.

> Repositorio privado. Este código viaja a servidores de clientes: no poner aquí
> credenciales, ni datos de una instalación concreta, ni lógica de licenciamiento
> del negocio (esa vive en `briela-superadmin`).

## Documentación

| Documento | Para qué |
|---|---|
| [`docs/BRIELA-PLAN.md`](docs/BRIELA-PLAN.md) | Arquitectura y plan por fases. **Fuente de verdad** |
| [`docs/BRIELA-CONTEXTO.md`](docs/BRIELA-CONTEXTO.md) | Documento de arranque y decisiones de origen |
| [`docs/manual/00-indice.md`](docs/manual/00-indice.md) | Manual funcional, módulo por módulo |
| [`CLAUDE.md`](CLAUDE.md) | Contexto y reglas del proyecto para Claude Code |

## Stack

Laravel 13 · PHP 8.3 · Vue 3 + Inertia 2 · Tailwind · Vite 8 · MySQL

`public/build/` está trackeado en git a propósito: es lo que permite instalar en
el servidor de un cliente sin Node ni Vite.

## Montar en local

Requiere PHP 8.3, Composer, Node y MySQL. En Windows con Laragon:

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;" + $env:Path
```

```bash
composer install
npm install
```

Crear la base `briela` y copiar la plantilla de configuración:

```bash
cp .env.example .env
php artisan key:generate
```

En `.env` dejar `DB_DATABASE=briela`, `APP_ENV=local` y `APP_DEBUG=true`. Luego:

```bash
php artisan migrate --seed
php artisan storage:link
```

Y para trabajar:

```bash
npm run dev
php artisan serve
```

> ⚠️ La base de este proyecto es **`briela`**. Nunca apuntar el `.env` a la
> base de otro proyecto.

## Reglas que no se rompen

Están completas en [`CLAUDE.md`](CLAUDE.md). Las que más cuestan si se olvidan:

- **Español colombiano neutro**, sin voseo, en UI, código y commits.
- **Cero personalización en el código de un cliente**: todo por configuración.
- **Migraciones hacia adelante y nunca destructivas** — al otro lado hay bases de
  datos de clientes a las que no hay acceso.
- Nunca `migrate:fresh`, `migrate:refresh` ni `db:wipe` contra una base real.
