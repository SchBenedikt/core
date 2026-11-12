<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use RuntimeException;

use function array_values;
use function bin2hex;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagedestroy;
use function is_dir;
use function is_resource;
use function is_string;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function scandir;
use function stream_context_create;
use function stream_socket_get_name;
use function stream_socket_server;
use function str_contains;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * A local HTTP server whose hostname is deliberately not loopback.
 *
 * The guard resolves `media.remote.example` to a public test address. The
 * test HTTP client maps that URL to this loopback server, so the full fetch,
 * redirect and timeout path is exercised without weakening production rules.
 */
final class RemoteMediaServer
{
	public readonly string $baseUrl;

	public readonly string $fixtureBytes;

	private string $directory;

	/**
	 * @var resource|null
	 */
	private $process = null;

	/**
	 * @var list<resource>
	 */
	private array $pipes = [];

	private string $log;

	public function __construct()
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-remote-media-' . bin2hex(random_bytes(8));
		if (!mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
			throw new RuntimeException('Could not create the remote-media test directory.');
		}

		$fixture = $this->directory . '/fixture.png';
		$image = imagecreatetruecolor(120, 80);
		if ($image === false) {
			throw new RuntimeException('GD is required for the remote-media test.');
		}
		$color = imagecolorallocate($image, 30, 140, 210);
		imagefill($image, 0, 0, $color);
		ob_start();
		imagepng($image);
		$bytes = ob_get_clean();
		imagedestroy($image);
		if (!is_string($bytes) || file_put_contents($fixture, $bytes) === false) {
			throw new RuntimeException('Could not write the remote-media fixture.');
		}
		$this->fixtureBytes = $bytes;

		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
		if ($socket === false) {
			throw new RuntimeException('Could not reserve a test port: ' . $errorMessage);
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		if (!is_string($address) || !str_contains($address, ':')) {
			throw new RuntimeException('Could not determine the test port.');
		}
		$port = (int) substr($address, strrpos($address, ':') + 1);

		$this->baseUrl = 'http://127.0.0.1:' . $port;
		$this->log = $this->directory . '/server.log';
		$router = __DIR__ . '/remote-media-router.php';

		$command = [
			PHP_BINARY,
			'-S',
			'127.0.0.1:' . $port,
			$router,
		];
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['file', $this->log, 'a'],
			2 => ['file', $this->log, 'a'],
		];

		$process = proc_open($command, $descriptors, $pipes, $this->directory, [
			'MEDIA_FIXTURE' => $fixture,
		]);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the remote-media test server.');
		}

		$this->process = $process;
		$this->pipes = array_values($pipes);
		if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
			fclose($this->pipes[0]);
		}

		$deadline = microtime(true) + 5.0;
		while (microtime(true) < $deadline) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				throw new RuntimeException('The remote-media test server exited: ' . $this->logTail());
			}

			$context = stream_context_create(['http' => ['timeout' => 0.2]]);
			$health = @file_get_contents($this->baseUrl . '/health', false, $context);
			if ($health === 'ok') {
				return;
			}

			usleep(50_000);
		}

		$this->stop();

		throw new RuntimeException('The remote-media test server did not become ready.');
	}

	public function stop(): void
	{
		if (is_resource($this->process)) {
			proc_terminate($this->process);
			proc_close($this->process);
			$this->process = null;
		}

		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		$this->pipes = [];

		$this->remove($this->directory);
	}

	private function logTail(): string
	{
		$log = @file_get_contents($this->log);

		return is_string($log) ? substr($log, -2000) : '';
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;
			if (is_dir($full)) {
				$this->remove($full);
			} else {
				@unlink($full);
			}
		}

		@rmdir($path);
	}
}
