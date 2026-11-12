<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\MultipartFormData;
use AlohaSocial\Core\Http\Request;

use function strlen;
use function trim;

/**
 * The parsed, still-unvalidated input of a media upload.
 *
 * The core media route and the Mastodon compatibility route accept the same
 * multipart/form-data envelope. Keeping the parsing in one place means a
 * malformed request, an oversized body or a missing file has the same stable
 * error on both surfaces; the media service remains the only place that
 * validates image bytes.
 */
final readonly class MediaUploadInput
{
	private const REQUEST_OVERHEAD_BYTES = 131_072;

	public function __construct(
		public string $bytes,
		public ?string $altText,
	) {
	}

	/**
	 * @param list<string> $altFields field names to try in order; the first present one wins
	 *
	 * @throws HttpException when the request is not a usable upload
	 */
	public static function fromRequest(Request $request, array $altFields = ['alt_text']): self
	{
		if (strlen($request->body) > Attachment::MAX_BYTES + self::REQUEST_OVERHEAD_BYTES) {
			throw new HttpException(413, 'payload_too_large', 'The upload is too large.');
		}

		$form = MultipartFormData::fromRequest($request);
		$file = $form->file('file');
		if ($file === null) {
			throw HttpException::validationFailed(['file' => 'A file field is required.']);
		}

		$altText = null;
		foreach ($altFields as $field) {
			$value = $form->field($field);
			if ($value === null) {
				continue;
			}

			$altText = trim($value);
			break;
		}

		if ($altText === '') {
			$altText = null;
		}

		return new self($file->contents, $altText);
	}
}
