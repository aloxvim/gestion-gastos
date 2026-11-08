<?php

/**
 * Script de tareas programadas
 * Ejecutar mensualmente: 0 0 1 * * php /var/www/html/scripts/cron.php
 *
 * Con --simular solo muestra lo que haría (a quién avisaría o eliminaría),
 * sin enviar correos, eliminar usuarios ni limpiar nada:
 *   docker exec gastos_backend php /var/www/html/scripts/cron.php --simular
 *
 * Avisos de inactividad: cada usuario recibe como mucho un recordatorio (1 año)
 * y una advertencia (18 meses) por periodo de inactividad. Los avisos enviados
 * se registran en el log de actividad (movimientos_pagina), que es lo que se
 * consulta para no repetirlos. Un usuario solo se elimina (2 años) si recibió
 * la advertencia hace al menos DIAS_MINIMOS_TRAS_ADVERTENCIA días; si no, se le
 * envía ahora y la eliminación se aplaza.
 */

require_once dirname(__DIR__) . '/config/config.php';

use Core\Database;
use Models\Usuario;
use Models\Sesion;
use Models\Codigo2FA;
use Models\TokenRecuperacion;

const ACCION_RECORDATORIO = 'ha recibido un recordatorio por inactividad';
const ACCION_ADVERTENCIA = 'ha recibido una advertencia de eliminación por inactividad';
const DIAS_MINIMOS_TRAS_ADVERTENCIA = 30;

$simular = in_array('--simular', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Iniciando tareas programadas...\n";
if ($simular) {
    echo "*** MODO SIMULACIÓN: no se enviará ni eliminará nada ***\n";
}
echo "\n";

/**
 * Envía el recordatorio de inactividad (1+ año)
 */
function enviarRecordatorio($user, $diasInactivo)
{
    $subject = "Recordatorio: No has usado tu cuenta en mucho tiempo";
    $body = "
        <h2>Te extrañamos!</h2>
        <p>Hola {$user['nombre_usuario']},</p>
        <p>No has iniciado sesión en <strong>{$diasInactivo} días</strong>.</p>
        <p>Queremos recordarte que tu cuenta sigue activa y lista para usar.</p>
        <p><a href='" . BASE_URL . "'>Iniciar sesión</a></p>
        <p><small>Nota: Las cuentas inactivas por más de 2 años son eliminadas automáticamente.</small></p>
    ";

    return sendEmail($user['correo_electronico'], $subject, $body);
}

/**
 * Envía la advertencia de eliminación (1.5+ años)
 */
function enviarAdvertencia($user, $diasInactivo, $diasRestantes)
{
    $subject = $diasRestantes > 0
        ? "⚠️ Tu cuenta será eliminada en {$diasRestantes} días"
        : "⚠️ Tu cuenta será eliminada próximamente";
    $plazo = $diasRestantes > 0
        ? "en los próximos <strong>{$diasRestantes} días</strong>"
        : "en los próximos <strong>" . DIAS_MINIMOS_TRAS_ADVERTENCIA . " días</strong>";
    $body = "
        <h2>Advertencia de cuenta inactiva</h2>
        <p>Hola {$user['nombre_usuario']},</p>
        <p>No has iniciado sesión en <strong>{$diasInactivo} días</strong>.</p>
        <p>Si no accedes a tu cuenta {$plazo},
        será eliminada automáticamente junto con todos tus datos.</p>
        <p><a href='" . BASE_URL . "'>Iniciar sesión ahora</a></p>
        <p>Si deseas mantener tu cuenta, simplemente inicia sesión.</p>
    ";

    return sendEmail($user['correo_electronico'], $subject, $body);
}

// ============================================
// 1. Gestión de usuarios inactivos
// ============================================

echo "Verificando usuarios inactivos...\n";

$usuarioModel = new Usuario();
$inactiveUsers = $usuarioModel->getInactiveUsers();
$avisosEnviados = 0;
$usuariosEliminados = 0;

foreach ($inactiveUsers as $user) {
    $ultimoLogueo = $user['ultimo_logueo'];
    $diasInactivo = (int) floor((time() - strtotime($ultimoLogueo)) / 86400);
    $diasRestantes = (int) ceil((strtotime($ultimoLogueo . ' +2 years') - time()) / 86400);
    $etiqueta = "{$user['nombre_usuario']} <{$user['correo_electronico']}> ({$diasInactivo} días inactivo)";

    // Avisos ya enviados en este periodo de inactividad (posteriores al último logueo)
    $fechaRecordatorio = $usuarioModel->getInactivityNoticeSince($user['id'], ACCION_RECORDATORIO, $ultimoLogueo);
    $fechaAdvertencia = $usuarioModel->getInactivityNoticeSince($user['id'], ACCION_ADVERTENCIA, $ultimoLogueo);

    $accion = $user['status'];

    // Nunca eliminar sin una advertencia previa con margen suficiente
    if ($accion === 'delete') {
        $diasDesdeAdvertencia = $fechaAdvertencia
            ? (int) floor((time() - strtotime($fechaAdvertencia)) / 86400)
            : null;

        if ($diasDesdeAdvertencia === null || $diasDesdeAdvertencia < DIAS_MINIMOS_TRAS_ADVERTENCIA) {
            if ($fechaAdvertencia) {
                echo "Aplazando eliminación de {$etiqueta}: advertido hace {$diasDesdeAdvertencia} días\n";
                continue;
            }
            echo "Eliminación de {$etiqueta} aplazada: nunca fue advertido\n";
            $accion = 'warning';
        }
    }

    switch ($accion) {
        case 'delete':
            echo "Eliminando usuario inactivo: {$etiqueta}\n";
            if ($simular) {
                echo "  (simulación) se eliminaría\n";
                break;
            }

            try {
                $usuarioModel->delete($user['id']);
                // El usuario ya no existe: registrar con id NULL y el nombre fijado
                Database::getInstance()->insert('movimientos_pagina', [
                    'id_usuario' => null,
                    'nombre_usuario' => $user['nombre_usuario'],
                    'accion' => 'ha sido eliminado por inactividad',
                    'ip' => null,
                    'fecha' => date('Y-m-d H:i:s')
                ]);
                $usuariosEliminados++;
                echo "  ✓ Usuario eliminado\n";
            } catch (Exception $e) {
                echo "  ✗ Error al eliminar: {$e->getMessage()}\n";
            }
            break;

        case 'warning':
            if ($fechaAdvertencia) {
                echo "Advertencia ya enviada a {$etiqueta} el {$fechaAdvertencia}, se omite\n";
                break;
            }

            echo "Enviando advertencia a: {$etiqueta}\n";
            if ($simular) {
                echo "  (simulación) se enviaría\n";
                break;
            }

            if (enviarAdvertencia($user, $diasInactivo, $diasRestantes)) {
                logAction($user['id'], ACCION_ADVERTENCIA);
                $avisosEnviados++;
                echo "  ✓ Correo enviado\n";
            } else {
                echo "  ✗ Error al enviar correo (se reintentará en la próxima ejecución)\n";
            }
            break;

        case 'reminder':
            if ($fechaRecordatorio) {
                echo "Recordatorio ya enviado a {$etiqueta} el {$fechaRecordatorio}, se omite\n";
                break;
            }

            echo "Enviando recordatorio a: {$etiqueta}\n";
            if ($simular) {
                echo "  (simulación) se enviaría\n";
                break;
            }

            if (enviarRecordatorio($user, $diasInactivo)) {
                logAction($user['id'], ACCION_RECORDATORIO);
                $avisosEnviados++;
                echo "  ✓ Correo enviado\n";
            } else {
                echo "  ✗ Error al enviar correo (se reintentará en la próxima ejecución)\n";
            }
            break;
    }
}

echo "Usuarios inactivos revisados: " . count($inactiveUsers) . "\n\n";

if ($simular) {
    echo "[" . date('Y-m-d H:i:s') . "] Simulación terminada (no se ha limpiado nada)\n";
    exit(0);
}

// ============================================
// 2. Limpiar sesiones expiradas
// ============================================

echo "Limpiando sesiones expiradas...\n";

$sesionModel = new Sesion();
$deletedSessions = $sesionModel->deleteExpired();

echo "Sesiones eliminadas: {$deletedSessions}\n\n";

// ============================================
// 3. Limpiar códigos 2FA expirados
// ============================================

echo "Limpiando códigos 2FA expirados...\n";

$codigo2FAModel = new Codigo2FA();
$deleted2FA = $codigo2FAModel->deleteExpired();

echo "Códigos 2FA eliminados: {$deleted2FA}\n\n";

// ============================================
// 4. Limpiar tokens de recuperación expirados
// ============================================

echo "Limpiando tokens de recuperación expirados...\n";

$tokenRecuperacionModel = new TokenRecuperacion();
$deletedTokens = $tokenRecuperacionModel->deleteExpired();

echo "Tokens eliminados: {$deletedTokens}\n\n";

// ============================================
// Resumen final
// ============================================

echo "[" . date('Y-m-d H:i:s') . "] Tareas completadas exitosamente\n";
echo "================================================\n";
echo "Resumen:\n";
echo "  - Usuarios inactivos revisados: " . count($inactiveUsers) . "\n";
echo "  - Avisos enviados: {$avisosEnviados}\n";
echo "  - Usuarios eliminados: {$usuariosEliminados}\n";
echo "  - Sesiones limpiadas: {$deletedSessions}\n";
echo "  - Códigos 2FA limpiados: {$deleted2FA}\n";
echo "  - Tokens limpiados: {$deletedTokens}\n";
echo "================================================\n";
