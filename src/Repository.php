<?php

namespace RiseTechApps\Repository;

use Illuminate\Container\Container;
use RiseTechApps\Repository\Contracts\RepositoryInterface;
use RiseTechApps\Repository\Core\BaseRepository;

class Repository
{
    public static array $driverNotSupported = ["file", "database"];
    public static string $methodFirst = 'FIRST';
    public static string $methodAll = 'ALL';
    public static string $methodFind = 'FIND';
    public static string $methodFindWhere = 'FIND_WHERE';
    public static string $methodFindWhereCustom = 'FIND_WHERE_CUSTOM';
    public static string $methodFindWhereEmail = 'FIND_WHERE_EMAIL';
    public static string $methodFindWhereFirst = 'FIND_WHERE_FIRST';
    public static string $methodDataTable = 'DATATABLE';
    public static string $methodOrder = 'ORDER';
    public static array $tagsCache = [];

    public static function setTagsCache(string $tag): void
    {
        self::$tagsCache[] = $tag;
    }

    public static function getTagsCache(): array
    {
        return self::$tagsCache;
    }

    public static function getBindingsRepository(): array
    {
        $allBindings = app()->getBindings();

        $repositoryContracts = [];

        foreach (array_keys($allBindings) as $contractName) {
            if (str_contains($contractName, 'Repository') && is_subclass_of($contractName,'RiseTechApps\Repository\Contracts\RepositoryInterface')) {
                $repositoryContracts[] = $contractName;
            }
        }

        return $repositoryContracts;
    }
}
