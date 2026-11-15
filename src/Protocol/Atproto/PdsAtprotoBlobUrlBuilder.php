<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use AlohaSocial\Core\Domain\Atproto\AtprotoBlobUrlBuilder;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use InvalidArgumentException;

use function filter_var;
use function in_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function strtolower;

/**
 * Builds the standard `com.atproto.sync.getBlob` URL on a PDS.
 *
 * This is a convenience adapter, not a network call. The PDS URL is fixed by
 * the host at construction; a record can only choose the DID and CID that are
 * encoded into its query string.
 */
final class PdsAtprotoBlobUrlBuilder implements AtprotoBlobUrlBuilder
{
	private readonly string $pdsUrl;

	public function __construct(string $pdsUrl)
	{
		$pdsUrl = rtrim($pdsUrl, '/');
		$scheme = parse_url($pdsUrl, PHP_URL_SCHEME);
		$host = parse_url($pdsUrl, PHP_URL_HOST);

		if (filter_var($pdsUrl, FILTER_VALIDATE_URL) === false
			|| !is_string($scheme)
			|| !is_string($host)
			|| $host === ''
			|| !in_array(strtolower($scheme), ['http', 'https'], true)
		) {
			throw new InvalidArgumentException('A PDS blob builder needs an absolute http(s) URL.');
		}

		$this->pdsUrl = $pdsUrl;
	}

	public function url(string $did, string $cid): ?string
	{
		if (!AtprotoIdentifiers::isDid($did) || $cid === '' || mb_strlen($cid) > 256) {
			return null;
		}

		return $this->pdsUrl . '/xrpc/com.atproto.sync.getBlob?did='
			. rawurlencode($did) . '&cid=' . rawurlencode($cid);
	}
}
