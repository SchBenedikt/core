<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { UiApiError } from '../api-error.js';
import { useNotifications } from '../composables/use-notifications.js';
import { useHostBridge } from '../plugin.js';
import type { Notification, NotificationType } from '../types.js';

const emit = defineEmits<{
	'open-post': [id: string];
	'open-actor': [id: string];
}>();

const bridge = useHostBridge();
const signedIn = ref<boolean | null>(null);
const enabled = computed(() => signedIn.value === true);
const {
	notifications,
	unreadCount,
	loading,
	mutating,
	error,
	hasMore,
	loadMore,
	reload,
	dismiss,
	clear,
} = useNotifications(20, enabled);

const signInUrl = computed(() => bridge.loginUrl(currentPath()));
const signedOut = computed(() => (
	signedIn.value === false
	|| (error.value instanceof UiApiError && error.value.status === 401)
));

const typeLabels: Record<NotificationType, string> = {
	favourite: 'favourited your post',
	follow: 'followed you',
	mention: 'mentioned you',
	reblog: 'boosted your post',
};

onMounted(async () => {
	try {
		signedIn.value = (await bridge.currentUser()) !== null;
	} catch {
		signedIn.value = false;
	}
});

function describe(notification: Notification): string {
	return typeLabels[notification.type];
}

function formatDate(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}

	return new Intl.DateTimeFormat(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	}).format(date);
}

function currentPath(): string {
	if (typeof window === 'undefined') {
		return '/';
	}

	return window.location.pathname + window.location.search;
}
</script>

<template>
	<section class="aloha-notifications" aria-label="Notifications" :aria-busy="loading">
		<header class="aloha-notifications__header">
			<h2 class="aloha-notifications__title">Notifications</h2>
			<span v-if="signedIn" class="aloha-notifications__badge" aria-live="polite">
				{{ unreadCount }} unread
			</span>
		</header>

		<p v-if="signedIn === null" class="aloha-notifications__status" role="status" aria-live="polite">
			Checking your session…
		</p>

		<p v-else-if="signedOut" class="aloha-notifications__status">
			<a :href="signInUrl">Sign in to see notifications</a>
		</p>

		<p v-else-if="loading && notifications.length === 0" class="aloha-notifications__status" role="status" aria-live="polite">
			Loading notifications…
		</p>

		<div v-else-if="error && notifications.length === 0" class="aloha-notifications__error" role="alert">
			<p>{{ error.message }}</p>
			<button type="button" :disabled="loading" @click="reload">Try again</button>
		</div>

		<p v-else-if="notifications.length === 0" class="aloha-notifications__status" role="status" aria-live="polite">
			No notifications yet.
		</p>

		<template v-else>
			<ul class="aloha-notifications__list" aria-label="Notification events">
				<li v-for="notification in notifications" :key="notification.id" class="aloha-notifications__item">
					<p class="aloha-notifications__text">
						<button
							class="aloha-notifications__actor"
							type="button"
							@click="emit('open-actor', notification.actor_id)"
						>
							@{{ notification.actor_id }}
						</button>
						{{ describe(notification) }}
					</p>
					<time class="aloha-notifications__time" :datetime="notification.created_at">
						{{ formatDate(notification.created_at) }}
					</time>
					<div class="aloha-notifications__actions">
						<button
							v-if="notification.post_id"
							type="button"
							@click="emit('open-post', notification.post_id)"
						>
							View post
						</button>
						<button
							type="button"
							:disabled="mutating"
							:aria-label="`Dismiss notification from ${notification.actor_id}`"
							@click="dismiss(notification.id)"
						>
							Dismiss
						</button>
					</div>
				</li>
			</ul>

			<p v-if="error" class="aloha-notifications__error" role="alert">
				{{ error.message }}
				<button type="button" :disabled="loading" @click="loadMore">Try again</button>
			</p>

			<div class="aloha-notifications__footer">
				<button
					v-if="hasMore && !error"
					type="button"
					:disabled="loading"
					@click="loadMore"
				>
					{{ loading ? 'Loading…' : 'Load more' }}
				</button>
				<button type="button" :disabled="mutating" @click="clear">
					Clear all
				</button>
			</div>
		</template>
	</section>
</template>

<style scoped>
.aloha-notifications {
	display: grid;
	gap: 1rem;
}

.aloha-notifications__header {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.5rem 1rem;
}

.aloha-notifications__title {
	margin: 0;
	font-size: 1.25rem;
}

.aloha-notifications__badge {
	padding: 0.15rem 0.55rem;
	border-radius: 999px;
	background: var(--aloha-badge-background, #e8edf5);
	color: var(--aloha-badge-text, #34445c);
	font-size: 0.8125rem;
}

.aloha-notifications__list {
	display: grid;
	gap: 0.75rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.aloha-notifications__item {
	display: grid;
	gap: 0.4rem;
	padding: 0.9rem 1rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
}

.aloha-notifications__text {
	margin: 0;
	overflow-wrap: anywhere;
}

.aloha-notifications__actor {
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font: inherit;
	font-weight: 700;
	cursor: pointer;
	overflow-wrap: anywhere;
}

.aloha-notifications__time {
	color: var(--aloha-muted-text, #5f6b7a);
	font-size: 0.875rem;
}

.aloha-notifications__actions,
.aloha-notifications__footer {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.aloha-notifications__actions button,
.aloha-notifications__footer button,
.aloha-notifications__error button {
	padding: 0.4rem 0.75rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-notifications__actions button:disabled,
.aloha-notifications__footer button:disabled,
.aloha-notifications__error button:disabled {
	cursor: wait;
	opacity: 0.65;
}

.aloha-notifications__actions button:focus-visible,
.aloha-notifications__footer button:focus-visible,
.aloha-notifications__actor:focus-visible,
.aloha-notifications__error button:focus-visible {
	outline: 2px solid var(--aloha-focus-color, #2457a6);
	outline-offset: 2px;
}

.aloha-notifications__status,
.aloha-notifications__error {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}

.aloha-notifications__error {
	color: var(--aloha-error-text, #9f1d20);
}
</style>
