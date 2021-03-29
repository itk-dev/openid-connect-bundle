<?php

namespace ItkDev\OpenIdConnectBundle\Util;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

class CliLoginHelper
{
    /** @var Connection */
    private $connection;

    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var AbstractPlatform */
    private $platform;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->schemaManager = $connection->getSchemaManager();
        $this->platform = $connection->getDatabasePlatform();
    }

    public function createToken(string $username): string
    {
        $token = Uuid::v4();

        $this->connection->insert(
            $this->getTableName(),
            [
                'username' => $username,
                'token' => $token,
            ],
            [
                Types::STRING,
                Types::STRING,
            ]
        );

        return $token;
    }


    public function getUsername(string $token, bool $remove = true): ?string
    {
        // Get username by token.
        $username = $this->connection->fetchOne(
            sprintf('SELECT username FROM %s WHERE token = :token', $this->platform->quoteIdentifier($this->getTableName())),
            ['token' => $token]
        );

        // fetchOne returns false if nothing found aka invalid token
        if (false === $username) {
            throw new Exception('Invalid token');
        }
        
        if ($remove) {
            // Remove the token.
            $this->connection->delete($this->getTableName(), [
                'token' => $token,
            ]);
        }

        // $username may be `false`, but we want it to be a string or null.
        return $username ?: null;
    }

    private function getTableName(): string
    {
        // @todo Get this from configuration.
        return 'itk_dev_cli_login';
    }

    public function ensureInitialized(): void
    {
        if (!$this->isInitialized()) {
            $expectedSchemaChangelog = $this->getExpectedTable();
            $this->schemaManager->createTable($expectedSchemaChangelog);

            return;
        }

        $expectedSchemaChangelog = $this->getExpectedTable();
        $diff = $this->needsUpdate($expectedSchemaChangelog);
        if ($diff === null) {
            return;
        }

        $this->schemaManager->alterTable($diff);
    }

    private function needsUpdate(Table $expectedTable): ?TableDiff
    {
        $comparator = new Comparator();
        $currentTable = $this->schemaManager->listTableDetails($this->getTableName());
        $diff = $comparator->diffTable($currentTable, $expectedTable);

        return $diff instanceof TableDiff ? $diff : null;
    }

    private function isInitialized(): bool
    {
        if ($this->connection instanceof MasterSlaveConnection) {
            $this->connection->connect('master');
        }

        // Check if table exists
        // @todo Figure out how to use the beneath
        // return $this->schemaManager->tablesExist([$this->getTableName()]);

        // This is a work around
        $table = $this->connection->fetchOne(
            sprintf('SHOW TABLES LIKE "%s"', $this->getTableName())
        );

        return $table!==false;
    }

    private function getExpectedTable(): Table
    {
        $schemaChangelog = new Table($this->getTableName());

        $schemaChangelog->addColumn(
            'username',
            Types::STRING,
            [
                'notnull' => true,
            ]
        );

        $schemaChangelog->addColumn(
            'token',
            Types::STRING,
            [
                'notnull' => true,
            ]
        );

        $schemaChangelog->addColumn(
            'created_at',
            Types::DATETIME_IMMUTABLE,
            [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]
        );

        $schemaChangelog->addUniqueIndex(['token']);

        return $schemaChangelog;
    }
}
