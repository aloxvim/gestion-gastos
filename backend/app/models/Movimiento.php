<?php

namespace Models;

use Core\Database;
use Exception;

class Movimiento
{
    /**
     * "Mover dinero" entre cuentas: se guarda como una retirada en la cuenta de
     * origen y un ingreso en la de destino que comparten id_transferencia. Así
     * los triggers de balance no cambian. No cuentan como ingresos ni gastos en
     * estadísticas ni filtros, y se crean, editan y borran siempre por parejas.
     *
     * CONTRAPARTE_*: datos del otro movimiento del par (la cuenta de destino si
     * este es la retirada, la de origen si es el ingreso). NULL en los normales.
     *
     * El adjunto de un "mover dinero" se guarda solo en la retirada; desde el
     * ingreso se ve como contraparte_adjunto.
     */
    private const CONTRAPARTE_COLUMNS = "t.id AS contraparte_id, t.id_cuenta AS contraparte_id_cuenta,
                ct.nombre AS contraparte_nombre, ct.color AS contraparte_color, t.adjunto AS contraparte_adjunto";
    private const CONTRAPARTE_JOIN = "LEFT JOIN movimientos t ON t.id_transferencia = m.id_transferencia AND t.id <> m.id
                LEFT JOIN cuentas ct ON ct.id = t.id_cuenta";

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Crea un nuevo movimiento
     */
    public function create($data)
    {
        // Validaciones
        if (!isset($data['tipo']) || !in_array($data['tipo'], ['ingreso', 'retirada'])) {
            throw new Exception("El tipo debe ser 'ingreso' o 'retirada'");
        }

        if (!isset($data['id_cuenta']) || empty($data['id_cuenta'])) {
            throw new Exception("Debe especificar una cuenta");
        }

        if (!isset($data['cantidad']) || $data['cantidad'] <= 0) {
            throw new Exception("La cantidad debe ser mayor a 0");
        }

        // Verificar saldo suficiente para retiradas
        if ($data['tipo'] === 'retirada') {
            $cuenta = (new Cuenta())->findById($data['id_cuenta']);
            if (!$cuenta) {
                throw new Exception("La cuenta especificada no existe");
            }

            if ($cuenta['balance'] < $data['cantidad']) {
                throw new Exception("Saldo insuficiente. Disponible: " . formatMoney($cuenta['balance'], $cuenta['moneda'] ?? 'EUR'));
            }
        }

        // Si no se especifica fecha, usar la actual
        if (!isset($data['fecha_movimiento'])) {
            $data['fecha_movimiento'] = date('Y-m-d H:i:s');
        }

        // Manejar archivo adjunto si existe
        if (isset($data['archivo']) && !empty($data['archivo'])) {
            $data['adjunto'] = $this->uploadFile($data['archivo']);
            unset($data['archivo']);
        }

        // Insertar movimiento (el trigger actualizará el balance automáticamente)
        $id = $this->db->insert('movimientos', $data);

        // Obtener información de la cuenta para el log
        $cuenta = (new Cuenta())->findById($data['id_cuenta']);
        $tipoAccion = $data['tipo'] === 'ingreso' ? 'ingreso' : 'retirada';

        logAction(
            $cuenta['id_usuario'],
            "ha registrado un {$tipoAccion} de " . formatMoney($data['cantidad']) .
            " en la cuenta '{$cuenta['nombre']}'"
        );

        return $id;
    }

    /**
     * Obtiene un movimiento por ID
     */
    public function findById($id)
    {
        $sql = "SELECT m.*, c.nombre as cuenta_nombre, c.id_usuario, " . self::CONTRAPARTE_COLUMNS . "
                FROM movimientos m
                INNER JOIN cuentas c ON m.id_cuenta = c.id
                " . self::CONTRAPARTE_JOIN . "
                WHERE m.id = :id";
        return $this->db->fetchOne($sql, ['id' => $id]);
    }

    /**
     * Obtiene movimientos de una cuenta
     */
    public function findByAccount($idCuenta, $filters = [])
    {
        $sql = "SELECT m.* FROM movimientos m WHERE m.id_cuenta = :id_cuenta";
        $params = ['id_cuenta' => $idCuenta];

        if (isset($filters['tipo'])) {
            $sql .= " AND m.tipo = :tipo";
            $params['tipo'] = $filters['tipo'];
        }

        if (isset($filters['fecha_desde'])) {
            $sql .= " AND m.fecha_movimiento >= :fecha_desde";
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (isset($filters['fecha_hasta'])) {
            $sql .= " AND m.fecha_movimiento <= :fecha_hasta";
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        // Ordenamiento
        $orderBy = $filters['order_by'] ?? 'fecha_movimiento';
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $sql .= " ORDER BY {$orderBy} {$orderDir}";

        // Paginación
        if (isset($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params['limit'] = (int) $filters['limit'];

            if (isset($filters['offset'])) {
                $sql .= " OFFSET :offset";
                $params['offset'] = (int) $filters['offset'];
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Obtiene todos los movimientos de un usuario
     */
    public function findByUser($idUsuario, $filters = [])
    {
        $sql = "SELECT m.*, c.nombre as cuenta_nombre, c.color as cuenta_color, " . self::CONTRAPARTE_COLUMNS . "
            FROM movimientos m
            INNER JOIN cuentas c ON m.id_cuenta = c.id
            " . self::CONTRAPARTE_JOIN . "
            WHERE c.id_usuario = :id_usuario";

        $params = ['id_usuario' => $idUsuario];

        // Filtros
        $sql .= $this->buildUserFilters($filters, $params);

        // Ordenamiento
        if (isset($filters['order_by']) && !empty($filters['order_by'])) {
            $orderBy = $filters['order_by'];
            $orderDir = isset($filters['order_dir']) && strtoupper($filters['order_dir']) === 'ASC' ? 'ASC' : 'DESC';

            // Validar campos permitidos para evitar SQL injection
            $allowedFields = ['fecha_movimiento', 'cantidad', 'id'];
            if (in_array($orderBy, $allowedFields)) {
                $sql .= " ORDER BY m.{$orderBy} {$orderDir}";
            } else {
                // Si el campo no es válido, usar orden por defecto
                $sql .= " ORDER BY m.fecha_movimiento DESC";
            }
        } else {
            // Orden por defecto: más reciente primero
            $sql .= " ORDER BY m.fecha_movimiento DESC";
        }

        // Paginación
        if (isset($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params['limit'] = (int) $filters['limit'];

            if (isset($filters['offset'])) {
                $sql .= " OFFSET :offset";
                $params['offset'] = (int) $filters['offset'];
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Cuenta total de movimientos de un usuario
     */
    public function countByUser($idUsuario, $filters = [])
    {
        $sql = "SELECT COUNT(*) 
                FROM movimientos m 
                INNER JOIN cuentas c ON m.id_cuenta = c.id 
                WHERE c.id_usuario = :id_usuario";

        $params = ['id_usuario' => $idUsuario];

        $sql .= $this->buildUserFilters($filters, $params);

        return $this->db->fetchColumn($sql, $params);
    }

    /**
     * Construye las condiciones WHERE de los filtros de movimientos.
     * Compartido por findByUser y countByUser para que el total de la
     * paginación siempre corresponda a los mismos filtros que el listado.
     */
    private function buildUserFilters($filters, &$params)
    {
        $sql = '';

        if (isset($filters['id_cuenta'])) {
            $sql .= " AND m.id_cuenta = :id_cuenta";
            $params['id_cuenta'] = $filters['id_cuenta'];
        }

        if (isset($filters['tipo'])) {
            if ($filters['tipo'] === 'transferencia') {
                $sql .= " AND m.id_transferencia IS NOT NULL";
            } else {
                // "Ingresos" y "Gastos" son solo los reales, sin los "mover dinero"
                $sql .= " AND m.tipo = :tipo AND m.id_transferencia IS NULL";
                $params['tipo'] = $filters['tipo'];
            }
        }

        // En listados, cada "mover dinero" aparece una sola vez (su retirada, que
        // trae la cuenta de destino como contraparte). Filtrando por una cuenta se
        // muestra la parte de esa cuenta, sea la retirada o el ingreso.
        // La exportación no lo activa: necesita los dos movimientos del par.
        if (!empty($filters['agrupar_transferencias']) && !isset($filters['id_cuenta'])) {
            $sql .= " AND NOT (m.id_transferencia IS NOT NULL AND m.tipo = 'ingreso')";
        }

        if (isset($filters['fecha_desde'])) {
            $sql .= " AND m.fecha_movimiento >= :fecha_desde";
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (isset($filters['fecha_hasta'])) {
            $sql .= " AND m.fecha_movimiento <= :fecha_hasta";
            // Una fecha sin hora ("2025-01-31") debe incluir todo ese día
            $params['fecha_hasta'] = strlen($filters['fecha_hasta']) === 10
                ? $filters['fecha_hasta'] . ' 23:59:59'
                : $filters['fecha_hasta'];
        }

        if (isset($filters['cantidad_min'])) {
            $sql .= " AND m.cantidad >= :cantidad_min";
            $params['cantidad_min'] = $filters['cantidad_min'];
        }

        if (isset($filters['cantidad_max'])) {
            $sql .= " AND m.cantidad <= :cantidad_max";
            $params['cantidad_max'] = $filters['cantidad_max'];
        }

        // Búsqueda en las anotaciones. La colación utf8mb4_unicode_ci ya ignora
        // mayúsculas y tildes; se escapan los comodines de LIKE (% y _)
        if (isset($filters['busqueda']) && trim($filters['busqueda']) !== '') {
            $sql .= " AND m.notas LIKE :busqueda";
            $params['busqueda'] = '%' . addcslashes(trim($filters['busqueda']), '%_\\') . '%';
        }

        return $sql;
    }

    /**
     * Actualiza un movimiento
     */
    /* public function update($id, $data)
    {
        $movimiento = $this->findById($id);

        if (!$movimiento) {
            throw new Exception("Movimiento no encontrado");
        }

        // No permitir cambiar la cuenta
        //unset($data['id_cuenta']);

        // Manejar archivo adjunto si existe
        if (isset($data['archivo']) && !empty($data['archivo'])) {
            // Eliminar archivo anterior si existe
            if ($movimiento['adjunto']) {
                $this->deleteFile($movimiento['adjunto']);
            }
            $data['adjunto'] = $this->uploadFile($data['archivo']);
            unset($data['archivo']);
        }

        $updated = $this->db->update('movimientos', $data, 'id = :id', ['id' => $id]);

        if ($updated) {
            $cuenta = (new Cuenta())->findById($movimiento['id_cuenta']);
            logAction(
                $cuenta['id_usuario'],
                "ha modificado un movimiento en la cuenta '{$cuenta['nombre']}'"
            );
        }

        return $updated;
    } */

    public function update($id, $data)
    {
        $movimiento = $this->findById($id);

        if (!$movimiento) {
            throw new Exception("Movimiento no encontrado");
        }

        // Manejar archivo adjunto si existe
        if (isset($data['archivo']) && !empty($data['archivo'])) {
            // Eliminar archivo anterior si existe
            if ($movimiento['adjunto']) {
                $this->deleteFile($movimiento['adjunto']);
            }
            $data['adjunto'] = $this->uploadFile($data['archivo']);
            unset($data['archivo']);
        }

        $updated = $this->db->update('movimientos', $data, 'id = :id', ['id' => $id]);

        if ($updated) {
            $cuenta = (new Cuenta())->findById($data['id_cuenta'] ?? $movimiento['id_cuenta']);
            logAction(
                $cuenta['id_usuario'],
                "ha modificado un movimiento en la cuenta '{$cuenta['nombre']}'"
            );
        }

        return $updated;
    }

    /**
     * Elimina un movimiento
     */
    public function delete($id)
    {
        $movimiento = $this->findById($id);

        if (!$movimiento) {
            throw new Exception("Movimiento no encontrado");
        }

        // Un "mover dinero" se borra siempre entero (sus dos movimientos)
        if (!empty($movimiento['id_transferencia'])) {
            return $this->deleteTransfer($movimiento);
        }

        // Eliminar archivo adjunto si existe
        if ($movimiento['adjunto']) {
            $this->deleteFile($movimiento['adjunto']);
        }

        $deleted = $this->db->delete('movimientos', 'id = :id', ['id' => $id]);

        if ($deleted) {
            $cuenta = (new Cuenta())->findById($movimiento['id_cuenta']);
            logAction(
                $cuenta['id_usuario'],
                "ha eliminado un movimiento de la cuenta '{$cuenta['nombre']}'"
            );
        }

        return $deleted;
    }

    /**
     * Mueve dinero entre dos cuentas del mismo usuario: crea la retirada en
     * origen y el ingreso en destino, enlazados por un id_transferencia común.
     *
     * $data: id_cuenta_origen, id_cuenta_destino, cantidad, notas, fecha_movimiento,
     *        archivo (opcional, entrada de $_FILES; se guarda en la retirada)
     */
    public function createTransfer($data)
    {
        $origen = (int) ($data['id_cuenta_origen'] ?? 0);
        $destino = (int) ($data['id_cuenta_destino'] ?? 0);
        $cantidad = round(floatval($data['cantidad'] ?? 0), 2);

        if (!$origen || !$destino) {
            throw new Exception("Debes indicar la cuenta de origen y la de destino");
        }
        if ($origen === $destino) {
            throw new Exception("La cuenta de origen y la de destino deben ser distintas");
        }
        if ($cantidad <= 0) {
            throw new Exception("La cantidad debe ser mayor a 0");
        }

        $comun = [
            'cantidad' => $cantidad,
            'notas' => $data['notas'] ?? null,
            'fecha_movimiento' => $data['fecha_movimiento'] ?? date('Y-m-d H:i:s'),
            'id_transferencia' => bin2hex(random_bytes(16)),
        ];

        $adjunto = !empty($data['archivo']) ? $this->uploadFile($data['archivo']) : null;

        try {
            return $this->withTransaction(function () use ($origen, $destino, $cantidad, $comun, $adjunto) {
                // FOR UPDATE: bloquea la cuenta de origen hasta terminar para que dos
                // operaciones simultáneas no puedan gastar el mismo saldo
                $cuentaOrigen = $this->db->fetchOne(
                    "SELECT id, nombre, balance, moneda, id_usuario FROM cuentas WHERE id = :id FOR UPDATE",
                    ['id' => $origen]
                );
                $cuentaDestino = $this->db->fetchOne(
                    "SELECT id, nombre, id_usuario FROM cuentas WHERE id = :id",
                    ['id' => $destino]
                );

                if (!$cuentaOrigen || !$cuentaDestino) {
                    throw new Exception("La cuenta especificada no existe");
                }
                if ($cuentaOrigen['id_usuario'] != $cuentaDestino['id_usuario']) {
                    throw new Exception("Solo se puede mover dinero entre cuentas del mismo usuario");
                }
                if ($cuentaOrigen['balance'] < $cantidad) {
                    throw new Exception("Saldo insuficiente en '{$cuentaOrigen['nombre']}'. Disponible: "
                        . formatMoney($cuentaOrigen['balance'], $cuentaOrigen['moneda'] ?? 'EUR'));
                }

                $idSalida = $this->db->insert('movimientos', $comun + ['tipo' => 'retirada', 'id_cuenta' => $origen, 'adjunto' => $adjunto]);
                $idEntrada = $this->db->insert('movimientos', $comun + ['tipo' => 'ingreso', 'id_cuenta' => $destino]);

                logAction(
                    $cuentaOrigen['id_usuario'],
                    "ha movido " . formatMoney($cantidad) . " de la cuenta '{$cuentaOrigen['nombre']}' a '{$cuentaDestino['nombre']}'"
                );

                return [
                    'id_transferencia' => $comun['id_transferencia'],
                    'movimiento_salida_id' => $idSalida,
                    'movimiento_entrada_id' => $idEntrada,
                ];
            });
        } catch (\Throwable $e) {
            // Si no se pudo guardar, no dejar el archivo huérfano
            if ($adjunto) {
                $this->deleteFile($adjunto);
            }
            throw $e;
        }
    }

    /**
     * Edita un "mover dinero" actualizando sus dos movimientos a la vez.
     * $movimiento es cualquiera de los dos (resultado de findById).
     *
     * $data (todo opcional): cantidad, notas, fecha_movimiento,
     *                        id_cuenta_origen, id_cuenta_destino,
     *                        archivo (entrada de $_FILES; sustituye al adjunto actual)
     */
    public function updateTransfer($movimiento, $data)
    {
        $par = $this->db->fetchAll(
            "SELECT id, tipo, id_cuenta, adjunto FROM movimientos WHERE id_transferencia = :t",
            ['t' => $movimiento['id_transferencia']]
        );

        $actual = ['retirada' => null, 'ingreso' => null];
        foreach ($par as $mov) {
            $actual[$mov['tipo']] = $mov;
        }

        $origen = (int) ($data['id_cuenta_origen'] ?? $actual['retirada']['id_cuenta'] ?? 0);
        $destino = (int) ($data['id_cuenta_destino'] ?? $actual['ingreso']['id_cuenta'] ?? 0);

        if ($origen && $destino && $origen === $destino) {
            throw new Exception("La cuenta de origen y la de destino deben ser distintas");
        }
        if (isset($data['cantidad']) && floatval($data['cantidad']) <= 0) {
            throw new Exception("La cantidad debe ser mayor a 0");
        }

        $comun = array_intersect_key($data, array_flip(['cantidad', 'notas', 'fecha_movimiento']));
        if (isset($comun['cantidad'])) {
            $comun['cantidad'] = round(floatval($comun['cantidad']), 2);
        }

        // El adjunto vive en la retirada (o en lo que quede del par si falta)
        $conAdjunto = $actual['retirada'] ?? $actual['ingreso'];
        $nuevoAdjunto = !empty($data['archivo']) ? $this->uploadFile($data['archivo']) : null;

        try {
            $this->withTransaction(function () use ($actual, $origen, $destino, $comun, $movimiento, $conAdjunto, $nuevoAdjunto) {
                // Los triggers revierten cada movimiento en su cuenta anterior y lo
                // aplican en la nueva, así que cambiar cuentas o cantidad cuadra solo
                foreach ($actual as $tipo => $mov) {
                    if ($mov === null) {
                        continue; // Par incompleto (p. ej. se eliminó la otra cuenta)
                    }
                    $cambios = $comun + ['id_cuenta' => $tipo === 'retirada' ? $origen : $destino];
                    if ($nuevoAdjunto && $mov['id'] === $conAdjunto['id']) {
                        $cambios['adjunto'] = $nuevoAdjunto;
                    }
                    $this->db->update('movimientos', $cambios, 'id = :id', ['id' => $mov['id']]);
                }

                logAction(
                    $movimiento['id_usuario'],
                    "ha modificado un movimiento de dinero entre cuentas"
                );
            });
        } catch (\Throwable $e) {
            if ($nuevoAdjunto) {
                $this->deleteFile($nuevoAdjunto);
            }
            throw $e;
        }

        // Solo tras guardar con éxito se borra el archivo sustituido
        if ($nuevoAdjunto && !empty($conAdjunto['adjunto'])) {
            $this->deleteFile($conAdjunto['adjunto']);
        }

        return true;
    }

    /**
     * Elimina los dos movimientos de un "mover dinero".
     * Se borran con un DELETE explícito (no en cascada) para que los triggers
     * de balance se ejecuten en ambas cuentas.
     */
    private function deleteTransfer($movimiento)
    {
        $adjuntos = $this->db->fetchAll(
            "SELECT adjunto FROM movimientos WHERE id_transferencia = :t AND adjunto IS NOT NULL",
            ['t' => $movimiento['id_transferencia']]
        );

        $deleted = $this->withTransaction(function () use ($movimiento) {
            $deleted = $this->db->delete(
                'movimientos',
                'id_transferencia = :t',
                ['t' => $movimiento['id_transferencia']]
            );

            logAction(
                $movimiento['id_usuario'],
                "ha eliminado un movimiento de dinero entre cuentas por " . formatMoney($movimiento['cantidad'])
            );

            return $deleted;
        });

        foreach ($adjuntos as $fila) {
            $this->deleteFile($fila['adjunto']);
        }

        return $deleted;
    }

    /**
     * Ejecuta $fn en una transacción, salvo que ya haya una abierta (por ejemplo
     * durante una importación), en cuyo caso se integra en ella.
     */
    private function withTransaction(callable $fn)
    {
        $pdo = $this->db->getConnection();
        $propia = !$pdo->inTransaction();

        if ($propia) {
            $pdo->beginTransaction();
        }

        try {
            $resultado = $fn();
            if ($propia) {
                $pdo->commit();
            }
            return $resultado;
        } catch (\Throwable $e) {
            if ($propia) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sube un archivo adjunto
     */
    private function uploadFile($file)
    {
        // Validar que el archivo temporal existe
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            throw new Exception("Error al subir el archivo");
        }

        // Verificar si es un archivo subido por POST o creado manualmente (PUT)
        $isUploadedFile = is_uploaded_file($file['tmp_name']);

        // Si no es un archivo subido por HTTP, verificar que sea un archivo temporal válido
        if (!$isUploadedFile) {
            // Verificar que el archivo esté en el directorio temporal del sistema
            $tmpDir = sys_get_temp_dir();
            $realTmpPath = realpath($file['tmp_name']);
            $realTmpDir = realpath($tmpDir);

            if (!$realTmpPath || strpos($realTmpPath, $realTmpDir) !== 0) {
                throw new Exception("Error al subir el archivo");
            }
        }

        // Validar tamaño
        if (!isValidFileSize($file['size'])) {
            throw new Exception("El archivo no puede superar los 5 MB");
        }

        // Validar tipo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!isValidFileType($mimeType, $extension)) {
            throw new Exception("Solo se permiten archivos PDF e imágenes (JPG, PNG, GIF, WEBP)");
        }

        // Generar nombre único
        $filename = generateUniqueFilename($file['name']);
        $destination = UPLOADS_PATH . '/movements/' . $filename;

        // Crear directorio si no existe
        if (!file_exists(UPLOADS_PATH . '/movements')) {
            mkdir(UPLOADS_PATH . '/movements', 0755, true);
        }

        // Mover/copiar archivo según su origen
        if ($isUploadedFile) {
            // Archivo subido por POST - usar move_uploaded_file
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception("Error al guardar el archivo");
            }
        } else {
            // Archivo temporal de PUT - usar rename o copy
            if (!rename($file['tmp_name'], $destination)) {
                // Si rename falla (diferente filesystem), intentar copy + unlink
                if (!copy($file['tmp_name'], $destination)) {
                    throw new Exception("Error al guardar el archivo");
                }
                @unlink($file['tmp_name']);
            }
        }

        return $filename;
    }

    /**
     * Elimina un archivo adjunto
     */
    private function deleteFile($filename)
    {
        $filepath = UPLOADS_PATH . '/movements/' . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
        }
    }

    /**
     * Exporta movimientos a CSV
     */
    public function exportToCSV($idUsuario, $filters = [])
    {
        $movimientos = $this->findByUser($idUsuario, $filters);

        $csv = "Fecha,Tipo,Cuenta,Cantidad,Notas\n";

        foreach ($movimientos as $mov) {
            $csv .= sprintf(
                "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $mov['fecha_movimiento'],
                $mov['tipo'],
                $mov['cuenta_nombre'],
                $mov['cantidad'],
                str_replace('"', '""', $mov['notas'] ?? '')
            );
        }

        return $csv;
    }

    /**
     * Exporta movimientos a JSON con adjuntos en Base64 y metadata de cuentas
     * Formato completo para backup/restore entre usuarios
     * INCLUYE balance actual para preservar balances iniciales
     *
     * Escribe directamente en la salida, movimiento a movimiento: con muchos
     * adjuntos el backup ocupa decenas de MB y construirlo entero en memoria
     * (array + json_encode) agotaba memory_limit.
     */
    public function streamJSONExport($idUsuario, $filters = [])
    {
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        $movimientos = $this->findByUser($idUsuario, $filters);
        $cuentaModel = new Cuenta();

        // Obtener todas las cuentas del usuario
        $todasCuentas = $cuentaModel->findByUser($idUsuario);

        // Mapear cuentas con balance actual
        $cuentas = [];
        foreach ($todasCuentas as $cuenta) {
            $cuentas[] = [
                'nombre' => $cuenta['nombre'],
                'tipo' => $cuenta['tipo'],
                'moneda' => $cuenta['moneda'],
                'color' => $cuenta['color'],
                'descripcion' => $cuenta['descripcion'] ?? null,
                'meta' => $cuenta['meta'] ?? null,
                'balance_actual' => $cuenta['balance'] // Preservar balance
            ];
        }

        // Cabecera del backup (todo salvo los movimientos, que van a continuación)
        echo '{"version":"2.0"';
        echo ',"fecha_exportacion":' . json_encode(date('Y-m-d H:i:s'));
        echo ',"usuario_id_original":' . json_encode($idUsuario); // Para verificar duplicados
        echo ',"cuentas":' . json_encode($cuentas, $jsonFlags);
        echo ',"movimientos":[';

        // Mapear movimientos con nombre de cuenta en lugar de ID
        $first = true;
        foreach ($movimientos as $mov) {
            $movimientoData = [
                'cuenta_nombre' => $mov['cuenta_nombre'],
                'tipo' => $mov['tipo'],
                'cantidad' => $mov['cantidad'],
                'fecha_movimiento' => $mov['fecha_movimiento'],
                'notas' => $mov['notas'] ?? null,
            ];

            // "Mover dinero": los dos movimientos del par comparten este valor
            if (!empty($mov['id_transferencia'])) {
                $movimientoData['id_transferencia'] = $mov['id_transferencia'];
            }

            // Procesar adjunto si existe
            if (!empty($mov['adjunto'])) {
                $filepath = UPLOADS_PATH . '/movements/' . $mov['adjunto'];
                if (file_exists($filepath)) {
                    $fileContent = file_get_contents($filepath);
                    $base64 = base64_encode($fileContent);

                    // Detectar MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $filepath);
                    finfo_close($finfo);

                    $movimientoData['adjunto_base64'] = "data:{$mimeType};base64,{$base64}";
                    $movimientoData['adjunto_nombre'] = basename($mov['adjunto']);
                    unset($fileContent, $base64);
                }
            }

            echo ($first ? "\n" : ",\n") . json_encode($movimientoData, $jsonFlags);
            $first = false;
            unset($movimientoData);
            flush();
        }

        echo "\n]}";
    }

    /**
     * Importa movimientos desde JSON con creación automática de cuentas
     * Detecta y previene duplicados si se importa en el mismo usuario
     *
     * Las cuentas nuevas se crean con su saldo base original (saldo final del
     * backup menos el neto de sus movimientos) y los movimientos se importan en
     * orden cronológico. Así la cuenta recorre los mismos saldos que la original
     * y ningún gasto se rechaza por "saldo insuficiente".
     */
    public function importFromJSON($idUsuario, $jsonData)
    {
        $data = json_decode($jsonData, true);
        // Liberar el texto original: con adjuntos en Base64 puede ocupar decenas de MB
        unset($jsonData);

        if (!is_array($data)) {
            throw new Exception("Formato JSON inválido");
        }

        // Verificar si es un backup antiguo (sin estructura de cuentas)
        $esFormatoNuevo = isset($data['version']) && isset($data['cuentas']) && isset($data['movimientos']);

        if (!$esFormatoNuevo) {
            // Compatibilidad con formato antiguo
            return $this->importFromJSONLegacy($idUsuario, $data);
        }

        // VALIDACIÓN: Detectar si se está intentando importar en el mismo usuario
        if (isset($data['usuario_id_original']) && $data['usuario_id_original'] == $idUsuario) {
            throw new Exception("No puedes importar un backup en la misma cuenta donde fue creado. Esto causaría duplicados.");
        }

        $cuentaModel = new Cuenta();
        $mapeoNombres = []; // nombre_cuenta => id_cuenta_nueva
        $cuentasCreadas = [];
        $cuentasExistentes = [];
        $balancesOriginales = []; // nombre_cuenta => balance_original
        $mapaTransferencias = []; // id_transferencia del backup => id_transferencia nuevo
        $imported = 0;
        $errors = [];

        // PASO 0: Recorrer los movimientos (sin tocar los adjuntos) para calcular
        // el neto de cada cuenta y el orden cronológico de importación.
        // El backup se exporta del más reciente al más antiguo: importarlo en ese
        // orden hacía que los gastos llegaran antes que los ingresos que los cubrían.
        $netoPorCuenta = []; // nombre_cuenta => ingresos - retiradas del backup
        $ordenImportacion = []; // índice en el backup => [fecha, prioridad]
        foreach ($data['movimientos'] as $index => $mov) {
            $nombreCuenta = $mov['cuenta_nombre'] ?? null;
            $tipo = $mov['tipo'] ?? null;
            $cantidad = floatval($mov['cantidad'] ?? 0);

            if ($nombreCuenta !== null && $cantidad > 0 && in_array($tipo, ['ingreso', 'retirada'], true)) {
                $netoPorCuenta[$nombreCuenta] = ($netoPorCuenta[$nombreCuenta] ?? 0)
                    + ($tipo === 'ingreso' ? $cantidad : -$cantidad);
            }

            // Sin fecha se importan al final (se les asigna la fecha actual).
            // Con la misma fecha, los ingresos van antes que las retiradas.
            $ordenImportacion[$index] = [
                $mov['fecha_movimiento'] ?? '9999-12-31 23:59:59',
                $tipo === 'ingreso' ? 0 : 1
            ];
        }
        asort($ordenImportacion);

        // PASO 1: Procesar cuentas - crear o mapear a existentes
        foreach ($data['cuentas'] as $cuentaData) {
            $nombreCuenta = $cuentaData['nombre'];
            $balanceOriginal = floatval($cuentaData['balance_actual'] ?? 0);
            $balancesOriginales[$nombreCuenta] = $balanceOriginal;

            // Buscar si ya existe una cuenta con ese nombre
            $cuentaExistente = $cuentaModel->findByNameAndUser($nombreCuenta, $idUsuario);

            if ($cuentaExistente) {
                // Mapear a cuenta existente
                $mapeoNombres[$nombreCuenta] = $cuentaExistente['id'];
                $cuentasExistentes[] = $nombreCuenta;
            } else {
                // Crear nueva cuenta con el saldo que tenía antes de su primer movimiento;
                // los triggers lo llevarán hasta el saldo final al importar los movimientos
                $saldoBase = round($balanceOriginal - ($netoPorCuenta[$nombreCuenta] ?? 0), 2);
                try {
                    $nuevaId = $cuentaModel->create([
                        'id_usuario' => $idUsuario,
                        'nombre' => $cuentaData['nombre'],
                        'tipo' => $cuentaData['tipo'],
                        'moneda' => $cuentaData['moneda'] ?? 'EUR',
                        'color' => $cuentaData['color'] ?? '#3B82F6',
                        'descripcion' => $cuentaData['descripcion'] ?? null,
                        'meta' => $cuentaData['meta'] ?? null,
                        'balance' => $saldoBase
                    ]);

                    $mapeoNombres[$nombreCuenta] = $nuevaId;
                    $cuentasCreadas[] = $nombreCuenta;

                } catch (Exception $e) {
                    $errors[] = "Error al crear cuenta '{$nombreCuenta}': " . $e->getMessage();
                }
            }
        }

        // PASO 2: Importar movimientos en orden cronológico usando el mapeo de nombres
        // Se elimina cada movimiento (con su adjunto en Base64) del array al
        // procesarlo, para que la memoria baje a medida que avanza
        foreach (array_keys($ordenImportacion) as $index) {
            $mov = $data['movimientos'][$index];
            unset($data['movimientos'][$index]);

            try {
                $nombreCuenta = $mov['cuenta_nombre'];

                // Verificar que la cuenta exista en el mapeo
                if (!isset($mapeoNombres[$nombreCuenta])) {
                    throw new Exception("La cuenta '{$nombreCuenta}' no pudo ser creada o mapeada");
                }

                $dataToInsert = [
                    'id_cuenta' => $mapeoNombres[$nombreCuenta],
                    'tipo' => $mov['tipo'],
                    'cantidad' => $mov['cantidad'],
                    'notas' => $mov['notas'] ?? null,
                    'fecha_movimiento' => $mov['fecha_movimiento'] ?? date('Y-m-d H:i:s')
                ];

                // "Mover dinero": se genera un identificador nuevo por cada par del
                // backup, para no mezclarlo con transferencias que ya existan
                if (!empty($mov['id_transferencia'])) {
                    $mapaTransferencias[$mov['id_transferencia']] ??= bin2hex(random_bytes(16));
                    $dataToInsert['id_transferencia'] = $mapaTransferencias[$mov['id_transferencia']];
                }

                // Procesar adjunto si existe en Base64
                if (!empty($mov['adjunto_base64'])) {
                    try {
                        // Extraer MIME type y datos
                        if (preg_match('/^data:([^;]+);base64,(.+)$/', $mov['adjunto_base64'], $matches)) {
                            $mimeType = $matches[1];
                            $base64Data = $matches[2];
                            $fileContent = base64_decode($base64Data);

                            if ($fileContent === false) {
                                throw new Exception("Error al decodificar adjunto");
                            }

                            // Determinar extensión
                            $extension = '';
                            switch ($mimeType) {
                                case 'application/pdf':
                                    $extension = 'pdf';
                                    break;
                                case 'image/jpeg':
                                    $extension = 'jpg';
                                    break;
                                case 'image/png':
                                    $extension = 'png';
                                    break;
                                case 'image/gif':
                                    $extension = 'gif';
                                    break;
                                case 'image/webp':
                                    $extension = 'webp';
                                    break;
                                default:
                                    throw new Exception("Tipo de archivo no soportado: {$mimeType}");
                            }

                            // Generar nombre único
                            $originalName = $mov['adjunto_nombre'] ?? "archivo.{$extension}";
                            $filename = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
                            $filepath = UPLOADS_PATH . '/movements/' . $filename;

                            // Guardar archivo
                            if (file_put_contents($filepath, $fileContent) === false) {
                                throw new Exception("Error al guardar adjunto");
                            }

                            $dataToInsert['adjunto'] = $filename;
                        }
                    } catch (Exception $e) {
                        // Si falla el adjunto, importar sin él pero registrar error
                        $errors[] = "Movimiento #" . ($index + 1) . " - Advertencia: " . $e->getMessage() . " (movimiento importado sin adjunto)";
                    }
                }

                $this->create($dataToInsert);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "Movimiento #" . ($index + 1) . ": " . $e->getMessage();
            }
        }

        // PASO 3: AJUSTAR BALANCES (red de seguridad)
        // Con el saldo base del PASO 1 el balance ya debería coincidir; solo hay
        // diferencia si algún movimiento no se pudo importar
        foreach ($cuentasCreadas as $nombreCuenta) {
            $idCuenta = $mapeoNombres[$nombreCuenta];
            $balanceOriginal = $balancesOriginales[$nombreCuenta];

            // Obtener balance actual calculado por los triggers
            $balanceActual = floatval($cuentaModel->getBalance($idCuenta));

            // Si hay diferencia, crear movimiento de "Balance Inicial"
            $diferencia = $balanceOriginal - $balanceActual;

            if (abs($diferencia) > 0.01) { // Tolerancia de 1 céntimo
                try {
                    $tipo = $diferencia > 0 ? 'ingreso' : 'retirada';
                    $cantidad = abs($diferencia);

                    $this->create([
                        'tipo' => $tipo,
                        'id_cuenta' => $idCuenta,
                        'cantidad' => $cantidad,
                        'notas' => '💼 Balance inicial (restaurado desde backup)',
                        'fecha_movimiento' => $data['fecha_exportacion'] ?? date('Y-m-d H:i:s')
                    ]);

                    $imported++; // Contar el movimiento de balance inicial

                } catch (Exception $e) {
                    $errors[] = "Error al ajustar balance inicial de '{$nombreCuenta}': " . $e->getMessage();
                }
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'cuentas_creadas' => $cuentasCreadas,
            'cuentas_existentes' => $cuentasExistentes
        ];
    }

    /**
     * Importación legacy (formato antiguo sin estructura de cuentas)
     * Mantenido para retrocompatibilidad
     */
    private function importFromJSONLegacy($idUsuario, $data)
    {
        $cuentaModel = new Cuenta();
        $imported = 0;
        $errors = [];

        foreach ($data as $index => $mov) {
            try {
                // Validar que la cuenta pertenezca al usuario
                if (!isset($mov['id_cuenta']) || !$cuentaModel->belongsToUser($mov['id_cuenta'], $idUsuario)) {
                    throw new Exception("La cuenta no pertenece al usuario");
                }

                $dataToInsert = [
                    'tipo' => $mov['tipo'],
                    'id_cuenta' => $mov['id_cuenta'],
                    'cantidad' => $mov['cantidad'],
                    'notas' => $mov['notas'] ?? null,
                    'fecha_movimiento' => $mov['fecha_movimiento'] ?? date('Y-m-d H:i:s')
                ];

                // Procesar adjunto si existe
                if (!empty($mov['adjunto_base64'])) {
                    try {
                        if (preg_match('/^data:([^;]+);base64,(.+)$/', $mov['adjunto_base64'], $matches)) {
                            $mimeType = $matches[1];
                            $base64Data = $matches[2];
                            $fileContent = base64_decode($base64Data);

                            if ($fileContent === false) {
                                throw new Exception("Error al decodificar adjunto");
                            }

                            $extension = '';
                            switch ($mimeType) {
                                case 'application/pdf':
                                    $extension = 'pdf';
                                    break;
                                case 'image/jpeg':
                                    $extension = 'jpg';
                                    break;
                                case 'image/png':
                                    $extension = 'png';
                                    break;
                                case 'image/gif':
                                    $extension = 'gif';
                                    break;
                                case 'image/webp':
                                    $extension = 'webp';
                                    break;
                                default:
                                    throw new Exception("Tipo de archivo no soportado");
                            }

                            $originalName = $mov['adjunto_nombre'] ?? "archivo.{$extension}";
                            $filename = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName);
                            $filepath = UPLOADS_PATH . '/movements/' . $filename;

                            if (file_put_contents($filepath, $fileContent) === false) {
                                throw new Exception("Error al guardar adjunto");
                            }

                            $dataToInsert['adjunto'] = $filename;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Línea " . ($index + 1) . " - Advertencia: " . $e->getMessage();
                    }
                }

                $this->create($dataToInsert);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "Línea " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors
        ];
    }

    /**
     * Importa movimientos desde CSV
     */
    public function importFromCSV($idUsuario, $csvContent)
    {
        $lines = explode("\n", trim($csvContent));

        // Saltar la primera línea (cabecera)
        array_shift($lines);

        if (empty($lines)) {
            throw new Exception("El archivo CSV está vacío");
        }

        $imported = 0;
        $errors = [];
        $cuentaModel = new Cuenta();

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            try {
                // Parsear CSV manualmente para manejar comillas
                $data = str_getcsv($line);

                if (count($data) < 4) {
                    throw new Exception("Formato inválido");
                }

                list($fecha, $tipo, $cuentaNombre, $cantidad, $notas) = array_pad($data, 5, '');

                // Validar tipo
                if (!in_array($tipo, ['ingreso', 'retirada'])) {
                    throw new Exception("Tipo debe ser 'ingreso' o 'retirada'");
                }

                // Buscar cuenta por nombre
                $cuentas = $cuentaModel->findByUser($idUsuario);
                $cuenta = null;
                foreach ($cuentas as $c) {
                    if ($c['nombre'] === $cuentaNombre) {
                        $cuenta = $c;
                        break;
                    }
                }

                if (!$cuenta) {
                    throw new Exception("Cuenta '$cuentaNombre' no encontrada");
                }

                // Validar cantidad
                if (!is_numeric($cantidad) || $cantidad <= 0) {
                    throw new Exception("Cantidad inválida");
                }

                $this->create([
                    'tipo' => $tipo,
                    'id_cuenta' => $cuenta['id'],
                    'cantidad' => $cantidad,
                    'notas' => $notas ?: null,
                    'fecha_movimiento' => $fecha
                ]);

                $imported++;
            } catch (Exception $e) {
                $errors[] = "Línea " . ($index + 2) . ": " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors
        ];
    }

    /**
     * Obtiene estadísticas de movimientos de un usuario
     */
    public function getUserStats($idUsuario, $filters = [])
    {
        $sql = "SELECT 
                    COUNT(*) as total_movimientos,
                    COUNT(CASE WHEN m.tipo = 'ingreso' THEN 1 END) as total_ingresos,
                    COUNT(CASE WHEN m.tipo = 'retirada' THEN 1 END) as total_retiradas,
                    COALESCE(SUM(CASE WHEN m.tipo = 'ingreso' THEN m.cantidad ELSE 0 END), 0) as suma_ingresos,
                    COALESCE(SUM(CASE WHEN m.tipo = 'retirada' THEN m.cantidad ELSE 0 END), 0) as suma_retiradas,
                    COALESCE(AVG(CASE WHEN m.tipo = 'ingreso' THEN m.cantidad END), 0) as promedio_ingresos,
                    COALESCE(AVG(CASE WHEN m.tipo = 'retirada' THEN m.cantidad END), 0) as promedio_retiradas,
                    MAX(m.cantidad) as movimiento_mayor,
                    MIN(m.cantidad) as movimiento_menor
                FROM movimientos m
                INNER JOIN cuentas c ON m.id_cuenta = c.id
                WHERE c.id_usuario = :id_usuario
                -- Mover dinero entre cuentas propias no es ni ingreso ni gasto
                AND m.id_transferencia IS NULL";

        $params = ['id_usuario' => $idUsuario];

        $sql .= $this->buildUserFilters(array_intersect_key($filters, array_flip(['fecha_desde', 'fecha_hasta'])), $params);

        return $this->db->fetchOne($sql, $params);
    }
}