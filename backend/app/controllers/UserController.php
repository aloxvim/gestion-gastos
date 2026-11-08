<?php

namespace Controllers;

use Models\Usuario;
use Models\Sesion;

class UserController
{
    private $usuarioModel;
    private $sesionModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->sesionModel = new Sesion();
    }

    /**
     * Obtener perfil del usuario actual
     */
    public function getProfile($userId)
    {
        try {
            $user = $this->usuarioModel->findById($userId);

            if (!$user) {
                jsonError("Usuario no encontrado", 404);
            }

            // Obtener balance total
            $balanceTotal = $this->usuarioModel->getTotalBalance($userId);

            // No devolver la contraseña
            unset($user['contrasena']);
            $user['balance_total'] = $balanceTotal;

            jsonSuccess("Perfil obtenido", ['user' => $user]);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Actualizar perfil: nombre de usuario y/o correo electrónico.
     * Requiere la contraseña actual. La foto va por uploadProfilePhoto.
     */
    public function updateProfile($userId)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            $user = $this->usuarioModel->findById($userId);
            if (!$user) {
                jsonError("Usuario no encontrado", 404);
            }

            if (empty($data['contrasena_actual'])) {
                jsonError("Debes confirmar tu contraseña actual", 400);
            }
            // 400 y no 401: un 401 haría que el frontend cerrara la sesión
            if (!verifyPassword($data['contrasena_actual'], $user['contrasena'])) {
                jsonError("La contraseña actual es incorrecta", 400);
            }

            $updateData = [];

            if (isset($data['nombre_usuario'])) {
                $nombreUsuario = sanitize($data['nombre_usuario']);

                if (mb_strlen($nombreUsuario) < 3) {
                    jsonError("El nombre de usuario debe tener al menos 3 caracteres", 400);
                }
                if (mb_strlen($nombreUsuario) > 50) {
                    jsonError("El nombre de usuario no puede tener más de 50 caracteres", 400);
                }
                if ($nombreUsuario !== $user['nombre_usuario']) {
                    $updateData['nombre_usuario'] = $nombreUsuario;
                }
            }

            if (isset($data['correo_electronico'])) {
                $correo = trim($data['correo_electronico']);

                if (!isValidEmail($correo)) {
                    jsonError("El correo electrónico no es válido", 400);
                }
                if (mb_strlen($correo) > 200) {
                    jsonError("El correo electrónico no puede tener más de 200 caracteres", 400);
                }
                if ($correo !== $user['correo_electronico']) {
                    $updateData['correo_electronico'] = $correo;
                }
            }

            if (empty($updateData)) {
                jsonError("No has cambiado ningún dato", 400);
            }

            // Usuario::update comprueba que el nombre y el correo no estén en uso
            $this->usuarioModel->update($userId, $updateData);

            $cambios = [];
            if (isset($updateData['nombre_usuario'])) {
                $cambios[] = "su nombre de usuario (antes '{$user['nombre_usuario']}')";
            }
            if (isset($updateData['correo_electronico'])) {
                $cambios[] = "su correo electrónico";
            }
            logAction($userId, 'ha cambiado ' . implode(' y ', $cambios));

            $user = $this->usuarioModel->findById($userId);

            jsonSuccess("Perfil actualizado exitosamente", [
                'user' => [
                    'id' => $user['id'],
                    'nombre_usuario' => $user['nombre_usuario'],
                    'correo_electronico' => $user['correo_electronico'],
                    'rol' => $user['rol'],
                    'foto_perfil' => $user['foto_perfil'],
                    'autenticacion_2fa' => (int) $user['autenticacion_2fa']
                ]
            ]);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Cambiar contraseña
     */
    public function changePassword($userId)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['contrasena_actual']) || empty($data['contrasena_nueva'])) {
                jsonError("Todos los campos son obligatorios", 400);
            }

            // Verificar contraseña actual
            $user = $this->usuarioModel->findById($userId);
            if (!verifyPassword($data['contrasena_actual'], $user['contrasena'])) {
                jsonError("La contraseña actual es incorrecta", 400); // no 401: cerraría la sesión
            }

            // Validar nueva contraseña
            $passwordErrors = validatePassword($data['contrasena_nueva']);
            if (!empty($passwordErrors)) {
                jsonError("La nueva contraseña no cumple los requisitos", 400, $passwordErrors);
            }

            // Actualizar contraseña
            $this->usuarioModel->update($userId, [
                'contrasena' => $data['contrasena_nueva']
            ]);

            // Cerrar todas las sesiones excepto la actual
            $currentToken = str_replace('Bearer ', '', getallheaders()['Authorization'] ?? '');
            $sessions = $this->sesionModel->getActiveByUser($userId);

            foreach ($sessions as $session) {
                if ($session['token'] !== $currentToken) {
                    $this->sesionModel->delete($session['token']);
                }
            }

            logAction($userId, 'ha cambiado su contraseña');

            jsonSuccess("Contraseña actualizada exitosamente");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Subir foto de perfil
     */
    public function uploadProfilePhoto($userId)
    {
        try {
            if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
                jsonError("Error al subir el archivo", 400);
            }

            $file = $_FILES['foto'];

            // Validar que sea una imagen
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mimeType = detectMimeType($file['tmp_name']);

            if (!in_array($mimeType, $allowedTypes)) {
                jsonError("Solo se permiten imágenes (JPG, PNG, GIF, WEBP)", 400);
            }

            // Validar tamaño (máximo 2MB para fotos de perfil)
            if ($file['size'] > 2097152) {
                jsonError("La imagen no puede superar los 2 MB", 400);
            }

            // Eliminar foto anterior si existe
            $user = $this->usuarioModel->findById($userId);
            if ($user['foto_perfil']) {
                $oldPhoto = UPLOADS_PATH . '/profiles/' . $user['foto_perfil'];
                if (file_exists($oldPhoto)) {
                    unlink($oldPhoto);
                }
            }

            // Guardar nueva foto (extensión según el contenido, nunca la del nombre enviado)
            $extension = extensionForMime($mimeType);
            $filename = 'profile_' . $userId . '_' . time() . '.' . $extension;
            $destination = UPLOADS_PATH . '/profiles/' . $filename;

            // Crear directorio si no existe
            if (!file_exists(UPLOADS_PATH . '/profiles')) {
                mkdir(UPLOADS_PATH . '/profiles', 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                jsonError("Error al guardar la imagen", 500);
            }

            // Actualizar base de datos
            $this->usuarioModel->update($userId, ['foto_perfil' => $filename]);

            logAction($userId, 'ha actualizado su foto de perfil');

            jsonSuccess("Foto de perfil actualizada", ['filename' => $filename]);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Activar/Desactivar 2FA
     */
    public function toggle2FA($userId)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['enable'])) {
                jsonError("Debe especificar si activar o desactivar", 400);
            }

            $enable = (bool) $data['enable'];

            // Activar o desactivar la verificación en 2 pasos exige la contraseña,
            // para que nadie la quite desde una sesión que se haya dejado abierta
            if (empty($data['contrasena'])) {
                jsonError("Debes confirmar tu contraseña", 400);
            }

            $user = $this->usuarioModel->findById($userId);
            if (!verifyPassword($data['contrasena'], $user['contrasena'])) {
                jsonError("Contraseña incorrecta", 400); // no 401: cerraría la sesión
            }

            $this->usuarioModel->toggle2FA($userId, $enable);

            $message = $enable ? "Verificación en 2 pasos activada" : "Verificación en 2 pasos desactivada";
            jsonSuccess($message);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Solicitar ser administrador
     */
    public function requestAdmin($userId)
    {
        try {
            $this->usuarioModel->requestAdmin($userId);
            jsonSuccess("Solicitud enviada exitosamente");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Eliminar cuenta
     */
    public function deleteAccount($userId)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            // Verificar contraseña
            if (empty($data['contrasena'])) {
                jsonError("Debe confirmar su contraseña", 400);
            }

            $user = $this->usuarioModel->findById($userId);
            if (!verifyPassword($data['contrasena'], $user['contrasena'])) {
                jsonError("Contraseña incorrecta", 400); // no 401: cerraría la sesión
            }

            // Verificar confirmaciones
            if (empty($data['confirmacion1']) || empty($data['confirmacion2'])) {
                jsonError("Debe confirmar la eliminación dos veces", 400);
            }

            if ($data['confirmacion1'] !== 'ELIMINAR' || $data['confirmacion2'] !== 'ELIMINAR') {
                jsonError("Las confirmaciones deben ser exactamente 'ELIMINAR'", 400);
            }

            // Registrar el borrado ANTES de eliminar, para que logAction pueda capturar el nombre
            logAction($userId, "ha eliminado su cuenta");

            // Eliminar usuario (cascada eliminará todo)
            $this->usuarioModel->delete($userId);

            jsonSuccess("Cuenta eliminada exitosamente");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }
}
