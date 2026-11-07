import os
import secrets
import sqlite3
import hashlib
import smtplib
import time
import uuid
from decimal import Decimal, InvalidOperation
from email.message import EmailMessage
from functools import wraps
from pathlib import Path

from flask import Flask, abort, flash, g, redirect, render_template, request, send_from_directory, session, url_for
from werkzeug.security import check_password_hash, generate_password_hash
from werkzeug.utils import secure_filename

BASE_DIR = Path(__file__).resolve().parent
DATABASE = Path(os.environ.get("DATABASE_PATH", str(BASE_DIR / "max720rp.db")))
CONTACT_EMAIL = os.environ.get("CONTACT_EMAIL", "max720rp@gmail.com")
app = Flask(__name__)
app.config.update(SECRET_KEY=os.environ.get("SECRET_KEY", "dev-change-this-before-deploying"), SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE="Lax", SESSION_COOKIE_SECURE=os.environ.get("COOKIE_SECURE") == "1")
app.config["MAX_CONTENT_LENGTH"] = 8 * 1024 * 1024
UPLOAD_DIR = Path(os.environ.get("UPLOAD_DIR", str(BASE_DIR / "static" / "uploads")))


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(DATABASE)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys = ON")
    return g.db


def init_db():
    DATABASE.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(DATABASE)
    db.executescript("""
        PRAGMA foreign_keys = ON;
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user','admin')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS songs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '', youtube_url TEXT NOT NULL DEFAULT '',
            cover_url TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '', price_cents INTEGER NOT NULL CHECK(price_cents >= 0),
            image_url TEXT NOT NULL DEFAULT '', stock INTEGER NOT NULL DEFAULT 0 CHECK(stock >= 0), active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id),
            status TEXT NOT NULL DEFAULT 'received', total_cents INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id INTEGER REFERENCES products(id), product_name TEXT NOT NULL,
            quantity INTEGER NOT NULL CHECK(quantity > 0), unit_price_cents INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS inquiries (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL,
            subject TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL,
            used_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        );
    """)
    user_columns = {row[1] for row in db.execute("PRAGMA table_info(users)")}
    if "is_deleted" not in user_columns:
        db.execute("ALTER TABLE users ADD COLUMN is_deleted INTEGER NOT NULL DEFAULT 0")
    song_columns = {row[1] for row in db.execute("PRAGMA table_info(songs)")}
    if "youtube_id" not in song_columns:
        db.execute("ALTER TABLE songs ADD COLUMN youtube_id TEXT NOT NULL DEFAULT ''")
    if "view_rank" not in song_columns:
        db.execute("ALTER TABLE songs ADD COLUMN view_rank INTEGER NOT NULL DEFAULT 1000")
    seeded = db.execute("SELECT 1 FROM app_settings WHERE setting_key='demo_catalog_seeded'").fetchone()
    if not seeded:
        if db.execute("SELECT COUNT(*) FROM songs").fetchone()[0] == 0:
            db.executemany("INSERT INTO songs(title,description,youtube_url) VALUES(?,?,?)", [
                ("Tu próximo lanzamiento", "Añade aquí la descripción de tu tema.", "https://www.youtube.com/@max720rp"),
                ("Desde el barrio", "Una historia, un sonido, una identidad.", "https://www.youtube.com/@max720rp"),
                ("Sin límites", "Escucha más música en el canal oficial.", "https://www.youtube.com/@max720rp")])
        if db.execute("SELECT COUNT(*) FROM products").fetchone()[0] == 0:
            db.executemany("INSERT INTO products(name,description,price_cents,stock) VALUES(?,?,?,?)", [
                ("Camiseta MAX720RP", "Corte urbano. Tallas S–XXL.", 2500, 12),
                ("Gorra 720", "Edición urbana en negro.", 1800, 8),
                ("Sudadera MAX", "Sudadera negra con identidad roja.", 4500, 6)])
        db.execute("INSERT INTO app_settings(setting_key,setting_value) VALUES('demo_catalog_seeded','1')")
    top_songs_seeded = db.execute("SELECT 1 FROM app_settings WHERE setting_key='youtube_top_songs_seeded'").fetchone()
    if not top_songs_seeded:
        db.execute("UPDATE songs SET published=0 WHERE title IN ('Tu próximo lanzamiento','Desde el barrio','Sin límites') AND youtube_id=''")
        top_songs = [
            ("Hoy Sale El Sol | max720rp ft.lehaci (Prod.Pacific) | Video Lyrics", "feat. lehaci · Prod. Pacific", "hS55ZtyAppk", 1),
            ("La Última Coca cola | max720rp (Prod.Jadez Beats) | (Video Lyrics)", "Prod. Jadez Beats", "ZKWrYxfDG3U", 2),
            ("Proceso - max720rp (Prod. APC Beats)", "Prod. APC Beats", "nQG81tTRsIk", 3),
        ]
        for title, description, youtube_id, rank in top_songs:
            youtube_url = f"https://www.youtube.com/watch?v={youtube_id}"
            cover_url = f"https://i.ytimg.com/vi/{youtube_id}/hqdefault.jpg"
            existing = db.execute("SELECT id FROM songs WHERE youtube_id=?", (youtube_id,)).fetchone()
            if existing:
                db.execute("UPDATE songs SET title=?,description=?,youtube_url=?,cover_url=?,published=1,view_rank=? WHERE id=?", (title, description, youtube_url, cover_url, rank, existing[0]))
            else:
                db.execute("INSERT INTO songs(title,description,youtube_url,cover_url,published,youtube_id,view_rank) VALUES(?,?,?,?,1,?,?)", (title, description, youtube_url, cover_url, youtube_id, rank))
        db.execute("INSERT INTO app_settings(setting_key,setting_value) VALUES('youtube_top_songs_seeded','1')")
    db.commit()
    db.close()


def login_required(fn):
    @wraps(fn)
    def wrapped(*args, **kwargs):
        if not g.user:
            flash("Inicia sesión para continuar.", "error")
            return redirect(url_for("login", next=request.path))
        return fn(*args, **kwargs)
    return wrapped


def admin_required(fn):
    @wraps(fn)
    @login_required
    def wrapped(*args, **kwargs):
        if g.user["role"] != "admin":
            abort(403)
        return fn(*args, **kwargs)
    return wrapped


@app.before_request
def load_user():
    if request.method == "POST":
        submitted = request.form.get("csrf_token", "")
        expected = session.get("csrf_token", "")
        if not expected or not secrets.compare_digest(submitted, expected):
            abort(400, "Invalid form token")
    session.setdefault("csrf_token", secrets.token_urlsafe(32))
    uid = session.get("user_id")
    g.user = get_db().execute("SELECT * FROM users WHERE id=? AND is_deleted=0", (uid,)).fetchone() if uid else None


@app.teardown_appcontext
def close_db(_error=None):
    db = g.pop("db", None)
    if db:
        db.close()


@app.context_processor
def inject_csrf_token():
    return {"csrf_token": session.get("csrf_token", "")}


@app.route("/")
def index():
    songs = get_db().execute("SELECT * FROM songs WHERE published=1 ORDER BY view_rank ASC,id DESC").fetchall()
    products = get_db().execute("SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY id DESC LIMIT 3").fetchall()
    return render_template("index.html", songs=songs, products=products)


@app.route("/musica")
def music():
    songs = get_db().execute("SELECT * FROM songs WHERE published=1 ORDER BY view_rank ASC,id DESC").fetchall()
    return render_template("music.html", songs=songs)


@app.route("/merch")
def merch():
    products = get_db().execute("SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY id").fetchall()
    cart = session.get("cart", {})
    cart_lines, cart_total = [], 0
    for product_id, quantity in cart.items():
        product = get_db().execute("SELECT * FROM products WHERE id=?", (product_id,)).fetchone()
        if product:
            quantity = int(quantity)
            cart_lines.append((product, quantity))
            cart_total += product["price_cents"] * quantity
    return render_template("merch.html", products=products, cart=cart, cart_lines=cart_lines, cart_total=cart_total)


@app.post("/carrito/<int:product_id>")
def add_to_cart(product_id):
    product = get_db().execute("SELECT * FROM products WHERE id=? AND active=1", (product_id,)).fetchone()
    if not product or product["stock"] <= 0:
        flash("Este artículo no está disponible.", "error")
    else:
        cart = session.get("cart", {})
        key = str(product_id)
        if int(cart.get(key, 0)) < product["stock"]:
            cart[key] = int(cart.get(key, 0)) + 1
            session["cart"] = cart
            flash(f"{product['name']} añadido al carrito.", "success")
        else:
            flash("No quedan más unidades disponibles.", "error")
    return redirect(url_for("merch"))


@app.post("/carrito/vaciar")
def clear_cart():
    session.pop("cart", None)
    flash("Carrito vaciado.", "success")
    return redirect(url_for("merch"))


@app.post("/pedido")
@login_required
def place_order():
    db = get_db()
    cart = session.get("cart", {})
    items, total = [], 0
    for product_id, qty in cart.items():
        product = db.execute("SELECT * FROM products WHERE id=? AND active=1", (product_id,)).fetchone()
        qty = int(qty)
        if not product or qty <= 0 or qty > product["stock"]:
            flash("No se pudo confirmar: revisa las existencias del carrito.", "error")
            return redirect(url_for("merch"))
        items.append((product, qty))
        total += product["price_cents"] * qty
    if not items:
        flash("Tu carrito está vacío.", "error")
        return redirect(url_for("merch"))
    try:
        db.execute("BEGIN IMMEDIATE")
        cur = db.execute("INSERT INTO orders(user_id,total_cents) VALUES(?,?)", (g.user["id"], total))
        for product, qty in items:
            changed = db.execute("UPDATE products SET stock=stock-? WHERE id=? AND stock>=?", (qty, product["id"], qty)).rowcount
            if not changed:
                raise ValueError("stock")
            db.execute("INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price_cents) VALUES(?,?,?,?,?)", (cur.lastrowid, product["id"], product["name"], qty, product["price_cents"]))
        db.commit()
    except (ValueError, sqlite3.Error):
        db.rollback()
        flash("El stock ha cambiado. Actualiza el carrito e inténtalo de nuevo.", "error")
        return redirect(url_for("merch"))
    session.pop("cart", None)
    flash(f"Pedido #{cur.lastrowid} registrado. Te contactaremos para coordinar el pago y el envío.", "success")
    return redirect(url_for("merch"))


@app.route("/contacto", methods=["GET", "POST"])
def contact():
    if request.method == "POST":
        name, email = request.form.get("name", "").strip(), request.form.get("email", "").strip()
        subject, message = request.form.get("subject", "").strip(), request.form.get("message", "").strip()
        valid_subjects = {"Concierto / booking", "Colaboración", "Prensa", "Otro"}
        if not all((name, email, subject, message)) or "@" not in email or len(name) > 120 or len(email) > 254 or subject not in valid_subjects or len(message) > 5000 or "\n" in email or "\r" in email:
            flash("Completa todos los campos. El mensaje admite hasta 5000 caracteres.", "error")
        else:
            get_db().execute("INSERT INTO inquiries(name,email,subject,message) VALUES(?,?,?,?)", (name, email, subject, message))
            get_db().commit()
            if send_contact_email(name, email, subject, message):
                flash("Tu mensaje se envió correctamente.", "success")
            else:
                flash("El mensaje quedó guardado, pero no se pudo enviar el correo. Revisa la configuración SMTP del servidor.", "error")
            return redirect(url_for("contact"))
    return render_template("contact.html")


def send_contact_email(name, reply_email, subject, body):
    host = os.environ.get("SMTP_HOST")
    username = os.environ.get("SMTP_USER")
    password = os.environ.get("SMTP_PASSWORD")
    sender = os.environ.get("MAIL_FROM", username or "")
    if not all((host, username, password, sender, CONTACT_EMAIL)):
        app.logger.error("No se puede enviar el formulario de contacto: faltan variables SMTP.")
        return False
    message = EmailMessage()
    message["Subject"] = f"MAX720RP · {subject}"
    message["From"] = sender
    message["To"] = CONTACT_EMAIL
    message["Reply-To"] = reply_email
    message.set_content(f"Nuevo mensaje del formulario de MAX720RP\n\nNombre: {name}\nEmail: {reply_email}\nAsunto: {subject}\n\n{body}")
    try:
        port = int(os.environ.get("SMTP_PORT", "587"))
        with smtplib.SMTP(host, port, timeout=15) as smtp:
            smtp.ehlo()
            smtp.starttls()
            smtp.ehlo()
            smtp.login(username, password)
            smtp.send_message(message)
        return True
    except (OSError, smtplib.SMTPException, ValueError):
        app.logger.exception("Error al enviar el mensaje de contacto a %s.", CONTACT_EMAIL)
        return False


@app.route("/registro", methods=["GET", "POST"])
def register():
    if g.user:
        return redirect(url_for("index"))
    if request.method == "POST":
        username, email, password = request.form.get("username", "").strip(), request.form.get("email", "").strip().lower(), request.form.get("password", "")
        if len(username) < 3 or len(password) < 8 or "@" not in email:
            flash("Usa un nombre de al menos 3 caracteres, un email válido y una contraseña de 8 o más caracteres.", "error")
        else:
            try:
                get_db().execute("INSERT INTO users(username,email,password_hash) VALUES(?,?,?)", (username, email, generate_password_hash(password)))
                get_db().commit()
                flash("Cuenta creada. Ya puedes iniciar sesión.", "success")
                return redirect(url_for("login"))
            except sqlite3.IntegrityError:
                flash("Ese nombre o email ya está registrado.", "error")
    return render_template("register.html")


@app.route("/iniciar-sesion", methods=["GET", "POST"])
def login():
    if g.user:
        return redirect(url_for("index"))
    if request.method == "POST":
        account = request.form.get("account", "").strip()
        user = get_db().execute("SELECT * FROM users WHERE is_deleted=0 AND (username=? COLLATE NOCASE OR email=? COLLATE NOCASE)", (account, account)).fetchone()
        if user and check_password_hash(user["password_hash"], request.form.get("password", "")):
            cart = session.get("cart", {})
            session.clear()
            session["user_id"] = user["id"]
            if cart:
                session["cart"] = cart
            session["csrf_token"] = secrets.token_urlsafe(32)
            next_url = request.args.get("next", "")
            if next_url.startswith("/") and not next_url.startswith("//"):
                return redirect(next_url)
            return redirect(url_for("admin" if user["role"] == "admin" else "index"))
        flash("Usuario o contraseña incorrectos.", "error")
    return render_template("login.html")


def send_password_reset(email, reset_url):
    host = os.environ.get("SMTP_HOST")
    username = os.environ.get("SMTP_USER")
    password = os.environ.get("SMTP_PASSWORD")
    sender = os.environ.get("MAIL_FROM", username or "")
    if not all((host, username, password, sender)):
        if request.remote_addr in ("127.0.0.1", "::1"):
            app.logger.warning("SMTP no configurado. Enlace local de restablecimiento: %s", reset_url)
            return True
        app.logger.error("No se puede enviar restablecimiento: faltan variables SMTP.")
        return False
    message = EmailMessage()
    message["Subject"] = "Restablece tu contraseña de MAX720RP"
    message["From"] = sender
    message["To"] = email
    message.set_content(
        "Hemos recibido una solicitud para cambiar la contraseña de tu cuenta MAX720RP.\n\n"
        f"Crea una contraseña nueva desde este enlace (válido durante 30 minutos):\n{reset_url}\n\n"
        "Si no solicitaste este cambio, ignora este correo."
    )
    try:
        port = int(os.environ.get("SMTP_PORT", "587"))
        with smtplib.SMTP(host, port, timeout=15) as smtp:
            smtp.ehlo()
            smtp.starttls()
            smtp.ehlo()
            smtp.login(username, password)
            smtp.send_message(message)
        return True
    except (OSError, smtplib.SMTPException, ValueError):
        app.logger.exception("Error al enviar el correo de restablecimiento.")
        return False


@app.route("/olvido-contrasena", methods=["GET", "POST"])
def forgot_password():
    if request.method == "POST":
        email = request.form.get("email", "").strip().lower()
        user = get_db().execute("SELECT id,email FROM users WHERE is_deleted=0 AND email=? COLLATE NOCASE", (email,)).fetchone()
        if user:
            token = secrets.token_urlsafe(32)
            token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
            db = get_db()
            db.execute("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL", (int(time.time()), user["id"]))
            db.execute("INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,?)", (user["id"], token_hash, int(time.time()) + 1800))
            db.commit()
            base_url = os.environ.get("PUBLIC_BASE_URL", request.url_root.rstrip("/"))
            reset_url = f"{base_url.rstrip('/')}{url_for('reset_password', token=token)}"
            send_password_reset(user["email"], reset_url)
        flash("Si el correo está registrado, recibirás un enlace para cambiar la contraseña.", "success")
        return redirect(url_for("forgot_password"))
    return render_template("forgot_password.html")


@app.route("/restablecer-contrasena/<token>", methods=["GET", "POST"])
def reset_password(token):
    token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
    reset = get_db().execute("SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>?", (token_hash, int(time.time()))).fetchone()
    if not reset:
        flash("El enlace ha caducado o ya se utilizó. Solicita uno nuevo.", "error")
        return redirect(url_for("forgot_password"))
    if request.method == "POST":
        password = request.form.get("password", "")
        if len(password) < 8:
            flash("La contraseña debe tener al menos 8 caracteres.", "error")
        else:
            db = get_db()
            db.execute("UPDATE users SET password_hash=? WHERE id=?", (generate_password_hash(password), reset["user_id"]))
            db.execute("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL", (int(time.time()), reset["user_id"]))
            db.commit()
            flash("Contraseña actualizada. Ya puedes iniciar sesión.", "success")
            return redirect(url_for("login"))
    return render_template("reset_password.html")


@app.post("/salir")
def logout():
    session.clear()
    flash("Has cerrado sesión.", "success")
    return redirect(url_for("index"))


@app.route("/admin", methods=["GET", "POST"])
@admin_required
def admin():
    db = get_db()
    if request.method == "POST":
        action = request.form.get("action", "role")
        user_id = request.form.get("user_id", type=int)
        target = db.execute("SELECT * FROM users WHERE id=? AND is_deleted=0", (user_id,)).fetchone()
        if action == "reset_password":
            password = request.form.get("new_password", "")
            if not target or len(password) < 8:
                flash("La nueva contraseña debe tener al menos 8 caracteres.", "error")
            else:
                db.execute("UPDATE users SET password_hash=? WHERE id=?", (generate_password_hash(password), user_id))
                db.execute("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL", (int(time.time()), user_id))
                db.commit()
                flash(f"Contraseña restablecida para {target['username']}.", "success")
            return redirect(url_for("admin"))
        if action == "delete_user":
            if not target:
                flash("No se encontró esa cuenta.", "error")
            elif target["id"] == g.user["id"]:
                flash("No puedes eliminar la cuenta con la que estás usando el panel.", "error")
            else:
                db.execute("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL", (int(time.time()), user_id))
                order_count = db.execute("SELECT COUNT(*) FROM orders WHERE user_id=?", (user_id,)).fetchone()[0]
                if order_count:
                    db.execute("UPDATE users SET username=?,email=?,password_hash=?,role='user',is_deleted=1 WHERE id=?", (f"cuenta-eliminada-{user_id}", f"eliminado-{user_id}@invalid.local", generate_password_hash(secrets.token_urlsafe(32)), user_id))
                    flash("Cuenta eliminada y anonimizada; se conservaron sus pedidos.", "success")
                else:
                    db.execute("DELETE FROM users WHERE id=?", (user_id,))
                    flash("Cuenta eliminada.", "success")
                db.commit()
            return redirect(url_for("admin"))
        role = request.form.get("role")
        if not target or role not in ("user", "admin"):
            flash("No se pudo actualizar el usuario.", "error")
        elif target["id"] == g.user["id"] and role != "admin":
            flash("No puedes quitarte tu propio acceso de administrador.", "error")
        else:
            db.execute("UPDATE users SET role=? WHERE id=?", (role, user_id))
            db.commit()
            flash(f"Rol de {target['username']} actualizado.", "success")
        return redirect(url_for("admin"))
    users = db.execute("SELECT id,username,email,role,created_at FROM users WHERE is_deleted=0 ORDER BY id").fetchall()
    orders = db.execute("SELECT orders.*,users.username FROM orders JOIN users ON users.id=orders.user_id ORDER BY orders.id DESC LIMIT 20").fetchall()
    inquiries = db.execute("SELECT * FROM inquiries ORDER BY id DESC LIMIT 20").fetchall()
    return render_template("admin.html", users=users, orders=orders, inquiries=inquiries)


@app.post("/admin/mensajes/<int:inquiry_id>/eliminar")
@admin_required
def delete_inquiry(inquiry_id):
    db = get_db()
    cursor = db.execute("DELETE FROM inquiries WHERE id=?", (inquiry_id,))
    db.commit()
    if cursor.rowcount:
        flash("Mensaje eliminado.", "success")
    else:
        flash("Ese mensaje ya no existe.", "error")
    return redirect(url_for("admin"))


@app.post("/admin/pedidos/<int:order_id>/eliminar")
@admin_required
def delete_order(order_id):
    db = get_db()
    cursor = db.execute("DELETE FROM orders WHERE id=?", (order_id,))
    db.commit()
    if cursor.rowcount:
        flash("Pedido eliminado junto con sus líneas. El stock actual no se modifica.", "success")
    else:
        flash("Ese pedido ya no existe.", "error")
    return redirect(url_for("admin"))


@app.route("/admin/productos", methods=["GET", "POST"])
@admin_required
def admin_products():
    db = get_db()
    if request.method == "POST":
        return_q = request.form.get("return_q", "").strip()[:120]
        return_url = url_for("admin_products", q=return_q) if return_q else url_for("admin_products")
        action = request.form.get("action", "save")
        product_id = request.form.get("product_id", type=int)
        if action == "delete" and product_id:
            previous = db.execute("SELECT image_url FROM products WHERE id=?", (product_id,)).fetchone()
            db.execute("UPDATE order_items SET product_id=NULL WHERE product_id=?", (product_id,))
            db.execute("DELETE FROM products WHERE id=?", (product_id,))
            db.commit()
            remove_product_image(previous["image_url"] if previous else "")
            flash("Producto eliminado. Los pedidos históricos conservan su nombre y precio.", "success")
            return redirect(return_url)
        name = request.form.get("name", "").strip()
        description = request.form.get("description", "").strip()
        image_file = request.files.get("image")
        active = 1 if request.form.get("active") == "1" else 0
        saved_image = ""
        try:
            price = Decimal(request.form.get("price", "")).quantize(Decimal("0.01"))
            stock = int(request.form.get("stock", ""))
            if not name or len(name) > 120 or len(description) > 1000 or price < 0 or price > 100000 or stock < 0 or stock > 1000000:
                raise ValueError
            price_cents = int(price * 100)
            previous = db.execute("SELECT image_url FROM products WHERE id=?", (product_id,)).fetchone() if product_id else None
            if product_id and not previous:
                raise ValueError
            old_image = previous["image_url"] if previous else ""
            saved_image = save_product_image(image_file) if image_file and image_file.filename else ""
            image_url = saved_image or old_image
            if product_id:
                db.execute("UPDATE products SET name=?,description=?,price_cents=?,image_url=?,stock=?,active=? WHERE id=?", (name, description, price_cents, image_url, stock, active, product_id))
                flash("Producto actualizado.", "success")
            else:
                db.execute("INSERT INTO products(name,description,price_cents,image_url,stock,active) VALUES(?,?,?,?,?,?)", (name, description, price_cents, image_url, stock, active))
                flash("Producto creado.", "success")
            db.commit()
            if image_url != old_image:
                remove_product_image(old_image)
        except (InvalidOperation, ValueError, TypeError, sqlite3.IntegrityError):
            db.rollback()
            remove_product_image(saved_image)
            flash("Revisa los datos y sube una imagen PNG, JPG o WebP válida (máximo 8 MB).", "error")
        return redirect(return_url)
    query = request.args.get("q", "").strip()[:120]
    if query:
        pattern = f"%{query}%"
        products = db.execute("SELECT * FROM products WHERE name LIKE ? OR description LIKE ? ORDER BY id DESC", (pattern, pattern)).fetchall()
    else:
        products = db.execute("SELECT * FROM products ORDER BY id DESC").fetchall()
    return render_template("admin_products.html", products=products, query=query)


def save_product_image(upload):
    filename = secure_filename(upload.filename)
    extension = Path(filename).suffix.lower()
    if extension not in (".png", ".jpg", ".jpeg", ".webp"):
        raise ValueError("unsupported image type")
    signature = upload.stream.read(12)
    upload.stream.seek(0)
    valid = (
        (extension == ".png" and signature.startswith(b"\x89PNG\r\n\x1a\n"))
        or (extension in (".jpg", ".jpeg") and signature.startswith(b"\xff\xd8\xff"))
        or (extension == ".webp" and signature.startswith(b"RIFF") and signature[8:12] == b"WEBP")
    )
    if not valid:
        raise ValueError("file content does not match image extension")
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    stored_name = f"{uuid.uuid4().hex}{extension}"
    upload.save(UPLOAD_DIR / stored_name)
    return f"uploads/{stored_name}"


def remove_product_image(image_path):
    if not image_path or not image_path.startswith("uploads/"):
        return
    filename = Path(image_path).name
    candidate = UPLOAD_DIR / filename
    if candidate.parent == UPLOAD_DIR and candidate.is_file():
        candidate.unlink()


@app.get("/uploads/<path:filename>")
def product_upload(filename):
    """Serve product images from local storage or a configured persistent disk."""
    return send_from_directory(UPLOAD_DIR, filename)


@app.cli.command("create-admin")
def create_admin():
    """Create the first admin (or promote an existing account): flask --app app create-admin"""
    import getpass
    username = input("Admin username: ").strip()
    email = input("Admin email: ").strip().lower()
    password = getpass.getpass("Admin password (8+ chars): ")
    if len(username) < 3 or "@" not in email or len(password) < 8:
        raise SystemExit("Invalid username, email, or password.")
    db = get_db()
    existing = db.execute("SELECT id FROM users WHERE username=? COLLATE NOCASE OR email=? COLLATE NOCASE", (username, email)).fetchone()
    if existing:
        db.execute("UPDATE users SET role='admin',password_hash=? WHERE id=?", (generate_password_hash(password), existing["id"]))
    else:
        db.execute("INSERT INTO users(username,email,password_hash,role) VALUES(?,?,?,'admin')", (username, email, generate_password_hash(password)))
    db.commit()
    print("Admin account ready.")


init_db()

if __name__ == "__main__":
    app.run(debug=os.environ.get("FLASK_DEBUG") == "1")
