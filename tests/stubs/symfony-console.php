<?php
// Minimal Symfony Console stubs for static analysis. Nextcloud ships the real classes.

namespace Symfony\Component\Console\Input {
	interface InputInterface {
		/** @return mixed */
		public function getArgument(string $name);
		/** @return mixed */
		public function getOption(string $name);
	}
	class InputArgument {
		public const REQUIRED = 1;
		public const OPTIONAL = 2;
	}
	class InputOption {
		public const VALUE_NONE = 1;
	}
}

namespace Symfony\Component\Console\Output {
	interface OutputInterface {
		/** @param string|iterable<string> $messages */
		public function writeln($messages, int $options = 0): void;
	}
}

namespace Symfony\Component\Console\Command {
	class Command {
		public const SUCCESS = 0;
		public const FAILURE = 1;
		public const INVALID = 2;
		public function __construct(?string $name = null) {}
		public function setName(string $name): static { return $this; }
		public function setDescription(string $description): static { return $this; }
		public function addArgument(string $name, ?int $mode = null, string $description = '', mixed $default = null): static { return $this; }
		public function addOption(string $name, string|array|null $shortcut = null, ?int $mode = null, string $description = '', mixed $default = null): static { return $this; }
		protected function configure(): void {}
		protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int { return 0; }
	}
}
