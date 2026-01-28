<?php
// ====== CONFIG ======
$EPG_SRC = 'https://raw.githubusercontent.com/davidmuma/EPG_dobleM/master/guiatv.xml';

// La URL playlist
$PLAYLIST_SRC = getenv('PLAYLIST_SRC') ?: 'https://ipfs.io/ipns/k2k4r8oqlcjxsritt5mczkcn4mmvcmymbqw7113fz2flkrerfwfps004/data/listas/lista_iptv.m3u';

// Reescritura ruta
$OLD_BASE = 'http://127.0.0.1:6878/';
$NEW_BASE = 'http://10.0.0.242:8080/';

// Ventana EPG
$HOURS = intval(getenv('EPG_HOURS') ?: '48');

// ====== OUTPUT PATHS ======
$docsDir = __DIR__ . '/../docs';
@mkdir($docsDir, 0777, true);

$epgOut = $docsDir . '/epg.xml';
$m3uOut = $docsDir . '/lista.m3u';

// ====== 1) BUILD EPG (48h) ======
$xmlStr = file_get_contents($EPG_SRC);
if ($xmlStr === false) { fwrite(STDERR, "No se pudo descargar EPG\n"); exit(1); }

$now = time();
$end = $now + $HOURS * 3600;

$dom = new DOMDocument();
$dom->loadXML($xmlStr, LIBXML_COMPACT | LIBXML_PARSEHUGE);
$xpath = new DOMXPath($dom);

$out = new DOMDocument('1.0', 'UTF-8');
$outTv = $out->createElement('tv');
$out->appendChild($outTv);

// Copia TODOS los canales
foreach ($xpath->query('/tv/channel') as $ch) {
  $outTv->appendChild($out->importNode($ch, true));
}

// Copia programas dentro de la ventana
foreach ($xpath->query('/tv/programme') as $pr) {
  $start = $pr->getAttribute('start'); // ej: 20260126213000 +0100
  if (!$start) continue;
  $s = substr($start, 0, 14);
  $dt = DateTime::createFromFormat('YmdHis', $s, new DateTimeZone('UTC'));
  if (!$dt) continue;
  $ts = $dt->getTimestamp();
  if ($ts >= $now && $ts <= $end) {
    $outTv->appendChild($out->importNode($pr, true));
  }
}

file_put_contents($epgOut, $out->saveXML());

// ====== 2) BUILD M3U with x-tvg-url ======
$m3u = file_get_contents($PLAYLIST_SRC);
if ($m3u === false) { fwrite(STDERR, "No se pudo descargar M3U\n"); exit(1); }

// Poner cabecera correcta para SS IPTV
$pagesBase = getenv('PAGES_BASE') ?: 'https://TUUSUARIO.github.io/TUREPO';
$epgUrl = rtrim($pagesBase, '/') . '/epg.xml';

if (preg_match('/^\s*#EXTM3U.*$/m', $m3u)) {
  $m3u = preg_replace('/^\s*#EXTM3U.*$/m', '#EXTM3U x-tvg-url="'.$epgUrl.'"', $m3u, 1);
} else {
  $m3u = '#EXTM3U x-tvg-url="'.$epgUrl.'"' . "\n" . $m3u;
}

// Reescribir URLs AceStream si quieres
$m3u = str_replace($OLD_BASE, $NEW_BASE, $m3u);

file_put_contents($m3uOut, $m3u);

echo "OK\n";
echo "EPG bytes: ".filesize($epgOut)."\n";
echo "M3U bytes: ".filesize($m3uOut)."\n";
