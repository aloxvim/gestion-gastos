<?php
ob_start();

// Sin cabeceras CORS: el navegador llama a la API desde el mismo origen (el
// nginx del frontend, o el proxy de Vite en desarrollo) y el bot desde el
// servidor, así que ninguna petición es de otro origen.

require_once '../../config/config.php';

// Eliminar prefijo /api de la URI
$_SERVER['REQUEST_URI'] = preg_replace('#^/api#', '', $_SERVER['REQUEST_URI']);

// Cargar autoloader de Composer
if (file_exists('../../vendor/autoload.php')) {
    require_once '../../vendor/autoload.php';
}

use Core\Router;
use Core\AuthMiddleware;
use Core\AdminMiddleware;
use Core\OwnerMiddleware;

use Controllers\AuthController;
use Controllers\UserController;
use Controllers\AccountController;
use Controllers\MovementController;
use Controllers\AdminController;
use Controllers\TagController;
use Controllers\AnalysisController;

$router = new Router();

// ============================================
// Rutas públicas (sin autenticación)
// ============================================

// Autenticación
$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login', [AuthController::class, 'login']);
$router->post('/auth/verify-2fa', [AuthController::class, 'verify2FA']);
$router->post('/auth/request-password-reset', [AuthController::class, 'requestPasswordReset']);
$router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// Etiquetas (públicas para mostrar en formularios)
$router->get('/tags', [TagController::class, 'getAll']);

// ============================================
// Rutas protegidas (requieren autenticación)
// ============================================

// Validar sesión
$router->get('/auth/validate', [AuthController::class, 'validateSession'], [AuthMiddleware::class]);
$router->post('/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);

// Usuario
$router->get('/user/profile', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->getProfile($userId);
}, [AuthMiddleware::class]);

$router->put('/user/profile', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->updateProfile($userId);
}, [AuthMiddleware::class]);

$router->put('/user/change-password', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->changePassword($userId);
}, [AuthMiddleware::class]);

$router->post('/user/profile-photo', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->uploadProfilePhoto($userId);
}, [AuthMiddleware::class]);

$router->put('/user/toggle-2fa', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->toggle2FA($userId);
}, [AuthMiddleware::class]);

$router->post('/user/request-admin', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->requestAdmin($userId);
}, [AuthMiddleware::class]);

$router->delete('/user/account', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new UserController();
    $controller->deleteAccount($userId);
}, [AuthMiddleware::class]);

// Cuentas
$router->post('/accounts', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->create($userId);
}, [AuthMiddleware::class]);

$router->get('/accounts', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->getAll($userId);
}, [AuthMiddleware::class]);

$router->get('/accounts/summary', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->getSummary($userId);
}, [AuthMiddleware::class]);

$router->get('/accounts/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->getOne($userId, $id);
}, [AuthMiddleware::class]);

$router->put('/accounts/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->update($userId, $id);
}, [AuthMiddleware::class]);

$router->delete('/accounts/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AccountController();
    $controller->delete($userId, $id);
}, [AuthMiddleware::class]);

// Movimientos
$router->post('/movements', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->create($userId);
}, [AuthMiddleware::class]);

$router->get('/movements', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->getAll($userId);
}, [AuthMiddleware::class]);

// Mover dinero entre cuentas propias
$router->post('/movements/transfer', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->createTransfer($userId);
}, [AuthMiddleware::class]);

$router->get('/movements/stats', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->getStats($userId);
}, [AuthMiddleware::class]);

$router->get('/movements/export/csv', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->exportCSV($userId);
}, [AuthMiddleware::class]);

$router->get('/movements/export/json', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->exportJSON($userId);
}, [AuthMiddleware::class]);

$router->post('/movements/import', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->importJSON($userId);
}, [AuthMiddleware::class]);

$router->post('/movements/import/csv', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->importCSV($userId);
}, [AuthMiddleware::class]);

$router->get('/movements/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->getOne($userId, $id);
}, [AuthMiddleware::class]);

$router->put('/movements/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->update($userId, $id);
}, [AuthMiddleware::class]);

$router->delete('/movements/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->delete($userId, $id);
}, [AuthMiddleware::class]);

// Descargar el adjunto (solo su dueño; /uploads/movements no se sirve directamente)
$router->get('/movements/:id/attachment', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->downloadAttachment($userId, $id);
}, [AuthMiddleware::class]);

$router->delete('/movements/:id/attachment', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new MovementController();
    $controller->deleteAttachment($userId, $id);
}, [AuthMiddleware::class]);

// Análisis con IA (Google Gemini)
$router->get('/analysis/config', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AnalysisController();
    $controller->getConfig($userId);
}, [AuthMiddleware::class]);

$router->put('/analysis/key', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AnalysisController();
    $controller->saveKey($userId);
}, [AuthMiddleware::class]);

$router->delete('/analysis/key', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AnalysisController();
    $controller->deleteKey($userId);
}, [AuthMiddleware::class]);

$router->post('/analysis', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AnalysisController();
    $controller->analyze($userId);
}, [AuthMiddleware::class]);

// ============================================
// Rutas de administración
// ============================================

// Gestión de usuarios (admin y propietario)
$router->get('/admin/users', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->getUsers($userId);
}, [AdminMiddleware::class]);

$router->put('/admin/users/role', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->changeUserRole($userId);
}, [AdminMiddleware::class]);

$router->put('/admin/users/update', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->updateUser($userId);
}, [AdminMiddleware::class]);

$router->delete('/admin/users', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->deleteUser($userId);
}, [AdminMiddleware::class]);

// Solo propietario
$router->get('/admin/activity-log', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->getActivityLog($userId);
}, [OwnerMiddleware::class]);

$router->get('/admin/stats', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new AdminController();
    $controller->getStats($userId);
}, [OwnerMiddleware::class]);

// Gestión de etiquetas (solo propietario)
$router->post('/admin/tags', function () {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new TagController();
    $controller->create($userId);
}, [OwnerMiddleware::class]);

$router->put('/admin/tags/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new TagController();
    $controller->update($userId, $id);
}, [OwnerMiddleware::class]);

$router->delete('/admin/tags/:id', function ($id) {
    $userId = $GLOBALS['current_user']['id'];
    $controller = new TagController();
    $controller->delete($userId, $id);
}, [OwnerMiddleware::class]);

// Ejecutar router
$router->run();