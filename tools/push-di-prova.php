<?php
/**
 * Push di prova verso Raffaello Scuola: crea un evento firmato come quelli del
 * plugin del Bookshop e stampa il comando curl per mandarlo.
 *
 * Uso, solo da riga di comando, con il segreto nell'ambiente e non negli argomenti:
 *   RCL_BOOKSHOP_SECRET=... php tools/push-di-prova.php --url=https://dev.raffaelloscuola.it --sku=SKU-PRODOTTO [opzioni]
 *   RCL_BOOKSHOP_SECRET=... php tools/push-di-prova.php --url=https://dev.raffaelloscuola.it --evento=ping [--sku=SKU1,SKU2]
 *
 * Il ping prova collegamento e firma senza scrivere niente: risponde con versione, ora e gli
 * SKU passati che non corrispondono a una licenza attiva (sku_sconosciuti).
 *
 * Opzioni:
 *   --sub=123                    sub di Identity del destinatario (senza: nessun destinatario)
 *   --evento=licenze_revocate    default licenze_assegnate; oppure ping
 *   --id=900001                  id della licenza di License Manager (default casuale)
 *   --chiave=RSCU-AB12-CD34      chiave della prima licenza (default casuale)
 *   --ordine=12345               id dell'ordine (default casuale)
 *   --giorni=365                 scadenza fra N giorni, 0 = illimitata (default 365)
 *   --quantita=2                 licenze nello stesso ordine, con id consecutivi
 *
 * Il corpo va in push-di-prova.json nella cartella corrente; la firma vale 5 minuti.
 * Per ripetere lo stesso push riusa --id, --chiave e --ordine.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$opt = getopt('', ['url:', 'sku:', 'sub:', 'evento:', 'id:', 'chiave:', 'ordine:', 'giorni:', 'quantita:']);
$secret = (string) getenv('RCL_BOOKSHOP_SECRET');
$evento = $opt['evento'] ?? 'licenze_assegnate';
if (empty($opt['url']) || ($evento !== 'ping' && empty($opt['sku'])) || $secret === '') {
    fwrite(STDERR, "Uso: RCL_BOOKSHOP_SECRET=... php tools/push-di-prova.php --url=https://dev.raffaelloscuola.it --sku=SKU [--sub=123] [--evento=licenze_revocate] [--id=N] [--chiave=RSCU-XXXX-XXXX] [--ordine=N] [--giorni=365] [--quantita=1]\n"
        . "     RCL_BOOKSHOP_SECRET=... php tools/push-di-prova.php --url=https://dev.raffaelloscuola.it --evento=ping [--sku=SKU1,SKU2]\n");
    exit(1);
}

if ($evento === 'ping') {
    $body = json_encode(['evento' => 'ping', 'skus' => isset($opt['sku']) ? explode(',', $opt['sku']) : []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents('push-di-prova.json', $body);
    fwrite(STDERR, "Ping, corpo in push-di-prova.json; la firma vale 5 minuti:\n\n");
    echo curl_command($opt['url'], $body, $secret);
    exit(0);
}

$alfabeto = 'ABCDEFGHLMNPQRSTUVZ123456789';
$chiave_casuale = function () use ($alfabeto) {
    $blocco = function () use ($alfabeto) {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $s;
    };
    return 'RSCU-' . $blocco() . '-' . $blocco();
};

$id = isset($opt['id']) ? (int) $opt['id'] : random_int(900000, 999999);
$ordine = isset($opt['ordine']) ? (int) $opt['ordine'] : random_int(900000, 999999);
$giorni = isset($opt['giorni']) ? (int) $opt['giorni'] : 365;
$quantita = max(1, isset($opt['quantita']) ? (int) $opt['quantita'] : 1);

$licenze = [];
for ($i = 0; $i < $quantita; $i++) {
    $licenze[] = [
        'id'          => $id + $i,
        'chiave'      => $i === 0 && isset($opt['chiave']) ? $opt['chiave'] : $chiave_casuale(),
        'riga_ordine' => 1,
        'prodotto'    => ['id' => 1, 'sku' => $opt['sku'], 'nome' => 'Prodotto di prova'],
        'scade_il'    => $giorni > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $giorni * 86400) : null,
    ];
}

$body = json_encode([
    'evento'  => $evento,
    'ordine'  => ['id' => $ordine, 'numero' => (string) $ordine, 'stato' => 'test'],
    'cliente' => ['sub' => isset($opt['sub']) ? (string) $opt['sub'] : null, 'email' => 'prova@example.test'],
    'licenze' => $licenze,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents('push-di-prova.json', $body);

fwrite(STDERR, 'Ordine ' . $ordine . ', licenze ' . implode(', ', array_map(function ($l) {
    return $l['id'] . ' ' . $l['chiave'];
}, $licenze)) . "\nCorpo in push-di-prova.json; la firma vale 5 minuti:\n\n");
echo curl_command($opt['url'], $body, $secret);

/** Comando curl con la firma di adesso: HMAC-SHA256 di timestamp + "." + corpo. */
function curl_command(string $url, string $body, string $secret): string
{
    $timestamp = (string) time();
    return "curl -sS -X POST '" . rtrim($url, '/') . "/wp-json/raffaello-licenze/v1/eventi'"
        . " -H 'Content-Type: application/json'"
        . " -H 'X-Raffaello-Timestamp: " . $timestamp . "'"
        . " -H 'X-Raffaello-Signature: sha256=" . hash_hmac('sha256', $timestamp . '.' . $body, $secret) . "'"
        . " --data-binary '@push-di-prova.json'\n";
}
