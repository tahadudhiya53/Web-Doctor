<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use Tahadudhiya\WebDoctor\models\DiagnosticRun;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Where a finished diagnostic run is kept so it can be shown again without being repeated.
 *
 * Craft's cache is the store rather than a table of Web Doctor's own: a run is not a record
 * anybody is entitled to keep, it is the latest answer, and one that has been evicted simply has
 * to be asked for again. History is a record, with different rules and a need for durable
 * storage.
 *
 * Runs are scoped by environment and site. A run made against production says nothing about
 * staging, and a run made while looking at one site must not be shown as though it described
 * another.
 */
class Runs extends Component
{
    /** @var string The prefix every cache key Web Doctor owns starts with. */
    public const CACHE_KEY_PREFIX = 'web-doctor:run:latest';

    /**
     * @var int Bumped whenever the stored object graph changes shape. A remembered run is a
     * serialized object, and an old one deserialized into new classes would be worse than no
     * run at all — so the key changes and the old entry is simply never asked for again.
     */
    private const FORMAT = 2;

    /**
     * @var CacheInterface|null Where runs are kept. Settable so a caller — a test, or a site
     * that wants Web Doctor on a cache of its own — can supply one.
     */
    public ?CacheInterface $cache = null;

    /**
     * @var string|null Which environment these runs belong to. Taken from Craft unless stated,
     * and stated where there is no application to ask — a run filed under a real environment's
     * name by something that was not running in it would be read as that environment's answer.
     */
    public ?string $environment = null;

    /**
     * Keeps this run as the latest one for the environment and site it was made in.
     */
    public function remember(DiagnosticRun $run): bool
    {
        try {
            // No expiry: a run does not stop being the most recent thing anybody knows because
            // time passed. The dashboard shows how old it is and lets the reader judge.
            return $this->cache()->set($this->key($run->context->environment, $run->context->siteId), $run, 0);
        } catch (Throwable $e) {
            // Through the sanitised representation: a cache driver's error can quote its own DSN.
            Craft::warning(sprintf('The latest diagnostic run could not be stored: %s', SafeException::from($e)->summary()), WebDoctor::LOG_CATEGORY);

            return false;
        }
    }

    /**
     * The most recent run for this site in this environment, where one is still held.
     *
     * Anything other than a run comes back as none. A cache can hand back whatever was last
     * written under a key, including something written by a version of this plugin that no
     * longer exists, and a dashboard built from a half-deserialized object would be a failure
     * report about Web Doctor dressed up as a health report about the site.
     */
    public function latest(?int $siteId = null, ?string $environment = null): ?DiagnosticRun
    {
        try {
            $stored = $this->cache()->get($this->key($environment ?? $this->environment(), $siteId));
        } catch (Throwable $e) {
            Craft::warning(sprintf('The latest diagnostic run could not be read: %s', SafeException::from($e)->summary()), WebDoctor::LOG_CATEGORY);

            return null;
        }

        return $stored instanceof DiagnosticRun ? $stored : null;
    }

    /**
     * The cache key a run for this environment and site is held under.
     */
    public function key(string $environment, ?int $siteId): string
    {
        return sprintf('%s:%d:%s:%s', self::CACHE_KEY_PREFIX, self::FORMAT, $environment, $siteId ?? 'all');
    }

    private function environment(): string
    {
        return $this->environment ??= Craft::$app->env;
    }

    private function cache(): CacheInterface
    {
        return $this->cache ??= Craft::$app->getCache();
    }
}
