<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsSchema;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Security\OpenSslSecretBox;

require __DIR__ . '/../../vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/health') {
	header('Content-Type: text/plain');
	echo 'ok';

	return;
}

$databasePath = getenv('PDS_TEST_DB') ?: '';
$secret = getenv('PDS_TEST_SECRET') ?: '';
if ($databasePath === '' || $secret === '') {
	http_response_code(500);
	echo 'PDS test environment is incomplete';

	return;
}

$pdo = new PDO('sqlite:' . $databasePath);
$database = new PdoConnection($pdo);
if ($database->fetchValue(
	"SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
	[PdsSchema::IDENTITY_TABLE],
) === null) {
	PdsSchema::create($database);
}
PdsSchema::upgrade($database);

$config = new ArrayConfig(['social' => [
	'atproto.pds.enabled' => 'true',
	'atproto.pds.public_url' => 'https://pds.example',
	'atproto.pds.handle_domain' => 'pds.example',
]]);

$app = Application::inMemory(
	config: $config,
	blobStore: new LocalBlobStore(dirname($databasePath) . '/blobs'),
	pdsRepository: new PdoPdsRepository($database),
	pdsSecrets: OpenSslSecretBox::fromBase64Key($secret),
	debug: true,
);

$request = Request::fromGlobals();
$user = $request->header('x-pds-test-user');
if ($user !== null && $user !== '') {
	$request = $request->withUserId($user);
}

$app->handle($request)->send();
