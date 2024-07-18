<?php
namespace Generate\StandardTable;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Generate\StandardTable\Classes\Join;
use Generate\StandardTable\Enums\TypeColumn;
use Generate\StandardTable\Enums\TypeFilterColumn;

class TableStructureService
{
    public $table = '';
    public $page_length = 10;
    public $rename_columns = [];
    public $column_types = [];
    public $column_filters = [];
    public $selected_columns = [];
    public $join;
    public $sorting = null;
    public $default_join = [
        [
            'field' => 'owner_id',
            'joinType' => 'inner',
            'relatedTable' => 'users',
            'relatedField' => 'rec_id',
            'select' => ['display_name', 'email']
        ]
    ];

        /**
     * Constructs a new instance of TableStructureService.
     * Initializes the 'join' property with a new instance of Join.
     */
    public function __construct() {
        $this->join = new Join();
    }


        /**
     * Sets the configuration settings for the table structure service.
     *
     * @param array $config An associative array containing the configuration settings.
     *                      The array should have the following keys:
     *                      - 'table': The name of the database table.
     *                      - 'pageLength' (optional): The number of rows to display per page.
     *                      - 'rename_columns' (optional): An array of column rename mappings.
     *                      - 'column_types' (optional): An array of column type mappings.
     *                      - 'column_filters' (optional): An array of column filter mappings.
     *                      - 'selected_columns' (optional): An array of selected column configurations.
     *                      - 'column_join' (optional): An array of default join configurations.
     *                      - 'column_sorting' (optional): Sort ascending or descending.
     *
     * @throws Exception If an error occurs while setting the settings.
     */
    public function setSettings($config)
    {
        try {
            $this->table = $config['table'];

            $this->page_length = $config['pageLength'] ?? $this->page_length;
            $this->rename_columns = $config['rename_columns'] ?? $this->rename_columns;
            $this->column_types = $config['column_types'] ?? $this->column_types;
            $this->column_filters = $config['column_filters'] ?? $this->column_filters;
            $this->selected_columns = $config['selected_columns'] ?? $this->getAllColumnDataBase();
            $this->default_join = array_merge($this->default_join, $config['column_join'] ?? []);
            $this->sorting = $this->sorting ?? $config['sorting'];
        } catch (Exception $e) {
            Log::error("Error setting settings: " . $e->getMessage());
        }
    }

        /**
     * Retrieves the table structure and data based on the provided configuration.
     *
     * @param \Closure|null $callback An optional callback function that can be used to modify the query before executing it.
     *
     * @return array An associative array containing the table structure and data.
     *               If an error occurs, the array will contain an 'error' key with the error message.
     *
     * @throws Exception If an error occurs while retrieving the table structure or data.
     */
    public function getTableStructure(?\Closure $callback = null)
    {
        try {
            // Retrieve column details from the database table
            $columnDetails = DB::select("DESCRIBE {$this->table}");

            // Initialize variables for column configuration and order numbers
            $columnsConfig = collect();
            $orderNumbers = [];
            $counterOrder = 0;

            // Process each column detail to build the column configuration
            foreach ($columnDetails as $column) {
                $selectedColumnConfig = $this->getSelectedColumnConfig($column->Field);
                if ($selectedColumnConfig === null) {
                    continue;
                }

                $originalTitle = ucwords(str_replace('_', ' ', $column->Field));
                $newTitle = $this->getNewTitle($column->Field);
                $columnType = $this->getColumnType($column->Field, $column->Type);
                $filterType = $this->getColumnFilter($column->Field, $column->Type);

                $order = $selectedColumnConfig['order'] ?? $counterOrder++;

                if ($order !== null) {
                    $order = intval($order);
                    if (in_array($order, $orderNumbers)) {
                        $nextOrder = 1;
                        while (in_array($nextOrder, $orderNumbers)) {
                            $nextOrder++;
                        }
                        $order = $nextOrder;
                    }
                    $orderNumbers[] = $order;
                }

                $columnsConfig->push([
                    'title' => $newTitle ?? $originalTitle,
                    'data' => $column->Field,
                    'visible' => $selectedColumnConfig['visible'] ?? true,
                    'type' => $columnType,
                    'typefilter' => $filterType,
                    'order' => $order
                ]);
            }

            // Build the selected columns array
            $selectedColumns = collect($this->selected_columns)->map(function ($col) {
                return $this->table . '.' . $col['data'];
            })->toArray();

            // Initialize the query builder with the main table
            $query = DB::table($this->table);

            // Apply default joins to the query
            foreach ($this->default_join as $joinConfig) {
                $this->join->joinTable($query, $this->table, $joinConfig, $selectedColumns);
            }

            // Select the required columns for the query
            $query->select($selectedColumns);

            // Apply conditions to the query based on the selected columns configuration
            foreach ($this->selected_columns as $columnConfig) {
                if (isset($columnConfig['conditions'])) {
                    foreach ($columnConfig['conditions'] as $condition) {
                        if ($condition[0] == 'where') {
                            $query->where($this->table . '.' . $columnConfig['data'], $condition[1], $condition[2]);
                        } elseif ($condition[0] == 'orWhere') {
                            $query->orWhere($this->table . '.' . $columnConfig['data'], $condition[1], $condition[2]);
                        }
                    }
                }
            }

            // Execute the callback function if provided
            if (!is_null($callback)) {
                $callback->call($this, $query);
            }

            if(!is_null($this->sorting)){
                $query->orderBy($this->sorting[0], $this->sorting[1]);
            }
            // Execute the query and retrieve the data
            $data = $query->get();

            // Return the table structure and data
            return [
                'columns' => $columnsConfig,
                'data' => $data,
                'pageLength' => $this->page_length
            ];
        } catch (Exception $e) {
            // Log the error and return the error message
            Log::error("Error getting table structure: " . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }

        /**
     * Retrieves all column names from the specified database table.
     *
     * @return array An array of associative arrays, where each array contains a single key-value pair.
     *               The key is 'data' and the value is the name of a column from the database table.
     *
     * @throws Exception If an error occurs while retrieving the column names.
     */
    public function getAllColumnDataBase(): array
    {
        // Retrieve the column names from the database table using Laravel's Schema facade
        $columns = Schema::getColumnListing($this->table);

        // Initialize an empty array to store the column names in the required format
        $data_columns = [];

        // Iterate through the retrieved column names and format them as required
        foreach ($columns as $column) {
            $data_columns[] = ['data' => $column];
        }

        // Return the formatted column names
        return $data_columns;
    }

        /**
     * Retrieves the new title for a given original title based on the rename_columns configuration.
     *
     * @param string $originalTitle The original title to be renamed.
     *
     * @return string|null The new title if a mapping is found in the rename_columns configuration,
     *                     or null if no mapping is found.
     */
    private function getNewTitle($originalTitle)
    {
        foreach ($this->rename_columns as $mapping) {
            if ($mapping[0] === $originalTitle) {
                return $mapping[1];
            }
        }
        return null;
    }

        /**
     * Retrieves the DataTables column type for a given column based on its field name and database type.
     * If a specific column type is defined for the column in the column_types configuration,
     * it will be returned. Otherwise, the column type will be determined by mapping the database type
     * to a default column type using the mapColumnType method.
     *
     * @param string $fieldName The name of the column field.
     * @param string $dbType The database type of the column.
     *
     * @return string The DataTables column type for the column.
     */
    private function getColumnType($fieldName, $dbType)
    {
        foreach ($this->column_types as $mapping) {
            if ($mapping[0] === $fieldName) {
                return $mapping[1];
            }
        }

        return $this->mapColumnType($dbType)[0];
    }

        /**
     * Retrieves the filter type for a given column based on its field name and database type.
     * If a specific filter type is defined for the column in the column_filters configuration,
     * it will be returned. Otherwise, the filter type will be determined by mapping the database type
     * to a default filter type using the mapColumnType method.
     *
     * @param string $fieldName The name of the column field.
     * @param string $dbType The database type of the column.
     *
     * @return string The filter type for the column.
     */
    private function getColumnFilter($fieldName, $dbType)
    {
        foreach ($this->column_filters as $mapping) {
            if ($mapping[0] === $fieldName) {
                return $mapping[1];
            }
        }

        return $this->mapColumnType($dbType)[1];
    }

        /**
     * Retrieves the configuration for a selected column based on its field name.
     *
     * @param string $fieldName The name of the column field.
     *
     * @return array|null The configuration for the selected column if found, or null if not found.
     *
     * The configuration array will have the following structure:
     * [
     *     'data' => string, // The name of the column field.
     *     'visible' => bool, // Indicates whether the column is visible in the table.
     *     'type' => string, // The type of the column (e.g., TypeColumn::Number, TypeColumn::Text).
     *     'typefilter' => string, // The type of filter for the column (e.g., TypeFilterColumn::Number, TypeFilterColumn::Text).
     *     'order' => int|null, // The order of the column in the table.
     *     // Additional column-specific configurations can be added here.
     * ]
     */
    private function getSelectedColumnConfig($fieldName)
    {
        foreach ($this->selected_columns as $columnConfig) {
            if ($columnConfig['data'] === $fieldName) {
                return $columnConfig;
            }
        }
        return null;
        }
    /**
    *Maps the database column type to the corresponding DataTables column type and filter type.
    *@param string $dbType The database column type.
    *@return array An array containing the DataTables column type and filter type.
    *The first element is the DataTables column type, and the second element is the filter type.
    *The possible column types are defined in the TypeColumn enum, and the possible filter types are defined in the TypeFilterColumn enum.
    *@throws Exception If an unsupported database column type is encountered.
    */
    private function mapColumnType($dbType)
    {
        if (strpos($dbType, 'int') !== false) {
            return [TypeColumn::Number, TypeFilterColumn::Number];
        } elseif (strpos($dbType, 'varchar') !== false || strpos($dbType, 'text') !== false) {
            return [TypeColumn::Text, TypeFilterColumn::Text];
        } elseif (strpos($dbType, 'datetime') !== false) {
            return [TypeColumn::DateTime, TypeFilterColumn::DateTime];
        } elseif (strpos($dbType, 'date') !== false) {
            return [TypeColumn::Date, TypeFilterColumn::Date];
        } elseif (strpos($dbType, 'time') !== false) {
            return [TypeColumn::Time, TypeFilterColumn::Time];
        } elseif (strpos($dbType, 'timestamp') !== false) {
            return [TypeColumn::DateTime, TypeFilterColumn::DateTime];
        } elseif (strpos($dbType, 'decimal') !== false || strpos($dbType, 'float') !== false || strpos($dbType, 'double') !== false) {
            return [TypeColumn::Number, TypeFilterColumn::Number];
        } elseif (strpos($dbType, 'boolean') !== false || strpos($dbType, 'tinyint(1)') !== false) {
            return [TypeColumn::Boolean, TypeFilterColumn::Text];
        } elseif (strpos($dbType, 'enum') !== false) {
            return [TypeColumn::Select, TypeFilterColumn::MultiSelect];
        } elseif (strpos($dbType, 'json') !== false) {
            return [TypeColumn::Json, TypeFilterColumn::Text];
        }
        return [TypeColumn::Text, TypeFilterColumn::Text];
    }
}
