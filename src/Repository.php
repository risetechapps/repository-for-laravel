<?php

namespace RiseTechApps\Repository;

class Repository
{
    public static array $driverNotSupported = ["file"];
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
        $bindings = app()->getBindings();

        return collect($bindings)
            ->filter(function ($binding, $abstract) {
                return str_contains($abstract, 'Repository');
            })
            ->map(function ($binding, $abstract) {
                return [
                    'interface' => $abstract,
                    'concrete'  => $binding['concrete'],
                ];
            })
            ->values()
            ->toArray();
    }
}
