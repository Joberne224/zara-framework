# Database drivers

Zara's database layer is SQL-specific:

- `DatabaseInterface` and `AbstractSqlDatabase` expose statements, tables, and transactions.
- `MysqlDatabase`, `PostgresDatabase`, and `SqliteDatabase` are the concrete drivers, each pairing a `DatabaseConfig` (or `SqliteConfig`) with driver-specific internals under `Internal/`.
- `ConnectionInterface` is shared only for connection lifecycle operations.
