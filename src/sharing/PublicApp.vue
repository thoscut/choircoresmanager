<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * The page of a public link (module "public_links", milestone 0.7): a
 * whole piece or an excerpt of a score, viewed and played without signing
 * in, inside Nextcloud's public layout. The server sends the score with the
 * page (the excerpt already cut to its measures): no download of the score
 * file, no practice file, no names of accounts, no notes. When the link
 * offers the scan, it opens inside the page.
 * With the module "offline" (milestone 0.15) a piece whose public domain is
 * confirmed can be kept on this device; then the page itself comes from the
 * device when there is no network. Each visit with a network is its check.
 */
import type { Catalog, PieceForDevice } from '../offline/rules.ts'
import type { PublicExcerpt } from '../types.ts'

import { mdiAlertCircleOutline } from '@mdi/js'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Attribution from './Attribution.vue'
import ExcerptViewer from './ExcerptViewer.vue'
import ScanFrame from './ScanFrame.vue'
import { formatDateTime } from '../format.ts'
import { askToPersist, checkPublicPiece, forgetPieces, keepPieces, offlineSupported, setUpWorker } from '../offline/device.ts'
import { MAX_DAYS_UNCHECKED, usable } from '../offline/rules.ts'

/**
 * The excerpt from the page, or null if it is missing.
 */
function initialExcerpt(): PublicExcerpt | null {
	try {
		const excerpt = loadState<PublicExcerpt | null>('cantoria', 'excerpt', null)
		return excerpt && typeof excerpt.xml === 'string' && excerpt.xml !== '' ? excerpt : null
	} catch {
		return null
	}
}

function initialVersion(): string {
	try {
		return String(loadState<string>('cantoria', 'version', ''))
	} catch {
		return ''
	}
}

const excerpt = initialExcerpt()
const version = initialVersion()
/** The token of this link, from the page's own address (/p/{token}) */
const token = /\/p\/([A-Za-z0-9]{32})\/?$/.exec(window.location.pathname)?.[1] ?? ''

// ---- kept on this device (milestone 0.15) ----------------------------------

/** This piece may be kept: module on, rights, and the browser can */
const offlineOn = excerpt?.offline === true && token !== '' && offlineSupported()
const catalog = ref<Catalog | null>(null)
/** The page came from the device: no network */
const fromDevice = ref(false)
const keeping = ref(false)
const keepError = ref('')
const deviceStatus = ref('')
const kept = computed(() => catalog.value?.pieces[token] !== undefined)
const scanKept = computed(() => catalog.value?.pieces[token]?.scan === true)

const piece: PieceForDevice = {
	key: token,
	title: excerpt?.title ?? '',
	composer: excerpt?.composer ?? '',
	expires: excerpt?.expires ?? null,
	offline: excerpt?.offline === true,
	scan: excerpt?.scan === true,
}

const checkedText = computed(() => {
	const kept = catalog.value?.pieces[token]
	const checked = kept ? Date.parse(kept.checked ?? catalog.value?.checked ?? '') : NaN
	return Number.isFinite(checked)
		? t('cantoria', 'Last checked with the server: {time}.', { time: formatDateTime(Math.floor(checked / 1000)) })
		: ''
})

/**
 * Keep this piece on the device or remove it.
 *
 * @param keep - keep it
 * @param withScan - with the scan (PDF)
 */
async function setKept(keep: boolean, withScan: boolean): Promise<void> {
	keeping.value = true
	keepError.value = ''
	try {
		if (keep) {
			catalog.value = await keepPieces('public', [piece], withScan, version)
			await askToPersist()
			deviceStatus.value = t('cantoria', '"{title}" is kept on this device.', { title: piece.title })
		} else {
			catalog.value = await forgetPieces('public', [token])
			deviceStatus.value = t('cantoria', '"{title}" is removed from this device.', { title: piece.title })
		}
	} catch (error) {
		keepError.value = t('cantoria', '"{title}" could not be kept on this device: {reason}', {
			title: piece.title,
			reason: error instanceof Error && error.message !== '' ? error.message : t('cantoria', 'Please check the network and the space on the device.'),
		})
	} finally {
		keeping.value = false
	}
}

/** Why the kept piece will not open again without a network, or '' */
const lockText = computed(() => {
	switch (usable(catalog.value, token, new Date())) {
	case 'expired':
		return t('cantoria', 'This link has expired. It is removed from this device at the next start with a network.')
	case 'unchecked':
		return n('cantoria', 'Locked: not checked with the server for more than %n day. Please open this page once with a network.',
			'Locked: not checked with the server for more than %n days. Please open this page once with a network.', MAX_DAYS_UNCHECKED)
	default:
		return ''
	}
})

onMounted(async () => {
	if (!offlineSupported() || token === '') {
		return
	}
	// registers the worker, or removes this piece when it may no longer be kept
	await setUpWorker('public', excerpt?.offline === true)
	if (offlineOn) {
		const checked = await checkPublicPiece(piece, excerpt?.served ?? '')
		catalog.value = checked.catalog
		fromDevice.value = checked.fromDevice
	} else {
		await forgetPieces('public', [token])
	}
})
</script>

<template>
	<!-- The public layout of Nextcloud has no main landmark of its own -->
	<main class="public-excerpt">
		<template v-if="excerpt">
			<h2 class="public-excerpt__title">
				{{ excerpt.title }}
			</h2>
			<p v-if="excerpt.composer" class="public-excerpt__composer">
				{{ excerpt.composer }}
			</p>
			<Attribution :credits="excerpt.credits ?? []" :notices="excerpt.notices ?? []" />
			<NcNoteCard type="info">
				{{ excerpt.measureFrom === null
					? t('cantoria', 'A piece shared by the choir. Playback comes from a simple synthesizer in your browser; it is no substitute for a recording.')
					: t('cantoria', 'An excerpt of a score, shared by the choir. Playback comes from a simple synthesizer in your browser; it is no substitute for a recording.') }}
			</NcNoteCard>
			<NcNoteCard v-if="fromDevice" type="info" role="status">
				{{ t('cantoria', 'No network: this piece is shown from this device. {checked}', { checked: checkedText }) }}
			</NcNoteCard>
			<ScanFrame v-if="excerpt.scan && (!fromDevice || scanKept)"
				collection="public"
				:piece-key="token"
				:title="excerpt.title" />
			<p v-else-if="excerpt.scan" class="public-excerpt__composer">
				{{ t('cantoria', 'The scan is not kept on this device.') }}
			</p>
			<ExcerptViewer :xml="excerpt.xml"
				:title="excerpt.title"
				:composer="excerpt.composer"
				:measure-from="excerpt.measureFrom"
				:measure-to="excerpt.measureTo"
				:voice="excerpt.voice"
				:verse="excerpt.verse"
				:tempo="excerpt.tempo"
				:can-play="excerpt.canPlay"
				:practice-key="excerpt.practiceKey ?? null"
				:offline="fromDevice" />
			<section v-if="offlineOn" class="public-excerpt__device" :aria-label="t('cantoria', 'On this device')">
				<NcCheckboxRadioSwitch type="switch"
					:model-value="kept"
					:disabled="fromDevice || keeping"
					@update:model-value="(on: boolean) => setKept(on, false)">
					{{ t('cantoria', 'Keep on this device') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-if="kept && excerpt.scan"
					type="switch"
					:model-value="scanKept"
					:disabled="fromDevice || keeping"
					@update:model-value="(on: boolean) => setKept(true, on)">
					{{ t('cantoria', 'With the scan (PDF)') }}
				</NcCheckboxRadioSwitch>
				<p class="public-excerpt__composer">
					{{ kept
						? t('cantoria', 'Kept in this browser: this page opens without a network, while the link is valid. Open it once with a network at least every {days} days.', { days: MAX_DAYS_UNCHECKED })
						: t('cantoria', 'Keep this piece in this browser to view, play and practise it without a network.') }}
				</p>
				<p v-if="kept && lockText" class="public-excerpt__composer">
					{{ lockText }}
				</p>
				<p v-if="keeping" class="public-excerpt__composer">
					{{ t('cantoria', 'Keeping on this device …') }}
				</p>
				<NcNoteCard v-if="keepError" type="error" role="alert">
					{{ keepError }}
				</NcNoteCard>
				<p class="hidden-visually" role="status">
					{{ deviceStatus }}
				</p>
			</section>
		</template>
		<NcEmptyContent v-else
			:name="t('cantoria', 'The score cannot be shown')"
			:description="t('cantoria', 'Please reload the page. If it stays empty, the link may no longer be valid.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
		</NcEmptyContent>
	</main>
</template>

<style>
/* Nextcloud's public layout holds #content to the height of the window and
   clips it: the app scrolls in its own element, as in the other apps.
   Without this the page cannot be scrolled at all, on a phone not even to
   the score. */
#cantoria-public {
	flex: 1 1 auto;
	min-width: 0;
	overflow-y: auto;
}
</style>

<style scoped>
.public-excerpt {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	box-sizing: border-box;
	width: 100%;
	max-width: 1200px;
	margin: 0 auto;
	padding: calc(3 * var(--default-grid-baseline));
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.public-excerpt__title {
	margin: 0;
	font-size: 1.4em;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.public-excerpt__device {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	max-width: 80ch;
	padding-top: calc(2 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.public-excerpt__device p {
	margin: 0;
}

.public-excerpt__composer {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
