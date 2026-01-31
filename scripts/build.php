<?php
declare(strict_types=1);

/**
 * Build EPG (XMLTV recortado por horas) + playlist M3U con x-tvg-url para SS IPTV.
 * Escribe primero a .tmp y SOLO reemplaza los ficheros finales si todo OK.
 */

// ===================== CONFIG =====================
$EPG_SRC = getenv('EPG_SRC') ?: 'https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv.xml';
$EPG_PUBLIC_URL = getenv('EPG_PUBLIC_URL') ?: 'https://ssiptv.sfl.workers.dev/epg.xml';

// IMPORTANTE: mejor por secret en GitHub Actions: PLAYLIST_SRC
$PLAYLIST_SRC = getenv('PLAYLIST_SRC') ?: 'https://ipfs.io/ipns/k2k4r8oqlcjxsritt5mczkcn4mmvcmymbqw7113fz2flkrerfwfps004/data/listas/lista_iptv.m3u';

// URL base pública de GitHub Pages (sin / al final), ej: https://TUUSUARIO.github.io/TUREPO
$PAGES_BASE = getenv('PAGES_BASE') ?: '';

// Ventana EPG en horas (24/48 recomendado para bajar de tamaño)
$HOURS = (int)(getenv('EPG_HOURS') ?: '24');

// Reescritura AceStream (opcional)
$OLD_BASE = getenv('OLD_BASE') ?: 'http://127.0.0.1:6878/';
$NEW_BASE = getenv('NEW_BASE') ?: 'http://10.0.0.242:8080/';

// Umbrales mínimos para considerar “válido” (ajusta si quieres)
$MIN_EPG_BYTES = (int)(getenv('MIN_EPG_BYTES') ?: '50000');   // 50 KB
$MIN_M3U_BYTES = (int)(getenv('MIN_M3U_BYTES') ?: '2000');    // 2 KB

// ===================== HELPERS =====================
function fail(string $msg, int $code = 1): void {
  fwrite(STDERR, $msg . "\n");
  exit($code);
}

function http_get(string $url, int $timeout = 60): string {
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'GET',
      'timeout' => $timeout,
      'header'  => "User-Agent: github-actions-ssiptv-bot\r\n",
    ]
  ]);
  $data = @file_get_contents($url, false, $ctx);
  if ($data === false) {
    fail("ERROR: no se pudo descargar: $url");
  }
  return $data;
}

function atomic_replace(string $tmp, string $final): void {
  // rename() es atómico si tmp y final están en el mismo filesystem/directorio
  if (!@rename($tmp, $final)) {
    @unlink($tmp);
    fail("ERROR: no se pudo reemplazar $final");
  }
}

// ===================== VALIDATE CONFIG =====================
if ($PLAYLIST_SRC === '') fail("ERROR: falta PLAYLIST_SRC (ponlo como secret en Actions).");
if ($PAGES_BASE === '')   fail("ERROR: falta PAGES_BASE (https://TUUSUARIO.github.io/TUREPO).");
if ($HOURS < 6)           fail("ERROR: EPG_HOURS demasiado bajo (min 6).");
if ($EPG_PUBLIC_URL === '') fail("ERROR: falta EPG_PUBLIC_URL (URL pública del Worker, ej: https://xxx.workers.dev/epg.xml).");

// ===================== OUTPUT PATHS =====================
$docsDir = __DIR__ . '/../docs';
if (!is_dir($docsDir) && !@mkdir($docsDir, 0777, true)) {
  fail("ERROR: no se pudo crear docs/");
}

$epgFinal = $docsDir . '/epg.xml';
$m3uFinal = $docsDir . '/lista.m3u';

$epgTmp = $docsDir . '/.epg.xml.tmp';
$m3uTmp = $docsDir . '/.lista.m3u.tmp';

// Limpiar restos antiguos
@unlink($epgTmp);
@unlink($m3uTmp);

// ===================== 1) BUILD EPG =====================
$xmlStr = http_get($EPG_SRC, 90);

// Validación mínima de descarga (evitar HTML de error)
if (stripos($xmlStr, '<tv') === false || stripos($xmlStr, '<programme') === false) {
  fail("ERROR: el EPG descargado no parece XMLTV válido (no veo <tv>/<programme>).");
}

$now = time();
$end = $now + $HOURS * 3600;

$dom = new DOMDocument();
$ok = @$dom->loadXML($xmlStr, LIBXML_COMPACT | LIBXML_PARSEHUGE);
if (!$ok) fail("ERROR: no se pudo parsear el XMLTV (DOMDocument::loadXML).");

$xpath = new DOMXPath($dom);

$out = new DOMDocument('1.0', 'UTF-8');
$out->formatOutput = false;
$outTvSrc = $xpath->query('/tv')->item(0);
if (!$outTvSrc) fail("ERROR: no encuentro el nodo <tv> en el XMLTV.");

$outTv = $out->importNode($outTvSrc, false); // false = sin hijos, mantiene atributos
$out->appendChild($outTv);

// Copiar todos los canales
foreach ($xpath->query('/tv/channel') as $ch) {
  $outTv->appendChild($out->importNode($ch, true));
}

// Copiar programas dentro de la ventana (por atributo start)
$kept = 0;
foreach ($xpath->query('/tv/programme') as $pr) {
  $start = $pr->getAttribute('start'); // ej: 20260126213000 +0100
  if ($start === '') continue;

$start = trim($start);

// Normaliza: "YYYYMMDDHHMMSS +0100" -> "YYYYMMDDHHMMSS+0100"
$startNorm = preg_replace('/\s+/', '', $start);

// Si tiene TZ (+HHMM o -HHMM) parsea con offset; si no, asume UTC
if (preg_match('/^\d{14}[+-]\d{4}$/', $startNorm)) {
  $dt = DateTime::createFromFormat('YmdHisO', $startNorm); // O = +0100
} elseif (preg_match('/^\d{14}$/', $startNorm)) {
  $dt = DateTime::createFromFormat('YmdHis', $startNorm, new DateTimeZone('UTC'));
} else {
  $dt = false;
}
if (!$dt) continue;

$ts = $dt->getTimestamp();
  
  if (!$dt) continue;

  $ts = $dt->getTimestamp();
  if ($ts >= $now && $ts <= $end) {
    $outTv->appendChild($out->importNode($pr, true));
    $kept++;
  }
}

file_put_contents($epgTmp, $out->saveXML());

// Validar tamaño EPG
$MIN_PROGRAMMES = (int)(getenv('MIN_PROGRAMMES') ?: '200');

if (!file_exists($epgTmp) || filesize($epgTmp) < $MIN_EPG_BYTES || $kept < $MIN_PROGRAMMES) {
  @unlink($epgTmp);
  fail("ERROR: epg.tmp pequeño o pocos programmes (kept=$kept).");
}
$MAX_EPG_BYTES = (int)(getenv('MAX_EPG_BYTES') ?: (5 * 1024 * 1024));

if (filesize($epgTmp) > $MAX_EPG_BYTES) {
  @unlink($epgTmp);
  fail("ERROR: epg.tmp supera 5MB (SS IPTV recomienda <5MB). Baja EPG_HOURS o filtra canales.");
}

// ===================== 2) BUILD M3U =====================
$m3u = http_get($PLAYLIST_SRC, 90);

// Validación mínima de M3U
if (stripos($m3u, '#EXTINF') === false) {
  @unlink($epgTmp);
  fail("ERROR: la M3U descargada no contiene #EXTINF (¿URL correcta?).");
}

// Cabecera SS IPTV: x-tvg-url="EPG_url"
$epgUrl = $EPG_PUBLIC_URL;
if (preg_match('/^\s*#EXTM3U.*$/m', $m3u)) {
  $m3u = preg_replace('/^\s*#EXTM3U.*$/m', '#EXTM3U x-tvg-url="'.$epgUrl.'"', $m3u, 1);
} else {
  $m3u = '#EXTM3U x-tvg-url="'.$epgUrl.'"' . "\n" . $m3u;
}

// Reescritura AceStream (si aplica)
if ($OLD_BASE !== '' && $NEW_BASE !== '' && $OLD_BASE !== $NEW_BASE) {
  $m3u = str_replace($OLD_BASE, $NEW_BASE, $m3u);
}

file_put_contents($m3uTmp, $m3u);

// Validar tamaño M3U
if (!file_exists($m3uTmp) || filesize($m3uTmp) < $MIN_M3U_BYTES) {
  @unlink($epgTmp);
  @unlink($m3uTmp);
  fail("ERROR: lista.tmp demasiado pequeña.");
}

// ===================== 3) ATOMIC SWAP =====================
atomic_replace($epgTmp, $epgFinal);
atomic_replace($m3uTmp, $m3uFinal);

echo "OK\n";
echo "EPG programmes kept: $kept\n";
echo "EPG bytes: " . filesize($epgFinal) . "\n";
echo "M3U bytes: " . filesize($m3uFinal) . "\n";
