<?php
//File: zara-framework/src/Database/Schema/ForeignKeyDefinition.php
declare(strict_types=1);

namespace Zara\Database\Schema;

/**
 * $onDelete/$onUpdate acceptent les actions SQL standard : CASCADE,
 * RESTRICT, SET NULL, NO ACTION, SET DEFAULT. Both the identifiers and the
 * actions are validated at construction: they are all interpolated directly
 * into DDL, and an unknown action is a Zara-side programming error worth an
 * immediate, precise message rather than a driver error at run time.
 */
final class ForeignKeyDefinition
{
    private const ALLOWED_ACTIONS = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];

    public readonly string $column;
    public readonly string $referencedTable;
    public readonly string $referencedColumn;
    public readonly string $onDelete;
    public readonly string $onUpdate;

    public function __construct(
        string $column,
        string $referencedTable,
        string $referencedColumn = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
    ) {
        $this->column = SchemaIdentifier::validate($column);
        $this->referencedTable = SchemaIdentifier::validate($referencedTable);
        $this->referencedColumn = SchemaIdentifier::validate($referencedColumn);
        $this->onDelete = $this->validateAction($onDelete, 'onDelete');
        $this->onUpdate = $this->validateAction($onUpdate, 'onUpdate');
    }

    private function validateAction(string $action, string $name): string
    {
        $normalized = strtoupper(trim($action));
        if (!in_array($normalized, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid %s action "%s".', $name, $action));
        }
        return $normalized;
    }
}
