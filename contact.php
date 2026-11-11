<?php
declare(strict_types=1);

session_start();

$types = [
    'rally' => [
        'title' => 'Vraag over onze rally’s',
        'subject' => 'Informatie over volgende rally’s',
        'message' => 'Wij zouden graag deelnemen aan een van jullie komende rally’s. Zouden jullie ons meer informatie kunnen bezorgen over de geplande edities, het programma en de inschrijvingsmogelijkheden?' . "\n\n" .
            'We kijken ernaar uit om samen met jullie op pad te gaan.' . "\n\n" .
            'Alvast hartelijk dank.',
        'greeting' => 'Beste Cindy en Bart,',
    ],
    'sponsor' => [
        'title' => 'Aanvraag sponsordossier',
        'subject' => 'Aanvraag sponsordossier',
        'message' => 'Wij hebben interesse om met ons bedrijf partner te worden van Legends on the Road.' . "\n\n" .
            'Graag ontvangen we jullie sponsordossier, zodat we de verschillende mogelijkheden kunnen bespreken.' . "\n\n" .
            'Alvast bedankt.',
        'greeting' => 'Beste Bart en Cindy,',
    ],
];

$type = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['type'] ?? '')
    : (string) ($_GET['type'] ?? '');
$formType = $types[$type] ?? null;
$status = '';
$sent = false;
$name = '';
$email = '';
$phone = '';

if ($formType === null) {
    http_response_code(404);
    $status = 'Deze contactaanvraag is niet gevonden. Kies een van de contactknoppen op de startpagina.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $website = trim((string) ($_POST['website'] ?? ''));

    if ($website !== '') {
        http_response_code(400);
        $status = 'Je aanvraag kon niet worden verwerkt. Probeer het opnieuw.';
    } elseif (isset($_SESSION['last_contact_submission']) && time() - (int) $_SESSION['last_contact_submission'] < 30) {
        http_response_code(429);
        $status = 'Je hebt net een aanvraag verstuurd. Wacht even voordat je opnieuw probeert.';
    } elseif ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($phone) > 40) {
        http_response_code(422);
        $status = 'Vul een geldige naam en e-mailadres in. Het telefoonnummer is optioneel.';
    } else {
        $_SESSION['last_contact_submission'] = time();
        $subject = str_replace('’', "'", $formType['subject']) . ' - Legends on the Road';
        $body = $formType['greeting'] . "\r\n\r\n" .
            $formType['message'] . "\r\n\r\n" .
            'Met vriendelijke groeten,' . "\r\n\r\n" .
            $name . ($phone !== '' ? "\r\n" . $phone : '') . "\r\n" .
            'E-mailadres: ' . $email;
        $headers = [
            'From: Legends on the Road <info@legendsontheroad.be>',
            'Reply-To: ' . $email,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (mail('info@legendsontheroad.be', $subject, $body, implode("\r\n", $headers))) {
            $status = 'Bedankt voor je bericht. Je aanvraag is verstuurd; we nemen zo snel mogelijk contact met je op.';
            $sent = true;
            $name = '';
            $email = '';
            $phone = '';
        } else {
            error_log('Contact form email could not be sent.');
            http_response_code(503);
            $status = 'Je bericht kon niet worden verstuurd. Probeer het later opnieuw of mail naar info@legendsontheroad.be.';
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Neem contact op met Legends on the Road.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <title><?= escape($formType['title'] ?? 'Contact') ?> | Legends on the Road</title>
</head>
<body class="home-page contact-form-page">
    <header class="home-header">
        <a class="home-brand" href="index.html">
            <span>Legends on the Road</span>
            <small>Drive – Connect – Remember</small>
        </a>
        <a class="home-contact-link" href="index.html">Terug naar startpagina</a>
    </header>
    <main>
        <section class="contact-form-section" aria-labelledby="contact-form-title">
            <p class="home-eyebrow">We horen graag van je</p>
            <h1 id="contact-form-title"><?= escape($formType['title'] ?? 'Contact') ?></h1>
            <?php if ($status !== ''): ?>
                <p class="contact-form-status" role="status"><?= escape($status) ?></p>
            <?php endif; ?>
            <?php if ($formType !== null && !$sent): ?>
                <form class="contact-form" method="post" action="contact.php">
                    <input type="hidden" name="type" value="<?= escape($type) ?>">
                    <div class="contact-form-trap" aria-hidden="true">
                        <label for="website">Laat dit veld leeg</label>
                        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                    </div>
                    <label for="name">Naam <span aria-hidden="true">*</span></label>
                    <input id="name" name="name" type="text" maxlength="120" autocomplete="name" value="<?= escape($name) ?>" required>
                    <label for="email">E-mailadres <span aria-hidden="true">*</span></label>
                    <input id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= escape($email) ?>" required>
                    <label for="phone">Telefoonnummer <span class="contact-form-optional">(optioneel)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= escape($phone) ?>">
                    <div class="contact-form-preview">
                        <p><?= escape($formType['greeting']) ?></p>
                        <p><?= nl2br(escape($formType['message'])) ?></p>
                        <p>Met vriendelijke groeten,<br>Je naam en telefoonnummer</p>
                    </div>
                    <button class="home-button" type="submit">Verstuur je aanvraag <span aria-hidden="true">›</span></button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <footer class="home-footer">
        <a class="home-footer-brand" href="index.html">Legends on the Road</a>
        <a href="mailto:info@legendsontheroad.be">info@legendsontheroad.be</a>
        <span>© 2026 Legends on the Road</span>
    </footer>
</body>
</html>
