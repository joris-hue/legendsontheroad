<?php
declare(strict_types=1);

function reviewStorageDirectory(): string
{
	$configuredDirectory = getenv('LEGENDS_REVIEW_DATA_DIR');
	if (is_string($configuredDirectory) && $configuredDirectory !== '') {
		if (!is_dir($configuredDirectory)) {
			@mkdir($configuredDirectory, 0700, true);
		}
		if (is_dir($configuredDirectory) && is_writable($configuredDirectory)) {
			@chmod($configuredDirectory, 0700);
			return $configuredDirectory;
		}
	}

	$outsideCandidates = [
		dirname(__DIR__) . DIRECTORY_SEPARATOR . 'review-data',
		dirname(__DIR__) . DIRECTORY_SEPARATOR . '.review-data',
	];

	foreach ($outsideCandidates as $directory) {
		if (is_dir($directory) || @mkdir($directory, 0700, true)) {
			if (is_writable($directory)) {
				@chmod($directory, 0700);
				return $directory;
			}
		}
	}

	$directory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
	if (!is_dir($directory)) {
		@mkdir($directory, 0700, true);
	}
	@chmod($directory, 0700);

	return $directory;
}

function reviewReviewsFile(): string
{
	return reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'reviews.json';
}

function reviewAdminFile(): string
{
	return reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'admin.json';
}

function reviewSetupKey(): ?string
{
	$environmentKey = getenv('LEGENDS_REVIEW_SETUP_KEY');
	if (is_string($environmentKey) && $environmentKey !== '') {
		return $environmentKey;
	}

	$keyFile = reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'setup.key';
	if (!is_file($keyFile) || !is_readable($keyFile)) {
		return null;
	}

	$key = trim((string) @file_get_contents($keyFile));
	return $key !== '' ? $key : null;
}

function reviewReadArray(string $file): array
{
	if (!is_file($file)) {
		return [];
	}

	$handle = @fopen($file, 'rb');
	if ($handle === false) {
		return [];
	}

	flock($handle, LOCK_SH);
	$contents = stream_get_contents($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	$value = json_decode((string) $contents, true);
	return is_array($value) ? $value : [];
}

function reviewWriteArray(string $file, array $value): bool
{
	$directory = dirname($file);
	if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
		return false;
	}

	$tempFile = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
	$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	if ($json === false) {
		return false;
	}

	$handle = @fopen($tempFile, 'xb');
	if ($handle === false) {
		return false;
	}

	if (!flock($handle, LOCK_EX)) {
		fclose($handle);
		@unlink($tempFile);
		return false;
	}

	$written = fwrite($handle, $json);
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	if ($written === false) {
		@unlink($tempFile);
		return false;
	}

	$result = @rename($tempFile, $file);
	if ($result) {
		@chmod($file, 0600);
	}
	return $result;
}

function reviewAll(): array
{
	$reviews = reviewReadArray(reviewReviewsFile());
	usort($reviews, static function (array $a, array $b): int {
		return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
	});
	return $reviews;
}

function reviewApproved(): array
{
	return array_values(array_filter(reviewAll(), static function (array $review): bool {
		return ($review['status'] ?? '') === 'approved';
	}));
}

function reviewAdd(string $name, int $rating, string $text): ?string
{
	$reviewsFile = reviewReviewsFile();
	$handle = @fopen($reviewsFile . '.lock', 'c');
	if ($handle === false) {
		return null;
	}

	flock($handle, LOCK_EX);
	$reviews = reviewReadArray($reviewsFile);
	$id = bin2hex(random_bytes(16));
	$reviews[] = [
		'id' => $id,
		'name' => $name,
		'rating' => $rating,
		'text' => $text,
		'status' => 'pending',
		'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
	];

	$saved = reviewWriteArray($reviewsFile, $reviews);
	flock($handle, LOCK_UN);
	fclose($handle);
	@chmod($reviewsFile . '.lock', 0600);

	return $saved ? $id : null;
}

function reviewSetStatus(string $id, string $status): bool
{
	if (!in_array($status, ['approved', 'rejected'], true)) {
		return false;
	}

	$reviewsFile = reviewReviewsFile();
	$handle = @fopen($reviewsFile . '.lock', 'c');
	if ($handle === false) {
		return false;
	}

	flock($handle, LOCK_EX);
	$reviews = reviewReadArray($reviewsFile);
	$found = false;
	foreach ($reviews as &$review) {
		if (($review['id'] ?? '') === $id) {
			$review['status'] = $status;
			$found = true;
			break;
		}
	}
	unset($review);

	$saved = $found && reviewWriteArray($reviewsFile, $reviews);
	flock($handle, LOCK_UN);
	fclose($handle);
	return $saved;
}

function reviewDelete(string $id): bool
{
	$reviewsFile = reviewReviewsFile();
	$handle = @fopen($reviewsFile . '.lock', 'c');
	if ($handle === false) {
		return false;
	}

	flock($handle, LOCK_EX);
	$reviews = reviewReadArray($reviewsFile);
	$filtered = array_values(array_filter($reviews, static function (array $review) use ($id): bool {
		return ($review['id'] ?? '') !== $id;
	}));

	$removed = count($filtered) !== count($reviews);
	$saved = $removed && reviewWriteArray($reviewsFile, $filtered);
	flock($handle, LOCK_UN);
	fclose($handle);
	return $saved;
}
