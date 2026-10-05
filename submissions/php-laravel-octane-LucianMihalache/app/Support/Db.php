<?php

namespace App\Support;

use PDO;
use PDOStatement;

/*
| The SQLite database, opened once by each worker and kept open for as long as the worker lives. The statements are
| prepared once too, so a query is one call to run it and one to read its rows: nothing is prepared or closed per
| request. A prepared statement keeps SQLite's query plan, never rows, so every request still reads its data from
| the database (rule 5). Laravel's database layer (DB::, Eloquent) is not used: it would wrap these calls in a query
| builder, a grammar and events for five fixed queries.
*/
final class Db
{
    private const POST = 'SELECT p.id, p.body, p.created_at, u.username,
               (SELECT count(*) FROM likes l WHERE l.post_id = p.id)
          FROM posts p JOIN users u ON u.id = p.user_id';

    private const SQL = [
        'feed'   => self::POST . ' ORDER BY p.created_at DESC, p.id DESC LIMIT 20',
        'post'   => self::POST . ' WHERE p.id = ?',
        'create' => 'INSERT INTO posts (user_id, body) VALUES (?, ?) RETURNING id, created_at',
        // One statement: the like is written only when the post exists, and a repeat changes nothing.
        'like'   => 'INSERT INTO likes (user_id, post_id) SELECT ?, id FROM posts WHERE id = ?
                     ON CONFLICT (user_id, post_id) DO NOTHING',
        'exists' => 'SELECT 1 FROM posts WHERE id = ?',
        'ping'   => 'SELECT 1',
    ];

    private static ?PDO $pdo = null;

    /** @var array<string, PDOStatement> */
    private static array $statements = [];

    /**
     * Runs one of the queries above and returns its rows (each a list, in the order of the SELECT).
     *
     * @return list<array<int, mixed>>
     */
    public static function rows(string $name, array $values = []): array
    {
        if (Profile::$on) {
            $from = hrtime(true);
            $rows = self::run($name, $values)->fetchAll();
            Profile::database(hrtime(true) - $from);

            return $rows;
        }

        return self::run($name, $values)->fetchAll();
    }

    /** Runs one of the writes above and returns how many rows it changed. */
    public static function changed(string $name, array $values): int
    {
        if (Profile::$on) {
            $from = hrtime(true);
            $count = self::run($name, $values)->rowCount();
            Profile::database(hrtime(true) - $from);

            return $count;
        }

        return self::run($name, $values)->rowCount();
    }

    private static function run(string $name, array $values): PDOStatement
    {
        $statement = self::$statements[$name] ??= self::connection()->prepare(self::SQL[$name]);
        $statement->execute($values);

        return $statement;
    }

    private static function connection(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . ($_SERVER['SQLITE_PATH'] ?? getenv('SQLITE_PATH')), null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                PDO::ATTR_TIMEOUT            => 5,   // wait this long for another worker's write to finish
            ]);
            // WAL with synchronous=NORMAL: a write is in the log file before its answer goes out (rule 6).
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            // The whole file (about 250 MB) is mapped: reads come from the kernel's page cache with no copy.
            $pdo->exec('PRAGMA mmap_size = 1073741824');
            $pdo->exec('PRAGMA cache_size = -8000');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            self::$pdo = $pdo;
        }

        return self::$pdo;
    }
}
