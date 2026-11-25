<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!headers_sent()) {
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
		'httponly' => true,
		'samesite' => 'Strict',
	]);
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

function adminConfigured(): bool
{
	$data = reviewReadArray(reviewAdminFile());
	return is_string($data['password_hash'] ?? null) && password_get_info($data['password_hash'])['algo'] !== null;
}

function writeAdminPassword(string $password): bool
{
	return reviewWriteArray(reviewAdminFile(), [
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
		'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
	]);
}

$status = '';
$csrf = ensureAdminCsrf();
$isLoggedIn = (($_SESSION['review_admin_logged_in'] ?? false) === true);

if ($isLoggedIn) {
	$loginTime = (int) ($_SESSION['review_admin_login_time'] ?? 0);
	if ($loginTime === 0 || time() - $loginTime > 1800) {
		$_SESSION['review_admin_logged_in'] = false;
		$isLoggedIn = false;
		unset($_SESSION['review_admin_login_time']);
		$status = 'Je sessie is verlopen. Meld je opnieuw aan.';
	}
}

if (!adminConfigured() && $_SERVER['REQUEST_METHOD'] === 'GET') {
	$isLoggedIn = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
	} elseif (postedAdminString('action') === 'setup') {
		$setupKey = reviewSetupKey();
		$postedKey = postedAdminString('setup_key');
		$password = postedAdminString('password');
		$passwordConfirmation = postedAdminString('password_confirmation');

		if (adminConfigured()) {
			$status = 'Het beheerwachtwoord is al ingesteld.';
		} elseif (!is_string($setupKey) || $setupKey === '' || !hash_equals($setupKey, $postedKey)) {
			http_response_code(403);
			$status = 'De eenmalige installatiesleutel is ongeldig.';
		} elseif (strlen($password) < 12 || $password !== $passwordConfirmation) {
			$status = 'Gebruik minimaal 12 tekens en voer hetzelfde wachtwoord twee keer in.';
		} elseif (!writeAdminPassword($password)) {
			http_response_code(503);
			$status = 'Het wachtwoord kon niet veilig worden opgeslagen.';
		} else {
			@unlink(reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'setup.key');
			$_SESSION['review_admin_logged_in'] = true;
			$_SESSION['review_admin_login_time'] = time();
			session_regenerate_id(true);
			$isLoggedIn = true;
			$status = 'Het beheerwachtwoord is ingesteld. Het installatiescherm is nu beveiligd en uitgeschakeld.';
		}
	} elseif (postedAdminString('action') === 'login') {
		$lockedUntil = (int) ($_SESSION['review_admin_locked_until'] ?? 0);
		if ($lockedUntil > time()) {
			http_response_code(429);
			$status = 'Te veel mislukte pogingen. Wacht een kwartier voordat je opnieuw probeert.';
		} else {
			$data = reviewReadArray(reviewAdminFile());
			$hash = (string) ($data['password_hash'] ?? '');
			if ($hash !== '' && password_verify(postedAdminString('password'), $hash)) {
				$_SESSION['review_admin_logged_in'] = true;
				$_SESSION['review_admin_login_time'] = time();
				session_regenerate_id(true);
				unset($_SESSION['review_admin_attempts'], $_SESSION['review_admin_locked_until']);
				$isLoggedIn = true;
				$status = 'Je bent aangemeld.';
			} else {
				$_SESSION['review_admin_attempts'] = (int) ($_SESSION['review_admin_attempts'] ?? 0) + 1;
				if ((int) $_SESSION['review_admin_attempts'] >= 5) {
					$_SESSION['review_admin_locked_until'] = time() + 900;
				}
				http_response_code(401);
				$status = 'Wachtwoord ongeldig.';
			}
		}
	} elseif ($isLoggedIn) {
		$action = postedAdminString('action');
		$id = postedAdminString('id');
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
	} else {
		http_response_code(401);
		$status = 'Je moet aangemeld zijn.';
	}

	$_SESSION['review_admin_csrf'] = bin2hex(random_bytes(32));
	$csrf = $_SESSION['review_admin_csrf'];
}

$reviews = $isLoggedIn ? reviewAll() : [];
$pendingCount = count(array_filter($reviews, static fn(array $review): bool => ($review['status'] ?? '') === 'pending'));
?>
<!doctype html>
<html lang="nl-BE">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
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

			<?php if (!$isLoggedIn): ?>
				<?php if (!adminConfigured()): ?>
					<p class="reviews-admin-intro">Stel het beheerwachtwoord éénmalig in met de installatiesleutel uit de serveromgeving. Na instellen wordt dit scherm permanent uitgeschakeld.</p>
					<form class="contact-form reviews-admin-form" method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<input type="hidden" name="action" value="setup">
						<label for="setup-key">Eenmalige installatiesleutel</label>
						<input id="setup-key" name="setup_key" type="password" autocomplete="off" required>
						<label for="setup-password">Nieuw beheerwachtwoord (minimaal 12 tekens)</label>
						<input id="setup-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
						<label for="setup-password-confirmation">Herhaal beheerwachtwoord</label>
						<input id="setup-password-confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
						<button class="home-button" type="submit">Wachtwoord instellen <span aria-hidden="true">›</span></button>
					</form>
				<?php else: ?>
					<form class="contact-form reviews-admin-form" method="post">
						<input type="hidden" name="csrf_token" value="<?= escapeAdmin($csrf) ?>">
						<input type="hidden" name="action" value="login">
						<label for="login-password">Beheerwachtwoord</label>
						<input id="login-password" name="password" type="password" autocomplete="current-password" required>
						<button class="home-button" type="submit">Aanmelden <span aria-hidden="true">›</span></button>
					</form>
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
