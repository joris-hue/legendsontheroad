<?php
declare(strict_types=1);

function reviewStorageDirectory(): string
{
	$webRoot = realpath(__DIR__);
	if ($webRoot === false) {
		throw new RuntimeException('The website root could not be resolved.');
	}

	$configuredDirectory = getenv('LEGENDS_REVIEW_DATA_DIR');
	$directory = is_string($configuredDirectory) && $configuredDirectory !== ''
		? $configuredDirectory
		: dirname($webRoot) . DIRECTORY_SEPARATOR . 'review-data';

	if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
		throw new RuntimeException('A private review storage directory could not be created.');
	}

	$resolvedDirectory = realpath($directory);
	if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || !is_writable($resolvedDirectory)) {
		throw new RuntimeException('A writable private review storage directory is unavailable.');
	}

	$normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $webRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	$normalizedData = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedDirectory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	$comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
	$comparisonData = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedData) : $normalizedData;
	if (strpos($comparisonData, $comparisonRoot) === 0) {
		throw new RuntimeException('Review data must be stored outside the public website root.');
	}
	if (!reviewSetPrivatePermissions($resolvedDirectory, 0700)) {
		throw new RuntimeException('Private review storage permissions could not be enforced.');
	}
	return $resolvedDirectory;
}

function reviewSetPrivatePermissions(string $path, int $mode): bool
{
	if (DIRECTORY_SEPARATOR === '\\') {
		return true;
	}
	if (!@chmod($path, $mode)) {
		return false;
	}
	$permissions = @fileperms($path);
	return $permissions !== false && ($permissions & 0077) === 0;
}

function reviewReviewsFile(): string
{
	return reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'reviews.json';
}

function reviewAdminFile(): string
{
	return reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'admin.json';
}

function reviewReadArray(string $file): array
{
	if (!file_exists($file)) {
		return [];
	}
	if (!is_file($file) || !is_readable($file)) {
		throw new RuntimeException('A review data file is unavailable.');
	}
	if (!reviewSetPrivatePermissions($file, 0600)) {
		throw new RuntimeException('Private review data file permissions could not be enforced.');
	}

	$contents = @file_get_contents($file);
	if ($contents === false) {
		throw new RuntimeException('A review data file could not be read.');
	}
	$value = json_decode($contents, true);
	if (!is_array($value)) {
		throw new RuntimeException('A review data file is invalid.');
	}
	return $value;
}

function reviewWriteArray(string $file, array $value): void
{
	$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	if ($json === false) {
		throw new RuntimeException('Review data could not be encoded.');
	}

	$tempFile = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
	$handle = @fopen($tempFile, 'xb');
	if ($handle === false) {
		throw new RuntimeException('A temporary review data file could not be created.');
	}
	if (!reviewSetPrivatePermissions($tempFile, 0600)) {
		fclose($handle);
		@unlink($tempFile);
		throw new RuntimeException('Private temporary file permissions could not be enforced.');
	}

	$offset = 0;
	$length = strlen($json);
	while ($offset < $length) {
		$written = fwrite($handle, substr($json, $offset));
		if ($written === false || $written === 0) {
			fclose($handle);
			@unlink($tempFile);
			throw new RuntimeException('Review data could not be written completely.');
		}
		$offset += $written;
	}
	if (!fflush($handle)) {
		fclose($handle);
		@unlink($tempFile);
		throw new RuntimeException('Review data could not be flushed to disk.');
	}
	fclose($handle);

	if (!@rename($tempFile, $file)) {
		@unlink($tempFile);
		throw new RuntimeException('Review data could not be saved.');
	}
	if (!reviewSetPrivatePermissions($file, 0600)) {
		throw new RuntimeException('Private review data file permissions could not be enforced.');
	}
}

function reviewWithLock(callable $callback)
{
	$lockFile = reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'reviews.lock';
	$handle = @fopen($lockFile, 'c');
	if ($handle === false) {
		throw new RuntimeException('Review storage could not be locked.');
	}
	if (!reviewSetPrivatePermissions($lockFile, 0600)) {
		fclose($handle);
		throw new RuntimeException('Private review lock permissions could not be enforced.');
	}
	if (!flock($handle, LOCK_EX)) {
		fclose($handle);
		throw new RuntimeException('Review storage could not be locked.');
	}

	try {
		return $callback();
	} finally {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}

function reviewUpdateAdmin(callable $callback)
{
	return reviewWithLock(static function () use ($callback) {
		$admin = reviewReadArray(reviewAdminFile());
		$result = $callback($admin);
		reviewWriteArray(reviewAdminFile(), $admin);
		return $result;
	});
}

function reviewGetAdmin(): array
{
	return reviewWithLock(static function (): array {
		return reviewReadArray(reviewAdminFile());
	});
}

function reviewAll(): array
{
	$reviews = reviewWithLock(static function (): array {
		return reviewReadArray(reviewReviewsFile());
	});
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

function reviewAdd(string $name, int $rating, string $text): string
{
	return reviewWithLock(static function () use ($name, $rating, $text): string {
		$reviews = reviewReadArray(reviewReviewsFile());
		$id = bin2hex(random_bytes(16));
		$reviews[] = [
			'id' => $id,
			'name' => $name,
			'rating' => $rating,
			'text' => $text,
			'status' => 'pending',
			'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
		];
		reviewWriteArray(reviewReviewsFile(), $reviews);
		return $id;
	});
}

function reviewSetStatus(string $id, string $status): bool
{
	if (!in_array($status, ['approved', 'rejected'], true)) {
		return false;
	}

	return reviewWithLock(static function () use ($id, $status): bool {
		$reviews = reviewReadArray(reviewReviewsFile());
		$found = false;
		foreach ($reviews as &$review) {
			if (($review['id'] ?? '') === $id) {
				$review['status'] = $status;
				$found = true;
				break;
			}
		}
		unset($review);
		if ($found) {
			reviewWriteArray(reviewReviewsFile(), $reviews);
		}
		return $found;
	});
}

function reviewDelete(string $id): bool
{
	return reviewWithLock(static function () use ($id): bool {
		$reviews = reviewReadArray(reviewReviewsFile());
		$filtered = array_values(array_filter($reviews, static function (array $review) use ($id): bool {
			return ($review['id'] ?? '') !== $id;
		}));
		if (count($filtered) === count($reviews)) {
			return false;
		}
		reviewWriteArray(reviewReviewsFile(), $filtered);
		return true;
	});
}

function reviewSendEmail(string $recipient, string $subject, string $body): bool
{
	if (getenv('LEGENDS_REVIEW_TEST_MODE') === '1') {
		reviewWithLock(static function () use ($recipient, $subject, $body): void {
			$outboxFile = reviewStorageDirectory() . DIRECTORY_SEPARATOR . 'test-outbox.json';
			$outbox = reviewReadArray($outboxFile);
			$outbox[] = [
				'to' => $recipient,
				'subject' => $subject,
				'body' => $body,
				'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
			];
			reviewWriteArray($outboxFile, $outbox);
		});
		return true;
	}

	$headers = [
		'From: Legends on the Road <info@legendsontheroad.be>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
	];
	return mail($recipient, $subject, $body, implode("\r\n", $headers));
}

function reviewClientKey(array &$admin): string
{
	if (!isset($admin['rate_salt']) || !is_string($admin['rate_salt'])) {
		$admin['rate_salt'] = bin2hex(random_bytes(32));
	}
	$address = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	return hash_hmac('sha256', $address, $admin['rate_salt']);
}

function reviewRequestAdminCode(string $sessionId): string
{
	$code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
	$purpose = '';
	$now = time();
	$sessionHash = hash('sha256', $sessionId);
	$clientKey = '';

	$allowed = reviewUpdateAdmin(static function (array &$admin) use ($now, $sessionHash, $code, &$purpose, &$clientKey): bool {
		$purpose = is_string($admin['password_hash'] ?? null) ? 'reset' : 'setup';
		$clientKey = reviewClientKey($admin);

		$requests = array_values(array_filter(
			is_array($admin['code_requests'] ?? null) ? $admin['code_requests'] : [],
			static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 3600
		));
		$clientRequestHistory = is_array($admin['client_code_requests'] ?? null)
			? $admin['client_code_requests']
			: [];
		foreach ($clientRequestHistory as $key => $timestamps) {
			$recent = array_values(array_filter(
				is_array($timestamps) ? $timestamps : [],
				static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 3600
			));
			if ($recent === []) {
				unset($clientRequestHistory[$key]);
			} else {
				$clientRequestHistory[$key] = $recent;
			}
		}
		$admin['client_code_requests'] = $clientRequestHistory;
		$clientRequests = array_values(array_filter(
			is_array($admin['client_code_requests'][$clientKey] ?? null) ? $admin['client_code_requests'][$clientKey] : [],
			static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 3600
		));
		$lastClientRequest = $clientRequests === [] ? 0 : max($clientRequests);

		$admin['code_requests'] = $requests;
		$admin['client_code_requests'][$clientKey] = $clientRequests;
		if (count($requests) >= 10 || count($clientRequests) >= 3 || $lastClientRequest > $now - 60) {
			return false;
		}

		$admin['code_requests'][] = $now;
		$admin['client_code_requests'][$clientKey][] = $now;
		$admin['challenge'] = [
			'purpose' => $purpose,
			'code_hash' => password_hash($code, PASSWORD_DEFAULT),
			'session_hash' => $sessionHash,
			'created_at' => $now,
			'expires_at' => $now + 600,
			'attempts' => 0,
		];
		return true;
	});

	if (!$allowed) {
		return 'limited';
	}

	$subject = $purpose === 'setup'
		? 'Verificatiecode voor reviewbeheer - Legends on the Road'
		: 'Wachtwoordherstel reviewbeheer - Legends on the Road';
	$body = "Je eenmalige verificatiecode is: {$code}\r\n\r\n" .
		"De code is 10 minuten geldig en kan maar een beperkt aantal keer worden geprobeerd.\r\n" .
		"Heb je deze code niet aangevraagd? Negeer deze e-mail.";

	if (!reviewSendEmail('info@legendsontheroad.be', $subject, $body)) {
		reviewUpdateAdmin(static function (array &$admin) use ($sessionHash): void {
			if (($admin['challenge']['session_hash'] ?? '') === $sessionHash) {
				unset($admin['challenge']);
			}
		});
		error_log('Review admin verification email could not be sent.');
		return 'mail_failed';
	}

	return 'sent';
}

function reviewCompleteAdminCode(string $sessionId, string $code, string $password): string
{
	$sessionHash = hash('sha256', $sessionId);
	return reviewUpdateAdmin(static function (array &$admin) use ($sessionHash, $code, $password): string {
		$challenge = $admin['challenge'] ?? null;
		if (!is_array($challenge) || !hash_equals((string) ($challenge['session_hash'] ?? ''), $sessionHash)) {
			return 'invalid';
		}
		if ((int) ($challenge['expires_at'] ?? 0) <= time()) {
			unset($admin['challenge']);
			return 'expired';
		}
		if ((int) ($challenge['attempts'] ?? 0) >= 5) {
			unset($admin['challenge']);
			return 'attempts_exceeded';
		}
		if (!preg_match('/\A[0-9]{8}\z/', $code) || !password_verify($code, (string) ($challenge['code_hash'] ?? ''))) {
			$admin['challenge']['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
			if ($admin['challenge']['attempts'] >= 5) {
				unset($admin['challenge']);
				return 'attempts_exceeded';
			}
			return 'wrong_code';
		}

		$isConfigured = is_string($admin['password_hash'] ?? null);
		$purpose = (string) ($challenge['purpose'] ?? '');
		if (($purpose === 'setup' && $isConfigured) || ($purpose === 'reset' && !$isConfigured)) {
			unset($admin['challenge']);
			return 'invalid';
		}
		if (strlen($password) < 14) {
			return 'weak_password';
		}

		$admin['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
		$admin['password_updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$admin['session_version'] = bin2hex(random_bytes(32));
		$admin['login_failures'] = [];
		unset($admin['challenge']);
		return $purpose === 'setup' ? 'setup_complete' : 'password_reset';
	});
}

function reviewCheckLogin(string $password): string
{
	$now = time();
	return reviewUpdateAdmin(static function (array &$admin) use ($password, $now): string {
		$clientKey = reviewClientKey($admin);
		$loginFailures = is_array($admin['login_failures'] ?? null) ? $admin['login_failures'] : [];
		foreach ($loginFailures as $key => $failure) {
			if (!is_array($failure)) {
				unset($loginFailures[$key]);
				continue;
			}
			$lastAttempt = (int) ($failure['last_attempt'] ?? $failure['locked_until'] ?? 0);
			if ($lastAttempt < $now - 86400 && (int) ($failure['locked_until'] ?? 0) <= $now) {
				unset($loginFailures[$key]);
			}
		}
		$admin['login_failures'] = $loginFailures;
		$login = $loginFailures[$clientKey] ?? ['count' => 0, 'locked_until' => 0];
		if ((int) ($login['locked_until'] ?? 0) > $now) {
			return 'locked';
		}

		$hash = (string) ($admin['password_hash'] ?? '');
		if ($hash !== '' && password_verify($password, $hash)) {
			unset($admin['login_failures'][$clientKey]);
			if (!is_string($admin['session_version'] ?? null)) {
				$admin['session_version'] = bin2hex(random_bytes(32));
			}
			if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
				$admin['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
			}
			return 'success';
		}

		$count = (int) ($login['count'] ?? 0) + 1;
		$admin['login_failures'][$clientKey] = [
			'count' => $count,
			'locked_until' => $count >= 5 ? $now + 900 : 0,
			'last_attempt' => $now,
		];
		return 'invalid';
	});
}
