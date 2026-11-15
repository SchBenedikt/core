<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\Ecdsa;

/**
 * Builds the PLC operation that commits a DID document.
 *
 * The rotation key signs the operation, exactly as it does in PLC. The signed
 * operation is itself the value hashed into its CID; `prev` links rotations to
 * the previous operation so an old operation cannot be replayed as the newest
 * state.
 */
final class PdsPlc
{
	public const TYPE = 'plc_operation';

	private function __construct()
	{
	}

	/**
	 * @return array{bytes: string, cid: Cid}
	 */
	public static function operation(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		string $pdsUrl,
		?Cid $previous,
		string $rotationPrivateKeyPem,
	): array {
		$data = [
			'type' => self::TYPE,
			'rotationKeys' => ['did:key:' . $rotationKey->publicKeyMultibase],
			'verificationMethods' => ['atproto' => 'did:key:' . $signingKey->publicKeyMultibase],
			'alsoKnownAs' => ['at://' . $identity->handle],
			'services' => [
				'atproto_pds' => [
					'type' => 'AtprotoPersonalDataServer',
					'endpoint' => $pdsUrl,
				],
			],
			'prev' => $previous,
		];

		$signature = Ecdsa::sign(DagCbor::encode($data), $rotationPrivateKeyPem);
		$bytes = DagCbor::encode($data + ['sig' => new Bytes($signature)]);

		return ['bytes' => $bytes, 'cid' => Cid::forData($bytes)];
	}
}
