<?php
// Reference run of the batched CLI commands, the safety net for refactoring their loop.
require __DIR__ . '/../lib.php';

r2t_reset();
$sources = [];
for ($i = 0; $i < 5; $i++) {
    $src = r2t_image(30 + $i, 400, 300);
    $sources[r2t_upload($src, "cli-$i.jpg", false)] = md5_file($src);
}

$summaries = [];
$run = function (string $command) use (&$summaries) {
    $out = WP_CLI::runcommand($command, ['return' => 'all', 'launch' => false, 'exit_error' => false]);
    $lines = explode("\n", trim($out->stdout . "\n" . $out->stderr));
    $summaries[] = preg_replace('/^Success: /', '', end($lines));
};

$run('numos-r2 migrate --batch-size=2');
$synced = count(array_filter(array_keys($sources), function ($id) { return get_post_meta($id, '_numos_r2_status', true) === 'synced'; }));

$run('numos-r2 delete-local --batch-size=2 --yes');
$gone = count(array_filter(array_keys($sources), function ($id) { return !file_exists(get_attached_file($id)); }));

$run('numos-r2 restore --batch-size=2 --yes');
$restored = 0;
foreach ($sources as $id => $md5) {
    $restored += (int) (file_exists(get_attached_file($id)) && md5_file(get_attached_file($id)) === $md5);
}

$ok = $synced === 5 && $gone === 5 && $restored === 5;
r2t_out('cli', $ok, "migrés $synced/5, locaux supprimés $gone/5, restaurés intacts $restored/5 ; " . implode(' | ', $summaries));
