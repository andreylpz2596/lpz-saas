# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bagisto 2.4.x - open-source Laravel 12 e-commerce platform. PHP 8.3+, Vue.js 3, Tailwind CSS 3, Vite 5.

## Common Commands

### Development
```bash
composer install                # Install PHP dependencies
php artisan bagisto:install     # Full installation (migrations, seeders, assets)
php artisan serve               # Start PHP dev server
php artisan optimize:clear      # Clear all caches (run after config/code changes)
```

### Testing
```bash
vendor/bin/pest                                         # Run all tests
vendor/bin/pest --testsuite="Admin Feature Test"        # Run a specific test suite
vendor/bin/pest packages/Webkul/Admin/tests/Feature     # Run tests in a directory
vendor/bin/pest --filter="test name"                    # Run a single test by name
```

Test suites defined in `phpunit.xml`: Admin Feature, Core Unit, Customer Unit, DataGrid Unit, Installer Feature, PayU Unit/Feature, Razorpay Unit/Feature, Shop Feature, Stripe Unit/Feature.

Tests use **Pest 3** with package-specific TestCase classes bound in `tests/Pest.php`. Each package's tests live in `packages/Webkul/<Package>/tests/`.

### E2E Tests (Playwright)
E2E tests are run from within each package directory. Each package has its own Playwright config and tests:

**Admin**:
```bash
cd packages/Webkul/Admin
npm install
npx playwright install --with-deps chromium
npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

**Shop**:
```bash
cd packages/Webkul/Shop
npm install
npx playwright install --with-deps chromium
npx playwright test --config=tests/e2e-pw/playwright.config.ts
```

Tests require a running Laravel server (`php artisan serve`) and seeded database. Set `BASE_URL` env var if not using default.

### Code Style
```bash
vendor/bin/pint             # Fix PHP code style (Laravel Pint)
vendor/bin/pint --test      # Check style without fixing
```

### Translations
When adding new translation keys, always provide translations for **all locales** in the package's `Resources/lang/` directory. Verify with:
```bash
php artisan bagisto:translations:check
```

## Architecture

### Modular Package System

All core functionality lives in **`packages/Webkul/`** (~42 packages). Each package is a self-contained Laravel package with its own models, controllers, routes, views, migrations, and service providers.

**Dual registration**: Each package registers in two places:
1. **`bootstrap/providers.php`** - Main ServiceProvider (routes, views, events, config)
2. **`config/concord.php`** - ModuleServiceProvider (Konekt Concord model/enum registration)

### Key Design Patterns

**Repository Pattern**: All database access goes through repositories (`Prettus L5 Repository`). Interfaces in `Contracts/`, implementations in `Repositories/`. Never use models directly for queries in controllers.

**Proxy Pattern**: Models have Proxy classes (e.g., `ProductProxy`, `CategoryProxy`) enabling model substitution without modifying core code. Always reference proxies when type-hinting across packages.

**Event-Driven Extensibility**: The framework fires events at key lifecycle points. Extend behavior via listeners rather than modifying core packages.

### Package Anatomy

```
packages/Webkul/<Package>/src/
├── Config/           # system.php (admin settings), admin-menu.php, acl.php
├── Database/         # Migrations/, Seeders/, Factories/
├── Http/Controllers/ # Separate Admin/ and Shop/ controller directories
├── Models/           # Eloquent models + Proxy classes
├── Repositories/     # Data access layer
├── Contracts/        # Interfaces for models and repositories
├── Resources/
│   ├── views/        # Blade templates (admin/, shop/)
│   ├── lang/         # Localization (translatable strings)
│   └── assets/       # CSS/JS source files
├── Routes/           # admin-routes.php, shop-routes.php, api.php
├── Providers/        # ServiceProvider + ModuleServiceProvider
└── Listeners/        # Event listeners
```

### Frontend Assets

Admin, Shop, and Installer each have independent Vite builds. Run `npm install` and `npm run dev`/`npm run build` from within the respective package directory:
- **Admin**: `packages/Webkul/Admin/` builds to `public/themes/admin/default/build/`
- **Shop**: `packages/Webkul/Shop/` builds to `public/themes/shop/default/build/`
- **Installer**: `packages/Webkul/Installer/`

Vue 3 components are used within Blade templates via `@pushOnce('scripts')` / Blade component slots.

### Naming Conventions

- **Namespace**: `Webkul\<PackageName>` (e.g., `Webkul\Product`)
- **Routes**: Separate `admin-routes.php` and `shop-routes.php` per package
- **Models**: Singular (`Product`, `Category`)
- **Repositories**: `<Model>Repository` (e.g., `ProductRepository`)
- **Controllers**: `<Model>Controller` in `Http/Controllers/Admin/` or `Shop/`


Or use: `php artisan package:make Webkul/<Name>` (requires `bagisto/bagisto-package-generator`)

## CI Pipeline

- **pest_tests.yml**: Pest tests on PHP 8.3 + MySQL 8.0
- **pint_tests.yml**: Code style checks with Laravel Pint
- **admin_playwright_tests.yml / shop_playwright_tests.yml**: E2E tests (6 parallel shards)
- **translation_tests.yml**: Translation file validation



# Proyecto ByLopez - Instrucciones para Claude Code

## Proyecto

Aplicación basada en Laravel + Bagisto.

El desarrollo personalizado de ByLopez debe mantenerse aislado del core de Bagisto.

---

## Restricción absoluta

NO modificar:

packages/Webkul/

NO modificar el core de Bagisto.

Se permite inspeccionar packages/Webkul/ únicamente como referencia para:

- arquitectura
- controladores
- repositories
- DataGrids
- Blade
- componentes UI
- permisos
- patrones de Bagisto
- validaciones
- estructura de módulos

Todo desarrollo específico de Corte y Confección debe mantenerse dentro de:

packages/ByLopez/CorteConfeccion/

No crear soluciones en el core para resolver problemas del módulo.

---

## Antes de modificar código

Antes de implementar cualquier funcionalidad:

1. Inspeccionar el código existente relacionado.
2. Identificar modelos, servicios, controladores, rutas, vistas y migraciones involucradas.
3. Buscar si ya existe una funcionalidad similar.
4. Evitar duplicar lógica.
5. Identificar posibles regresiones.
6. Entender primero el flujo de negocio existente.

No asumir que algo no existe sin buscarlo.

---

## Arquitectura

Preferir reutilizar la arquitectura existente.

Evitar:

- lógica de negocio compleja directamente en Blade
- estados duplicados en múltiples archivos
- validaciones repetidas
- consultas duplicadas
- código muerto
- métodos enormes
- soluciones temporales cuando existe una solución estructural

Preferir cuando corresponda:

- services
- support classes
- state machines
- Form Requests
- repositories
- componentes reutilizables
- helpers centralizados

No crear abstracciones innecesarias.

---

## Flujo de negocio

La lógica del negocio tiene prioridad sobre simplificaciones técnicas.

Antes de cambiar un flujo existente:

1. identificar cómo funciona actualmente;
2. explicar si existe una inconsistencia;
3. preservar trazabilidad;
4. evitar saltarse estados;
5. evitar actualizar datos históricos de forma destructiva.

Si el requerimiento del usuario puede generar inconsistencias de negocio, señalarlo antes de implementarlo.

---

## UX / Frontend

Actuar como desarrollador frontend senior.

Las vistas administrativas deben ser:

- intuitivas
- limpias
- fáciles de escanear
- coherentes con Bagisto
- responsivas cuando sea posible
- con jerarquía visual clara

Priorizar:

- pocos clics
- reducción de ruido visual
- agrupación lógica
- accordions cuando ayuden
- estados visuales claros
- feedback de acciones
- campos con nombres comprensibles
- evitar información duplicada

No cambiar el flujo de negocio solamente para hacer una interfaz más simple.

---

## Seguridad y permisos

Antes de agregar o modificar acciones:

- revisar permisos existentes
- revisar roles
- evitar depender únicamente de ocultar botones en frontend
- validar autorización también en backend

---

## Base de datos

Antes de crear una migración:

- revisar tablas existentes
- revisar relaciones
- revisar nullable/default
- considerar datos históricos existentes

No destruir información histórica salvo solicitud explícita.

---

## Calidad

No solucionar únicamente el síntoma de un bug.

Buscar la causa raíz.

Antes de finalizar:

- revisar sintaxis PHP
- namespaces
- imports
- rutas
- relaciones
- permisos
- estados
- validaciones
- posibles referencias rotas

Ejecutar las pruebas disponibles que sean relevantes.

Nunca afirmar que algo fue probado si no se ejecutó realmente.

---

## Alcance

No modificar archivos fuera del alcance del requerimiento sin una razón clara.

Si durante el trabajo se detecta un problema adicional:

- informarlo;
- no corregirlo automáticamente si implica ampliar significativamente el alcance.

---

## Respuesta final

Ser conciso.

Al terminar informar:

- cambios realizados
- archivos principales modificados
- pruebas ejecutadas y resultado
- riesgos o pendientes encontrados
- confirmar si packages/Webkul/ permaneció sin cambios
