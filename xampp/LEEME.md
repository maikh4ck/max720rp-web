# MAX720RP para XAMPP (PHP + MariaDB)

Esta versión sustituye Flask/Python por PHP y MariaDB. La aplicación original y su archivo `max720rp.db` se mantienen en la raíz del proyecto hasta completar y revisar la migración.

**Estado actual:** la base MariaDB `max720rp` de este XAMPP ya contiene una migración verificada de `max720rp.db`. No vuelvas a ejecutar `setup.php` salvo que quieras reemplazar los datos existentes de MariaDB por el contenido del SQLite.

## Instalar en XAMPP

1. En el panel XAMPP, inicia **Apache** y **MySQL**.
2. Copia la carpeta `xampp` del proyecto a `C:\xampp\htdocs\max720rp` (el resultado debe incluir `C:\xampp\htdocs\max720rp\index.php` y `static\`).
3. Edita `config.php` si tu MariaDB tiene otra contraseña para `root`.
4. Ejecuta desde PowerShell la preparación de la base y la importación de tus datos:

   ```powershell
   cd C:\xampp\htdocs\max720rp
   C:\xampp\php\php.exe setup.php "C:\Users\Usuario\Documents\pagina max720rp\max720rp.db"
   ```

   El instalador crea `max720rp`, crea las tablas e importa registros. Conserva IDs y hashes de contraseña del SQLite, así que los usuarios podrán entrar con su contraseña actual. El fichero SQLite original no se modifica. Si MariaDB ya tenía información, el importador reemplaza las filas de esas tablas: ejecútalo solo una vez sobre una base vacía.

5. Abre `http://localhost/max720rp/`. Si la base SQLite actual contiene una cuenta administradora, su rol se conserva. Para crear o recuperar una cuenta admin puedes ejecutar `C:\xampp\php\php.exe create_admin.php` desde la consola. Ese script solo funciona por CLI.

## Funciones incluidas

- Portada, catálogo de YouTube, merch, contacto y navegación móvil con los recursos visuales existentes.
- Registro, inicio/cierre de sesión, compatibilidad con hashes scrypt/PBKDF2 de Flask y roles de usuario/admin.
- Carrito por sesión, pedidos transaccionales con control de stock e historial.
- Panel de administración para roles, restablecimiento de claves, cuentas, pedidos y mensajes.
- Gestión de productos con imagen local, publicar/ocultar, búsqueda y eliminación.
- Solicitud de recuperación de contraseña con enlace de un uso por 30 minutos.

## Correo y recuperación

La versión PHP envía directamente por SMTP con TLS; no depende de `mail()` ni del `sendmail.ini` de XAMPP. El proyecto viene preparado para Gmail (`smtp.gmail.com`, puerto `587`, STARTTLS), igual que la versión Flask, que esperaba `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD` y `MAIL_FROM`. No había una contraseña SMTP configurada en el entorno anterior.

Para activar el envío real, abre la cuenta `max720rp@gmail.com`, activa la verificación en dos pasos y crea una contraseña de aplicación en [Google App Passwords](https://myaccount.google.com/apppasswords). Google requiere la verificación en dos pasos para crearla. Edita `C:\xampp\htdocs\max720rp\config.local.php` y guarda la contraseña de aplicación en `smtp_password` (puedes pegarla con o sin espacios). No uses la contraseña normal de Gmail ni compartas la clave por chat. El archivo local está bloqueado desde la web y excluido de Git. `config.local.example.php` sirve como plantilla si necesitas reconstruir la configuración local.

Después reinicia Apache desde XAMPP. La recuperación emite un enlace de un solo uso válido por 30 minutos. Los mensajes de booking se guardan en la base y se envían a `max720rp@gmail.com`; si el SMTP falla, el panel conserva el mensaje.

## Publicación

XAMPP está pensado para desarrollo local. Para servirlo públicamente se necesita un hosting que admita PHP y MariaDB, activar HTTPS y cambiar las credenciales de la base. No expongas el servidor local de XAMPP a Internet como alojamiento de producción.

