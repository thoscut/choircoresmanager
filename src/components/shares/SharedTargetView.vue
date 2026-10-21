<template>
	<div class="shared-target">
		<section v-if="selected" ref="viewerSection" class="shared-target__viewer">
			<div class="shared-target__viewer-header">
				<div>
					<h3>{{ selected.score.title }}</h3>
					<p class="shared-target__meta">
						{{ describe(selected.score) }}
					</p>
				</div>
				<NcButton variant="tertiary" :aria-label="t('Close')" @click="select(null)">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</div>
			<MusicXmlPlayer
				v-if="selected.kind === 'musicxml'"
				:key="`musicxml-${selected.score.id}`"
				:loadFile="loadSelectedFile" />
			<PdfViewer
				v-else
				:key="`pdf-${selected.score.id}`"
				:loadFile="loadSelectedFile" />
		</section>

		<NcEmptyContent
			v-if="target.scores.length === 0"
			:name="emptyText">
			<template #icon>
				<ScoreIcon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="shared-target__list">
			<li
				v-for="score in target.scores"
				:key="score.id"
				class="shared-target__item"
				:class="{ 'shared-target__item--active': selected?.score.id === score.id }">
				<span v-if="score.index !== null" class="shared-target__index">{{ score.index }}</span>
				<div class="shared-target__item-text">
					<span class="shared-target__item-title">{{ score.title }}</span>
					<span class="shared-target__meta">{{ describe(score) }}</span>
				</div>
				<div class="shared-target__item-actions">
					<NcButton
						v-if="score.hasMusicXml"
						:variant="isSelected(score, 'musicxml') ? 'primary' : 'secondary'"
						@click="select(score, 'musicxml')">
						<template #icon>
							<PlayIcon :size="20" />
						</template>
						{{ t('Show & play') }}
					</NcButton>
					<NcButton
						v-if="score.hasPdf"
						:variant="isSelected(score, 'pdf') ? 'primary' : 'secondary'"
						@click="select(score, 'pdf')">
						<template #icon>
							<PDFIcon :size="20" />
						</template>
						{{ t('PDF') }}
					</NcButton>
					<span v-if="!score.hasMusicXml && !score.hasPdf" class="shared-target__meta">{{ t('No digital notes') }}</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { SharedScore, SharedTarget } from '@/api/generated/openapi/data-contracts'

import { nextTick, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import MusicXmlPlayer from '@/components/MusicXmlPlayer.vue'
import PdfViewer from '@/components/PdfViewer.vue'
import { CloseIcon, PDFIcon, PlayIcon, ScoreIcon } from '@/icons/vue-material'
import { t } from '@/utils/l10n'
import { formatDurationHHMMSS } from '@/utils/timeFormatUtils'

type FileKind = 'musicxml' | 'pdf'

interface Props {
	/**
	 * The shared score or folder collection
	 */
	target: SharedTarget
	/**
	 * Function loading a file of a score in the share
	 */
	loadFile: (scoreId: number, kind: FileKind) => Promise<ArrayBuffer>
	/**
	 * Text shown when the share contains no scores
	 */
	emptyText?: string
}

const props = withDefaults(defineProps<Props>(), {
	emptyText: () => t('No scores shared'),
})

const selected = ref<{ score: SharedScore, kind: FileKind } | null>(null)
const viewerSection = ref<HTMLElement | null>(null)

/**
 * Load the file of the selected score
 */
function loadSelectedFile(): Promise<ArrayBuffer> {
	return props.loadFile(selected.value!.score.id, selected.value!.kind)
}

/**
 * @param score - A score of the share
 * @param kind - The file type
 */
function isSelected(score: SharedScore, kind: FileKind): boolean {
	return selected.value?.score.id === score.id && selected.value.kind === kind
}

/**
 * Show a file of a score, or close the viewer
 *
 * @param score - The score, or null to close the viewer
 * @param kind - The file type to show
 */
async function select(score: SharedScore | null, kind: FileKind = 'musicxml'): Promise<void> {
	selected.value = score ? { score, kind } : null
	if (score) {
		await nextTick()
		viewerSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
	}
}

/**
 * One line describing composer, arranger, year and duration of a score
 *
 * @param score - The score
 */
function describe(score: SharedScore): string {
	const parts: string[] = []
	if (score.composer) {
		parts.push(score.composer)
	}
	if (score.arranger) {
		parts.push(t('arr. {arranger}', { arranger: score.arranger }))
	}
	if (score.year) {
		parts.push(String(score.year))
	}
	if (score.duration) {
		parts.push(formatDurationHHMMSS(score.duration))
	}
	return parts.join(' · ')
}
</script>

<style lang="scss" scoped>
.shared-target {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));

	h3 {
		margin: 0;
	}

	&__viewer {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		padding: calc(3 * var(--default-grid-baseline));
		border: 2px solid var(--color-primary-element);
		border-radius: var(--border-radius-large);
	}

	&__viewer-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: calc(2 * var(--default-grid-baseline));
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
		flex-wrap: wrap;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
		padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);

		&--active {
			background: var(--color-primary-element-light);
		}
	}

	&__index {
		min-width: 2em;
		font-weight: bold;
		font-variant-numeric: tabular-nums;
		color: var(--color-text-maxcontrast);
	}

	&__item-text {
		display: flex;
		flex: 1 1 200px;
		flex-direction: column;
		min-width: 0;
	}

	&__item-title {
		font-weight: bold;
	}

	&__item-actions {
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
	}
}
</style>
