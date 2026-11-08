import requests
from typing import Optional, Dict, Any
from config import API_URL

class APIClient:
    """Cliente para interactuar con la API REST"""
    
    def __init__(self, token: Optional[str] = None):
        self.base_url = API_URL
        self.token = token
        self.headers = {}
        if token:
            self.headers['Authorization'] = f'Bearer {token}'
    
    def _request(self, method: str, endpoint: str, **kwargs) -> Dict[str, Any]:
        """Realiza una petición HTTP"""
        url = f"{self.base_url}{endpoint}"
        
        # Asegurar que los headers se incluyan
        if 'headers' not in kwargs:
            kwargs['headers'] = {}
        kwargs['headers'].update(self.headers)
        
        try:
            response = requests.request(
                method=method,
                url=url,
                timeout=30,
                **kwargs
            )
            
            if not response.ok:
                # En un 401 el backend distingue "Usuario o contraseña incorrectos"
                # (login) de una sesión caducada; usar su mensaje si lo hay
                fallback = ("Sesión expirada o inválida" if response.status_code == 401
                            else f'Error {response.status_code}')
                try:
                    error_data = response.json()
                    message = error_data.get('message') or fallback
                except ValueError:
                    message = fallback
                raise Exception(message)
            
            try:
                return response.json()
            except ValueError:
                raise Exception(f"Respuesta no válida del servidor: {response.text[:200]}")
        
        except requests.exceptions.RequestException as e:
            raise Exception(f"Error de conexión: {str(e)}")
    
    # AUTH
    def login(self, username: str, password: str) -> Dict[str, Any]:
        """Inicia sesión"""
        headers = {'Content-Type': 'application/json'}
        return self._request('POST', '/auth/login', json={
            'nombre_usuario': username,
            'contrasena': password
        }, headers=headers)
    
    def verify_2fa(self, user_id: int, code: str) -> Dict[str, Any]:
        """Verifica código 2FA"""
        headers = {'Content-Type': 'application/json'}
        return self._request('POST', '/auth/verify-2fa', json={
            'user_id': user_id,
            'codigo': code
        }, headers=headers)
    
    def logout(self) -> Dict[str, Any]:
        """Cierra sesión"""
        headers = {'Content-Type': 'application/json'}
        return self._request('POST', '/auth/logout', headers=headers)
    
    # ACCOUNTS
    def get_accounts(self) -> Dict[str, Any]:
        """Obtiene lista de cuentas"""
        return self._request('GET', '/accounts')
    
    def get_account(self, account_id: int) -> Dict[str, Any]:
        """Obtiene una cuenta específica"""
        return self._request('GET', f'/accounts/{account_id}')
    
    def get_accounts_summary(self) -> Dict[str, Any]:
        """Obtiene resumen de cuentas"""
        return self._request('GET', '/accounts/summary')
    
    # MOVEMENTS
    def get_movements(self, limit: int = 10, **filters) -> Dict[str, Any]:
        """Obtiene lista de movimientos"""
        params = {'limit': limit, **filters}
        return self._request('GET', '/movements', params=params)
    
    def get_movement(self, movement_id: int) -> Dict[str, Any]:
        """Obtiene un movimiento específico"""
        return self._request('GET', f'/movements/{movement_id}')
    
    def create_movement(self, data: Dict[str, Any], file_path: Optional[str] = None) -> Dict[str, Any]:
        """Crea un nuevo movimiento"""
        if file_path:
            # Si hay archivo, usar multipart/form-data
            with open(file_path, 'rb') as f:
                files = {'adjunto': (file_path.split('/')[-1], f)}
                # Enviar como form-data sin Content-Type en headers (requests lo establece automáticamente)
                headers = {k: v for k, v in self.headers.items() if k != 'Content-Type'}
                return self._request('POST', '/movements', data=data, files=files, headers=headers)
        else:
            # Sin archivo, enviar como form-data también
            headers = {k: v for k, v in self.headers.items()}
            return self._request('POST', '/movements', data=data, headers=headers)
    
    def create_transfer(self, data: Dict[str, Any], file_path: Optional[str] = None) -> Dict[str, Any]:
        """Mueve dinero entre dos cuentas (id_cuenta_origen, id_cuenta_destino, cantidad, notas,
        fecha_movimiento), con un adjunto opcional"""
        if file_path:
            with open(file_path, 'rb') as f:
                files = {'adjunto': (file_path.split('/')[-1], f)}
                return self._request('POST', '/movements/transfer', data=data, files=files)
        return self._request('POST', '/movements/transfer', data=data)

    def delete_movement(self, movement_id: int) -> Dict[str, Any]:
        """Elimina un movimiento"""
        return self._request('DELETE', f'/movements/{movement_id}')
