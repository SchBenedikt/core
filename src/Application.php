<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Http\HandlerMap;
use AlohaSocial\Core\Http\Kernel;
use AlohaSocial\Core\Http\Middleware\RequireAuthentication;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Http\RouteManifest;
use AlohaSocial\Core\Http\Router;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Jobs\JobHandlerRegistry;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Jobs\JobWorker;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\RandomIdGenerator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A complete core wired to in-memory adapters.
 *
 * This is a reference assembly, not the production bootstrap: the Nextcloud
 * and standalone hosts wire the same services around their own platform
 * adapters through their own containers. It exists so the demo, the tools and
 * the end-to-end test exercise one real, connected object graph.
 */
final readonly class Application
{
	public function __construct(
		public RouteManifest $routes,
		public Kernel $kernel,
		public PostService $posts,
		public TimelineService $timelines,
		public JobWorker $worker,
		public JobQueue $queue,
	) {
	}

	public static function inMemory(
		?Clock $clock = null,
		?Config $config = null,
		?HttpClient $httpClient = null,
		?RsaKeyPair $signingKey = null,
		?InboxResolver $inboxResolver = null,
		?LoggerInterface $logger = null,
		bool $debug = false,
	): self {
		$clock ??= new SystemClock();
		$config ??= new ArrayConfig([
			InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'instance.name' => 'Aloha Social',
			],
		]);
		$logger ??= new NullLogger();
		$inboxResolver ??= new EmptyInboxResolver();

		if (!$inboxResolver instanceof EmptyInboxResolver && ($httpClient === null || $signingKey === null)) {
			throw new InvalidArgumentException(
				'A delivery target requires both an HTTP client and a signing key.',
			);
		}

		$ids = new RandomIdGenerator();
		$repository = new InMemoryPostRepository();
		$queue = new InMemoryJobQueue($clock, $ids);
		$activities = new ActivityFactory($config);

		$postService = new PostService(
			$repository,
			$queue,
			$inboxResolver,
			$activities,
			$clock,
			$ids,
		);
		$timelines = new TimelineService($repository);
		$instance = new InstanceInfo($config);

		$handlers = (new HandlerMap())
			->with(HealthHandler::class, static fn (): HealthHandler => new HealthHandler())
			->with(InstanceHandler::class, static fn (): InstanceHandler => new InstanceHandler($instance))
			->with(CreatePostHandler::class, static fn (): CreatePostHandler => new CreatePostHandler($postService))
			->with(ShowPostHandler::class, static fn (): ShowPostHandler => new ShowPostHandler($postService))
			->with(DeletePostHandler::class, static fn (): DeletePostHandler => new DeletePostHandler($postService))
			->with(PublicTimelineHandler::class, static fn (): PublicTimelineHandler => new PublicTimelineHandler($timelines))
			->with(HomeTimelineHandler::class, static fn (): HomeTimelineHandler => new HomeTimelineHandler($timelines));

		$jobHandlers = new JobHandlerRegistry();
		if ($httpClient !== null && $signingKey !== null) {
			$jobHandlers = $jobHandlers->with(
				JobType::FEDERATION_DELIVER,
				new FederationDeliveryHandler($httpClient, $signingKey, $clock),
			);
		}

		$routes = Routes::manifest();

		// A manifest entry without a handler would only fail when somebody
		// hits it. Checking at boot turns that into a start-up error.
		$missing = [];
		foreach ($routes->routes() as $route) {
			if (!in_array($route->handler, $handlers->registered(), true)) {
				$missing[] = $route->name . ' (' . $route->handler . ')';
			}
		}

		if ($missing !== []) {
			throw new InvalidArgumentException(
				'No handler is registered for: ' . implode(', ', $missing),
			);
		}

		$kernel = new Kernel(
			new Router($routes),
			$handlers,
			[new RequireAuthentication()],
			$logger,
			$debug,
		);

		return new self(
			$routes,
			$kernel,
			$postService,
			$timelines,
			new JobWorker($queue, $jobHandlers, $clock, $logger),
			$queue,
		);
	}

	public function handle(Request $request): Response
	{
		return $this->kernel->handle($request);
	}
}
