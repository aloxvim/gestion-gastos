-- ============================================
-- Migración 002: Análisis con IA (Google Gemini)
-- ============================================
-- Guarda la clave API de Google AI Studio de cada usuario, que se usa para
-- clasificar sus movimientos por categorías a partir de las anotaciones.
--
-- Esta migración solo CREA una tabla nueva: no modifica ni borra ninguna tabla
-- ni dato existente. Va en una tabla aparte (y no como columna de usuarios)
-- para que los "SELECT * FROM usuarios" existentes nunca puedan devolver la clave.
--
-- Aplicar en una instalación existente (hacer antes una copia de seguridad).
-- La contraseña de gastos_user está en .env (DB_APP_PASSWORD):
--   DB_PASS="$(grep '^DB_APP_PASSWORD=' .env | cut -d= -f2-)"
--   docker exec -e MYSQL_PWD="$DB_PASS" gastos_db mysqldump -ugastos_user --no-tablespaces gastos_db > backup_antes_analisis_ia.sql
--   docker exec -i -e MYSQL_PWD="$DB_PASS" gastos_db mysql -ugastos_user gastos_db < backend/database/migrations/002_analisis_ia.sql
--
-- Se puede ejecutar varias veces: si la tabla ya existe no hace nada.
--
-- Revertir (se pierden las claves guardadas, nada más):
--   DROP TABLE configuracion_ia;
-- ============================================

CREATE TABLE IF NOT EXISTS configuracion_ia (
    id_usuario INT PRIMARY KEY,
    clave_api_gemini VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
