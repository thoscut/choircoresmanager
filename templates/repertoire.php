<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The public repertoire page (milestone 0.7), rendered on the server without
// scripts. Every value is printed with p() (escaped); the links are https
// links to CPDL and IMSLP, checked by PublicRepertoireService. The texts come
// translated from the controller.

/** @var array{entries: list<array{title: string, composer: string, persons: list<array{role: string, name: string, lifeDates: ?string}>, scoring: list<string>, cpdl: ?string, imslp: ?string}>, texts: array<string, string>} $_ */
$texts = $_['texts'];
?>

<style>
	/* A page of its own on the background picture of the public layout, as the
	   page of a public link: the text needs the main background for contrast */
	.notenarchiv-repertoire {
		max-width: 50em;
		margin: calc(4 * var(--default-grid-baseline, 4px)) auto;
		padding: calc(4 * var(--default-grid-baseline, 4px));
		border-radius: var(--border-radius-container-large, var(--border-radius-large, 12px));
		background-color: var(--color-main-background, #fff);
		color: var(--color-main-text, #222);
	}

	.notenarchiv-repertoire h2 {
		margin-top: 0;
	}

	.notenarchiv-repertoire__list {
		padding: 0;
		list-style: none;
	}

	.notenarchiv-repertoire__item {
		padding-block: calc(3 * var(--default-grid-baseline, 4px));
		border-bottom: 1px solid var(--color-border, #ccc);
	}

	.notenarchiv-repertoire__item h3 {
		margin: 0 0 var(--default-grid-baseline, 4px);
		font-size: 1.15em;
	}

	.notenarchiv-repertoire__item p {
		margin: 0;
	}

	.notenarchiv-repertoire__links {
		display: flex;
		flex-wrap: wrap;
		gap: calc(3 * var(--default-grid-baseline, 4px));
		margin-top: var(--default-grid-baseline, 4px);
	}

	.notenarchiv-repertoire__links a {
		color: var(--color-main-text, #000);
		text-decoration: underline;
	}

	.notenarchiv-repertoire__links a:focus-visible {
		outline: 2px solid var(--color-main-text, #000);
		outline-offset: 2px;
	}
</style>

<div class="notenarchiv-repertoire">
	<h2><?php p($texts['heading']); ?></h2>
	<p><?php p($texts['intro']); ?></p>
	<?php if ($_['entries'] === []): ?>
		<p><?php p($texts['empty']); ?></p>
	<?php else: ?>
		<ul class="notenarchiv-repertoire__list">
			<?php foreach ($_['entries'] as $entry): ?>
				<li class="notenarchiv-repertoire__item">
					<h3><?php p($entry['title']); ?></h3>
					<?php /* The composer as written, unless the register names one (e.g. "Volksweise") */ ?>
					<?php if ($entry['composer'] !== '' && !in_array('composer', array_column($entry['persons'], 'role'), true)): ?>
						<p><?php p($texts['composer']); ?>: <?php p($entry['composer']); ?></p>
					<?php endif; ?>
					<?php foreach ($entry['persons'] as $person): ?>
						<p>
							<?php p($person['role'] === 'lyricist' ? $texts['lyricist'] : $texts['composer']); ?>:
							<?php p($person['name']); ?><?php if ($person['lifeDates'] !== null): ?> (<?php p($person['lifeDates']); ?>)<?php endif; ?>
						</p>
					<?php endforeach; ?>
					<?php if ($entry['scoring'] !== []): ?>
						<p><?php p($texts['scoring']); ?>: <?php p(implode(', ', $entry['scoring'])); ?></p>
					<?php endif; ?>
					<?php if ($entry['cpdl'] !== null || $entry['imslp'] !== null): ?>
						<p class="notenarchiv-repertoire__links">
							<?php if ($entry['cpdl'] !== null): ?>
								<a href="<?php p($entry['cpdl']); ?>" rel="external noopener noreferrer"><?php p($texts['cpdl']); ?></a>
							<?php endif; ?>
							<?php if ($entry['imslp'] !== null): ?>
								<a href="<?php p($entry['imslp']); ?>" rel="external noopener noreferrer"><?php p($texts['imslp']); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
