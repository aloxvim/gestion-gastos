# 💰 Gestión de Gastos

Aplicación web completa para la gestión de ingresos y gastos personales, con backend en PHP, frontend en React y bot de Telegram.

![Estado](https://img.shields.io/badge/estado-100%25%20funcional-brightgreen)
![Backend](https://img.shields.io/badge/backend-PHP%208.2-blue)
![Frontend](https://img.shields.io/badge/frontend-React%2018-cyan)
![Bot](https://img.shields.io/badge/bot-Telegram-blue)

## 🌟 Características Principales

### 💼 Gestión Financiera
- ✅ Múltiples cuentas (bancarias y efectivo)
- ✅ Registro de ingresos y gastos
- ✅ **Mover dinero** entre tus cuentas (p. ej. de efectivo al banco) sin que cuente como ingreso ni como gasto
- ✅ Balance automático con triggers SQL
- ✅ Metas de ahorro con seguimiento
- ✅ Archivos adjuntos (PDF e imágenes, hasta 5 MB), también al mover dinero
- ✅ Búsqueda de movimientos por anotaciones y filtros por cuenta, tipo y fechas
- ✅ Copias de seguridad en JSON con adjuntos incluidos (importación de hasta 256 MB)
- ✅ Modo oscuro (claro, oscuro o según el sistema)
- ✅ **Análisis con IA** (Google Gemini): cuánto has gastado e ingresado por categoría, deducida de tus anotaciones

### 🔐 Seguridad
- ✅ Sesiones con tokens aleatorios que caducan
- ✅ Verificación en 2 pasos (2FA) por correo
- ✅ Contraseñas cifradas con bcrypt
- ✅ Sanitización de inputs y consultas preparadas
- ✅ Roles de usuario (4 niveles)
- ✅ Base de datos no expuesta a la red y phpMyAdmin protegido con contraseña

### 👥 Administración
- ✅ Panel de administración completo
- ✅ Gestión de usuarios
- ✅ Historial de acciones (auditoría)
- ✅ Estadísticas del sistema
- ✅ Gestión de etiquetas

### 🤖 Bot de Telegram
- ✅ Inicio de sesión desde Telegram (con 2FA)
- ✅ Consulta de balance y cuentas
- ✅ Crear movimientos con archivos
- ✅ Mover dinero entre cuentas
- ✅ Ver historial de movimientos
- ✅ Eliminar movimientos

## 🏗️ Arquitectura

```
                 Red local
                     │
        ┌────────────┼──────────────┐
        │ :3000      │ :8082        │ :8081
┌───────▼───────┐ ┌──▼──────────┐ ┌─▼──────────┐   ┌──────────────┐
│   Frontend    │ │ Backend API │ │ phpMyAdmin │   │ Telegram Bot │
│ React + Nginx │─▶ PHP+Apache  │ └─────┬──────┘   │    Python    │
└───────────────┘ └──────┬──────┘       │          └──────┬───────┘
                         │      ┌───────▼──┐              │
                         └──────▶  MySQL   │◀─────────────┘ (vía API)
                                └──────────┘
              (MySQL solo es accesible dentro de Docker)
```

| Servicio | Puerto | Para qué |
|----------|--------|----------|
| Frontend | `3000` | La aplicación web. Su Nginx también reenvía `/api` y `/uploads` al backend |
| Backend | `8082` | API REST y página de instalación inicial |
| phpMyAdmin | `8081` | Corregir datos a mano. Pide usuario y contraseña |
| MySQL | — | Sin puerto publicado: solo lo usan el backend y phpMyAdmin |

## 📦 Tecnologías

### Backend
- PHP 8.2
- MySQL 8.0
- Apache 2.4
- Composer
- PHPMailer

### Frontend
- React 18
- Vite
- Tailwind CSS
- React Router
- Axios

### Bot
- Python 3.11
- python-telegram-bot
- requests

### DevOps
- Docker
- Docker Compose
- Nginx

## 🚀 Instalación

### 1. Clonar repositorio

```bash
git clone https://github.com/alockgoy/gestion-gastos.git
cd gestion-gastos
```

### 2. Configurar variables de entorno

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

Configurar:
- **Contraseñas de la base de datos (obligatorias):** `DB_APP_PASSWORD` y `DB_ROOT_PASSWORD`. Usa valores largos y aleatorios; puedes generarlos con:
  ```bash
  od -An -tx1 -N20 /dev/urandom | tr -d ' \n'; echo
  ```
- Credenciales SMTP (Gmail, etc.), necesarias para el 2FA y la recuperación de contraseña
- Token del bot de Telegram
- `GITHUB_REPO_URL` (enlace al código fuente que muestra la web)

> ⚠️ `.env` contiene contraseñas y no se sube a GitHub. No lo compartas.

### 3. Levantar con Docker

```bash
docker compose up -d --build
```

La primera vez, MySQL crea la base de datos con `backend/database/init.sql` (tablas, triggers y etiquetas iniciales).

### 4. Instalación inicial

1. Ir a http://localhost:8082
2. Completar el formulario de instalación
3. Crear el usuario propietario

### 5. Acceder a la aplicación

- **Aplicación web**: http://localhost:3000 (o `http://IP-del-servidor:3000` desde otros dispositivos de la red)
- **Backend API**: http://localhost:8082/api
- **phpMyAdmin**: http://localhost:8081, con usuario `gastos_user` y la contraseña `DB_APP_PASSWORD` de `.env` (no permite entrar como `root`)

> 🔒 Ninguno de estos puertos debería estar abierto a Internet. No los redirijas en el router.

## 📱 Configurar Bot de Telegram

1. Buscar **@BotFather** en Telegram
2. Crear bot: `/newbot`
3. Copiar el token
4. Añadir token en `.env`:
   ```
   TELEGRAM_BOT_TOKEN=tu_token_aqui
   ```
5. Aplicar el cambio: `docker compose up -d telegram_bot`
6. Buscar tu bot en Telegram y usar `/start`

Guía completa en [telegram_bot/readme.md](telegram_bot/readme.md).

## 🔄 Actualizar una instalación existente

Los contenedores **no leen el código de la carpeta**, sino que lo copian al construirse. Por eso un cambio no se aplica hasta reconstruir, y descargar el código nuevo no afecta a la app en marcha.

```bash
# 1. Copia de seguridad (ver la sección siguiente)

# 2. Descargar el código nuevo
git checkout main
git pull

# 3. Aplicar las migraciones nuevas, si las hay (ver "Migraciones de base de datos")

# 4. Reconstruir y arrancar
docker compose up -d --build
```

Si la actualización incluye cambios en la base de datos, **aplica la migración antes de reconstruir** (paso 3 antes del 4): el código nuevo la necesita, y el antiguo funciona igual con ella.

### Migraciones de base de datos

`init.sql` solo se ejecuta cuando la base de datos está vacía. Los cambios de estructura posteriores están en `backend/database/migrations/`, numerados en el orden en que hay que aplicarlos. Cada archivo explica en su cabecera qué cambia y cómo deshacerlo.

| Migración | Cambio |
|-----------|--------|
| `001_mover_dinero.sql` | Añade `id_transferencia` a `movimientos` para "Mover dinero" |
| `002_analisis_ia.sql` | Crea la tabla `configuracion_ia` (clave API de Gemini de cada usuario) para el análisis con IA |

Para aplicar una:

```bash
DB_PASS="$(grep '^DB_APP_PASSWORD=' .env | cut -d= -f2-)"
docker exec -i -e MYSQL_PWD="$DB_PASS" gastos_db mysql -ugastos_user gastos_db < backend/database/migrations/001_mover_dinero.sql
```

Si ya estaba aplicada, MySQL responde con un error del tipo `Duplicate column name` y no cambia nada (la 002 simplemente no hace nada).

> Las instalaciones anteriores a la versión con contraseñas en `.env` deben ejecutar una vez `./scripts/cambiar_contrasenas_bd.sh` justo después del `git pull`. Hasta entonces, `docker compose` se niega a arrancar y avisa de que falta `DB_APP_PASSWORD`.

## 💾 Copias de Seguridad

Desde la carpeta del proyecto:

```bash
DB_PASS="$(grep '^DB_APP_PASSWORD=' .env | cut -d= -f2-)"

# Base de datos
docker exec -e MYSQL_PWD="$DB_PASS" gastos_db mysqldump -ugastos_user --no-tablespaces gastos_db > backup_$(date +%Y%m%d).sql
tail -1 backup_$(date +%Y%m%d).sql      # debe terminar en "-- Dump completed on ..."

# Archivos adjuntos (sudo y -a: algunos pertenecen al usuario del contenedor)
sudo cp -a uploads "uploads_backup_$(date +%Y%m%d)"
```

Guarda también una copia de `.env`: sin sus contraseñas no podrás usar la base de datos.

Para **restaurar** la base de datos se usa `root`, porque el volcado incluye los triggers:

```bash
ROOT_PASS="$(grep '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)"
docker exec -i -e MYSQL_PWD="$ROOT_PASS" gastos_db mysql -uroot gastos_db < backup_AAAAMMDD.sql
```

Cada usuario también puede descargar su propia copia desde la web (**Movimientos → Exportar JSON**, con adjuntos incluidos) e importarla en otra cuenta.

## 🔑 Contraseñas de la Base de Datos

Las contraseñas de MySQL (`gastos_user` y `root`) están en `.env`. Para cambiarlas en una instalación existente:

```bash
./scripts/cambiar_contrasenas_bd.sh
```

El script genera contraseñas nuevas, las aplica en MySQL, las guarda en `.env` (antes hace una copia en `.env.bak-FECHA`), recrea los contenedores y comprueba que la app sigue funcionando. Al terminar muestra la contraseña nueva de phpMyAdmin.

Editar solo `.env` no basta: las variables `MYSQL_*` únicamente se aplican al crear la base de datos.

## 📖 Documentación

### Estructura del Proyecto

```
gestion-gastos/
├── backend/                 # API REST en PHP
│   ├── app/                 # Controladores, modelos y núcleo (router, BD)
│   ├── database/init.sql    # Esquema inicial, triggers y datos base
│   ├── database/migrations/ # Cambios de esquema para instalaciones existentes
│   └── scripts/cron.php     # Tareas programadas
├── frontend/                # Aplicación React (servida por Nginx)
├── telegram_bot/            # Bot de Telegram
├── phpmyadmin/              # Configuración de seguridad de phpMyAdmin
├── scripts/                 # Utilidades de administración del servidor
├── uploads/                 # Adjuntos y fotos de perfil (no se sube a GitHub)
├── mysql-data/              # Datos de MySQL (no se sube a GitHub)
├── docker-compose.yml       # Orquestación Docker
└── .env.example             # Plantilla de variables de entorno
```

### Endpoints API Principales

```
POST   /api/auth/login              # Iniciar sesión
POST   /api/auth/register           # Registrarse
GET    /api/user/profile            # Ver perfil
GET    /api/accounts                # Listar cuentas
POST   /api/accounts                # Crear cuenta
GET    /api/movements               # Listar movimientos (filtros: id_cuenta, tipo, fecha_desde, fecha_hasta, busqueda)
POST   /api/movements               # Crear movimiento (ingreso o gasto)
POST   /api/movements/transfer      # Mover dinero entre dos cuentas propias
GET    /api/movements/stats         # Estadísticas (sin contar los movimientos de dinero)
GET    /api/movements/export/json   # Exportar copia de seguridad JSON
GET    /api/movements/export/csv    # Exportar CSV
POST   /api/movements/import        # Importar copia de seguridad JSON
PUT    /api/analysis/key            # Guardar la clave API de Google AI Studio
POST   /api/analysis                # Analizar con IA (fecha_desde, fecha_hasta)
```

Todas las rutas están en `backend/public/api/index.php`. Las protegidas necesitan la cabecera `Authorization: Bearer <token>`.

### Comandos del Bot

```
/login          # Iniciar sesión
/balance        # Ver balance
/cuentas        # Listar cuentas
/movimientos    # Ver últimos movimientos
/nuevo          # Crear ingreso o gasto
/mover          # Mover dinero entre tus cuentas
/eliminar [ID]  # Eliminar movimiento
/logout         # Cerrar sesión
```

Guía completa en [telegram_bot/readme.md](telegram_bot/readme.md).

## 🎯 Roles de Usuario

| Rol | Permisos |
|-----|----------|
| **Propietario** | Control total del sistema, no eliminable |
| **Administrador** | Gestión de usuarios (excepto propietario) |
| **Usuario** | Gestión de sus propias cuentas y movimientos |
| **Solicita** | Usuario que solicitó ser administrador |

## 🔧 Desarrollo

Lo más sencillo es trabajar con Docker y reconstruir el servicio que cambies:

```bash
docker compose up -d --build backend    # o frontend, telegram_bot
docker compose logs -f backend          # ver errores de PHP
```

No hay tests automáticos. La única comprobación automática es que el frontend compile (`npm run build`, que también se ejecuta al construir su imagen).

Sin Docker:

```bash
# Frontend (el servidor de desarrollo reenvía /api a http://backend:80)
cd frontend
npm install
npm run dev

# Bot
cd telegram_bot
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python bot.py       # necesita TELEGRAM_BOT_TOKEN y API_URL
```

Los cambios de estructura de la base de datos van en `init.sql` (instalaciones nuevas) **y** en una migración nueva en `backend/database/migrations/` (instalaciones existentes). Deben ser aditivos, para no romper los datos que ya existen.

## 📊 Características Destacadas

### Balance Automático
Los triggers SQL actualizan el balance automáticamente al crear, editar o eliminar movimientos.

### Mover Dinero
Pasar dinero de una cuenta a otra (por ejemplo, ingresar efectivo en el banco) se guarda como una salida y una entrada enlazadas. Los saldos de ambas cuentas se actualizan, pero no cuenta como gasto ni como ingreso: así las estadísticas reflejan solo lo que realmente gastas.

### Análisis con IA
Cada usuario guarda su propia clave API gratuita de [Google AI Studio](https://aistudio.google.com/apikey) en Configuración → Análisis IA. Como los movimientos no tienen categoría, Gemini la deduce de las anotaciones; los importes los suma la aplicación, así que las cifras son exactas aunque la clasificación pueda fallar. A Google solo se envían las anotaciones y los importes, no los nombres de las cuentas ni las fechas. Los "Mover dinero" no cuentan; cualquier otro ingreso o gasto se suma siempre, diga lo que diga su anotación (los traspasos antiguos anotados como gasto + ingreso conviene sustituirlos por un "Mover dinero"). Si un modelo está saturado o ha agotado su cuota gratuita, se prueba el siguiente (por defecto `gemini-flash-latest`, `gemini-flash-lite-latest` y `gemini-2.5-flash`); la lista se cambia con `GEMINI_MODEL` en `.env`, separada por comas.

### Metas de Ahorro
Configura objetivos de ahorro y ve tu progreso en tiempo real.

### Historial Completo
Auditoría de todas las acciones realizadas en el sistema (solo propietario).

### Exportación/Importación
Exporta tus datos en JSON, con adjuntos incluidos, e impórtalos en otra cuenta. Al importar se reconstruyen las cuentas con sus saldos y los movimientos en orden cronológico, sin perder ninguno.

### Archivos Adjuntos
Adjunta recibos, facturas o comprobantes (PDF, imágenes hasta 5 MB).

## 🔒 Seguridad

- Contraseñas hasheadas con bcrypt (cost 12)
- Tokens de sesión aleatorios con `random_bytes()`, guardados en la base de datos y con caducidad
- Prepared statements (prevención SQL Injection)
- Validación y sanitización de inputs
- CORS configurado
- MySQL sin puerto publicado: solo accesible dentro de Docker
- phpMyAdmin con usuario y contraseña obligatorios, sin acceso como `root` y con cierre de sesión tras 30 minutos de inactividad
- Contraseñas de la base de datos fuera del repositorio (en `.env`)

## 📝 Tareas Programadas

`backend/scripts/cron.php` se encarga de:
- Enviar un recordatorio a los usuarios sin iniciar sesión desde hace 1 año
- Avisar a los 18 meses de que la cuenta se eliminará
- Eliminar las cuentas inactivas durante más de 2 años
- Limpiar sesiones y tokens caducados

**No se ejecuta automáticamente**: hay que programarlo en el servidor. Por ejemplo, para el día 1 de cada mes a las 3:00, añade con `crontab -e`:

```
0 3 1 * * docker exec gastos_backend php /var/www/html/scripts/cron.php >> $HOME/gastos_cron.log 2>&1
```

Para ejecutarlo manualmente:

```bash
docker exec gastos_backend php /var/www/html/scripts/cron.php
```

## 🩺 Solución de Problemas

| Síntoma | Causa y solución |
|---------|------------------|
| `required variable DB_APP_PASSWORD is missing a value` | Falta la contraseña en `.env`. En una instalación nueva, rellénala. En una existente, ejecuta `./scripts/cambiar_contrasenas_bd.sh` |
| `mysqldump: ... PROCESS privilege` | Falta `--no-tablespaces` en el comando de copia de seguridad |
| `cp: ... Permiso denegado` al copiar `uploads` | Usa `sudo cp -a` |
| phpMyAdmin no deja entrar como `root` | Es intencionado. Entra con `gastos_user` |
| Un cambio en el código no se ve | Hay que reconstruir: `docker compose up -d --build <servicio>` |
| Error al importar una copia de seguridad grande | El límite es 256 MB. Ver `backend/apache-config.conf` y `frontend/nginx.conf` |

## 🤝 Contribuir

1. Fork el proyecto
2. Crear rama: `git checkout -b feature/nueva-funcionalidad`
3. Commit: `git commit -m 'Añadir nueva funcionalidad'` (añade `Closes #N` en el mensaje para cerrar la issue correspondiente al fusionarlo)
4. Push: `git push origin feature/nueva-funcionalidad`
5. Crear Pull Request

## 📄 Licencia

Este proyecto es de código abierto.

## 🐛 Reportar Problemas

Crear un issue en GitHub con:
- Descripción del problema
- Pasos para reproducir
- Logs relevantes (`docker compose logs backend`)
- Screenshots si aplica


⭐ Si te gusta el proyecto, dale una estrella en GitHub!

**Aplicación creada usando Claude 4.5**
