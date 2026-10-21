<template>
	<Layout :title="t('Manage shares')">
		<template #content>
			<div class="shares-page">
				<section class="shares-page__section">
					<h2>{{ t('Share') }}</h2>
					<div class="shares-page__picker">
						<div class="shares-page__target-type" role="radiogroup" :aria-label="t('What do you want to share?')">
							<NcCheckboxRadioSwitch
								v-model="targetType"
								type="radio"
								value="folder_collection"
								name="share-target-type">
								{{ t('Folder collection') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								v-model="targetType"
								type="radio"
								value="score"
								name="share-target-type">
								{{ t('Score') }}
							</NcCheckboxRadioSwitch>
						</div>
						<NcSelect
							v-if="targetType === 'folder_collection'"
							v-model="selectedFolderCollection"
							class="shares-page__select"
							:options="folderCollectionsStore.folderCollectionsSorted"
							label="title"
							:inputLabel="t('Folder collection')"
							:placeholder="t('Select folder collection')" />
						<NcSelect
							v-else
							v-model="selectedScore"
							class="shares-page__select"
							:options="scoresStore.scoresSorted"
							label="title"
							:inputLabel="t('Score')"
							:placeholder="t('Select score')" />
					</div>
					<ShareManager
						v-if="selectedTargetId !== null"
						:targetType="targetType"
						:targetId="selectedTargetId"
						@changed="loadShares" />
				</section>

				<section class="shares-page__section">
					<h2>{{ t('All shares') }}</h2>
					<NcLoadingIcon v-if="loading" :size="32" />
					<p v-else-if="shares.length === 0" class="shares-page__meta">
						{{ t('Nothing has been shared yet') }}
					</p>
					<ul v-else class="shares-page__list">
						<li v-for="share in shares" :key="share.id" class="shares-page__item">
							<FolderCollectionIcon v-if="share.targetType === 'folder_collection'" :size="20" />
							<ScoreIcon v-else :size="20" />
							<div class="shares-page__item-text">
								<button class="shares-page__item-title" type="button" @click="pick(share)">
									{{ share.label || share.targetTitle || t('Deleted item') }}
								</button>
								<span class="shares-page__meta">
									<template v-if="share.shareType === 'link'">{{ t('Public link (only published scores)') }}</template>
									<template v-else>{{ t('Group: {group}', { group: share.groupName ?? '' }) }}</template>
								</span>
							</div>
							<NcButton
								v-if="share.shareType === 'link'"
								variant="tertiary"
								:aria-label="t('Open link')"
								:title="t('Open link')"
								:href="linkUrl(share)"
								target="_blank">
								<template #icon>
									<OpenExternalIcon :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:aria-label="t('Edit shares of this item')"
								:title="t('Edit shares of this item')"
								@click="pick(share)">
								<template #icon>
									<EditIcon :size="20" />
								</template>
							</NcButton>
						</li>
					</ul>
				</section>
			</div>
		</template>
	</Layout>
</template>

<script setup lang="ts">
import type { FolderCollection, Score, Share } from '@/api/generated/openapi/data-contracts'

import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Layout from '@/components/Layout.vue'
import ShareManager from '@/components/shares/ShareManager.vue'
import { apiClients } from '@/api/client'
import { EditIcon, FolderCollectionIcon, OpenExternalIcon, ScoreIcon } from '@/icons/vue-material'
import { useFolderCollectionsStore } from '@/stores/folderCollectionsStore'
import { useScoresStore } from '@/stores/scoresStore'
import { tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'

const folderCollectionsStore = useFolderCollectionsStore()
const scoresStore = useScoresStore()

const targetType = ref<'folder_collection' | 'score'>('folder_collection')
const selectedFolderCollection = ref<FolderCollection | null>(null)
const selectedScore = ref<Score | null>(null)
const shares = ref<Share[]>([])
const loading = ref(true)

const selectedTargetId = computed((): number | null => targetType.value === 'folder_collection'
	? selectedFolderCollection.value?.id ?? null
	: selectedScore.value?.id ?? null)

/**
 * Load the overview of all shares
 */
async function loadShares(): Promise<void> {
	await tryShowError(async () => {
		const response = await apiClients.default.shareApiGetAllShares()
		shares.value = response.data.ocs.data
	}, t('Failed to load shares: '))
	loading.value = false
}

/**
 * Select the item of a share to edit its shares
 *
 * @param share - A share from the overview
 */
function pick(share: Share): void {
	targetType.value = share.targetType
	if (share.targetType === 'folder_collection') {
		selectedFolderCollection.value = folderCollectionsStore.folderCollections.find((fc) => fc.id === share.targetId) ?? null
	} else {
		selectedScore.value = scoresStore.scores.find((score) => score.id === share.targetId) ?? null
	}
	window.scrollTo({ top: 0, behavior: 'smooth' })
}

/**
 * @param share - A link share
 */
function linkUrl(share: Share): string {
	return generateUrl('/apps/orchestrascoresmanager/s/{token}', { token: share.token ?? '' })
}

onMounted(() => {
	folderCollectionsStore.initialize()
	scoresStore.initialize()
	loadShares()
})
</script>

<style lang="scss" scoped>
.shares-page {
	display: flex;
	flex-direction: column;
	gap: calc(6 * var(--default-grid-baseline));
	max-width: 800px;

	h2 {
		margin: 0;
	}

	&__section {
		display: flex;
		flex-direction: column;
		gap: calc(3 * var(--default-grid-baseline));
	}

	&__picker {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: calc(3 * var(--default-grid-baseline));
	}

	&__target-type {
		display: flex;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__select {
		flex: 1 1 260px;
		min-width: 0;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__item {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
	}

	&__item-text {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__item-title {
		all: unset;
		font-weight: bold;
		cursor: pointer;

		&:focus-visible {
			outline: 2px solid var(--color-main-text);
		}
	}
}
</style>
