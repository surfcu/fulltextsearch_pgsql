<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Command;

use OCA\FullTextSearch_PgSql\Service\SchemaService;
use OCA\FullTextSearch_PgSql\Service\VocabularyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ fulltextsearch_pgsql:vocabulary             show the vocabulary size
 * occ fulltextsearch_pgsql:vocabulary --rebuild   recreate it from the index
 */
class Vocabulary extends Command {

	public function __construct(
		private VocabularyService $vocabulary,
		private SchemaService $schemaService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('fulltextsearch_pgsql:vocabulary')
			->setDescription('Show or rebuild the word list used for typo correction')
			->addOption('rebuild', null, InputOption::VALUE_NONE, 'Recreate the word list from the index; drops words that no longer occur');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->schemaService->ensureSchema();
		if ($input->getOption('rebuild')) {
			$last = -1;
			$words = $this->vocabulary->rebuild(function (int $done, int $total) use ($output, &$last): void {
				$percent = $total > 0 ? intdiv($done * 100, $total) : 100;
				if ($percent !== $last) {
					$output->writeln("$done / $total documents ($percent%)");
					$last = $percent;
				}
			});
			$output->writeln("Vocabulary rebuilt: $words words");
			return self::SUCCESS;
		}
		$output->writeln('Vocabulary: ' . $this->vocabulary->count() . ' words');
		return self::SUCCESS;
	}
}
