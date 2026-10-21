<template>
	<div class="shared-page">
		<header class="shared-page__header">
			<h2>{{ target.title }}</h2>
			<p v-if="target.description" class="shared-page__meta">
				{{ target.description }}
			</p>
			<p class="shared-page__meta">
				{{ t('Only freely available scores are shown. Select a score to view the notes and listen to the individual voices.') }}
			</p>
		</header>
		<SharedTargetView
			:target="target"
			:loadFile="loadFile"
			:emptyText="t('No freely available scores in this share')" />
	</div>
</template>

<script setup lang="ts">
import type { SharedTarget } from '@/api/generated/openapi/data-contracts'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import SharedTargetView from '@/components/shares/SharedTargetView.vue'
import { t } from '@/utils/l10n'

const target = loadState<SharedTarget>('orchestrascoresmanager', 'sharedTarget')
const token = loadState<string>('orchestrascoresmanager', 'shareToken')

/**
 * Load a file of a score in the share
 *
 * @param scoreId - The score ID
 * @param kind - The file type
 */
async function loadFile(scoreId: number, kind: 'musicxml' | 'pdf'): Promise<ArrayBuffer> {
	const response = await fetch(generateUrl('/apps/orchestrascoresmanager/s/{token}/scores/{id}/{kind}', { token, id: scoreId, kind }))
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`)
	}
	return response.arrayBuffer()
}
</script>

<style lang="scss" scoped>
.shared-page {
	box-sizing: border-box;
	width: 100%;
	max-width: 1100px;
	margin: 0 auto;
	padding: calc(4 * var(--default-grid-baseline));
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	background: var(--color-main-background);
	border-radius: var(--border-radius-container-large, var(--border-radius-large));

	h2 {
		margin: 0;
	}

	&__header {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__meta {
		color: var(--color-text-maxcontrast);
	}
}
</style>
