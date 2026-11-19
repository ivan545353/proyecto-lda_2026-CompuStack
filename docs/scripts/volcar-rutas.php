<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$pre = "App\Http\Controllers\\";
foreach (app('router')->getRoutes() as $r) {
  $u = $r->uri();
  if (str_starts_with($u,'sanctum') || str_starts_with($u,'_') || str_starts_with($u,'storage') || $u==='up') continue;
  $mw = array_filter($r->gatherMiddleware(), fn($m)=>is_string($m));
  $mw = array_map(fn($m)=>str_contains($m,chr(92)) ? substr(strrchr($m,chr(92)),1) : $m, $mw);
  $mw = array_filter($mw, fn($m)=>!in_array($m,['web','api']));
  echo implode('|',array_diff($r->methods(),['HEAD'])),' ',$u,' | ',$r->getName(),' | ',str_replace($pre,'',$r->getActionName()),' | ',implode(',',$mw),"\n";
}
