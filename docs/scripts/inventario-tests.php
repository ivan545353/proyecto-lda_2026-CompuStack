<?php
// Uso: php inventario.php rutas.txt  (desde la raíz del backend)
$rutas = [];
foreach (file($argv[1]) as $l) {
  if (!preg_match('/^(\S+) (\S+) \| ([^ ]*) \|/', $l, $m) || str_starts_with($m[2], 'api/')) continue;
  $met = explode('|', $m[1])[0];
  if ($m[3] && ($met !== 'GET' || str_starts_with($m[2], 'buscar') || preg_match('/imprimir|pdf|exportar|comprobantes-transferencia/', $m[2]))) {
    $rutas[$m[3]] = [$met, $m[2]];
  }
}
$porArchivo = []; $porRuta = []; $citas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tests'));
foreach ($it as $f) {
  if (!str_ends_with($f, '.php')) continue;
  $s = file_get_contents($f);
  $rel = str_replace(chr(92), '/', substr($f, 6));
  // ->post( ->put( ->patch( ->delete( (con o sin Json), aunque el argumento esté en la línea siguiente
  $re = '/->(post|put|patch|delete)(Json)?\(\s*(route\(\s*[\x27"]([\w.\-]+)[\x27"]|[\x27"]([^\x27"]*)[\x27"]|(\$\w+|\w+\())/s';
  preg_match_all($re, $s, $mm, PREG_SET_ORDER);
  foreach ($mm as $x) {
    if (!empty($x[4])) { $n = $x[4]; }
    elseif (isset($x[5]) && $x[5] !== '') { if (str_contains($x[5], '/api/') || !str_starts_with($x[5], '/')) continue; $n = 'url:' . $x[5]; }
    else { $n = '?'; }
    if ($n !== '?' && !str_starts_with($n, 'url:') && !isset($rutas[$n])) $n = 'otra:' . $n;
    $porArchivo[$rel][$n] = ($porArchivo[$rel][$n] ?? 0) + 1;
    $porRuta[$n] = ($porRuta[$n] ?? 0) + 1;
  }
  foreach ($rutas as $n => $_) {
    $c = preg_match_all('/[\x27"]' . preg_quote($n, '/') . '[\x27"]/', $s);
    $d = $porArchivo[$rel][$n] ?? 0;
    if ($c > $d) $citas[$rel][$n] = $c - $d;
  }
}
ksort($porArchivo); arsort($porRuta);
$total = 0;
foreach ($porArchivo as $a => $v) { $t = array_sum($v); $total += $t; echo str_pad($a, 50), ' ', str_pad($t, 4, ' ', STR_PAD_LEFT), '  ', json_encode($v), "\n"; }
echo "\nTOTAL: $total llamadas en ", count($porArchivo), " archivos\n\nPor ruta:\n";
foreach ($porRuta as $n => $c) echo "  $c $n\n";
echo "\nCitas de rutas retirables fuera de una llamada directa:\n";
foreach ($citas as $a => $v) echo "  $a ", json_encode($v), "\n";
