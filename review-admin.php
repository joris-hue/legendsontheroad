<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$isLoopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$secureTransport = $isHttps || $isLoopback;
if (!headers_sent()) {
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
		'httponly' => true,
		'samesite' => 'Strict',
	]);
	header('Cache-Control: no-store, private');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: no-referrer');
}
session_start();

require_once __DIR__ . '/review_store.php';

function escapeAdmin(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postedAdminString(string $key): string
{
	$value = $_POST[$key] ?? '';
	return is_string($value) ? $value : '';
}

function ensureAdminCsrf(): string
{
	if (!isset($_SESSION['review_admin_csrf'])) {
		$_SESSION['review_admin_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['review_admin_csrf'];
}

function adminConfigured(array $admin): bool
{
	$hash = $admin['password_hash'] ?? null;
	return is_string($hash) && password_get_info($hash)['algo'] !== null;
}

function adminChallengeForSession(array $admin): ?array
{
	$challenge = $admin['challenge'] ?? null;
	if (
		!is_array($challenge) ||
		(int) ($challenge['expires_at'] ?? 0) <= time() ||
		!hash_equals((string) ($challenge['session_hash'] ?? ''), hash('sha256', session_id()))
	) {
		return null;
	}
	return $challenge;
}

$status = '';
$csrf = ensureAdminCsrf();
$storageAvailable = false;
$admin = [];
$reviews = [];

if (!$secureTransport) {
	http_response_code(503);
	$status = 'Beheer is alleen beschikbaar via een beveiligde HTTPS-verbinding.';
} else {
	try {
		reviewStorageDirectory();
		$admin = reviewGetAdmin();
		$storageAvailable = true;
	} catch (RuntimeException $exception) {
		error_log('Private review storage is unavailable: ' . $exception->getMessage());
		http_response_code(503);
		$status = 'Beheer is uitgeschakeld omdat er geen veilige opslag buiten de publieke website beschikbaar is. Neem contact op met de hostingbeheerder.';
	}
}

$configured = $storageAvailable && adminConfigured($admin);
$isLoggedIn = $configured &&
	(($_SESSION['review_admin_logged_in'] ?? false) === true) &&
	is_string($admin['session_version'] ?? null) &&
	hash_equals((string) $admin['session_version'], (string) ($_SESSION['review_admin_session_version'] ?? ''));

if ($isLoggedIn) {
	$loginTime = (int) ($_SESSION['review_admin_login_time'] ?? 0);
	if ($loginTime === 0 || time() - $loginTime > 1800) {
		$_SESSION['review_admin_logged_in'] = false;
		$isLoggedIn = false;
		unset($_SESSION['review_admin_login_time']);
		$status = 'Je sessie is verlopen. Meld je opnieuw aan.';
	}
}

if ($storageAvailable && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedToken = postedAdminString('csrf_token');
	if (!hash_equals($csrf, $postedToken)) {
		http_response_code(403);
		$status = 'De beveiligingscontrole is mislukt. Vernieuw de pagina.';
	} elseif (isset($_POST['logout'])) {
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
		}
		session_destroy();
		header('Location: review-admin.php');
		exit;
	} elseif (postedAdminString('action') === 'request_code' && !$isLoggedIn) {
		try {
			$result = reviewRequestAdminCode(session_id());
			if ($result === 'sent') {
				$status = 'Als de e-mail bezorgd wordt, staat de eenmalige code in de inbox van info@legendsontheroad.be. De code is 10 minuten geldig.';
			} elseif ($result === 'limited') {
				http_response_code(429);
				$status = 'Aanvragen zijn beperkt tot één code per minuut, drie per IP per uur en tien totaal per uur. Probeer het later opnieuw.';
			} else {
				http_response_code(503);
				$status = 'De verificatie-e-mail kon niet worden verstuurd. Probeer het later opnieuw.';
			}
		} catch (RuntimeException $exception) {
			error_log('Admin verification request failed: ' . $exception->getMessage());
			http_response_code(503);
			$status = 'Verificatie kon niet veilig worden verwerkt. Beheer is uitgeschakeld.';
		}
	} elseif (postedAdminString('action') === 'verify_code' && !$isLoggedIn) {
		$password = postedAdminString('password');
		$passwordConfirmation = postedAdminString('password_confirmation');
		if (strlen($password) < 14 || $password !== $passwordConfirmation) {
			http_response_code(422);
			$status = 'Kies een wachtwoord van minimaal 14 tekens en bevestig het correct.';
		} else {
			try {
				$result = reviewCompleteAdminCode(session_id(), postedAdminString('verification_code'), $password);
				if ($result === 'setup_complete' || $result === 'password_reset') {
					session_regenerate_id(true);
					$_SESSION['review_admin_logged_in'] = true;
					$_SESSION['review_admin_login_time'] = time();
					$isLoggedIn = true;
					$admin = reviewGetAdmin();
					$_SESSION['review_admin_session_version'] = (string) ($admin['session_version'] ?? '');
					$configured = true;
					$status = $result === 'setup_complete'
						? 'Je beheerderswachtwoord is ingesteld.'
						: 'Je wachtwoord is opnieuw ingesteld.';
				} elseif ($result === 'wrong_code') {
					http_response_code(401);
					$status = 'De code klopt niet. Controleer de e-mail en probeer opnieuw.';
				} elseif ($result === 'expired') {
					http_response_code(410);
					$status = 'De code is verlopen. Vraag een nieuwe verificatiecode aan.';
				} elseif ($result === 'attempts_exceeded') {
					http_response_code(429);
					$status = 'Het maximumaantal pogingen is bereikt. Vraag later een nieuwe code aan.';
				} elseif ($result === 'weak_password') {
					http_response_code(422);
					$status = 'Kies een wachtwoord van minimaal 14 tekens.';
				} else {
					http_response_code(400);
					$status = 'Deze verificatiecode is ongeldig of hoort niet bij deze sessie. Vraag een nieuwe code aan.';
				}
			} catch (RuntimeException $exception) {
				error_log('Admin verification failed: ' . $exception->getMessage());
				http_response_code(503);
				$status = 'Verificatie kon niet veilig worden opgeslagen. Beheer is uitgeschakeld.';
			}
		}
	} elseif (postedAdminString('action') === 'login' && $configured && !$isLoggedIn) {
		try {
			$result = reviewCheckLogin(postedAdminString('password'));
			if ($result === 'success') {
				session_regenerate_id(true);
				$_SESSION['review_admin_logged_in'] = true;
				$_SESSION['review_admin_login_time'] = time();
				$admin = reviewGetAdmin();
				$_SESSION['review_admin_session_version'] = (string) ($admin['session_version'] ?? '');
				$isLoggedIn = true;
				$status = 'Je bent aangemeld.';
			} elseif ($result === 'locked') {
				http_response_code(429);
				$status = 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.';
			} else {
				http_response_code(401);
				$status = 'Wachtwoord ongeldig.';
			}
		} catch (RuntimeException $exception) {
			error_log('Admin login failed: ' . $exception->getMessage());
			http_response_code(503);
			$status = 'Aanmelden kon niet veilig worden gecontroleerd. Beheer is uitgeschakeld.';
		}
	} elseif ($isLoggedIn) {
		$action = postedAdminString('action');
		$id = postedAdminString('id');
		try {
			if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
				http_response_code(400);
				$status = 'Ongeldige review.';
			} elseif ($action === 'approve' && reviewSetStatus($id, 'approved')) {
				$status = 'De review is goedgekeurd en wordt nu op de website getoond.';
			} elseif ($action === 'reject' && reviewSetStatus($id, 'rejected')) {
				$status = 'De review is geweigerd.';
			} elseif ($action === 'delete' && reviewDelete($id)) {
				$status = 'De review is definitief verwijderd.';
			} else {
				http_response_code(400);
				$status = 'De actie kon niet worden uitgevoerd.';
			}
		} catch (RuntimeException $exception) {
			error_log('Review administration action failed: ' . $exception->getMessage());
			http_response_code(503);
			$status = 'De wijziging kon niet veilig worden opgeslagen.';
		}
	} else {
		http_response_code(401);
		$status = 'Meld je aan of vraag een e-mailverificatiecode aan.';
	}

	$_SESSION['review_admin_csrf'] = bin2hex(random_bytes(32));
	$csrf = $_SESSION['review_admin_csrf'];
	try {
		$admin = reviewGetAdmin();
		$configured = adminConfigured($admin);
	} catch (RuntimeException $exception) {
		$storageAvailable = false;
		$isLoggedIn = false;
		http_response_code(503);
		$status = 'Veilige opslag is niet beschikbaar. Beheer is uitgeschakeld.';
	}
}

if ($storageAvailable && $isLoggedIn) {
	try {
		$reviews = reviewAll();
	} catch (RuntimeException $exception) {
		$storageAvailable = false;
		$isLoggedIn = false;
		http_response_code(503);
		$status = 'Reviews konden niet veilig worden gelezen. Beheer is uitgeschakeld.';
		error_log('Reviews could not be read: ' . $exception->getMessage());
	}
}

$challenge = $storageAvailable && !$isLoggedIn ? adminChallengeForSession($admin) : null;
$pendingCount = count(array_filter($reviews, static fn(array $review): bool => ($review['status'] ?? '') === 'pending'));
?>
<!doctype html>
<html lang="nl-BE">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="css/style.css">
	<title>Reviewbeheer | Legends on the Road</title>
</head>
<body class="home-page reviews-admin-page">
	<header class="home-header">
		<a class="home-brand" href="index.html">
			<span>Legends on the Road</span>
			<small>Drive – Connect – Remember</small>
		</a>
		<nav class="home-navigation" aria-label="Beheernavigatie">
			<a href="reviews.php">Bekijk reviews</a>
			<a href="index.html">Startpagina</a>
		</nav>
	</header>
	<main>
		<section class="reviews-admin" aria-labelledby="admin-title">
			<p class="home-eyebrow">Beheer</p>
			<h1 id="admin-title"><?= $isLoggedIn ? 'Reviewoverzicht' : 'Beveiligde toegang' ?></h1>
			<?php if ($status !== ''): ?>
				<p class="contact-form-status" role="status"><?= escapeAdmin($status) ?></p>
			<?php endif; ?>

			<?php if (!$secureTransport): ?>
				<p class="reviews-admin-intro">Open deze beheerpagina via HTTPS. Aanmeld- en verificatieformulieren worden via een onbeveiligde verbinding niet getoond.</p>
			<?php elseif (!$storageAvailable): ?>
				<p class="reviews-admin-intro">Er zijn geen installatie- of wachtwoordformulieren actief. De opslag moet eerst veilig beschikbaar zijn buiten de publieke webmap.</p>
			<?php elseif (!$isLoggedIn): ?>
				<?php if (!$configured): ?>
					<p class="reviews-admin-intro">We sturen een eenmalige code naar info@legendsontheroad.be om te controleren dat jij de mailbox beheert. De code verloopt na 10 minuten.</p>
				<?php endif; ?>

				<?php if ($challenge !== null): ?>
					<form class="contact-form reviews-admin-form" method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<input type="hidden" name="action" value="verify_code">
						<label for="verification-code">Eenmalige code uit de e-mail</label>
						<input id="verification-code" name="verification_code" type="text" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="one-time-code" required>
						<label for="new-password"><?= $configured ? 'Nieuw wachtwoord (minimaal 14 tekens)' : 'Beheerderswachtwoord (minimaal 14 tekens)' ?></label>
						<input id="new-password" name="password" type="password" minlength="14" autocomplete="new-password" required>
						<label for="password-confirmation">Herhaal wachtwoord</label>
						<input id="password-confirmation" name="password_confirmation" type="password" minlength="14" autocomplete="new-password" required>
						<button class="home-button" type="submit"><?= $configured ? 'Wachtwoord herstellen' : 'Wachtwoord instellen' ?> <span aria-hidden="true">›</span></button>
					</form>
					<p class="review-guidance">Een code kan maximaal vijf keer worden geprobeerd. Vraag niet herhaaldelijk nieuwe codes aan.</p>
				<?php elseif (!$configured): ?>
					<form class="contact-form reviews-admin-form" method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<input type="hidden" name="action" value="request_code">
						<button class="home-button" type="submit">Stuur verificatiecode <span aria-hidden="true">›</span></button>
					</form>
				<?php else: ?>
					<form class="contact-form reviews-admin-form" method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<input type="hidden" name="action" value="login">
						<label for="login-password">Beheerwachtwoord</label>
						<input id="login-password" name="password" type="password" autocomplete="current-password" required>
						<button class="home-button" type="submit">Aanmelden <span aria-hidden="true">›</span></button>
					</form>
					<details class="reviews-password-reset">
						<summary>Wachtwoord vergeten?</summary>
						<p class="reviews-admin-intro">Een herstelcode wordt naar info@legendsontheroad.be gestuurd.</p>
						<form class="contact-form reviews-admin-form" method="post">
							<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
							<input type="hidden" name="action" value="request_code">
							<button class="admin-action" type="submit">Stuur herstelcode</button>
						</form>
					</details>
				<?php endif; ?>
			<?php else: ?>
				<div class="reviews-admin-toolbar">
					<p><strong><?= count($reviews) ?></strong> reviews · <strong><?= $pendingCount ?></strong> in afwachting</p>
					<form method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<button class="admin-link-button" type="submit" name="logout" value="1">Afmelden</button>
					</form>
				</div>
				<?php if ($reviews === []): ?>
					<p class="reviews-admin-empty">Er zijn nog geen inzendingen.</p>
				<?php else: ?>
					<div class="review-admin-list">
						<?php foreach ($reviews as $review): ?>
							<article class="review-admin-card" data-status="<?= escapeAdmin((string) $review['status']) ?>">
								<div class="review-admin-card-heading">
									<div>
										<span class="review-admin-status status-<?= escapeAdmin((string) $review['status']) ?>"><?= escapeAdmin((string) $review['status']) ?></span>
										<h2><?= escapeAdmin((string) $review['name']) ?></h2>
									</div>
									<div class="review-admin-stars" aria-label="<?= (int) $review['rating'] ?> van 5 sterren">
										<?php for ($star = 1; $star <= 5; $star++): ?>
											<span<?= $star <= (int) $review['rating'] ? ' class="is-filled"' : '' ?>>★</span>
										<?php endfor; ?>
									</div>
								</div>
								<p class="review-admin-text"><?= nl2br(escapeAdmin((string) $review['text'])) ?></p>
								<p class="review-admin-date"><?= escapeAdmin((string) $review['created_at']) ?></p>
								<div class="review-admin-actions">
									<?php if (($review['status'] ?? '') !== 'approved'): ?>
										<form method="post">
											<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
											<input type="hidden" name="action" value="approve">
											<input type="hidden" name="id" value="<?= escapeAdmin((string) $review['id']) ?>">
											<button class="admin-action approve" type="submit">Goedkeuren</button>
										</form>
									<?php endif; ?>
									<?php if (($review['status'] ?? '') !== 'rejected'): ?>
										<form method="post">
											<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
											<input type="hidden" name="action" value="reject">
											<input type="hidden" name="id" value="<?= escapeAdmin((string) $review['id']) ?>">
											<button class="admin-action reject" type="submit">Weigeren</button>
										</form>
									<?php endif; ?>
									<form method="post" onsubmit="return confirm('Deze review definitief verwijderen?');">
										<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
										<input type="hidden" name="action" value="delete">
										<input type="hidden" name="id" value="<?= escapeAdmin((string) $review['id']) ?>">
										<button class="admin-action delete" type="submit">Verwijderen</button>
									</form>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	</main>
	<footer class="home-footer">
		<a class="home-footer-brand" href="index.html">Legends on the Road</a>
		<span>© 2026 Legends on the Road</span>
	</footer>
</body>
</html>
