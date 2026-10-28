<?php

namespace Models;

use Core\Database;

/**
 * Clave API de Google AI Studio de cada usuario (tabla configuracion_ia).
 * La clave nunca se devuelve al frontend: solo si existe y sus últimos caracteres.
 */
class ConfiguracionIA
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Devuelve la clave del usuario o null si no tiene
     */
    public function getClave($idUsuario)
    {
        $clave = $this->db->fetchColumn(
            "SELECT clave_api_gemini FROM configuracion_ia WHERE id_usuario = :id",
            ['id' => $idUsuario]
        );

        return $clave ?: null;
    }

    /**
     * Guarda (o reemplaza) la clave del usuario
     */
    public function setClave($idUsuario, $clave)
    {
        $sql = "INSERT INTO configuracion_ia (id_usuario, clave_api_gemini)
                VALUES (:id, :clave)
                ON DUPLICATE KEY UPDATE clave_api_gemini = VALUES(clave_api_gemini)";

        return $this->db->query($sql, ['id' => $idUsuario, 'clave' => $clave]);
    }

    /**
     * Elimina la clave del usuario
     */
    public function deleteClave($idUsuario)
    {
        return $this->db->delete('configuracion_ia', 'id_usuario = :id', ['id' => $idUsuario]);
    }
}
