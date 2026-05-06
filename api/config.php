<?php

// Cargar variables de entorno desde .env si existe (entorno local)
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

// Configuración de MySQL desde variables de entorno
$DB_HOST = getenv('DB_HOST') ?: "localhost";
$DB_NAME = getenv('DB_NAME') ?: "eva";
$DB_USER = getenv('DB_USER') ?: "root";
$DB_PASS = getenv('DB_PASS') ?: "";

// API Keys
$OPENAI_API_KEY = getenv('OPENAI_API_KEY') ?: "";
$DEEPSEEK_API_KEY = getenv('DEEPSEEK_API_KEY') ?: "";

?>