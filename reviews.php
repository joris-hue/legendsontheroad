<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/review_store.php';

if (!isset($_SESSION['review_csrf_token'])) {
	$_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
}

$status = '';
$sent = false;
$name = '';
$rating = '';
$review = '';
$consent = false;
$storageAvailable = false;
$approvedReviews = [];

try {
	reviewStorageDirectory();
	$approvedReviews = reviewApproved();
	$storageAvailable = true;
} catch (RuntimeException $exception) {
	http_response_code(503);
	$status = 'Reviews zijn tijdelijk niet beschikbaar omdat veilige serveropslag ontbreekt.';
	error_log('Private review storage is unavailable: ' . $exception->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$name = trim(postedString('name'));
	$rating = postedString('rating');
	$review = trim(str_replace("\r", '', postedString('review')));
	$website = trim(postedString('website'));
	$consent = postedString('consent') === '1';
	$postedToken = postedString('csrf_token');
	$tokenIsValid = hash_equals($_SESSION['review_csrf_token'], $postedToken);
	$nameLength = preg_match_all('/./us', $name);
	$reviewLength = preg_match_all('/./us', $review);

	if (!$storageAvailable) {
		http_response_code(503);
		$status = 'Je review kan niet worden verstuurd omdat veilige serveropslag ontbreekt.';
	} elseif (!$tokenIsValid) {
		http_response_code(400);
		$status = 'Je formulier is verlopen. Vernieuw de pagina en probeer het opnieuw.';
	} elseif ($website !== '') {
		http_response_code(400);
		$status = 'Je review kon niet worden verwerkt. Probeer het opnieuw.';
	} elseif (isset($_SESSION['last_review_submission']) && time() - (int) $_SESSION['last_review_submission'] < 30) {
		http_response_code(429);
		$status = 'Je hebt net een review verstuurd. Wacht even voordat je opnieuw probeert.';
	} elseif (
		$name === '' ||
		$nameLength === false ||
		$nameLength > 80 ||
		preg_match('/[\r\n\x00-\x1F\x7F]/', $name) === 1 ||
		!in_array($rating, ['1', '2', '3', '4', '5'], true) ||
		$reviewLength === false ||
		$reviewLength < 20 ||
		$reviewLength > 2000 ||
		strlen($review) > 8000 ||
		!$consent
	) {
		http_response_code(422);
		$status = 'Controleer je naam, kies een score van 1 tot 5 sterren, schrijf een review van minstens 20 tekens en geef toestemming voor publicatie na goedkeuring.';
	} else {
		$stored = false;
		try {
			reviewAdd($name, (int) $rating, $review);
			$stored = true;
		} catch (RuntimeException $exception) {
			error_log('Review could not be stored.');
			http_response_code(503);
			$status = 'Je review kon niet veilig worden opgeslagen. Probeer het later opnieuw.';
		}

		if ($stored) {
			$body = "Er is een nieuwe review ingezonden voor Legends on the Road.\r\n\r\n" .
				'Naam: ' . $name . "\r\n" .
				'Score: ' . $rating . " / 5 sterren\r\n\r\n" .
				"Review:\r\n" . str_replace("\n", "\r\n", $review);
			try {
				$mailSent = reviewSendEmail(
					'info@legendsontheroad.be',
					'Nieuwe review ontvangen - Legends on the Road',
					$body
				);
			} catch (RuntimeException $exception) {
				error_log('Review notification failed: ' . $exception->getMessage());
				$mailSent = false;
			}

			if ($mailSent) {
				$_SESSION['last_review_submission'] = time();
				$status = 'Bedankt voor je review! We beoordelen hem eerst. Na goedkeuring kunnen we hem met je naam op deze pagina delen.';
				$sent = true;
			} else {
				error_log('Review form email could not be sent, but review was stored.');
				$_SESSION['last_review_submission'] = time();
				$status = 'Je review is veilig opgeslagen en wordt beoordeeld. De e-mailmelding kon momenteel niet worden verstuurd.';
			}
			$name = '';
			$rating = '';
			$review = '';
			$consent = false;
		}
	}

	if ($tokenIsValid) {
		$_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
	}
}

function escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postedString(string $key): string
{
	$value = $_POST[$key] ?? '';
	return is_string($value) ? $value : '';
}
?>
<!doctype html>
<html lang="nl-BE">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="Deel je ervaring met Legends on the Road. Reviews worden eerst goedgekeurd voordat ze op de website verschijnen.">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="css/style.css">
	<title>Deel je ervaring | Legends on the Road</title>
</head>
<body class="home-page reviews-page">
	<header class="home-header">
		<a class="home-brand" href="index.html" aria-label="Legends on the Road startpagina">
			<span>Legends on the Road</span>
			<small>Drive – Connect – Remember</small>
		</a>
		<nav class="home-navigation" aria-label="Hoofdnavigatie">
			<a href="index.html#verhaal">Ons verhaal</a>
			<a href="vorige-rallys.html">Mooie herinneringen</a>
			<a href="reviews.php" aria-current="page">Reviews</a>
			<a class="home-contact-link" href="index.html#contact">Contact <span aria-hidden="true">›</span></a>
		</nav>
	</header>

	<main>
		<section class="contact-form-section reviews-section" aria-labelledby="reviews-title">
			<p class="home-eyebrow">Legends on the Road</p>
			<h1 id="reviews-title">Deel jouw ervaring</h1>
			<p class="reviews-intro">Elke rondrit vertelt een verhaal. We zijn benieuwd naar dat van jou.</p>
			<?php if ($status !== ''): ?>
				<p class="contact-form-status" role="status"><?= escape($status) ?></p>
			<?php endif; ?>
			<?php if (!$storageAvailable): ?>
				<p class="reviews-intro">Reviewinzendingen zijn tijdelijk uitgeschakeld.</p>
			<?php else: ?>
			<form class="contact-form review-form" id="review-form" method="post" action="reviews.php#review-form">
				<input type="hidden" name="csrf_token" value="<?= escape($_SESSION['review_csrf_token']) ?>">
				<div class="contact-form-trap" aria-hidden="true">
					<label for="website">Laat dit veld leeg</label>
					<input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
				</div>
				<label for="name">Naam of voornaam</label>
				<input id="name" name="name" type="text" maxlength="80" autocomplete="name" value="<?= escape($name) ?>" required>

				<fieldset class="review-rating">
					<legend>Geef jouw beoordeling</legend>
					<div class="review-stars">
						<?php for ($score = 1; $score <= 5; $score++): ?>
							<label>
								<input type="radio" name="rating" value="<?= $score ?>" aria-label="<?= $score ?> <?= $score === 1 ? 'ster' : 'sterren' ?>"<?= $rating === (string) $score ? ' checked' : '' ?> required>
								<span aria-hidden="true">★</span>
								<span class="visually-hidden"><?= $score ?> <?= $score === 1 ? 'ster' : 'sterren' ?></span>
							</label>
						<?php endfor; ?>
					</div>
				</fieldset>

				<label for="review">Hoe heb jij onze rondrit beleefd?</label>
				<textarea id="review" name="review" rows="7" minlength="20" maxlength="2000" placeholder="Jouw verhaal" required><?= escape($review) ?></textarea>
				<p class="review-guidance">Schrijf minstens 20 tekens. Deel geen gevoelige persoonlijke gegevens.</p>

				<label class="review-consent">
					<input type="checkbox" name="consent" value="1"<?= $consent ? ' checked' : '' ?> required>
					<span>Ik geef toestemming om mijn review na goedkeuring te publiceren.</span>
				</label>

				<button class="home-button" type="submit">Deel mijn ervaring <span aria-hidden="true">→</span></button>
			</form>
			<?php endif; ?>
		</section>

		<section class="approved-reviews" aria-labelledby="approved-reviews-title">
			<div>
				<p class="home-eyebrow">Woorden van onze deelnemers</p>
				<h2 id="approved-reviews-title">Samen onderweg, samen genieten.</h2>
				<?php if ($approvedReviews === []): ?>
					<p>Reviews verschijnen hier nadat ze zijn goedgekeurd. Bedankt aan iedereen die zijn of haar ervaring met ons deelt.</p>
				<?php else: ?>
					<div class="review-grid">
						<?php foreach ($approvedReviews as $approvedReview): ?>
							<article class="review-card">
								<div class="review-card-stars" aria-label="<?= (int) $approvedReview['rating'] ?> van 5 sterren">
									<?php for ($star = 1; $star <= 5; $star++): ?>
										<span aria-hidden="true"<?= $star <= (int) $approvedReview['rating'] ? ' class="is-filled"' : '' ?>>★</span>
									<?php endfor; ?>
								</div>
								<blockquote><?= nl2br(escape((string) $approvedReview['text'])) ?></blockquote>
								<p class="review-card-name"><?= escape((string) $approvedReview['name']) ?></p>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
	</main>

	<footer class="home-footer">
		<a class="home-footer-brand" href="index.html">Legends on the Road</a>
		<nav aria-label="Footer navigatie">
			<a href="reviews.php" aria-current="page">Reviews ›</a>
			<a href="https://www.instagram.com/legends.ontheroad/" target="_blank" rel="noopener noreferrer">Instagram ›</a>
			<a href="mailto:info@legendsontheroad.be">Contact ›</a>
		</nav>
		<span>© 2026 Legends on the Road</span>
	</footer>
</body>
</html>
