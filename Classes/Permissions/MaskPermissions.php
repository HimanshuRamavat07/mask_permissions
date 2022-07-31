<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace HOV\MaskPermissions\Permissions;

use MASK\Mask\Helper\FieldHelper;
use TYPO3\CMS\Beuser\Domain\Repository\BackendUserGroupRepository;
use MASK\Mask\Domain\Repository\StorageRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class MaskPermissions
{
    protected $defaultExcludeFields = [
        'sys_language_uid',
        'starttime',
        'endtime',
        'l10n_parent',
        'hidden',
        'fe_group',
        'editlock'
    ];

    public function update(int $groupUid = 0): bool
    {
        $maskConfig = $this->getMaskConfig();
        if ($maskConfig === []) {
            return false;
        }

        if ($groupUid !== 0) {
            $groups = [$groupUid];
        } else {
            $groups = $this->getBeUserGroups();
        }

        foreach ($groups as $group) {
            $result = $this->getPermissions($group);

            // Update non_exclude_fields
            $nonExcludeFields = $result['non_exclude_fields'];
            $nonExcludeFields = GeneralUtility::trimExplode(',', $nonExcludeFields);
            $nonExcludeFields = array_merge(
                $nonExcludeFields,
                $this->getMaskFields($maskConfig),
                $this->getMaskAdditionalTableModify($maskConfig)
            );
            $nonExcludeFields = array_unique($nonExcludeFields);
            $nonExcludeFields = implode(',', $nonExcludeFields);

            $queryBuilder = $this->getQueryBuilder('be_groups');
            $queryBuilder
                ->update('be_groups')
                ->set('non_exclude_fields', $nonExcludeFields)
                ->where($queryBuilder->expr()->eq('uid', $group))
                ->execute();

            // Update tables_modify
            $tablesModify = $result['tables_modify'];
            $tablesModify = GeneralUtility::trimExplode(',', $tablesModify);
            $tablesModify = array_merge($tablesModify, $this->getMaskCustomTables($maskConfig));
            $tablesModify = array_unique($tablesModify);
            $tablesModify = implode(',', $tablesModify);

            $queryBuilder
                ->update('be_groups')
                ->set('tables_modify', $tablesModify)
                ->where($queryBuilder->expr()->eq('uid', $group))
                ->execute();

            // Update explicit_allowdeny
            $explicitAllowDeny = $result['explicit_allowdeny'];
            $explicitAllowDeny = GeneralUtility::trimExplode(',', $explicitAllowDeny);
            $explicitAllowDeny = array_merge($explicitAllowDeny, $this->getMaskExplicitAllow($maskConfig));
            $explicitAllowDeny = array_unique($explicitAllowDeny);
            $explicitAllowDeny = implode(',', $explicitAllowDeny);

            $queryBuilder
                ->update('be_groups')
                ->set('explicit_allowdeny', $explicitAllowDeny)
                ->where($queryBuilder->expr()->eq('uid', $group))
                ->execute();
        }
        return true;
    }

    /**
     * Is an update necessary?
     *
     * Is used to determine whether a wizard needs to be run.
     * Check if data for migration exists.
     */
    public function updateNecessary(int $groupUid = 0): bool
    {
        $maskConfig = $this->getMaskConfig();
        if ($maskConfig === []) {
            return false;
        }

        if ($groupUid) {
            $groups = [$groupUid];
        } else {
            $groups = $this->getBeUserGroups();
        }

        foreach ($groups as $uid) {
            $result = $this->getPermissions($uid);

            $nonExcludeFields = $result['non_exclude_fields'];
            $nonExcludeFields = GeneralUtility::trimExplode(',', $nonExcludeFields);
            $nonExcludeFields = array_filter(
                $nonExcludeFields,
                static function ($item) {
                    return strpos($item, 'tx_mask') !== false;
                }
            );

            $fields = array_merge($this->getMaskFields($maskConfig), $this->getMaskAdditionalTableModify($maskConfig));
            $fieldsToUpdate = array_diff($fields, $nonExcludeFields);

            $tablesModify = $result['tables_modify'];
            $tablesModify = GeneralUtility::trimExplode(',', $tablesModify);
            $tablesModify = array_filter(
                $tablesModify,
                static function ($item) {
                    return strpos($item, 'tx_mask') !== false;
                }
            );

            $tablesToUpdate = array_diff($this->getMaskCustomTables($maskConfig), $tablesModify);

            $explicitAllowDeny = $result['explicit_allowdeny'];
            $explicitAllowDeny = GeneralUtility::trimExplode(',', $explicitAllowDeny);
            $explicitAllowDenyToUpdate = array_diff($this->getMaskExplicitAllow($maskConfig), $explicitAllowDeny);

            if ($fieldsToUpdate || $tablesToUpdate || $explicitAllowDenyToUpdate) {
                return true;
            }
        }
        return false;
    }

    protected function getQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder;
    }

    protected function getMaskConfig(): array
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $maskConfig = $storageRepository->load();
        if (!$maskConfig) {
            return [];
        }
        return $maskConfig;
    }

    protected function getMaskFields(array $maskConfig): array
    {
        if (method_exists(FieldHelper::class, 'getFormType')) {
            $fieldHelper = GeneralUtility::makeInstance(FieldHelper::class);
        } else {
            $fieldHelper = GeneralUtility::makeInstance(StorageRepository::class);
        }
        $elements = $this->getMaskElements($maskConfig);
        $fields = [];

        foreach ($elements as $element) {
            if (!array_key_exists('columns', $element)) {
                continue;
            }
            $columns = $element['columns'];
            foreach ($columns as $col) {
                if ($fieldHelper->getFormType($col, $element['key']) === 'palette') {
                    foreach ($maskConfig['tt_content']['palettes'][$col]['showitem'] ?? [] as $item) {
                        $fields = $this->addField($fields, $item);
                    }
                } else {
                    $fields = $this->addField($fields, $col);
                }
            }
        }
        return $fields;
    }

    protected function addField(array $fields, string $column): array
    {
        if (strpos($column, 'tx_mask') !== false) {
            $fields[] = 'tt_content:' . $column;
        }
        return $fields;
    }

    protected function getMaskCustomTables(array $maskConfig): array
    {
        $keys = array_keys($maskConfig);
        return array_filter(
            $keys,
            static function ($item) {
                return strpos($item, 'tx_mask') !== false;
            }
        );
    }

    protected function getMaskAdditionalTableModify(array $maskConfig): array
    {
        $customTables = $this->getMaskCustomTables($maskConfig);
        $additionalTableModify = [];
        foreach ($customTables as $key) {
            foreach ($maskConfig[$key]['tca'] as $tcaField => $value) {
                $additionalTableModify[] = $key . ':' . $tcaField;
            }
            foreach ($this->defaultExcludeFields as $default) {
                $additionalTableModify[] = $key . ':' . $default;
            }
        }
        return $additionalTableModify;
    }

    protected function getMaskExplicitAllow(array $maskConfig): array
    {
        $elements = $this->getMaskElements($maskConfig);
        $explicitAllow = [];
        foreach ($elements as $element => $value) {
            $explicitAllow[] = 'tt_content:CType:mask_' . $element . ':ALLOW';
        }
        return $explicitAllow;
    }

    protected function getPermissions($uid)
    {
        $queryBuilder = $this->getQueryBuilder('be_groups');
        return $queryBuilder
            ->select('non_exclude_fields', 'tables_modify', 'explicit_allowdeny')
            ->from('be_groups')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->execute()
            ->fetch();
    }

    protected function getMaskElements(array $maskConfig): array
    {
        return $maskConfig['tt_content']['elements'] ?? [];
    }

    protected function getBeUserGroups(): array
    {
        $uids = [];
        $backendUserGroups = GeneralUtility::makeInstance(BackendUserGroupRepository::class)->findAll();
        foreach ($backendUserGroups as $group) {
            $uids[] = $group->getUid();
        }
        return $uids;
    }
}
