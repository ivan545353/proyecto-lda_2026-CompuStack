<?php

const APP_URL = 'http://localhost/lab_prog_2025_reales_ivan_backend/public/';
define('APP_URI', $_SERVER['DOCUMENT_ROOT'] . '/lab_prog_2025_reales_ivan_backend/app/');



define('APP_FILE_LOG_ERRORS',   APP_URI.'logs/error.log');
define('APP_FILE_LOG_ACCESS',   APP_URI.'logs/access.log');





//#########################################
// CONTROLADOR Y ACCION POR DEFECTO
//#########################################

const APP_DEFAULT_CONTROLLER = "authentication";
const APP_DEFAULT_ACTION = "index";
const APP_AUTHENTICATION_CONTROLLER = "authentication";
const APP_LOGIN_ACTION = "login";
define("JWT_SECRET", 'REEMPLAZADO_POR_SEGURIDAD_VER_legacy/README.md');
const JWT_EXPIRATION = 3600; // 1 hora

//#########################################
// MANEJO DE SESIONES
//#########################################

