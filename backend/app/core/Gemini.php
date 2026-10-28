<?php

namespace Core;

use Exception;

/**
 * Cliente mínimo de la API de Google Gemini (claves de Google AI Studio).
 *
 * Los modelos se prueban en orden: si uno está saturado (503), sin cuota (429,
 * que en el plan gratuito va por modelo) o no existe (404), se pasa al siguiente.
 * La lista se puede cambiar con GEMINI_MODEL, separando los modelos por comas.
 */
class Gemini
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const TIMEOUT = 120;
    private const MODELOS_POR_DEFECTO = 'gemini-flash-latest,gemini-flash-lite-latest,gemini-2.5-flash';
    // Errores temporales de Google: se reintenta una vez el mismo modelo antes de pasar al siguiente
    private const ESPERA_REINTENTO = 3;

    private $apiKey;
    private $modelos;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
        $this->modelos = array_values(array_filter(array_map(
            'trim',
            explode(',', getenv('GEMINI_MODEL') ?: self::MODELOS_POR_DEFECTO)
        )));
    }

    /**
     * Comprueba que la clave es válida pidiendo la lista de modelos (no gasta cuota)
     */
    public function validarClave()
    {
        $this->request('GET', '/models?pageSize=1');
        return true;
    }

    /**
     * Pide una respuesta JSON que cumpla $schema (formato responseSchema de Gemini)
     * y la devuelve ya decodificada
     */
    public function generarJSON($instrucciones, $prompt, $schema)
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $instrucciones]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $respuesta = $this->generarConModelos($body);

        // Con los modelos que "piensan" puede haber varias partes; las de
        // razonamiento llevan thought = true y no forman parte de la respuesta
        $texto = '';
        foreach ($respuesta['candidates'][0]['content']['parts'] ?? [] as $parte) {
            if (empty($parte['thought']) && isset($parte['text'])) {
                $texto .= $parte['text'];
            }
        }

        $json = json_decode($texto, true);
        if (!is_array($json)) {
            $motivo = $respuesta['candidates'][0]['finishReason'] ?? 'desconocido';
            error_log("Gemini: respuesta no válida (finishReason: {$motivo})");
            throw new Exception("La IA devolvió una respuesta que no se pudo interpretar. Inténtalo de nuevo o reduce el periodo");
        }

        return $json;
    }

    /**
     * Prueba los modelos en orden hasta que uno responda. Los errores que no
     * dependen del modelo (clave no válida, petición incorrecta) se lanzan sin más
     */
    private function generarConModelos($body)
    {
        $ultimoError = null;

        foreach ($this->modelos as $modelo) {
            for ($intento = 1; $intento <= 2; $intento++) {
                try {
                    return $this->request('POST', "/models/{$modelo}:generateContent", $body);
                } catch (Exception $e) {
                    $ultimoError = $e;
                    $status = $e->getCode();

                    if ($status >= 500 && $intento === 1) {
                        sleep(self::ESPERA_REINTENTO);
                        continue;
                    }
                    if ($status >= 500 || $status === 429 || $status === 404) {
                        error_log("Gemini: {$modelo} no disponible (HTTP {$status}), probando el siguiente modelo");
                        continue 2;
                    }
                    throw $e;
                }
            }
        }

        throw $ultimoError;
    }

    private function request($method, $path, $body = null)
    {
        $ch = curl_init(self::API_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            // La clave va en una cabecera y no en la URL para que no acabe en logs
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log("Gemini: error de conexión: {$curlError}");
            throw new Exception("No se pudo conectar con Google Gemini. Comprueba la conexión a Internet del servidor");
        }

        $data = json_decode($raw, true);

        if ($status >= 400) {
            $detalle = $data['error']['message'] ?? $raw;
            error_log("Gemini: HTTP {$status} ({$path}): {$detalle}");
            // El código HTTP va en la excepción para decidir si probar otro modelo
            throw new Exception($this->mensajeError($status, $data['error'] ?? []), $status);
        }

        return $data;
    }

    /**
     * Traduce los errores habituales de la API a un mensaje para el usuario
     */
    private function mensajeError($status, $error)
    {
        $razones = array_column($error['details'] ?? [], 'reason');

        if (in_array('API_KEY_INVALID', $razones) || $status === 401 || $status === 403) {
            return "La clave API de Google AI Studio no es válida o no tiene permiso para usar Gemini";
        }
        if ($status === 429) {
            return "Se ha superado el límite de uso gratuito de Gemini en todos los modelos disponibles. Espera un poco e inténtalo de nuevo";
        }
        if ($status === 404) {
            return "Ninguno de los modelos de Gemini configurados (" . implode(', ', $this->modelos) . ") está disponible";
        }
        if ($status >= 500) {
            return "Google Gemini está saturado ahora mismo en todos los modelos disponibles. Inténtalo en unos minutos";
        }

        return "Error de Google Gemini: " . ($error['message'] ?? "HTTP {$status}");
    }
}
