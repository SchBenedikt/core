<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

use AlohaSocial\Core\Api\ActorHandler;
use AlohaSocial\Core\Api\BlockActorHandler;
use AlohaSocial\Core\Api\BlocksHandler;
use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowRemoteActorHandler;
use AlohaSocial\Core\Api\FollowersCollectionHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\FollowingCollectionHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\OutboxHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\ShowMediaHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\UnblockActorHandler;
use AlohaSocial\Core\Api\UploadMediaHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Api\UnfollowRemoteActorHandler;
use AlohaSocial\Core\Api\WebFingerHandler;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\AcceptAllFollows;
use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\ActorResolver;
use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\BlockRepository;
use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\FollowPolicy;
use AlohaSocial\Core\Domain\Federation\FollowerInboxResolver;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\NoBlockedActors;
use AlohaSocial\Core\Domain\Federation\PdoBlockRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowerRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Media\PdoAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryHomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\PdoHomeTimelineRepository;
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
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Image\GdImageProcessor;
use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Platform\Image\ImageProcessor;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\ActorDocumentService;
use AlohaSocial\Core\Service\Federation\ActorOutboxService;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Service\Federation\InboxService;
use AlohaSocial\Core\Service\Federation\RemoteMediaFetcher;
use AlohaSocial\Core\Service\Federation\RemoteMediaService;
use AlohaSocial\Core\Service\Federation\RemoteOutboxBackfill;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Service\BlockService;
use AlohaSocial\Core\Service\Federation\WebFingerService;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\RandomIdGenerator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A complete core wired to a set of ports.
 *
 * `inMemory()` is the reference assembly for the demo, tools and tests.
 * `forHost()` is the production composition root: it takes the host's real
 * ports and uses the SQL repositories, so a host does not have to copy the
 * service wiring. Both share one assembly path, which keeps them from drifting.
 *
 * This is still a library: the host owns authentication, its HTTP controller,
 * rate limiting, migrations, the durable queue and the key at rest. The core
 * owns the route contract and everything behind the handlers.
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
		public FollowingService $following,
		public BlockService $blocks,
		public ?RemoteOutboxBackfill $backfill,
		public ?MediaService $media,
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
		?FollowingRepository $followingRepository = null,
		?LoggerInterface $logger = null,
		bool $debug = false,
		?RemotePostRepository $remotePostRepository = null,
		?BlockedActorPolicy $blockedActors = null,
		?BlockRepository $blockRepository = null,
		?FollowPolicy $followPolicy = null,
		?AttachmentRepository $attachmentRepository = null,
		?BlobStore $blobStore = null,
		?ImageProcessor $imageProcessor = null,
		?HostResolver $hostResolver = null,
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

		$posts = new InMemoryPostRepository();
		$followers = new InMemoryFollowerRepository();
		$followingRepository ??= new InMemoryFollowingRepository();
		$remotePostRepository ??= new InMemoryRemotePostRepository();
		$blockRepository ??= new InMemoryBlockRepository();
		$attachmentRepository ??= new InMemoryAttachmentRepository();
		$blobStore ??= new InMemoryBlobStore();
		$imageProcessor ??= new GdImageProcessor();
		$homeTimeline = new InMemoryHomeTimelineRepository(
			$posts,
			$remotePostRepository,
			$followingRepository,
			$blockRepository,
		);

		if ($inboxResolver === null) {
			$inboxResolver = ($httpClient !== null && $signingKey !== null)
				? new FollowerInboxResolver($followers)
				: new EmptyInboxResolver();
		}

		$queue = new InMemoryJobQueue($clock, $ids);

		return self::assemble(
			clock: $clock,
			config: $config,
			posts: $posts,
			followers: $followers,
			followingRepository: $followingRepository,
			remotePosts: $remotePostRepository,
			homeTimeline: $homeTimeline,
			blockRepository: $blockRepository,
			blockedActors: $blockedActors,
			followPolicy: $followPolicy,
			attachmentRepository: $attachmentRepository,
			blobStore: $blobStore,
			imageProcessor: $imageProcessor,
			hostResolver: $hostResolver,
			inboxResolver: $inboxResolver,
			httpClient: $httpClient,
			signingKey: $signingKey,
			actorResolver: $actorResolver,
			activityLog: $activityLog,
			localActors: $localActors,
			queue: $queue,
			ids: $ids,
			logger: $logger,
			debug: $debug,
		);
	}

	/**
	 * The production assembly for a host.
	 *
	 * The host supplies its ports and a durable queue and activity log; the
	 * repositories are the SQL adapters. A host that cannot provide the actor
	 * resolver still gets the local API and outbound delivery, while the inbox
	 * honestly answers 503.
	 *
	 * The queue and activity log are required rather than defaulted: an
	 * in-memory retry queue or replay guard loses work when the process
	 * restarts, and a production host should have to choose them explicitly.
	 */
	public static function forHost(
		Config $config,
		Connection $database,
		HttpClient $httpClient,
		RsaKeyPair $signingKey,
		LocalActorDirectory $localActors,
		JobQueue $queue,
		ActivityLog $activityLog,
		?ActorResolver $actorResolver = null,
		?InboxResolver $inboxResolver = null,
		?Clock $clock = null,
		?LoggerInterface $logger = null,
		bool $debug = false,
		?BlockedActorPolicy $blockedActors = null,
		?BlockRepository $blockRepository = null,
		?FollowPolicy $followPolicy = null,
		?AttachmentRepository $attachmentRepository = null,
		?BlobStore $blobStore = null,
		?ImageProcessor $imageProcessor = null,
		?HostResolver $hostResolver = null,
	): self {
		$clock ??= new SystemClock();
		$logger ??= new NullLogger();
		$ids = new RandomIdGenerator();

		$followers = new PdoFollowerRepository($database);
		$inboxResolver ??= new FollowerInboxResolver($followers);
		$remotePosts = new PdoRemotePostRepository($database);
		$blockRepository ??= new PdoBlockRepository($database);

		return self::assemble(
			clock: $clock,
			config: $config,
			posts: new PdoPostRepository($database),
			followers: $followers,
			followingRepository: new PdoFollowingRepository($database),
			remotePosts: $remotePosts,
			homeTimeline: new PdoHomeTimelineRepository($database),
			blockRepository: $blockRepository,
			blockedActors: $blockedActors,
			followPolicy: $followPolicy,
			attachmentRepository: $attachmentRepository ?? new PdoAttachmentRepository($database),
			blobStore: $blobStore,
			imageProcessor: $imageProcessor ?? new GdImageProcessor(),
			hostResolver: $hostResolver,
			inboxResolver: $inboxResolver,
			httpClient: $httpClient,
			signingKey: $signingKey,
			actorResolver: $actorResolver,
			activityLog: $activityLog,
			localActors: $localActors,
			queue: $queue,
			ids: $ids,
			logger: $logger,
			debug: $debug,
		);
	}

	/**
	 * @param PostRepository       $posts
	 * @param FollowerRepository   $followers
	 * @param RemotePostRepository $remotePosts
	 */
	private static function assemble(
		Clock $clock,
		Config $config,
		PostRepository $posts,
		FollowerRepository $followers,
		FollowingRepository $followingRepository,
		RemotePostRepository $remotePosts,
		HomeTimelineRepository $homeTimeline,
		BlockRepository $blockRepository,
		?BlockedActorPolicy $blockedActors,
		?FollowPolicy $followPolicy,
		AttachmentRepository $attachmentRepository,
		?BlobStore $blobStore,
		ImageProcessor $imageProcessor,
		?HostResolver $hostResolver,
		InboxResolver $inboxResolver,
		?HttpClient $httpClient,
		?RsaKeyPair $signingKey,
		?ActorResolver $actorResolver,
		ActivityLog $activityLog,
		?LocalActorDirectory $localActors,
		JobQueue $queue,
		IdGenerator $ids,
		?LoggerInterface $logger,
		bool $debug,
	): self {
		// A delivery target requires something that can sign and something
		// that can reach the peer. Failing here beats queueing a job that can
		// never be delivered.
		if (!$inboxResolver instanceof EmptyInboxResolver && ($httpClient === null || $signingKey === null)) {
			throw new InvalidArgumentException(
				'A delivery target requires both an HTTP client and a signing key.',
			);
		}

		$logger ??= new NullLogger();
		$activities = new ActivityFactory($config);
		$remotePostFactory = new RemotePostFactory($clock);

		$mediaService = $blobStore === null
			? null
			: new MediaService(
				attachments: $attachmentRepository,
				posts: $posts,
				blobs: $blobStore,
				images: $imageProcessor,
				config: $config,
				clock: $clock,
				ids: $ids,
				remotePosts: $remotePosts,
			);

		$postService = new PostService(
			$posts,
			$attachmentRepository,
			$queue,
			$inboxResolver,
			$activities,
			$clock,
			$ids,
		);
		$timelines = new TimelineService($posts, $homeTimeline);
		$followerService = new FollowerService($followers, $clock, $ids);
		$blockService = new BlockService($blockRepository, $clock, $ids);
		$federationDelivery = ($httpClient !== null && $signingKey !== null)
			? new FederationDeliveryHandler($httpClient, $signingKey, $clock)
			: null;
		$followingService = new FollowingService(
			$followingRepository,
			$clock,
			$ids,
			$activities,
			$federationDelivery,
		);
		$instance = new InstanceInfo($config);
		$blockedActors ??= new NoBlockedActors();
		$followPolicy ??= new AcceptAllFollows();

		// Remote media is wired only when the host provided the bounded HTTP
		// capability. A plain HttpClient is enough for delivery and actor
		// resolution, but not for fetching untrusted image bytes.
		$remoteMedia = ($mediaService !== null && $httpClient instanceof BoundedHttpClient)
			? new RemoteMediaService(
				new RemoteMediaFetcher(
					$httpClient,
					$hostResolver ?? new DnsHostResolver(),
					$config,
				),
				$mediaService,
				$remotePosts,
				$logger,
			)
			: null;

		// The backfill uses the same media importer as the inbox. This lets a
		// later run repair historical posts whose media was disabled or failed
		// without creating a second post row.
		$backfill = $httpClient === null
			? null
			: new RemoteOutboxBackfill(
				$httpClient,
				$remotePosts,
				$remotePostFactory,
				$followingRepository,
				$blockedActors,
				$remoteMedia,
				$logger,
			);

		// Inbound federation needs a way to resolve a key id, a client to
		// reach the peer and a key to sign the Accept. Missing any of them
		// leaves the endpoint honestly at 503 rather than half-working.
		$inboxService = ($actorResolver !== null && $httpClient !== null && $signingKey !== null)
			? new InboxService(
				$actorResolver,
				$followerService,
				$followingService,
				$remotePosts,
				$activityLog,
				$activities,
				$remotePostFactory,
				$queue,
				$clock,
				$blockedActors,
				$followPolicy,
				$remoteMedia,
			)
			: null;

		// An actor document needs a key to publish and a directory to answer
		// whether a handle exists. Missing either keeps the routes at 503.
		$actorDocuments = ($signingKey !== null && $localActors !== null)
			? new ActorDocumentService($activities, $signingKey, $localActors, $followerService, $followingService)
			: null;

		// The outbox reads the same post repository the local API writes.
		// It is enabled with the rest of the actor surface, so an instance
		// that cannot publish its document does not advertise half an actor.
		$actorOutbox = ($signingKey !== null && $localActors !== null)
			? new ActorOutboxService($activities, $localActors, $posts)
			: null;

		// WebFinger maps a handle to the document route. It is gated like the
		// document itself: if that route would answer 503, resolving to it
		// would only hand a peer a dead URL.
		$webFinger = ($signingKey !== null && $localActors !== null)
			? new WebFingerService($activities, $localActors)
			: null;

		$handlers = (new HandlerMap())
			->with(HealthHandler::class, static fn (): HealthHandler => new HealthHandler())
			->with(InstanceHandler::class, static fn (): InstanceHandler => new InstanceHandler($instance))
			->with(CreatePostHandler::class, static fn (): CreatePostHandler => new CreatePostHandler($postService, $mediaService))
			->with(ShowPostHandler::class, static fn (): ShowPostHandler => new ShowPostHandler($postService, $mediaService))
			->with(UploadMediaHandler::class, static fn (): UploadMediaHandler => new UploadMediaHandler($mediaService))
			->with(ShowMediaHandler::class, static fn (): ShowMediaHandler => new ShowMediaHandler($mediaService))
			->with(DeletePostHandler::class, static fn (): DeletePostHandler => new DeletePostHandler($postService))
			->with(PublicTimelineHandler::class, static fn (): PublicTimelineHandler => new PublicTimelineHandler($timelines, $mediaService))
			->with(HomeTimelineHandler::class, static fn (): HomeTimelineHandler => new HomeTimelineHandler($timelines, $mediaService))
			->with(FollowersHandler::class, static fn (): FollowersHandler => new FollowersHandler($followerService))
			->with(FollowHandler::class, static fn (): FollowHandler => new FollowHandler($followerService))
			->with(BlocksHandler::class, static fn (): BlocksHandler => new BlocksHandler($blockService))
			->with(BlockActorHandler::class, static fn (): BlockActorHandler => new BlockActorHandler($blockService))
			->with(UnblockActorHandler::class, static fn (): UnblockActorHandler => new UnblockActorHandler($blockService))
			->with(UnfollowHandler::class, static fn (): UnfollowHandler => new UnfollowHandler($followerService))
			->with(FollowRemoteActorHandler::class, static fn (): FollowRemoteActorHandler => new FollowRemoteActorHandler($followingService))
			->with(UnfollowRemoteActorHandler::class, static fn (): UnfollowRemoteActorHandler => new UnfollowRemoteActorHandler($followingService))
			->with(InboxHandler::class, static fn (): InboxHandler => new InboxHandler($inboxService))
			->with(ActorHandler::class, static fn (): ActorHandler => new ActorHandler($actorDocuments))
			->with(FollowersCollectionHandler::class, static fn (): FollowersCollectionHandler => new FollowersCollectionHandler($actorDocuments))
			->with(FollowingCollectionHandler::class, static fn (): FollowingCollectionHandler => new FollowingCollectionHandler($actorDocuments))
			->with(OutboxHandler::class, static fn (): OutboxHandler => new OutboxHandler($actorOutbox))
			->with(WebFingerHandler::class, static fn (): WebFingerHandler => new WebFingerHandler($webFinger));

		$jobHandlers = new JobHandlerRegistry();
		if ($federationDelivery !== null) {
			$jobHandlers = $jobHandlers->with(JobType::FEDERATION_DELIVER, $federationDelivery);
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
			$followingService,
			$blockService,
			$backfill,
			$mediaService,
		);
	}

	public function handle(Request $request): Response
	{
		return $this->kernel->handle($request);
	}
}
