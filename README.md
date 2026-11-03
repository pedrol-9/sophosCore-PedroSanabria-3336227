# SophosCore - Plataforma Integral de Gestión Escolar con IA

**SophosCore** es un sistema web de gestión académica y escolar diseñado para optimizar el seguimiento pedagógico, centralizar calificaciones y registros académicos, y potenciar la labor de docentes y el aprendizaje de los estudiantes mediante herramientas de Inteligencia Artificial (Google Gemini API).

---

## 🚀 Características Principales

* **Portal Multirrol:** Vistas y accesos diferenciados para Administradores (Rectoría/Coordinación), Docentes y Estudiantes.
* **Gestión Académica Completa:**
  * Control de notas por periodos (P1, P2, P3) y evidencias de aprendizaje.
  * Generación y visualización de boletines escolares con soporte optimizado para impresión.
  * Registro de asistencias y observador del estudiante.
* **Módulos con Inteligencia Artificial:**
  * **Asistente de Redacción Docente:** Generación automatizada de retroalimentaciones pedagógicas, recomendaciones y observaciones de rendimiento.
  * **Tutor Virtual de Aprendizaje:** Asistente conversacional inteligente para guiar al estudiante en sus materias.
* **Diseño Moderno y Responsivo:** Construido con Bootstrap 5, estilo visual glassmorphism, tipografía cuidada y soporte multidispositivo.

---

## 🛠️ Stack Tecnológico

| Capa | Tecnologías |
| :--- | :--- |
| **Backend / API** | PHP 8.x (Arquitectura REST basada en servicios modulares) |
| **Base de Datos** | MySQL / MariaDB (Motor InnoDB, codificación `utf8mb4_spanish_ci`) |
| **Conexión a BD** | PDO (PHP Data Objects) con consultas preparadas seguras |
| **Frontend** | HTML5 semántico, CSS3 personalizado, JavaScript Vanilla, Bootstrap 5.3, Bootstrap Icons |
| **Inteligencia Artificial** | Google Gemini API |

---

## 📁 Estructura del Proyecto

```text
sophoscore-app/
├── api/                     # Endpoints de la API REST en PHP
│   ├── login.php            # Autenticación y verificación de credenciales
│   ├── docente.php          # Consultas y actualización para docentes
│   ├── estudiante.php       # Consultas de materias, notas y tutor
│   └── boletin.php          # Generación de datos de boletines escolares
├── assets/                  # Recursos estáticos
│   ├── css/                 # Hojas de estilo (landing, módulos, impresión)
│   ├── fonts/               # Tipografías locales
│   ├── icons/               # Iconografía en formato SVG
│   ├── img/                 # Logotipos, avatares e ilustraciones
│   └── js/                  # Scripts de interacción y sincronización de datos
├── Components/              # Componentes de interfaz reutilizables (navbar, footer)
├── config/                  # Archivos de configuración
│   ├── config.php           # Constantes del sistema y credenciales de BD
│   └── db.php               # Conexión centralizada PDO a MySQL
├── Database/                # Scripts de base de datos
│   └── sophos_core.sql      # Esquema oficial, tablas e información inicial
├── Views/                   # Vistas del sistema divididas por módulos y roles
│   ├── m1-autenticacion/    # Vista de inicio de sesión
│   ├── m2-academico/        # Calificaciones, boletines y panel académico
│   ├── m3-asistente-docente/# Asistente de redacción IA para profesores
│   ├── m4-tutor-academico/  # Tutor interactivo IA para alumnos
│   └── usuarios/            # Dashboards para admin, docente y estudiante
├── .env                     # Variables de entorno (API Keys)
├── CREDENCIALES.md          # Usuarios y contraseñas de prueba
├── index.html               # Landing page principal y portal de acceso
└── README.md                # Documentación del proyecto
```

---

## ⚙️ Conexión a la Base de Datos

La conexión a la base de datos se encuentra centralizada en `config/db.php`:

* **Motor:** MySQL / MariaDB (InnoDB).
* **Manejador:** PDO (PHP Data Objects).
* **Configuraciones de seguridad:**
  * Modo de error estricto mediante excepciones (`PDO::ERRMODE_EXCEPTION`).
  * Modo de obtención asociativo (`PDO::FETCH_ASSOC`).
  * Desactivación de emulación de sentencias preparadas (`PDO::ATTR_EMULATE_PREPARES => false`) para protección contra inyección SQL.

Los parámetros de conexión se definen en `config/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sophos_core');
define('DB_CHARSET', 'utf8mb4');
```

---

## 📦 Instalación y Puesta en Marcha

### 1. Requisitos Previos
* Servidor local con soporte para PHP 8.0+ y MySQL/MariaDB (como [XAMPP](https://www.apachefriends.org/), [Laragon](https://laragon.org/) o WampServer).
* Navegador web moderno (Chrome, Edge, Firefox).

### 2. Configurar la Base de Datos
1. Iniciar los servicios de Apache y MySQL en el panel de control (ej. XAMPP).
2. Abrir el gestor de bases de datos (ej. phpMyAdmin en `http://localhost/phpmyadmin`).
3. Crear o importar la base de datos ejecutando el script:
   ```
   Database/sophos_core.sql
   ```
   *(Este script creará la base de datos `sophos_core`, las tablas relacionales y cargará los datos de prueba).*

### 3. Configurar Parámetros del Entorno
* Si tu usuario o contraseña de MySQL son diferentes a los predeterminados (`root` sin contraseña), edita los valores en `config/config.php`.
* Si utilizas el Asistente o Tutor de IA, verifica que tu clave API de Gemini esté configurada en `.env`:
  ```env
  GEMINI_API_KEY=tu_clave_aqui
  ```

### 4. Ejecutar el Proyecto
Puedes colocar la carpeta en el directorio público de tu servidor web (ej. `htdocs` en XAMPP) o ejecutar el servidor embebido de PHP desde la raíz del proyecto:

```bash
php -S localhost:8000
```

Accede desde tu navegador a:
```
http://localhost:8000/
```

---

## 🔑 Credenciales de Acceso para Demostración

La contraseña general para todas las cuentas de prueba es: **`Sophos2026!`**

| Rol | Usuario / Correo | Vista Asociada |
| :--- | :--- | :--- |
| **Administrador** | `admin@sophoscore.edu.co` | `Views/usuarios/admin.html` |
| **Docente** | `mariana.ruiz@sophoscore.edu.co` | `Views/usuarios/docente.html` |
| **Estudiante** | `alex.johnson@sophoscore.edu.co` | `Views/usuarios/estudiante.html` |

> *Para consultar opciones adicionales de docentes y estudiantes, revisa el archivo [CREDENCIALES.md](file:///c:/dev/sophoscore-app/CREDENCIALES.md).*

---

## 📄 Licencia

Este proyecto es para fines educativos y de gestión institucional. Todos los derechos reservados.

