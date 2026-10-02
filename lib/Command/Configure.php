<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Command;

use InvalidArgumentException;
use OCA\FullTextSearch_PgSql\Service\ConfigService;
use OCA\FullTextSearch_PgSql\Service\SchemaService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ fulltextsearch_pgsql:configure                       show settings
 * occ fulltextsearch_pgsql:configure '{"language":"turkish"}'
 * occ fulltextsearch_pgsql:configure --languages           list text search configurations
 */
class Configure extends Command {

	public function __construct(
		private ConfigService $configService,
		private SchemaService $schemaService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('fulltextsearch_pgsql:configure')
			->setDescription('Show or change the PostgreSQL full text search settings')
			->addArgument('json', InputArgument::OPTIONAL, 'Settings to change, as a JSON object')
			->addOption('languages', null, InputOption::VALUE_NONE, 'List the languages this PostgreSQL server supports');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('languages')) {
			$output->writeln(implode("\n", $this->schemaService->getTextSearchConfigs()));
			return self::SUCCESS;
		}

		$json = (string)$input->getArgument('json');
		if ($json !== '') {
			$settings = json_decode($json, true);
			if (!is_array($settings)) {
				$output->writeln('<error>Argument must be a JSON object, e.g. {"language":"turkish"}</error>');
				return self::INVALID;
			}
			try {
				$languageChanged = $this->configService->setConfig($settings);
			} catch (InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return self::INVALID;
			}
			if ($languageChanged) {
				$output->writeln('<comment>Language changed. Rebuild the index so existing documents use it:</comment>');
				$output->writeln('<comment>  occ fulltextsearch:reset && occ fulltextsearch:index</comment>');
			}
		}

		$output->writeln((string)json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return self::SUCCESS;
	}
}
