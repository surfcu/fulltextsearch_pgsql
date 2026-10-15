<?php
/**
 * @var \OCP\IL10N $l
 * @var array{config: array<string, mixed>, defaults: array<string, mixed>, languages: list<string>,
 *            pdfExtractors: list<string>, status: array<string, mixed>, saveUrl: string} $_
 */
$config = $_['config'];
?>
<div id="ftspg-settings" class="section"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-status="<?php p((string)json_encode($_['status'])); ?>">
	<h2><?php p($l->t('PostgreSQL search platform')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Settings for the PostgreSQL platform of Full text search. The same settings are available with occ fulltextsearch_pgsql:configure.')); ?>
	</p>

	<h3><?php p($l->t('Status')); ?></h3>
	<ul class="ftspg-status" aria-live="polite"></ul>

	<form class="ftspg-form">
		<h3><?php p($l->t('Language')); ?></h3>
		<p class="ftspg-field">
			<label for="ftspg-language"><?php p($l->t('Text search language')); ?></label>
			<select id="ftspg-language" name="language">
				<?php foreach ($_['languages'] as $language): ?>
					<option value="<?php p($language); ?>"<?php if ($language === $config['language']): ?> selected<?php endif; ?>><?php p($language); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="settings-hint">
			<?php p($l->t('Controls stemming and stop words. Documents keep the language they were indexed with: after a change, rebuild the index.')); ?>
		</p>

		<h3><?php p($l->t('Matching')); ?></h3>
		<p class="ftspg-field">
			<input type="checkbox" class="checkbox" id="ftspg-use-trigram" name="use_trigram"<?php if ($config['use_trigram']): ?> checked<?php endif; ?>>
			<label for="ftspg-use-trigram"><?php p($l->t('Match words inside titles and file names (needs pg_trgm)')); ?></label>
		</p>
		<p class="ftspg-field">
			<input type="checkbox" class="checkbox" id="ftspg-typo-correction" name="typo_correction"<?php if ($config['typo_correction']): ?> checked<?php endif; ?>>
			<label for="ftspg-typo-correction"><?php p($l->t('Correct typos and missing accents from the indexed vocabulary')); ?></label>
		</p>
		<p class="ftspg-field">
			<label for="ftspg-max-results"><?php p($l->t('Maximum results per search')); ?></label>
			<input type="number" id="ftspg-max-results" name="max_results" min="1" max="10000" required
				value="<?php p((string)$config['max_results']); ?>">
		</p>

		<h3><?php p($l->t('Content extraction')); ?></h3>
		<p class="ftspg-field">
			<label for="ftspg-pdf-extractor"><?php p($l->t('Read PDF files with')); ?></label>
			<select id="ftspg-pdf-extractor" name="pdf_extractor">
				<?php
				$labels = [
					'auto' => $l->t('pdftotext when installed, otherwise the built-in parser'),
					'pdftotext' => $l->t('pdftotext only'),
					'pdfparser' => $l->t('Built-in parser only'),
				];
foreach ($_['pdfExtractors'] as $extractor): ?>
					<option value="<?php p($extractor); ?>"<?php if ($extractor === $config['pdf_extractor']): ?> selected<?php endif; ?>><?php p($labels[$extractor] ?? $extractor); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="settings-hint">
			<?php p($l->t('pdftotext (poppler-utils) is faster and copes with more files. The built-in parser needs nothing installed, but uses more memory and skips files over 64 MB.')); ?>
		</p>
		<p class="ftspg-field">
			<label for="ftspg-pdftotext-path"><?php p($l->t('Path to pdftotext')); ?></label>
			<input type="text" id="ftspg-pdftotext-path" name="pdftotext_path" spellcheck="false" autocomplete="off"
				placeholder="<?php p($l->t('Found on PATH')); ?>"
				value="<?php p((string)$config['pdftotext_path']); ?>">
		</p>
		<p class="ftspg-field">
			<label for="ftspg-max-content-size"><?php p($l->t('Text kept per document (bytes)')); ?></label>
			<input type="number" id="ftspg-max-content-size" name="max_content_size" min="0" max="1000000" required
				value="<?php p((string)$config['max_content_size']); ?>">
		</p>
		<p class="settings-hint">
			<?php p($l->t('PostgreSQL limits each document\'s search data to 1 MB; text beyond this size is not indexed.')); ?>
		</p>

		<p class="ftspg-actions">
			<button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
			<span class="ftspg-message" role="status"></span>
		</p>
		<p class="ftspg-reindex" hidden>
			<?php p($l->t('The language changed. Rebuild the index so existing documents use it:')); ?>
			<code>occ fulltextsearch:reset &amp;&amp; occ fulltextsearch:index</code>
		</p>
	</form>
</div>
