<?php
namespace Generate\StandardTable\Enums;
abstract class TypeFilterColumn
{
    const Text = 'text';
    const Date = 'date';
    const DateTime = 'datetime';
    const DateRange = 'daterange';
    const DateTimeRange = 'datetimerange';
    const Number = 'number';
    const Time = 'time';
    const MultiSelect = 'multiselect';
}
