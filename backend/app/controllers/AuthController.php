<?php

namespace Controllers;

use Models\Usuario;
use Models\Sesion;
use Models\Codigo2FA;
use Models\TokenRecuperacion;

class AuthController
{
    // Fallos de contraseña permitidos por usuario e IP antes de bloquear un rato
    private const MAX_FALLOS_LOGIN = 10;
    private const BLOQUEO_LOGIN = 900; // 15 minutos
    // Fallos permitidos por cada código 2FA; al llegar se anula el código
    private const MAX_FALLOS_2FA = 5;

    private $usuarioModel;
    private $sesionModel;
    private $codigo2FAModel;
    private $tokenRecuperacionModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->sesionModel = new Sesion();
        $this->codigo2FAModel = new Codigo2FA();
        $this->tokenRecuperacionModel = new TokenRecuperacion();
    }

    /**
     * Registro de nuevo usuario
     */
    public function register()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            // Validaciones
            if (empty($data['nombre_usuario']) || empty($data['correo_electronico']) || empty($data['contrasena'])) {
                jsonError("Todos los campos son obligatorios", 400);
            }

            // Validar formato de correo
            if (!isValidEmail($data['correo_electronico'])) {
                jsonError("El correo electrónico no es válido", 400);
            }

            // Validar contraseña
            $passwordErrors = validatePassword($data['contrasena']);
            if (!empty($passwordErrors)) {
                jsonError("La contraseña no cumple los requisitos", 400, $passwordErrors);
            }

            // Validar longitud de campos
            if (strlen($data['nombre_usuario']) > 50) {
                jsonError("El nombre de usuario no puede tener más de 50 caracteres", 400);
            }

            if (strlen($data['correo_electronico']) > 200) {
                jsonError("El correo electrónico no puede tener más de 200 caracteres", 400);
            }

            // Crear usuario
            $userId = $this->usuarioModel->create([
                'nombre_usuario' => sanitize($data['nombre_usuario']),
                'correo_electronico' => sanitize($data['correo_electronico']),
                'contrasena' => $data['contrasena'],
                'rol' => 'usuario'
            ]);

            // Crear sesión automáticamente
            $token = $this->sesionModel->create($userId);

            // Actualizar último logueo
            $this->usuarioModel->updateLastLogin($userId);

            // Obtener datos del usuario
            $user = $this->usuarioModel->findById($userId);

            // Registrar acción
            logAction($userId, 'se ha registrado en la aplicación');

            jsonSuccess("Usuario registrado exitosamente", [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'nombre_usuario' => $user['nombre_usuario'],
                    'correo_electronico' => $user['correo_electronico'],
                    'rol' => $user['rol'],
                    'foto_perfil' => $user['foto_perfil']
                ]
            ], 201);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Inicio de sesión
     */
    public function login()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['nombre_usuario']) || empty($data['contrasena'])) {
                jsonError("Usuario o contraseña incorrectos", 401);
            }

            // Límite de intentos por usuario e IP, contra ataques de fuerza bruta
            $claveIntentos = 'login:' . getClientIP() . ':' . mb_strtolower(trim($data['nombre_usuario']));
            $espera = tooManyAttempts($claveIntentos, self::MAX_FALLOS_LOGIN);
            if ($espera) {
                jsonError("Demasiados intentos fallidos. Inténtalo de nuevo en " . ceil($espera / 60) . " minutos", 429);
            }

            // Verificar credenciales
            $user = $this->usuarioModel->verifyCredentials(
                $data['nombre_usuario'],
                $data['contrasena']
            );

            if (!$user) {
                registerFailedAttempt($claveIntentos, self::BLOQUEO_LOGIN);
                jsonError("Usuario o contraseña incorrectos", 401);
            }

            clearFailedAttempts($claveIntentos);

            // Verificar si tiene 2FA activado
            if ($user['autenticacion_2fa']) {
                // Generar código 2FA (sustituye al anterior, con sus intentos a cero)
                $codigo = $this->codigo2FAModel->generate($user['id']);
                clearFailedAttempts('2fa:' . $user['id']);

                // Enviar correo con el código
                $subject = "Código de verificación - Gestión de Gastos";
                $body = "
                    <h2>Código de verificación</h2>
                    <p>Tu código de verificación es: <strong>{$codigo}</strong></p>
                    <p>Este código es válido por 5 minutos.</p>
                    <p>Si no solicitaste este código, ignora este mensaje.</p>
                ";

                sendEmail($user['correo_electronico'], $subject, $body);

                jsonSuccess("Se ha enviado un código de verificación a tu correo", [
                    'requires_2fa' => true,
                    'user_id' => $user['id']
                ]);
            } else {
                // Crear sesión directamente
                $token = $this->sesionModel->create($user['id']);

                // Actualizar último logueo
                $this->usuarioModel->updateLastLogin($user['id']);

                // Registrar acción
                logAction($user['id'], 'ha iniciado sesión');

                jsonSuccess("Inicio de sesión exitoso", [
                    'token' => $token,
                    'user' => [
                        'id' => $user['id'],
                        'nombre_usuario' => $user['nombre_usuario'],
                        'correo_electronico' => $user['correo_electronico'],
                        'rol' => $user['rol'],
                        'foto_perfil' => $user['foto_perfil'],
                        'autenticacion_2fa' => (int)$user['autenticacion_2fa']
                    ]
                ]);
            }

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Verificación de código 2FA
     */
    public function verify2FA()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['user_id']) || empty($data['codigo'])) {
                jsonError("Datos incompletos", 400);
            }

            // Validar código. Un código de 6 cifras se podría adivinar probando
            // en bucle, así que tras MAX_FALLOS_2FA fallos se anula y hay que
            // volver a iniciar sesión para recibir otro.
            $claveIntentos = '2fa:' . (int) $data['user_id'];
            if (!$this->codigo2FAModel->validate($data['user_id'], $data['codigo'])) {
                if (registerFailedAttempt($claveIntentos, TFA_CODE_EXPIRY) >= self::MAX_FALLOS_2FA) {
                    $this->codigo2FAModel->invalidate($data['user_id']);
                    clearFailedAttempts($claveIntentos);
                    jsonError("Demasiados intentos fallidos. Vuelve a iniciar sesión para recibir un código nuevo", 401);
                }
                jsonError("Código incorrecto o expirado", 401);
            }
            clearFailedAttempts($claveIntentos);

            // Obtener usuario
            $user = $this->usuarioModel->findById($data['user_id']);

            // Crear sesión
            $token = $this->sesionModel->create($user['id']);

            // Actualizar último logueo
            $this->usuarioModel->updateLastLogin($user['id']);

            // Registrar acción
            logAction($user['id'], 'ha iniciado sesión con verificación en 2 pasos');

            jsonSuccess("Verificación exitosa", [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'nombre_usuario' => $user['nombre_usuario'],
                    'correo_electronico' => $user['correo_electronico'],
                    'rol' => $user['rol'],
                    'foto_perfil' => $user['foto_perfil'],
                    'autenticacion_2fa' => (int)$user['autenticacion_2fa']
                ]
            ]);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Cierre de sesión
     */
    public function logout()
    {
        try {
            $headers = getallheaders();
            $token = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $token);

            if (empty($token)) {
                jsonError("Token no proporcionado", 401);
            }

            // Validar sesión
            $session = $this->sesionModel->validate($token);

            if (!$session) {
                jsonError("Sesión inválida", 401);
            }

            // Eliminar sesión
            $this->sesionModel->delete($token);

            // Registrar acción
            logAction($session['id_usuario'], 'ha cerrado sesión');

            jsonSuccess("Sesión cerrada exitosamente");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Solicitud de recuperación de contraseña
     */
    public function requestPasswordReset()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['correo_electronico'])) {
                jsonError("El correo electrónico es obligatorio", 400);
            }

            // Buscar usuario por correo
            $user = $this->usuarioModel->findByEmail($data['correo_electronico']);

            // Por seguridad, siempre responder lo mismo exista o no el usuario
            if ($user) {
                // Generar token de recuperación
                $token = $this->tokenRecuperacionModel->generate($user['id']);

                // Construir URL de recuperación
                $resetUrl = BASE_URL . "/reset-password?token=" . $token;

                // Enviar correo
                $subject = "Recuperación de contraseña - Gestión de Gastos";
                $body = "
                    <h2>Recuperación de contraseña</h2>
                    <p>Has solicitado restablecer tu contraseña.</p>
                    <p>Haz clic en el siguiente enlace para crear una nueva contraseña:</p>
                    <p><a href='{$resetUrl}'>{$resetUrl}</a></p>
                    <p>Este enlace es válido por 1 hora.</p>
                    <p>Si no solicitaste este cambio, ignora este mensaje.</p>
                ";

                sendEmail($user['correo_electronico'], $subject, $body);

                // Registrar acción
                logAction($user['id'], 'ha solicitado recuperación de contraseña');
            }

            jsonSuccess("Si el correo existe, recibirás un enlace de recuperación");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Restablecimiento de contraseña
     */
    public function resetPassword()
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['token']) || empty($data['nueva_contrasena'])) {
                jsonError("Datos incompletos", 400);
            }

            // Validar contraseña
            $passwordErrors = validatePassword($data['nueva_contrasena']);
            if (!empty($passwordErrors)) {
                jsonError("La contraseña no cumple los requisitos", 400, $passwordErrors);
            }

            // Validar token
            $tokenData = $this->tokenRecuperacionModel->validate($data['token']);

            if (!$tokenData) {
                jsonError("Token inválido o expirado", 401);
            }

            // Actualizar contraseña
            $this->usuarioModel->update($tokenData['id_usuario'], [
                'contrasena' => $data['nueva_contrasena']
            ]);

            // Marcar token como usado
            $this->tokenRecuperacionModel->markAsUsed($data['token']);

            // Cerrar todas las sesiones del usuario por seguridad
            $this->sesionModel->deleteAllByUser($tokenData['id_usuario']);

            // Registrar acción
            logAction($tokenData['id_usuario'], 'ha restablecido su contraseña');

            jsonSuccess("Contraseña actualizada exitosamente");

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 400);
        }
    }

    /**
     * Validar sesión actual
     */
    public function validateSession()
    {
        try {
            $headers = getallheaders();
            $token = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $token);

            if (empty($token)) {
                jsonError("Token no proporcionado", 401);
            }

            // Validar sesión
            $session = $this->sesionModel->validate($token);

            if (!$session) {
                jsonError("Sesión inválida o expirada", 401);
            }

            // Renovar sesión
            $this->sesionModel->renew($token);

            jsonSuccess("Sesión válida", [
                'user' => [
                    'id' => $session['id'],
                    'nombre_usuario' => $session['nombre_usuario'],
                    'correo_electronico' => $session['correo_electronico'],
                    'rol' => $session['rol'],
                    'foto_perfil' => $session['foto_perfil'],
                    'autenticacion_2fa' => (int)$session['autenticacion_2fa']
                ]
            ]);

        } catch (\Exception $e) {
            jsonError($e->getMessage(), 401);
        }
    }
}