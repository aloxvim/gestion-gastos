from telegram import Update
from telegram.ext import ContextTypes, ConversationHandler
from services.session_manager import session_manager
from handlers.movement_handlers import require_login, download_attachment, UnsupportedAttachment
from utils.formatters import format_money
from datetime import datetime
import os
from config import (
    MOVE_FROM,
    MOVE_TO,
    MOVE_AMOUNT,
    MOVE_NOTES,
    MOVE_FILE
)

# Conversación /mover: mueve dinero entre dos cuentas del usuario.
# No cuenta como ingreso ni como gasto (ver Movimiento::createTransfer en el backend).
# Los mensajes no usan Markdown porque incluyen nombres de cuentas, que podrían
# contener caracteres como _ o * y romper el formato.


def _account_line(index: int, account: dict) -> str:
    tipo_emoji = '💵' if account['tipo'] == 'efectivo' else '🏦'
    return f"{index}. {tipo_emoji} {account['nombre']} ({format_money(account['balance'])})\n"


def _pick_account(text: str, accounts: list):
    """Devuelve la cuenta elegida por número, o None si la respuesta no es válida"""
    text = text.strip()
    if not text.isdigit():
        return None
    index = int(text) - 1
    if index < 0 or index >= len(accounts):
        return None
    return accounts[index]


def _clear(context: ContextTypes.DEFAULT_TYPE):
    for key in list(context.user_data.keys()):
        if key.startswith('move_'):
            del context.user_data[key]


@require_login
async def move_start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Comando /mover - Inicia el movimiento de dinero entre cuentas"""
    api = session_manager.get_api_client(update.effective_user.id)

    try:
        accounts = api.get_accounts()['data']['cuentas']
    except Exception as e:
        await update.message.reply_text(f"❌ Error: {str(e)}")
        return ConversationHandler.END

    if len(accounts) < 2:
        await update.message.reply_text(
            "❌ Necesitas al menos dos cuentas para mover dinero entre ellas.\n"
            "Crea otra cuenta desde la aplicación web."
        )
        return ConversationHandler.END

    context.user_data['move_accounts'] = accounts

    message = "🔄 Mover dinero entre cuentas\n\n"
    message += "No cuenta como ingreso ni como gasto.\n\n"
    message += "¿Desde qué cuenta sale el dinero?\n\n"
    for i, account in enumerate(accounts, 1):
        message += _account_line(i, account)
    message += "\nResponde con el número de la cuenta, o /cancelar para abortar."

    await update.message.reply_text(message)
    return MOVE_FROM


@require_login
async def move_from(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Recibe la cuenta de origen"""
    accounts = context.user_data.get('move_accounts', [])
    origin = _pick_account(update.message.text, accounts)

    if origin is None:
        await update.message.reply_text(
            f"❌ Responde con un número del 1 al {len(accounts)}."
        )
        return MOVE_FROM

    context.user_data['move_origin'] = origin
    destinations = [acc for acc in accounts if acc['id'] != origin['id']]
    context.user_data['move_destinations'] = destinations

    message = f"Desde: {origin['nombre']}\n\n"
    message += "¿A qué cuenta va el dinero?\n\n"
    for i, account in enumerate(destinations, 1):
        message += _account_line(i, account)
    message += "\nResponde con el número de la cuenta."

    await update.message.reply_text(message)
    return MOVE_TO


@require_login
async def move_to(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Recibe la cuenta de destino"""
    destinations = context.user_data.get('move_destinations', [])
    destination = _pick_account(update.message.text, destinations)

    if destination is None:
        await update.message.reply_text(
            f"❌ Responde con un número del 1 al {len(destinations)}."
        )
        return MOVE_TO

    context.user_data['move_destination'] = destination
    origin = context.user_data['move_origin']

    await update.message.reply_text(
        f"{origin['nombre']} → {destination['nombre']}\n\n"
        f"¿Cuánto quieres mover? Disponible en {origin['nombre']}: {format_money(origin['balance'])}\n"
        "Ejemplo: 50.00"
    )
    return MOVE_AMOUNT


@require_login
async def move_amount(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Recibe la cantidad"""
    try:
        amount = float(update.message.text.strip().replace(',', '.'))
        if amount <= 0:
            raise ValueError()
    except ValueError:
        await update.message.reply_text(
            "❌ Cantidad inválida. Ingresa un número positivo.\n"
            "Ejemplo: 50.00"
        )
        return MOVE_AMOUNT

    context.user_data['move_amount'] = round(amount, 2)

    await update.message.reply_text(
        f"Cantidad: {format_money(amount)}\n\n"
        "¿Deseas agregar notas? (opcional)\n\n"
        "Escribe las notas o envía /omitir para terminar."
    )
    return MOVE_NOTES


@require_login
async def move_notes(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Recibe las notas (o /omitir)"""
    notes = '' if update.message.text.strip() == '/omitir' else update.message.text.strip()[:1000]
    context.user_data['move_notes'] = notes

    await update.message.reply_text(
        "¿Deseas adjuntar un archivo? (PDF o imagen, por ejemplo el ticket)\n\n"
        "Envía el archivo o /omitir para terminar."
    )
    return MOVE_FILE


@require_login
async def move_file(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Recibe el adjunto (o /omitir) y realiza el movimiento"""
    try:
        file_path = await download_attachment(update)
    except UnsupportedAttachment:
        await update.message.reply_text(
            "❌ Tipo de archivo no soportado. Solo se permiten PDF e imágenes "
            "(JPG, PNG, GIF, WEBP).\n\n"
            "Envía otro archivo o /omitir para terminar sin adjunto."
        )
        return MOVE_FILE

    api = session_manager.get_api_client(update.effective_user.id)
    origin = context.user_data['move_origin']
    destination = context.user_data['move_destination']
    amount = context.user_data['move_amount']

    try:
        api.create_transfer({
            'id_cuenta_origen': origin['id'],
            'id_cuenta_destino': destination['id'],
            'cantidad': amount,
            'notas': context.user_data.get('move_notes', ''),
            'fecha_movimiento': datetime.now().strftime('%Y-%m-%d %H:%M:00'),
        }, file_path)

        await update.message.reply_text(
            "✅ Dinero movido correctamente\n\n"
            f"🔄 {format_money(amount)}\n"
            f"{origin['nombre']} → {destination['nombre']}\n"
            + ("📎 Con archivo adjunto\n" if file_path else "")
            + "\nUsa /cuentas para ver los saldos actualizados."
        )
    except Exception as e:
        await update.message.reply_text(
            f"❌ No se pudo mover el dinero: {str(e)}\n\n"
            "Usa /mover para intentarlo de nuevo."
        )
    finally:
        if file_path and os.path.exists(file_path):
            os.remove(file_path)

    _clear(context)
    return ConversationHandler.END
