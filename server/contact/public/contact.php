<?php
// Contactformulier van darkesttimes.nl: controleert het bericht en verstuurt het via de Brevo-API.
// Caddy stuurt POST /api/contact hierheen. Sleutel en adressen staan in .env (zie compose.yml).

const MAX_PER_IP_PER_UUR = 5;
const MAX_PER_DAG = 50;          // ruim onder de 300/dag van het gratis Brevo-plan
const TELLER = '/app/data/teller.json';

$wilJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function antwoord(int $status, string $tekst): never {
    global $wilJson;
    http_response_code($status);
    if ($wilJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $status < 300, 'bericht' => $tekst]);
    } else {
        // Zonder JavaScript: een simpele pagina met een link terug
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>Contact</title><body style="font-family:system-ui;background:#000;color:#fff;padding:2rem">'
            . '<p>' . htmlspecialchars($tekst) . '</p><p><a href="/#contact" style="color:#b9a6ff">Terug naar de site</a></p>';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    antwoord(405, 'Alleen versturen via het formulier.');
}

$veld = fn(string $naam, int $max) => mb_substr(trim((string)($_POST[$naam] ?? '')), 0, $max);
$naam    = $veld('naam', 100);
$email   = $veld('email', 200);
$branche = $veld('branche', 100);
$bericht = $veld('bericht', 5000);

// Honeypot: dit veld is onzichtbaar voor mensen, alleen bots vullen het in. Doe alsof het gelukt is.
if ($veld('website', 200) !== '') {
    antwoord(200, 'Bedankt! Je bericht is verstuurd.');
}

if ($naam === '' || $bericht === '') {
    antwoord(422, 'Vul je naam en een bericht in.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    antwoord(422, 'Dat e-mailadres klopt niet helemaal.');
}
$naam = str_replace(["\r", "\n"], ' ', $naam);
$branche = str_replace(["\r", "\n"], ' ', $branche);

// Teller tegen misbruik: per IP-adres per uur, en voor de hele site per dag
$fp = fopen(TELLER, 'c+');
flock($fp, LOCK_EX);
$teller = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
$nu = time();
$ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$vandaag = date('Y-m-d');
if (($teller['dag'] ?? '') !== $vandaag) {
    $teller = ['dag' => $vandaag, 'aantal' => 0, 'ip' => []];
}
$teller['ip'][$ip] = array_values(array_filter($teller['ip'][$ip] ?? [], fn($t) => $t > $nu - 3600));
$teVeel = count($teller['ip'][$ip]) >= MAX_PER_IP_PER_UUR || $teller['aantal'] >= MAX_PER_DAG;
if (!$teVeel) {
    $teller['ip'][$ip][] = $nu;
    $teller['aantal']++;
}
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($teller));
flock($fp, LOCK_UN);
fclose($fp);
if ($teVeel) {
    antwoord(429, 'Er zijn net veel berichten verstuurd. Probeer het later nog eens, of mail naar ' . getenv('MAIL_TO') . '.');
}

$tekst = "Nieuw bericht via het contactformulier van darkesttimes.nl\n\n"
    . "Naam:    $naam\n"
    . "E-mail:  $email\n"
    . "Branche: " . ($branche !== '' ? $branche : '-') . "\n\n"
    . "$bericht\n";

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'api-key: ' . getenv('BREVO_API_KEY'),
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'sender'      => ['name' => 'Darkesttimes website', 'email' => getenv('MAIL_FROM')],
        'to'          => [['email' => getenv('MAIL_TO')]],
        'replyTo'     => ['email' => $email, 'name' => $naam],
        'subject'     => "Contactformulier: $naam",
        'textContent' => $tekst,
    ]),
]);
$resultaat = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

if ($status < 200 || $status >= 300) {
    // Komt in `docker compose logs` terecht; zonder de inhoud van het bericht
    error_log("Brevo gaf status $status: " . ($resultaat ?: curl_error($ch)));
    antwoord(502, 'Versturen lukte even niet. Probeer het later nog eens, of mail naar ' . getenv('MAIL_TO') . '.');
}

antwoord(200, 'Bedankt! Je bericht is verstuurd. Ik reageer zo snel mogelijk.');
