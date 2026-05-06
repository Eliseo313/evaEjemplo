<?php

header("Content-Type: application/json; charset=UTF-8");

require_once "config.php";

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input["mensaje"]) || empty(trim($input["mensaje"]))) {
    echo json_encode([
        "error" => "No se recibió ninguna pregunta."
    ]);
    exit;
}

$mensajeUsuario = trim($input["mensaje"]);

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    echo json_encode([
        "error" => "Error de conexión a la base de datos.",
        "detalle" => $e->getMessage()
    ]);
    exit;
}

/*
    Paso 1:
    Detectar qué quiere preguntar el usuario.

    Como estamos aprendiendo, haremos algo seguro:
    NO dejaremos que la IA ejecute SQL libre.
    Nosotros decidimos qué consultas se pueden hacer.
*/

$mensajeLower = strtolower($mensajeUsuario);

$datos = [];
$tipoConsulta = "";

try {
    if (
        str_contains($mensajeLower, "hoy") ||
        str_contains($mensajeLower, "venta de hoy") ||
        str_contains($mensajeLower, "ventas de hoy")
    ) {
        $tipoConsulta = "ventas_hoy";

        $sql = "
            SELECT 
                COUNT(*) AS numero_ventas,
                SUM(iTotalVenta) AS total_vendido,
                AVG(iTotalVenta) AS promedio_venta
            FROM tbl_ventas
            WHERE DATE(dFechaVenta) = CURDATE()
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (
        str_contains($mensajeLower, "mes") ||
        str_contains($mensajeLower, "este mes")
    ) {
        $tipoConsulta = "ventas_mes";

        $sql = "
            SELECT 
                COUNT(*) AS numero_ventas,
                SUM(iTotalVenta) AS total_vendido,
                AVG(iTotalVenta) AS promedio_venta,
                MIN(iTotalVenta) AS venta_menor,
                MAX(iTotalVenta) AS venta_mayor
            FROM tbl_ventas
            WHERE MONTH(dFechaVenta) = MONTH(CURDATE())
            AND YEAR(dFechaVenta) = YEAR(CURDATE())
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (
        str_contains($mensajeLower, "semana") ||
        str_contains($mensajeLower, "esta semana")
    ) {
        $tipoConsulta = "ventas_semana";

        $sql = "
            SELECT 
                COUNT(*) AS numero_ventas,
                SUM(iTotalVenta) AS total_vendido,
                AVG(iTotalVenta) AS promedio_venta,
                MIN(iTotalVenta) AS venta_menor,
                MAX(iTotalVenta) AS venta_mayor
            FROM tbl_ventas
            WHERE YEARWEEK(dFechaVenta, 1) = YEARWEEK(CURDATE(), 1)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (
        str_contains($mensajeLower, "todas") ||
        str_contains($mensajeLower, "historial") ||
        str_contains($mensajeLower, "general")
    ) {
        $tipoConsulta = "resumen_general";

        $sql = "
            SELECT 
                COUNT(*) AS numero_ventas,
                SUM(iTotalVenta) AS total_vendido,
                AVG(iTotalVenta) AS promedio_venta,
                MIN(iTotalVenta) AS venta_menor,
                MAX(iTotalVenta) AS venta_mayor
            FROM tbl_ventas
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif (
        str_contains($mensajeLower, "día") ||
        str_contains($mensajeLower, "dia") ||
        str_contains($mensajeLower, "por fecha")
    ) {
        $tipoConsulta = "ventas_por_dia";

        $sql = "
            SELECT 
                DATE(dFechaVenta) AS fecha,
                COUNT(*) AS numero_ventas,
                SUM(iTotalVenta) AS total_vendido
            FROM tbl_ventas
            GROUP BY DATE(dFechaVenta)
            ORDER BY fecha DESC
            LIMIT 10
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Intento de detección automática de otras tablas mencionadas
        $tablasDisponibles = [];
        $stmtTablas = $pdo->query("SHOW TABLES");
        while ($t = $stmtTablas->fetch(PDO::FETCH_COLUMN)) {
            $tablasDisponibles[] = $t;
        }

        $tablaEncontrada = "";
        foreach ($tablasDisponibles as $t) {
            if (str_contains($mensajeLower, strtolower($t))) {
                $tablaEncontrada = $t;
                break;
            }
        }

        if ($tablaEncontrada) {
            $tipoConsulta = "analisis_tabla_" . $tablaEncontrada;
            // Obtenemos los últimos 10 registros para dar contexto a la IA
            $sql = "SELECT * FROM `$tablaEncontrada` ORDER BY 1 DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Default: Resumen de ventas si no se detecta nada específico
            $tipoConsulta = "resumen_general";
            $sql = "
                SELECT 
                    COUNT(*) AS numero_ventas,
                    SUM(iTotalVenta) AS total_vendido,
                    AVG(iTotalVenta) AS promedio_venta,
                    MIN(iTotalVenta) AS venta_menor,
                    MAX(iTotalVenta) AS venta_mayor
                FROM tbl_ventas
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (PDOException $e) {
    echo json_encode([
        "error" => "Error al consultar la base de datos.",
        "detalle" => $e->getMessage()
    ]);
    exit;
}

// Obtener estructura de la base de datos para el contexto de la IA
$estructuraDB = "";
try {
    $tablesStmt = $pdo->query("SHOW TABLES");
    while ($table = $tablesStmt->fetch(PDO::FETCH_COLUMN)) {
        $estructuraDB .= "Tabla: $table\nColumnas: ";
        $columnsStmt = $pdo->query("DESCRIBE $table");
        $cols = [];
        while ($col = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $col['Field'] . " (" . $col['Type'] . ")";
        }
        $estructuraDB .= implode(", ", $cols) . "\n\n";
    }
} catch (PDOException $e) {
    $estructuraDB = "No se pudo obtener la estructura de la base de datos.";
}

/*
    Paso 2:
    Mandamos a la IA:
    - La pregunta del usuario
    - La estructura de la base de datos
    - El tipo de consulta
    - Los datos reales obtenidos de MySQL
*/

$prompt = "
Eres EVA, una asistente inteligente para un sistema de gestión empresarial.

Tu tarea es analizar datos de una base de datos MySQL para ayudar al usuario a entender su negocio.

Estructura de la base de datos detectada:
$estructuraDB

Pregunta del usuario:
$mensajeUsuario

Tipo de consulta realizada internamente:
$tipoConsulta

Datos reales obtenidos desde la base de datos (JSON):
" . json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Instrucciones:
1. Responde en español de forma profesional y amable.
2. Si los datos están vacíos, indícalo y sugiere por qué (ej. no hay ventas hoy).
3. Si hay datos, realiza un análisis breve: resumen numérico, interpretación de tendencias y una sugerencia estratégica para el negocio.
4. Si el usuario pregunta por algo que no está en los datos de la consulta actual pero sí en la estructura de las tablas, sugiérele que sea más específico o dile que puedes ayudarle con esos temas.
5. No menciones detalles técnicos como 'JSON' o 'consultas SQL' a menos que sea necesario.
";

// Usamos DeepSeek si la llave está configurada, si no, intentamos OpenAI (o damos error)
$respuestaIA = "";
if (!empty($DEEPSEEK_API_KEY)) {
    $respuestaIA = llamarDeepSeek($DEEPSEEK_API_KEY, $prompt);
} elseif (!empty($OPENAI_API_KEY)) {
    $respuestaIA = llamarOpenAI($OPENAI_API_KEY, $prompt);
} else {
    $respuestaIA = "Error: No se ha configurado ninguna API Key (DeepSeek o OpenAI) en config.php.";
}

echo json_encode([
    "pregunta" => $mensajeUsuario,
    "tipoConsulta" => $tipoConsulta,
    "datos" => $datos,
    "respuesta" => $respuestaIA
], JSON_UNESCAPED_UNICODE);


/**
 * Función para llamar a DeepSeek
 */
function llamarDeepSeek($apiKey, $prompt) {
    $url = "https://api.deepseek.com/chat/completions";

    $data = [
        "model" => "deepseek-chat",
        "messages" => [
            ["role" => "system", "content" => "Eres un analista de datos experto."],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return "Error al conectar con DeepSeek: " . curl_error($ch);
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result["choices"][0]["message"]["content"])) {
        return $result["choices"][0]["message"]["content"];
    }

    if (isset($result["error"]["message"])) {
        return "Error de DeepSeek: " . $result["error"]["message"];
    }

    return "No se pudo obtener una respuesta válida de DeepSeek. " . $response;
}

/**
 * Función para llamar a OpenAI (Legacy/Fallback)
 */
function llamarOpenAI($apiKey, $prompt) {
    $url = "https://api.openai.com/v1/chat/completions";

    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    if (curl_errno($ch)) return "Error cURL: " . curl_error($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result["choices"][0]["message"]["content"] ?? "Error en respuesta de OpenAI.";
}

?>