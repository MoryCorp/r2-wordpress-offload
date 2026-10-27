<?php
// Files registered in place (SFTP, media import) keep spaces and accents in their names. Core
// returns such URLs raw, as browsers accept in src; a srcset candidate needs its spaces encoded.
require __DIR__ . '/../lib.php';

r2t_reset();
$uploads = wp_upload_dir();
$path = $uploads['path'] . '/Été 2026 vue.jpg';
copy(r2t_image(12), $path);
$id = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'noms', 'post_status' => 'inherit'], $path);
wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $path));

$urls = ['url' => wp_get_attachment_url($id)];
preg_match('#src="([^"]+)"#', apply_filters('the_content', '<img src="' . esc_url($uploads['url'] . '/Été 2026 vue.jpg') . '">'), $m);
$urls['contenu'] = $m[1] ?? '';
preg_match('#srcset="([^"]+)"#', wp_get_attachment_image($id, 'large'), $s);
foreach (empty($s[1]) ? [] : explode(', ', $s[1]) as $i => $candidate) {
    $urls["srcset$i"] = substr($candidate, 0, strrpos($candidate, ' '));
}

$bad = [];
foreach ($urls as $label => $url) {
    $key = r2t_key_of_url($url);
    $unparsable = strpos($label, 'srcset') === 0 && strpos($url, ' ') !== false;
    if ($unparsable || $key === null || r2t_store_meta($key) === null) {
        $bad[] = "$label ($url)";
    }
}
r2t_out('noms', empty($bad), empty($bad) ? count($urls) . ' URL valides et existantes' : 'URL invalides ou inexistantes : ' . implode(' ; ', $bad));
