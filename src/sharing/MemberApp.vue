<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page "Shared with me" for choir members (role "member", module
 * "member_links", milestone 0.7). Choir members see nothing of the catalog:
 * only the links that the conductor or the music librarians made for them,
 * and the score view of the chosen link: the whole piece or the measures
 * the server cuts, and the scan (PDF) when the link offers it. The list comes
 * first (milestone 0.12): on a phone it is above the notes, on a wide screen
 * beside them.
 * The server sends the list with the page and checks the role, the link and
 * the rights again on every call.
 * With the module "offline" (milestone 0.15) a piece can be kept on this
 * device, where its rights allow saving, and then be viewed, played and
 * practised without a network (src/offline/). At every start with a network
 * the kept pieces are checked with the server.
 */
import type { Catalog } from '../offline/rules.ts'
import type { MemberLink, MemberPageState, MemberPracticeAudioInput } from '../types.ts'

import { mdiAlertCircleOutline, mdiMusicNoteOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { isAxiosError } from '@nextcloud/axios'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Attribution from './Attribution.vue'
import ExcerptViewer from './ExcerptViewer.vue'
import { focusAfterUpdate } from '../focus.ts'
import { formatDate, formatDateTime, formatSize } from '../format.ts'
import { askToPersist, checkWithServer, forgetPiece, keepPiece, offlineSupported, readCatalog, removeEverything, setUpWorker, storageInfo } from '../offline/device.ts'
import { MAX_DAYS_UNCHECKED, usable } from '../offline/rules.ts'
import { forgetPractising } from '../score/practiceSettings.ts'
import { authorizeMemberPracticeAudio, fetchMemberLinks, fetchMemberScore, memberScanUrl } from './api.ts'
import { scopeText } from './linkScope.ts'

/**
 * The links from the page; an empty list if the state is missing.
 */
function initialLinks(): MemberLink[] {
	try {
		const state = loadState<MemberPageState>('notenarchiv', 'member', { links: [] })
		return Array.isArray(state.links) ? state.links : []
	} catch {
		return []
	}
}

/**
 * Practising without a network from the page (module "offline").
 */
function initialOffline(): { enabled: boolean, version: string } {
	try {
		const state = loadState<MemberPageState>('notenarchiv', 'member', { links: [] })
		return { enabled: state.offline?.enabled === true, version: String(state.offline?.version ?? '') }
	} catch {
		return { enabled: false, version: '' }
	}
}

const links = initialLinks()
const offlineConfig = initialOffline()
/** Pieces can be kept on this device: module on, and the browser can */
const offlineOn = offlineConfig.enabled && offlineSupported()
const deviceHeadingId = useId()
const pageHeadingId = useId()
const listHeadingId = useId()
const excerptHeadingId = useId()
const excerptHeading = ref<HTMLElement | null>(null)

const selectedToken = ref<string | null>(null)
const selected = computed(() => links.find((link) => link.token === selectedToken.value) ?? null)
const scoreStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
const scoreXml = ref('')
const scoreError = ref('')
/** A link from the address that is not (or no longer) in the list. */
const notice = ref('')

// ---- kept on this device (milestone 0.15) ----------------------------------

/** The kept pieces of this account on this device */
const catalog = ref<Catalog | null>(null)
/** The server answered the last check; false: shown from the device */
const reachable = ref(true)
/** Token of the piece being kept or removed */
const keeping = ref<string | null>(null)
const keepError = ref('')
const storage = ref<{ usage: number | null, quota: number | null, persisted: boolean } | null>(null)
const confirmingDelete = ref(false)
const deviceStatus = ref('')
const withoutNetwork = computed(() => offlineOn && !reachable.value)
const keptCount = computed(() => Object.keys(catalog.value?.pieces ?? {}).length)

/**
 * Whether a link is kept on this device.
 *
 * @param link - member link
 */
function isKept(link: MemberLink): boolean {
	return catalog.value?.pieces[link.token] !== undefined
}

/**
 * Whether the scan of a kept link is kept, too.
 *
 * @param link - member link
 */
function scanKept(link: MemberLink): boolean {
	return catalog.value?.pieces[link.token]?.scan === true
}

/**
 * Why a piece cannot be shown without a network, or ''.
 *
 * @param link - member link
 */
function lockText(link: MemberLink): string {
	switch (usable(catalog.value, link.token, new Date())) {
	case 'ok':
		return ''
	case 'expired':
		return t('notenarchiv', 'This link has expired. It is removed from this device at the next start with a network.')
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

/**
 * Check the kept pieces with the server; without an answer the page shows
 * what is kept on the device.
 */
async function checkDevice(): Promise<void> {
	if (!offlineOn) {
		return
	}
	catalog.value = await readCatalog()
	try {
		const fresh = await fetchMemberLinks()
		reachable.value = true
		catalog.value = await checkWithServer(fresh)
	} catch (error) {
		// an answer of the server (e.g. not logged in) is no missing network
		reachable.value = isAxiosError(error) && error.response !== undefined
	}
	await refreshStorage()
}

/**
 * Keep a piece on this device or remove it.
 *
 * @param link - member link
 * @param keep - keep it
 * @param withScan - with the scan (PDF)
 */
async function setKept(link: MemberLink, keep: boolean, withScan: boolean): Promise<void> {
	keeping.value = link.token
	keepError.value = ''
	try {
		if (keep) {
			catalog.value = await keepPiece(link, withScan, offlineConfig.version)
			await askToPersist()
			deviceStatus.value = t('notenarchiv', '"{title}" is kept on this device.', { title: link.title })
		} else {
			catalog.value = await forgetPiece(link.token)
			deviceStatus.value = t('notenarchiv', '"{title}" is removed from this device.', { title: link.title })
		}
	} catch (error) {
		keepError.value = t('notenarchiv', '"{title}" could not be kept on this device: {reason}', {
			title: link.title,
			reason: error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'Please check the network and the space on the device.'),
		})
		catalog.value = await readCatalog()
	} finally {
		keeping.value = null
		await refreshStorage()
	}
}

/** Delete the kept pieces and everything remembered for practising, on this device only. */
async function deleteDevice(): Promise<void> {
	await removeEverything()
	forgetPractising()
	catalog.value = null
	confirmingDelete.value = false
	deviceStatus.value = t('notenarchiv', 'Everything the music archive kept on this device is deleted.')
	await refreshStorage()
}

/** Space text: used of available */
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

function onOnline(): void {
	checkDevice()
}

/** Words of a text reference (the same texts as in the archive). */
const WORDS = {
	page: (page: string) => t('notenarchiv', 'p. {page}', { page }),
	measure: (measure: string) => t('notenarchiv', 'm. {measure}', { measure }),
	measures: (from: string, to: string) => t('notenarchiv', 'm. {from}–{to}', { from, to }),
	verse: (verse: string) => t('notenarchiv', 'verse {verse}', { verse }),
}

/**
 * Whole piece or measures, voice and verse of a link.
 *
 * @param link - member link
 */
function place(link: MemberLink): string {
	return scopeText({ measureFrom: link.measure_from, measureTo: link.measure_to, voice: link.voice, verse: link.verse }, WORDS,
		t('notenarchiv', 'Whole piece'))
}

async function loadSelectedScore(): Promise<void> {
	const link = selected.value
	if (!link) {
		return
	}
	if (withoutNetwork.value && lockText(link) !== '') {
		scoreStatus.value = 'error'
		scoreError.value = lockText(link)
		return
	}
	scoreStatus.value = 'loading'
	scoreError.value = ''
	try {
		const xml = await fetchMemberScore(link.token)
		if (selectedToken.value === link.token) {
			scoreXml.value = xml
			scoreStatus.value = 'ready'
		}
	} catch (error) {
		if (selectedToken.value === link.token) {
			scoreStatus.value = 'error'
			scoreError.value = error instanceof Error && error.message !== '' ? error.message : t('notenarchiv', 'The score could not be loaded.')
		}
	}
}

/**
 * Show an excerpt next to or below the list; the address names it, so
 * that it can be bookmarked.
 *
 * @param link - member link
 * @param moveFocus - put the focus on its heading (after a click in the list)
 */
async function select(link: MemberLink, moveFocus = true): Promise<void> {
	notice.value = ''
	selectedToken.value = link.token
	scoreXml.value = ''
	const url = window.location.pathname + '?link=' + encodeURIComponent(link.token) + window.location.hash
	window.history.replaceState(null, '', url)
	if (moveFocus) {
		focusAfterUpdate(excerptHeading)
	}
	await loadSelectedScore()
}

/**
 * Ask the server before a practice file of the shown excerpt is made.
 *
 * @param input - voice, mode, format, tempo, repeats
 */
function exportAudio(input: MemberPracticeAudioInput): Promise<void> {
	const link = selected.value
	if (!link) {
		return Promise.reject(new Error(t('notenarchiv', 'Please choose a piece from the list first.')))
	}
	return authorizeMemberPracticeAudio(link.token, input)
}

onMounted(async () => {
	if (offlineSupported()) {
		// registers the worker, or removes it and what it kept when the module is off
		await setUpWorker(offlineConfig.enabled)
		await checkDevice()
		window.addEventListener('online', onOnline)
	}
	const token = new URLSearchParams(window.location.search).get('link')
	const linked = token === null ? null : links.find((link) => link.token === token)
	if (linked) {
		// from a bookmark or a message: straight to the notes, also on a phone
		select(linked, false)
		nextTick(() => excerptHeading.value?.scrollIntoView?.({ block: 'start' }))
	} else if (token !== null) {
		notice.value = t('notenarchiv', 'This link is not available to you, or no longer: it may have expired or been revoked. Please ask the conductor or the music librarians for a new link. Everything else shared with you is listed below.')
	} else if (links.length === 1) {
		select(links[0], false)
	}
})

onBeforeUnmount(() => window.removeEventListener('online', onOnline))
</script>

<template>
	<NcContent app-name="notenarchiv">
		<NcAppContent :page-heading="t('notenarchiv', 'Shared with me')"
			:page-title="t('notenarchiv', 'Shared with me') + ' - ' + t('notenarchiv', 'Music archive')">
			<div class="member-app">
				<h2 :id="pageHeadingId" class="member-app__title">
					{{ t('notenarchiv', 'Shared with me') }}
				</h2>
				<p class="member-app__intro">
					{{ t('notenarchiv', 'Pieces and excerpts that the conductor or the music librarians share with the choir members: view, listen and practise your voice. Each link is valid for a limited time.') }}
				</p>

				<NcNoteCard v-if="notice" type="warning" role="status">
					{{ notice }}
				</NcNoteCard>
				<NcNoteCard v-if="withoutNetwork" type="info" role="status">
					{{ keptCount > 0
						? t('notenarchiv', 'No network: the pieces kept on this device can be viewed, played and practised. {checked}', { checked: checkedText })
						: t('notenarchiv', 'No network, and no piece is kept on this device.') }}
				</NcNoteCard>

				<NcEmptyContent v-if="links.length === 0"
					:name="t('notenarchiv', 'Nothing has been shared with you yet')"
					:description="t('notenarchiv', 'When the conductor or the music librarians share a piece or an excerpt with the choir members, it appears here. Each link is valid for at most six months.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
					</template>
				</NcEmptyContent>

				<template v-else>
					<div class="member-app__layout">
						<section class="member-app__list" :aria-labelledby="listHeadingId">
							<h3 :id="listHeadingId" class="member-app__list-title">
								{{ n('notenarchiv', '%n shared link', '%n shared links', links.length) }}
							</h3>
							<ul class="member-app__items">
								<li v-for="link in links"
									:key="link.token"
									class="member-app__item"
									:class="{ 'member-app__item--selected': link.token === selectedToken }">
									<button type="button"
										class="member-app__open"
										:aria-current="link.token === selectedToken ? 'true' : undefined"
										@click="select(link)">
										{{ link.title }}
									</button>
									<p v-if="link.composer" class="member-app__muted">
										{{ link.composer }}
									</p>
									<p>{{ place(link) }}</p>
									<p v-if="link.note" class="member-app__note-text">
										{{ t('notenarchiv', 'Note: {note}', { note: link.note }) }}
									</p>
									<p class="member-app__muted">
										<template v-if="link.expires">
											{{ t('notenarchiv', 'Valid until {date}', { date: formatDate(link.expires) }) }} ·
										</template>
										{{ link.canPlay ? t('notenarchiv', 'Playback: yes') : t('notenarchiv', 'Playback: no') }}
										<template v-if="link.canExportAudio">
											· {{ t('notenarchiv', 'Practice files: yes') }}
										</template>
										<template v-if="link.scan">
											· {{ t('notenarchiv', 'with scan (PDF)') }}
										</template>
									</p>
									<div v-if="offlineOn" class="member-app__device">
										<template v-if="link.offline">
											<NcCheckboxRadioSwitch type="switch"
												:model-value="isKept(link)"
												:disabled="withoutNetwork || keeping !== null"
												@update:model-value="(on: boolean) => setKept(link, on, false)">
												{{ t('notenarchiv', 'Keep on this device') }}
											</NcCheckboxRadioSwitch>
											<NcCheckboxRadioSwitch v-if="isKept(link) && link.scan"
												type="switch"
												:model-value="scanKept(link)"
												:disabled="withoutNetwork || keeping !== null"
												@update:model-value="(on: boolean) => setKept(link, true, on)">
												{{ t('notenarchiv', 'With the scan (PDF)') }}
											</NcCheckboxRadioSwitch>
											<p v-if="keeping === link.token" class="member-app__muted">
												{{ t('notenarchiv', 'Keeping on this device …') }}
											</p>
											<p v-else-if="isKept(link) && lockText(link) !== ''" class="member-app__muted">
												{{ lockText(link) }}
											</p>
										</template>
										<p v-else class="member-app__muted">
											{{ t('notenarchiv', 'Cannot be kept on this device: the rights do not allow saving the notes.') }}
										</p>
										<p v-if="withoutNetwork && !isKept(link)" class="member-app__muted">
											{{ t('notenarchiv', 'Not on this device: needs a network.') }}
										</p>
									</div>
								</li>
							</ul>
						</section>
						<section v-if="selected" class="member-app__excerpt" :aria-labelledby="excerptHeadingId">
							<h3 :id="excerptHeadingId" ref="excerptHeading" class="member-app__excerpt-title">
								{{ selected.title }}
							</h3>
							<p v-if="selected.composer" class="member-app__muted">
								{{ selected.composer }}
							</p>
							<Attribution :credits="selected.credits ?? []" :notices="selected.notices ?? []" />
							<NcNoteCard v-if="selected.note" type="info" class="member-app__note">
								<p class="member-app__note-text">
									{{ t('notenarchiv', 'Note: {note}', { note: selected.note }) }}
								</p>
							</NcNoteCard>
							<p v-if="selected.expires" class="member-app__muted">
								{{ t('notenarchiv', 'Valid until {date}', { date: formatDate(selected.expires) }) }}
							</p>
							<p v-if="selected.scan && withoutNetwork && !scanKept(selected)" class="member-app__muted">
								{{ t('notenarchiv', 'The scan is not kept on this device.') }}
							</p>
							<p v-else-if="selected.scan">
								<!-- The browser's own PDF viewer, in a tab of its own -->
								<a :href="memberScanUrl(selected.token)"
									class="member-app__scan"
									target="_blank"
									rel="noopener noreferrer">{{ t('notenarchiv', 'Open the scan (PDF)') }}<span class="hidden-visually"> {{ t('notenarchiv', '(opens in a new tab)') }}</span></a>
							</p>

							<NcEmptyContent v-if="scoreStatus === 'loading'" :name="t('notenarchiv', 'Loading the score …')">
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
								:key="selected.token"
								:xml="scoreXml"
								:title="selected.title"
								:composer="selected.composer"
								:measure-from="selected.measure_from"
								:measure-to="selected.measure_to"
								:voice="selected.voice"
								:verse="selected.verse"
								:tempo="selected.tempo"
								:can-play="selected.canPlay"
								:play-reason="selected.reason"
								:export-audio="selected.canExportAudio ? exportAudio : null"
								:practice-key="selected.practiceKey ?? null"
								:offline="withoutNetwork" />
						</section>
						<p v-else class="member-app__muted">
							{{ t('notenarchiv', 'Choose a piece from the list to see and hear it.') }}
						</p>
					</div>
				</template>

				<section class="member-app__device-section" :aria-labelledby="deviceHeadingId">
					<h3 :id="deviceHeadingId" class="member-app__list-title">
						{{ t('notenarchiv', 'On this device') }}
					</h3>
					<template v-if="offlineOn">
						<p>
							{{ n('notenarchiv', '%n piece is kept on this device for practising without a network.', '%n pieces are kept on this device for practising without a network.', keptCount) }}
							{{ storageText }}
						</p>
						<p v-if="keptCount > 0" class="member-app__muted">
							{{ checkedText }}
							{{ storage?.persisted ? t('notenarchiv', 'The browser keeps them even when space runs short.') : t('notenarchiv', 'The browser may delete them when space runs short; on an iPhone or iPad, add this page to the home screen.') }}
						</p>
					</template>
					<p v-else-if="offlineConfig.enabled" class="member-app__muted">
						{{ t('notenarchiv', 'This browser cannot keep pieces for practising without a network.') }}
					</p>
					<p class="member-app__muted">
						{{ t('notenarchiv', 'Passages and settings for practising are remembered in this browser only. On a shared device, delete them when you are done.') }}
					</p>
					<NcNoteCard v-if="keepError" type="error" role="alert">
						{{ keepError }}
					</NcNoteCard>
					<div class="member-app__device-buttons">
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
.member-app {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	box-sizing: border-box;
	max-width: 100%;
	padding: calc(3 * var(--default-grid-baseline));
}

.member-app p {
	margin: 0;
}

.member-app__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
}

.member-app__intro {
	max-width: 80ch;
}

.member-app__layout {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
}

.member-app__excerpt {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.member-app__list {
	padding-bottom: calc(3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

/* Wide screens: the list beside the notes */
@media (min-width: 1024px) {
	.member-app__layout {
		display: grid;
		grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
		align-items: start;
	}

	.member-app__list {
		padding-bottom: 0;
		border-bottom: none;
	}
}

.member-app__excerpt-title,
.member-app__list-title {
	margin: 0;
	font-size: 1.2em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.member-app__excerpt-title:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.member-app__muted {
	color: var(--color-text-maxcontrast);
}

.member-app__note-text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.member-app__items {
	display: flex;
	flex-direction: column;
}

.member-app__item {
	padding: calc(2 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
}

.member-app__item:last-child {
	border-bottom: none;
}

/* the chosen excerpt: a bar at the start, not only a color (WCAG 1.4.1) */
.member-app__item--selected {
	border-inline-start: 4px solid var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.member-app__open {
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

.member-app__scan {
	display: inline-block;
	min-height: var(--default-clickable-area);
	line-height: var(--default-clickable-area);
	color: var(--color-main-text);
	text-decoration: underline;
}

.member-app__scan:focus-visible,
.member-app__open:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.member-app__device {
	margin-top: var(--default-grid-baseline);
}

.member-app__device-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding-top: calc(3 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.member-app__device-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.member-app {
		--default-clickable-area: 44px;
	}
}
</style>
