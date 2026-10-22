<template>
	<div class="share-manager">
		<NcNoteCard type="info">
			{{ t('Public links work without login and only show scores that are approved for publication and whose rights are confirmed. Group shares show all scores, including PDFs, to the members of the group, but never the notes of internal preservation copies. Files cannot be downloaded from shares.') }}
		</NcNoteCard>

		<div v-if="loading" class="share-manager__state">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else>
			<ul v-if="shares.length > 0" class="share-manager__list">
				<li v-for="share in shares" :key="share.id" class="share-manager__item">
					<div class="share-manager__icon">
						<LinkIcon v-if="share.shareType === 'link'" :size="20" />
						<GroupIcon v-else :size="20" />
					</div>
					<div class="share-manager__text">
						<span class="share-manager__title">
							{{ share.shareType === 'link' ? t('Public link') : share.groupName }}
						</span>
						<span v-if="share.label" class="share-manager__meta">{{ share.label }}</span>
						<span v-if="share.shareType === 'link'" class="share-manager__meta share-manager__url">{{ linkUrl(share) }}</span>
					</div>
					<NcButton
						v-if="share.shareType === 'link'"
						variant="tertiary"
						:aria-label="t('Copy link')"
						:title="t('Copy link')"
						@click="copyLink(share)">
						<template #icon>
							<CloneIcon :size="20" />
						</template>
					</NcButton>
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
						:aria-label="t('Remove share')"
						:title="t('Remove share')"
						@click="removeShare(share)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<p v-else class="share-manager__meta">
				{{ t('Not shared yet') }}
			</p>

			<NcTextField
				v-model="label"
				:label="t('Title shown on the share (optional)')"
				:maxlength="255" />

			<div class="share-manager__add">
				<NcSelect
					v-model="selectedGroup"
					class="share-manager__group-select"
					:options="availableGroups"
					label="displayName"
					:inputLabel="t('Share with group')"
					:placeholder="t('Select group')" />
				<NcButton :disabled="!selectedGroup || busy" @click="createShare('group')">
					<template #icon>
						<GroupIcon :size="20" />
					</template>
					{{ t('Share') }}
				</NcButton>
			</div>

			<NcButton :disabled="busy" @click="createShare('link')">
				<template #icon>
					<LinkIcon :size="20" />
				</template>
				{{ t('Create public link') }}
			</NcButton>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { Share, ShareGroup } from '@/api/generated/openapi/data-contracts'

import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { apiClients } from '@/api/client'
import { CloneIcon, DeleteIcon, GroupIcon, LinkIcon, OpenExternalIcon } from '@/icons/vue-material'
import { tryShowError } from '@/utils/errorHandling'
import { t } from '@/utils/l10n'

interface Props {
	targetType: 'score' | 'folder_collection'
	targetId: number
}

const props = defineProps<Props>()

const emit = defineEmits<{
	changed: []
}>()

const shares = ref<Share[]>([])
const groups = ref<ShareGroup[]>([])
const selectedGroup = ref<ShareGroup | null>(null)
const label = ref('')
const loading = ref(true)
const busy = ref(false)

const availableGroups = computed(() => groups.value.filter((group) => !shares.value.some((share) => share.groupId === group.id)))

/**
 * Load the shares of the target and the groups to choose from
 */
async function load(): Promise<void> {
	loading.value = true
	await tryShowError(async () => {
		const [sharesResponse, groupsResponse] = await Promise.all([
			apiClients.default.shareApiGetShares({ targetType: props.targetType, targetId: props.targetId }),
			groups.value.length > 0 ? null : apiClients.default.shareApiGetShareGroups(),
		])
		shares.value = sharesResponse.data.ocs.data
		if (groupsResponse) {
			groups.value = groupsResponse.data.ocs.data
		}
	}, t('Failed to load shares: '))
	loading.value = false
}

/**
 * @param shareType - Whether to create a public link or a group share
 */
async function createShare(shareType: 'link' | 'group'): Promise<void> {
	busy.value = true
	const created = await tryShowError(async () => {
		const response = await apiClients.default.shareApiPostShare({
			shareType,
			targetType: props.targetType,
			targetId: props.targetId,
			groupId: shareType === 'group' ? selectedGroup.value?.id : undefined,
			label: label.value.trim() || undefined,
		})
		return response.data.ocs.data
	}, t('Failed to share: '))
	busy.value = false
	if (created) {
		shares.value.push(created)
		emit('changed')
		selectedGroup.value = null
		label.value = ''
		if (created.shareType === 'link') {
			await copyLink(created)
		}
	}
}

/**
 * @param share - The share to remove
 */
async function removeShare(share: Share): Promise<void> {
	await tryShowError(async () => {
		await apiClients.default.shareApiDeleteShare(share.id)
		shares.value = shares.value.filter((s) => s.id !== share.id)
		emit('changed')
	}, t('Failed to remove share: '))
}

/**
 * @param share - A link share
 */
function linkUrl(share: Share): string {
	return window.location.origin + generateUrl('/apps/orchestrascoresmanager/s/{token}', { token: share.token ?? '' })
}

/**
 * @param share - A link share
 */
async function copyLink(share: Share): Promise<void> {
	try {
		await navigator.clipboard.writeText(linkUrl(share))
		showSuccess(t('Link copied to the clipboard'))
	} catch {
		// Clipboard access can be denied, the link is shown in the list anyway
	}
}

onMounted(load)
watch(() => [props.targetType, props.targetId], load)
</script>

<style lang="scss" scoped>
.share-manager {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));

	&__state {
		display: flex;
		justify-content: center;
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

	&__icon {
		display: flex;
		color: var(--color-text-maxcontrast);
	}

	&__text {
		display: flex;
		flex: 1 1 auto;
		flex-direction: column;
		min-width: 0;
	}

	&__title {
		font-weight: bold;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
	}

	&__url {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__add {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__group-select {
		flex: 1 1 200px;
		min-width: 0;
	}
}
</style>
