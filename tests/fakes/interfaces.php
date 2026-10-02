<?php

declare(strict_types=1);

// Reduced copies of server interfaces whose full versions pull in Doctrine and dozens of
// unrelated methods. Only what this app calls is declared.

namespace OCP\DB {
	interface IResult {
		public function closeCursor(): bool;
		public function fetch(int $fetchMode = \PDO::FETCH_ASSOC);
		public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): array;
		public function fetchOne();
	}
}

namespace OCP {
	interface IDBConnection {
		public const PLATFORM_MYSQL = 'mysql';
		public const PLATFORM_ORACLE = 'oracle';
		public const PLATFORM_POSTGRES = 'postgres';
		public const PLATFORM_SQLITE = 'sqlite';
		public function executeQuery(string $sql, array $params = [], $types = []): DB\IResult;
		public function executeStatement($sql, array $params = [], array $types = []): int;
		public function inTransaction(): bool;
		public function getDatabaseProvider(): string;
	}

	interface IConfig {
		public function getSystemValueString(string $key, string $default = ''): string;
	}

	interface IAppConfig {
		public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string;
		public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int;
		public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool;
		public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;
		public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool;
		public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool;
	}
}
