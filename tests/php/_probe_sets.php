<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$cwd = getcwd();
chdir($root . '/4fach');
require_once $root . '/4fach/tools.php';
require_once $root . '/4fach/vali_data.php';
chdir($cwd);
$source = file_get_contents($root . '/4fach/vali_data.php');
$start = strpos($source, 'function checkdata (){');
$end = strpos($source, '}  // checkdata !!!');
$body = substr($source, $start, $end - $start);
preg_match_all('/case "([^"]+)"\s*:/', $body, $m, PREG_OFFSET_CAPTURE);
$tasks = [];
$count = count($m[0]);
for ($i = 0; $i < $count; $i++) {
    $name = $m[1][$i][0];
    $from = $m[0][$i][1] + strlen($m[0][$i][0]);
    $to = ($i + 1 < $count) ? $m[0][$i + 1][1] : strlen($body);
    $tasks[$name] = substr($body, $from, $to - $from);
}
$fields = [];
$pending = [];
foreach ($tasks as $name => $chunk) {
    preg_match_all('/\$this->validate\s*\[\s*"([^"]+)"\s*\]/', $chunk, $f);
    $set = array_values(array_unique($f[1]));
    if ($set === []) { $pending[] = $name; continue; }
    foreach ($pending as $p) { $fields[$p] = $set; }
    $pending = [];
    $fields[$name] = $set;
}
foreach ($pending as $p) { $fields[$p] = []; }
$acceptsEmpty = static function (string $field) use ($root): bool {
    $cwd = getcwd();
    chdir($root . '/4fach');
    try {
        $v = new vali_data_form([$field => '']);
        $v->checkallfields();
    } finally { chdir($cwd); }
    return ($v->validate[$field] ?? false) === true;
};
foreach ($fields as $task => $set) {
    sort($set);
    $req = [];
    foreach ($set as $f) { if (!$acceptsEmpty($f)) { $req[] = $f; } }
    echo str_pad($task, 22) . " ALL=" . implode(',', $set) . "\n";
    echo str_pad('', 22) . " REQ=" . implode(',', $req) . "\n";
}
echo "--- trim-guarded fields in checkdata ---\n";
preg_match_all('/trim \(\(string\) \(\$this->i_data \["([^"]+)"\]/', $body, $t);
print_r($t[1]);
echo "--- priority/medium storage of empty ---\n";
var_dump(estab_message_priority_storage_value(''));
var_dump(estab_message_medium_storage_value(''));
