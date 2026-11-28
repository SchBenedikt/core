<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

use AlohaSocial\Core\Api\ActorHandler;
use AlohaSocial\Core\Api\BlockActorHandler;
use AlohaSocial\Core\Api\BoostPostHandler;
use AlohaSocial\Core\Api\BlocksHandler;
use AlohaSocial\Core\Api\ClearNotificationsHandler;
use AlohaSocial\Core\Api\ConversationMuteStatusHandler;
use AlohaSocial\Core\Api\DismissConversationHandler;
use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\CreateFilterHandler;
use AlohaSocial\Core\Api\CreateRecommendationFeedbackHandler;
use AlohaSocial\Core\Api\CreateRecommendationSignalHandler;
use AlohaSocial\Core\Api\CreateScheduledPostHandler;
use AlohaSocial\Core\Api\CreateStoryHandler;
use AlohaSocial\Core\Api\CreateStoryReactionHandler;
use AlohaSocial\Core\Api\CreateStoryReplyHandler;
use AlohaSocial\Core\Api\AccountDeletionStatusHandler;
use AlohaSocial\Core\Api\AccountStatusesHandler;
use AlohaSocial\Core\Api\AccountMoveStatusHandler;
use AlohaSocial\Core\Api\DeleteAccountDataHandler;
use AlohaSocial\Core\Api\DeleteStoryHandler;
use AlohaSocial\Core\Api\ExportAccountDataHandler;
use AlohaSocial\Core\Api\RecordStoryViewHandler;
use AlohaSocial\Core\Api\ShowStoryHandler;
use AlohaSocial\Core\Api\ShowStoryMediaHandler;
use AlohaSocial\Core\Api\StoriesHandler;
use AlohaSocial\Core\Api\StoryReactionsHandler;
use AlohaSocial\Core\Api\StoryRepliesHandler;
use AlohaSocial\Core\Api\StoryViewersHandler;
use AlohaSocial\Core\Api\DeleteRecommendationDataHandler;
use AlohaSocial\Core\Api\ExportRecommendationDataHandler;
use AlohaSocial\Core\Api\GetRecommendationPreferencesHandler;
use AlohaSocial\Core\Api\GetRecommendationsHandler;
use AlohaSocial\Core\Api\UpdateRecommendationPreferencesHandler;
use AlohaSocial\Core\Api\DeleteFilterHandler;
use AlohaSocial\Core\Api\FiltersHandler;
use AlohaSocial\Core\Api\DeleteScheduledPostHandler;
use AlohaSocial\Core\Api\ScheduledPostsHandler;
use AlohaSocial\Core\Api\ShowFilterHandler;
use AlohaSocial\Core\Api\ShowScheduledPostHandler;
use AlohaSocial\Core\Api\RemotePollHandler;
use AlohaSocial\Core\Api\UpdateFilterHandler;
use AlohaSocial\Core\Api\VoteRemotePollHandler;
use AlohaSocial\Core\Api\QuoteAuthorizationHandler;
use AlohaSocial\Core\Api\RevokeQuoteAuthorizationHandler;
use AlohaSocial\Core\Api\UpdateScheduledPostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\DismissNotificationHandler;
use AlohaSocial\Core\Api\FollowedHashtagTimelineHandler;
use AlohaSocial\Core\Api\FollowedTagsHandler;
use AlohaSocial\Core\Api\AcceptFollowRequestHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowRequestsHandler;
use AlohaSocial\Core\Api\RejectFollowRequestHandler;
use AlohaSocial\Core\Api\FollowHashtagHandler;
use AlohaSocial\Core\Api\FollowLocalAccountHandler;
use AlohaSocial\Core\Api\FollowRemoteActorHandler;
use AlohaSocial\Core\Api\FollowersCollectionHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\FollowingCollectionHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\PdsXrpcHandler;
use AlohaSocial\Core\Api\HashtagTimelineHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\DirectTimelineHandler;
use AlohaSocial\Core\Api\MarkConversationReadHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\LikeRemotePostHandler;
use AlohaSocial\Core\Api\NotificationUnreadCountHandler;
use AlohaSocial\Core\Api\MuteConversationHandler;
use AlohaSocial\Core\Api\NotificationPolicyHandler;
use AlohaSocial\Core\Api\NotificationRequestActionHandler;
use AlohaSocial\Core\Api\NotificationRequestsHandler;
use AlohaSocial\Core\Api\NotificationsHandler;
use AlohaSocial\Core\Api\UnmuteConversationHandler;
use AlohaSocial\Core\Api\OutboxHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountFollowersHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountFollowingHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountLookupHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountStatusesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAddListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonBookmarkStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonBookmarksHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonClearNotificationsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteMediaHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFiltersHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonShowFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonScheduledStatusesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDismissNotificationHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFollowAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonInstanceHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMediaUploadHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMuteAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMutesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonNotificationUnreadCountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonNotificationsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonPollHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonPublicTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonHashtagTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonHomeTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonReblogStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonRelationshipsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFollowRequestsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAuthorizeFollowRequestHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonRejectFollowRequestHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonRemoveListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonSearchHandler;
use AlohaSocial\Core\Api\SearchHandler;
use AlohaSocial\Core\Api\TrendsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonStatusContextHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonTranslateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonTranslationLanguagesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnfollowAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnmuteAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnreblogStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnbookmarkStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnfavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateMediaHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonVerifyCredentialsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonVotePollHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\PublishAtprotoHandler;
use AlohaSocial\Core\Api\ReactToRemotePostHandler;
use AlohaSocial\Core\Api\ShowRemotePostHandler;
use AlohaSocial\Core\Api\ShowAtprotoPublicationHandler;
use AlohaSocial\Core\Api\ShowLocalAccountHandler;
use AlohaSocial\Core\Api\ShowMediaHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\SubmitReportHandler;
use AlohaSocial\Core\Api\StartAccountMoveHandler;
use AlohaSocial\Core\Api\UpdatePostHandler;
use AlohaSocial\Core\Api\ShowRemoteThreadHandler;
use AlohaSocial\Core\Api\UnblockActorHandler;
use AlohaSocial\Core\Api\UnboostPostHandler;
use AlohaSocial\Core\Api\UnlikeRemotePostHandler;
use AlohaSocial\Core\Api\UnreactFromRemotePostHandler;
use AlohaSocial\Core\Api\UploadMediaHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Api\UnfollowHashtagHandler;
use AlohaSocial\Core\Api\UnfollowLocalAccountHandler;
use AlohaSocial\Core\Api\UnfollowRemoteActorHandler;
use AlohaSocial\Core\Api\WebFingerHandler;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Account\AccountDataRepository;
use AlohaSocial\Core\Domain\Account\PdoAccountDataRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\AtprotoBlobUrlBuilder;
use AlohaSocial\Core\Domain\Atproto\AtprotoDeleter;
use AlohaSocial\Core\Domain\Atproto\AllowAllAtprotoContent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventSource;
use AlohaSocial\Core\Domain\Atproto\AtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoModerationPolicy;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInboundRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoInteractionTargetPolicy;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\AtprotoPublisher;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoPublicationRepository;
use AlohaSocial\Core\Domain\Atproto\PdoAtprotoInteractionRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\InMemoryPdsRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsConfig;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsDirectory;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRepository;
use AlohaSocial\Core\Domain\Federation\AcceptAllFollows;
use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
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
use AlohaSocial\Core\Domain\Federation\InboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryInboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\PdoInboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowerRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\LocalActorSearch;
use AlohaSocial\Core\Domain\Federation\MentionResolver;
use AlohaSocial\Core\Domain\Federation\MuteRepository;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationSnapshotCache;
use AlohaSocial\Core\Domain\Recommendation\PdoRecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\PdoRecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\RecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSnapshotCache;
use AlohaSocial\Core\Domain\Story\InMemoryStoryRepository;
use AlohaSocial\Core\Domain\Story\PdoStoryRepository;
use AlohaSocial\Core\Domain\Story\StoryRepository;
use AlohaSocial\Core\Domain\Filter\FilterRuleRepository;
use AlohaSocial\Core\Domain\Filter\InMemoryFilterRuleRepository;
use AlohaSocial\Core\Domain\Filter\PdoFilterRuleRepository;
use AlohaSocial\Core\Domain\Federation\NoBlockedActors;
use AlohaSocial\Core\Domain\Federation\PdoBlockRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowerRepository;
use AlohaSocial\Core\Domain\Federation\PdoAccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\PdoFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryAccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\AccountMoveRepository;
use AlohaSocial\Core\Domain\Federation\RemoteActorFetcher;
use AlohaSocial\Core\Domain\Federation\PdoMuteRepository;
use AlohaSocial\Core\Domain\Federation\PdoRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryPendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\PdoPendingRemoteUpdateStore;
use AlohaSocial\Core\Domain\Federation\PdoRemotePollRepository;
use AlohaSocial\Core\Domain\Federation\PdoRemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\PdoRemoteThreadRepository;
use AlohaSocial\Core\Domain\Federation\RemotePostImporter;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Federation\RemoteThreadRepository;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Media\PdoAttachmentRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\ConversationMuteRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryConversationMuteRepository;
use AlohaSocial\Core\Domain\Conversation\ConversationMarkerRepository;
use AlohaSocial\Core\Domain\Conversation\InMemoryConversationMarkerRepository;
use AlohaSocial\Core\Domain\Conversation\PdoConversationMarkerRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationSenderRepository;
use AlohaSocial\Core\Domain\Notification\NotificationEventSink;
use AlohaSocial\Core\Domain\Notification\NotificationPolicy;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\NotificationRelationshipReader;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Notification\NotificationSenderAge;
use AlohaSocial\Core\Domain\Notification\NotificationSenderRepository;
use AlohaSocial\Core\Domain\Notification\NotificationThreadResolver;
use AlohaSocial\Core\Domain\Notification\NullNotificationEventSink;
use AlohaSocial\Core\Domain\Notification\NullNotificationRelationships;
use AlohaSocial\Core\Domain\Notification\PdoConversationMuteRepository;
use AlohaSocial\Core\Domain\Notification\PdoNotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\PdoNotificationSenderRepository;
use AlohaSocial\Core\Domain\Notification\UnknownNotificationSenderAge;
use AlohaSocial\Core\Domain\Notification\PdoNotificationRepository;
use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Interaction\InMemoryReactionRepository;
use AlohaSocial\Core\Domain\Interaction\PdoReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionRepository;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryBookmarkRepository;
use AlohaSocial\Core\Domain\Post\InMemoryBoostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPollVoteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\PdoBookmarkRepository;
use AlohaSocial\Core\Domain\Post\PdoBoostRepository;
use AlohaSocial\Core\Domain\Post\PdoFavouriteRepository;
use AlohaSocial\Core\Domain\Post\PdoPollVoteRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRevisionRepository;
use AlohaSocial\Core\Domain\Post\InMemoryQuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\PdoQuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\QuoteGrantRepository;
use AlohaSocial\Core\Domain\Post\PdoPostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PollVoteCounter;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Moderation\HeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\InMemoryHeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\InMemoryModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\InMemoryReportRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\NoPostHolds;
use AlohaSocial\Core\Domain\Moderation\PdoHeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\PdoModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\PdoReportRepository;
use AlohaSocial\Core\Domain\Moderation\PostHoldPolicy;
use AlohaSocial\Core\Domain\Moderation\ReportRepository;
use AlohaSocial\Core\Domain\Moderation\StoredModerationPolicy;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostSearchRepository;
use AlohaSocial\Core\Domain\Search\InMemorySearchIndex;
use AlohaSocial\Core\Domain\Search\InMemoryTrendRepository;
use AlohaSocial\Core\Domain\Search\PdoSearchIndex;
use AlohaSocial\Core\Domain\Search\PdoTrendRepository;
use AlohaSocial\Core\Domain\Search\RemoteActorSearch;
use AlohaSocial\Core\Domain\Search\SearchIndex;
use AlohaSocial\Core\Domain\Search\TrendRepository;
use AlohaSocial\Core\Domain\Scheduling\InMemoryScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\PdoScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostRepository;
use AlohaSocial\Core\Domain\Timeline\AccountListRepository;
use AlohaSocial\Core\Domain\Timeline\FollowedHashtagRepository;
use AlohaSocial\Core\Domain\Timeline\HomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryAccountListRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryFollowedHashtagRepository;
use AlohaSocial\Core\Domain\Timeline\InMemoryHomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\LocalFollowHomeTimelineRepository;
use AlohaSocial\Core\Domain\Timeline\PdoAccountListRepository;
use AlohaSocial\Core\Domain\Timeline\PdoFollowedHashtagRepository;
use AlohaSocial\Core\Domain\Timeline\PdoHomeTimelineRepository;
use AlohaSocial\Core\Api\ActorCollectionsHandler;
use AlohaSocial\Core\Api\ActorFeaturedHandler;
use AlohaSocial\Core\Api\AddCollectionItemHandler;
use AlohaSocial\Core\Api\ArchivePostHandler;
use AlohaSocial\Core\Api\ArchivedPostsHandler;
use AlohaSocial\Core\Api\CollectionsHandler;
use AlohaSocial\Core\Api\CreateCollectionHandler;
use AlohaSocial\Core\Api\DeleteCollectionHandler;
use AlohaSocial\Core\Api\PinsHandler;
use AlohaSocial\Core\Api\PinPostHandler;
use AlohaSocial\Core\Api\RemoveCollectionItemHandler;
use AlohaSocial\Core\Api\ShowCollectionHandler;
use AlohaSocial\Core\Api\UnarchivePostHandler;
use AlohaSocial\Core\Api\UnpinPostHandler;
use AlohaSocial\Core\Api\UpdateCollectionHandler;
use AlohaSocial\Core\Domain\Collection\InMemoryMediaCollectionRepository;
use AlohaSocial\Core\Domain\Collection\MediaCollectionRepository;
use AlohaSocial\Core\Domain\Collection\PdoMediaCollectionRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostPinRepository;
use AlohaSocial\Core\Domain\Post\PdoPostPinRepository;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Domain\Translation\TranslationProvider;
use AlohaSocial\Core\Service\Collection\MediaCollectionService;
use AlohaSocial\Core\Service\PinService;
use AlohaSocial\Core\Http\HandlerMap;
use AlohaSocial\Core\Http\Kernel;
use AlohaSocial\Core\Http\Middleware\MastodonApiGate;
use AlohaSocial\Core\Http\Middleware\RejectDeletedAccountWrites;
use AlohaSocial\Core\Http\Middleware\RequireAuthentication;
use AlohaSocial\Core\Http\Middleware\RequireScopes;
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
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Core\Platform\Events\EventBroker;
use AlohaSocial\Core\Platform\Http\BoundedHttpClient;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Image\GdImageProcessor;
use AlohaSocial\Core\Platform\Media\MediaProbe;
use AlohaSocial\Core\Platform\Media\MediaTranscoder;
use AlohaSocial\Core\Platform\Network\DnsHostResolver;
use AlohaSocial\Core\Platform\Network\HostResolver;
use AlohaSocial\Core\Platform\Image\ImageProcessor;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\GuardedActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorInboxResolver;
use AlohaSocial\Core\Protocol\ActivityPub\HttpActorResolver;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbed;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoQuoteBuilder;
use AlohaSocial\Core\Protocol\Atproto\DpopResourceProofProvider;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoBlobUploader;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoIdentityResolver;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\AtprotoTimelineReader;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoTimelineReader;
use AlohaSocial\Core\Service\Account\AccountDataService;
use AlohaSocial\Core\Service\Account\AccountErasureBlobRetryHandler;
use AlohaSocial\Core\Service\MediaBlobDeleteHandler;
use AlohaSocial\Core\Service\MediaBlobDeletion;
use AlohaSocial\Core\Service\AccountListService;
use AlohaSocial\Core\Service\Atproto\AtprotoDeleteHandler;
use AlohaSocial\Core\Service\Atproto\AtprotoInteractionService;
use AlohaSocial\Core\Service\Atproto\AtprotoProfileService;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishHandler;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;
use AlohaSocial\Core\Service\Atproto\AtprotoFollowService;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncHandler;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncService;
use AlohaSocial\Core\Service\Federation\ActorDocumentService;
use AlohaSocial\Core\Service\Federation\ActorOutboxService;
use AlohaSocial\Core\Service\Federation\ActorProfileUpdateService;
use AlohaSocial\Core\Service\Federation\FederationDeliveryHandler;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\FollowingDeliveryHandler;
use AlohaSocial\Core\Service\Federation\InboxService;
use AlohaSocial\Core\Service\Federation\RemoteFetcher;
use AlohaSocial\Core\Service\Search\SearchIndexer;
use AlohaSocial\Core\Service\Search\SearchService;
use AlohaSocial\Core\Service\Search\WebFingerRemoteActorSearch;
use AlohaSocial\Core\Service\Federation\RemoteMediaFetcher;
use AlohaSocial\Core\Service\Federation\RemoteMediaService;
use AlohaSocial\Core\Service\Federation\RemoteOutboxBackfill;
use AlohaSocial\Core\Service\Federation\RemotePollCloseHandler;
use AlohaSocial\Core\Service\Federation\RemotePollService;
use AlohaSocial\Core\Service\Federation\RemotePostAccess;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Service\Federation\RemoteThreadFetcher;
use AlohaSocial\Core\Service\Federation\RemoteThreadService;
use AlohaSocial\Core\Service\BlockService;
use AlohaSocial\Core\Service\BoostService;
use AlohaSocial\Core\Service\Cleanup\CleanupJobHandler;
use AlohaSocial\Core\Service\Cleanup\CleanupService;
use AlohaSocial\Core\Service\ConversationService;
use AlohaSocial\Core\Service\Federation\WebFingerService;
use AlohaSocial\Core\Service\FollowedHashtagService;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\AccountMoveHandler;
use AlohaSocial\Core\Service\AccountMoveService;
use AlohaSocial\Core\Service\FollowRequestService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\LocalAccountService;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\Notification\NotificationPolicyService;
use AlohaSocial\Core\Service\Notification\PostNotificationThreadResolver;
use AlohaSocial\Core\Service\Notification\RepositoryNotificationRelationships;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Service\ReactionService;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\Recommendation\DefaultRecommendationRanker;
use AlohaSocial\Core\Service\Recommendation\LiveRecommendationCandidates;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;
use AlohaSocial\Core\Service\Recommendation\StoredRecommendationEligibility;
use AlohaSocial\Core\Service\Story\StoryAccess;
use AlohaSocial\Core\Service\Story\StoryExpireHandler;
use AlohaSocial\Core\Service\Story\StoryService;
use AlohaSocial\Core\Service\Mastodon\MastodonFilterPresenter;
use AlohaSocial\Core\Service\Mastodon\MastodonScheduledStatusPresenter;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Service\Scheduling\ScheduledPostPublishHandler;
use AlohaSocial\Core\Service\Mastodon\MastodonPresenter;
use AlohaSocial\Core\Service\Mastodon\MastodonService;
use AlohaSocial\Core\Service\MediaAccess;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Platform\Moderation\AdminAuthorization;
use AlohaSocial\Core\Platform\Moderation\NoAdministrators;
use AlohaSocial\Core\Service\Moderation\HeldPostService;
use AlohaSocial\Core\Service\Moderation\ModerationService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\QuoteService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Service\Translation\TranslationService;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Security\SecretBox;
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
		public ?RemoteThreadService $threads,
		public ?AtprotoPublishService $atproto = null,
		public ?MastodonService $mastodon = null,
		public ?CleanupService $cleanup = null,
		public ?AtprotoInteractionService $atprotoInteractions = null,
		public ?AtprotoProfileService $atprotoProfiles = null,
		public ?AtprotoTimelineReader $atprotoTimeline = null,
		public ?ModerationService $moderation = null,
		public ?HeldPostService $heldPosts = null,
		public ?NotificationService $notifications = null,
		public ?SearchService $search = null,
		public ?FollowRequestService $followRequests = null,
		public ?ActorProfileUpdateService $profileUpdates = null,
		public ?AccountMoveService $accountMoves = null,
		public ?AtprotoSyncService $atprotoSync = null,
		public ?RecommendationService $recommendations = null,
		public ?StoryService $stories = null,
		public ?PinService $pins = null,
		public ?MediaCollectionService $collections = null,
		public ?NativePdsService $pds = null,
		public ?AccountDataService $accountData = null,
		public ?AtprotoFollowService $atprotoFollows = null,
		public ?ReactionService $reactions = null,
		public ?BoostService $boosts = null,
		public ?EventBroker $eventBroker = null,
	) {
	}

	public static function inMemory(
		?Clock $clock = null,
		?Config $config = null,
		?HttpClient $httpClient = null,
		?RsaKeyPair $signingKey = null,
		?InboxResolver $inboxResolver = null,
		?ActorResolver $actorResolver = null,
		?MentionResolver $mentionResolver = null,
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
		?FavouriteRepository $favouriteRepository = null,
		?ReactionRepository $reactionRepository = null,
		?PollVoteRepository $pollVoteRepository = null,
		?BlobStore $blobStore = null,
		?ImageProcessor $imageProcessor = null,
		?HostResolver $hostResolver = null,
		?RemoteThreadRepository $remoteThreadRepository = null,
		?AtprotoAccountResolver $atprotoAccounts = null,
		?AtprotoPublisher $atprotoPublisher = null,
		?AtprotoPublicationRepository $atprotoPublications = null,
		?NotificationRepository $notificationRepository = null,
		?NotificationPolicyRepository $notificationPolicies = null,
		?NotificationSenderRepository $notificationSenders = null,
		?ConversationMuteRepository $conversationMutes = null,
		?NotificationRelationshipReader $notificationRelationships = null,
		?NotificationSenderAge $notificationSenderAge = null,
		?NotificationEventSink $notificationEventSink = null,
		?NotificationThreadResolver $notificationThreadResolver = null,
		?NotificationPolicy $notificationDefaultPolicy = null,
		?BoostRepository $boostRepository = null,
		?BookmarkRepository $bookmarkRepository = null,
		?MuteRepository $muteRepository = null,
		?AccountListRepository $accountListRepository = null,
		?FollowedHashtagRepository $followedHashtagRepository = null,
		?PostRevisionRepository $postRevisions = null,
		?PendingRemoteUpdateStore $pendingRemoteUpdates = null,
		?DpopResourceProofProvider $atprotoDpop = null,
		?ScheduledPostRepository $scheduledPostRepository = null,
		?FilterRuleRepository $filterRuleRepository = null,
		?RemotePollRepository $remotePollRepository = null,
		?RemotePollVoteRepository $remotePollVoteRepository = null,
		?QuoteGrantRepository $quoteGrantRepository = null,
		?MediaProbe $mediaProbe = null,
		?MediaTranscoder $mediaTranscoder = null,
		?ReportRepository $reportRepository = null,
		?ModerationActionRepository $moderationActions = null,
		?HeldPostRepository $heldPostRepository = null,
		?AdminAuthorization $adminAuthorization = null,
		?PostHoldPolicy $postHoldPolicy = null,
		?SearchIndex $searchIndex = null,
		?TrendRepository $trends = null,
		?RemoteActorSearch $remoteActorSearch = null,
		?SearchService $search = null,
		?LocalActorProfileProvider $localProfiles = null,
		?FollowRequestService $followRequests = null,
		?ActorProfileUpdateService $profileUpdates = null,
		?AccountMoveRepository $accountMoveRepository = null,
		?RemoteActorFetcher $remoteActors = null,
		?ActorInboxResolver $actorInboxResolver = null,
		?RemotePostImporter $remotePostImporter = null,
		?AtprotoEventSource $atprotoEventSource = null,
		?AtprotoInboundRepository $atprotoInbound = null,
		?AtprotoModerationPolicy $atprotoModeration = null,
		?AtprotoBlobUrlBuilder $atprotoBlobs = null,
		?RecommendationRepository $recommendationRepository = null,
		?RecommendationCandidateSource $recommendationCandidates = null,
		?RecommendationSnapshotCache $recommendationSnapshots = null,
		?RecommendationService $recommendationService = null,
		?StoryRepository $storyRepository = null,
		?StoryService $storyService = null,
		?PostPinRepository $pinRepository = null,
		?MediaCollectionRepository $mediaCollectionRepository = null,
		?PinService $pinService = null,
		?MediaCollectionService $mediaCollectionService = null,
		?PdsRepository $pdsRepository = null,
		?SecretBox $pdsSecrets = null,
		?PdsDirectory $pdsDirectory = null,
		?InboundBlockRepository $inboundBlocks = null,
		?AtprotoFollowRepository $atprotoFollowRepository = null,
		?AtprotoInteractionRepository $atprotoInteractionRepository = null,
		?EventBroker $eventBroker = null,
		?ConversationMarkerRepository $conversationMarkers = null,
		?TranslationProvider $translationProvider = null,
	): self {
		$clock ??= new SystemClock();
		$config ??= new ArrayConfig([
			InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'instance.name' => 'Aloha Social',
			],
		]);
		$blobStore ??= new InMemoryBlobStore();
		$pds = PdsConfig::enabled($config)
			? self::assemblePds($config, $clock, $pdsRepository ?? new InMemoryPdsRepository(), $pdsSecrets, $blobStore, $pdsDirectory)
			: null;
		$logger ??= new NullLogger();
		$activityLog ??= new InMemoryActivityLog();
		$accountMoveRepository ??= new InMemoryAccountMoveRepository();
		$ids = new RandomIdGenerator();

		$posts = new InMemoryPostRepository();
		$followers = new InMemoryFollowerRepository();
		$followingRepository ??= new InMemoryFollowingRepository();
		$remotePostRepository ??= new InMemoryRemotePostRepository();
		$remoteThreadRepository ??= new InMemoryRemoteThreadRepository();
		$atprotoPublications ??= new InMemoryAtprotoPublicationRepository();
		$atprotoInteractionRepository ??= new InMemoryAtprotoInteractionRepository();
		$atprotoPublisher ??= $httpClient === null ? null : new XrpcAtprotoPublisher($httpClient, $atprotoDpop);
		$blockRepository ??= new InMemoryBlockRepository();
		$attachmentRepository ??= new InMemoryAttachmentRepository($posts, $remotePostRepository);
		$favouriteRepository ??= new InMemoryFavouriteRepository();
		$reactionRepository ??= new InMemoryReactionRepository();
		$pollVoteRepository ??= new InMemoryPollVoteRepository(
			$posts instanceof PollVoteCounter ? $posts : null,
		);
		$notificationRepository ??= new InMemoryNotificationRepository();
		$boostRepository ??= new InMemoryBoostRepository();
		$bookmarkRepository ??= new InMemoryBookmarkRepository();
		$muteRepository ??= new InMemoryMuteRepository();
		$accountListRepository ??= new InMemoryAccountListRepository();
		$followedHashtagRepository ??= new InMemoryFollowedHashtagRepository();
		$postRevisions ??= new InMemoryPostRevisionRepository();
		$pendingRemoteUpdates ??= new InMemoryPendingRemoteUpdateStore();
		$scheduledPostRepository ??= new InMemoryScheduledPostRepository();
		$filterRuleRepository ??= new InMemoryFilterRuleRepository();
		$remotePollRepository ??= new InMemoryRemotePollRepository();
		$remotePollVoteRepository ??= new InMemoryRemotePollVoteRepository();
		$quoteGrantRepository ??= new InMemoryQuoteGrantRepository();
		$imageProcessor ??= new GdImageProcessor();
		$homeTimeline = new InMemoryHomeTimelineRepository(
			$posts,
			$remotePostRepository,
			$followingRepository,
			$blockRepository,
			$muteRepository,
			$clock,
			$boostRepository,
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
			threadRepository: $remoteThreadRepository,
			homeTimeline: $homeTimeline,
			blockRepository: $blockRepository,
			blockedActors: $blockedActors,
			followPolicy: $followPolicy,
			attachmentRepository: $attachmentRepository,
			favouriteRepository: $favouriteRepository,
			reactionRepository: $reactionRepository,
			pollVoteRepository: $pollVoteRepository,
			notificationRepository: $notificationRepository,
			notificationPolicies: $notificationPolicies,
			notificationSenders: $notificationSenders,
			conversationMutes: $conversationMutes,
			conversationMarkers: $conversationMarkers ?? new InMemoryConversationMarkerRepository(),
			notificationRelationships: $notificationRelationships,
			notificationSenderAge: $notificationSenderAge,
			notificationEventSink: $notificationEventSink,
			notificationThreadResolver: $notificationThreadResolver,
			notificationDefaultPolicy: $notificationDefaultPolicy,
			boostRepository: $boostRepository,
			bookmarkRepository: $bookmarkRepository,
			muteRepository: $muteRepository,
			accountListRepository: $accountListRepository,
			followedHashtagRepository: $followedHashtagRepository,
			postRevisions: $postRevisions,
			pendingRemoteUpdates: $pendingRemoteUpdates,
			scheduledPostRepository: $scheduledPostRepository,
			filterRuleRepository: $filterRuleRepository,
			remotePollRepository: $remotePollRepository,
			remotePollVoteRepository: $remotePollVoteRepository,
			quoteGrantRepository: $quoteGrantRepository,
			mediaProbe: $mediaProbe,
			mediaTranscoder: $mediaTranscoder,
			reportRepository: $reportRepository,
			moderationActions: $moderationActions,
			heldPostRepository: $heldPostRepository,
			adminAuthorization: $adminAuthorization,
			postHoldPolicy: $postHoldPolicy,
			searchIndex: $searchIndex,
			trends: $trends,
			remoteActorSearch: $remoteActorSearch,
			search: $search,
			localProfiles: $localProfiles,
			followRequests: $followRequests,
			profileUpdates: $profileUpdates,
			accountMoveRepository: $accountMoveRepository,
			remoteActors: $remoteActors,
			actorInboxResolver: $actorInboxResolver,
			remotePostImporter: $remotePostImporter,
			atprotoEventSource: $atprotoEventSource,
			atprotoInbound: $atprotoInbound,
			atprotoModeration: $atprotoModeration,
			atprotoBlobs: $atprotoBlobs,
			recommendationRepository: $recommendationRepository,
			recommendationCandidates: $recommendationCandidates,
			recommendationSnapshots: $recommendationSnapshots,
			recommendationService: $recommendationService,
			storyRepository: $storyRepository,
			storyService: $storyService,
			pinRepository: $pinRepository,
			mediaCollectionRepository: $mediaCollectionRepository,
			pinService: $pinService,
			mediaCollectionService: $mediaCollectionService,
			pds: $pds,
			blobStore: $blobStore,
			imageProcessor: $imageProcessor,
			hostResolver: $hostResolver,
			atprotoPublications: $atprotoPublications,
			atprotoInteractionRepository: $atprotoInteractionRepository,
			atprotoFollowRepository: $atprotoFollowRepository ?? new InMemoryAtprotoFollowRepository(),
			eventBroker: $eventBroker,
			atprotoAccounts: $atprotoAccounts,
			atprotoPublisher: $atprotoPublisher,
			inboxResolver: $inboxResolver,
			httpClient: $httpClient,
			signingKey: $signingKey,
			actorResolver: $actorResolver,
			mentionResolver: $mentionResolver,
			activityLog: $activityLog,
			localActors: $localActors,
			queue: $queue,
			ids: $ids,
			logger: $logger,
			debug: $debug,
			atprotoDpop: $atprotoDpop,
			inboundBlocks: $inboundBlocks,
			translationProvider: $translationProvider,
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
		?MentionResolver $mentionResolver = null,
		?InboxResolver $inboxResolver = null,
		?Clock $clock = null,
		?LoggerInterface $logger = null,
		bool $debug = false,
		?BlockedActorPolicy $blockedActors = null,
		?BlockRepository $blockRepository = null,
		?FollowPolicy $followPolicy = null,
		?AttachmentRepository $attachmentRepository = null,
		?FavouriteRepository $favouriteRepository = null,
		?ReactionRepository $reactionRepository = null,
		?PollVoteRepository $pollVoteRepository = null,
		?BlobStore $blobStore = null,
		?ImageProcessor $imageProcessor = null,
		?HostResolver $hostResolver = null,
		?AtprotoAccountResolver $atprotoAccounts = null,
		?AtprotoPublisher $atprotoPublisher = null,
		?AtprotoPublicationRepository $atprotoPublications = null,
		?NotificationRepository $notificationRepository = null,
		?NotificationPolicyRepository $notificationPolicies = null,
		?NotificationSenderRepository $notificationSenders = null,
		?ConversationMuteRepository $conversationMutes = null,
		?NotificationRelationshipReader $notificationRelationships = null,
		?NotificationSenderAge $notificationSenderAge = null,
		?NotificationEventSink $notificationEventSink = null,
		?NotificationThreadResolver $notificationThreadResolver = null,
		?NotificationPolicy $notificationDefaultPolicy = null,
		?BoostRepository $boostRepository = null,
		?BookmarkRepository $bookmarkRepository = null,
		?MuteRepository $muteRepository = null,
		?AccountListRepository $accountListRepository = null,
		?FollowedHashtagRepository $followedHashtagRepository = null,
		?TransactionRunner $transactions = null,
		?PostRevisionRepository $postRevisions = null,
		?PendingRemoteUpdateStore $pendingRemoteUpdates = null,
		?DpopResourceProofProvider $atprotoDpop = null,
		?ReportRepository $reportRepository = null,
		?ModerationActionRepository $moderationActions = null,
		?HeldPostRepository $heldPostRepository = null,
		?AdminAuthorization $adminAuthorization = null,
		?PostHoldPolicy $postHoldPolicy = null,
		?SearchIndex $searchIndex = null,
		?TrendRepository $trends = null,
		?RemoteActorSearch $remoteActorSearch = null,
		?SearchService $search = null,
		?LocalActorProfileProvider $localProfiles = null,
		?FollowRequestService $followRequests = null,
		?ActorProfileUpdateService $profileUpdates = null,
		?AccountMoveRepository $accountMoveRepository = null,
		?RemoteActorFetcher $remoteActors = null,
		?ActorInboxResolver $actorInboxResolver = null,
		?RemotePostImporter $remotePostImporter = null,
		?AtprotoEventSource $atprotoEventSource = null,
		?AtprotoInboundRepository $atprotoInbound = null,
		?AtprotoModerationPolicy $atprotoModeration = null,
		?AtprotoBlobUrlBuilder $atprotoBlobs = null,
		?PdsRepository $pdsRepository = null,
		?SecretBox $pdsSecrets = null,
		?PdsDirectory $pdsDirectory = null,
		?InboundBlockRepository $inboundBlocks = null,
		?AtprotoFollowRepository $atprotoFollowRepository = null,
		?AtprotoInteractionRepository $atprotoInteractionRepository = null,
		?AtprotoInteractionTargetPolicy $atprotoRemoteTargetPolicy = null,
		?EventBroker $eventBroker = null,
		?ConversationMarkerRepository $conversationMarkers = null,
		?TranslationProvider $translationProvider = null,
	): self {
		$clock ??= new SystemClock();
		$pds = PdsConfig::enabled($config)
			? self::assemblePds($config, $clock, $pdsRepository ?? new PdoPdsRepository($database), $pdsSecrets, $blobStore, $pdsDirectory)
			: null;
		$logger ??= new NullLogger();
		$ids = new RandomIdGenerator();
		// A production host must not let a peer point delivery or actor
		// discovery at a private address. In-memory callers keep the option to
		// supply a deterministic resolver without forcing DNS on fixtures.
		$hostResolver ??= new DnsHostResolver();

		$followers = new PdoFollowerRepository($database);
		$inboxResolver ??= new FollowerInboxResolver($followers);
		$remotePosts = new PdoRemotePostRepository($database);
		$blockRepository ??= new PdoBlockRepository($database);
		$inboundBlocks ??= new PdoInboundBlockRepository($database);
		$muteRepository ??= new PdoMuteRepository($database);
		$accountListRepository ??= new PdoAccountListRepository($database);
		$followedHashtagRepository ??= new PdoFollowedHashtagRepository($database);
		$postRevisions ??= new PdoPostRevisionRepository($database);
		$pendingRemoteUpdates ??= new PdoPendingRemoteUpdateStore($database);

		return self::assemble(
			clock: $clock,
			config: $config,
			posts: new PdoPostRepository($database),
			followers: $followers,
			followingRepository: new PdoFollowingRepository($database),
			remotePosts: $remotePosts,
			threadRepository: new PdoRemoteThreadRepository($database),
			homeTimeline: new PdoHomeTimelineRepository($database, $clock),
			blockRepository: $blockRepository,
			blockedActors: $blockedActors,
			followPolicy: $followPolicy,
			attachmentRepository: $attachmentRepository ?? new PdoAttachmentRepository($database),
			favouriteRepository: $favouriteRepository ?? new PdoFavouriteRepository($database),
			reactionRepository: $reactionRepository ?? new PdoReactionRepository($database),
			pollVoteRepository: $pollVoteRepository ?? new PdoPollVoteRepository($database),
			notificationRepository: $notificationRepository ?? new PdoNotificationRepository($database),
			notificationPolicies: $notificationPolicies ?? new PdoNotificationPolicyRepository($database),
			notificationSenders: $notificationSenders ?? new PdoNotificationSenderRepository($database),
			conversationMutes: $conversationMutes ?? new PdoConversationMuteRepository($database),
			conversationMarkers: $conversationMarkers ?? new PdoConversationMarkerRepository($database),
			notificationRelationships: $notificationRelationships,
			notificationSenderAge: $notificationSenderAge,
			notificationEventSink: $notificationEventSink,
			notificationThreadResolver: $notificationThreadResolver,
			notificationDefaultPolicy: $notificationDefaultPolicy,
			boostRepository: $boostRepository ?? new PdoBoostRepository($database),
			bookmarkRepository: $bookmarkRepository ?? new PdoBookmarkRepository($database),
			muteRepository: $muteRepository,
			accountListRepository: $accountListRepository,
			followedHashtagRepository: $followedHashtagRepository,
			postRevisions: $postRevisions,
			pendingRemoteUpdates: $pendingRemoteUpdates,
			reportRepository: $reportRepository ?? new PdoReportRepository($database),
			moderationActions: $moderationActions ?? new PdoModerationActionRepository($database),
			heldPostRepository: $heldPostRepository ?? new PdoHeldPostRepository($database),
			adminAuthorization: $adminAuthorization,
			postHoldPolicy: $postHoldPolicy,
			searchIndex: $searchIndex ?? new PdoSearchIndex($database),
			trends: $trends ?? new PdoTrendRepository($database),
			remoteActorSearch: $remoteActorSearch,
			search: $search,
			localProfiles: $localProfiles,
			followRequests: $followRequests,
			profileUpdates: $profileUpdates,
			accountMoveRepository: $accountMoveRepository ?? new PdoAccountMoveRepository($database),
			remoteActors: $remoteActors,
			actorInboxResolver: $actorInboxResolver,
			remotePostImporter: $remotePostImporter,
			atprotoEventSource: $atprotoEventSource,
			atprotoInbound: $atprotoInbound ?? new PdoAtprotoInboundRepository($database),
			atprotoModeration: $atprotoModeration,
			atprotoBlobs: $atprotoBlobs,
			recommendationRepository: new PdoRecommendationRepository($database),
			recommendationCandidates: new PdoRecommendationCandidateSource($database),
			recommendationSnapshots: new InMemoryRecommendationSnapshotCache(),
			storyRepository: new PdoStoryRepository($database),
			pinRepository: new PdoPostPinRepository($database),
			mediaCollectionRepository: new PdoMediaCollectionRepository($database),
			scheduledPostRepository: $scheduledPostRepository ?? new PdoScheduledPostRepository($database),
			filterRuleRepository: $filterRuleRepository ?? new PdoFilterRuleRepository($database),
			remotePollRepository: new PdoRemotePollRepository($database),
			remotePollVoteRepository: new PdoRemotePollVoteRepository($database),
			quoteGrantRepository: new PdoQuoteGrantRepository($database),
			blobStore: $blobStore,
			imageProcessor: $imageProcessor ?? new GdImageProcessor(),
			hostResolver: $hostResolver,
			atprotoPublications: $atprotoPublications ?? new PdoAtprotoPublicationRepository($database),
			atprotoInteractionRepository: $atprotoInteractionRepository ?? new PdoAtprotoInteractionRepository($database),
			atprotoFollowRepository: $atprotoFollowRepository ?? new PdoAtprotoFollowRepository($database),
			atprotoAccounts: $atprotoAccounts,
			atprotoPublisher: $atprotoPublisher ?? new XrpcAtprotoPublisher($httpClient, $atprotoDpop),
			atprotoRemoteTargetPolicy: $atprotoRemoteTargetPolicy,
			eventBroker: $eventBroker,
			inboxResolver: $inboxResolver,
			httpClient: $httpClient,
			signingKey: $signingKey,
			actorResolver: $actorResolver,
			mentionResolver: $mentionResolver,
			activityLog: $activityLog,
			localActors: $localActors,
			queue: $queue,
			ids: $ids,
			logger: $logger,
			debug: $debug,
			transactions: $transactions ?? ($database instanceof TransactionRunner ? $database : null),
			atprotoDpop: $atprotoDpop,
			pds: $pds,
			accountDataRepository: new PdoAccountDataRepository($database),
			inboundBlocks: $inboundBlocks,
			translationProvider: $translationProvider,
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
		RemoteThreadRepository $threadRepository,
		HomeTimelineRepository $homeTimeline,
		BlockRepository $blockRepository,
		?BlockedActorPolicy $blockedActors,
		?FollowPolicy $followPolicy,
		AttachmentRepository $attachmentRepository,
		FavouriteRepository $favouriteRepository,
		ReactionRepository $reactionRepository,
		PollVoteRepository $pollVoteRepository,
		NotificationRepository $notificationRepository,
		?NotificationPolicyRepository $notificationPolicies,
		?NotificationSenderRepository $notificationSenders,
		?ConversationMuteRepository $conversationMutes,
		ConversationMarkerRepository $conversationMarkers,
		?NotificationRelationshipReader $notificationRelationships,
		?NotificationSenderAge $notificationSenderAge,
		?NotificationEventSink $notificationEventSink,
		?NotificationThreadResolver $notificationThreadResolver,
		?NotificationPolicy $notificationDefaultPolicy,
		BoostRepository $boostRepository,
		BookmarkRepository $bookmarkRepository,
		MuteRepository $muteRepository,
		AccountListRepository $accountListRepository,
		FollowedHashtagRepository $followedHashtagRepository,
		PostRevisionRepository $postRevisions,
		PendingRemoteUpdateStore $pendingRemoteUpdates,
		?BlobStore $blobStore,
		ImageProcessor $imageProcessor,
		?HostResolver $hostResolver,
		AtprotoPublicationRepository $atprotoPublications,
		AtprotoInteractionRepository $atprotoInteractionRepository,
		?AtprotoAccountResolver $atprotoAccounts,
		?AtprotoPublisher $atprotoPublisher,
		InboxResolver $inboxResolver,
		?HttpClient $httpClient,
		?RsaKeyPair $signingKey,
		?ActorResolver $actorResolver,
		?MentionResolver $mentionResolver,
		ActivityLog $activityLog,
		?LocalActorDirectory $localActors,
		JobQueue $queue,
		IdGenerator $ids,
		?LoggerInterface $logger,
		bool $debug,
		?TransactionRunner $transactions = null,
		?DpopResourceProofProvider $atprotoDpop = null,
		?ScheduledPostRepository $scheduledPostRepository = null,
		?FilterRuleRepository $filterRuleRepository = null,
		?RemotePollRepository $remotePollRepository = null,
		?RemotePollVoteRepository $remotePollVoteRepository = null,
		?QuoteGrantRepository $quoteGrantRepository = null,
		?MediaProbe $mediaProbe = null,
		?MediaTranscoder $mediaTranscoder = null,
		?ReportRepository $reportRepository = null,
		?ModerationActionRepository $moderationActions = null,
		?HeldPostRepository $heldPostRepository = null,
		?AdminAuthorization $adminAuthorization = null,
		?PostHoldPolicy $postHoldPolicy = null,
		?SearchIndex $searchIndex = null,
		?TrendRepository $trends = null,
		?RemoteActorSearch $remoteActorSearch = null,
		?SearchService $search = null,
		?LocalActorProfileProvider $localProfiles = null,
		?FollowRequestService $followRequests = null,
		?ActorProfileUpdateService $profileUpdates = null,
		?AccountMoveRepository $accountMoveRepository = null,
		?RemoteActorFetcher $remoteActors = null,
		?ActorInboxResolver $actorInboxResolver = null,
		?RemotePostImporter $remotePostImporter = null,
		?AtprotoEventSource $atprotoEventSource = null,
		?AtprotoInboundRepository $atprotoInbound = null,
		?AtprotoModerationPolicy $atprotoModeration = null,
		?AtprotoBlobUrlBuilder $atprotoBlobs = null,
		?RecommendationRepository $recommendationRepository = null,
		?RecommendationCandidateSource $recommendationCandidates = null,
		?RecommendationSnapshotCache $recommendationSnapshots = null,
		?RecommendationService $recommendationService = null,
		?StoryRepository $storyRepository = null,
		?StoryService $storyService = null,
		?PostPinRepository $pinRepository = null,
		?MediaCollectionRepository $mediaCollectionRepository = null,
		?PinService $pinService = null,
		?MediaCollectionService $mediaCollectionService = null,
		?NativePdsService $pds = null,
		?AccountDataRepository $accountDataRepository = null,
		?InboundBlockRepository $inboundBlocks = null,
		?AtprotoFollowRepository $atprotoFollowRepository = null,
		?AtprotoInteractionTargetPolicy $atprotoRemoteTargetPolicy = null,
		?EventBroker $eventBroker = null,
		?TranslationProvider $translationProvider = null,
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
		$inboundBlocks ??= new InMemoryInboundBlockRepository();
		$pinRepository ??= new InMemoryPostPinRepository();
		$mediaCollectionRepository ??= new InMemoryMediaCollectionRepository();
		$pinService ??= new PinService($posts, $pinRepository, $clock);
		$searchIndex ??= new InMemorySearchIndex();
		$trends ??= new InMemoryTrendRepository();
		$searchIndexer = new SearchIndexer($searchIndex, $trends, $clock, $logger);
		$reportRepository ??= new InMemoryReportRepository();
		$moderationActions ??= new InMemoryModerationActionRepository();
		$heldPostRepository ??= new InMemoryHeldPostRepository();
		$adminAuthorization ??= new NoAdministrators();
		$postHoldPolicy ??= new NoPostHolds();
		$notificationPolicies ??= new InMemoryNotificationPolicyRepository();
		$notificationSenders ??= new InMemoryNotificationSenderRepository();
		$conversationMutes ??= new InMemoryConversationMuteRepository();
		$notificationSenderAge ??= new UnknownNotificationSenderAge();
		$notificationEventSink ??= new NullNotificationEventSink();
		// Existing installations keep every stored interaction visible. A host
		// can opt new accounts into the protective default policy explicitly.
		$notificationDefaultPolicy ??= NotificationPolicy::legacy();
		$moderationPolicy = new StoredModerationPolicy($moderationActions);
		// Built before the activity factory: a note serializes its media, so
		// delivery and the outbox use the very same attachment urls.
		$mediaBlobDeletion = $blobStore === null ? null : new MediaBlobDeletion($blobStore, $queue, $logger);
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
				probe: $mediaProbe,
				transcoder: $mediaTranscoder,
				blobDeletion: $mediaBlobDeletion,
			);
		$activities = new ActivityFactory($config, $mediaService);
		$accountData = $accountDataRepository === null || $blobStore === null
			? null
			: new AccountDataService($accountDataRepository, $blobStore, $activities, $queue, $clock);
		$cleanup = new CleanupService(
			$attachmentRepository,
			$notificationRepository,
			$threadRepository,
			$blobStore,
			$clock,
			$logger,
			$mediaBlobDeletion,
		);
		$notificationRelationships ??= new RepositoryNotificationRelationships(
			$followers,
			$followingRepository,
			$activities,
		);
		$notificationThreadResolver ??= new PostNotificationThreadResolver($posts, $threadRepository);
		$notificationPolicyService = new NotificationPolicyService(
			$notificationPolicies,
			$notificationSenders,
			$conversationMutes,
			$notificationRelationships,
			$clock,
			$notificationSenderAge,
			$moderationPolicy,
			$notificationDefaultPolicy,
		);
		$notificationService = new NotificationService(
			$notificationRepository,
			$clock,
			$logger,
			$notificationPolicyService,
			$notificationEventSink,
			$notificationThreadResolver,
			$eventBroker,
		);
		$muteService = new MuteService($muteRepository, $clock);
		$accountListService = new AccountListService($accountListRepository, $clock, $ids);
		$followedHashtagService = new FollowedHashtagService($followedHashtagRepository, $clock);
		$remotePostFactory = new RemotePostFactory($clock);
		$targetGuard = new FederationTargetGuard($hostResolver, $moderationPolicy);
		$actorInboxResolver ??= $httpClient === null ? null : new HttpActorInboxResolver($httpClient, $targetGuard);
		if ($actorResolver !== null) {
			// Even a host-provided resolver must not be asked to fetch a
			// private actor URL on behalf of an inbound signature. Local
			// actor IDs are already owned by this instance and skip the fetch.
			$localBaseUrl = $config->get('social', 'instance.url');
			$actorResolver = new GuardedActorResolver(
				$actorResolver,
				$targetGuard,
				is_string($localBaseUrl) ? $localBaseUrl : null,
			);
		}

		// Facets and reply references need the PDS of the publishing account;
		// both adapters reuse the host's HTTP client instead of adding one.
		$atprotoService = new AtprotoPublishService(
			$config,
			$posts,
			$atprotoPublications,
			$atprotoAccounts,
			$atprotoPublisher,
			$queue,
			$clock,
			$atprotoPublisher instanceof AtprotoDeleter ? $atprotoPublisher : null,
			$httpClient === null ? null : new AtprotoFacets(new XrpcAtprotoIdentityResolver($httpClient)),
			$httpClient === null ? null : new AtprotoReplyBuilder(new XrpcAtprotoRecordResolver($httpClient)),
			$httpClient === null ? null : new AtprotoEmbed(new XrpcAtprotoBlobUploader($httpClient, $atprotoDpop)),
			$mediaService,
			$httpClient === null ? null : new AtprotoQuoteBuilder(new XrpcAtprotoRecordResolver($httpClient)),
			$activities,
		);
		$atprotoFollowService = new AtprotoFollowService(
			$config,
			$atprotoFollowRepository ?? new InMemoryAtprotoFollowRepository(),
			$atprotoAccounts,
			$atprotoPublisher,
			$atprotoPublisher instanceof AtprotoDeleter ? $atprotoPublisher : null,
			$httpClient === null ? null : new XrpcAtprotoRecordResolver($httpClient),
			$clock,
		);
		// Likes and reposts mirror only posts this instance published; the
		// subject CID is read from the PDS. Local blocks gate both directions.
		$atprotoInteractions = new AtprotoInteractionService(
			$config,
			$posts,
			$atprotoPublications,
			$atprotoAccounts,
			$atprotoPublisher,
			$httpClient === null ? null : new XrpcAtprotoRecordResolver($httpClient),
			$clock,
			$atprotoPublisher instanceof AtprotoDeleter ? $atprotoPublisher : null,
			$blockRepository,
			$activities,
			$atprotoInteractionRepository,
			$atprotoRemoteTargetPolicy,
		);
		// The profile write shares the blob uploader with post media; the
		// timeline read is a port so a host can cache or map it as it likes.
		$atprotoProfiles = new AtprotoProfileService(
			$config,
			$atprotoAccounts,
			$atprotoPublisher,
			$atprotoPublisher instanceof AtprotoDeleter ? $atprotoPublisher : null,
			$httpClient === null ? null : new XrpcAtprotoBlobUploader($httpClient, $atprotoDpop),
			$clock,
		);
		$atprotoTimeline = $httpClient === null
			? null
			: new XrpcAtprotoTimelineReader($httpClient, $atprotoDpop);
		$atprotoSync = $atprotoEventSource === null
			? null
			: new AtprotoSyncService(
				$atprotoEventSource,
				$atprotoInbound ?? new InMemoryAtprotoInboundRepository(),
				$atprotoModeration ?? new AllowAllAtprotoContent(),
				$clock,
				$transactions,
				$atprotoBlobs,
			);
		$followerService = new FollowerService($followers, $clock, $ids);
		$followRequestService = $followRequests
			?? new FollowRequestService($followers, $followerService, $activities, $queue);
		$reactionService = new ReactionService(
			$posts,
			$favouriteRepository,
			$reactionRepository,
			$followers,
			$followingRepository,
			$blockRepository,
			$activities,
			$queue,
			$clock,
			$notificationService,
			$muteRepository,
			$remotePosts,
			$inboundBlocks ?? new InMemoryInboundBlockRepository(),
			$remoteActors,
			$actorInboxResolver,
		);
		$blockService = new BlockService(
			$blockRepository,
			$clock,
			$ids,
			$queue,
			$activities,
			$actorInboxResolver,
		);
		$remotePollService = new RemotePollService(
			$remotePollRepository ?? new InMemoryRemotePollRepository(),
			$remotePollVoteRepository ?? new InMemoryRemotePollVoteRepository(),
			$pollVoteRepository,
			$posts,
			$activities,
			$queue,
			$actorInboxResolver,
			$clock,
			$inboundBlocks,
			$followers,
		);
		$quoteService = new QuoteService(
			$posts,
			$remotePosts,
			$quoteGrantRepository ?? new InMemoryQuoteGrantRepository(),
			$queue,
			$activities,
			$clock,
			$actorInboxResolver,
			$blockService,
			$inboundBlocks,
		);
		$federationDelivery = ($httpClient !== null && $signingKey !== null)
			? new FederationDeliveryHandler($httpClient, $signingKey, $clock, $targetGuard)
			: null;
		$followingService = new FollowingService(
			$followingRepository,
			$clock,
			$ids,
			$activities,
			$federationDelivery,
			$queue,
			$transactions,
			$inboundBlocks,
		);
		$remoteActors ??= $httpClient instanceof BoundedHttpClient
			? new HttpActorResolver($httpClient, $targetGuard)
			: null;
		$accountMoveService = $remoteActors === null
			? null
			: new AccountMoveService(
				$accountMoveRepository ?? new InMemoryAccountMoveRepository(),
				$followingRepository,
				$followers,
				$followingService,
				$remoteActors,
				$activities,
				$queue,
				$clock,
			);
		$instance = new InstanceInfo($config, $localActors, $posts, $remotePosts);
		$localRelationships = $localActors === null
			? null
			: new LocalRelationshipService(
				$followers,
				$followingService,
				$followerService,
				$localActors,
				$activities,
				$blockRepository,
				$notificationService,
				$muteService,
			);
		$localAccounts = new LocalAccountService(
			$localRelationships,
			$notificationService,
			$activities,
			$clock,
		);
		$postService = new PostService(
			$posts,
			$attachmentRepository,
			$queue,
			$inboxResolver,
			$activities,
			$clock,
			$ids,
			$atprotoService,
			$localRelationships,
			$notificationService,
			transactions: $transactions,
			revisions: $postRevisions,
			quotes: $quoteService,
			config: $config,
			heldPosts: $heldPostRepository,
			holdPolicy: $postHoldPolicy,
			searchIndexer: $searchIndexer,
			pins: $pinRepository,
			mentions: $mentionResolver,
			inboundBlocks: $inboundBlocks,
			accountMoves: $accountMoveRepository,
			events: $eventBroker,
			remotePosts: $remotePosts,
			remoteAccess: new RemotePostAccess($followingRepository, $blockRepository, $inboundBlocks),
			remoteActors: $remoteActors,
			actorInboxes: $actorInboxResolver,
			remoteImporter: $remotePostImporter,
		);
		$mediaCollectionService ??= new MediaCollectionService(
			$mediaCollectionRepository,
			$postService,
			$attachmentRepository,
			$clock,
			$ids,
			$mediaService,
			$localRelationships,
		);
		$moderationService = new ModerationService(
			$reportRepository,
			$moderationActions,
			$adminAuthorization,
			$postService,
			$clock,
			$ids,
			$localActors,
		);
		$heldPostService = new HeldPostService(
			$heldPostRepository,
			$postService,
			$adminAuthorization,
			$clock,
		);
		$scheduledPostService = new ScheduledPostService(
			$scheduledPostRepository ?? new InMemoryScheduledPostRepository(),
			$postService,
			$posts,
			$attachmentRepository,
			$queue,
			$clock,
			$ids,
			$config,
		);
		$scheduledPresenter = new MastodonScheduledStatusPresenter();
		$filterService = new FilterService(
			$filterRuleRepository ?? new InMemoryFilterRuleRepository(),
			$clock,
			$ids,
		);
		$filterPresenter = new MastodonFilterPresenter();
		$recommendationService ??= new RecommendationService(
			$config,
			$recommendationCandidates ?? new InMemoryRecommendationCandidateSource(),
			$recommendationRepository ?? new InMemoryRecommendationRepository(),
			new StoredRecommendationEligibility($blockRepository, $muteRepository, $moderationPolicy, $clock),
			new DefaultRecommendationRanker(),
			$clock,
			$ids,
			$recommendationSnapshots ?? new InMemoryRecommendationSnapshotCache(),
			new LiveRecommendationCandidates(
				$posts,
				$remotePosts,
				new RemotePostAccess($followingRepository, $blockRepository, $inboundBlocks),
			),
		);
		$storyRepository ??= new InMemoryStoryRepository();
		$storyService ??= new StoryService(
			$config,
			$storyRepository,
			new StoryAccess($followers, $followingRepository, $activities, $clock, $moderationPolicy),
			$mediaService,
			$followers,
			$followingRepository,
			$queue,
			$activities,
			$clock,
			$ids,
		);
		$boostService = new BoostService(
			$postService,
			$boostRepository,
			$clock,
			$ids,
			$queue,
			$activities,
			$inboxResolver,
			$notificationService,
			$eventBroker,
			$localRelationships,
			$transactions,
		);
		if ($localRelationships !== null) {
			$homeTimeline = new LocalFollowHomeTimelineRepository(
				$homeTimeline,
				$posts,
				$localRelationships,
				$boostRepository,
				$muteRepository,
				$clock,
				$accountListRepository,
			);
		}
		$timelines = new TimelineService($posts, $homeTimeline, $moderationPolicy);
		$conversationService = new ConversationService($postService, $posts, $conversationMarkers);
		$translationService = new TranslationService($postService, $translationProvider);
		// Remote actor lookup and media fetching require the bounded client and
		// the host resolver. An unbounded HTTP client is never used for these.
		$resolver = $hostResolver ?? new DnsHostResolver();
		$remoteActorSearch ??= $httpClient instanceof BoundedHttpClient
			? new WebFingerRemoteActorSearch(new RemoteFetcher($httpClient, $resolver), $clock)
			: null;
		$mastodonService = new MastodonService(
			$config,
			$posts,
			$postService,
			new MastodonPresenter($instance, $activities, $mediaService, $favouriteRepository, $localRelationships, $localActors, $clock, $pollVoteRepository, $posts, $boostRepository, $bookmarkRepository, $muteService, $reactionService, $localProfiles, $pinRepository),
			$localActors,
			$mediaService,
			$favouriteRepository,
			$clock,
			$localRelationships,
			$notificationService,
			$posts instanceof PostSearchRepository ? $posts : null,
			$localActors instanceof LocalActorSearch ? $localActors : null,
			$pollVoteRepository,
			$boostRepository,
			$boostService,
			$bookmarkRepository,
			$muteService,
			$accountListService,
			$trends,
			$localProfiles,
			$followingService,
			$followingRepository,
			$remoteActors,
			$blockRepository,
			$inboundBlocks,
			$remotePollService,
			$reactionService,
			$timelines,
			$remotePosts,
			$remoteActorSearch,
			$followRequestService,
			$translationService,
		);
		$blockedActors ??= new NoBlockedActors();
		$followPolicy ??= new AcceptAllFollows();

		$searchService = $search ?? new SearchService(
			$searchIndex,
			$clock,
			$remoteActorSearch,
			$trends,
			$localRelationships,
			$muteService,
			$moderationPolicy,
		);
		$remoteMedia = ($mediaService !== null && $httpClient instanceof BoundedHttpClient)
			? new RemoteMediaService(
				new RemoteMediaFetcher(
					$httpClient,
					$resolver,
					$config,
				),
				$mediaService,
				$remotePosts,
				$logger,
			)
			: null;

		// Thread context is an explicit, authenticated read operation. It
		// shares the bounded network guard with media but has its own limits
		// and cache, so remote media being disabled does not disable threads.
		$remoteThreadService = $httpClient instanceof BoundedHttpClient
			? new RemoteThreadService(
				$remotePosts,
				$threadRepository,
				$followingRepository,
				$blockRepository,
				new RemoteThreadFetcher(
					new RemoteFetcher($httpClient, $resolver),
					$config,
					$clock,
					$logger,
				),
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
				$localProfiles,
				$remoteMedia,
				$notificationService,
				$pendingRemoteUpdates,
				$reactionService,
				$remotePollService,
				$quoteService,
				$moderationService,
				$moderationPolicy,
				$accountMoveService,
				storyService: $storyService,
				inboundBlocks: $inboundBlocks ?? new InMemoryInboundBlockRepository(),
				localActors: $localActors,
				eventBroker: $eventBroker,
				transactions: $transactions,
				posts: $posts,
				blocks: $blockRepository,
				mutes: $muteRepository,
			)
			: null;

		// An actor document needs a key to publish and a directory to answer
		// whether a handle exists. Missing either keeps the routes at 503.
		$actorDocuments = ($signingKey !== null && $localActors !== null)
			? new ActorDocumentService(
				$activities,
				$signingKey,
				$localActors,
				$followerService,
				$followingService,
				$localRelationships,
				$localProfiles,
				$accountMoveService,
				$pinRepository,
				$posts,
			)
			: null;
		$profileUpdateService = $profileUpdates
			?? ($actorDocuments !== null && $localProfiles !== null
				? new ActorProfileUpdateService($actorDocuments, $followers, $activities, $queue, $localProfiles)
				: null);

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

		// Media reads reuse the post visibility rule, so a followers-only image
		// needs the same relationship as the post that carries it. Remote
		// attachments go through the shared remote audience rule.
		$mediaAccess = new MediaAccess(
			$posts,
			$localRelationships,
			$remotePosts,
			new RemotePostAccess($followingRepository, $blockRepository, $inboundBlocks),
		);

		$handlers = (new HandlerMap())
			->with(HealthHandler::class, static fn (): HealthHandler => new HealthHandler())
			->with(PdsXrpcHandler::class, static fn (): PdsXrpcHandler => new PdsXrpcHandler($pds))
			->with(InstanceHandler::class, static fn (): InstanceHandler => new InstanceHandler($instance))
			->with(CreatePostHandler::class, static fn (): CreatePostHandler => new CreatePostHandler($postService, $mediaService))
			->with(SubmitReportHandler::class, static fn (): SubmitReportHandler => new SubmitReportHandler($moderationService))
			->with(ShowPostHandler::class, static fn (): ShowPostHandler => new ShowPostHandler($postService, $mediaService, $reactionService, $filterService))
			->with(FiltersHandler::class, static fn (): FiltersHandler => new FiltersHandler($filterService))
			->with(CreateFilterHandler::class, static fn (): CreateFilterHandler => new CreateFilterHandler($filterService))
			->with(ShowFilterHandler::class, static fn (): ShowFilterHandler => new ShowFilterHandler($filterService))
			->with(UpdateFilterHandler::class, static fn (): UpdateFilterHandler => new UpdateFilterHandler($filterService))
			->with(DeleteFilterHandler::class, static fn (): DeleteFilterHandler => new DeleteFilterHandler($filterService))
			->with(GetRecommendationsHandler::class, static fn (): GetRecommendationsHandler => new GetRecommendationsHandler($recommendationService))
			->with(GetRecommendationPreferencesHandler::class, static fn (): GetRecommendationPreferencesHandler => new GetRecommendationPreferencesHandler($recommendationService))
			->with(UpdateRecommendationPreferencesHandler::class, static fn (): UpdateRecommendationPreferencesHandler => new UpdateRecommendationPreferencesHandler($recommendationService))
			->with(CreateRecommendationFeedbackHandler::class, static fn (): CreateRecommendationFeedbackHandler => new CreateRecommendationFeedbackHandler($recommendationService))
			->with(CreateRecommendationSignalHandler::class, static fn (): CreateRecommendationSignalHandler => new CreateRecommendationSignalHandler($recommendationService))
			->with(ExportRecommendationDataHandler::class, static fn (): ExportRecommendationDataHandler => new ExportRecommendationDataHandler($recommendationService))
			->with(DeleteRecommendationDataHandler::class, static fn (): DeleteRecommendationDataHandler => new DeleteRecommendationDataHandler($recommendationService))
			->with(ExportAccountDataHandler::class, static fn (): ExportAccountDataHandler => new ExportAccountDataHandler($accountData))
			->with(DeleteAccountDataHandler::class, static fn (): DeleteAccountDataHandler => new DeleteAccountDataHandler($accountData))
			->with(AccountDeletionStatusHandler::class, static fn (): AccountDeletionStatusHandler => new AccountDeletionStatusHandler($accountData))
			->with(AccountMoveStatusHandler::class, static fn (): AccountMoveStatusHandler => new AccountMoveStatusHandler($accountMoveService))
			->with(StartAccountMoveHandler::class, static fn (): StartAccountMoveHandler => new StartAccountMoveHandler($accountMoveService))
			->with(StoriesHandler::class, static fn (): StoriesHandler => new StoriesHandler($storyService))
			->with(CreateStoryHandler::class, static fn (): CreateStoryHandler => new CreateStoryHandler($storyService))
			->with(ShowStoryHandler::class, static fn (): ShowStoryHandler => new ShowStoryHandler($storyService))
			->with(DeleteStoryHandler::class, static fn (): DeleteStoryHandler => new DeleteStoryHandler($storyService))
			->with(ShowStoryMediaHandler::class, static fn (): ShowStoryMediaHandler => new ShowStoryMediaHandler($storyService))
			->with(RecordStoryViewHandler::class, static fn (): RecordStoryViewHandler => new RecordStoryViewHandler($storyService))
			->with(StoryViewersHandler::class, static fn (): StoryViewersHandler => new StoryViewersHandler($storyService))
			->with(CreateStoryReactionHandler::class, static fn (): CreateStoryReactionHandler => new CreateStoryReactionHandler($storyService))
			->with(StoryReactionsHandler::class, static fn (): StoryReactionsHandler => new StoryReactionsHandler($storyService))
			->with(CreateStoryReplyHandler::class, static fn (): CreateStoryReplyHandler => new CreateStoryReplyHandler($storyService))
			->with(StoryRepliesHandler::class, static fn (): StoryRepliesHandler => new StoryRepliesHandler($storyService))
			->with(RemotePollHandler::class, static fn (): RemotePollHandler => new RemotePollHandler($remotePollService))
			->with(VoteRemotePollHandler::class, static fn (): VoteRemotePollHandler => new VoteRemotePollHandler($remotePollService, $clock))
			->with(QuoteAuthorizationHandler::class, static fn (): QuoteAuthorizationHandler => new QuoteAuthorizationHandler($quoteService))
			->with(RevokeQuoteAuthorizationHandler::class, static fn (): RevokeQuoteAuthorizationHandler => new RevokeQuoteAuthorizationHandler($quoteService))
			->with(ShowRemotePostHandler::class, static fn (): ShowRemotePostHandler => new ShowRemotePostHandler($reactionService))
			->with(LikeRemotePostHandler::class, static fn (): LikeRemotePostHandler => new LikeRemotePostHandler($reactionService))
			->with(UnlikeRemotePostHandler::class, static fn (): UnlikeRemotePostHandler => new UnlikeRemotePostHandler($reactionService))
			->with(ReactToRemotePostHandler::class, static fn (): ReactToRemotePostHandler => new ReactToRemotePostHandler($reactionService))
			->with(UnreactFromRemotePostHandler::class, static fn (): UnreactFromRemotePostHandler => new UnreactFromRemotePostHandler($reactionService))
			->with(UpdatePostHandler::class, static fn (): UpdatePostHandler => new UpdatePostHandler($postService, $mediaService))
			->with(ScheduledPostsHandler::class, static fn (): ScheduledPostsHandler => new ScheduledPostsHandler($scheduledPostService))
			->with(CreateScheduledPostHandler::class, static fn (): CreateScheduledPostHandler => new CreateScheduledPostHandler($scheduledPostService))
			->with(ShowScheduledPostHandler::class, static fn (): ShowScheduledPostHandler => new ShowScheduledPostHandler($scheduledPostService))
			->with(UpdateScheduledPostHandler::class, static fn (): UpdateScheduledPostHandler => new UpdateScheduledPostHandler($scheduledPostService))
			->with(DeleteScheduledPostHandler::class, static fn (): DeleteScheduledPostHandler => new DeleteScheduledPostHandler($scheduledPostService))
			->with(PublishAtprotoHandler::class, static fn (): PublishAtprotoHandler => new PublishAtprotoHandler($atprotoService))
			->with(ShowAtprotoPublicationHandler::class, static fn (): ShowAtprotoPublicationHandler => new ShowAtprotoPublicationHandler($atprotoService))
			->with(ShowRemoteThreadHandler::class, static fn (): ShowRemoteThreadHandler => new ShowRemoteThreadHandler($remoteThreadService))
			->with(UploadMediaHandler::class, static fn (): UploadMediaHandler => new UploadMediaHandler($mediaService))
			->with(ShowMediaHandler::class, static fn (): ShowMediaHandler => new ShowMediaHandler($mediaService, $mediaAccess))
			->with(DeletePostHandler::class, static fn (): DeletePostHandler => new DeletePostHandler($postService))
			->with(ArchivedPostsHandler::class, static fn (): ArchivedPostsHandler => new ArchivedPostsHandler($postService))
			->with(ArchivePostHandler::class, static fn (): ArchivePostHandler => new ArchivePostHandler($postService))
			->with(UnarchivePostHandler::class, static fn (): UnarchivePostHandler => new UnarchivePostHandler($postService))
			->with(PinsHandler::class, static fn (): PinsHandler => new PinsHandler($pinService))
			->with(PinPostHandler::class, static fn (): PinPostHandler => new PinPostHandler($pinService))
			->with(UnpinPostHandler::class, static fn (): UnpinPostHandler => new UnpinPostHandler($pinService))
			->with(CollectionsHandler::class, static fn (): CollectionsHandler => new CollectionsHandler($mediaCollectionService))
			->with(CreateCollectionHandler::class, static fn (): CreateCollectionHandler => new CreateCollectionHandler($mediaCollectionService))
			->with(ShowCollectionHandler::class, static fn (): ShowCollectionHandler => new ShowCollectionHandler($mediaCollectionService))
			->with(UpdateCollectionHandler::class, static fn (): UpdateCollectionHandler => new UpdateCollectionHandler($mediaCollectionService))
			->with(DeleteCollectionHandler::class, static fn (): DeleteCollectionHandler => new DeleteCollectionHandler($mediaCollectionService))
			->with(AddCollectionItemHandler::class, static fn (): AddCollectionItemHandler => new AddCollectionItemHandler($mediaCollectionService))
			->with(RemoveCollectionItemHandler::class, static fn (): RemoveCollectionItemHandler => new RemoveCollectionItemHandler($mediaCollectionService))
			->with(BoostPostHandler::class, static fn (): BoostPostHandler => new BoostPostHandler($boostService))
			->with(UnboostPostHandler::class, static fn (): UnboostPostHandler => new UnboostPostHandler($boostService))
			->with(PublicTimelineHandler::class, static fn (): PublicTimelineHandler => new PublicTimelineHandler($timelines, $mediaService, $filterService))
			->with(AccountStatusesHandler::class, static fn (): AccountStatusesHandler => new AccountStatusesHandler($posts, $postService, $mediaService))
			->with(HashtagTimelineHandler::class, static fn (): HashtagTimelineHandler => new HashtagTimelineHandler($timelines, $mediaService, $filterService))
			->with(FollowedHashtagTimelineHandler::class, static fn (): FollowedHashtagTimelineHandler => new FollowedHashtagTimelineHandler($followedHashtagService, $timelines, $mediaService, $filterService))
			->with(FollowedTagsHandler::class, static fn (): FollowedTagsHandler => new FollowedTagsHandler($followedHashtagService))
			->with(FollowHashtagHandler::class, static fn (): FollowHashtagHandler => new FollowHashtagHandler($followedHashtagService))
			->with(UnfollowHashtagHandler::class, static fn (): UnfollowHashtagHandler => new UnfollowHashtagHandler($followedHashtagService))
			->with(HomeTimelineHandler::class, static fn (): HomeTimelineHandler => new HomeTimelineHandler($timelines, $mediaService, $filterService))
			->with(DirectTimelineHandler::class, static fn (): DirectTimelineHandler => new DirectTimelineHandler($timelines, $mediaService, $filterService, $conversationService))
			->with(MarkConversationReadHandler::class, static fn (): MarkConversationReadHandler => new MarkConversationReadHandler($conversationService))
			->with(DismissConversationHandler::class, static fn (): DismissConversationHandler => new DismissConversationHandler($conversationService))
			->with(FollowersHandler::class, static fn (): FollowersHandler => new FollowersHandler($followerService))
			->with(FollowHandler::class, static fn (): FollowHandler => new FollowHandler($followerService))
			->with(BlocksHandler::class, static fn (): BlocksHandler => new BlocksHandler($blockService))
			->with(BlockActorHandler::class, static fn (): BlockActorHandler => new BlockActorHandler($blockService))
			->with(UnblockActorHandler::class, static fn (): UnblockActorHandler => new UnblockActorHandler($blockService))
			->with(UnfollowHandler::class, static fn (): UnfollowHandler => new UnfollowHandler($followerService))
			->with(FollowRemoteActorHandler::class, static fn (): FollowRemoteActorHandler => new FollowRemoteActorHandler($followingService, $remoteActors))
			->with(UnfollowRemoteActorHandler::class, static fn (): UnfollowRemoteActorHandler => new UnfollowRemoteActorHandler($followingService))
			->with(ShowLocalAccountHandler::class, static fn (): ShowLocalAccountHandler => new ShowLocalAccountHandler($localAccounts))
			->with(FollowLocalAccountHandler::class, static fn (): FollowLocalAccountHandler => new FollowLocalAccountHandler($localAccounts))
			->with(UnfollowLocalAccountHandler::class, static fn (): UnfollowLocalAccountHandler => new UnfollowLocalAccountHandler($localAccounts))
			->with(NotificationsHandler::class, static fn (): NotificationsHandler => new NotificationsHandler($localAccounts, $filterService, $posts))
			->with(NotificationUnreadCountHandler::class, static fn (): NotificationUnreadCountHandler => new NotificationUnreadCountHandler($localAccounts))
			->with(DismissNotificationHandler::class, static fn (): DismissNotificationHandler => new DismissNotificationHandler($localAccounts))
			->with(ClearNotificationsHandler::class, static fn (): ClearNotificationsHandler => new ClearNotificationsHandler($localAccounts))
			->with(SearchHandler::class, static fn (): SearchHandler => new SearchHandler($searchService))
			->with(TrendsHandler::class, static fn (): TrendsHandler => new TrendsHandler($searchService))
			->with(FollowRequestsHandler::class, static fn (): FollowRequestsHandler => new FollowRequestsHandler($followRequestService))
			->with(AcceptFollowRequestHandler::class, static fn (): AcceptFollowRequestHandler => new AcceptFollowRequestHandler($followRequestService))
			->with(RejectFollowRequestHandler::class, static fn (): RejectFollowRequestHandler => new RejectFollowRequestHandler($followRequestService))
			->with(NotificationPolicyHandler::class, static fn (): NotificationPolicyHandler => new NotificationPolicyHandler($notificationService))
			->with(NotificationRequestsHandler::class, static fn (): NotificationRequestsHandler => new NotificationRequestsHandler($notificationService))
			->with(NotificationRequestActionHandler::class, static fn (): NotificationRequestActionHandler => new NotificationRequestActionHandler($notificationService))
			->with(ConversationMuteStatusHandler::class, static fn (): ConversationMuteStatusHandler => new ConversationMuteStatusHandler($notificationService))
			->with(MuteConversationHandler::class, static fn (): MuteConversationHandler => new MuteConversationHandler($notificationService))
			->with(UnmuteConversationHandler::class, static fn (): UnmuteConversationHandler => new UnmuteConversationHandler($notificationService))
			->with(InboxHandler::class, static fn (): InboxHandler => new InboxHandler($inboxService))
			->with(ActorHandler::class, static fn (): ActorHandler => new ActorHandler($actorDocuments))
			->with(FollowersCollectionHandler::class, static fn (): FollowersCollectionHandler => new FollowersCollectionHandler($actorDocuments))
			->with(FollowingCollectionHandler::class, static fn (): FollowingCollectionHandler => new FollowingCollectionHandler($actorDocuments))
			->with(ActorFeaturedHandler::class, static fn (): ActorFeaturedHandler => new ActorFeaturedHandler($actorDocuments))
			->with(ActorCollectionsHandler::class, static fn (): ActorCollectionsHandler => new ActorCollectionsHandler($mediaCollectionService, $localActors))
			->with(OutboxHandler::class, static fn (): OutboxHandler => new OutboxHandler($actorOutbox))
			->with(WebFingerHandler::class, static fn (): WebFingerHandler => new WebFingerHandler($webFinger))
			->with(MastodonInstanceHandler::class, static fn (): MastodonInstanceHandler => new MastodonInstanceHandler($mastodonService))
			->with(MastodonMediaUploadHandler::class, static fn (): MastodonMediaUploadHandler => new MastodonMediaUploadHandler($mastodonService))
			->with(MastodonPublicTimelineHandler::class, static fn (): MastodonPublicTimelineHandler => new MastodonPublicTimelineHandler($mastodonService))
			->with(MastodonHashtagTimelineHandler::class, static fn (): MastodonHashtagTimelineHandler => new MastodonHashtagTimelineHandler($mastodonService))
			->with(MastodonHomeTimelineHandler::class, static fn (): MastodonHomeTimelineHandler => new MastodonHomeTimelineHandler($mastodonService))
			->with(MastodonStatusHandler::class, static fn (): MastodonStatusHandler => new MastodonStatusHandler($mastodonService))
			->with(MastodonTranslateStatusHandler::class, static fn (): MastodonTranslateStatusHandler => new MastodonTranslateStatusHandler($mastodonService))
			->with(MastodonTranslationLanguagesHandler::class, static fn (): MastodonTranslationLanguagesHandler => new MastodonTranslationLanguagesHandler($mastodonService))
			->with(MastodonStatusContextHandler::class, static fn (): MastodonStatusContextHandler => new MastodonStatusContextHandler($mastodonService))
			->with(MastodonReblogStatusHandler::class, static fn (): MastodonReblogStatusHandler => new MastodonReblogStatusHandler($mastodonService))
			->with(MastodonUnreblogStatusHandler::class, static fn (): MastodonUnreblogStatusHandler => new MastodonUnreblogStatusHandler($mastodonService))
			->with(MastodonNotificationsHandler::class, static fn (): MastodonNotificationsHandler => new MastodonNotificationsHandler($mastodonService))
			->with(MastodonPollHandler::class, static fn (): MastodonPollHandler => new MastodonPollHandler($mastodonService))
			->with(MastodonVotePollHandler::class, static fn (): MastodonVotePollHandler => new MastodonVotePollHandler($mastodonService))
			->with(MastodonNotificationUnreadCountHandler::class, static fn (): MastodonNotificationUnreadCountHandler => new MastodonNotificationUnreadCountHandler($mastodonService))
			->with(MastodonDismissNotificationHandler::class, static fn (): MastodonDismissNotificationHandler => new MastodonDismissNotificationHandler($mastodonService))
			->with(MastodonClearNotificationsHandler::class, static fn (): MastodonClearNotificationsHandler => new MastodonClearNotificationsHandler($mastodonService))
			->with(MastodonSearchHandler::class, static fn (): MastodonSearchHandler => new MastodonSearchHandler($mastodonService))
			->with(MastodonCreateStatusHandler::class, static fn (): MastodonCreateStatusHandler => new MastodonCreateStatusHandler($mastodonService, $scheduledPostService, $scheduledPresenter))
			->with(MastodonScheduledStatusesHandler::class, static fn (): MastodonScheduledStatusesHandler => new MastodonScheduledStatusesHandler($scheduledPostService, $scheduledPresenter))
			->with(MastodonFiltersHandler::class, static fn (): MastodonFiltersHandler => new MastodonFiltersHandler($filterService, $filterPresenter, $clock))
			->with(MastodonCreateFilterHandler::class, static fn (): MastodonCreateFilterHandler => new MastodonCreateFilterHandler($filterService, $filterPresenter, $clock))
			->with(MastodonShowFilterHandler::class, static fn (): MastodonShowFilterHandler => new MastodonShowFilterHandler($filterService, $filterPresenter, $clock))
			->with(MastodonUpdateFilterHandler::class, static fn (): MastodonUpdateFilterHandler => new MastodonUpdateFilterHandler($filterService, $filterPresenter, $clock))
			->with(MastodonDeleteFilterHandler::class, static fn (): MastodonDeleteFilterHandler => new MastodonDeleteFilterHandler($filterService, $filterPresenter, $clock))
			->with(MastodonScheduledStatusHandler::class, static fn (): MastodonScheduledStatusHandler => new MastodonScheduledStatusHandler($scheduledPostService, $scheduledPresenter))
			->with(MastodonUpdateScheduledStatusHandler::class, static fn (): MastodonUpdateScheduledStatusHandler => new MastodonUpdateScheduledStatusHandler($scheduledPostService, $scheduledPresenter))
			->with(MastodonDeleteScheduledStatusHandler::class, static fn (): MastodonDeleteScheduledStatusHandler => new MastodonDeleteScheduledStatusHandler($scheduledPostService, $scheduledPresenter))
			->with(MastodonDeleteStatusHandler::class, static fn (): MastodonDeleteStatusHandler => new MastodonDeleteStatusHandler($mastodonService))
			->with(MastodonUpdateStatusHandler::class, static fn (): MastodonUpdateStatusHandler => new MastodonUpdateStatusHandler($mastodonService))
			->with(MastodonRelationshipsHandler::class, static fn (): MastodonRelationshipsHandler => new MastodonRelationshipsHandler($mastodonService))
			->with(MastodonFollowRequestsHandler::class, static fn (): MastodonFollowRequestsHandler => new MastodonFollowRequestsHandler($mastodonService))
			->with(MastodonAuthorizeFollowRequestHandler::class, static fn (): MastodonAuthorizeFollowRequestHandler => new MastodonAuthorizeFollowRequestHandler($mastodonService))
			->with(MastodonRejectFollowRequestHandler::class, static fn (): MastodonRejectFollowRequestHandler => new MastodonRejectFollowRequestHandler($mastodonService))
			->with(MastodonFollowAccountHandler::class, static fn (): MastodonFollowAccountHandler => new MastodonFollowAccountHandler($mastodonService))
			->with(MastodonUnfollowAccountHandler::class, static fn (): MastodonUnfollowAccountHandler => new MastodonUnfollowAccountHandler($mastodonService))
			->with(MastodonMuteAccountHandler::class, static fn (): MastodonMuteAccountHandler => new MastodonMuteAccountHandler($mastodonService))
			->with(MastodonUnmuteAccountHandler::class, static fn (): MastodonUnmuteAccountHandler => new MastodonUnmuteAccountHandler($mastodonService))
			->with(MastodonMutesHandler::class, static fn (): MastodonMutesHandler => new MastodonMutesHandler($mastodonService))
			->with(MastodonListsHandler::class, static fn (): MastodonListsHandler => new MastodonListsHandler($mastodonService))
			->with(MastodonCreateListHandler::class, static fn (): MastodonCreateListHandler => new MastodonCreateListHandler($mastodonService))
			->with(MastodonListHandler::class, static fn (): MastodonListHandler => new MastodonListHandler($mastodonService))
			->with(MastodonUpdateListHandler::class, static fn (): MastodonUpdateListHandler => new MastodonUpdateListHandler($mastodonService))
			->with(MastodonDeleteListHandler::class, static fn (): MastodonDeleteListHandler => new MastodonDeleteListHandler($mastodonService))
			->with(MastodonListAccountsHandler::class, static fn (): MastodonListAccountsHandler => new MastodonListAccountsHandler($mastodonService))
			->with(MastodonAddListAccountsHandler::class, static fn (): MastodonAddListAccountsHandler => new MastodonAddListAccountsHandler($mastodonService))
			->with(MastodonRemoveListAccountsHandler::class, static fn (): MastodonRemoveListAccountsHandler => new MastodonRemoveListAccountsHandler($mastodonService))
			->with(MastodonListTimelineHandler::class, static fn (): MastodonListTimelineHandler => new MastodonListTimelineHandler($mastodonService))
			->with(MastodonUpdateMediaHandler::class, static fn (): MastodonUpdateMediaHandler => new MastodonUpdateMediaHandler($mastodonService))
			->with(MastodonDeleteMediaHandler::class, static fn (): MastodonDeleteMediaHandler => new MastodonDeleteMediaHandler($mastodonService))
			->with(MastodonFavouriteStatusHandler::class, static fn (): MastodonFavouriteStatusHandler => new MastodonFavouriteStatusHandler($mastodonService))
			->with(MastodonUnfavouriteStatusHandler::class, static fn (): MastodonUnfavouriteStatusHandler => new MastodonUnfavouriteStatusHandler($mastodonService))
			->with(MastodonBookmarkStatusHandler::class, static fn (): MastodonBookmarkStatusHandler => new MastodonBookmarkStatusHandler($mastodonService))
			->with(MastodonUnbookmarkStatusHandler::class, static fn (): MastodonUnbookmarkStatusHandler => new MastodonUnbookmarkStatusHandler($mastodonService))
			->with(MastodonBookmarksHandler::class, static fn (): MastodonBookmarksHandler => new MastodonBookmarksHandler($mastodonService))
			->with(MastodonAccountStatusesHandler::class, static fn (): MastodonAccountStatusesHandler => new MastodonAccountStatusesHandler($mastodonService))
			->with(MastodonAccountHandler::class, static fn (): MastodonAccountHandler => new MastodonAccountHandler($mastodonService))
			->with(MastodonAccountLookupHandler::class, static fn (): MastodonAccountLookupHandler => new MastodonAccountLookupHandler($mastodonService))
			->with(MastodonAccountFollowersHandler::class, static fn (): MastodonAccountFollowersHandler => new MastodonAccountFollowersHandler($mastodonService))
			->with(MastodonAccountFollowingHandler::class, static fn (): MastodonAccountFollowingHandler => new MastodonAccountFollowingHandler($mastodonService))
			->with(MastodonVerifyCredentialsHandler::class, static fn (): MastodonVerifyCredentialsHandler => new MastodonVerifyCredentialsHandler($mastodonService));

		$jobHandlers = new JobHandlerRegistry();
		if ($federationDelivery !== null) {
			$jobHandlers = $jobHandlers
				->with(JobType::FEDERATION_DELIVER, $federationDelivery)
				->with(
					JobType::FEDERATION_FOLLOW,
					new FollowingDeliveryHandler($followingRepository, $federationDelivery),
				);
		}
		if ($accountMoveService !== null) {
			$jobHandlers = $jobHandlers->with(
				JobType::ACCOUNT_MOVE,
				new AccountMoveHandler($accountMoveService),
			);
		}
		if ($atprotoSync !== null) {
			$jobHandlers = $jobHandlers->with(
				JobType::ATPROTO_SYNC,
				new AtprotoSyncHandler($atprotoSync, $logger),
			);
		}
		if ($accountData !== null) {
			$jobHandlers = $jobHandlers->with(
				JobType::ACCOUNT_ERASURE_BLOB_RETRY,
				new AccountErasureBlobRetryHandler($accountData),
			);
		}
		if ($blobStore !== null) {
			$jobHandlers = $jobHandlers->with(
				JobType::MEDIA_BLOB_DELETE,
				new MediaBlobDeleteHandler($blobStore),
			);
		}
		$jobHandlers = $jobHandlers
			->with(JobType::ATPROTO_PUBLISH, new AtprotoPublishHandler($atprotoService, $logger))
			->with(JobType::ATPROTO_DELETE, new AtprotoDeleteHandler($atprotoService, $logger))
			->with(JobType::CLEANUP, new CleanupJobHandler($cleanup, $logger))
			->with(JobType::STORY_EXPIRE, new StoryExpireHandler($storyService, $logger))
			->with(JobType::POLL_CLOSE, new RemotePollCloseHandler($remotePollService, $clock))
			->with(JobType::SCHEDULED_POST_PUBLISH, new ScheduledPostPublishHandler($scheduledPostService));

		$routes = Routes::manifest(MastodonApiConfig::prefix($config));

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
			[
				new MastodonApiGate($mastodonService),
				new RequireAuthentication(),
				new RequireScopes(),
				new RejectDeletedAccountWrites($accountDataRepository, $accountData),
			],
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
			new JobWorker($queue, $jobHandlers, $clock, $logger, $accountDataRepository, $accountData),
			$queue,
			$followingService,
			$blockService,
			$backfill,
			$mediaService,
			$remoteThreadService,
			$atprotoService,
			$mastodonService,
			$cleanup,
			$atprotoInteractions,
			$atprotoProfiles,
			$atprotoTimeline,
			$moderationService,
			$heldPostService,
			$notificationService,
			$searchService,
			$followRequestService,
			$profileUpdateService,
			$accountMoveService,
			$atprotoSync,
			$recommendationService,
			$storyService,
			$pinService,
			$mediaCollectionService,
			$pds,
			$accountData,
			$atprotoFollowService,
			$reactionService,
			$boostService,
			$eventBroker,
		);
	}

	private static function assemblePds(
		Config $config,
		Clock $clock,
		PdsRepository $repository,
		?SecretBox $secrets,
		?BlobStore $blobs = null,
		?PdsDirectory $directory = null,
	): NativePdsService {
		if ($secrets === null) {
			$key = PdsConfig::secretBoxKey($config);
			if ($key === null) {
				throw new InvalidArgumentException(
					'atproto.pds.secret_box_key is required when the native PDS is enabled.',
				);
			}

			$secrets = OpenSslSecretBox::fromBase64Key($key);
		}

		return new NativePdsService($config, $repository, $secrets, $clock, $blobs, $directory);
	}

	public function handle(Request $request): Response
	{
		return $this->kernel->handle($request);
	}
}
