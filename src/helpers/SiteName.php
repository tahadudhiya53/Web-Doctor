<?php

namespace Tahadudhiya\WebDoctor\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use Tahadudhiya\WebDoctor\models\SafeException;
use Throwable;

/**
 * A site's name as it is now, kept beside a record's link to the site so that a deleted site's
 * records still say where they were made — and the rules every record shares for reading that pair.
 *
 * Read from Craft's sites table itself, trashed sites included: Craft soft-deletes a site, and a
 * record made while looking at one in the trash still happened there. A lookup that fails is an
 * error, never a blank, since a blank would later read as a record about no particular site. A site
 * with no row at all has no name to keep, and the record's foreign key is what refuses it.
 */
final class SiteName
{
    public static function of(?int $siteId): ?string
    {
        if ($siteId === null) {
            return null;
        }

        $name = (new Query())->select(['name'])->from(Table::SITES)->where(['id' => $siteId])->scalar();

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    /**
     * The condition that finds a place's records. No particular site is no site ID and no kept
     * name: a deleted site's records also have no site ID, but they keep its name, and they are not
     * the installation's.
     *
     * @return array<string, int|null>
     */
    public static function place(?int $siteId): array
    {
        return $siteId === null ? ['siteId' => null, 'siteName' => null] : ['siteId' => $siteId];
    }

    /**
     * Whether a record was made at a site that is no longer there: one deleted outright, whose link
     * was nulled and whose name was kept, or one in the trash. Where that cannot be established it is
     * read as gone — the caller refuses, which is the safe answer — and why is logged, since the
     * reader is told the site has gone.
     */
    public static function isGone(?int $siteId, ?string $keptName): bool
    {
        if ($siteId === null) {
            return $keptName !== null;
        }

        try {
            return Craft::$app->getSites()->getSiteById($siteId, true) === null;
        } catch (Throwable $e) {
            SafeException::log('Whether a site still exists could not be established', $e);

            return true;
        }
    }

    /**
     * What to call the site a record belongs to, by its name now, so a renamed site reads the same
     * on every page.
     *
     * A record made with no particular site in view and one made about a site that has since been
     * deleted are different facts: the second reads as the site it was, never as the installation.
     * A site Craft no longer has reads by the name kept for it; one whose lookup failed is logged and
     * read the same way, without being called gone.
     */
    public static function label(?int $siteId, ?string $keptName = null): string
    {
        if ($siteId === null) {
            return $keptName === null
                ? Craft::t('web-doctor', 'All sites')
                : Craft::t('web-doctor', '{name} (deleted)', ['name' => $keptName]);
        }

        try {
            $name = Craft::$app->getSites()->getSiteById($siteId)?->getName();
        } catch (Throwable $e) {
            SafeException::log('A site’s name could not be read', $e);

            return $keptName ?? Craft::t('web-doctor', 'Site #{id}', ['id' => $siteId]);
        }

        return $name ?? $keptName ?? Craft::t('web-doctor', 'Site #{id} (no longer available)', ['id' => $siteId]);
    }

    /**
     * The sites a list can be filtered by, by ID, where Craft can say. A failure costs the filter its
     * choices, never the page, and is logged.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        try {
            $sites = [];

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $sites[(int)$site->id] = $site->getName();
            }

            return $sites;
        } catch (Throwable $e) {
            SafeException::log('The sites could not be read to filter a list by', $e);

            return [];
        }
    }

    /**
     * What to call the site a record was made at, by the name kept when it was made — what the
     * record says, not what the site is called now. A deleted site keeps its name, marked as deleted;
     * a site whose name was not kept is named by its ID.
     */
    public static function recorded(?int $siteId, ?string $keptName, bool $unreadable = false): string
    {
        return match (true) {
            $unreadable => Craft::t('web-doctor', 'Could not be read'),
            $siteId === null && $keptName === null => Craft::t('web-doctor', 'All sites'),
            $siteId === null => Craft::t('web-doctor', '{name} (deleted)', ['name' => $keptName]),
            default => $keptName ?? Craft::t('web-doctor', 'Site #{id}', ['id' => $siteId]),
        };
    }
}
