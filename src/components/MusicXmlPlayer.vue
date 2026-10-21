<template>
	<div class="score-player">
		<div class="score-player__toolbar">
			<NcButton
				variant="primary"
				:disabled="status !== 'ready'"
				:aria-label="isPlaying ? t('Pause') : t('Play')"
				:title="isPlaying ? t('Pause') : t('Play')"
				@click="isPlaying ? pause() : play()">
				<template #icon>
					<PauseIcon v-if="isPlaying" :size="20" />
					<PlayIcon v-else :size="20" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="status !== 'ready'"
				:aria-label="t('Stop')"
				:title="t('Stop')"
				@click="stop()">
				<template #icon>
					<StopIcon :size="20" />
				</template>
			</NcButton>

			<span class="score-player__time">{{ formatTime(position) }} / {{ formatTime(duration) }}</span>

			<label class="score-player__tempo">
				<span>{{ t('Tempo') }} {{ tempo }}%</span>
				<input
					type="range"
					min="25"
					max="150"
					step="5"
					:value="tempo"
					:disabled="status !== 'ready'"
					@input="setTempo(Number(($event.target as HTMLInputElement).value))">
			</label>

			<div v-if="pageCount > 1" class="score-player__pages">
				<NcButton
					variant="tertiary"
					:disabled="currentPage <= 1"
					:aria-label="t('Previous page')"
					@click="renderPage(currentPage - 1)">
					<template #icon>
						<PreviousIcon :size="20" />
					</template>
				</NcButton>
				<span>{{ t('Page {page} of {count}', { page: currentPage, count: pageCount }) }}</span>
				<NcButton
					variant="tertiary"
					:disabled="currentPage >= pageCount"
					:aria-label="t('Next page')"
					@click="renderPage(currentPage + 1)">
					<template #icon>
						<NextIcon :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<div v-if="voices.length > 0" class="score-player__voices">
			<div v-for="voice in voices" :key="voice.key" class="score-player__voice">
				<span class="score-player__voice-label">{{ voice.label }}</span>
				<NcCheckboxRadioSwitch
					v-model="voice.solo"
					type="switch"
					@update:modelValue="updateVoice(voice.key)">
					{{ t('Solo') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="voice.muted"
					type="switch"
					@update:modelValue="updateVoice(voice.key)">
					{{ t('Mute') }}
				</NcCheckboxRadioSwitch>
				<input
					v-model.number="voice.volume"
					type="range"
					min="-30"
					max="6"
					step="1"
					:aria-label="t('Volume of {voice}', { voice: voice.label })"
					:title="t('Volume of {voice}', { voice: voice.label })"
					@input="updateVoice(voice.key)">
			</div>
		</div>

		<div v-if="status === 'loading'" class="score-player__state">
			<NcLoadingIcon :size="32" />
			<span>{{ t('Loading score…') }}</span>
		</div>
		<NcNoteCard v-else-if="status === 'error'" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<!-- The rendered score is inserted here by the player -->
		<div
			ref="scoreContainer"
			class="score-player__score"
			:class="{ 'score-player__score--hidden': status !== 'ready' }"
			@click="onScoreClick" />
	</div>
</template>

<script setup lang="ts">
import debounce from 'lodash.debounce'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { NextIcon, PauseIcon, PlayIcon, PreviousIcon, StopIcon } from '@/icons/vue-material'
import { useScorePlayer } from '@/player/useScorePlayer.ts'
import { t } from '@/utils/l10n'

interface Props {
	/**
	 * Function loading the MusicXML file (uncompressed or .mxl)
	 */
	loadFile: () => Promise<ArrayBuffer>
}

const props = defineProps<Props>()

const scoreContainer = ref<HTMLElement | null>(null)
const {
	status,
	errorMessage,
	pageCount,
	currentPage,
	voices,
	isPlaying,
	tempo,
	position,
	duration,
	load,
	renderPage,
	relayout,
	play,
	pause,
	stop,
	seekToElement,
	setTempo,
	updateVoice,
	dispose,
} = useScorePlayer(scoreContainer)

/**
 * Fetch and load the score
 */
async function loadScore(): Promise<void> {
	status.value = 'loading'
	try {
		const data = await props.loadFile()
		await load(data)
	} catch (error) {
		console.error('Failed to fetch score:', error)
		errorMessage.value = t('The score could not be loaded')
		status.value = 'error'
	}
}

/**
 * Jump to a clicked note
 *
 * @param event - The click event
 */
function onScoreClick(event: MouseEvent): void {
	const note = (event.target as Element | null)?.closest('g.note')
	if (note?.id) {
		seekToElement(note.id)
	}
}

/**
 * @param ms - Time in milliseconds
 */
function formatTime(ms: number): string {
	const totalSeconds = Math.floor(ms / 1000 * 100 / tempo.value)
	const minutes = Math.floor(totalSeconds / 60)
	const seconds = totalSeconds % 60
	return `${minutes}:${String(seconds).padStart(2, '0')}`
}

const onResize = debounce(() => relayout(), 300)
let resizeObserver: ResizeObserver | null = null
let lastWidth = 0

onMounted(() => {
	loadScore()
	if (scoreContainer.value) {
		resizeObserver = new ResizeObserver((entries) => {
			const width = Math.round(entries[0].contentRect.width)
			if (width > 0 && Math.abs(width - lastWidth) > 20) {
				lastWidth = width
				onResize()
			}
		})
		resizeObserver.observe(scoreContainer.value)
	}
})

watch(() => props.loadFile, () => loadScore())

onBeforeUnmount(() => {
	resizeObserver?.disconnect()
	onResize.cancel()
	dispose()
})
</script>

<style lang="scss" scoped>
.score-player {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__time {
		font-variant-numeric: tabular-nums;
		color: var(--color-text-maxcontrast);
	}

	&__tempo {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__pages {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__voices {
		display: flex;
		flex-wrap: wrap;
		gap: calc(2 * var(--default-grid-baseline)) calc(4 * var(--default-grid-baseline));
	}

	&__voice {
		flex: 1 1 280px;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		min-width: 0;
		gap: calc(2 * var(--default-grid-baseline));
		padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__voice-label {
		font-weight: bold;
		min-width: 5em;
	}

	&__voice input[type='range'] {
		flex: 1 1 80px;
		min-width: 80px;
	}

	&__state {
		display: flex;
		align-items: center;
		gap: calc(2 * var(--default-grid-baseline));
	}

	&__score {
		width: 100%;
		background: #fff;
		color: #000;
		border-radius: var(--border-radius-large);
		cursor: pointer;

		&--hidden {
			display: none;
		}

		:deep(svg) {
			display: block;
			width: 100%;
			height: auto;
		}

		:deep(.score-player__note--playing) {
			fill: var(--color-primary-element);
			stroke: var(--color-primary-element);
		}
	}
}
</style>
