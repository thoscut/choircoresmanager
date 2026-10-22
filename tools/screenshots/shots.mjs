/**
 * The screenshots of the documentation: file, account, language, theme and
 * the steps that lead to the picture. A step function returns a clip
 * rectangle for a detail picture, or nothing for the whole window
 * (1600 x 1000). Texts of the app are given as English source strings;
 * h.t() turns them into the session's language.
 */

const CONCERT = 'Adventskonzert 2026'

/** Open the sidebar of a copy on one of its tabs. */
async function sidebar(h, shelfmark, tab = 'overview') {
	await h.app(`?view=catalog&copy=${await h.copyId(shelfmark)}`)
	await h.page.waitForSelector('.app-sidebar', { visible: true })
	if (tab !== 'overview') {
		await h.page.click(`#tab-button-${tab}`)
	}
	await h.settle(1200)
}

/** Open the score viewer of a copy and choose the focus voice. */
async function score(h, shelfmark, voice = null, mode = null, measures = null) {
	await sidebar(h, shelfmark)
	await h.click('.app-sidebar button', h.t('Show score of {shelfmark}').replace('{shelfmark}', shelfmark))
	await h.page.waitForSelector('.score-viewer__page svg', { timeout: 60000 })
	await h.settle(1000)
	if (measures) {
		await h.fill(h.t('From measure'), measures[0], '.score-viewer')
		await h.fill(h.t('To measure'), measures[1], '.score-viewer')
		await h.click('.score-viewer button', h.t('Show'))
		await h.page.waitForSelector('.score-viewer__page svg', { timeout: 60000 })
		await h.settle(800)
	}
	if (voice) {
		await h.select(h.t('Focus voice'), voice)
	}
	if (mode) {
		// "all", "focus" (focus voice louder) or "solo"
		await h.page.evaluate((mode) => document.querySelector(`.score-viewer input[type=radio][value="${mode}"]`)?.click(), mode)
	}
	await h.settle(1200)
}

/** Focus voice, sharing and the first system of the score. */
const scoreClip = async (h) => {
	// The actions for the passage (text reference, link, task) open on request
	const closed = await h.page.evaluate(() => !!document.querySelector('.score-viewer__place') && getComputedStyle(document.querySelector('.score-viewer__place')).display === 'none')
	if (closed) {
		await h.click('.score-viewer button', h.t('This passage'))
		await h.settle(400)
	}
	const clip = await h.rect(['.score-viewer__voice', '.score-viewer__place', '.score-viewer__summary', '.score-viewer__page svg'], { pad: 12, maxHeight: 470 })
	return { ...clip, width: Math.min(clip.width, 960) }
}

/** The visible part of the sidebar. */
const sidebarClip = (h, maxHeight = 1000) => h.rect('.app-sidebar', { pad: 0, maxHeight, fit: true })

/** The open modal dialog. */
const dialogClip = async (h) => {
	// The dialog on top: the last one that is visible
	await h.page.evaluate(() => {
		const dialogs = [...document.querySelectorAll('.modal-container')].filter((d) => d.getClientRects().length > 0)
		dialogs.forEach((d) => d.removeAttribute('data-screenshot'))
		dialogs.pop()?.setAttribute('data-screenshot', 'dialog')
	})
	return h.rect('[data-screenshot=dialog]', { pad: 0 })
}

/** Close a dialog without saving. */
const escape = async (h) => {
	await h.page.keyboard.press('Escape')
	await h.sleep(500)
}

/** Open the archive management dialog. */
async function archiveManagement(h) {
	await h.app('?view=catalog')
	await h.click('.app-navigation a, .app-navigation button', h.t('Archive management'))
	await h.page.waitForSelector('.modal-container', { visible: true })
	await h.settle(800)
}

const en = { lang: 'en', theme: 'light' }
const de = { lang: 'de', theme: 'light' }

export const SHOTS = [
	// ---- full window, also the app store screenshots ----
	{
		name: 'catalog', file: 'catalog.png', account: 'librarian', ...en,
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'catalog-dark', file: 'catalog-dark.png', account: 'librarian', lang: 'en', theme: 'dark',
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'copy-rights', file: 'copy-rights.png', account: 'librarian', ...en,
		run: async (h) => sidebar(h, '2-1', 'rights'),
	},
	{
		name: 'score-voice-focus', file: 'score-voice-focus.png', account: 'librarian', ...en,
		run: async (h) => score(h, '2-1', 'Tenor II', 'focus', ['1', '8']),
	},
	{
		name: 'practice-audio', file: 'practice-audio.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
		},
		after: escape,
	},
	{
		name: 'start-page-due', file: 'start-page-due.png', account: 'librarian', ...en,
		run: async (h) => h.app(''),
	},
	{
		name: 'concert-planning', file: 'concert-planning.png', account: 'librarian', ...en,
		run: async (h) => h.app(`?event=${await h.eventId(CONCERT)}`),
	},
	{
		name: 'statistics', file: 'statistics.png', account: 'librarian', ...en,
		run: async (h) => h.app('?view=statistics'),
	},
	{
		name: 'dashboard-widget', file: 'dashboard-widget.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.goto('/apps/dashboard/')
			await h.page.waitForFunction(() => document.body.innerText.includes('Music archive'), { timeout: 30000 })
			await h.settle(1500)
		},
	},
	{
		name: 'score-dark', file: 'score-dark.png', account: 'librarian', lang: 'en', theme: 'dark',
		run: async (h) => score(h, '2-1', 'Tenor II', 'focus', ['1', '8']),
	},

	// ---- details ----
	{
		name: 'copy-overview', file: 'copy-overview.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '1-1')
			return sidebarClip(h, 600)
		},
	},
	{
		name: 'rights-panel', file: 'rights-panel.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '2-1', 'rights')
			return sidebarClip(h, 800)
		},
	},
	{
		name: 'score-excerpt', file: 'score-excerpt.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			return scoreClip(h)
		},
	},
	{
		name: 'practice-audio-dialog', file: 'practice-audio-dialog.png', account: 'librarian', ...en,
		run: async (h) => {
			await score(h, '2-1', 'Tenor II', 'focus', ['1', '8'])
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'task-question', file: 'task-question.png', account: 'librarian', ...en,
		run: async (h) => {
			await sidebar(h, '2-1', 'tasks')
			return sidebarClip(h, 620)
		},
	},
	{
		name: 'concert-warnings', file: 'concert-warnings.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			return h.rect(['.event-detail__summary', '.event-detail__items'], { pad: 12 })
		},
	},
	{
		name: 'accession-form', file: 'accession-form.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=accession')
			await h.select(h.t('Binder (required)'), '4')
			return h.rect('section[aria-labelledby=accession-form]', { pad: 12 })
		},
	},
	{
		name: 'notifications', file: 'notifications.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=catalog')
			await h.click('header button.header-menu__trigger', ['Notifications', 'Benachrichtigungen'])
			await h.page.waitForSelector('.notification-dismiss-button', { visible: true, timeout: 15000 })
			await h.settle(800)
			// Mark the open menu of the notifications app to crop it
			await h.page.evaluate(() => {
				const button = [...document.querySelectorAll('.notification-dismiss-button')].find((b) => b.getClientRects().length > 0)
				button?.closest('.header-menu__wrapper, .header-menu__content, [role=dialog]')?.setAttribute('data-screenshot', 'notifications')
			})
			return h.rect('[data-screenshot=notifications]', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'backup-restore', file: 'backup-restore.png', account: 'librarian', ...en,
		run: async (h) => {
			await archiveManagement(h)
			await h.page.evaluate(() => document.querySelector('#archive-backup')?.scrollIntoView({ block: 'start' }))
			await h.settle(500)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'import-report', file: 'import-report.png', account: 'librarian', ...en,
		run: async (h, state) => {
			await archiveManagement(h)
			await h.click('.modal-container button', h.t('Choose CSV file …'))
			await pickFile(h, [state.demoFolder, '_Import', 'catalog.csv'])
			await h.page.waitForSelector('.import-report', { visible: true, timeout: 30000 })
			await h.settle(800)
			await h.page.evaluate(() => document.querySelector('#archive-import')?.scrollIntoView({ block: 'start' }))
			await h.settle(400)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		name: 'file-sync-report', file: 'file-sync-report.png', account: 'librarian', ...en,
		run: async (h) => {
			await archiveManagement(h)
			await h.click('.modal-container button', h.t('Run file sync'))
			await h.page.waitForSelector('.file-sync-report', { visible: true, timeout: 60000 })
			await h.page.evaluate(() => document.querySelector('#archive-file-sync')?.scrollIntoView({ block: 'start' }))
			await h.settle(600)
			return h.rect('.modal-container', { pad: 0 })
		},
		after: escape,
	},
	{
		// Administration → Music archive: archive folder, then roles and groups
		name: 'settings-roles', file: 'settings-roles.png', account: 'admin', ...en,
		run: async (h) => {
			await h.goto('/settings/admin/notenarchiv')
			await h.page.waitForSelector('#notenarchiv-roles', { visible: true, timeout: 30000 })
			await h.settle(1200)
			return h.rect(['#notenarchiv-archive-folder', '#notenarchiv-roles'], { pad: 0 })
		},
	},
	{
		// The access check of the archive folder, opened like the link of the app does
		name: 'admin-settings', file: 'admin-settings.png', account: 'admin', ...en,
		run: async (h) => {
			await h.goto('/settings/admin/notenarchiv#notenarchiv-access')
			await h.page.waitForSelector('#notenarchiv-access .access-check__table', { visible: true, timeout: 60000 })
			await h.settle(1200)
			return h.rect('#notenarchiv-access', { pad: 0 })
		},
	},
	{
		name: 'protection-terms', file: 'protection-terms.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=protection-review')
			return h.rect('#app-content-vue', { pad: 0, fit: true })
		},
	},
	{
		name: 'merge-proposals', file: 'merge-proposals.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=merges')
			return h.rect('#app-content-vue', { pad: 0, maxHeight: 885 })
		},
	},
	{
		name: 'annual-report', file: 'annual-report.png', account: 'librarian', ...en,
		run: async (h) => {
			await h.app('?view=statistics')
			await h.page.evaluate(() => document.querySelector('.annual-report')?.scrollIntoView({ block: 'start' }))
			await h.settle(600)
			return h.rect('.annual-report', { pad: 12 })
		},
	},
	{
		name: 'calendar-dialog', file: 'calendar-dialog.png', account: 'conductor', ...en,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			await h.page.click('#event-detail-add-to-calendar')
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(1200)
			return dialogClip(h)
		},
		after: escape,
	},

	// ---- German interface (informal) ----
	{
		name: 'de-catalog', file: 'de/catalog.png', account: 'librarian', ...de,
		run: async (h) => h.app('?view=catalog'),
	},
	{
		name: 'de-start-page', file: 'de/start-page.png', account: 'librarian', ...de,
		run: async (h) => {
			await h.app('')
			return h.rect(['.due-view .view-header', '.due-view__cards', '.due-view__concerts'], { pad: 8 })
		},
	},
	{
		name: 'de-rights-confirm', file: 'de/rights-confirm.png', account: 'librarian', ...de,
		run: async (h) => {
			await sidebar(h, '1-3', 'rights')
			await h.click('.rights-panel button', h.t('Confirm'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Reason (required)'), 'Alle Beteiligten sind vor mehr als 70 Jahren gestorben.', '.modal-container')
			await h.fill(h.t('Evidence (required), e.g. source of the life dates or license'), 'Lebensdaten im Personenregister; Titelseite des Drucks', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-score-voice', file: 'de/score-voice.png', account: 'conductor', ...de,
		run: async (h) => {
			await score(h, '1-1', 'Alt', 'focus')
			return scoreClip(h)
		},
	},
	{
		name: 'de-practice-audio', file: 'de/practice-audio.png', account: 'conductor', ...de,
		run: async (h) => {
			await score(h, '1-1', 'Alt', 'focus')
			await h.click('.score-viewer button', h.t('Practice files …'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.settle(800)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-valid-version', file: 'de/valid-version.png', account: 'conductor', ...de,
		run: async (h) => {
			await sidebar(h, '2-2', 'rights')
			await h.click('.rights-panel button', h.t('Set as valid for the choir'))
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Reason (optional)'), 'Diesen Satz singen wir im Adventskonzert.', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-question-answer', file: 'de/question-answer.png', account: 'conductor', ...de,
		run: async (h) => {
			await h.app('?view=tasks')
			await h.page.evaluate(() => {
				const item = [...document.querySelectorAll('li, article')].find((el) => el.innerText.includes('Alt, Takt 3'))
				item?.scrollIntoView({ block: 'center' })
				;[...(item?.querySelectorAll('button') ?? [])].find((b) => b.innerText.trim() === 'Antworten' || b.innerText.trim() === 'Antwort')?.click()
			})
			await h.page.waitForSelector('.modal-container', { visible: true })
			await h.fill(h.t('Answer (required)'), 'Die zwei Achtel aus der Abschrift gelten, so singen wir es.', '.modal-container')
			await h.settle(400)
			return dialogClip(h)
		},
		after: escape,
	},
	{
		name: 'de-concert', file: 'de/concert.png', account: 'conductor', ...de,
		run: async (h) => {
			await h.app(`?event=${await h.eventId(CONCERT)}`)
			return h.rect(['.event-detail__summary', '.event-detail__items'], { pad: 12 })
		},
	},
]

/**
 * Choose a file in Nextcloud's file picker by clicking through the folders.
 *
 * @param {object} h helpers
 * @param {string[]} path folder names and the file name
 */
async function pickFile(h, path) {
	await h.page.waitForSelector('.file-picker', { visible: true, timeout: 20000 })
	for (const name of path) {
		const row = `.file-picker tr[data-filename="${name}"]`
		await h.page.waitForSelector(row, { visible: true, timeout: 20000 })
		await h.page.click(`${row} .row-name`)
		await h.sleep(1200)
	}
	await h.click('.file-picker button', ['Choose', 'Auswählen'])
}
