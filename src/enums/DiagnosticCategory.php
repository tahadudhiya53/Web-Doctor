<?php

namespace Tahadudhiya\WebDoctor\enums;

use Craft;

/**
 * The part of a Craft installation a diagnostic is about.
 *
 * The category is what groups results in the control panel, what a report is sectioned by, and
 * part of the deterministic ordering a diagnostic run is returned in.
 */
enum DiagnosticCategory: string
{
    case CRAFT = 'craft';
    case PHP = 'php';
    case DATABASE = 'database';
    case PLUGINS = 'plugins';
    case QUEUE = 'queue';
    case FILESYSTEM = 'filesystem';
    case STORAGE = 'storage';
    case ASSETS = 'assets';
    case EMAIL = 'email';
    case HTTP = 'http';
    case CONFIGURATION = 'configuration';
    case PROJECT_CONFIG = 'projectConfig';
    case ENVIRONMENT = 'environment';
    case SECURITY = 'security';
    case PERFORMANCE = 'performance';
    case USERS = 'users';
    case CONTENT = 'content';
    case SEARCH = 'search';
    case COMMERCE = 'commerce';
    case MULTI_SITE = 'multiSite';
    case DEPLOYMENT = 'deployment';

    public function label(): string
    {
        return match ($this) {
            self::CRAFT => Craft::t('web-doctor', 'Craft'),
            self::PHP => Craft::t('web-doctor', 'PHP'),
            self::DATABASE => Craft::t('web-doctor', 'Database'),
            self::PLUGINS => Craft::t('web-doctor', 'Plugins'),
            self::QUEUE => Craft::t('web-doctor', 'Queue'),
            self::FILESYSTEM => Craft::t('web-doctor', 'Filesystem'),
            self::STORAGE => Craft::t('web-doctor', 'Storage'),
            self::ASSETS => Craft::t('web-doctor', 'Assets'),
            self::EMAIL => Craft::t('web-doctor', 'Email'),
            self::HTTP => Craft::t('web-doctor', 'HTTP'),
            self::CONFIGURATION => Craft::t('web-doctor', 'Configuration'),
            self::PROJECT_CONFIG => Craft::t('web-doctor', 'Project Config'),
            self::ENVIRONMENT => Craft::t('web-doctor', 'Environment'),
            self::SECURITY => Craft::t('web-doctor', 'Security'),
            self::PERFORMANCE => Craft::t('web-doctor', 'Performance'),
            self::USERS => Craft::t('web-doctor', 'Users and permissions'),
            self::CONTENT => Craft::t('web-doctor', 'Content'),
            self::SEARCH => Craft::t('web-doctor', 'Search'),
            self::COMMERCE => Craft::t('web-doctor', 'Commerce'),
            self::MULTI_SITE => Craft::t('web-doctor', 'Multi-site'),
            self::DEPLOYMENT => Craft::t('web-doctor', 'Deployment'),
        };
    }

    /**
     * Where the category sits when results are grouped, so a run always reads in the same order
     * regardless of the order diagnostics happened to be registered in.
     */
    public function position(): int
    {
        return array_search($this, self::cases(), true) ?: 0;
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
