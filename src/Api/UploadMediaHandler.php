<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\MultipartFormData;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Storage\StorageException;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\ValidationException;

use function strlen;
use function trim;

/**
 * `POST /api/v1/media`
 *
 * Accepts `multipart/form-data` with a file field named `file` and an
 * optional `alt_text` field. The service owns image and size validation.
 */
final class UploadMediaHandler implements Handler
{
	private const REQUEST_OVERHEAD_BYTES = 131_072;

	public function __construct(
		private readonly ?MediaService $media,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		if ($this->media === null) {
			throw new HttpException(503, 'media_not_configured', 'Media storage is not configured.');
		}

		if (strlen($request->body) > Attachment::MAX_BYTES + self::REQUEST_OVERHEAD_BYTES) {
			throw new HttpException(413, 'payload_too_large', 'The upload is too large.');
		}

		$form = MultipartFormData::fromRequest($request);
		$file = $form->file('file');
		if ($file === null) {
			throw HttpException::validationFailed(['file' => 'A file field is required.']);
		}

		$altText = $form->field('alt_text');
		$altText = $altText === null ? null : trim($altText);
		if ($altText === '') {
			$altText = null;
		}

		try {
			$attachment = $this->media->upload($userId, $file->contents, $altText);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (StorageException) {
			throw new HttpException(503, 'media_storage_unavailable', 'The media file could not be stored.');
		}

		return Response::json(
			['attachment' => $this->media->present($attachment)],
			201,
			['Location' => ApiVersion::BASE_PATH . '/media/' . rawurlencode($attachment->id)],
		);
	}
}
