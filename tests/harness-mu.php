<?php
/**
 * Test-only mu-plugin: points the offload plugin's shared R2Client at the fake S3 endpoint.
 * The plugin builds one R2Client in numos_r2_init (plugins_loaded, priority 10) and hands it
 * to every component, so redirecting that instance redirects all traffic.
 */

/**
 * Plugin component instance hooked on a filter, or null.
 */
function r2t_hooked(string $hook, int $priority, string $class) {
    global $wp_filter;
    foreach (($wp_filter[$hook]->callbacks[$priority] ?? []) as $cb) {
        $fn = $cb['function'];
        if (is_array($fn) && is_object($fn[0]) && get_class($fn[0]) === $class) {
            return $fn[0];
        }
    }
    return null;
}

function r2t_media_handler() {
    return r2t_hooked('wp_generate_attachment_metadata', 10, 'NumosR2\MediaHandler');
}

function r2t_url_rewriter() {
    return r2t_hooked('wp_get_attachment_url', 99, 'NumosR2\UrlRewriter');
}

/**
 * Read or write a private property, for wiring the test double only.
 */
function r2t_prop(object $obj, string $name, ...$value) {
    return (function () use ($name, $value) {
        if ($value) {
            $this->$name = $value[0];
        }
        return $this->$name;
    })->call($obj);
}

function r2t_client() {
    $handler = r2t_media_handler();
    return $handler ? r2t_prop($handler, 'r2_client') : null;
}

add_action('plugins_loaded', function () {
    $client = r2t_client();
    if ($client) {
        r2t_prop($client, 'endpoint', getenv('R2T_ENDPOINT'));
    }
}, 11);
