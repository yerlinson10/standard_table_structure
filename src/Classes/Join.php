<?php
namespace Generate\StandardTable\Classes;
use Illuminate\Support\Facades\Schema;

class Join {

    /**
 * This function is used to join a related table to the given query based on the provided join configuration.
 *
 * @param Illuminate\Database\Query\Builder $query The query builder instance to apply the join.
 * @param string $table The name of the table to join.
 * @param array $joinConfig The configuration for the join, including the field to join on, the related table, and the related field.
 * @param array $selectedColumns The array to store the selected columns from the joined table.
 *
 * @return void
 */
public function joinTable(&$query, $table, &$joinConfig, &$selectedColumns):void {
    $field = $joinConfig['field'];
    $joinType = $joinConfig['joinType'] ?? 'inner';
    $joinTypeQuery = 'inner';
    $relatedTable = $joinConfig['relatedTable'];
    $relatedField = $joinConfig['relatedField'];

    if (!Schema::hasColumn($table, $field) || !Schema::hasColumn($relatedTable, $relatedField)) {
        return;
    }

    $joinTypeQuery = ($joinType == 'left' || $joinType == 'right') ? $joinType : $joinTypeQuery;
    $query->join($relatedTable, "$table.$field", '=', "$relatedTable.$relatedField", $joinTypeQuery);

    if (isset($joinConfig['select'])) {
        foreach ($joinConfig['select'] as $joinedColumn) {
            if ($joinedColumn == '*') {
                $selectedColumns[] = "$relatedTable.$joinedColumn";
                break;
            } elseif (Schema::hasColumn($relatedTable, $joinedColumn)) {
                $selectedColumns[] = "$relatedTable.$joinedColumn as $relatedTable.$joinedColumn";
            }
        }
    }
}
}
