<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use function is_array;
use function is_string;

/**
 * Builds a reply reference from a parent AT URI.
 *
 * The parent is read with `getRecord` because its CID cannot be derived from
 * the URI. A parent that is itself a reply carries its own `root`; that root is
 * inherited, so a deep thread keeps one root instead of re-rooting at the
 * direct parent. Only a root reference without a usable CID is fetched
 * separately. A parent or root that the PDS cannot return is a refusal, never a
 * reference without a CID.
 */
final class AtprotoReplyBuilder
{
	public function __construct(
		private readonly AtprotoRecordResolver $records,
	) {
	}

	/**
	 * @throws AtprotoReplyException
	 */
	public function build(string $pdsUrl, string $parentUri): AtprotoReplyRef
	{
		$parent = $this->records->getRecord($pdsUrl, $parentUri);
		if ($parent === null) {
			throw new AtprotoReplyException(
				AtprotoReplyException::PARENT_UNRESOLVABLE,
				'The reply parent could not be read from the PDS.',
			);
		}

		$rootUri = $parent->uri;
		$rootCid = $parent->cid;

		$parentReply = $parent->record['reply'] ?? null;
		if (is_array($parentReply)) {
			$root = $parentReply['root'] ?? null;
			$inheritedUri = is_array($root) ? ($root['uri'] ?? null) : null;
			if (is_string($inheritedUri) && $inheritedUri !== '') {
				if (!str_starts_with($inheritedUri, 'at://') || strlen($inheritedUri) > 512) {
					throw new AtprotoReplyException(
						AtprotoReplyException::ROOT_UNRESOLVABLE,
						'The reply root of the parent record is not a valid AT URI.',
					);
				}

				$inheritedCid = $root['cid'] ?? null;
				$rootUri = $inheritedUri;
				$rootCid = is_string($inheritedCid) && $inheritedCid !== '' ? $inheritedCid : null;
			}
		}

		if ($rootCid === null) {
			$rootRecord = $this->records->getRecord($pdsUrl, $rootUri);
			if ($rootRecord === null) {
				throw new AtprotoReplyException(
					AtprotoReplyException::ROOT_UNRESOLVABLE,
					'The reply root could not be read from the PDS.',
				);
			}

			$rootCid = $rootRecord->cid;
		}

		return new AtprotoReplyRef($rootUri, $rootCid, $parent->uri, $parent->cid);
	}
}
