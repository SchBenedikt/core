<script setup lang="ts">
import { computed } from 'vue';
import { useActorProfile } from '../composables/use-actor-profile.js';

const props = defineProps<{ accountId: string }>();

const accountId = computed(() => props.accountId);
const { account, loading, busy, error, toggleFollow, reload } = useActorProfile(accountId);

const initials = computed(() => {
	const name = account.value?.display_name?.trim() ?? '';
	if (name === '') {
		return '?';
	}

	return Array.from(name).slice(0, 2).join('').toUpperCase();
});

const canFollow = computed(() => account.value?.relationship != null);
const following = computed(() => account.value?.relationship?.following === true);
</script>

<template>
	<section class="aloha-actor" aria-label="Account profile" :aria-busy="loading">
		<p v-if="loading && account === null" class="aloha-actor__status" role="status" aria-live="polite">
			Loading profile…
		</p>

		<div v-else-if="error && account === null" class="aloha-actor__error" role="alert">
			<p>{{ error.message }}</p>
			<button type="button" :disabled="loading" @click="reload">Try again</button>
		</div>

		<p v-else-if="account === null" class="aloha-actor__status" role="status" aria-live="polite">
			Account not found.
		</p>

		<template v-else>
			<header class="aloha-actor__header">
				<span class="aloha-actor__avatar" aria-hidden="true">{{ initials }}</span>
				<div class="aloha-actor__identity">
					<h2 class="aloha-actor__name">{{ account.display_name }}</h2>
					<p class="aloha-actor__handle">@{{ account.handle }}</p>
				</div>
				<button
					v-if="canFollow"
					class="aloha-actor__follow"
					type="button"
					:disabled="busy"
					:aria-pressed="following"
					@click="toggleFollow"
				>
					{{ following ? 'Unfollow' : 'Follow' }}
				</button>
			</header>

			<p class="aloha-actor__counts">
				<span><strong>{{ account.followers_count }}</strong> followers</span>
				<span><strong>{{ account.following_count }}</strong> following</span>
			</p>

			<p v-if="error" class="aloha-actor__error" role="alert">{{ error.message }}</p>
		</template>
	</section>
</template>

<style scoped>
.aloha-actor {
	display: grid;
	gap: 1rem;
}

.aloha-actor__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem 1rem;
}

.aloha-actor__avatar {
	display: grid;
	place-items: center;
	width: 3rem;
	height: 3rem;
	border-radius: 999px;
	background: var(--aloha-avatar-background, #e8edf5);
	color: var(--aloha-avatar-text, #34445c);
	font-weight: 700;
}

.aloha-actor__identity {
	display: grid;
	gap: 0.125rem;
	min-width: 0;
}

.aloha-actor__name {
	margin: 0;
	font-size: 1.25rem;
	overflow-wrap: anywhere;
}

.aloha-actor__handle {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
	overflow-wrap: anywhere;
}

.aloha-actor__follow {
	margin-left: auto;
	padding: 0.5rem 0.9rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-actor__follow[aria-pressed='true'] {
	background: var(--aloha-following-background, #eef3fb);
}

.aloha-actor__follow:disabled {
	cursor: wait;
	opacity: 0.65;
}

.aloha-actor__follow:focus-visible,
.aloha-actor__error button:focus-visible {
	outline: 2px solid var(--aloha-focus-color, #2457a6);
	outline-offset: 2px;
}

.aloha-actor__counts {
	display: flex;
	gap: 1rem;
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}

.aloha-actor__status,
.aloha-actor__error {
	margin: 0;
	color: var(--aloha-muted-text, #5f6b7a);
}

.aloha-actor__error {
	color: var(--aloha-error-text, #9f1d20);
}

.aloha-actor__error button {
	margin-left: 0.5rem;
	padding: 0.5rem 0.9rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	background: var(--aloha-button-background, transparent);
	cursor: pointer;
}

.aloha-actor__error button:disabled {
	cursor: wait;
	opacity: 0.65;
}
</style>
