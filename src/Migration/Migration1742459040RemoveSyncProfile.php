<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1742459040RemoveSyncProfile extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1742459040;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<SQL
DELETE FROM `elio_data_discovery_sync_profile`
WHERE `profile` = 'BI Sync';
SQL;
        $connection->executeStatement($sql);

        $tableHasProfiles = $connection->fetchOne("
            SELECT COUNT(*)
            FROM `elio_data_discovery_sync_profile`
        ");

        if (!$tableHasProfiles) {
            $sql = <<<SQL
TRUNCATE TABLE `elio_data_discovery_entity_status`;
SQL;

            $connection->executeStatement($sql);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
