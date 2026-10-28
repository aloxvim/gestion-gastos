<?php

// ============================================
// AnalysisController.php
// ============================================
// Análisis de ingresos y gastos con IA (Google Gemini).
//
// Los movimientos no tienen categoría: Gemini la deduce de las anotaciones.
// A la IA solo se le pide clasificar; los importes los suma PHP, para que las
// cifras sean exactas. Se envían a Google las anotaciones y los importes, pero
// no los nombres de las cuentas ni las fechas.

namespace Controllers;

use Core\Database;
use Core\Gemini;
use Models\ConfiguracionIA;
use Models\Movimiento;

class AnalysisController
{
    private const CATEGORIA_SIN_NOTA = 'Sin anotación';
    private const CATEGORIA_OTROS = 'Otros';

    // Anotaciones distintas como máximo por análisis (para no pasar del límite gratuito)
    private const MAX_GRUPOS = 1500;
    private const MAX_LONGITUD_NOTA = 200;

    private $configModel;
    private $movimientoModel;

    public function __construct()
    {
        $this->configModel = new ConfiguracionIA();
        $this->movimientoModel = new Movimiento();
    }

    /**
     * Estado de la configuración: si hay clave guardada (solo sus últimos
     * caracteres) y si el usuario ya tiene movimientos
     */
    public function getConfig($userId)
    {
        try {
            $clave = $this->configModel->getClave($userId);

            jsonSuccess("Configuración obtenida", [
                'config' => [
                    'clave_configurada' => $clave !== null,
                    'clave_final' => $clave !== null ? substr($clave, -4) : null,
                    'tiene_movimientos' => $this->movimientoModel->countByUser($userId) > 0,
                ]
            ]);

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Guardar la clave API (se comprueba con Google antes de guardarla)
     */
    public function saveKey($userId)
    {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $clave = trim($data['clave'] ?? '');

            if ($clave === '') {
                jsonError("La clave API es obligatoria", 400);
            }

            if (strlen($clave) > 255 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $clave)) {
                jsonError("El formato de la clave API no es válido", 400);
            }

            (new Gemini($clave))->validarClave();

            $this->configModel->setClave($userId, $clave);

            logAction($userId, 'ha guardado su clave API de Google AI Studio');

            jsonSuccess("Clave API guardada", [
                'config' => [
                    'clave_configurada' => true,
                    'clave_final' => substr($clave, -4),
                ]
            ]);

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Eliminar la clave API
     */
    public function deleteKey($userId)
    {
        try {
            $this->configModel->deleteClave($userId);

            logAction($userId, 'ha eliminado su clave API de Google AI Studio');

            jsonSuccess("Clave API eliminada");

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Analizar los ingresos y gastos de un periodo
     */
    public function analyze($userId)
    {
        try {
            $clave = $this->configModel->getClave($userId);

            if ($clave === null) {
                jsonError("Primero guarda tu clave API de Google AI Studio en Configuración", 400);
            }

            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $filters = [];

            foreach (['fecha_desde', 'fecha_hasta'] as $campo) {
                if (!empty($data[$campo])) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$campo])) {
                        jsonError("La fecha no es válida", 400);
                    }
                    $filters[$campo] = $data[$campo];
                }
            }

            $movimientos = $this->movimientoModel->findForAnalysis($userId, $filters);

            // Agrupar por tipo + anotación, para enviar cada anotación una sola vez
            $grupos = [];
            $grupoDeMovimiento = [];

            foreach ($movimientos as $i => $mov) {
                $nota = trim(preg_replace('/\s+/u', ' ', (string) $mov['notas']));

                if ($nota === '') {
                    $grupoDeMovimiento[$i] = null;
                    continue;
                }

                $claveGrupo = $mov['tipo'] . '|' . mb_strtolower($nota);

                if (!isset($grupos[$claveGrupo])) {
                    $grupos[$claveGrupo] = [
                        'tipo' => $mov['tipo'],
                        'nota' => mb_substr($nota, 0, self::MAX_LONGITUD_NOTA),
                        'veces' => 0,
                        'centimos' => 0,
                    ];
                }

                $grupos[$claveGrupo]['veces']++;
                $grupos[$claveGrupo]['centimos'] += $this->centimos($mov['cantidad']);
                $grupoDeMovimiento[$i] = $claveGrupo;
            }

            if (count($grupos) > self::MAX_GRUPOS) {
                jsonError("Hay demasiadas anotaciones distintas en este periodo (" . count($grupos) . "). Elige un periodo más corto", 400);
            }

            $categoriaDeGrupo = [];
            $observaciones = [];

            if (!empty($grupos)) {
                $resultado = $this->clasificar($clave, array_values($grupos));
                $clavesGrupos = array_keys($grupos);

                foreach ($resultado['clasificacion'] ?? [] as $item) {
                    $n = (int) ($item['n'] ?? 0);
                    $categoria = trim((string) ($item['categoria'] ?? ''));

                    if (isset($clavesGrupos[$n - 1]) && $categoria !== '') {
                        $categoriaDeGrupo[$clavesGrupos[$n - 1]] = mb_substr($categoria, 0, 50);
                    }
                }

                $observaciones = array_values(array_filter(
                    array_map('trim', $resultado['observaciones'] ?? []),
                    fn($o) => is_string($o) && $o !== ''
                ));
            }

            // Sumar por tipo y categoría (en céntimos, para evitar errores de redondeo)
            $resumen = [
                'ingreso' => $this->resumenVacio(),
                'retirada' => $this->resumenVacio(),
            ];

            foreach ($movimientos as $i => $mov) {
                $grupo = $grupoDeMovimiento[$i];
                $categoria = $grupo === null
                    ? self::CATEGORIA_SIN_NOTA
                    : ($categoriaDeGrupo[$grupo] ?? self::CATEGORIA_OTROS);

                $moneda = $mov['moneda'] ?: 'EUR';
                $centimos = $this->centimos($mov['cantidad']);
                $r = &$resumen[$mov['tipo']];

                if (!isset($r['categorias'][$categoria])) {
                    $r['categorias'][$categoria] = [
                        'nombre' => $categoria,
                        'num_movimientos' => 0,
                        'totales' => [],
                        'movimientos' => [],
                    ];
                }

                $c = &$r['categorias'][$categoria];
                $c['num_movimientos']++;
                $c['totales'][$moneda] = ($c['totales'][$moneda] ?? 0) + $centimos;
                $c['movimientos'][] = [
                    'id' => (int) $mov['id'],
                    'fecha_movimiento' => $mov['fecha_movimiento'],
                    'cantidad' => $mov['cantidad'],
                    'moneda' => $moneda,
                    'notas' => $mov['notas'],
                    'cuenta_nombre' => $mov['cuenta_nombre'],
                ];

                $r['totales'][$moneda] = ($r['totales'][$moneda] ?? 0) + $centimos;

                unset($c, $r);
            }

            logAction($userId, 'ha analizado sus movimientos con IA');

            jsonSuccess("Análisis completado", [
                'analisis' => [
                    'ingresos' => $this->formatearResumen($resumen['ingreso']),
                    'gastos' => $this->formatearResumen($resumen['retirada']),
                    'observaciones' => $observaciones,
                    'num_movimientos' => count($movimientos),
                ]
            ]);

        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Pide a Gemini una categoría para cada anotación y unas observaciones
     */
    private function clasificar($clave, $grupos)
    {
        $otros = self::CATEGORIA_OTROS;

        $instrucciones = <<<TXT
Clasificas los movimientos de una aplicación personal de gastos. Recibes una lista
numerada de anotaciones que el usuario escribió en sus movimientos. Cada línea tiene:
número | tipo (GASTO o INGRESO) | veces que aparece | importe total | anotación.

Asigna a cada número una categoría corta en español:
- Usa pocas categorías y repite exactamente el mismo nombre para cosas del mismo tipo.
  Para los gastos, entre 4 y 12 categorías (por ejemplo: Alimentación, Restaurantes,
  Entretenimiento, Transporte, Hogar, Suministros, Salud, Ropa, Suscripciones,
  Tecnología, Regalos, Educación). Para los ingresos, entre 1 y 6 (por ejemplo:
  Nómina, Ventas, Regalos, Devoluciones, Intereses).
- Todos los movimientos de la lista cuentan como gasto o ingreso real. No crees
  categorías de traspasos ni de movimientos entre cuentas: si la anotación habla de
  sacar dinero del cajero, recargar una tarjeta o pasar dinero de un sitio a otro,
  ponle una categoría descriptiva normal (por ejemplo, "Efectivo" o "Recargas").
- Si la anotación no permite saber de qué se trata, usa "{$otros}".
- Devuelve una entrada en "clasificacion" por cada número de la lista.

En "observaciones" escribe entre 2 y 4 frases breves en español, dirigidas al usuario
(de tú), sobre patrones útiles de sus gastos e ingresos. No inventes datos que no
estén en la lista y no des totales: la aplicación los calcula por su cuenta.
TXT;

        $lineas = [];
        foreach ($grupos as $i => $g) {
            $lineas[] = sprintf(
                '%d | %s | %d | %s | %s',
                $i + 1,
                $g['tipo'] === 'ingreso' ? 'INGRESO' : 'GASTO',
                $g['veces'],
                number_format($g['centimos'] / 100, 2, '.', ''),
                $g['nota']
            );
        }

        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'clasificacion' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'n' => ['type' => 'INTEGER'],
                            'categoria' => ['type' => 'STRING'],
                        ],
                        'required' => ['n', 'categoria'],
                    ],
                ],
                'observaciones' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING'],
                ],
            ],
            'required' => ['clasificacion', 'observaciones'],
        ];

        return (new Gemini($clave))->generarJSON($instrucciones, implode("\n", $lineas), $schema);
    }

    private function resumenVacio()
    {
        return ['totales' => [], 'categorias' => []];
    }

    /**
     * Pasa los céntimos a euros y ordena las categorías de mayor a menor importe
     */
    private function formatearResumen($resumen)
    {
        $aEuros = fn($totales) => array_map(fn($c) => round($c / 100, 2), $totales);

        $categorias = array_values($resumen['categorias']);
        usort($categorias, fn($a, $b) => array_sum($b['totales']) <=> array_sum($a['totales']));

        foreach ($categorias as &$c) {
            $c['totales'] = $aEuros($c['totales']);
        }
        unset($c);

        return [
            'totales' => $aEuros($resumen['totales']),
            'categorias' => $categorias,
        ];
    }

    private function centimos($cantidad)
    {
        return (int) round(((float) $cantidad) * 100);
    }

    /**
     * Si falta la tabla configuracion_ia, avisa de que hay que aplicar la migración
     */
    private function handleError(\Exception $e)
    {
        if (!Database::getInstance()->tableExists('configuracion_ia')) {
            jsonError("Falta actualizar la base de datos: aplica la migración 002_analisis_ia.sql", 500);
        }

        // Nunca 401: el frontend lo interpretaría como sesión caducada
        jsonError($e->getMessage(), 400);
    }
}
