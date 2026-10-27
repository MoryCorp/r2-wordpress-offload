<?php
// A restore may only report success for files whose bytes match what was offloaded, and an
// attachment that is not fully restored must stay queued for the next restore.
require __DIR__ . '/../lib.php';

// 06a: download cut short, restore attempted twice.
r2t_reset(['remove_local_files' => true]);
$src = r2t_image(5);
$id = r2t_upload($src, 'restore.jpg');
$local = get_attached_file($id);
$key = r2t_key_of_url(wp_get_attachment_url($id));
r2t_fault(['truncate_get' => [$key => intdiv(filesize($src), 2)]]);
$cleaner = r2t_cleaner();
$claimed = $cleaner->restore_from_r2($id)['success'] | $cleaner->restore_from_r2($id)['success'];
$intact = file_exists($local) && md5_file($local) === md5_file($src);
$queued = in_array($id, $cleaner->get_r2_only_attachments(100), true);
r2t_out('06a', (!$claimed || $intact) && ($intact || $queued), sprintf(
    'restauration %s, fichier %s, %s',
    $claimed ? 'déclarée réussie' : 'refusée',
    file_exists($local) ? ($intact ? 'intact' : 'tronqué') : 'absent',
    $queued ? 'toujours en attente de restauration' : 'sorti de la file de restauration'
));

// 06b: a truncated file already sits at the target path.
r2t_reset(['remove_local_files' => true]);
$src = r2t_image(6);
$id = r2t_upload($src, 'reprise.jpg');
$local = get_attached_file($id);
wp_mkdir_p(dirname($local));
file_put_contents($local, substr(file_get_contents($src), 0, intdiv(filesize($src), 2)));
$claimed = r2t_cleaner()->restore_from_r2($id)['success'];
$intact = md5_file($local) === md5_file($src);
r2t_out('06b', !$claimed || $intact, $claimed
    ? ($intact ? 'fichier tronqué remplacé par la bonne version' : 'fichier tronqué compté restauré')
    : 'restauration refusée');

// 06c: the process running the restore dies mid-download, then the restore is run again.
r2t_reset(['remove_local_files' => true]);
$src = r2t_image(22);
$id = r2t_upload($src, 'interrompu.jpg');
$local = get_attached_file($id);
$key = r2t_key_of_url(wp_get_attachment_url($id));
r2t_fault(['stall_get' => [$key => intdiv(filesize($src), 2)]]);
r2t_child("(new NumosR2\\LocalCleaner(r2t_client()))->restore_from_r2($id);", 5);
r2t_fault([]);
$left = file_exists($local) ? filesize($local) : 0;
$claimed = r2t_cleaner()->restore_from_r2($id)['success'];
$intact = file_exists($local) && md5_file($local) === md5_file($src);
r2t_out('06c', !$claimed || $intact, sprintf(
    'après interruption : %s ; reprise %s, fichier %s',
    $left ? "fichier partiel de $left o à l'emplacement final" : 'emplacement final intact',
    $claimed ? 'déclarée réussie' : 'refusée',
    $intact ? 'intact' : 'tronqué'
));

// 06d: the name freed by local deletion now holds a newer attachment's only copy.
r2t_reset(['remove_local_files' => true]);
$first = r2t_upload(r2t_image(26), 'occupe.jpg');
$second = r2t_upload(r2t_image(27), 'occupe.jpg', false);
$local = get_attached_file($second);
$bytes = file_get_contents($local);
$same_name = $local === get_attached_file($first);
r2t_cleaner()->restore_from_r2($first);
$intact = file_get_contents($local) === $bytes;
r2t_out('06d', $intact, ($same_name ? 'nom réutilisé' : 'nom différent')
    . ', copie unique du second attachment ' . ($intact ? 'intacte' : 'écrasée par la restauration du premier'));
