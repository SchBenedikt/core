<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use InvalidArgumentException;

/**
 * did:plc identifiers and DID documents.
 *
 * The identifier is derived from a genesis seed, not from the host's database
 * id, so it stays stable when the account is renamed. The document advertises
 * the current signing and rotation keys plus the PDS service endpoint.
 */
final class PdsDid
{
	public const CONTEXT = [
		'https://www.w3.org/ns/did/v1',
		'https://w3id.org/security/multikey/v1',
	];

	private function __construct()
	{
	}

	public static function generate(string $seed): string
	{
		$digest = hash('sha256', 'aloha-pds-genesis|' . $seed, true);

		return 'did:plc:' . PdsBase32::encode(substr($digest, 0, 15));
	}

	public static function assertValid(string $did): void
	{
		if (preg_match('/^did:plc:[a-z2-7]{24}$/', $did) !== 1) {
			throw new InvalidArgumentException('The DID is not a valid did:plc identifier.');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function document(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		string $pdsUrl,
	): array {
		$did = $identity->did;

		return [
			'@context' => self::CONTEXT,
			'id' => $did,
			'alsoKnownAs' => ['at://' . $identity->handle],
			'verificationMethod' => [
				[
					'id' => $did . '#atproto',
					'type' => 'Multikey',
					'controller' => $did,
					'publicKeyMultibase' => $signingKey->publicKeyMultibase,
				],
				[
					'id' => $did . '#atproto_rotation',
					'type' => 'Multikey',
					'controller' => $did,
					'publicKeyMultibase' => $rotationKey->publicKeyMultibase,
				],
			],
			'service' => [
				[
					'id' => '#atproto_pds',
					'type' => 'AtprotoPersonalDataServer',
					'serviceEndpoint' => $pdsUrl,
				],
			],
		];
	}
}
