<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\Pds\PdsDirectory;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsPlcOperation;
use AlohaSocial\Core\Platform\Http\HttpClient;
use JsonException;
use RuntimeException;

/**
 * Submits signed PLC operations to a PLC directory over the host's HTTP port.
 *
 * The directory endpoint is unauthenticated by design: the operation's
 * rotation signature is the authorization. The client validates the HTTP
 * status but never retries a rejected operation; a retry belongs to the
 * account owner and is exposed as `identity.submitPlcOperation`.
 */
final readonly class PlcDirectoryClient implements PdsDirectory
{
	public function __construct(
		private HttpClient $http,
		private string $baseUrl = 'https://plc.directory',
		private int $timeoutSeconds = 10,
	) {
		if (rtrim(trim($baseUrl), '/') === '') {
			throw new \InvalidArgumentException('A PLC directory URL is required.');
		}
	}

	public function submit(PdsPlcOperation $operation): void
	{
		try {
			$body = json_encode($operation->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
			throw new RuntimeException('The PLC operation could not be serialized: ' . $e->getMessage(), 0, $e);
		}

		$response = $this->http->request(
			'POST',
			rtrim($this->baseUrl, '/') . '/' . rawurlencode($operation->did),
			[
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			$body,
			$this->timeoutSeconds,
		);

		if (!$response->isSuccessful()) {
			throw new RuntimeException(
				'The PLC directory rejected the operation with HTTP ' . $response->status . '.',
			);
		}
	}
}
