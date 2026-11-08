# MAX720RP — web de artista

Para la versión PHP/MariaDB que funciona con XAMPP, consulta [xampp/LEEME.md](xampp/LEEME.md). La carpeta `xampp` está preparada para usarse sin Python; la versión Flask original se conserva como respaldo.

Aplicación web en Flask con SQLite. Incluye página de presentación, catálogo musical enlazado a YouTube, tienda con carrito y registro de pedidos, formulario de booking y cuentas con roles `user` / `admin`.

## Arranque local en Windows

Requiere Python 3.10 o posterior.

```powershell
py -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
$env:SECRET_KEY = (python -c "import secrets; print(secrets.token_hex(32))")
flask --app app create-admin
python app.py
```

Abre `http://127.0.0.1:5000`. Crea el primer administrador con el comando interactivo `flask --app app create-admin`; si el usuario ya existe, el comando actualiza su contraseña y rol de administrador. No hay una contraseña maestra ni se concede el rol admin durante el registro público. Desde `/admin` puedes promover o degradar cuentas, restablecer contraseñas y eliminar usuarios; en `/admin/productos` puedes gestionar la tienda y subir imágenes locales.

## Recuperación por correo

El formulario de contacto envía los mensajes a `max720rp@gmail.com`; el restablecimiento de contraseña también usa el mismo servidor SMTP. Configura antes de arrancar la aplicación `SMTP_HOST`, `SMTP_PORT` (`587`), `SMTP_USER`, `SMTP_PASSWORD` y, opcionalmente, `MAIL_FROM` y `PUBLIC_BASE_URL`. Con Gmail se usa `smtp.gmail.com` y una contraseña de aplicación de Google (no la contraseña normal de la cuenta). En PowerShell, por ejemplo:

```powershell
$env:SMTP_HOST = "smtp.gmail.com"
$env:SMTP_PORT = "587"
$env:SMTP_USER = "tu-correo@gmail.com"
$env:MAIL_FROM = $env:SMTP_USER
$env:SMTP_PASSWORD = Read-Host "Contraseña de aplicación SMTP"
```

Los mensajes de contacto también se guardan en el panel. Si el envío SMTP falla, la web muestra un aviso de error en vez de confirmar falsamente el envío.

En desarrollo local sin SMTP, el enlace de un solo uso se escribe en la consola del servidor. Los tokens vencen en 30 minutos y solo se guarda su hash en la base de datos.

## Publicar en internet y mantenerla encendida

La configuración incluida permite desplegar en Render desde GitHub. Render ejecuta Flask con Gunicorn y vuelve a desplegar automáticamente cuando se suben cambios a la rama conectada. El archivo `render.yaml` configura un disco persistente para SQLite y las fotos subidas; ese disco requiere un servicio de pago en Render. Una instancia gratuita sin disco perdería los usuarios, productos, pedidos e imágenes al reiniciar o desplegar.

1. Sube este proyecto a un repositorio privado en GitHub. No subas `.venv`, `max720rp.db`, secretos ni fotos de `static/uploads`.
2. En Render, conecta GitHub y crea un Blueprint usando ese repositorio. Render leerá `render.yaml`; confirma la creación del servicio y del disco persistente.
3. En el servicio, define `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD` y `MAIL_FROM` para habilitar el envío real de contacto y recuperación. Define también `PUBLIC_BASE_URL` con la URL pública de la web para generar enlaces de recuperación correctos.
4. En la consola Shell del servicio, crea tu administrador con `flask --app app create-admin`. Usa una contraseña robusta. Después inicia sesión y gestiona la web desde el panel.

El primer despliegue crea una base nueva en el servidor; el archivo SQLite que ya existe en tu ordenador no se copia automáticamente. Si quieres conservar las cuentas, pedidos y productos actuales, habrá que migrar esa base y las imágenes al disco del servidor antes de usarlo en producción. El despliegue no se ha publicado desde este entorno porque requiere vincular tu cuenta de GitHub y Render.

Tras la publicación, Render mantiene el proceso arrancado y gestiona los reinicios. Para actualizar la web, sube los cambios a la rama conectada en GitHub; no hace falta abrir Python en tu ordenador para que la web publicada siga disponible.

## Personalización antes de publicar

- La base `max720rp.db` se crea automáticamente en el primer arranque. La página de música muestra los tres vídeos compartidos por el artista, en el orden indicado, con sus miniaturas oficiales de YouTube. Las prendas iniciales son contenido de demostración; edita sus nombres, precios y existencias desde el panel de productos.
- El logo proporcionado se muestra en la cabecera, la portada y el acceso. Sustituye las portadas de demostración por las carátulas oficiales.
- Configura un `SECRET_KEY` aleatorio y persistente. En producción activa HTTPS, sirve la app con un servidor WSGI (por ejemplo, Waitress en Windows) y define `COOKIE_SECURE=1`.
- Los pedidos quedan registrados, pero esta versión no cobra tarjetas: pago y envío se coordinan después de recibirlos. El formulario de booking guarda los mensajes en SQLite para consulta desde el panel.

## Datos

`users` guarda cuentas y roles; `songs` y `products` mantienen el catálogo; las imágenes locales de producto se guardan en `static/uploads`; el banner de página está en `static/max720rp-banner.png`; `orders` y `order_items` conservan el precio y el nombre de cada artículo al confirmar; `inquiries` almacena solicitudes de contacto; `password_resets` guarda hashes de tokens temporales; `app_settings` evita volver a crear los datos de demostración después de borrarlos. La eliminación de una cuenta con pedidos la anonimiza para conservar el historial. Se usan contraseñas hash, formularios POST con token CSRF y transacción para validar/descontar stock.

