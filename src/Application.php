<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

use AlohaSocial\Core\Api\ActorHandler;
use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowersCollectionHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\FollowerInboxResolver;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
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
use AlohaSocial\Core\Service\Federation\ActorDocumentService;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Service\Federation\InboxService;
use AlohaSocial\Core\Service\FollowerService;
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
		public FollowerService $followers,
		public ?InboxService $inbox,
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
		?ActorResolver $actorResolver = null,
		?ActivityLog $activityLog = null,
		?LocalActorDirectory $localActors = null,
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
		$activityLog ??= new InMemoryActivityLog();

		$ids = new RandomIdGenerator();
		$repository = new InMemoryPostRepository();
		$followers = new InMemoryFollowerRepository();

		// With a client and a key the reference assembly federates to the real
		// follow graph. Without them it reports no audience, so a post never
		// queues a delivery that could not be signed.
		if ($inboxResolver === null) {
			$inboxResolver = ($httpClient !== null && $signingKey !== null)
				? new FollowerInboxResolver($followers)
				: new EmptyInboxResolver();
		}

		if (!$inboxResolver instanceof EmptyInboxResolver && ($httpClient === null || $signingKey === null)) {
			throw new InvalidArgumentException(
				'A delivery target requires both an HTTP client and a signing key.',
			);
		}
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
		$followerService = new FollowerService($followers, $clock, $ids);
		$instance = new InstanceInfo($config);

		// Inbound federation needs a way to resolve a key id, a client to
		// reach the peer and a key to sign the Accept. Missing any of them
		// leaves the endpoint honestly at 503 rather than half-working.
		$inboxService = ($actorResolver !== null && $httpClient !== null && $signingKey !== null)
			? new InboxService($actorResolver, $followerService, $activityLog, $activities, $queue, $clock)
			: null;

		// An actor document needs a key to publish and a directory to answer
		// whether a handle exists. Missing either keeps the routes at 503.
		$actorDocuments = ($signingKey !== null && $localActors !== null)
			? new ActorDocumentService($activities, $signingKey, $localActors, $followerService)
			: null;

		$handlers = (new HandlerMap())
			->with(HealthHandler::class, static fn (): HealthHandler => new HealthHandler())
			->with(InstanceHandler::class, static fn (): InstanceHandler => new InstanceHandler($instance))
			->with(CreatePostHandler::class, static fn (): CreatePostHandler => new CreatePostHandler($postService))
			->with(ShowPostHandler::class, static fn (): ShowPostHandler => new ShowPostHandler($postService))
			->with(DeletePostHandler::class, static fn (): DeletePostHandler => new DeletePostHandler($postService))
			->with(PublicTimelineHandler::class, static fn (): PublicTimelineHandler => new PublicTimelineHandler($timelines))
			->with(HomeTimelineHandler::class, static fn (): HomeTimelineHandler => new HomeTimelineHandler($timelines))
			->with(FollowersHandler::class, static fn (): FollowersHandler => new FollowersHandler($followerService))
			->with(FollowHandler::class, static fn (): FollowHandler => new FollowHandler($followerService))
			->with(UnfollowHandler::class, static fn (): UnfollowHandler => new UnfollowHandler($followerService))
			->with(InboxHandler::class, static fn (): InboxHandler => new InboxHandler($inboxService))
			->with(ActorHandler::class, static fn (): ActorHandler => new ActorHandler($actorDocuments))
			->with(FollowersCollectionHandler::class, static fn (): FollowersCollectionHandler => new FollowersCollectionHandler($actorDocuments));

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
			$followerService,
			$inboxService,
			new JobWorker($queue, $jobHandlers, $clock, $logger),
			$queue,
		);
	}

	public function handle(Request $request): Response
	{
		return $this->kernel->handle($request);
	}
}
