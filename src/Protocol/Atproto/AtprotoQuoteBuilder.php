<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Builds an AT Protocol record embed from a record whose URI and CID were
 * verified against the linked account's PDS.
 */
final class AtprotoQuoteBuilder
{
	public function __construct(
		private readonly AtprotoRecordResolver $records,
	) {
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws AtprotoQuoteException when the target is missing, mismatched or not a feed post
	 */
	public function build(string $pdsUrl, string $uri): array
	{
		$record = $this->records->getRecord($pdsUrl, $uri);
		if ($record === null
			|| $record->uri !== $uri
			|| ($record->record['$type'] ?? null) !== AtprotoPostRecord::COLLECTION
		) {
			throw new AtprotoQuoteException();
		}

		return [
			'$type' => AtprotoEmbed::RECORD,
			'record' => [
				'uri' => $record->uri,
				'cid' => $record->cid,
			],
		];
	}
}
