/**
 * Brings the demo catalog into the state shown in the screenshots, through
 * the app's own API and as the accounts that may do each step: the demo
 * folder shared read-only with the conductors, persons, performances and
 * song lists imported, rights confirmed, a version in the singers' portal, a
 * license, valid versions, tasks, a proposal, an internal link and a planned
 * concert. Every text is invented.
 *
 * Steps that are done already are skipped, so it can run again; the planned
 * concert "Adventskonzert 2026" marks the state as complete.
 */

const CONCERT = 'Adventskonzert 2026'

/**
 * @param {object} options
 * @param {(role: string) => Promise<object>} options.session logged-in session of a role
 * @param {string} options.demoFolder folder of the demo data in the librarian's files
 * @param {(...text: string[]) => void} options.log progress
 */
export async function prepare({ session, demoFolder, log }) {
	const librarian = await session('librarian')
	const conductor = await session('conductor')
	const contributor = await session('contributor')

	const check = (what, result, ok = [200, 201]) => {
		if (!ok.includes(result.status)) {
			throw new Error(`${what}: HTTP ${result.status} ${JSON.stringify(result.json)}`)
		}
		return result.json
	}

	// File ids of the import lists in the demo folder
	const listing = await librarian.request('PROPFIND', `/remote.php/dav/files/${encodeURIComponent(librarian.uid)}/${encodeURIComponent(demoFolder)}/_Import/`, {
		headers: { Depth: '1', 'Content-Type': 'application/xml' },
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	if (listing.status !== 207) {
		throw new Error(`The folder ${demoFolder}/_Import of ${librarian.uid} cannot be read (HTTP ${listing.status}).`)
	}
	const fileIds = Object.fromEntries([...listing.text.matchAll(/<d:href>([^<]+)<\/d:href>[\s\S]*?<oc:fileid>(\d+)<\/oc:fileid>/g)]
		.map((match) => [decodeURIComponent(match[1]).split('/').filter(Boolean).pop(), Number(match[2])]))

	// Everyone with a role reads the files through their own Nextcloud: the
	// conductors get the demo folder as a read-only share.
	const ocs = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
	const sharesPath = '/ocs/v2.php/apps/files_sharing/api/v1/shares'
	const shares = await librarian.request('GET', `${sharesPath}?path=${encodeURIComponent('/' + demoFolder)}`, { headers: ocs })
	const conductorGroup = process.env.NC_GROUP_CONDUCTORS ?? 'demo-conductors'
	if (!JSON.parse(shares.text).ocs?.data?.some((share) => share.share_with === conductorGroup)) {
		const shared = await librarian.request('POST', sharesPath, {
			headers: { ...ocs, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ path: '/' + demoFolder, shareType: '1', shareWith: conductorGroup, permissions: '1' }).toString(),
		})
		if (shared.status !== 200) {
			throw new Error(`Sharing ${demoFolder} with ${conductorGroup} failed: HTTP ${shared.status}`)
		}
		log(`${demoFolder} shared with ${conductorGroup} (read only)`)
	}

	const entries = check('entries', await librarian.api('GET', '/api/entries'))
	if (entries.length === 0) {
		throw new Error('The catalog is empty: import the demo catalog first (see README.md).')
	}
	const copy = Object.fromEntries(entries.map((entry) => [entry.shelfmark, entry]))
	const versionOf = async (shelfmark) => check('entry ' + shelfmark, await librarian.api('GET', `/api/entries/${copy[shelfmark].id}`)).version.id

	if (check('persons', await librarian.api('GET', '/api/persons')).length === 0) {
		const report = check('person import', await librarian.api('POST', '/api/persons/import', { fileId: fileIds['persons.csv'], dryRun: false }))
		log(`persons imported: ${report.created ?? '?'} new`)
	}
	const concerts = check('concerts', await librarian.api('GET', '/api/events?kind=concert'))
	if (!concerts.some((event) => event.imported)) {
		check('performance import', await librarian.api('POST', '/api/performances/import', { fileId: fileIds['performances.csv'], dryRun: false }))
		check('song list import', await librarian.api('POST', '/api/performances/import', { fileId: fileIds['song-lists.csv'], dryRun: false }))
		log('performances and song lists imported')
	}
	if (concerts.some((event) => event.title === CONCERT)) {
		log(`"${CONCERT}" exists: the demo state is complete`)
		return
	}

	// Rights: confirmed public domain (1-3 stays unconfirmed for the dialog)
	for (const shelfmark of ['2-1', '1-1', '1-2', '2-2']) {
		check('confirm ' + shelfmark, await librarian.api('POST', `/api/versions/${await versionOf(shelfmark)}/confirm`, {
			status: 'public_domain',
			reason: 'Everyone involved died more than 70 years ago; see the persons of this version.',
			evidence: 'Life dates in the register of persons; title page of the print',
		}))
	}
	check('portal 2-1', await librarian.api('POST', `/api/versions/${await versionOf('2-1')}/portal`, { since: '2025-09-01' }))
	check('license 2-6', await librarian.api('POST', `/api/versions/${await versionOf('2-6')}/licenses`, {
		license: { holder: 'Demo-Musikverlag', uses: ['binder'], quantity: 30, valid_from: '2025-01-01', valid_until: '2026-11-15', evidence: 'Invoice 2025-017 (demo)', notes: '' },
	}))

	// The conductor chooses the versions the choir sings
	for (const shelfmark of ['2-1', '1-1']) {
		check('valid ' + shelfmark, await conductor.api('POST', `/api/versions/${await versionOf(shelfmark)}/valid`, { reason: 'The version the choir sings' }))
	}

	// Questions and tasks with a place in the score
	check('task 2-1', await librarian.api('POST', `/api/copies/${copy['2-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'conductor', category: 'reading', question: 'Tenor II in bar 6: slur or tie? The scan is unclear.', measure_from: '5', measure_to: '8', voice: 'Tenor II' },
	}))
	check('task 1-4', await librarian.api('POST', `/api/copies/${copy['1-4'].id}/tasks`, {
		task: { kind: 'check', addressee: 'operations', category: 'shelf', question: 'Please count the copies in binder 1 before Advent.' },
	}))
	check('task 1-1', await conductor.api('POST', `/api/copies/${copy['1-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'librarian', category: 'origin', question: 'Is there an edition with the third verse for the concert?', measure_from: '1', measure_to: '4', voice: 'Sopran', verse: '3' },
	}))
	// German question for the German quick start
	check('task 1-1 (de)', await librarian.api('POST', `/api/copies/${copy['1-1'].id}/tasks`, {
		task: { kind: 'question', addressee: 'conductor', category: 'reading', question: 'Alt, Takt 3: Gilt die Viertelnote aus dem Druck oder die zwei Achtel aus der Abschrift?', measure_from: '3', measure_to: '3', voice: 'Alt' },
	}))

	// A proposal from a tool waits in the inbox
	check('proposal', await contributor.api('POST', '/api/proposals', {
		level: 'version', id: await versionOf('1-2'), fields: { key_signature: 'D major' }, reason: 'Read from the MusicXML file', source: 'Transcription round (demo)',
	}))

	// Internal link to a place in the score
	check('link 2-1', await librarian.api('POST', `/api/copies/${copy['2-1'].id}/links`, {
		link: { measure_from: '5', measure_to: '8', voice: 'Tenor II', note: 'Slur or tie in bar 6?' },
	}))

	// A planned concert with warnings
	const event = check('concert', await librarian.api('POST', '/api/events', {
		kind: 'concert',
		event: { title: CONCERT, event_date: '2026-12-13', start_time: '17:00', end_time: '18:30', location: 'Demostadt, Kirche St. Beispiel', is_public: true, board_agreed: true, notes: '' },
	}))
	for (const shelfmark of ['1-6', '1-4', '1-1', '3-1', '2-6', '4-1', '1-3']) {
		check('item ' + shelfmark, await librarian.api('POST', `/api/events/${event.id}/items`, { copyId: copy[shelfmark].id }))
	}
	const detail = check('concert detail', await librarian.api('GET', `/api/events/${event.id}`))
	const durations = [150, 185, 140, 140, 165, 160, 120]
	for (const [index, item] of detail.items.entries()) {
		check('duration', await librarian.api('PATCH', `/api/event-items/${item.id}`, { item: { duration: durations[index] ?? 120 } }))
	}
	log(`demo state created (concert ${event.id})`)
}
