### 🚀 Sistema de Procesamiento de Solicitudes Automático

Una plataforma web diseñada para optimizar, centralizar y automatizar el flujo de solicitudes entre las tiendas y los analistas. El sistema utiliza un **árbol de decisión lógico** para procesar los requerimientos de forma automática, eliminando el intercambio excesivo de correos electrónicos y facilitando la gestión de datos mediante la carga y generación de reportes en Excel. 

### 🌟 Características Principales

* **Árbol de Decisión Inteligente:** Clasifica y procesa cada solicitud automáticamente según reglas de negocio predefinidas.
* **Cero Correos Electrónicos:** Automatiza la comunicación y los estados, evitando que el personal de tienda y los analistas dependan del email.
* **Gestión Eficiente para Analistas:** Interfaz centralizada para que el analista apruebe, rechace o procese solicitudes masivas sin fricciones.
* **Procesamiento de Excel:** Exportación rápida de reportes y solicitudes optimizada mediante la librería nativa SimpleXLSXGen.
* **Interfaz Autoadaptable:** Diseño limpio, moderno y responsivo adaptado a cualquier dispositivo.

### 🛠️ Tecnologías Utilizadas

* **Backend:** PHP (Procesamiento lógico y manejo de archivos)
* **Frontend:** JavaScript (Interactividad, validaciones dinámicas y peticiones asíncronas)
* **Diseño:** Bootstrap (Estilos y estructura visual responsiva)
* **Librerías:** SimpleXLSXGen.php (Generación ligera y rápida de archivos Excel OpenXML)


Usa el código con precaución.

### 🚀 Instalación y Configuración

1. **Clonar el repositorio:** 

bash

git clone https://github.com/SithFolken/solicitudes.git

Usa el código con precaución.
2. **Configurar el entorno:** 

  * Mueve la carpeta del proyecto a tu servidor local (ej. htdocs en XAMPP o /var/www/html/).
  * Asegúrate de tener instalado **PHP 7.3 o superior**.
3. **Permisos de carpetas:** 

  * Otorga permisos de escritura a la carpeta processed/ (o la carpeta destino de tus reportes) para que PHP pueda guardar los archivos Excel generados:

bash

chmod -R 775 processed/

Usa el código con precaución.
4. **Ejecutar:** 

  * Abre tu navegador web e ingresa a http://localhost/carga_tienda.

### 📈 Flujo de Trabajo (Árbol de Decisión)

1. **Tienda:** Ingresa la solicitud completando los parámetros requeridos en el formulario web.
2. **Sistema (JS/PHP):** Evalúa las respuestas mediante el árbol de decisión lógico.
3. **Analista:** Recibe la solicitud ya filtrada y estructurada en su panel de control.
4. **Salida:** El sistema genera reportes consolidados en formato .xlsx listos para su descarga.