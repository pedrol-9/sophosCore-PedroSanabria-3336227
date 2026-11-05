<?php
/**
 * SophosCore - Conexión Centralizada a Base de Datos MySQL
 */

require_once __DIR__ . '/config.php';

function getDBConnection() {

    static $pdo = null;

    if ($pdo === null) {

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'error' => 'Error de conexión a la base de datos: ' . $e->getMessage()
            ]);
            
            exit;
        }
    }
    return $pdo;
}
