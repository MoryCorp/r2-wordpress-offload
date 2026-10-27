<?php
// A restored file is placed only where nothing exists yet, including where link() is disabled,
// as PHP-FPM pools commonly configure it.
namespace NumosR2 {
    // Stand-ins for a filesystem without hard links, and for an upload landing on the path
    // between an existence check and the move.
    function link($from, $to) {
        return empty($GLOBALS['r2t_no_link']) ? \link($from, $to) : false;
    }

    function rename($from, $to) {
        if (isset($GLOBALS['r2t_intruder'])) {
            file_put_contents($to, $GLOBALS['r2t_intruder']);
            $GLOBALS['r2t_intruded'] = true;
        }
        return \rename($from, $to);
    }
}

namespace {
    require __DIR__ . '/../lib.php';

    // h5b: restore in a PHP process where link() is disabled.
    r2t_reset(['remove_local_files' => true]);
    $a = r2t_upload(r2t_image(45), 'h5b.jpg');
    $local = get_attached_file($a);
    $expected = r2t_store_get(r2t_key_of_url(wp_get_attachment_url($a)));
    $wp = preg_replace('/^php /', 'php -d disable_functions=link ', getenv('R2T_WP'));
    exec($wp . ' eval ' . escapeshellarg("(new NumosR2\\LocalCleaner(r2t_client()))->restore_from_r2($a);") . ' 2>&1', $output, $code);
    clearstatcache();
    $restored = file_exists($local) && file_get_contents($local) === $expected;
    // The runner reads "Fatal error" in a scenario's output as its own crash: report the cause only.
    $fatal = preg_replace('/^.*Uncaught Error: /', '', (string) current(preg_grep('/Uncaught Error/', $output)));
    r2t_out('h5b', $restored, $restored ? 'restauration faite sans link()' : 'restauration impossible sans link() : ' . ($fatal ?: "code $code"));

    // h5c: no hard links, and an upload lands on the path right after the existence check.
    r2t_reset(['remove_local_files' => true]);
    $a = r2t_upload(r2t_image(46), 'h5c.jpg');
    $local = get_attached_file($a);
    $GLOBALS['r2t_no_link'] = true;
    $GLOBALS['r2t_intruder'] = file_get_contents(r2t_image(47));
    r2t_cleaner()->restore_from_r2($a);
    $overwritten = !empty($GLOBALS['r2t_intruded']) && file_get_contents($local) !== $GLOBALS['r2t_intruder'];
    unset($GLOBALS['r2t_no_link'], $GLOBALS['r2t_intruder']);
    r2t_out('h5c', !$overwritten, $overwritten ? 'fichier arrivé après le contrôle d\'absence écrasé' : 'aucun fichier écrasé');
}
