<?php
/**
 * Security Headers - OneMundo Delivery
 * Define headers de segurança HTTP
 */

class SecurityHeaders {
    private static $applied = false;
    
    public static function apply($nonce = null) {
        if (self::$applied || headers_sent()) {
            return;
        }
        
        // Headers básicos de segurança
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
        
        // CSP com nonce se fornecido
        if ($nonce) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none';");
        }
        
        self::$applied = true;
    }
    
    public static function generateNonce() {
        return base64_encode(random_bytes(16));
    }
}
