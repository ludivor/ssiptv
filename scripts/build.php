<?php
declare(strict_types=1);

/**
 * Build EPG (XMLTV recortado por horas) filtrando canales según tvg-id
 * extraídos de una playlist M3U remota.
 *
 * Salida: docs/epg.xml
 * Nota: NO genera ni guarda ninguna M3U en el repositorio.
 */

// ===================== CONFIG =====================
$EPG_SRC = getenv('EPG_SRC') ?: 'https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv.xml';

// Playlist remota (solo se usa para extraer tvg-id; no se publica)
$PLAYLIST_SRC = getenv('PLAYLIST_SRC') ?: '';

// Ventana EPG en horas
$HOURS = (int)(getenv('EPG_HOURS') ?: '24');

// Umbrales mínimos
$MIN_EPG_BYTES = (int)(getenv('MIN_EPG_BYTES') ?: '50000');   // 50 KB
$MIN_PROGRAMMES = (int)(getenv('MIN_PROGRAMMES') ?: '200');
$MAX_EPG_BYTES = (int)(getenv('MAX_EPG_BYTES') ?: (5 * 1024 * 1024)); // 5 MB

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
      'header'  => "User-Agent: VLC/3.0.18 LibVLC/3.0.18\r\n" .
                   "Accept: */*\r\n",
      'follow_location' => 1
    ]
  ]);
  
  $data = @file_get_contents($url, false, $ctx);
  if ($data === false) {
    fail("ERROR: no se pudo descargar: $url");
  }

  // Descomprimir automáticamente si el servidor responde en gzip
  if (substr($data, 0, 2) === "\x1f\x8b") {
    $decompressed = @gzdecode($data);
    if ($decompressed !== false) {
      $data = $decompressed;
    }
  }

  return $data;
}

// ===================== 0) DOWNLOAD M3U + EXTRAER tvg-id =====================
$m3u = http_get($PLAYLIST_SRC, 90);

if (stripos($m3u, '#EXTINF') === false) {
  // Muestra los primeros 300 caracteres para ver qué devolvió realmente el servidor
  $preview = substr(trim($m3u), 0, 300);
  fail("ERROR: la M3U descargada no contiene #EXTINF.\n--- CONTENIDO RECIBIDO ---\n$preview\n--------------------------");
}
function atomic_replace(string $tmp, string $final): void {
  if (!@rename($tmp, $final)) {
    @unlink($tmp);
    fail("ERROR: no se pudo reemplazar $final");
  }
}

// ===================== VALIDATE CONFIG =====================
if ($PLAYLIST_SRC === '') fail("ERROR: falta PLAYLIST_SRC (ponlo como secret/variable en Actions).");
if ($HOURS < 6)           fail("ERROR: EPG_HOURS demasiado bajo (min 6).");

// ===================== OUTPUT PATHS =====================
$docsDir = __DIR__ . '/../docs';
if (!is_dir($docsDir) && !@mkdir($docsDir, 0777, true)) {
  fail("ERROR: no se pudo crear docs/");
}

$epgFinal = $docsDir . '/epg.xml';
$epgTmp   = $docsDir . '/.epg.xml.tmp';

@unlink($epgTmp);

// ===================== 0) DOWNLOAD M3U + EXTRAER tvg-id =====================
$m3u = http_get($PLAYLIST_SRC, 90);

if (stripos($m3u, '#EXTINF') === false) {
  fail("ERROR: la M3U descargada no contiene #EXTINF (¿URL correcta?).");
}

$wanted = []; // set: id => true
if (preg_match_all('/tvg-id="([^"]+)"/i', $m3u, $mm)) {
  foreach ($mm[1] as $id) {
    $id = preg_replace('/\s+/', ' ', trim($id));
    if ($id !== '') $wanted[$id] = true;
  }
}

if (count($wanted) < 1) {
  fail('ERROR: no pude extraer tvg-id de la M3U (no puedo filtrar canales).');
}

// ===================== 1) DOWNLOAD + PARSE EPG =====================
$xmlStr = http_get($EPG_SRC, 90);

if (stripos($xmlStr, '<tv') === false || stripos($xmlStr, '<programme') === false) {
  fail("ERROR: el EPG descargado no parece XMLTV válido (no veo <tv>/<programme>).");
}

$now = time();
$end = $now + $HOURS * 3600;

$dom = new DOMDocument();
$ok = @$dom->loadXML($xmlStr, LIBXML_COMPACT | LIBXML_PARSEHUGE);
if (!$ok) fail("ERROR: no se pudo parsear el XMLTV (DOMDocument::loadXML).");

$xpath = new DOMXPath($dom);

// ===================== 2) BUILD OUTPUT EPG =====================
$out = new DOMDocument('1.0', 'UTF-8');
$out->formatOutput = true;
$out->preserveWhiteSpace = false;

$outTvSrc = $xpath->query('/tv')->item(0);
if (!$outTvSrc) fail("ERROR: no encuentro el nodo <tv> en el XMLTV.");

$outTv = $out->importNode($outTvSrc, false); // copia atributos sin hijos
$out->appendChild($outTv);

// Copiar solo canales presentes en la M3U (tvg-id)
$keptChannels = 0;
foreach ($xpath->query('/tv/channel') as $ch) {
  $id = preg_replace('/\s+/', ' ', trim($ch->getAttribute('id')));
  if ($id !== '' && isset($wanted[$id])) {
    $outTv->appendChild($out->importNode($ch, true));
    $keptChannels++;
  }
}

if ($keptChannels < 1) {
  fail("ERROR: tras filtrar, no quedó ningún <channel>. ¿tvg-id coincide con channel id del XMLTV?");
}

// Copiar programas dentro de la ventana
$keptProgrammes = 0;
foreach ($xpath->query('/tv/programme') as $pr) {
  $chName = preg_replace('/\s+/', ' ', trim($pr->getAttribute('channel')));
  if ($chName === '' || !isset($wanted[$chName])) continue;

  $startAttr = trim($pr->getAttribute('start'));
  $stopAttr  = trim($pr->getAttribute('stop'));
  if ($startAttr === '' || $stopAttr === '') continue;

  $startNorm = preg_replace('/\s+/', '', $startAttr);
  $stopNorm  = preg_replace('/\s+/', '', $stopAttr);

  $dtStart = DateTime::createFromFormat('YmdHisO', $startNorm);
  $dtStop  = DateTime::createFromFormat('YmdHisO', $stopNorm);
  if (!$dtStart || !$dtStop) continue;

  $tsStart = $dtStart->getTimestamp();
  $tsStop  = $dtStop->getTimestamp();

  if ($tsStart < $end && $tsStop > $now) {
    $outTv->appendChild($out->importNode($pr, true));
    $keptProgrammes++;
  }
}

$xmlOut = $out->saveXML();
file_put_contents($epgTmp, $xmlOut);

// ===================== 3) VALIDATE + ATOMIC SWAP =====================
if (!file_exists($epgTmp) || filesize($epgTmp) < $MIN_EPG_BYTES || $keptProgrammes < $MIN_PROGRAMMES) {
  @unlink($epgTmp);
  fail("ERROR: epg.tmp pequeño o pocos programmes (kept=$keptProgrammes).");
}

if (filesize($epgTmp) > $MAX_EPG_BYTES) {
  @unlink($epgTmp);
  fail("ERROR: epg.tmp supera el máximo permitido. Baja EPG_HOURS o filtra más canales.");
}

atomic_replace($epgTmp, $epgFinal);

echo "OK\n";
echo "Channels kept: $keptChannels\n";
echo "Programmes kept: $keptProgrammes\n";
echo "EPG bytes: " . filesize($epgFinal) . "\n";
echo "Wanted tvg-id: " . count($wanted) . "\n";
