<?php
class EmailService {

    public static function notificarNuevaSolicitud($para, $nombre, $datos) {
        self::enviarCorreo($para, $nombre, "🔔 Nueva Solicitud #{$datos['id_solicitud']}", self::plantillaNuevaSolicitud($nombre, $datos));
    }

    public static function notificarSolicitudCreada($para, $nombre, $datos) {
        self::enviarCorreo($para, $nombre, "✅ Solicitud #{$datos['id_solicitud']} Creada", self::plantillaSolicitudCreada($nombre, $datos));
    }

    public static function notificarSolicitudProcesada($para, $nombre, $datos, $skus) {
        self::enviarCorreo($para, $nombre, " Solicitud #{$datos['id_solicitud']} Procesada", self::plantillaProcesada($nombre, $datos, $skus, false));
    }

    public static function notificarCambiosSolicitud($para, $nombre, $datos, $skus) {
        self::enviarCorreo($para, $nombre, "⚠️ Solicitud #{$datos['id_solicitud']} con Cambios", self::plantillaProcesada($nombre, $datos, $skus, true));
    }

    private static function enviarCorreo($para, $nombre, $asunto, $cuerpo) {
        // Intentar usar PHPMailer si existe
        $phpMailerPath = __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
        
        if (file_exists($phpMailerPath)) {
            require_once $phpMailerPath;
            require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';
            require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';
            
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = 'smtp.correocl.falabella.com';
                $mail->SMTPAuth   = false;
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
                $mail->Port       = 25;
                $mail->setFrom('no-reply@sodimac.cl', 'Sistema de Cargas');
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->addAddress($para, $nombre);
                $mail->Subject = $asunto;
                $mail->Body    = $cuerpo;
                $mail->send();
                error_log("✅ Email enviado (PHPMailer) a: {$para}");
                return true;
            } catch (Exception $e) {
                error_log("❌ Error PHPMailer: " . $mail->ErrorInfo);
            }
        }

        // Fallback: mail() nativo (no rompe el sistema)
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: Sistema de Cargas <no-reply@sodimac.cl>\r\n";
        
        @mail($para, $asunto, $cuerpo, $headers);
        error_log("⚠️ Email intentado (mail nativo) a: {$para}");
        return true;
    }

    // ... (las mismas plantillas HTML del mensaje anterior) ...
    private static function plantillaNuevaSolicitud($nombre, $datos) {
        return "<h2 style='color:#198754;'> Nueva Solicitud</h2><p>Hola {$nombre}</p><p>ID: #{$datos['id_solicitud']}</p><p>SKU: {$datos['sku']}</p>";
    }
    
    private static function plantillaSolicitudCreada($nombre, $datos) {
        return "<h2 style='color:#0d6efd;'>✅ Solicitud Creada</h2><p>Hola {$nombre}</p><p>Tu solicitud #{$datos['id_solicitud']} fue creada.</p>";
    }
    
    private static function plantillaProcesada($nombre, $datos, $skus, $con_cambios) {
        $tabla = "<table border='1' style='border-collapse:collapse; padding:10px;'>";
        foreach ($skus as $sku) {
            $tabla .= "<tr><td>{$sku['sku']}</td><td>{$sku['cantidad_final']}</td><td>{$sku['md']}</td></tr>";
        }
        $tabla .= "</table>";
        return "<h2>Solicitud #{$datos['id_solicitud']} Procesada</h2>{$tabla}";
    }
}
?>