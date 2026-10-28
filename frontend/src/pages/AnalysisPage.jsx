import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { analysisAPI } from '../services/api';
import { Card } from '../components/common/Card';
import { Button } from '../components/common/Button';
import { Input } from '../components/common/Input';
import { EmptyState } from '../components/common/EmptyState';
import { LoadingSpinner } from '../components/common/LoadingSpinner';
import {
  Sparkles,
  TrendingUp,
  TrendingDown,
  ChevronDown,
  ChevronUp,
  Lightbulb,
  Key,
  AlertTriangle,
} from 'lucide-react';
import { formatMoney, formatDateShort } from '../utils/formatters';
import toast from 'react-hot-toast';

// Fecha local en formato YYYY-MM-DD (toISOString usaría UTC y podría cambiar de día)
const toDateString = (date) => {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const PERIODOS = [
  { id: 'mes', name: 'Este mes' },
  { id: 'mes_anterior', name: 'Mes anterior' },
  { id: 'tres_meses', name: 'Últimos 3 meses' },
  { id: 'anio', name: 'Este año' },
  { id: 'todo', name: 'Todo' },
  { id: 'personalizado', name: 'Personalizado' },
];

const getRango = (periodo) => {
  const hoy = new Date();
  const y = hoy.getFullYear();
  const m = hoy.getMonth();

  switch (periodo) {
    case 'mes':
      return { fecha_desde: toDateString(new Date(y, m, 1)), fecha_hasta: toDateString(hoy) };
    case 'mes_anterior':
      return { fecha_desde: toDateString(new Date(y, m - 1, 1)), fecha_hasta: toDateString(new Date(y, m, 0)) };
    case 'tres_meses':
      return { fecha_desde: toDateString(new Date(y, m - 2, 1)), fecha_hasta: toDateString(hoy) };
    case 'anio':
      return { fecha_desde: toDateString(new Date(y, 0, 1)), fecha_hasta: toDateString(hoy) };
    default:
      return {};
  }
};

// Los totales vienen por moneda ({ EUR: 120.5 }); casi siempre habrá solo una
const formatTotales = (totales) => {
  const entries = Object.entries(totales || {});
  if (entries.length === 0) return formatMoney(0);
  return entries.map(([moneda, cantidad]) => formatMoney(cantidad, moneda)).join(' + ');
};

const sumTotales = (totales) => Object.values(totales || {}).reduce((a, b) => a + b, 0);

const CategoryRow = ({ categoria, total, color }) => {
  const [open, setOpen] = useState(false);
  const porcentaje = total > 0 ? (sumTotales(categoria.totales) / total) * 100 : 0;

  return (
    <div className="border border-gray-200 rounded-lg">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className="w-full p-4 text-left hover:bg-gray-50 rounded-lg transition-colors"
      >
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <p className="font-medium text-gray-900 truncate">{categoria.nombre}</p>
            <p className="text-xs text-gray-500">
              {categoria.num_movimientos} {categoria.num_movimientos === 1 ? 'movimiento' : 'movimientos'}
            </p>
          </div>
          <div className="flex items-center gap-3 flex-shrink-0">
            <div className="text-right">
              <p className="font-semibold text-gray-900">{formatTotales(categoria.totales)}</p>
              <p className="text-xs text-gray-500">{porcentaje.toFixed(1)} %</p>
            </div>
            {open ? <ChevronUp size={18} className="text-gray-400" /> : <ChevronDown size={18} className="text-gray-400" />}
          </div>
        </div>
        <div className="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden">
          <div className={`h-full rounded-full ${color}`} style={{ width: `${porcentaje}%` }} />
        </div>
      </button>

      {open && (
        <ul className="border-t border-gray-200 divide-y divide-gray-100 max-h-80 overflow-y-auto">
          {categoria.movimientos.map((mov) => (
            <li key={mov.id} className="flex items-center justify-between gap-4 px-4 py-2 text-sm">
              <div className="min-w-0">
                <p className="text-gray-900 truncate">{mov.notas || 'Sin anotación'}</p>
                <p className="text-xs text-gray-500">
                  {formatDateShort(mov.fecha_movimiento)} · {mov.cuenta_nombre}
                </p>
              </div>
              <span className="text-gray-700 flex-shrink-0">{formatMoney(mov.cantidad, mov.moneda)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

const CategorySection = ({ titulo, resumen, color, vacio }) => {
  const total = sumTotales(resumen.totales);

  return (
    <Card>
      <h2 className="text-xl font-semibold text-gray-900 mb-4">{titulo}</h2>
      {resumen.categorias.length === 0 ? (
        <p className="text-center text-gray-500 py-8">{vacio}</p>
      ) : (
        <div className="space-y-3">
          {resumen.categorias.map((categoria) => (
            <CategoryRow key={categoria.nombre} categoria={categoria} total={total} color={color} />
          ))}
        </div>
      )}
    </Card>
  );
};

export const AnalysisPage = () => {
  const [config, setConfig] = useState(null);
  const [loadingConfig, setLoadingConfig] = useState(true);
  const [periodo, setPeriodo] = useState('mes');
  const [rangoPersonalizado, setRangoPersonalizado] = useState({ fecha_desde: '', fecha_hasta: '' });
  const [analizando, setAnalizando] = useState(false);
  const [analisis, setAnalisis] = useState(null);

  useEffect(() => {
    loadConfig();
  }, []);

  const loadConfig = async () => {
    try {
      const response = await analysisAPI.getConfig();
      setConfig(response.data.data.config);
    } catch (error) {
      toast.error(error.response?.data?.message || 'Error al cargar la configuración');
    } finally {
      setLoadingConfig(false);
    }
  };

  const handleAnalyze = async () => {
    const rango = periodo === 'personalizado'
      ? Object.fromEntries(Object.entries(rangoPersonalizado).filter(([, v]) => v))
      : getRango(periodo);

    if (rango.fecha_desde && rango.fecha_hasta && rango.fecha_desde > rango.fecha_hasta) {
      toast.error('La fecha de inicio es posterior a la de fin');
      return;
    }

    setAnalizando(true);
    try {
      const response = await analysisAPI.analyze(rango);
      setAnalisis(response.data.data.analisis);
    } catch (error) {
      toast.error(error.response?.data?.message || 'Error al analizar los movimientos');
    } finally {
      setAnalizando(false);
    }
  };

  if (loadingConfig) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 fade-in">
      <div>
        <h1 className="text-3xl font-bold text-gray-900">Análisis</h1>
        <p className="text-gray-600 mt-1">
          En qué gastas y de dónde vienen tus ingresos, agrupado por la IA a partir de tus anotaciones
        </p>
      </div>

      {!config?.clave_configurada ? (
        <Card>
          <EmptyState
            icon={Key}
            title="Configura tu clave de Google AI Studio"
            description="El análisis usa Google Gemini. Guarda tu clave API gratuita en Configuración para empezar."
            action={
              <Link to="/settings" state={{ tab: 'ai' }}>
                <Button className="mx-auto">Ir a Configuración</Button>
              </Link>
            }
          />
        </Card>
      ) : (
        <>
          <Card>
            <div className="flex flex-wrap gap-2 mb-4">
              {PERIODOS.map((p) => (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => setPeriodo(p.id)}
                  className={`px-3 py-1.5 rounded-lg text-sm border transition-colors ${periodo === p.id
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-gray-200 text-gray-700 hover:bg-gray-50'
                    }`}
                >
                  {p.name}
                </button>
              ))}
            </div>

            {periodo === 'personalizado' && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <Input
                  label="Desde"
                  type="date"
                  value={rangoPersonalizado.fecha_desde}
                  onChange={(e) => setRangoPersonalizado({ ...rangoPersonalizado, fecha_desde: e.target.value })}
                />
                <Input
                  label="Hasta"
                  type="date"
                  value={rangoPersonalizado.fecha_hasta}
                  onChange={(e) => setRangoPersonalizado({ ...rangoPersonalizado, fecha_hasta: e.target.value })}
                />
              </div>
            )}

            <Button onClick={handleAnalyze} loading={analizando}>
              <Sparkles size={18} />
              {analizando ? 'Analizando…' : 'Analizar'}
            </Button>
            {analizando && (
              <p className="text-sm text-gray-500 mt-2">
                La IA está clasificando tus movimientos. Puede tardar hasta un minuto.
              </p>
            )}

            <div className="flex items-start gap-2 mt-4 p-3 rounded-lg bg-yellow-50 border border-yellow-200 text-sm text-yellow-800">
              <AlertTriangle size={18} className="flex-shrink-0 mt-0.5" />
              <p>
                El análisis usa la API gratuita de Google Gemini, que tiene límites de uso y a
                veces está saturada. Si falla, vuelve a intentarlo en unos minutos o prueba con
                un periodo más corto: cuantos más movimientos, más tarda y más fácil es que falle.
              </p>
            </div>
          </Card>

          {analisis && analisis.num_movimientos === 0 && (
            <Card>
              <EmptyState
                icon={Sparkles}
                title="No hay movimientos en este periodo"
                description="Elige otro periodo. Los «Mover dinero» entre tus cuentas no cuentan como ingresos ni gastos."
              />
            </Card>
          )}

          {analisis && analisis.num_movimientos > 0 && (
            <>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Card className="border-l-4 border-green-500">
                  <div className="flex items-center gap-3">
                    <TrendingUp className="w-8 h-8 text-green-600 flex-shrink-0" />
                    <div>
                      <p className="text-sm text-gray-600">Has ingresado</p>
                      <p className="text-2xl font-bold text-gray-900">
                        {formatTotales(analisis.ingresos.totales)}
                      </p>
                    </div>
                  </div>
                </Card>
                <Card className="border-l-4 border-red-500">
                  <div className="flex items-center gap-3">
                    <TrendingDown className="w-8 h-8 text-red-600 flex-shrink-0" />
                    <div>
                      <p className="text-sm text-gray-600">Has gastado</p>
                      <p className="text-2xl font-bold text-gray-900">
                        {formatTotales(analisis.gastos.totales)}
                      </p>
                    </div>
                  </div>
                </Card>
              </div>

              {analisis.observaciones.length > 0 && (
                <Card>
                  <div className="flex items-center gap-2 mb-3">
                    <Lightbulb className="w-5 h-5 text-yellow-500" />
                    <h2 className="text-xl font-semibold text-gray-900">Observaciones</h2>
                  </div>
                  <ul className="space-y-2 text-gray-700 list-disc list-inside">
                    {analisis.observaciones.map((obs, i) => (
                      <li key={i}>{obs}</li>
                    ))}
                  </ul>
                </Card>
              )}

              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <CategorySection
                  titulo="Gastos por categoría"
                  resumen={analisis.gastos}
                  color="bg-red-500"
                  vacio="No hay gastos en este periodo"
                />
                <CategorySection
                  titulo="Ingresos por categoría"
                  resumen={analisis.ingresos}
                  color="bg-green-500"
                  vacio="No hay ingresos en este periodo"
                />
              </div>

              <p className="text-xs text-gray-500">
                Las categorías las deduce la IA a partir de tus anotaciones y pueden contener
                errores; los importes los calcula la aplicación. Los «Mover dinero» entre tus
                cuentas no se incluyen.
              </p>
            </>
          )}
        </>
      )}
    </div>
  );
};
