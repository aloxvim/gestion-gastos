# Bot de Telegram - Gestión de Gastos

Bot de Telegram para interactuar con la aplicación de Gestión de Gastos.

## 🚀 Características

- ✅ Inicio de sesión con usuario y contraseña
- ✅ Soporte para verificación en 2 pasos (2FA)
- ✅ Ver balance total de cuentas
- ✅ Listar todas las cuentas
- ✅ Ver detalles de cuentas específicas
- ✅ Listar últimos movimientos
- ✅ Crear nuevos movimientos (ingresos/gastos)
- ✅ Adjuntar archivos a movimientos
- ✅ Eliminar movimientos
- ✅ Cierre de sesión seguro

## 📋 Requisitos

- Python 3.10+
- Token de bot de Telegram (obtenido de @BotFather)
- API backend funcionando

## 🔧 Instalación

### Opción 1: Docker (recomendado)

El bot ya está configurado en el `docker-compose.yml` principal.

```bash
# Configurar variables de entorno
cp .env.example .env
nano .env  # Añadir el token del bot

# Levantar servicios (incluye el bot)
docker-compose up -d
```

### Opción 2: Local

```bash
cd telegram_bot

# Crear entorno virtual
python -m venv venv
source venv/bin/activate  # En Windows: venv\Scripts\activate

# Instalar dependencias
pip install -r requirements.txt

# Configurar variables de entorno
cp .env.example .env
nano .env  # Añadir configuración

# Ejecutar bot
python bot.py
```

## 🤖 Crear el Bot en Telegram

1. Buscar **@BotFather** en Telegram
2. Enviar `/newbot`
3. Seguir las instrucciones:
   - Nombre del bot: `Gestión de Gastos Bot`
   - Username: `gestion_gastos_bot` (debe terminar en "bot")
4. Copiar el token que te proporciona
5. Pegarlo en el archivo `.env`

## 📱 Comandos Disponibles

### Sesión
- `/start` - Iniciar el bot
- `/login` - Iniciar sesión
- `/logout` - Cerrar sesión

### Consultas
- `/balance` - Ver balance total
- `/cuentas` - Listar todas las cuentas
- `/cuenta [número]` - Ver detalles de una cuenta
- `/movimientos [cantidad]` - Ver últimos movimientos (default: 10)

### Acciones
- `/nuevo` - Crear nuevo movimiento (paso a paso)
- `/mover` - Mover dinero entre tus cuentas (paso a paso, con adjunto opcional). No cuenta como ingreso ni como gasto
- `/eliminar [ID]` - Eliminar un movimiento (si es un movimiento de dinero, se eliminan la salida y la entrada)

### Ayuda
- `/ayuda` - Ver lista de comandos
- `/cancelar` - Cancelar operación actual

## 🔐 Seguridad

- El bot **NO almacena** contraseñas
- Las sesiones se mantienen en memoria (se pierden al reiniciar)
- Soporta autenticación en 2 pasos (2FA)
- Los archivos temporales se eliminan después de procesarse

## 📝 Flujo de Uso

### 1. Iniciar sesión

```
Usuario: /login
Bot: Ingresa tu nombre de usuario:
Usuario: john_doe
Bot: Ahora ingresa tu contraseña:
Usuario: [contraseña]
Bot: ✅ ¡Bienvenido, john_doe!
```

### 2. Ver balance

```
Usuario: /balance
Bot: 💼 Resumen Financiero
     Balance Total: 1,234.56 EUR
     Cuentas: 3
```

### 3. Crear movimiento

```
Usuario: /nuevo
Bot: ¿Qué tipo de movimiento?
     1️⃣ Ingreso 📈
     2️⃣ Gasto 📉
Usuario: 1
Bot: Selecciona la cuenta:
     1. 🏦 Santander
     2. 💵 Efectivo
Usuario: 1
Bot: Ingresa la cantidad:
Usuario: 100
Bot: ¿Deseas agregar notas?
Usuario: Salario mensual
Bot: ¿Deseas adjuntar un archivo?
Usuario: /omitir
Bot: ✅ Ingreso registrado exitosamente!
```

## 🐛 Solución de Problemas

### El bot no responde
- Verificar que el token sea correcto
- Verificar que el bot esté ejecutándose
- Revisar logs: `docker logs gastos_telegram_bot`

### Error de conexión con API
- Verificar que el backend esté funcionando
- En Docker, verificar que estén en la misma red
- Verificar la variable `API_URL`

### Sesión expirada
- Las sesiones duran 2 horas (configurable en backend)
- Volver a hacer `/login`

## 📊 Estructura del Código

```
telegram_bot/
├── bot.py                      # Archivo principal
├── config.py                   # Configuración y constantes
├── requirements.txt            # Dependencias
├── Dockerfile                  # Imagen Docker
├── services/
│   ├── api_client.py          # Cliente API REST
│   └── session_manager.py     # Gestor de sesiones
├── handlers/
│   ├── auth_handlers.py       # Login/Logout
│   ├── query_handlers.py      # Consultas
│   └── movement_handlers.py   # Crear/Editar/Eliminar
└── utils/
    └── formatters.py          # Formato de mensajes
```

## 🔄 Actualizaciones Futuras

Posibles mejoras:
- [ ] Editar movimientos existentes
- [ ] Filtros avanzados de movimientos
- [ ] Estadísticas y gráficas
- [ ] Notificaciones de metas alcanzadas
- [ ] Exportar datos desde el bot
- [ ] Comandos inline
- [ ] Teclados personalizados

## 📞 Soporte

Para reportar errores o sugerencias, crear un issue en el repositorio de GitHub.