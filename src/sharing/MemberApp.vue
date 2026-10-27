<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page "Shared with me" for choir members (role "member", module
 * "member_links", milestone 0.7). Choir members see nothing of the catalog:
 * only the links that the conductor or the music librarians made for them,
 * and above the list the score view of the chosen link: the whole piece or
 * the measures the server cuts, and the scan (PDF) when the link offers it.
 * The server sends the list with the page and checks the role, the link and
 * the rights again on every call.
 */
import type { MemberLink, MemberPageState, MemberPracticeAudioInput } from '../types.ts'

import { mdiAlertCircleOutline, mdiMusicNoteOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref, useId } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Attribution from './Attribution.vue'
import ExcerptViewer from './ExcerptViewer.vue'
import { focusAfterUpdate } from '../focus.ts'
import { formatDate } from '../format.ts'
import { authorizeMemberPracticeAudio, fetchMemberScore, memberScanUrl } from './api.ts'
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

const links = initialLinks()
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
 * Show an excerpt above the list; the address names it, so that it can be
 * bookmarked.
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

onMounted(() => {
	const token = new URLSearchParams(window.location.search).get('link')
	const linked = token === null ? null : links.find((link) => link.token === token)
	if (linked) {
		select(linked, false)
	} else if (token !== null) {
		notice.value = t('notenarchiv', 'This link is not available to you, or no longer: it may have expired or been revoked. Please ask the conductor or the music librarians for a new link. Everything else shared with you is listed below.')
	} else if (links.length === 1) {
		select(links[0], false)
	}
})
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

				<NcEmptyContent v-if="links.length === 0"
					:name="t('notenarchiv', 'Nothing has been shared with you yet')"
					:description="t('notenarchiv', 'When the conductor or the music librarians share a piece or an excerpt with the choir members, it appears here. Each link is valid for at most six months.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMusicNoteOutline" />
					</template>
				</NcEmptyContent>

				<template v-else>
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
						<p v-if="selected.scan">
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
							:export-audio="selected.canExportAudio ? exportAudio : null" />
					</section>
					<p v-else class="member-app__muted">
						{{ t('notenarchiv', 'Choose a piece from the list to see and hear it.') }}
					</p>

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
							</li>
						</ul>
					</section>
				</template>
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

.member-app__excerpt {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-bottom: calc(3 * var(--default-grid-baseline));
	border-bottom: 1px solid var(--color-border);
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

/* Touch: targets of at least 44 pixels */
@media (pointer: coarse) {
	.member-app {
		--default-clickable-area: 44px;
	}
}
</style>
