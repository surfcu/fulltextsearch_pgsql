<?php

declare(strict_types=1);

namespace OCA\FullTextSearch_PgSql\Tests;

use OCP\DB\IResult;
use OCP\FullTextSearch\Model\IIndex;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

final class PdoResult implements IResult {
	public function __construct(private PDOStatement $stmt) {
	}
	public function closeCursor(): bool {
		return $this->stmt->closeCursor();
	}
	public function fetch(int $fetchMode = PDO::FETCH_ASSOC) {
		return $this->stmt->fetch($fetchMode);
	}
	public function fetchAll(int $fetchMode = PDO::FETCH_ASSOC): array {
		return $this->stmt->fetchAll($fetchMode);
	}
	public function fetchOne() {
		return $this->stmt->fetchColumn();
	}
}

/** Mimics Nextcloud's connection: positional parameters and *PREFIX* replacement. */
final class PdoConnection implements IDBConnection {
	/** @var list<string> */
	public array $statements = [];

	public function __construct(public PDO $pdo, private string $prefix = 'oc_') {
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	public function executeQuery(string $sql, array $params = [], $types = []): IResult {
		$stmt = $this->pdo->prepare($this->sql($sql));
		$stmt->execute(array_values($params));
		return new PdoResult($stmt);
	}
	public function executeStatement($sql, array $params = [], array $types = []): int {
		$stmt = $this->pdo->prepare($this->sql($sql));
		$stmt->execute(array_values($params));
		return $stmt->rowCount();
	}
	public function inTransaction(): bool {
		return $this->pdo->inTransaction();
	}
	public function getDatabaseProvider(): string {
		return self::PLATFORM_POSTGRES;
	}
	private function sql(string $sql): string {
		$sql = str_replace('*PREFIX*', $this->prefix, $sql);
		$this->statements[] = $sql;
		return $sql;
	}
}

final class ArrayAppConfig implements IAppConfig {
	public array $values = [];
	public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string {
		return (string)($this->values[$key] ?? $default);
	}
	public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int {
		return (int)($this->values[$key] ?? $default);
	}
	public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool {
		return (bool)($this->values[$key] ?? $default);
	}
	public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
		$this->values[$key] = $value;
		return true;
	}
	public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool {
		$this->values[$key] = $value;
		return true;
	}
	public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool {
		$this->values[$key] = $value;
		return true;
	}
}

final class ArrayConfig implements IConfig {
	public function __construct(private array $system = []) {
	}
	public function getSystemValueString(string $key, string $default = ''): string {
		return (string)($this->system[$key] ?? $default);
	}
}

final class MemoryLogger implements LoggerInterface {
	public array $records = [];
	public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
	public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
	public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
	public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
	public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
	public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
	public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
	public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->records[] = [$level, (string)$message];
	}
}

/** Same status semantics as OCA\FullTextSearch\Model\Index. */
final class TestIndex implements IIndex {
	private int $status = 0;
	private array $errors = [];
	private int $lastIndex = 0;
	private array $options = [];
	private string $source = '';
	private string $ownerId = '';

	public function __construct(private string $providerId, private string $documentId, int $status = IIndex::INDEX_FULL) {
		$this->status = $status;
	}
	public function getProviderId(): string { return $this->providerId; }
	public function getDocumentId(): string { return $this->documentId; }
	public function getCollection(): string { return ''; }
	public function setSource(string $source): IIndex { $this->source = $source; return $this; }
	public function getSource(): string { return $this->source; }
	public function setOwnerId(string $ownerId): IIndex { $this->ownerId = $ownerId; return $this; }
	public function getOwnerId(): string { return $this->ownerId; }
	public function setStatus(int $status, bool $reset = false): IIndex {
		$this->status = $reset ? $status : ($this->status | $status);
		return $this;
	}
	public function getStatus(): int { return $this->status; }
	public function isStatus(int $status): bool { return (bool)($status & $this->status); }
	public function unsetStatus(int $status): IIndex { $this->status &= ~$status; return $this; }
	public function addOption(string $option, string $value): IIndex { $this->options[$option] = $value; return $this; }
	public function addOptionInt(string $option, int $value): IIndex { $this->options[$option] = $value; return $this; }
	public function getOption(string $option, string $default = ''): string { return (string)($this->options[$option] ?? $default); }
	public function getOptionInt(string $option, int $default = 0): int { return (int)($this->options[$option] ?? $default); }
	public function getOptions(): array { return $this->options; }
	public function addError(string $message, string $exception = '', int $sev = self::ERROR_SEV_3): IIndex {
		$this->errors[] = $message;
		return $this;
	}
	public function getErrorCount(): int { return count($this->errors); }
	public function getErrors(): array { return $this->errors; }
	public function resetErrors(): IIndex { $this->errors = []; return $this; }
	public function setLastIndex(int $lastIndex = -1): IIndex { $this->lastIndex = $lastIndex === -1 ? time() : $lastIndex; return $this; }
	public function getLastIndex(): int { return $this->lastIndex; }
	public function jsonSerialize(): array { return []; }
}

/**
 * Builds an object implementing $interface. Methods listed in $handlers run that closure
 * (or return that value); every other method returns $this for fluent interfaces or an
 * empty value of its return type.
 */
function double(string $interface, array $handlers = []): object {
	static $n = 0;
	$ref = new ReflectionClass($interface);
	$class = 'TestDouble' . (++$n);
	$methods = [];
	foreach ($ref->getMethods() as $method) {
		$params = [];
		foreach ($method->getParameters() as $p) {
			$type = $p->hasType() ? typeToString($p->getType()) . ' ' : '';
			$default = '';
			if ($p->isDefaultValueAvailable()) {
				$default = ' = ' . var_export($p->getDefaultValue(), true);
			} elseif ($p->isOptional() && !$p->isVariadic()) {
				$default = ' = null';
			}
			$params[] = $type . ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . $default;
		}
		$returnType = $method->hasReturnType() ? typeToString($method->getReturnType()) : '';
		$body = $returnType === 'void'
			? '$this->__handle(__FUNCTION__, func_get_args());'
			: 'return $this->__handle(__FUNCTION__, func_get_args());';
		$static = $method->isStatic() ? 'static ' : '';
		$methods[] = sprintf(
			'public %sfunction %s(%s)%s { %s }',
			$static, $method->getName(), implode(', ', $params), $returnType !== '' ? ': ' . $returnType : '', $body
		);
	}

	$source = 'return new class($handlers, ' . var_export($interface, true) . ') implements \\' . $interface . ' {
		public function __construct(private array $__handlers, private string $__interface) {}
		public function __handle(string $name, array $args): mixed {
			$type = (new \\ReflectionMethod($this->__interface, $name))->getReturnType();
			if (array_key_exists($name, $this->__handlers)) {
				$h = $this->__handlers[$name];
				$value = $h instanceof \\Closure ? $h(...$args) : $h;
				// Fluent setters: a handler that returns nothing still returns the object.
				if ($value === null && $type instanceof \\ReflectionNamedType && !$type->isBuiltin() && !$type->allowsNull()) {
					return $this;
				}
				return $value;
			}
			if ($type === null) { return $this; }
			$t = $type instanceof \\ReflectionNamedType ? $type->getName() : "mixed";
			return match (true) {
				$type->allowsNull() => null,
				$t === "string" => "",
				$t === "int" => 0,
				$t === "float" => 0.0,
				$t === "bool" => false,
				$t === "array" => [],
				default => $this,
			};
		}
		' . implode("\n", $methods) . '
	};';
	return eval($source);
}

function typeToString(\ReflectionType $type): string {
	if ($type instanceof ReflectionUnionType) {
		return implode('|', array_map(fn ($t) => typeToString($t), $type->getTypes()));
	}
	/** @var ReflectionNamedType $type */
	$name = $type->getName();
	$prefix = $type->isBuiltin() || in_array($name, ['self', 'static'], true) ? '' : '\\';
	$nullable = $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '';
	return $nullable . $prefix . $name;
}
