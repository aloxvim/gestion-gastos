<?php
/**
 * Configuración adicional de phpMyAdmin (se monta en el contenedor desde
 * docker-compose.yml). phpMyAdmin es accesible desde la red local, así que:
 */

// No permitir iniciar sesión como root: para corregir datos basta con gastos_user
$cfg['Servers'][1]['AllowRoot'] = false;

// No permitir iniciar sesión sin contraseña
$cfg['Servers'][1]['AllowNoPassword'] = false;

// Cerrar la sesión tras 30 minutos de inactividad
$cfg['LoginCookieValidity'] = 1800;
