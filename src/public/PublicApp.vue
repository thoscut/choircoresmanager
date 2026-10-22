<template>
	<div class="public-scores">
		<header class="public-scores__header">
			<h2>{{ t('Freely available scores') }}</h2>
			<p class="public-scores__intro">
				{{ t('These scores are in the public domain or may be provided digitally under a licence. Select a score to view the notes and listen to the individual voices.') }}
			</p>
			<p class="public-scores__disclaimer">
				{{ t('All content is provided as is, dated with its last update, without warranty and without any obligation to keep earlier versions available.') }}
			</p>
			<NcTextField
				v-model="search"
				class="public-scores__search"
				:label="t('Search by title or composer')"
				trailingButtonIcon="close"
				:showTrailingButton="search !== ''"
				@trailingButtonClick="search = ''" />
		</header>

		<section v-if="selectedScore" ref="playerSection" class="public-scores__player">
			<div class="public-scores__player-header">
				<div>
					<h3>{{ selectedScore.title }}</h3>
					<p class="public-scores__meta">
						{{ describe(selectedScore) }}
					</p>
					<p v-if="selectedScore.lastUpdated" class="public-scores__meta">
						{{ lastUpdatedText(selectedScore.lastUpdated) }}
					</p>
				</div>
				<div class="public-scores__player-actions">
					<NcButton variant="secondary" :href="musicXmlUrl(selectedScore.id)">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('Download MusicXML') }}
					</NcButton>
					<NcButton variant="tertiary" :aria-label="t('Close')" @click="selectScore(null)">
						<template #icon>
							<CloseIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
			<MusicXmlPlayer :key="selectedScore.id" :loadFile="loadSelectedFile" />
		</section>

		<NcEmptyContent
			v-if="filteredScores.length === 0"
			:name="scores.length === 0 ? t('No freely available scores yet') : t('No scores match your search')">
			<template #icon>
				<ScoreIcon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="public-scores__list">
			<li
				v-for="score in filteredScores"
				:key="score.id"
				class="public-scores__item"
				:class="{ 'public-scores__item--active': selectedScore?.id === score.id }">
				<div class="public-scores__item-text">
					<span class="public-scores__item-title">{{ score.title }}</span>
					<span class="public-scores__meta">{{ describe(score) }}</span>
					<span v-if="score.lastUpdated" class="public-scores__meta">{{ lastUpdatedText(score.lastUpdated) }}</span>
				</div>
				<NcButton
					v-if="score.hasMusicXml"
					:variant="selectedScore?.id === score.id ? 'primary' : 'secondary'"
					@click="selectScore(score)">
					<template #icon>
						<PlayIcon :size="20" />
					</template>
					{{ t('Show & play') }}
				</NcButton>
				<span v-else class="public-scores__no-file">{{ t('No digital notes') }}</span>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { computed, nextTick, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MusicXmlPlayer from '@/components/MusicXmlPlayer.vue'
import { formatDateStr } from '@/composables/useDateFormatting'
import { CloseIcon, DownloadIcon, PlayIcon, ScoreIcon } from '@/icons/vue-material'
import { t } from '@/utils/l10n'
import { formatDurationHHMMSS } from '@/utils/timeFormatUtils'

interface PublicScore {
	id: number
	title: string
	composer: string | null
	arranger: string | null
	year: number | null
	duration: number | null
	hasMusicXml: boolean
	/** Date (Y-m-d) of the last change of the score or its MusicXML file */
	lastUpdated: string | null
}

const scores = loadState<PublicScore[]>('orchestrascoresmanager', 'publicScores', [])
const search = ref('')
const selectedScore = ref<PublicScore | null>(null)
const playerSection = ref<HTMLElement | null>(null)

const filteredScores = computed(() => {
	const query = search.value.trim().toLocaleLowerCase()
	if (!query) {
		return scores
	}
	return scores.filter((score) => [score.title, score.composer, score.arranger]
		.some((value) => value?.toLocaleLowerCase().includes(query)))
})

/**
 * @param id - The score ID
 */
function musicXmlUrl(id: number): string {
	return generateUrl('/apps/orchestrascoresmanager/public/scores/{id}/musicxml', { id })
}

/**
 * Load the MusicXML file of the selected score for the player
 */
async function loadSelectedFile(): Promise<ArrayBuffer> {
	const response = await fetch(musicXmlUrl(selectedScore.value!.id))
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`)
	}
	return response.arrayBuffer()
}

/**
 * Text stating when a score was last updated
 *
 * @param date - The date in Y-m-d format
 */
function lastUpdatedText(date: string): string {
	return t('Last updated: {date}', { date: formatDateStr(date) })
}

/**
 * One line describing composer, arranger, year and duration of a score
 *
 * @param score - The score
 */
function describe(score: PublicScore): string {
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

/**
 * Show a score in the player and remember it in the address
 *
 * @param score - The score to show, or null to close the player
 */
async function selectScore(score: PublicScore | null): Promise<void> {
	selectedScore.value = score
	history.replaceState(null, '', score ? `#score-${score.id}` : window.location.pathname + window.location.search)
	if (score) {
		await nextTick()
		playerSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
	}
}

onMounted(() => {
	const match = window.location.hash.match(/^#score-(\d+)$/)
	if (match) {
		const score = scores.find((s) => s.id === Number(match[1]) && s.hasMusicXml)
		if (score) {
			selectScore(score)
		}
	}
})
</script>

<style lang="scss" scoped>
.public-scores {
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

	h3 {
		margin: 0;
	}

	&__header {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__intro,
	&__meta {
		color: var(--color-text-maxcontrast);
	}

	&__disclaimer {
		color: var(--color-text-maxcontrast);
		font-size: var(--font-size-small, 13px);
	}

	&__search {
		max-width: 400px;
	}

	&__player {
		display: flex;
		flex-direction: column;
		gap: calc(2 * var(--default-grid-baseline));
		padding: calc(3 * var(--default-grid-baseline));
		border: 2px solid var(--color-primary-element);
		border-radius: var(--border-radius-large);
	}

	&__player-header {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__player-actions {
		display: flex;
		gap: var(--default-grid-baseline);
		align-items: flex-start;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(2 * var(--default-grid-baseline));
		padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);

		&--active {
			background: var(--color-primary-element-light);
		}
	}

	&__item-text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__item-title {
		font-weight: bold;
	}

	&__no-file {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}
}
</style>
