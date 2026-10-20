export const formatMoney = (amount, currency = 'EUR') => {
  return new Intl.NumberFormat('es-ES', {
    style: 'currency',
    currency: currency,
  }).format(amount);
};

export const formatDate = (date) => {
  return new Date(date).toLocaleString('es-ES', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

// "Mover dinero": cuentas de origen y destino. El movimiento recibido puede ser
// cualquiera de las dos partes del par (retirada en origen o ingreso en destino).
export const getTransferAccounts = (movement) => {
  const own = { nombre: movement.cuenta_nombre, color: movement.cuenta_color };
  const other = {
    nombre: movement.contraparte_nombre || 'Cuenta eliminada',
    color: movement.contraparte_color || '#9ca3af',
  };
  return movement.tipo === 'retirada'
    ? { from: own, to: other }
    : { from: other, to: own };
};

export const formatDateShort = (date) => {
  return new Date(date).toLocaleDateString('es-ES', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};