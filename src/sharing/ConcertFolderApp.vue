<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The concert folder of the conductor (module "concert_folder", milestone
 * 0.15): the programmes still to be sung in their order, each item with
 * its score (to view, play and practise, as on the shared pages) and its
 * scan. A whole programme can be kept on this device for the rehearsal and
 * the concert without a network; at every start with a network the kept
 * items are checked with the server. Only reads: nothing is changed from
 * here, also not "sung" (that is confirmed in the archive afterwards).
 */
import type { Catalog, PieceForDevice } from '../offline/rules.ts'
import type { FolderConcert, FolderItem, FolderPageState } from '../types.ts'

import { mdiAlertCircleOutline, mdiMusicNoteOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ExcerptViewer from './ExcerptViewer.vue'
import ScanFrame from './ScanFrame.vue'
import { focusAfterUpdate } from '../focus.ts'
import { formatDate, formatDateTime, formatSize } from '../format.ts'
import { askToPersist, checkWithServer, forgetPieces, keepPieces, offlineSupported, readCatalog, removeEverything, setUpWorker, storageInfo } from '../offline/device.ts'
import { MAX_DAYS_UNCHECKED, usable } from '../offline/rules.ts'
import { forgetPractising } from '../score/practiceSettings.ts'
import { fetchFolderConcerts, fetchFolderScore } from './api.ts'

function initialState(): FolderPageState {
	try {
		const state = loadState<FolderPageState>('notenarchiv', 'folder', { concerts: [], version: '' })
		return { concerts: Array.isArray(state.concerts) ? state.concerts : [], version: String(state.version ?? '') }
	} catch {
		return { concerts: [], version: '' }
	}
}

const state = initialState()
const concerts = state.concerts
const offlineOn = offlineSupported()
const pageHeadingId = useId()
const deviceHeadingId = useId()
const itemHeadingId = useId()
const itemHeading = ref<HTMLElement | null>(null)
const archiveUrl = generateUrl('/apps/notenarchiv/')

const selectedId = ref<number | null>(null)
const selectedConcert = computed(() => concerts.find((concert) => concert.items.some((item) => item.id === selectedId.value)) ?? null)
const selected = computed(() => selectedConcert.value?.items.find((item) => item.id === selectedId.value) ?? null)
const scoreStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
const scoreXml = ref('')
const scoreError = ref('')

// ---- kept on this device ---------------------------------------------------

const catalog = ref<Catalog | null>(null)
/** The server answered the last check; false: shown from the device */
const reachable = ref(true)
/** Id of the programme being kept or removed */
const keeping = ref<number | null>(null)
const withScans = ref<Record<number, boolean>>({})
const keepError = ref('')
const storage = ref<{ usage: number | null, quota: number | null, persisted: boolean } | null>(null)
const confirmingDelete = ref(false)
const deviceStatus = ref('')
const withoutNetwork = computed(() => offlineOn && !reachable.value)
const keptCount = computed(() => Object.keys(catalog.value?.pieces ?? {}).length)

/**
 * What the server says about keeping an item.
 *
 * @param item - programme item
 */
function forDevice(item: FolderItem): PieceForDevice {
	return { key: String(item.id), title: item.title, composer: item.composer, expires: null, offline: item.offline, scan: item.scan }
}

function isKept(item: FolderItem): boolean {
	return catalog.value?.pieces[String(item.id)] !== undefined
}

function scanKept(item: FolderItem): boolean {
	return catalog.value?.pieces[String(item.id)]?.scan === true
}

/**
 * The items of a programme that may be kept.
 *
 * @param concert - programme
 */
function keepable(concert: FolderConcert): FolderItem[] {
	return concert.items.filter((item) => item.offline)
}

/**
 * Whether every item of a programme that may be kept is kept.
 *
 * @param concert - programme
 */
function concertKept(concert: FolderConcert): boolean {
	const items = keepable(concert)
	return items.length > 0 && items.every(isKept)
}

/**
 * Why an item cannot be shown without a network, or ''.
 *
 * @param item - programme item
 */
function lockText(item: FolderItem): string {
	switch (usable(catalog.value, String(item.id), new Date())) {
	case 'ok':
		return ''
	case 'unchecked':
		return n('notenarchiv', 'Locked: not checked with the server for more than %n day. Please open this page once with a network.',
			'Locked: not checked with the server for more than %n days. Please open this page once with a network.', MAX_DAYS_UNCHECKED)
	default:
		return t('notenarchiv', 'There is no network, and this piece is not kept on this device.')
	}
}

async function refreshStorage(): Promise<void> {
	storage.value = await storageInfo()
}

/** Check the kept items with the server; without an answer the page shows what is kept. */
async function checkDevice(): Promise<void> {
	if (!offlineOn) {
		return
	}
	catalog.value = await readCatalog('folder')
	try {
		const fresh = await fetchFolderConcerts()
		reachable.value = true
		catalog.value = await checkWithServer('folder', fresh.flatMap((concert) => concert.items.map(forDevice)))
	} catch (error) {
		// an answer of the server (e.g. not logged in) is no missing network
		reachable.value = isAxiosError(error) && error.response !== undefined
	}
	await refreshStorage()
}

/**
 * Keep the items of a programme on this device, or remove them.
 *
 * @param concert - programme
 * @param keep - keep them
 */
async function setConcertKept(concert: FolderConcert, keep: boolean): Promise<void> {
	keeping.value = concert.id
	keepError.value = ''
	const items = keepable(concert)
	try {
		if (keep) {
			catalog.value = await keepPieces('folder', items.map(forDevice), withScans.value[concert.id] === true, state.version)
			await askToPersist()
			deviceStatus.value = n('notenarchiv', '%n piece is kept on this device.', '%n pieces are kept on this device.', items.length)
		} else {
			catalog.value = await forgetPieces('folder', items.map((item) => String(item.id)))
			deviceStatus.value = t('notenarchiv', '"{title}" is removed from this device.', { title: concert.title })
		}
	} catch (error) {
		keepError.value = t('notenarchiv', 'Not every piece could be kept on this device: {reason}', {
			reason: error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'Please check the network and the space on the device.'),
		})
		catalog.value = await readCatalog('folder')
	} finally {
		keeping.value = null
		await refreshStorage()
	}
}

/** Delete the kept items and everything remembered for practising, on this device only. */
async function deleteDevice(): Promise<void> {
	await removeEverything('folder')
	forgetPractising()
	catalog.value = null
	confirmingDelete.value = false
	deviceStatus.value = t('notenarchiv', 'Everything the concert folder kept on this device is deleted.')
	await refreshStorage()
}

const storageText = computed(() => {
	const info = storage.value
	if (!info || info.usage === null) {
		return ''
	}
	return info.quota === null
		? t('notenarchiv', '{used} used on this device.', { used: formatSize(info.usage) })
		: t('notenarchiv', '{used} of {available} used on this device.', { used: formatSize(info.usage), available: formatSize(info.quota) })
})

const checkedText = computed(() => {
	const checked = catalog.value ? Date.parse(catalog.value.checked) : NaN
	return Number.isFinite(checked)
		? t('notenarchiv', 'Last checked with the server: {time}.', { time: formatDateTime(Math.floor(checked / 1000)) })
		: ''
})

/**
 * Date, time and place of a programme.
 *
 * @param concert - programme
 */
function when(concert: FolderConcert): string {
	return [concert.date ? formatDate(concert.date) : '', concert.startTime ?? '', concert.location ?? ''].filter((part) => part !== '').join(' · ')
}

/**
 * Minutes and seconds of a duration.
 *
 * @param seconds - duration in seconds
 */
function duration(seconds: number): string {
	return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

// ---- the item shown --------------------------------------------------------

async function loadSelectedScore(): Promise<void> {
	const item = selected.value
	if (!item || !item.score) {
		scoreStatus.value = 'idle'
		return
	}
	if (withoutNetwork.value && lockText(item) !== '') {
		scoreStatus.value = 'error'
		scoreError.value = lockText(item)
		return
	}
	scoreStatus.value = 'loading'
	scoreError.value = ''
	try {
		const xml = await fetchFolderScore(item.id)
		if (selectedId.value === item.id) {
			scoreXml.value = xml
			scoreStatus.value = 'ready'
		}
	} catch (error) {
		if (selectedId.value === item.id) {
			scoreStatus.value = 'error'
			scoreError.value = error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'The score could not be loaded.')
		}
	}
}

/**
 * Show an item; the address names it, so that it can be bookmarked.
 *
 * @param item - programme item
 * @param moveFocus - put the focus on its heading
 */
async function select(item: FolderItem, moveFocus = true): Promise<void> {
	selectedId.value = item.id
	scoreXml.value = ''
	window.history.replaceState(null, '', window.location.pathname + '?item=' + item.id + window.location.hash)
	if (moveFocus) {
		focusAfterUpdate(itemHeading)
	}
	await loadSelectedScore()
}

/** The item before and after the shown one in its programme, for turning to the next piece */
const neighbours = computed(() => {
	const items = selectedConcert.value?.items ?? []
	const index = items.findIndex((item) => item.id === selectedId.value)
	return { previous: index > 0 ? items[index - 1] : null, next: index >= 0 && index < items.length - 1 ? items[index + 1] : null }
})

function onOnline(): void {
	checkDevice()
}

onMounted(async () => {
	if (offlineOn) {
		await setUpWorker('folder', true)
		await checkDevice()
		window.addEventListener('online', onOnline)
	}
	const id = Number(new URLSearchParams(window.location.search).get('item'))
	const linked = concerts.flatMap((concert) => concert.items).find((item) => item.id === id)
	if (linked) {
		select(linked, false)
	}
})

onBeforeUnmount(() => window.removeEventListener('online', onOnline))
</script>

<template>
	<NcContent app-name="notenarchiv">
		<NcAppContent :page-heading="t('notenarchiv', 'Concert folder')"
			:page-title="t('notenarchiv', 'Concert folder') + ' - ' + t('notenarchiv', 'Music archive')">
			<div class="folder-app">
				<h2 :id="pageHeadingId" class="folder-app__title">
					{{ t('notenarchiv', 'Concert folder') }}
				</h2>
				<p class="folder-app__intro">
					{{ t('notenarchiv', 'The programmes still to be sung, in their order: view, play and practise each piece, and keep a whole programme on this device for the rehearsal and the concert without a network.') }}
					<a v-if="!withoutNetwork" :href="archiveUrl" class="folder-app__link">{{ t('notenarchiv', 'Back to the music archive') }}</a>
				</p>

				<NcNoteCard v-if="withoutNetwork" type="info" role="status">
					{{ keptCount > 0
						? t('notenarchiv', 'No network: the pieces kept on this device can be viewed, played and practised. {checked}', { checked: checkedText })
						: t('notenarchiv', 'No network, and no piece is kept on this device.') }}
				</NcNoteCard>

				<NcEmptyContent v-if="concerts.length === 0"
					:name="t('notenarchiv', 'No programme to be sung')"
					:description="t('notenarchiv', 'Concerts and song lists in draft or fixed appear here, the next one first.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
					</template>
				</NcEmptyContent>

				<div v-else class="folder-app__layout">
					<div class="folder-app__list">
						<section v-for="concert in concerts"
							:key="concert.id"
							class="folder-app__concert"
							:aria-labelledby="`folder-concert-${concert.id}`">
							<h3 :id="`folder-concert-${concert.id}`" class="folder-app__concert-title">
								{{ concert.title || (concert.kind === 'song_list' ? t('notenarchiv', 'Song list') : t('notenarchiv', 'Concert')) }}
							</h3>
							<p v-if="when(concert)" class="folder-app__muted">
								{{ when(concert) }}
							</p>
							<p class="folder-app__muted">
								{{ concert.status === 'fixed' ? t('notenarchiv', 'Programme fixed') : t('notenarchiv', 'Draft programme') }}
							</p>
							<div v-if="offlineOn && keepable(concert).length > 0" class="folder-app__device">
								<NcCheckboxRadioSwitch type="switch"
									:model-value="concertKept(concert)"
									:disabled="withoutNetwork || keeping !== null"
									@update:model-value="(on: boolean) => setConcertKept(concert, on)">
									{{ n('notenarchiv', 'Keep %n piece on this device', 'Keep all %n pieces on this device', keepable(concert).length) }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch v-if="!concertKept(concert) && concert.items.some((item) => item.scan && item.offline)"
									:model-value="withScans[concert.id] === true"
									:disabled="withoutNetwork || keeping !== null"
									@update:model-value="(on: boolean) => withScans[concert.id] = on">
									{{ t('notenarchiv', 'With the scans (PDF)') }}
								</NcCheckboxRadioSwitch>
								<p v-if="keeping === concert.id" class="folder-app__muted">
									{{ t('notenarchiv', 'Keeping on this device …') }}
								</p>
							</div>
							<ol class="folder-app__items">
								<li v-for="item in concert.items"
									:key="item.id"
									class="folder-app__item"
									:class="{ 'folder-app__item--selected': item.id === selectedId }">
									<button type="button"
										class="folder-app__open"
										:aria-current="item.id === selectedId ? 'true' : undefined"
										@click="select(item)">
										{{ item.title }}
									</button>
									<p v-if="item.composer" class="folder-app__muted">
										{{ item.composer }}
									</p>
									<p class="folder-app__muted">
										<template v-if="item.shelfmark">
											{{ item.shelfmark }} ·
										</template>
										<template v-if="item.encore">
											{{ t('notenarchiv', 'Encore') }} ·
										</template>
										<template v-if="item.duration">
											{{ duration(item.duration) }} ·
										</template>
										{{ item.score ? t('notenarchiv', 'Score') : t('notenarchiv', 'No score') }}<template v-if="item.scan">
											· {{ t('notenarchiv', 'Scan') }}
										</template>
									</p>
									<p v-if="item.participation || item.note" class="folder-app__note-text">
										{{ [item.participation, item.note].filter(Boolean).join(' · ') }}
									</p>
									<p v-if="offlineOn && isKept(item)" class="folder-app__muted">
										{{ lockText(item) !== '' && withoutNetwork ? lockText(item) : (scanKept(item) ? t('notenarchiv', 'On this device, with the scan') : t('notenarchiv', 'On this device')) }}
									</p>
									<p v-else-if="offlineOn && item.offlineReason" class="folder-app__muted">
										{{ t('notenarchiv', 'Cannot be kept on this device: {reason}', { reason: item.offlineReason }) }}
									</p>
								</li>
							</ol>
						</section>
					</div>

					<section v-if="selected" class="folder-app__piece" :aria-labelledby="itemHeadingId">
						<h3 :id="itemHeadingId" ref="itemHeading" class="folder-app__concert-title">
							{{ selected.title }}
						</h3>
						<p v-if="selected.composer" class="folder-app__muted">
							{{ selected.composer }}
						</p>
						<nav class="folder-app__turn" :aria-label="t('notenarchiv', 'Programme order')">
							<NcButton v-if="neighbours.previous" variant="tertiary" @click="select(neighbours.previous)">
								{{ t('notenarchiv', 'Previous: {title}', { title: neighbours.previous.title }) }}
							</NcButton>
							<NcButton v-if="neighbours.next" variant="secondary" @click="select(neighbours.next)">
								{{ t('notenarchiv', 'Next: {title}', { title: neighbours.next.title }) }}
							</NcButton>
						</nav>
						<p v-if="selected.scan && withoutNetwork && !scanKept(selected)" class="folder-app__muted">
							{{ t('notenarchiv', 'The scan is not kept on this device.') }}
						</p>
						<ScanFrame v-else-if="selected.scan"
							:key="selected.id"
							collection="folder"
							:piece-key="String(selected.id)"
							:title="selected.title" />

						<NcEmptyContent v-if="!selected.score"
							:name="t('notenarchiv', 'No score')"
							:description="selected.reason ?? t('notenarchiv', 'This programme item has no MusicXML score.')">
							<template #icon>
								<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
							</template>
						</NcEmptyContent>
						<NcEmptyContent v-else-if="scoreStatus === 'loading'" :name="t('notenarchiv', 'Loading the score …')">
							<template #icon>
								<NcLoadingIcon :size="64" />
							</template>
						</NcEmptyContent>
						<NcEmptyContent v-else-if="scoreStatus === 'error'"
							:name="t('notenarchiv', 'The score cannot be shown')"
							:description="scoreError">
							<template #icon>
								<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
							</template>
							<template #action>
								<NcButton @click="loadSelectedScore">
									{{ t('notenarchiv', 'Try again') }}
								</NcButton>
							</template>
						</NcEmptyContent>
						<ExcerptViewer v-else-if="scoreStatus === 'ready'"
							:key="selected.id"
							:xml="scoreXml"
							:title="selected.title"
							:composer="selected.composer"
							:measure-from="null"
							:measure-to="null"
							:can-play="selected.canPlay"
							:play-reason="selected.reason"
							:practice-key="selected.practiceKey"
							:offline="withoutNetwork" />
					</section>
					<p v-else class="folder-app__muted">
						{{ t('notenarchiv', 'Choose a piece of a programme to see and hear it.') }}
					</p>
				</div>

				<section class="folder-app__device-section" :aria-labelledby="deviceHeadingId">
					<h3 :id="deviceHeadingId" class="folder-app__concert-title">
						{{ t('notenarchiv', 'On this device') }}
					</h3>
					<template v-if="offlineOn">
						<p>
							{{ n('notenarchiv', '%n piece is kept on this device for practising without a network.', '%n pieces are kept on this device for practising without a network.', keptCount) }}
							{{ storageText }}
						</p>
						<p v-if="keptCount > 0" class="folder-app__muted">
							{{ checkedText }}
							{{ storage?.persisted ? t('notenarchiv', 'The browser keeps them even when space runs short.') : t('notenarchiv', 'The browser may delete them when space runs short; on an iPhone or iPad, add this page to the home screen.') }}
						</p>
					</template>
					<p v-else class="folder-app__muted">
						{{ t('notenarchiv', 'This browser cannot keep pieces for practising without a network.') }}
					</p>
					<NcNoteCard v-if="keepError" type="error" role="alert">
						{{ keepError }}
					</NcNoteCard>
					<div v-if="offlineOn" class="folder-app__device-buttons">
						<NcButton v-if="!confirmingDelete" variant="secondary" @click="confirmingDelete = true">
							{{ t('notenarchiv', 'Delete everything on this device') }}
						</NcButton>
						<template v-else>
							<NcButton variant="error" @click="deleteDevice">
								{{ t('notenarchiv', 'Delete kept pieces, passages and settings') }}
							</NcButton>
							<NcButton variant="tertiary" @click="confirmingDelete = false">
								{{ t('notenarchiv', 'Cancel') }}
							</NcButton>
						</template>
					</div>
					<p class="hidden-visually" role="status">
						{{ deviceStatus }}
					</p>
				</section>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.folder-app {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	box-sizing: border-box;
	max-width: 100%;
	padding: calc(3 * var(--default-grid-baseline));
}

.folder-app p {
	margin: 0;
}

.folder-app__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.folder-app__intro {
	max-width: 80ch;
}

.folder-app__link {
	color: var(--color-main-text);
	text-decoration: underline;
}

.folder-app__layout {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

/* Wide screens: the programmes beside the notes */
@media (min-width: 1024px) {
	.folder-app__layout {
		display: grid;
		grid-template-columns: minmax(16rem, 24rem) minmax(0, 1fr);
		align-items: start;
	}
}

.folder-app__list {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.folder-app__concert {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-bottom: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.folder-app__piece {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.folder-app__concert-title {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.folder-app__concert-title:focus-visible,
.folder-app__open:focus-visible,
.folder-app__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.folder-app__muted {
	color: var(--color-text-maxcontrast);
}

.folder-app__note-text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.folder-app__items {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding-inline-start: 1.5em;
	list-style: decimal;
}

.folder-app__item {
	padding: var(--default-grid-baseline) 0;
}

/* the chosen piece: a bar at the start, not only a color (WCAG 1.4.1) */
.folder-app__item--selected {
	padding-inline-start: var(--default-grid-baseline);
	border-inline-start: 4px solid var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.folder-app__open {
	min-height: var(--default-clickable-area);
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	font-weight: bold;
	text-align: start;
	text-decoration: underline;
	cursor: pointer;
	overflow-wrap: anywhere;
}

.folder-app__turn {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.folder-app__device {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.folder-app__device-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.folder-app__device-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.folder-app {
		--default-clickable-area: 44px;
	}
}
</style>
