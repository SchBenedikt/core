<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Moderation\ModerationService;
use AlohaSocial\Core\Service\ValidationException;

use function array_is_list;
use function is_array;
use function is_string;
use function trim;

/**
 * `POST /api/v1/reports`
 *
 * Any authenticated local account can file a report. Reviewing and resolving
 * are service operations guarded by the host's AdminAuthorization port; there
 * is deliberately no unauthenticated admin route here.
 */
final class SubmitReportHandler implements Handler
{
	public function __construct(
		private readonly ModerationService $moderation,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();
		$targetType = $body['target_type'] ?? null;
		$targetId = $body['target_id'] ?? null;
		$reason = $body['reason'] ?? null;
		if (!is_string($targetType) || !is_string($targetId) || !is_string($reason)) {
			throw HttpException::validationFailed([
				'target_type' => 'target_type, target_id and reason are required strings.',
			]);
		}

		$comment = $body['comment'] ?? null;
		if ($comment !== null && !is_string($comment)) {
			throw HttpException::validationFailed(['comment' => 'comment must be a string or null.']);
		}

		$evidence = $body['evidence'] ?? [];
		if (!is_array($evidence) || !array_is_list($evidence)) {
			throw HttpException::validationFailed(['evidence' => 'evidence must be a list of strings.']);
		}

		foreach ($evidence as $entry) {
			if (!is_string($entry)) {
				throw HttpException::validationFailed(['evidence' => 'evidence must be a list of strings.']);
			}
		}

		try {
			$report = $this->moderation->submitReport(
				$userId,
				trim($targetType),
				trim($targetId),
				trim($reason),
				$comment,
				$evidence,
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['report' => $report->toArray()], 201);
	}
}
