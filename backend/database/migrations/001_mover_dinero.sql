-- ============================================
-- Migración 001: "Mover dinero" entre cuentas (issue #52)
-- ============================================
-- Un "mover dinero" se guarda como dos movimientos normales (una retirada en la
-- cuenta de origen y un ingreso en la de destino) que comparten el mismo
-- id_transferencia. Así los triggers de balance siguen funcionando sin cambios.
--
-- Esta migración solo AÑADE una columna opcional: no modifica ni borra datos,
-- y los movimientos existentes quedan con id_transferencia = NULL (ingresos y
-- gastos normales).
--
-- Aplicar en una instalación existente (hacer antes una copia de seguridad):
--   docker exec gastos_db mysqldump -ugastos_user -pgastos_pass --no-tablespaces gastos_db > backup_antes_mover_dinero.sql
--   (--no-tablespaces es necesario: gastos_user no tiene el privilegio PROCESS)
--   docker exec -i gastos_db mysql -ugastos_user -pgastos_pass gastos_db < backend/database/migrations/001_mover_dinero.sql
--
-- Si ya está aplicada, MySQL responde "Duplicate column name 'id_transferencia'"
-- y no cambia nada.
--
-- Revertir (los "mover dinero" pasarían a contar como ingreso + gasto normales):
--   ALTER TABLE movimientos DROP INDEX idx_transferencia, DROP COLUMN id_transferencia;
-- ============================================

ALTER TABLE movimientos
    ADD COLUMN id_transferencia CHAR(32) NULL DEFAULT NULL AFTER adjunto,
    ADD INDEX idx_transferencia (id_transferencia);
