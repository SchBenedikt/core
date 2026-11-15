<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsError;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Http\XrpcException;

/**
 * The HTTP-to-service adapter for com.atproto.identity, repo and sync.
 *
 * Authentication is still the host's job. The routes require a user id on
 * writes, and the service scopes every write to that account's DID. Reads are
 * public because that is what a PDS repository is.
 */
final class PdsXrpcHandler implements Handler
{
	public function __construct(private readonly ?NativePdsService $pds)
	{
	}

	public function handle(Request $request): Response
	{
		try {
			return $this->dispatch($request);
		} catch (PdsError $e) {
			throw new XrpcException($e->status, $e->error, $e->getMessage());
		} catch (XrpcException $e) {
			throw $e;
		}
	}

	private function dispatch(Request $request): Response
	{
		$pds = $this->pds;
		if ($pds === null) {
			throw PdsError::notEnabled();
		}

		return match ($request->path) {
			'/xrpc/com.atproto.identity.createDid' => $this->createDid($request, $pds),
			'/xrpc/com.atproto.identity.resolveDid' => $this->resolveDid($request, $pds),
			'/xrpc/com.atproto.identity.rotateKeys' => $this->rotateKeys($request, $pds),
			'/xrpc/com.atproto.repo.createRecord' => $this->createRecord($request, $pds),
			'/xrpc/com.atproto.repo.getRecord' => $this->getRecord($request, $pds),
			'/xrpc/com.atproto.repo.listRecords' => $this->listRecords($request, $pds),
			'/xrpc/com.atproto.repo.describeRepo' => $this->describeRepo($request, $pds),
			'/xrpc/com.atproto.sync.getRepo' => $this->getRepo($request, $pds),
			default => throw new XrpcException(404, 'NotFound', 'Unknown XRPC method.'),
		};
	}

	private function createDid(Request $request, NativePdsService $pds): Response
	{
		$body = $this->body($request);
		$handle = $body['handle'] ?? null;
		if ($handle !== null && !is_string($handle)) {
			throw new XrpcException(400, 'InvalidRequest', 'handle must be a string.');
		}

		return Response::json($pds->createIdentity($this->userId($request), $handle));
	}

	private function resolveDid(Request $request, NativePdsService $pds): Response
	{
		$did = $request->queryParam('did');
		if ($did === null || $did === '') {
			throw new XrpcException(400, 'InvalidRequest', 'did is required.');
		}

		return Response::json($pds->resolveDid($did));
	}

	private function rotateKeys(Request $request, NativePdsService $pds): Response
	{
		return Response::json($pds->rotateKeys($this->userId($request)));
	}

	private function createRecord(Request $request, NativePdsService $pds): Response
	{
		return Response::json($pds->createRecord($this->userId($request), $this->body($request)));
	}

	private function getRecord(Request $request, NativePdsService $pds): Response
	{
		return Response::json($pds->getRecord(
			$this->requiredQuery($request, 'repo'),
			$this->requiredQuery($request, 'collection'),
			$this->requiredQuery($request, 'rkey'),
		));
	}

	private function listRecords(Request $request, NativePdsService $pds): Response
	{
		$reverse = in_array(strtolower($request->queryParam('reverse', 'false') ?? 'false'), ['1', 'true'], true);

		return Response::json($pds->listRecords(
			$this->requiredQuery($request, 'repo'),
			$this->requiredQuery($request, 'collection'),
			$request->queryInt('limit', 50, 1, 100),
			$request->queryParam('cursor'),
			$reverse,
		));
	}

	private function describeRepo(Request $request, NativePdsService $pds): Response
	{
		return Response::json($pds->describeRepo($this->requiredQuery($request, 'repo')));
	}

	private function getRepo(Request $request, NativePdsService $pds): Response
	{
		$repo = $request->queryParam('did') ?? $request->queryParam('repo');
		if ($repo === null || $repo === '') {
			throw new XrpcException(400, 'InvalidRequest', 'did is required.');
		}

		return new Response(200, ['Content-Type' => 'application/vnd.ipld.car'], $pds->getRepo($repo));
	}

	private function userId(Request $request): string
	{
		if ($request->userId === null || $request->userId === '') {
			throw new XrpcException(401, 'AuthRequired', 'Authentication is required.');
		}

		return $request->userId;
	}

	private function requiredQuery(Request $request, string $name): string
	{
		$value = $request->queryParam($name);
		if ($value === null || $value === '') {
			throw new XrpcException(400, 'InvalidRequest', $name . ' is required.');
		}

		return $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function body(Request $request): array
	{
		try {
			return $request->json();
		} catch (HttpException $e) {
			throw new XrpcException(400, 'InvalidRequest', $e->getMessage());
		}
	}
}
