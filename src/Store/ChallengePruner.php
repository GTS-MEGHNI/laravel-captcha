<?php

declare(strict_types=1);

namespace GtsMeghni\LaravelCaptcha\Store;

use GtsMeghni\LaravelCaptcha\Support\Value;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Filesystem\Filesystem;

/**
 * Removes expired challenges that nothing will ever read again.
 *
 * Laravel drops an expired cache entry when something reads it, and an
 * abandoned challenge — issued, never displayed, never answered — is never read
 * again. On Redis and the other TTL-evicting stores that costs nothing, because
 * the store expires keys itself. On the file and database stores the entries
 * accumulate: files against an inode quota, rows in the cache table.
 */
class ChallengePruner
{
    public function __construct(
        protected CacheFactory $cache,
        protected Config $config,
        protected Filesystem $files,
        protected ?string $store = null,
        protected string $prefix = 'captcha',
    ) {}

    public function prune(): PruneResult
    {
        $store = $this->cache->store($this->store)->getStore();

        return match (true) {
            $store instanceof DatabaseStore => $this->pruneDatabase($store),
            $store instanceof FileStore => $this->pruneFiles($store),
            default => PruneResult::unnecessary($this->driver()),
        };
    }

    /**
     * The configured driver name, for reporting.
     */
    public function driver(): string
    {
        $name = $this->store ?? Value::string($this->config->get('cache.default'), 'file');

        return Value::string($this->config->get("cache.stores.{$name}.driver"), $name);
    }

    /**
     * Delete expired rows whose key belongs to this package.
     */
    protected function pruneDatabase(DatabaseStore $store): PruneResult
    {
        $name = $this->store ?? Value::string($this->config->get('cache.default'), 'database');
        $table = Value::string($this->config->get("cache.stores.{$name}.table"), 'cache');

        $like = $this->escapeForLike($store->getPrefix().$this->prefix.':').'%';

        $query = $store->getConnection()->table($table);

        $removed = $query
            ->whereRaw($this->likeKey($query->getGrammar()), [$like])
            ->where('expiration', '<=', time())
            ->delete();

        return new PruneResult('database', $removed, true, true);
    }

    /**
     * Delete expired cache files.
     *
     * A file store names its files after a hash of the key, so entries cannot be
     * attributed to this package. The sweep therefore covers every expired entry
     * in the store's directory, which is safe — they are expired — but it is the
     * reason to give the captcha its own store rather than share one.
     */
    protected function pruneFiles(FileStore $store): PruneResult
    {
        $directory = $store->getDirectory();

        if (! $this->files->isDirectory($directory)) {
            return new PruneResult('file', 0, true, false);
        }

        $now = time();
        $removed = 0;

        foreach ($this->files->allFiles($directory) as $file) {
            $path = $file->getPathname();

            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            $expiration = fread($handle, 10);
            fclose($handle);

            // A forever entry writes an expiration nine centuries out, so it is
            // still a numeric stamp and simply never matches.
            if (! is_string($expiration) || ! ctype_digit($expiration)) {
                continue;
            }

            if ((int) $expiration <= $now && $this->files->delete($path)) {
                $removed++;
            }
        }

        return new PruneResult('file', $removed, true, false);
    }

    /**
     * The `key LIKE ?` test, quoted and escaped for the grammar in hand.
     *
     * Two portability problems meet in one clause. `key` is a reserved word in
     * MySQL, so the column has to be quoted, and each grammar quotes it its own
     * way. And the ESCAPE clause is not optional: MySQL escapes LIKE patterns
     * with a backslash by default, but SQLite and SQL Server have no default
     * escape character at all, so without it an escaped pattern matches the
     * escape character literally and the sweep silently deletes nothing — which
     * is what a cache prefix of `laravel_cache_` used to produce.
     *
     * Spelled out per grammar rather than run through `wrap()` so the SQL stays
     * a literal string that static analysis can see is not attacker-shaped.
     *
     * @return literal-string
     */
    protected function likeKey(Grammar $grammar): string
    {
        return match (true) {
            // MariaDbGrammar extends the MySQL one, so it is covered here.
            $grammar instanceof MySqlGrammar => "`key` LIKE ? ESCAPE '!'",
            $grammar instanceof SqlServerGrammar => "[key] LIKE ? ESCAPE '!'",
            // SQLite and PostgreSQL both quote with doubled quotes.
            default => '"key" LIKE ? ESCAPE \'!\'',
        };
    }

    /**
     * Escape the wildcards a cache prefix may legitimately contain.
     *
     * `!` rather than the conventional backslash, because a backslash cannot be
     * written portably: MySQL reads `'\'` as an escaped quote and needs `'\\'`,
     * while SQLite reads `'\\'` as two characters and rejects it. `!` means the
     * same thing to every grammar.
     */
    protected function escapeForLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
