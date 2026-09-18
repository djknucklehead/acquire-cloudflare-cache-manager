<?php
/**
 * Plugin Name: Acquire Persistent Public Cache Guard
 * Description: Password cache exclusions and cacheable form-page identification for explicitly configured public domains; independent of the cache-manager plugin.
 * Version: 2.0.0
 */
defined('ABSPATH') || exit;

final class ACFCM_Persistent_Public_Cache_Guard {
    private static $reason = '';
    private static $form_present = false;

    public static function enabled() {
        $scope = get_option('acfcm_public_cache_scope', array());
        if (empty($scope['host']) || wp_parse_url(home_url('/'), PHP_URL_HOST) !== $scope['host']) return false;
        // The reviewed legacy helper owns its original scopes. Do not start a second buffer.
        if (class_exists('Acquire_Public_Cache_Guard', false) && is_callable(array('Acquire_Public_Cache_Guard', 'enabled')) && Acquire_Public_Cache_Guard::enabled()) return false;
        return true;
    }

    public static function protect($reason) {
        if (!self::enabled()) return;
        self::$reason = $reason;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        self::send_headers();
    }

    public static function send_headers() {
        if (headers_sent()) return;
        if (self::$form_present) header('X-Acquire-Form-Page: gravity-forms', true);
        if (self::$reason === '') return;
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate', true);
        header('CDN-Cache-Control: no-store', true);
        header('Cloudflare-CDN-Cache-Control: no-store', true);
        header('X-Acquire-Cache-Guard: '.self::$reason, true);
    }

    public static function prepare() {
        if (!self::enabled() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (is_user_logged_in() || is_preview()) self::protect('authenticated-or-preview');
        $post = get_queried_object();
        if ($post instanceof WP_Post && $post->post_password !== '') self::protect('password-protected');
        // Keep headers available when a form is rendered after the theme's opening markup.
        // Preserve HTML bytes exactly; do not buffer feeds, downloads, REST or admin responses.
        if (class_exists('GFForms') && !is_feed() && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET','HEAD'), true)) {
            ob_start(array(__CLASS__, 'finish'));
        }
    }

    public static function form($form, $ajax = false, $values = null, $context = 'form_display') {
        if ($context === 'form_display' && self::enabled()) {
            self::$form_present = true;
            self::send_headers();
        }
        return $form;
    }

    public static function finish($html) {
        // Also catch rendered form HTML supplied from a fragment cache.
        if (self::enabled() && preg_match('/<form\b[^>]*\bid\s*=\s*["\x27]gform_[0-9]+["\x27]/i', $html)) self::$form_present = true;
        self::send_headers();
        return $html;
    }
}

add_action('template_redirect', array('ACFCM_Persistent_Public_Cache_Guard','prepare'), -9999);
add_filter('gform_pre_render', array('ACFCM_Persistent_Public_Cache_Guard','form'), -9999, 4);
